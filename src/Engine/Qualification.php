<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * From group standings to the knockout bracket. Pure logic, no WordPress dependencies.
 *
 * Seeds: all group winners first (best record first), then all runners-up, etc.
 * Groups can have different sizes, so records are compared per match played.
 * Then lower levels (runners-up, thirds…) are placed so that, whenever possible:
 *   - nobody meets a team from its own group in round 1;
 *   - teams from the same group start in opposite halves (they can only meet in the final).
 * Output order = seed order, ready for Bracket::generate( count( $entrants ) ).
 */
final class Qualification {

	/**
	 * @param array<int, array<int, array>> $group_tables group index => Standings::compute() rows (ordered)
	 * @param array<int,int> $seed_rank Optional team => original seed (1 = best). Breaks identical records
	 *                                  before falling back to the group order.
	 * @return array{entrants:int[], group_of:array<int,int>}
	 */
	public static function knockout_entrants( array $group_tables, int $advance, array $seed_rank = array() ): array {
		$advance  = max( 1, $advance );
		$group_of = array();
		$levels   = array();

		for ( $pos = 1; $pos <= $advance; $pos++ ) {
			$level = array();
			foreach ( $group_tables as $g => $rows ) {
				foreach ( $rows as $row ) {
					if ( (int) $row['position'] === $pos ) {
						$row['group']               = (int) $g;
						$level[]                    = $row;
						$group_of[ (int) $row['team'] ] = (int) $g;
					}
				}
			}
			usort( $level, static fn( $x, $y ) => self::compare( $x, $y, $seed_rank ) );
			$levels[] = array_map( static fn( $r ) => (int) $r['team'], $level );
		}

		$n = array_sum( array_map( 'count', $levels ) );
		if ( $n < 2 ) {
			return array(
				'entrants' => array_merge( ...( $levels ?: array( array() ) ) ),
				'group_of' => $group_of,
			);
		}

		$size  = Bracket::size_for( $n );
		$order = Bracket::seed_order( $size );
		$slot  = array_flip( $order ); // seed => slot (0..size-1)

		$by_seed = array(); // seed => team
		$next    = 1;
		foreach ( $levels as $li => $teams ) {
			$free = range( $next, $next + count( $teams ) - 1 );
			$next += count( $teams );

			foreach ( $teams as $team ) {
				if ( 0 === $li ) {
					$seed = array_shift( $free );
				} else {
					$seed = self::best_seed( $team, $free, $by_seed, $group_of, $slot, $order, $size );
					$free = array_values( array_diff( $free, array( $seed ) ) );
				}
				$by_seed[ $seed ] = $team;
			}
		}
		ksort( $by_seed );

		return array(
			'entrants' => array_values( $by_seed ),
			'group_of' => $group_of,
		);
	}

	/** Better record first: win rate, set and game difference per match, original seed, then group index. */
	private static function compare( array $x, array $y, array $seed_rank ): int {
		$rate = static fn( array $r, string $k ): float => $r['played'] > 0 ? $r[ $k ] / $r['played'] : 0.0;
		$seed = static fn( array $r ): int => $seed_rank[ (int) $r['team'] ] ?? PHP_INT_MAX;
		return array( $rate( $y, 'won' ), $rate( $y, 'set_diff' ), $rate( $y, 'game_diff' ), $seed( $x ), $x['group'] )
			<=> array( $rate( $x, 'won' ), $rate( $x, 'set_diff' ), $rate( $x, 'game_diff' ), $seed( $y ), $y['group'] );
	}

	private static function best_seed( int $team, array $free, array $by_seed, array $group_of, array $slot, array $order, int $size ): int {
		$group = $group_of[ $team ];
		$best  = null;
		$score = -1;
		foreach ( $free as $seed ) {
			$s        = $slot[ $seed ];
			$opponent = $order[ $s ^ 1 ]; // Paired slot in round 1.
			$clash    = isset( $by_seed[ $opponent ] ) && $group_of[ $by_seed[ $opponent ] ] === $group;

			$half      = $s < $size / 2 ? 0 : 1;
			$same_half = false;
			foreach ( $by_seed as $placed_seed => $placed ) {
				if ( $group_of[ $placed ] === $group && ( $slot[ $placed_seed ] < $size / 2 ? 0 : 1 ) === $half ) {
					$same_half = true;
				}
			}
			$candidate = ( $clash ? 0 : 2 ) + ( $same_half ? 0 : 1 );
			if ( $candidate > $score ) {
				$score = $candidate;
				$best  = $seed;
			}
		}
		return (int) $best;
	}
}
