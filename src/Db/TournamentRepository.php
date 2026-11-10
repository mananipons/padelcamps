<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for tournaments, categories and phases.
 * Callers must pass already sanitized/validated values.
 */
final class TournamentRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/** All tournaments with category and registration counts, newest dates first. */
	public function all(): array {
		global $wpdb;
		$t = self::t( 'tournaments' );
		$c = self::t( 'categories' );
		$r = self::t( 'registrations' );

		$sql = "SELECT t.*,
			(SELECT COUNT(*) FROM {$c} WHERE tournament_id = t.id) AS categories_count,
			(SELECT COUNT(*) FROM {$r} r INNER JOIN {$c} c2 ON c2.id = r.category_id WHERE c2.tournament_id = t.id) AS registrations_count
			FROM {$t} t
			ORDER BY COALESCE(t.starts_on, '9999-12-31') DESC, t.id DESC";

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = self::t( 'tournaments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function find_by_slug( string $slug ): ?array {
		global $wpdb;
		$t   = self::t( 'tournaments' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", $slug ), ARRAY_A );
		return $row ?: null;
	}

	/** Registration form configuration of a tournament (sanitized, with defaults). */
	public function form_config( array $tournament ): array {
		$settings = json_decode( (string) ( $tournament['settings'] ?? '' ), true );
		$raw      = is_array( $settings ) && isset( $settings['form'] ) && is_array( $settings['form'] ) ? $settings['form'] : array();
		return \PadelCamps\Engine\RegistrationForm::normalize( $raw );
	}

	public function settings( array $tournament ): array {
		$settings = json_decode( (string) ( $tournament['settings'] ?? '' ), true );
		return is_array( $settings ) ? $settings : array();
	}

	/** How player names appear publicly: 'full' (default) or 'initials'. */
	public function public_names( array $tournament ): string {
		$mode = $this->settings( $tournament )['public_names'] ?? 'full';
		return in_array( $mode, \PadelCamps\Engine\PublicName::MODES, true ) ? $mode : 'full';
	}

	public function save_setting( int $id, string $key, $value ): void {
		global $wpdb;
		$tournament = $this->find( $id );
		if ( ! $tournament ) {
			return;
		}
		$settings         = $this->settings( $tournament );
		$settings[ $key ] = $value;
		$wpdb->update( self::t( 'tournaments' ), array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $id ) );
	}

	public function save_form_config( int $id, array $config ): void {
		global $wpdb;
		$tournament = $this->find( $id );
		if ( ! $tournament ) {
			return;
		}
		$settings = json_decode( (string) ( $tournament['settings'] ?? '' ), true );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['form'] = $config;
		$wpdb->update( self::t( 'tournaments' ), array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $id ) );
	}

	public function unique_slug( string $name, int $ignore_id = 0 ): string {
		global $wpdb;
		$t    = self::t( 'tournaments' );
		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'torneig';
		$slug = $base;
		$i    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE slug = %s AND id <> %d", $slug, $ignore_id ) ) ) {
			$slug = $base . '-' . $i++;
		}
		return $slug;
	}

	/** @param array{name:string, starts_on:?string, ends_on:?string, venue:string, description:string} $data */
	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'tournaments' ),
			array(
				'name'        => $data['name'],
				'slug'        => $this->unique_slug( $data['name'] ),
				'status'      => 'draft',
				'starts_on'   => $data['starts_on'],
				'ends_on'     => $data['ends_on'],
				'venue'       => $data['venue'],
				'description' => $data['description'],
				'created_at'  => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Slug is kept on update so public URLs do not break. */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'tournaments' ),
			array(
				'name'        => $data['name'],
				'starts_on'   => $data['starts_on'],
				'ends_on'     => $data['ends_on'],
				'venue'       => $data['venue'],
				'description' => $data['description'],
			),
			array( 'id' => $id )
		);
	}

	public function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::t( 'tournaments' ), array( 'status' => $status ), array( 'id' => $id ) );
	}

	public function registrations_count( int $tournament_id ): int {
		global $wpdb;
		$r = self::t( 'registrations' );
		$c = self::t( 'categories' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d",
				$tournament_id
			)
		);
	}

	/** Raw cascade delete. The UI decides when this is allowed. */
	/** What deleting the tournament will remove (shown before confirming). */
	public function deletion_summary( int $id ): array {
		global $wpdb;
		$c   = self::t( 'categories' );
		$r   = self::t( 'registrations' );
		$m   = self::t( 'matches' );
		$rp  = self::t( 'registration_players' );
		$one = static fn( string $sql ): int => (int) $wpdb->get_var( $wpdb->prepare( $sql, $id ) );
		return array(
			'categories'    => $one( "SELECT COUNT(*) FROM {$c} WHERE tournament_id = %d" ),
			'registrations' => $one( "SELECT COUNT(*) FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d" ),
			'players'       => $one( "SELECT COUNT(DISTINCT rp.player_id) FROM {$rp} rp INNER JOIN {$r} r ON r.id = rp.registration_id INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d" ),
			'matches'       => $one( "SELECT COUNT(*) FROM {$m} m INNER JOIN {$c} c ON c.id = m.category_id WHERE c.tournament_id = %d AND m.status <> 'bye'" ),
			'played'        => $one( "SELECT COUNT(*) FROM {$m} m INNER JOIN {$c} c ON c.id = m.category_id WHERE c.tournament_id = %d AND m.status = 'played'" ),
			'emails'        => $one( 'SELECT COUNT(*) FROM ' . self::t( 'notifications_log' ) . ' WHERE tournament_id = %d' ),
		);
	}

	/**
	 * Delete a tournament and everything that hangs from it: categories, phases,
	 * registrations, players that are in no other tournament, matches, result and
	 * reschedule reports, draw log, courts and send log. Irreversible.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		$c   = self::t( 'categories' );
		$r   = self::t( 'registrations' );
		$rp  = self::t( 'registration_players' );
		$p   = self::t( 'players' );
		$m   = self::t( 'matches' );
		$cat = $wpdb->prepare( "SELECT id FROM {$c} WHERE tournament_id = %d", $id );
		$reg = "SELECT id FROM {$r} WHERE category_id IN ({$cat})";

		$wpdb->query( 'START TRANSACTION' );
		$player_ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT player_id FROM {$rp} WHERE registration_id IN ({$reg})" ) );

		$wpdb->query( 'DELETE FROM ' . self::t( 'result_reports' ) . " WHERE match_id IN (SELECT id FROM {$m} WHERE category_id IN ({$cat}))" );
		$wpdb->query( 'DELETE FROM ' . self::t( 'reschedule_requests' ) . " WHERE match_id IN (SELECT id FROM {$m} WHERE category_id IN ({$cat}))" );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'notifications_log' ) . " WHERE tournament_id = %d OR ( ref_type = 'registration' AND ref_id IN ({$reg}) )", $id ) );
		$wpdb->query( "DELETE FROM {$m} WHERE category_id IN ({$cat})" );
		$wpdb->query( 'DELETE FROM ' . self::t( 'draw_log' ) . " WHERE category_id IN ({$cat})" );
		$wpdb->query( 'DELETE FROM ' . self::t( 'phases' ) . " WHERE category_id IN ({$cat})" );
		$wpdb->query( "DELETE FROM {$rp} WHERE registration_id IN ({$reg})" );
		$wpdb->query( "DELETE FROM {$r} WHERE category_id IN ({$cat})" );
		$wpdb->delete( $c, array( 'tournament_id' => $id ) );
		$wpdb->delete( self::t( 'courts' ), array( 'tournament_id' => $id ) );
		$wpdb->delete( self::t( 'tournaments' ), array( 'id' => $id ) );
		// Players are shared between tournaments: remove only the ones left without any registration.
		if ( $player_ids ) {
			$list = implode( ',', $player_ids );
			$wpdb->query( "DELETE pl FROM {$p} pl LEFT JOIN {$rp} x ON x.player_id = pl.id WHERE pl.id IN ({$list}) AND x.id IS NULL" );
		}
		$wpdb->query( 'COMMIT' );
	}

	// --- Categories -------------------------------------------------------

	public function categories( int $tournament_id ): array {
		global $wpdb;
		$c = self::t( 'categories' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$c} WHERE tournament_id = %d ORDER BY sort_order ASC, id ASC", $tournament_id ),
			ARRAY_A
		) ?: array();
	}

	public function category( int $id ): ?array {
		global $wpdb;
		$c   = self::t( 'categories' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$c} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Insert (no 'id') or update (with 'id') a category.
	 *
	 * @param array{id?:int, tournament_id:int, name:string, format:string, registration_mode:string, max_teams:?int, price:float} $data
	 */
	public function save_category( array $data ): int {
		global $wpdb;
		$c   = self::t( 'categories' );
		$row = array(
			'name'              => $data['name'],
			'format'            => $data['format'],
			'registration_mode' => $data['registration_mode'],
			'max_teams'         => $data['max_teams'],
			'price'             => $data['price'],
		);

		if ( ! empty( $data['id'] ) ) {
			$wpdb->update( $c, $row, array( 'id' => (int) $data['id'] ) );
			return (int) $data['id'];
		}

		$row['tournament_id'] = (int) $data['tournament_id'];
		$row['sort_order']    = 1 + (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(sort_order), 0) FROM {$c} WHERE tournament_id = %d", $row['tournament_id'] )
		);
		$wpdb->insert( $c, $row );
		return (int) $wpdb->insert_id;
	}

	public function category_has_matches( int $id ): bool {
		global $wpdb;
		$m = self::t( 'matches' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$m} WHERE category_id = %d LIMIT 1", $id ) );
	}

	public function category_registrations_count( int $id ): int {
		global $wpdb;
		$r = self::t( 'registrations' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE category_id = %d", $id ) );
	}

	/** Raw cascade delete of a category and everything under it. */
	public function delete_category( int $id ): void {
		global $wpdb;
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );

		$wpdb->query(
			$wpdb->prepare( "DELETE rp FROM {$rp} rp INNER JOIN {$r} r ON r.id = rp.registration_id WHERE r.category_id = %d", $id )
		);
		$wpdb->delete( $r, array( 'category_id' => $id ) );
		$wpdb->query(
			$wpdb->prepare( 'DELETE rr FROM ' . self::t( 'result_reports' ) . ' rr INNER JOIN ' . self::t( 'matches' ) . ' m ON m.id = rr.match_id WHERE m.category_id = %d', $id )
		);
		$wpdb->delete( self::t( 'matches' ), array( 'category_id' => $id ) );
		$wpdb->delete( self::t( 'draw_log' ), array( 'category_id' => $id ) );
		$wpdb->delete( self::t( 'phases' ), array( 'category_id' => $id ) );
		$wpdb->delete( self::t( 'categories' ), array( 'id' => $id ) );
	}

	// --- Phases -----------------------------------------------------------

	public function phases( int $category_id ): array {
		global $wpdb;
		$p = self::t( 'phases' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$p} WHERE category_id = %d ORDER BY sort_order ASC, id ASC", $category_id ),
			ARRAY_A
		) ?: array();
	}

	/** Phases with decoded settings (for the bulk edit of categories). */
	public function phases_decoded( int $category_id ): array {
		return array_map(
			static function ( array $p ): array {
				$s             = json_decode( (string) $p['settings'], true );
				$p['settings'] = is_array( $s ) ? $s : array();
				return $p;
			},
			$this->phases( $category_id )
		);
	}

	public function update_phase_settings( int $phase_id, array $settings ): void {
		global $wpdb;
		$wpdb->update( self::t( 'phases' ), array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $phase_id ) );
	}

	/** True once the final bracket (knockout phase) has been created. */
	public function knockout_has_matches( int $category_id ): bool {
		global $wpdb;
		$m = self::t( 'matches' );
		$p = self::t( 'phases' );
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT m.id FROM {$m} m INNER JOIN {$p} p ON p.id = m.phase_id WHERE m.category_id = %d AND p.type IN ('knockout','consolation') LIMIT 1", $category_id )
		);
	}

	/** Places (0 = unlimited) and/or price; null keeps the current value. */
	public function update_category_numbers( int $id, ?int $max_teams, ?float $price ): void {
		global $wpdb;
		$row = array();
		if ( null !== $max_teams ) {
			$row['max_teams'] = $max_teams > 0 ? $max_teams : null;
		}
		if ( null !== $price ) {
			$row['price'] = $price;
		}
		if ( $row ) {
			$wpdb->update( self::t( 'categories' ), $row, array( 'id' => $id ) );
		}
	}

	/** @param array<int, array{type:string, sort_order:int, settings:array}> $phases */
	public function replace_phases( int $category_id, array $phases ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'phases' ), array( 'category_id' => $category_id ) );
		foreach ( $phases as $phase ) {
			$wpdb->insert(
				self::t( 'phases' ),
				array(
					'category_id' => $category_id,
					'type'        => $phase['type'],
					'sort_order'  => $phase['sort_order'],
					'settings'    => wp_json_encode( $phase['settings'] ),
				)
			);
		}
	}
}
