<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Courts and match times of a tournament (all categories share the courts).
 */
final class ScheduleRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	public function courts( int $tournament_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::t( 'courts' ) . ' WHERE tournament_id = %d ORDER BY sort_order, id', $tournament_id ),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Make the court list match $names (in order). Existing courts keep their id
	 * (and their matches) by position; removed courts free their matches.
	 *
	 * @param string[] $names
	 */
	public function sync_courts( int $tournament_id, array $names ): void {
		global $wpdb;
		$existing = $this->courts( $tournament_id );
		foreach ( array_values( $names ) as $i => $name ) {
			if ( isset( $existing[ $i ] ) ) {
				$wpdb->update( self::t( 'courts' ), array( 'name' => $name, 'sort_order' => $i ), array( 'id' => (int) $existing[ $i ]['id'] ) );
			} else {
				$wpdb->insert( self::t( 'courts' ), array( 'tournament_id' => $tournament_id, 'name' => $name, 'sort_order' => $i ) );
			}
		}
		foreach ( array_slice( $existing, count( $names ) ) as $court ) {
			$wpdb->update( self::t( 'matches' ), array( 'court_id' => null, 'scheduled_at' => null ), array( 'court_id' => (int) $court['id'] ) );
			$wpdb->delete( self::t( 'courts' ), array( 'id' => (int) $court['id'] ) );
		}
	}

	/** Every match of the tournament with its category and phase info. */
	public function matches( int $tournament_id ): array {
		global $wpdb;
		$m = self::t( 'matches' );
		$c = self::t( 'categories' );
		$p = self::t( 'phases' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, c.name AS category_name, c.sort_order AS category_sort, p.type AS phase_type, p.sort_order AS phase_sort
				FROM {$m} m INNER JOIN {$c} c ON c.id = m.category_id LEFT JOIN {$p} p ON p.id = m.phase_id
				WHERE c.tournament_id = %d",
				$tournament_id
			),
			ARRAY_A
		) ?: array();
	}

	/** reg id => availability answers, for confirmed registrations of the tournament. */
	public function availability( int $tournament_id ): array {
		global $wpdb;
		$r    = self::t( 'registrations' );
		$c    = self::t( 'categories' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT r.id, r.answers FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d AND r.status = 'confirmed'", $tournament_id ),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $row ) {
			$answers                  = json_decode( (string) $row['answers'], true );
			$out[ (int) $row['id'] ] = is_array( $answers['availability'] ?? null ) ? $answers['availability'] : array();
		}
		return $out;
	}

	public function set_schedule( int $match_id, ?string $start, ?int $court_id ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'matches' ),
			array(
				'scheduled_at' => $start,
				'court_id'     => $court_id,
			),
			array( 'id' => $match_id )
		);
	}

	/** Forget times and courts of every match not played yet. */
	public function clear( int $tournament_id ): void {
		global $wpdb;
		$m = self::t( 'matches' );
		$c = self::t( 'categories' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$m} m INNER JOIN {$c} c ON c.id = m.category_id SET m.scheduled_at = NULL, m.court_id = NULL
				WHERE c.tournament_id = %d AND m.status <> 'played'",
				$tournament_id
			)
		);
	}
}
