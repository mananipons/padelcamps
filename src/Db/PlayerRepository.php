<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the Players screen: list, profile (registrations and points), edit,
 * merge and delete. A player is identified by their DNI/NIE (see Engine\Dni).
 * Callers must pass already validated values (Engine\PlayerEdit).
 */
final class PlayerRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/**
	 * @param array{s?:string, no_dni?:bool} $filters
	 * @return array{rows: array, total: int}
	 */
	public function search( array $filters, int $page, int $per_page ): array {
		global $wpdb;
		$p  = self::t( 'players' );
		$rp = self::t( 'registration_players' );
		$r  = self::t( 'registrations' );
		$pt = self::t( 'ranking_points' );

		$where = ' WHERE 1=1';
		$s     = isset( $filters['s'] ) ? trim( (string) $filters['s'] ) : '';
		if ( '' !== $s ) {
			$like  = '%' . $wpdb->esc_like( $s ) . '%';
			$where .= $wpdb->prepare( ' AND (p.name LIKE %s OR p.email LIKE %s OR p.dni LIKE %s OR p.club LIKE %s OR p.phone LIKE %s)', $like, $like, $like, $like, $like );
		}
		if ( ! empty( $filters['no_dni'] ) ) {
			$where .= ' AND p.dni IS NULL';
		}

		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p} p{$where}" );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*,
				(SELECT COUNT(*) FROM {$rp} x INNER JOIN {$r} rr ON rr.id = x.registration_id WHERE x.player_id = p.id AND rr.status <> 'cancelled') AS registrations,
				(SELECT COALESCE(SUM(points), 0) FROM {$pt} WHERE player_id = p.id) AS points
				FROM {$p} p{$where} ORDER BY p.name ASC, p.id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		) ?: array();
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'players' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Find by numeric id or by DNI/NIE (already normalized). */
	public function find_by_ref( string $ref ): ?array {
		global $wpdb;
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) && strlen( $ref ) < 8 ) {
			return $this->find( (int) $ref );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'players' ) . ' WHERE dni = %s', $ref ), ARRAY_A );
		return $row ?: null;
	}

	/** Registrations the player is in, newest tournament first. */
	public function registrations( int $player_id ): array {
		global $wpdb;
		$rp = self::t( 'registration_players' );
		$r  = self::t( 'registrations' );
		$c  = self::t( 'categories' );
		$t  = self::t( 'tournaments' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id AS registration_id, r.status, r.created_at, c.id AS category_id, c.name AS category_name, t.id AS tournament_id, t.name AS tournament_name, t.starts_on
				FROM {$rp} x
				INNER JOIN {$r} r ON r.id = x.registration_id
				INNER JOIN {$c} c ON c.id = r.category_id
				INNER JOIN {$t} t ON t.id = c.tournament_id
				WHERE x.player_id = %d
				ORDER BY COALESCE(t.starts_on, '0000-00-00') DESC, r.id DESC",
				$player_id
			),
			ARRAY_A
		) ?: array();
	}

	/** Ranking points rows of the player (published results and manual adjustments). */
	public function points( int $player_id ): array {
		global $wpdb;
		$pt = self::t( 'ranking_points' );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT pt.*, s.name AS season_name, rc.name AS ranking_category_name, t.name AS tournament_name, c.name AS category_name
				FROM ' . $pt . ' pt
				LEFT JOIN ' . self::t( 'seasons' ) . ' s ON s.id = pt.season_id
				LEFT JOIN ' . self::t( 'ranking_categories' ) . ' rc ON rc.id = pt.ranking_category_id
				LEFT JOIN ' . self::t( 'tournaments' ) . ' t ON t.id = pt.tournament_id
				LEFT JOIN ' . self::t( 'categories' ) . ' c ON c.id = pt.category_id
				WHERE pt.player_id = %d
				ORDER BY pt.season_id DESC, pt.id DESC',
				$player_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Save a player's data (validated by Engine\PlayerEdit).
	 *
	 * @param array{name:string, dni:string, email:string, phone:string, club:string} $data
	 * @return ?string error code: not_found | dni_taken, or null when saved
	 */
	public function update( int $id, array $data ): ?string {
		global $wpdb;
		if ( ! $this->find( $id ) ) {
			return 'not_found';
		}
		if ( '' !== $data['dni'] ) {
			$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'players' ) . ' WHERE dni = %s AND id <> %d', $data['dni'], $id ) );
			if ( $owner ) {
				return 'dni_taken';
			}
		}
		$wpdb->update(
			self::t( 'players' ),
			array(
				'name'  => $data['name'],
				'dni'   => '' !== $data['dni'] ? $data['dni'] : null,
				'email' => $data['email'],
				'phone' => '' !== $data['phone'] ? $data['phone'] : null,
				'club'  => '' !== $data['club'] ? $data['club'] : null,
			),
			array( 'id' => $id )
		);
		return null;
	}

	/**
	 * Merge two rows of the same person: everything of `$remove` (registrations, points) moves to
	 * `$keep`, which also takes the DNI, phone and club it was missing; `$remove` is deleted.
	 *
	 * Refused when the two have different DNI (they are different people by definition) or when
	 * both are in the same registration (merging would leave a pair with the same player twice).
	 *
	 * @return ?string error code: not_found | same | dni_conflict | same_registration, or null when merged
	 */
	public function merge( int $keep_id, int $remove_id ): ?string {
		global $wpdb;
		if ( $keep_id === $remove_id ) {
			return 'same';
		}
		$keep   = $this->find( $keep_id );
		$remove = $this->find( $remove_id );
		if ( ! $keep || ! $remove ) {
			return 'not_found';
		}
		if ( null !== $keep['dni'] && null !== $remove['dni'] ) {
			return 'dni_conflict';
		}
		$rp = self::t( 'registration_players' );
		$shared = $wpdb->get_var(
			$wpdb->prepare( "SELECT a.registration_id FROM {$rp} a INNER JOIN {$rp} b ON b.registration_id = a.registration_id WHERE a.player_id = %d AND b.player_id = %d LIMIT 1", $keep_id, $remove_id )
		);
		if ( $shared ) {
			return 'same_registration';
		}

		$p = self::t( 'players' );
		$wpdb->query( 'START TRANSACTION' );
		$fill = array();
		if ( null === $keep['dni'] && null !== $remove['dni'] ) {
			$wpdb->update( $p, array( 'dni' => null ), array( 'id' => $remove_id ) ); // free the unique value first.
			$fill['dni'] = $remove['dni'];
		}
		foreach ( array( 'phone', 'club' ) as $field ) {
			if ( '' === (string) $keep[ $field ] && '' !== (string) $remove[ $field ] ) {
				$fill[ $field ] = $remove[ $field ];
			}
		}
		if ( '' === (string) $keep['email'] && '' !== (string) $remove['email'] ) {
			$fill['email'] = $remove['email'];
		}
		if ( $fill ) {
			$wpdb->update( $p, $fill, array( 'id' => $keep_id ) );
		}
		$wpdb->update( $rp, array( 'player_id' => $keep_id ), array( 'player_id' => $remove_id ) );
		$wpdb->update( self::t( 'ranking_points' ), array( 'player_id' => $keep_id ), array( 'player_id' => $remove_id ) );
		$wpdb->delete( $p, array( 'id' => $remove_id ) );
		$wpdb->query( 'COMMIT' );
		return null;
	}

	/** True when the player is in no registration at all. */
	public function is_unused( int $id ): bool {
		global $wpdb;
		return ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'registration_players' ) . ' WHERE player_id = %d LIMIT 1', $id ) );
	}

	/**
	 * Erase a player that is in no registration (right to erasure), with their points and adjustments.
	 * A player with registrations is erased by deleting those registrations.
	 *
	 * @return ?string error code: not_found | in_use, or null when deleted
	 */
	public function delete_unused( int $id ): ?string {
		global $wpdb;
		if ( ! $this->find( $id ) ) {
			return 'not_found';
		}
		if ( ! $this->is_unused( $id ) ) {
			return 'in_use';
		}
		$wpdb->delete( self::t( 'players' ), array( 'id' => $id ) );
		( new RankingRepository() )->delete_orphans();
		return null;
	}
}
