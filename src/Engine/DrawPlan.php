<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Turns confirmed entrants into an assignment (groups, or seed order) and the
 * assignment into matches. Pure logic, no WordPress dependencies.
 *
 * The random part (the draw) only produces the assignment. Matches are always
 * rebuilt deterministically from the assignment, so a manual swap of two pairs
 * is just "change the assignment and rebuild".
 *
 * Entrant: ['id' => int, 'rating' => float|null] (higher rating = stronger).
 */
final class DrawPlan {

	/** How many seeded entrants a knockout gets: 2 (<6), 4 (6–23), 8 (24+). */
	public static function seeded_count( int $entrants ): int {
		if ( $entrants >= 24 ) {
			return 8;
		}
		if ( $entrants >= 6 ) {
			return 4;
		}
		return min( 2, max( 0, $entrants ) );
	}

	private static function teams( array $entrants ): array {
		return array_map(
			static fn( $e ) => array(
				'id'     => (int) $e['id'],
				'rating' => (float) ( $e['rating'] ?? 0 ),
				'club'   => null,
				'clubs'  => is_array( $e['clubs'] ?? null ) ? $e['clubs'] : array(),
			),
			$entrants
		);
	}

	/** Strongest first; ties by id (stable, deterministic). */
	public static function by_rating( array $entrants ): array {
		$teams = self::teams( $entrants );
		usort( $teams, static fn( $a, $b ) => ( $b['rating'] <=> $a['rating'] ) ?: ( $a['id'] <=> $b['id'] ) );
		return array_column( $teams, 'id' );
	}

	/**
	 * Random (seeded) group draw by pots.
	 *
	 * @return array<int, int[]> group index (0-based) => entrant ids, strongest first.
	 */
	public static function assign_groups( array $entrants, int $preferred_size, string $seed ): array {
		$count = Groups::count_for( count( $entrants ), $preferred_size );
		if ( $count < 1 ) {
			return array();
		}
		$rank   = array_flip( self::by_rating( $entrants ) );
		$groups = array();
		foreach ( Draw::groups( self::teams( $entrants ), $count, $seed ) as $g => $teams ) {
			$ids = array_column( $teams, 'id' );
			usort( $ids, static fn( $a, $b ) => $rank[ $a ] <=> $rank[ $b ] );
			$groups[ $g ] = $ids;
		}
		return $groups;
	}

	/**
	 * Random (seeded) knockout order: top seeds by rating, the rest drawn.
	 *
	 * @return int[] entrant ids by seed (index 0 = seed 1).
	 */
	public static function assign_seeds( array $entrants, string $seed ): array {
		$ordered = Draw::seed_teams( self::teams( $entrants ), self::seeded_count( count( $entrants ) ), $seed );
		return array_column( $ordered, 'id' );
	}

	/**
	 * Knockout matches from ids in seed order. Byes are resolved: the bye match
	 * has a winner and that winner already sits in its round-2 match.
	 *
	 * @return array<int, array{round:int, position:int, a:?int, b:?int, winner:?int, is_bye:bool, next_round:?int, next_pos:?int, next_slot:?string}>
	 */
	public static function knockout( array $seeded_ids ): array {
		$seeded_ids = array_values( $seeded_ids );
		if ( count( $seeded_ids ) < 2 ) {
			return array();
		}
		$id_of = static fn( $seed ) => null === $seed ? null : ( $seeded_ids[ $seed - 1 ] ?? null );
		$out   = array();
		foreach ( Bracket::generate( count( $seeded_ids ) ) as $m ) {
			$a     = $id_of( $m['seed_a'] );
			$b     = $id_of( $m['seed_b'] );
			$out[] = array(
				'round'      => $m['round'],
				'position'   => $m['position'],
				'a'          => $a,
				'b'          => $b,
				'winner'     => $m['is_bye'] ? ( $a ?? $b ) : null,
				'is_bye'     => $m['is_bye'],
				'next_round' => $m['next_round'],
				'next_pos'   => $m['next_pos'],
				'next_slot'  => $m['next_slot'],
			);
		}
		return $out;
	}

