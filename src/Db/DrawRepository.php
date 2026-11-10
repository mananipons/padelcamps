<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence of draws: the assignment (registrations.group_no / seed_no),
 * the matches of each phase, results and the draw log.
 */
final class DrawRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/**
	 * Confirmed registrations of a category, by current seed then rating, with
	 * 'clubs' (the clubs of its players, used to keep same-club pairs apart).
	 */
	public function confirmed_entrants( int $category_id ): array {
		global $wpdb;
		$r    = self::t( 'registrations' );
		$rp   = self::t( 'registration_players' );
		$p    = self::t( 'players' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.rating, r.group_no, r.seed_no, GROUP_CONCAT(NULLIF(TRIM(p.club), '') SEPARATOR '\n') AS clubs
				FROM {$r} r
				LEFT JOIN {$rp} rp ON rp.registration_id = r.id
				LEFT JOIN {$p} p ON p.id = rp.player_id
				WHERE r.category_id = %d AND r.status = 'confirmed'
				GROUP BY r.id, r.rating, r.group_no, r.seed_no
				ORDER BY r.seed_no IS NULL, r.seed_no ASC, r.rating DESC, r.id ASC",
				$category_id
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$row ) {
			$row['clubs'] = '' !== (string) $row['clubs'] ? array_values( array_unique( explode( "\n", (string) $row['clubs'] ) ) ) : array();
		}
		return $rows;
	}

	public function pending_count( int $category_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'registrations' ) . " WHERE category_id = %d AND status = 'pending'", $category_id ) );
	}

	/**
	 * @return array{group:?array, knockout:?array, consolation:?array} phase rows
	 *         (group = groups or round_robin; consolation is created on demand).
	 */
	public function phases( int $category_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'phases' ) . ' WHERE category_id = %d ORDER BY sort_order, id', $category_id ), ARRAY_A ) ?: array();
		$out  = array(
			'group'       => null,
			'knockout'    => null,
			'consolation' => null,
		);
		foreach ( $rows as $row ) {
			$row['settings'] = json_decode( (string) $row['settings'], true ) ?: array();
			if ( in_array( $row['type'], array( 'groups', 'round_robin' ), true ) && ! $out['group'] ) {
				$out['group'] = $row;
			} elseif ( 'knockout' === $row['type'] && ! $out['knockout'] ) {
				$out['knockout'] = $row;
			} elseif ( 'consolation' === $row['type'] && ! $out['consolation'] ) {
				$out['consolation'] = $row;
			}
		}
		return $out;
	}

	/**
	 * The consolation phase row of a category, created when first needed (same
	 * sort order as the knockout, so both brackets are scheduled round by round).
	 */
	public function ensure_consolation_phase( int $category_id, array $knockout_phase ): array {
		global $wpdb;
		$existing = $this->phases( $category_id )['consolation'];
		if ( $existing ) {
			return $existing;
		}
		$wpdb->insert(
			self::t( 'phases' ),
			array(
				'category_id' => $category_id,
				'type'        => 'consolation',
				'sort_order'  => (int) $knockout_phase['sort_order'],
				'settings'    => wp_json_encode( array( 'format' => $knockout_phase['settings']['format'] ?? array() ) ),
			)
		);
		return $this->phases( $category_id )['consolation'];
	}

	/**
	 * Insert the consolation bracket and tell each main round-1 match where its loser goes.
	 *
	 * @param array $plan  from Engine\DrawPlan::consolation()
	 * @param array $main_r1_ids main round-1 position => match id
	 */
	public function insert_consolation( int $category_id, int $phase_id, array $plan, array $main_r1_ids ): void {
		global $wpdb;
		$this->insert_knockout( $category_id, $phase_id, $plan['matches'] );
		$ids = array();
		foreach ( $this->matches( $category_id ) as $m ) {
			if ( (int) $m['phase_id'] === $phase_id ) {
				$ids[ $m['round'] . ':' . $m['position'] ] = (int) $m['id'];
			}
		}
		foreach ( $plan['feeds'] as $position => $target ) {
			if ( isset( $main_r1_ids[ $position ], $ids[ $target['round'] . ':' . $target['position'] ] ) ) {
				$wpdb->update(
					self::t( 'matches' ),
					array(
						'loser_next_match_id' => $ids[ $target['round'] . ':' . $target['position'] ],
						'loser_next_slot'     => $target['slot'],
					),
					array( 'id' => $main_r1_ids[ $position ] )
				);
			}
		}
	}

	/** Winner of a match without changing its score (used for consolation byes). */
	public function set_winner( int $match_id, ?int $winner ): void {
		global $wpdb;
		$wpdb->update( self::t( 'matches' ), array( 'winner_reg_id' => $winner ), array( 'id' => $match_id ) );
	}

	public function matches( int $category_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::t( 'matches' ) . ' WHERE category_id = %d ORDER BY phase_id, group_no, round, position, id', $category_id ),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$row ) {
			$score        = json_decode( (string) $row['score'], true );
			$row['score'] = is_array( $score ) ? $score : null;
		}
		return $rows;
	}

	public function find_match( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'matches' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$score        = json_decode( (string) $row['score'], true );
		$row['score'] = is_array( $score ) ? $score : null;
		return $row;
	}

	public function has_results( int $category_id, ?int $phase_id = null ): bool {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT id FROM ' . self::t( 'matches' ) . " WHERE category_id = %d AND status = 'played'", $category_id );
		if ( null !== $phase_id ) {
			$sql .= $wpdb->prepare( ' AND phase_id = %d', $phase_id );
		}
		return (bool) $wpdb->get_var( $sql . ' LIMIT 1' );
	}

	public function count_matches( int $category_id, ?int $phase_id = null ): int {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'matches' ) . ' WHERE category_id = %d', $category_id );
		if ( null !== $phase_id ) {
			$sql .= $wpdb->prepare( ' AND phase_id = %d', $phase_id );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/** Delete every match of the category and forget the assignment. */
	public function clear( int $category_id ): void {
		global $wpdb;
		$wpdb->delete( self::t( 'matches' ), array( 'category_id' => $category_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'registrations' ) . ' SET group_no = NULL, seed_no = NULL WHERE category_id = %d', $category_id ) );
	}

	public function delete_phase_matches( int $category_id, int $phase_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::t( 'matches' ),
			array(
				'category_id' => $category_id,
				'phase_id'    => $phase_id,
			)
		);
	}

	/**
	 * @param array<int,int[]> $groups 0-based group => ids
	 * @param int[] $seed_order all ids, best first (stored as seed_no)
	 */
	public function save_groups( int $category_id, array $groups, array $seed_order ): void {
		global $wpdb;
		$r = self::t( 'registrations' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$r} SET group_no = NULL, seed_no = NULL WHERE category_id = %d", $category_id ) );
		$seed = array_flip( array_values( $seed_order ) );
		foreach ( $groups as $g => $ids ) {
			foreach ( $ids as $id ) {
				$wpdb->update(
					$r,
					array(
						'group_no' => $g + 1,
						'seed_no'  => ( $seed[ $id ] ?? 998 ) + 1,
					),
					array( 'id' => (int) $id )
				);
			}
		}
	}

	/** @param int[] $seed_ids ids by seed */
	public function save_seeds( int $category_id, array $seed_ids ): void {
		global $wpdb;
		$r = self::t( 'registrations' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$r} SET group_no = NULL, seed_no = NULL WHERE category_id = %d", $category_id ) );
		foreach ( array_values( $seed_ids ) as $i => $id ) {
			$wpdb->update( $r, array( 'seed_no' => $i + 1 ), array( 'id' => (int) $id ) );
		}
	}

	/** @return array<int,int[]> 0-based group => ids ordered by seed */
	public function current_groups( int $category_id ): array {
		$groups = array();
		foreach ( $this->confirmed_entrants( $category_id ) as $e ) {
			if ( null !== $e['group_no'] ) {
				$groups[ (int) $e['group_no'] - 1 ][] = (int) $e['id'];
			}
		}
		ksort( $groups );
		return $groups;
	}

	/** @return int[] ids by seed */
	public function current_seeds( int $category_id ): array {
		$ids = array();
		foreach ( $this->confirmed_entrants( $category_id ) as $e ) {
			if ( null !== $e['seed_no'] ) {
				$ids[] = (int) $e['id'];
			}
		}
		return $ids;
	}

	/** @param array<int, array{group:int, round:int, a:int, b:int}> $matches from Engine\Groups::matches() */
	public function insert_group_matches( int $category_id, int $phase_id, array $matches ): void {
		global $wpdb;
		$position = array();
		foreach ( $matches as $m ) {
			$key = $m['group'] . ':' . $m['round'];
			$pos = $position[ $key ] = ( $position[ $key ] ?? -1 ) + 1;
			$wpdb->insert(
				self::t( 'matches' ),
				array(
					'category_id' => $category_id,
					'phase_id'    => $phase_id,
					'group_no'    => $m['group'] + 1,
					'round'       => $m['round'],
					'position'    => $pos,
					'reg_a_id'    => $m['a'],
					'reg_b_id'    => $m['b'],
					'status'      => 'scheduled',
				)
			);
		}
	}

	/** @param array $ko from Engine\DrawPlan::knockout() */
	public function insert_knockout( int $category_id, int $phase_id, array $ko ): void {
		global $wpdb;
		$m   = self::t( 'matches' );
		$ids = array();
		foreach ( $ko as $row ) {
			$wpdb->insert(
				$m,
				array(
					'category_id'   => $category_id,
					'phase_id'      => $phase_id,
					'round'         => $row['round'],
					'position'      => $row['position'],
					'reg_a_id'      => $row['a'],
					'reg_b_id'      => $row['b'],
					'winner_reg_id' => $row['winner'],
					'status'        => $row['is_bye'] ? 'bye' : 'scheduled',
				)
			);
			$ids[ $row['round'] . ':' . $row['position'] ] = (int) $wpdb->insert_id;
		}
		foreach ( $ko as $row ) {
			if ( null === $row['next_round'] ) {
				continue;
			}
			$wpdb->update(
				$m,
				array(
					'next_match_id' => $ids[ $row['next_round'] . ':' . $row['next_pos'] ] ?? null,
					'next_slot'     => $row['next_slot'],
				),
				array( 'id' => $ids[ $row['round'] . ':' . $row['position'] ] )
			);
		}
	}

	public function update_result( int $match_id, ?array $score, ?int $winner, string $status ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'matches' ),
			array(
				'score'         => null === $score ? null : wp_json_encode( $score ),
				'winner_reg_id' => $winner,
				'status'        => $status,
			),
			array( 'id' => $match_id )
		);
	}

	public function set_slot( int $match_id, string $slot, ?int $reg_id ): void {
		global $wpdb;
		$wpdb->update( self::t( 'matches' ), array( 'a' === $slot ? 'reg_a_id' : 'reg_b_id' => $reg_id ), array( 'id' => $match_id ) );
	}

	public function set_category_seed( int $category_id, string $seed ): void {
		global $wpdb;
		$wpdb->update( self::t( 'categories' ), array( 'seed' => $seed ), array( 'id' => $category_id ) );
	}

	public function log( int $category_id, ?int $phase_id, string $seed, array $params, array $result ): void {
		global $wpdb;
		$wpdb->insert(
			self::t( 'draw_log' ),
			array(
				'category_id' => $category_id,
				'phase_id'    => $phase_id,
				'user_id'     => get_current_user_id() ?: null,
				'seed'        => $seed,
				'params'      => wp_json_encode( $params ),
				'result'      => wp_json_encode( $result ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	public function last_log( int $category_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'draw_log' ) . ' WHERE category_id = %d ORDER BY id DESC LIMIT 1', $category_id ), ARRAY_A );
		return $row ?: null;
	}
}
