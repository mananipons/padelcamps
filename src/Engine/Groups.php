<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Group stage planning. Pure logic, no WordPress dependencies.
 * The actual distribution of teams into groups is Draw::groups() (pots by rating).
 */
final class Groups {

	/**
	 * How many groups for N teams aiming at a preferred size, never leaving a
	 * group with fewer than 3 teams when it can be avoided.
	 * 8 → 2×4, 10 → 3,3,4, 6 → 2×3, 5 → 1×5, 12 → 3×4.
	 */
	public static function count_for( int $teams, int $preferred_size = 4 ): int {
		if ( $teams < 2 ) {
			return 0;
		}
		$preferred_size = max( 3, $preferred_size );
		$groups         = max( 1, (int) round( $teams / $preferred_size ) );
		while ( $groups > 1 && intdiv( $teams, $groups ) < 3 ) {
			--$groups;
		}
		return $groups;
	}

	/**
	 * Group-stage matches for every group.
	 *
	 * @param array<int, int[]> $groups group index => team ids
	 * @return array<int, array{group:int, round:int, a:int, b:int}>
	 */
	public static function matches( array $groups ): array {
		$out = array();
		foreach ( $groups as $g => $team_ids ) {
			foreach ( RoundRobin::rounds( $team_ids ) as $r => $pairs ) {
				foreach ( $pairs as $pair ) {
					$out[] = array(
						'group' => (int) $g,
						'round' => $r + 1,
						'a'     => $pair[0],
						'b'     => $pair[1],
					);
				}
			}
		}
		return $out;
	}
}
