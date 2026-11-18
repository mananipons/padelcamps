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

	/**
	 * True when this person already has a non-cancelled registration in the category.
	 * The person is the DNI/NIE; players saved before the DNI was mandatory (no DNI yet)
	 * are still recognised by their email.
	 */
	public function has_active_registration( int $category_id, string $dni, string $email = '' ): bool {
		global $wpdb;
		if ( '' === $dni && '' === $email ) {
			return false;
		}
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );
		$p  = self::t( 'players' );

		$match = array();
		if ( '' !== $dni ) {
			$match[] = $wpdb->prepare( 'p.dni = %s', $dni );
		}
		if ( '' !== $email ) {
			$match[] = $wpdb->prepare( '(p.dni IS NULL AND p.email = %s)', $email );
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT r.id FROM {$r} r
				INNER JOIN {$rp} rp ON rp.registration_id = r.id
				INNER JOIN {$p} p ON p.id = rp.player_id
				WHERE r.category_id = %d AND r.status <> 'cancelled' AND (" . implode( ' OR ', $match ) . ') LIMIT 1',
				$category_id
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
		// The organizer (manual registration) chooses the status; the public form gets pending or waitlist.
		$status   = isset( $data['status'] ) && RegistrationStatus::is_valid( $data['status'] )
			? $data['status']
			: RegistrationForm::initial_status( null === $category['max_teams'] ? null : (int) $category['max_teams'], $occupied );
		// The category price is per player: each player pays their own fee.
		$price  = (float) $category['price'];
		$amount = round( $price * count( $data['players'] ), 2 );
		// Who has already paid (manual registration): 'player_payments' => [position => 'paid'|'pending'],
		// or 'payment_status' => 'paid' for everybody.
		$player_pay = array();
		foreach ( array_keys( $data['players'] ) as $i ) {
			if ( $price <= 0 ) {
				$player_pay[ $i ] = RegistrationStatus::PAYMENT_FREE;
				continue;
			}
			$wanted           = $data['player_payments'][ $i ] ?? ( $data['payment_status'] ?? '' );
			$player_pay[ $i ] = RegistrationStatus::PAYMENT_PAID === $wanted ? RegistrationStatus::PAYMENT_PAID : RegistrationStatus::PAYMENT_PENDING;
		}
		$payment = RegistrationStatus::aggregate( $player_pay );
		$now     = current_time( 'mysql' );
		$p1      = $data['players'][0];

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
		if ( ! empty( $data['source'] ) ) {
			$answers['source'] = (string) $data['source']; // 'admin' = entered by the organizer.
		}

		$inserted = $wpdb->insert(
			$r,
			array(
				'category_id'    => (int) $category['id'],
				'status'         => $status,
				'payment_status' => $payment,
				'paid_at'        => RegistrationStatus::PAYMENT_PAID === $payment ? $now : null,
				'amount'         => $amount,
				'contact_name'   => $p1['name'],
				'contact_email'  => $p1['email'],
				'contact_phone'  => $p1['phone'],
				'rating'         => $data['rating'] ?? null,
				'notes'          => $data['org_notes'] ?? null,
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
					'amount'          => $price > 0 ? $price : 0,
					'payment_status'  => $player_pay[ $i ],
					'paid_at'         => RegistrationStatus::PAYMENT_PAID === $player_pay[ $i ] ? $now : null,
				)
			) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );
		return array(
			'id'      => $registration_id,
			'status'  => $status,
			'amount'  => $amount, // whole pair
			'price'   => $price,  // per player
			'payment' => $payment,
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
		if ( 'unpaid' === ( $f['payment_status'] ?? '' ) ) {
			// Not fully paid yet: nobody paid, or only some of the players.
			$sql .= " AND r.payment_status IN ('pending','partial')";
		} elseif ( ! empty( $f['payment_status'] ) && RegistrationStatus::is_valid_payment( $f['payment_status'] ) ) {
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
			"SELECT rp.registration_id, rp.position, p.*, rp.id AS rp_id, rp.amount AS pay_amount, rp.payment_status AS pay_status, rp.paid_at AS pay_paid_at
			FROM {$rp} rp INNER JOIN {$p} p ON p.id = rp.player_id
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
		// Money is per player: each player pays their own fee.
		$money = $wpdb->get_results(
			'SELECT r.status, pp.payment_status, COALESCE(SUM(pp.amount), 0) AS total '
			. self::from() . ' INNER JOIN ' . self::t( 'registration_players' ) . ' pp ON pp.registration_id = r.id'
			. $this->where( $filters ) . ' GROUP BY r.status, pp.payment_status',
			ARRAY_A
		);
		return RegistrationStatus::summarize( $rows ?: array(), $money ?: array() );
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
		if ( ! $ids || ! RegistrationStatus::is_valid_player_payment( $payment ) || RegistrationStatus::PAYMENT_FREE === $payment ) {
			return 0;
		}
		// Every player of the registrations (the pair pays in full, or is refunded in full).
		$list    = implode( ',', $ids );
		$paid_at = RegistrationStatus::PAYMENT_PAID === $payment ? current_time( 'mysql' ) : null;
		$sql     = 'UPDATE ' . self::t( 'registration_players' ) . ' SET payment_status = %s, paid_at = ' . ( null === $paid_at ? 'NULL' : '%s' )
			. " WHERE registration_id IN ({$list}) AND amount > 0 AND payment_status <> %s";
		$args    = null === $paid_at ? array( $payment, $payment ) : array( $payment, $paid_at, $payment );
		$wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		return $this->sync_payment( $ids );
	}

	/**
	 * Payment of one player of a registration (position 1, 2…).
	 *
	 * @return bool whether something changed
	 */
	public function set_player_payment( int $registration_id, int $position, string $payment ): bool {
		global $wpdb;
		if ( ! RegistrationStatus::is_valid_player_payment( $payment ) || RegistrationStatus::PAYMENT_FREE === $payment ) {
			return false;
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::t( 'registration_players' ) . ' SET payment_status = %s, paid_at = ' . ( RegistrationStatus::PAYMENT_PAID === $payment ? '%s' : 'NULL' )
				. ' WHERE registration_id = %d AND position = %d AND amount > 0 AND payment_status <> %s',
				...( RegistrationStatus::PAYMENT_PAID === $payment
					? array( $payment, current_time( 'mysql' ), $registration_id, $position, $payment )
					: array( $payment, $registration_id, $position, $payment ) )
			)
		);
		$changed = $wpdb->rows_affected > 0;
		$this->sync_payment( array( $registration_id ) );
		return $changed;
	}

	/**
	 * Recompute each registration's amount and payment from its players.
	 *
	 * @param int[] $ids
	 * @return int registrations whose payment state changed
	 */
	public function sync_payment( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$list = implode( ',', $ids );
		$rows = $wpdb->get_results(
			'SELECT r.id, r.payment_status, GROUP_CONCAT(rp.payment_status ORDER BY rp.position) AS states,
				COALESCE(SUM(rp.amount), 0) AS amount, MAX(rp.paid_at) AS paid_at
			FROM ' . self::t( 'registrations' ) . ' r INNER JOIN ' . self::t( 'registration_players' ) . " rp ON rp.registration_id = r.id
			WHERE r.id IN ({$list}) GROUP BY r.id, r.payment_status",
			ARRAY_A
		) ?: array();
		$changed = 0;
		foreach ( $rows as $row ) {
			$state = RegistrationStatus::aggregate( explode( ',', (string) $row['states'] ) );
			$wpdb->update(
				self::t( 'registrations' ),
				array(
					'payment_status' => $state,
					'amount'         => (float) $row['amount'],
					'paid_at'        => RegistrationStatus::PAYMENT_PAID === $state ? $row['paid_at'] : null,
				),
				array( 'id' => (int) $row['id'] )
			);
			$changed += $state !== $row['payment_status'] ? 1 : 0;
		}
		return $changed;
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
			// Erased players take their ranking points (and adjustments) with them.
			( new RankingRepository() )->delete_orphans();
		}
		$wpdb->query( 'COMMIT' );
		return $deleted;
	}

	/**
	 * Organizer edits the players of a registration (name, email, phone, club).
	 * The contact data of the registration follows player 1.
	 *
	 * A DNI/NIE that already belongs to another player is refused (nothing is saved):
	 * if it is the same person, the two players must be merged instead.
	 *
	 * @param array<int, array{name:string, email:string, phone:string, club:string, dni:string}> $players by position (0 = player 1)
	 * @return array<string,string> errors by field (p1_dni / p2_dni => dni_taken); empty when saved
	 */
	public function update_players( int $registration_id, array $players ): array {
		global $wpdb;
		$rp  = self::t( 'registration_players' );
		$p   = self::t( 'players' );
		$ids = $wpdb->get_results( $wpdb->prepare( "SELECT player_id, position FROM {$rp} WHERE registration_id = %d ORDER BY position", $registration_id ), ARRAY_A ) ?: array();

		$errors = array();
		foreach ( $ids as $row ) {
			$player = $players[ (int) $row['position'] - 1 ] ?? null;
			if ( $player && '' !== (string) ( $player['dni'] ?? '' ) ) {
				$owner = $this->player_by_dni( (string) $player['dni'] );
				if ( $owner && (int) $owner['id'] !== (int) $row['player_id'] ) {
					$errors[ 'p' . (int) $row['position'] . '_dni' ] = 'dni_taken';
				}
			}
		}
		if ( $errors ) {
			return $errors;
		}

		foreach ( $ids as $row ) {
			$player = $players[ (int) $row['position'] - 1 ] ?? null;
			if ( ! $player ) {
				continue;
			}
			$wpdb->update(
				$p,
				array(
					'name'  => $player['name'],
					'email' => $player['email'],
					'dni'   => '' !== (string) ( $player['dni'] ?? '' ) ? $player['dni'] : null,
					'phone' => '' !== $player['phone'] ? $player['phone'] : null,
					'club'  => '' !== $player['club'] ? $player['club'] : null,
				),
				array( 'id' => (int) $row['player_id'] )
			);
		}
		$wpdb->update(
			self::t( 'registrations' ),
			array(
				'contact_name'  => $players[0]['name'],
				'contact_email' => $players[0]['email'],
				'contact_phone' => $players[0]['phone'],
			),
			array( 'id' => $registration_id )
		);
		return array();
	}

	/** Other registrations (not this one) that share a player row with it, to warn before editing. */
	public function shared_player_registrations( int $registration_id ): int {
		global $wpdb;
		$rp = self::t( 'registration_players' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT o.registration_id) FROM {$rp} me INNER JOIN {$rp} o ON o.player_id = me.player_id AND o.registration_id <> me.registration_id WHERE me.registration_id = %d",
				$registration_id
			)
		);
	}

	/** Same name ignoring case, accents and extra spaces. */
	private static function same_name( string $a, string $b ): bool {
		$norm = static fn( string $s ): string => strtolower( trim( (string) preg_replace( '/\s+/', ' ', remove_accents( $s ) ) ) );
		return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
	}

	/** The player that owns a DNI/NIE (already normalized), or null. */
	public function player_by_dni( string $dni ): ?array {
		global $wpdb;
		if ( '' === $dni ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'players' ) . ' WHERE dni = %s LIMIT 1', $dni ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Resolve a player. The DNI/NIE is the identity: the same DNI is always the same
	 * player, whatever name, email or phone is typed. Existing data is never overwritten
	 * (anyone could type someone else's DNI); only empty fields are completed.
	 *
	 * Players created before the DNI was mandatory have no DNI: when one of them registers
	 * again with the same email AND the same name, that row adopts the DNI (so history and
	 * points stay with the person). Anything less certain creates a new player instead:
	 * two people can share an email (family, club), and wrongly merging two people would
	 * mix their points. Real duplicates are resolved by merging them by hand.
	 */
	private function player_id( array $player ): int {
		global $wpdb;
		$p   = self::t( 'players' );
		$dni = (string) ( $player['dni'] ?? '' );

		$existing = null;
		if ( '' !== $dni ) {
			$existing = $this->player_by_dni( $dni );
			if ( ! $existing && '' !== $player['email'] ) {
				$legacy = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p} WHERE email = %s AND dni IS NULL ORDER BY id ASC", $player['email'] ), ARRAY_A ) ?: array();
				foreach ( $legacy as $row ) {
					if ( self::same_name( (string) $row['name'], (string) $player['name'] ) ) {
						$existing = $row;
						$wpdb->update( $p, array( 'dni' => $dni ), array( 'id' => (int) $row['id'] ) );
						break;
					}
				}
			}
		} elseif ( '' !== $player['email'] ) {
			// No DNI (only reachable from code paths that skip validation): legacy behaviour.
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p} WHERE email = %s ORDER BY id ASC LIMIT 1", $player['email'] ), ARRAY_A );
		}

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

		$inserted = $wpdb->insert(
			$p,
			array(
				'name'  => $player['name'],
				'email' => $player['email'],
				'dni'   => '' !== $dni ? $dni : null,
				'phone' => '' !== $player['phone'] ? $player['phone'] : null,
				'club'  => '' !== $player['club'] ? $player['club'] : null,
			)
		);
		if ( ! $inserted && '' !== $dni ) {
			// Two submissions with the same DNI raced: the other one won, reuse its row.
			$other = $this->player_by_dni( $dni );
			return $other ? (int) $other['id'] : 0;
		}
		return (int) $wpdb->insert_id;
	}
}
