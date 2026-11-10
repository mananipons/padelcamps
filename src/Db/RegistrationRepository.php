<?php
namespace PadelCamps\Db;

use PadelCamps\Engine\RegistrationForm;
use PadelCamps\Engine\RegistrationStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for registrations (one per pair) and their players.
 * Callers must pass data already validated by Engine\RegistrationForm.
 */
final class RegistrationRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/**
	 * Registrations that hold a place (pending + confirmed) per category.
	 *
	 * @param int[] $category_ids
	 * @return array<int,int> category id => count
	 */
	public function occupied_counts( array $category_ids ): array {
		global $wpdb;
		$category_ids = array_values( array_map( 'intval', $category_ids ) );
		$out          = array_fill_keys( $category_ids, 0 );
		if ( ! $category_ids ) {
			return $out;
		}
		$ids  = implode( ',', $category_ids );
		$r    = self::t( 'registrations' );
		$rows = $wpdb->get_results(
			"SELECT category_id, COUNT(*) AS n FROM {$r} WHERE category_id IN ({$ids}) AND status IN ('pending','confirmed') GROUP BY category_id",
			ARRAY_A
		);
		foreach ( $rows ?: array() as $row ) {
			$out[ (int) $row['category_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** True when someone with this email already has a non-cancelled registration in the category. */
	public function has_active_registration( int $category_id, string $email ): bool {
		global $wpdb;
		if ( '' === $email ) {
			return false;
		}
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );
		$p  = self::t( 'players' );
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT r.id FROM {$r} r
				INNER JOIN {$rp} rp ON rp.registration_id = r.id
				INNER JOIN {$p} p ON p.id = rp.player_id
				WHERE r.category_id = %d AND r.status <> 'cancelled' AND p.email = %s LIMIT 1",
				$category_id,
				$email
			)
		);
	}

	/**
	 * Create a registration atomically. The category row is locked so two
	 * simultaneous submissions cannot both take the last place.
	 *
	 * @param array{category_id:int, players:array, notes:string, availability:array, consents:array, locale:string} $data
	 * @return array{id:int, status:string, amount:float}|false
	 */
	public function create( array $data ) {
		global $wpdb;
		$c  = self::t( 'categories' );
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );

		$wpdb->query( 'START TRANSACTION' );

		$category = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, max_teams, price FROM {$c} WHERE id = %d FOR UPDATE", (int) $data['category_id'] ),
			ARRAY_A
		);
		if ( ! $category ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$occupied = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE category_id = %d AND status IN ('pending','confirmed')", (int) $category['id'] )
		);
		$status   = RegistrationForm::initial_status( null === $category['max_teams'] ? null : (int) $category['max_teams'], $occupied );
		$amount   = (float) $category['price'];
		$p1       = $data['players'][0];

		$answers = array(
			'players'      => array_map(
				static fn( $p ) => array(
					'dni'  => $p['dni'],
					'pack' => $p['pack'], // Welcome pack answers: [ ['label' => ..., 'value' => ...], ... ]
				),
				$data['players']
			),
			'notes'        => $data['notes'],
			'availability' => $data['availability'],
			'consents'     => $data['consents'],
			'consented_at' => current_time( 'mysql' ),
			'locale'       => $data['locale'],
		);

		$inserted = $wpdb->insert(
			$r,
			array(
				'category_id'    => (int) $category['id'],
				'status'         => $status,
				'payment_status' => $amount > 0 ? 'pending' : 'free',
				'amount'         => $amount,
				'contact_name'   => $p1['name'],
				'contact_email'  => $p1['email'],
				'contact_phone'  => $p1['phone'],
				'answers'        => wp_json_encode( $answers ),
				'created_at'     => current_time( 'mysql' ),
			)
		);
		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		$registration_id = (int) $wpdb->insert_id;

		foreach ( $data['players'] as $i => $player ) {
			$player_id = $this->player_id( $player );
			if ( ! $player_id || ! $wpdb->insert(
				$rp,
				array(
					'registration_id' => $registration_id,
					'player_id'       => $player_id,
					'position'        => $i + 1,
				)
			) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );
		return array(
			'id'     => $registration_id,
			'status' => $status,
			'amount' => $amount,
		);
	}

	// --- Listing / management ------------------------------------------------

	private const FROM = 'FROM %1$s r
		INNER JOIN %2$s c ON c.id = r.category_id
		INNER JOIN %3$s t ON t.id = c.tournament_id';

	private static function from(): string {
		return sprintf( self::FROM, self::t( 'registrations' ), self::t( 'categories' ), self::t( 'tournaments' ) );
	}

	/**
	 * WHERE clause (prepared) for the supported filters.
	 *
	 * @param array{tournament_id?:int, category_id?:int, status?:string, payment_status?:string, s?:string} $f
	 */
	private function where( array $f ): string {
		global $wpdb;
		$sql = ' WHERE 1=1';

		if ( ! empty( $f['tournament_id'] ) ) {
			$sql .= $wpdb->prepare( ' AND c.tournament_id = %d', (int) $f['tournament_id'] );
		}
		if ( ! empty( $f['category_id'] ) ) {
			$sql .= $wpdb->prepare( ' AND r.category_id = %d', (int) $f['category_id'] );
		}
		if ( ! empty( $f['status'] ) && RegistrationStatus::is_valid( $f['status'] ) ) {
			$sql .= $wpdb->prepare( ' AND r.status = %s', $f['status'] );
		}
		if ( ! empty( $f['payment_status'] ) && RegistrationStatus::is_valid_payment( $f['payment_status'] ) ) {
			$sql .= $wpdb->prepare( ' AND r.payment_status = %s', $f['payment_status'] );
		}
		if ( isset( $f['s'] ) && '' !== trim( $f['s'] ) ) {
			$like = '%' . $wpdb->esc_like( trim( $f['s'] ) ) . '%';
			$rp   = self::t( 'registration_players' );
			$p    = self::t( 'players' );
			$sql .= $wpdb->prepare(
				" AND (r.contact_name LIKE %s OR r.contact_email LIKE %s OR EXISTS (
					SELECT 1 FROM {$rp} rp INNER JOIN {$p} p ON p.id = rp.player_id
					WHERE rp.registration_id = r.id AND (p.name LIKE %s OR p.email LIKE %s)))",
				$like,
				$like,
				$like,
				$like
			);
		}
		return $sql;
	}

	/** @return array<int, array> registrations with category/tournament names, newest first. */
	public function search( array $filters, int $limit = 25, int $offset = 0 ): array {
		global $wpdb;
		$sql = 'SELECT r.*, c.name AS category_name, c.tournament_id, t.name AS tournament_name, t.slug AS tournament_slug '
			. self::from() . $this->where( $filters )
			. $wpdb->prepare( ' ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d', $limit, $offset );
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public function count( array $filters ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::from() . $this->where( $filters ) );
	}

	/** Players of the given registrations, ordered by position: registration id => players. */
	public function players_for( array $registration_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $registration_ids ) ) );
		$out = array_fill_keys( $ids, array() );
		if ( ! $ids ) {
			return $out;
		}
		$list = implode( ',', $ids );
		$rp   = self::t( 'registration_players' );
		$p    = self::t( 'players' );
		$rows = $wpdb->get_results(
			"SELECT rp.registration_id, rp.position, p.* FROM {$rp} rp INNER JOIN {$p} p ON p.id = rp.player_id
			WHERE rp.registration_id IN ({$list}) ORDER BY rp.registration_id, rp.position",
			ARRAY_A
		);
		foreach ( $rows ?: array() as $row ) {
			$out[ (int) $row['registration_id'] ][] = $row;
		}
		return $out;
	}

	/** One registration with everything needed for the detail screen, or null. */
	public function find( int $id ): ?array {
		$rows = $this->search_by_id( $id );
		if ( ! $rows ) {
			return null;
		}
		$row            = $rows[0];
		$row['players'] = $this->players_for( array( $id ) )[ $id ] ?? array();
		$answers        = json_decode( (string) $row['answers'], true );
		$row['answers'] = is_array( $answers ) ? $answers : array();
		return $row;
	}

	private function search_by_id( int $id ): array {
		global $wpdb;
		$sql = 'SELECT r.*, c.name AS category_name, c.tournament_id, c.max_teams, t.name AS tournament_name, t.slug AS tournament_slug '
			. self::from() . $wpdb->prepare( ' WHERE r.id = %d', $id );
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/** Money and status summary for the same filters as the list. */
	public function summary( array $filters ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT r.status, r.payment_status, COUNT(*) AS n, COALESCE(SUM(r.amount), 0) AS total '
			. self::from() . $this->where( $filters ) . ' GROUP BY r.status, r.payment_status',
			ARRAY_A
		);
		return RegistrationStatus::summarize( $rows ?: array() );
	}

	/** Places taken and waiting list per category of a tournament. */
	public function category_stats( int $tournament_id ): array {
		global $wpdb;
		$c = self::t( 'categories' );
		$r = self::t( 'registrations' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.name, c.max_teams,
					COALESCE(SUM(r.status IN ('pending','confirmed')), 0) AS occupied,
					COALESCE(SUM(r.status = 'waitlist'), 0) AS waitlist
				FROM {$c} c LEFT JOIN {$r} r ON r.category_id = c.id
				WHERE c.tournament_id = %d GROUP BY c.id, c.name, c.max_teams ORDER BY c.sort_order, c.id",
				$tournament_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Keep only the ids that belong to the tournament (0 = no restriction, only existing ids).
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public function filter_ids( array $ids, int $tournament_id = 0 ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$list = implode( ',', $ids );
		$sql  = 'SELECT r.id ' . self::from() . " WHERE r.id IN ({$list})";
		if ( $tournament_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND c.tournament_id = %d', $tournament_id );
		}
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/** @param int[] $ids @return int rows changed */
	public function set_status( array $ids, string $status ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! RegistrationStatus::is_valid( $status ) ) {
			return 0;
		}
		$list = implode( ',', $ids );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'registrations' ) . " SET status = %s WHERE id IN ({$list}) AND status <> %s", $status, $status ) );
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Payment mark. Free registrations (amount 0) are never touched.
	 *
	 * @param int[] $ids @return int rows changed
	 */
	public function set_payment( array $ids, string $payment ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! RegistrationStatus::is_valid_payment( $payment ) || RegistrationStatus::PAYMENT_FREE === $payment ) {
			return 0;
		}
		$list    = implode( ',', $ids );
		$paid_at = RegistrationStatus::PAYMENT_PAID === $payment ? current_time( 'mysql' ) : null;
		$sql     = 'UPDATE ' . self::t( 'registrations' ) . ' SET payment_status = %s, paid_at = ' . ( null === $paid_at ? 'NULL' : '%s' )
			. " WHERE id IN ({$list}) AND amount > 0 AND payment_status <> %s";
		$args    = null === $paid_at ? array( $payment, $payment ) : array( $payment, $paid_at, $payment );
		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		return (int) $wpdb->rows_affected;
	}

	/** Organizer-only data: private notes and the rating used for seeding. */
	public function update_details( int $id, ?float $rating, string $notes ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'registrations' ),
			array(
				'rating' => $rating,
				'notes'  => $notes,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Categories (of the given registrations) that now have more places taken than allowed.
	 *
	 * @param int[] $ids
	 * @return string[] category names
	 */
	public function over_capacity_categories( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$list = implode( ',', $ids );
		$r    = self::t( 'registrations' );
		$cats = $wpdb->get_col( "SELECT DISTINCT category_id FROM {$r} WHERE id IN ({$list})" );
		$out  = array();
		foreach ( $cats as $category_id ) {
			$c = $wpdb->get_row( $wpdb->prepare( 'SELECT name, max_teams FROM ' . self::t( 'categories' ) . ' WHERE id = %d', (int) $category_id ), ARRAY_A );
			if ( ! $c ) {
				continue;
			}
			$occupied = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE category_id = %d AND status IN ('pending','confirmed')", (int) $category_id ) );
			if ( RegistrationStatus::over_capacity( null === $c['max_teams'] ? null : (int) $c['max_teams'], $occupied ) ) {
				$out[] = $c['name'];
			}
		}
		return $out;
	}

	/**
	 * Delete registrations and the personal data tied to them: the players that
	 * belong to no other registration, and the email log entries.
	 *
	 * @param int[] $ids @return int registrations deleted
	 */
	public function delete( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$list = implode( ',', $ids );
		$r    = self::t( 'registrations' );
		$rp   = self::t( 'registration_players' );
		$p    = self::t( 'players' );

		$player_ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT player_id FROM {$rp} WHERE registration_id IN ({$list})" ) );

		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( "DELETE FROM {$rp} WHERE registration_id IN ({$list})" );
		$wpdb->query( "DELETE FROM {$r} WHERE id IN ({$list})" );
		$deleted = (int) $wpdb->rows_affected;
		$wpdb->query( "DELETE FROM " . self::t( 'notifications_log' ) . " WHERE ref_type = 'registration' AND ref_id IN ({$list})" );
		$wpdb->query( 'DELETE FROM ' . self::t( 'result_reports' ) . " WHERE reg_id IN ({$list})" );
		if ( $player_ids ) {
			$plist = implode( ',', $player_ids );
			$wpdb->query( "DELETE p FROM {$p} p LEFT JOIN {$rp} rp ON rp.player_id = p.id WHERE p.id IN ({$plist}) AND rp.id IS NULL" );
		}
		$wpdb->query( 'COMMIT' );
		return $deleted;
	}

	/**
	 * Reuse the player with the same email. Existing data is never overwritten
	 * (anyone could type someone else's email); only empty fields are completed.
	 */
	private function player_id( array $player ): int {
		global $wpdb;
		$p = self::t( 'players' );

		if ( '' !== $player['email'] ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p} WHERE email = %s ORDER BY id ASC LIMIT 1", $player['email'] ), ARRAY_A );
			if ( $existing ) {
				$fill = array();
				foreach ( array( 'phone', 'club' ) as $field ) {
					if ( '' === (string) $existing[ $field ] && '' !== $player[ $field ] ) {
						$fill[ $field ] = $player[ $field ];
					}
				}
				if ( $fill ) {
					$wpdb->update( $p, $fill, array( 'id' => (int) $existing['id'] ) );
				}
				return (int) $existing['id'];
			}
		}

		$wpdb->insert(
			$p,
			array(
				'name'  => $player['name'],
				'email' => $player['email'],
				'phone' => '' !== $player['phone'] ? $player['phone'] : null,
				'club'  => '' !== $player['club'] ? $player['club'] : null,
			)
		);
		return (int) $wpdb->insert_id;
	}
}
