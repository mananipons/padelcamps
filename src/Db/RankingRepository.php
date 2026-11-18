<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Ranking points per player (see Schema: pc_ranking_points).
 *
 * Points of a tournament category are published and unpublished as a whole
 * (replacing the previous rows), so they can be recalculated safely. Manual
 * adjustments live in the same table with result = 'adjustment'.
 */
final class RankingRepository {

	public const ADJUSTMENT = 'adjustment';

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/**
	 * Publish the points of a category: replaces any earlier rows of it and stamps
	 * the category. All or nothing.
	 *
	 * @param array<int, array{registration_id:int, player_id:int, points:int, result:string}> $rows
	 */
	public function publish( int $category_id, int $tournament_id, int $season_id, int $ranking_category_id, array $rows ): void {
		global $wpdb;
		$pts = self::t( 'ranking_points' );
		$now = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $pts, array( 'category_id' => $category_id ) );
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$pts,
				array(
					'season_id'           => $season_id,
					'ranking_category_id' => $ranking_category_id,
					'tournament_id'       => $tournament_id,
					'category_id'         => $category_id,
					'registration_id'     => (int) $row['registration_id'],
					'player_id'           => (int) $row['player_id'],
					'points'              => (int) $row['points'],
					'result'              => (string) $row['result'],
					'created_at'          => $now,
				)
			);
		}
		$wpdb->update( self::t( 'categories' ), array( 'points_published_at' => $now ), array( 'id' => $category_id ) );
		$wpdb->query( 'COMMIT' );
	}

	/** Remove the published points of a category (manual adjustments stay). */
	public function unpublish( int $category_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'ranking_points' ), array( 'category_id' => $category_id ) );
		$wpdb->update( self::t( 'categories' ), array( 'points_published_at' => null ), array( 'id' => $category_id ) );
	}

	/**
	 * Published rows of a category as "player_id:points:result" strings, sorted: lets the
	 * service tell whether what is stored still matches what the results say.
	 *
	 * @return string[]
	 */
	public function category_signature( int $category_id ): array {
		global $wpdb;
		$pts  = self::t( 'ranking_points' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT player_id, points, result FROM {$pts} WHERE category_id = %d", $category_id ), ARRAY_A ) ?: array();
		$sig  = array_map( static fn( $r ) => (int) $r['player_id'] . ':' . (int) $r['points'] . ':' . $r['result'], $rows );
		sort( $sig );
		return $sig;
	}

	/** True when any category of the tournament has published points. */
	public function tournament_has_points( int $tournament_id ): bool {
		global $wpdb;
		$c = self::t( 'categories' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$c} WHERE tournament_id = %d AND points_published_at IS NOT NULL LIMIT 1", $tournament_id ) );
	}

	// --- Cleanup (called when the data they came from disappears) -------------

	public function delete_tournament_points( int $tournament_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'ranking_points' ), array( 'tournament_id' => $tournament_id ) );
	}

	public function delete_category_points( int $category_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'ranking_points' ), array( 'category_id' => $category_id ) );
	}

	public function delete_season_points( int $season_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'ranking_points' ), array( 'season_id' => $season_id ) );
	}

	public function delete_ranking_category_points( int $ranking_category_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'ranking_points' ), array( 'ranking_category_id' => $ranking_category_id ) );
	}

	/**
	 * Points of players that no longer exist (right to erasure: deleting a player deletes
	 * the points that were attached to them, adjustments included).
	 */
	public function delete_orphans(): void {
		global $wpdb;
		$pts = self::t( 'ranking_points' );
		$p   = self::t( 'players' );
		$wpdb->query( "DELETE rp FROM {$pts} rp LEFT JOIN {$p} p ON p.id = rp.player_id WHERE p.id IS NULL" );
	}

	// --- Manual adjustments ---------------------------------------------------

	/** Add (or subtract, with negative points) points to a player in a season ranking. */
	public function add_adjustment( int $season_id, int $ranking_category_id, int $player_id, int $points, string $note ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'ranking_points' ),
			array(
				'season_id'           => $season_id,
				'ranking_category_id' => $ranking_category_id,
				'player_id'           => $player_id,
				'points'              => $points,
				'result'              => self::ADJUSTMENT,
				'note'                => mb_substr( $note, 0, 190 ),
				'created_at'          => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Only manual adjustments can be deleted one by one; published rows go through unpublish(). */
	public function delete_adjustment( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::t( 'ranking_points' ), array( 'id' => $id, 'result' => self::ADJUSTMENT ) );
	}

	/** Delete a manual adjustment only if it belongs to this player. */
	public function delete_adjustment_of_player( int $id, int $player_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::t( 'ranking_points' ), array( 'id' => $id, 'player_id' => $player_id, 'result' => self::ADJUSTMENT ) );
	}

	/** Manual adjustments with the player name, newest first. */
	public function adjustments( int $season_id, ?int $ranking_category_id = null ): array {
		global $wpdb;
		$pts = self::t( 'ranking_points' );
		$p   = self::t( 'players' );
		$sql = $wpdb->prepare(
			"SELECT rp.*, p.name AS player_name FROM {$pts} rp INNER JOIN {$p} p ON p.id = rp.player_id
			WHERE rp.season_id = %d AND rp.result = %s",
			$season_id,
			self::ADJUSTMENT
		);
		if ( null !== $ranking_category_id ) {
			$sql .= $wpdb->prepare( ' AND rp.ranking_category_id = %d', $ranking_category_id );
		}
		return $wpdb->get_results( $sql . ' ORDER BY rp.id DESC', ARRAY_A ) ?: array();
	}

	// --- Ranking --------------------------------------------------------------

	/**
	 * Points each player has in one season ranking category, for seeding a tournament.
	 * The points of `$exclude_tournament_id` itself are left out (a draw must only look at
	 * what the players did elsewhere); manual adjustments, which belong to no tournament,
	 * always count. A player without any row is simply absent (= 0).
	 *
	 * @param int[] $player_ids
	 * @return array<int,int> player id => points
	 */
	public function player_points( array $player_ids, int $season_id, int $ranking_category_id, int $exclude_tournament_id = 0 ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$list = implode( ',', $ids );
		$pts  = self::t( 'ranking_points' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT player_id, SUM(points) AS points FROM {$pts}
				WHERE season_id = %d AND ranking_category_id = %d AND player_id IN ({$list})
				AND (tournament_id IS NULL OR tournament_id <> %d)
				GROUP BY player_id",
				$season_id,
				$ranking_category_id,
				$exclude_tournament_id
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['player_id'] ] = (int) $row['points'];
		}
		return $out;
	}

	/**
	 * The ranking of one season category: points summed per player, best first
	 * (ties by name). `tournaments` counts the tournaments the player scored in.
	 *
	 * @return array<int, array{player_id:int, name:string, club:?string, points:int, tournaments:int}>
	 */
	public function totals( int $season_id, int $ranking_category_id ): array {
		global $wpdb;
		$pts  = self::t( 'ranking_points' );
		$p    = self::t( 'players' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id AS player_id, p.name, p.club, SUM(rp.points) AS points, COUNT(DISTINCT rp.tournament_id) AS tournaments
				FROM {$pts} rp INNER JOIN {$p} p ON p.id = rp.player_id
				WHERE rp.season_id = %d AND rp.ranking_category_id = %d
				GROUP BY p.id, p.name, p.club
				ORDER BY points DESC, p.name ASC",
				$season_id,
				$ranking_category_id
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$row ) {
			$row['player_id']   = (int) $row['player_id'];
			$row['points']      = (int) $row['points'];
			$row['tournaments'] = (int) $row['tournaments'];
		}
		return $rows;
	}
}
