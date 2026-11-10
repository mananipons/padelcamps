<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Magic-link tokens (one per pair) and result reports made by players.
 */
final class ResultRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	// --- Tokens -----------------------------------------------------------------

	/** Token of a registration, created on first use. */
	public function token_for( int $reg_id ): string {
		global $wpdb;
		$token = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT token FROM ' . self::t( 'registrations' ) . ' WHERE id = %d', $reg_id ) );
		return '' !== $token ? $token : $this->regenerate_token( $reg_id );
	}

	/** New random token (the old link stops working). */
	public function regenerate_token( int $reg_id ): string {
		global $wpdb;
		$token = strtolower( wp_generate_password( 32, false, false ) );
		$wpdb->update( self::t( 'registrations' ), array( 'token' => $token ), array( 'id' => $reg_id ) );
		return $token;
	}

	/** Confirmed registration behind a token, with its category and tournament, or null. */
	public function find_by_token( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[a-z0-9]{32}$/', $token ) ) {
			return null;
		}
		$r   = self::t( 'registrations' );
		$c   = self::t( 'categories' );
		$t   = self::t( 'tournaments' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.id, r.category_id, r.status, c.name AS category_name, c.tournament_id, t.name AS tournament_name, t.status AS tournament_status
				FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id INNER JOIN {$t} t ON t.id = c.tournament_id
				WHERE r.token = %s",
				$token
			),
			ARRAY_A
		);
		return $row && 'confirmed' === $row['status'] ? $row : null;
	}

	/** Confirmed pairs of a tournament (for the links list). */
	public function confirmed_pairs( int $tournament_id ): array {
		global $wpdb;
		$r = self::t( 'registrations' );
		$c = self::t( 'categories' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.token, r.contact_email, r.contact_phone, c.id AS category_id, c.name AS category_name
				FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id
				WHERE c.tournament_id = %d AND r.status = 'confirmed' ORDER BY c.sort_order, c.id, r.seed_no IS NULL, r.seed_no, r.id",
				$tournament_id
			),
			ARRAY_A
		) ?: array();
	}

	// --- Matches of a pair ------------------------------------------------------------

	/** Matches where the registration plays (both phases), in play order. */
	public function matches_of( int $reg_id ): array {
		global $wpdb;
		$m    = self::t( 'matches' );
		$p    = self::t( 'phases' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, p.type AS phase_type FROM {$m} m LEFT JOIN {$p} p ON p.id = m.phase_id
				WHERE (m.reg_a_id = %d OR m.reg_b_id = %d) AND m.status <> 'bye'
				ORDER BY m.scheduled_at IS NULL, m.scheduled_at, p.sort_order, m.round, m.id",
				$reg_id,
				$reg_id
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$row ) {
			$score        = json_decode( (string) $row['score'], true );
			$row['score'] = is_array( $score ) ? $score : null;
		}
		return $rows;
	}

	// --- Reports ----------------------------------------------------------------------

	public function add_report( int $match_id, int $reg_id, array $result, string $status ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'result_reports' ),
			array(
				'match_id'    => $match_id,
				'reg_id'      => $reg_id,
				'result'      => wp_json_encode( $result ),
				'status'      => $status,
				'created_at'  => current_time( 'mysql' ),
				'resolved_at' => 'pending' === $status ? null : current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function set_report_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'result_reports' ),
			array(
				'status'      => $status,
				'resolved_at' => 'pending' === $status ? null : current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/** Close every open (pending/disputed) report of a match. */
	public function close_open( int $match_id, string $status ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::t( 'result_reports' ) . " SET status = %s, resolved_at = %s WHERE match_id = %d AND status IN ('pending','disputed')",
				$status,
				current_time( 'mysql' ),
				$match_id
			)
		);
	}

	/** Open reports (pending/disputed) of a match, newest first. */
	public function open_reports( int $match_id ): array {
		global $wpdb;
		return $this->decode(
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . self::t( 'result_reports' ) . " WHERE match_id = %d AND status IN ('pending','disputed') ORDER BY id DESC", $match_id ),
				ARRAY_A
			) ?: array()
		);
	}

	public function find_report( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'result_reports' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->decode( array( $row ) )[0] : null;
	}

	/** Open reports of a tournament with their match, for the admin. */
	public function open_reports_of_tournament( int $tournament_id ): array {
		global $wpdb;
		$rr = self::t( 'result_reports' );
		$m  = self::t( 'matches' );
		$c  = self::t( 'categories' );
		$p  = self::t( 'phases' );
		return $this->decode(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT rr.*, m.category_id, m.round, m.group_no, m.reg_a_id, m.reg_b_id, m.status AS match_status, c.name AS category_name, p.type AS phase_type
					FROM {$rr} rr INNER JOIN {$m} m ON m.id = rr.match_id INNER JOIN {$c} c ON c.id = m.category_id LEFT JOIN {$p} p ON p.id = m.phase_id
					WHERE c.tournament_id = %d AND rr.status IN ('pending','disputed') ORDER BY rr.status DESC, rr.match_id, rr.id",
					$tournament_id
				),
				ARRAY_A
			) ?: array()
		);
	}

	private function decode( array $rows ): array {
		foreach ( $rows as &$row ) {
			$result        = json_decode( (string) $row['result'], true );
			$row['result'] = is_array( $result ) ? $result : array();
		}
		return $rows;
	}
}
