<?php
namespace PadelCamps\Db;

use PadelCamps\Engine\PointsScheme;
use PadelCamps\Engine\PublicRanking;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for seasons and their ranking categories.
 * Callers must pass already sanitized/validated values.
 */
final class SeasonRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/** All seasons, newest first, with tournament / ranking category counts. */
	public function all(): array {
		global $wpdb;
		$s  = self::t( 'seasons' );
		$tt = self::t( 'tournaments' );
		$rc = self::t( 'ranking_categories' );

		$sql = "SELECT s.*,
			(SELECT COUNT(*) FROM {$tt} WHERE season_id = s.id) AS tournaments_count,
			(SELECT COUNT(*) FROM {$tt} WHERE season_id = s.id AND counts_for_ranking = 1) AS ranked_count,
			(SELECT COUNT(*) FROM {$rc} WHERE season_id = s.id) AS categories_count
			FROM {$s} s
			ORDER BY COALESCE(s.starts_on, '0000-00-00') DESC, s.id DESC";

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$s   = self::t( 'seasons' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$s} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * The season to suggest for a new tournament: the one whose dates include today
	 * (the latest start wins), otherwise the most recent one.
	 */
	public function current(): ?array {
		$today = current_time( 'Y-m-d' );
		$all   = $this->all();
		foreach ( $all as $season ) {
			$from = $season['starts_on'] ?: '0000-00-00';
			$to   = $season['ends_on'] ?: '9999-12-31';
			if ( $from <= $today && $today <= $to ) {
				return $season;
			}
		}
		return $all[0] ?? null;
	}

	/** Points scheme of a season (complete, sanitized). */
	public function scheme( array $season ): array {
		return PointsScheme::normalize( json_decode( (string) ( $season['points'] ?? '' ), true ) );
	}

	/** How player names show in the public ranking: 'initials' (default, private) or 'full'. */
	public function public_names( array $season ): string {
		return PublicRanking::mode( $season['public_names'] ?? null );
	}

	/** @param array{name:string, starts_on:?string, ends_on:?string, points:array<string,int>, public_names?:string} $data */
	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'seasons' ),
			array(
				'name'         => $data['name'],
				'starts_on'    => $data['starts_on'],
				'ends_on'      => $data['ends_on'],
				'points'       => wp_json_encode( PointsScheme::normalize( $data['points'] ) ),
				'public_names' => PublicRanking::mode( $data['public_names'] ?? null ),
				'created_at'   => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** `public_names` is only touched when given. @param array{name:string, starts_on:?string, ends_on:?string, points:array<string,int>, public_names?:string} $data */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = array(
			'name'      => $data['name'],
			'starts_on' => $data['starts_on'],
			'ends_on'   => $data['ends_on'],
			'points'    => wp_json_encode( PointsScheme::normalize( $data['points'] ) ),
		);
		if ( array_key_exists( 'public_names', $data ) ) {
			$row['public_names'] = PublicRanking::mode( $data['public_names'] );
		}
		$wpdb->update( self::t( 'seasons' ), $row, array( 'id' => $id ) );
	}

	/**
	 * Delete a season. Its tournaments and categories are kept, just unlinked
	 * (they stop counting for any ranking).
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$tt = self::t( 'tournaments' );
		$c  = self::t( 'categories' );
		$rc = self::t( 'ranking_categories' );

		( new RankingRepository() )->delete_season_points( $id );
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$c} c INNER JOIN {$rc} rc ON rc.id = c.ranking_category_id SET c.ranking_category_id = NULL, c.points_published_at = NULL WHERE rc.season_id = %d", $id )
		);
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$tt} SET season_id = NULL, counts_for_ranking = 0 WHERE season_id = %d", $id )
		);
		$wpdb->delete( $rc, array( 'season_id' => $id ) );
		$wpdb->delete( self::t( 'seasons' ), array( 'id' => $id ) );
	}

	// --- Ranking categories -------------------------------------------------

	public function ranking_categories( int $season_id ): array {
		global $wpdb;
		$rc = self::t( 'ranking_categories' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$rc} WHERE season_id = %d ORDER BY sort_order ASC, id ASC", $season_id ),
			ARRAY_A
		) ?: array();
	}

	public function ranking_category( int $id ): ?array {
		global $wpdb;
		$rc  = self::t( 'ranking_categories' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rc} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Tournament categories linked to one ranking category. */
	public function ranking_category_usage( int $ranking_category_id ): int {
		global $wpdb;
		$c = self::t( 'categories' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE ranking_category_id = %d", $ranking_category_id ) );
	}

	/**
	 * Replace the ranking categories of a season keeping the identity of the existing ones
	 * (so tournament categories stay linked after a rename or reorder).
	 *
	 * @param array<int,string> $existing id => name; ids of this season missing here are deleted
	 * @param string[]          $new      names of categories to add, in display order after the existing ones
	 */
	public function sync_ranking_categories( int $season_id, array $existing, array $new ): void {
		global $wpdb;
		$rc      = self::t( 'ranking_categories' );
		$c       = self::t( 'categories' );
		$current = array_map( 'intval', wp_list_pluck( $this->ranking_categories( $season_id ), 'id' ) );

		foreach ( array_diff( $current, array_keys( $existing ) ) as $gone ) {
			// Its published points go too: they belong to a ranking that no longer exists.
			( new RankingRepository() )->delete_ranking_category_points( $gone );
			$wpdb->update( $c, array( 'ranking_category_id' => null, 'points_published_at' => null ), array( 'ranking_category_id' => $gone ) );
			$wpdb->delete( $rc, array( 'id' => $gone ) );
		}

		$order = 0;
		foreach ( $existing as $id => $name ) {
			if ( in_array( (int) $id, $current, true ) ) {
				$wpdb->update( $rc, array( 'name' => $name, 'sort_order' => ++$order ), array( 'id' => (int) $id, 'season_id' => $season_id ) );
			}
		}
		foreach ( $new as $name ) {
			$wpdb->insert( $rc, array( 'season_id' => $season_id, 'name' => $name, 'sort_order' => ++$order ) );
		}
	}

	/** Unlink the categories of a tournament from their ranking categories (the season changed). */
	public function clear_category_links( int $tournament_id ): void {
		global $wpdb;
		$wpdb->update( self::t( 'categories' ), array( 'ranking_category_id' => null ), array( 'tournament_id' => $tournament_id ) );
	}
}
