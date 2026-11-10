<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * "Everyone plays everyone" pairings (circle method). Deterministic: the same
 * list gives the same rounds. With an odd number of teams one team rests each
 * round. Pure logic, no WordPress dependencies.
 */
final class RoundRobin {

	/**
	 * @param int[] $team_ids
	 * @return array<int, array<int, array{0:int,1:int}>> rounds => list of [a, b]
	 */
	public static function rounds( array $team_ids ): array {
		$teams = array_values( $team_ids );
		if ( count( $teams ) < 2 ) {
			return array();
		}
		if ( 1 === count( $teams ) % 2 ) {
			$teams[] = null; // Rest.
		}
		$n      = count( $teams );
		$rounds = array();

		for ( $r = 0; $r < $n - 1; $r++ ) {
			$pairs = array();
			for ( $i = 0; $i < $n / 2; $i++ ) {
				$a = $teams[ $i ];
				$b = $teams[ $n - 1 - $i ];
				if ( null === $a || null === $b ) {
					continue;
				}
				// Alternate sides so nobody is always "team A".
				$pairs[] = 0 === ( $r + $i ) % 2 ? array( $a, $b ) : array( $b, $a );
			}
			$rounds[] = $pairs;

			// Rotate all but the first.
			$fixed = array_shift( $teams );
			$last  = array_pop( $teams );
			array_unshift( $teams, $last );
			array_unshift( $teams, $fixed );
		}
		return $rounds;
	}
}