	/**
	 * Consolation bracket for the losers of round 1 of the main bracket.
	 *
	 * Only real round-1 matches feed it (pairs that had a bye do not play the
	 * consolation). Loser of the k-th real round-1 match (by position) = consolation
	 * seed k. Slots stay empty until the result is known; empty "bye" matches give
	 * their single entrant a walk-through as soon as it arrives.
	 *
	 * @param array $main rows from knockout() (main bracket)
	 * @return array{matches: array, feeds: array<int, array{round:int, position:int, slot:string}>}
	 *   matches: like knockout() but a/b/winner are null; feeds: main round-1 position => consolation slot.
	 */
	public static function consolation( array $main ): array {
		$feeders = array();
		foreach ( $main as $m ) {
			if ( 1 === (int) $m['round'] && ! $m['is_bye'] ) {
				$feeders[] = (int) $m['position'];
			}
		}
		sort( $feeders );
		$n = count( $feeders );
		if ( $n < 2 ) {
			return array(
				'matches' => array(),
				'feeds'   => array(),
			);
		}

		$matches = array();
		$feeds   = array();
		foreach ( Bracket::generate( $n ) as $m ) {
			$matches[] = array(
				'round'      => $m['round'],
				'position'   => $m['position'],
				'a'          => null,
				'b'          => null,
				'winner'     => null,
				'is_bye'     => $m['is_bye'],
				'next_round' => $m['next_round'],
				'next_pos'   => $m['next_pos'],
				'next_slot'  => $m['next_slot'],
			);
			if ( 1 === $m['round'] ) {
				foreach ( array( 'a' => $m['seed_a'], 'b' => $m['seed_b'] ) as $slot => $seed ) {
					if ( null !== $seed ) {
						$feeds[ $feeders[ $seed - 1 ] ] = array(
							'round'    => 1,
							'position' => $m['position'],
							'slot'     => $slot,
						);
					}
				}
			}
		}
		// Bracket::generate() pre-advances bye seeds into round 2; here nobody is known yet.
		foreach ( $matches as &$m ) {
			if ( $m['round'] > 1 ) {
				$m['a'] = null;
				$m['b'] = null;
			}
		}
		return array(
			'matches' => $matches,
			'feeds'   => $feeds,
		);
	}

	/** Name key for a knockout round: final | semi | quarter | r16 | round. */
	public static function round_key( int $round, int $total_rounds ): string {
		$from_end = $total_rounds - $round;
		return array( 'final', 'semi', 'quarter', 'r16' )[ $from_end ] ?? 'round';
	}

	/** Swap two entrants inside a groups assignment (keeps each group ordered by rating rank). */
	public static function swap_in_groups( array $groups, int $x, int $y, array $rank ): array {
		foreach ( $groups as $g => $ids ) {
			foreach ( $ids as $i => $id ) {
				if ( $id === $x ) {
					$groups[ $g ][ $i ] = $y;
				} elseif ( $id === $y ) {
					$groups[ $g ][ $i ] = $x;
				}
			}
			usort( $groups[ $g ], static fn( $a, $b ) => ( $rank[ $a ] ?? PHP_INT_MAX ) <=> ( $rank[ $b ] ?? PHP_INT_MAX ) );
		}
		return $groups;
	}

	/** Swap two entrants in a seed order. */
	public static function swap_in_seeds( array $seeded_ids, int $x, int $y ): array {
		$ix = array_search( $x, $seeded_ids, true );
		$iy = array_search( $y, $seeded_ids, true );
		if ( false !== $ix && false !== $iy ) {
			$seeded_ids[ $ix ] = $y;
			$seeded_ids[ $iy ] = $x;
		}
		return $seeded_ids;
	}
}
