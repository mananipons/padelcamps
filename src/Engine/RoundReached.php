<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Furthest result reached by every entrant of a category, expressed with the keys
 * of the points scheme (see PointsScheme). Pure logic, no WordPress dependencies.
 *
 * Rules:
 *  - groups_knockout / knockout: decided by the main knockout bracket only. The final
 *    winner is champion, the final loser runner_up, then semifinal / quarterfinal /
 *    round_of_16 by the round the entrant lost. Everyone else (lost earlier in a big
 *    bracket, or left in the group stage) is participation. The consolation bracket
 *    never changes the result.
 *  - round_robin: decided by the final standings, as if it were an elimination
 *    bracket with the same number of places: 1st champion, 2nd runner_up, 3rd–4th
 *    semifinal, 5th–8th quarterfinal, 9th–16th round_of_16, the rest participation.
 *
 * A category is "complete" only when its deciding stage is finished (final winner
 * known, or every round-robin match decided), so points are never awarded halfway.
 *
 * A pair that withdrew (it never took the court: no decided match where it was not the
 * side giving a walkover) gets no result at all, so no points. It is listed in
 * `withdrawn`. Pairs that played at least once keep their normal result, even if they
 * later gave a walkover or retired.
 */
final class RoundReached {

	/**
	 * @param string  $format   FormatTemplates id: groups_knockout | knockout | round_robin
	 * @param int[]   $entrants Registration ids that took part (confirmed registrations)
	 * @param array<int, array{phase:string, round:int, a:?int, b:?int, winner:?int, walkover_loser?:?int}> $matches
	 *        phase is the phase type: groups | knockout | consolation | round_robin;
	 *        walkover_loser is the registration that did not show up in a walkover (null otherwise)
	 * @param int[]   $ranking  round_robin only: registration ids by final position (1st first)
	 * @return array{complete:bool, results:array<int,string>, withdrawn:int[]}
	 *         results: registration id => scheme key; withdrawn: entrants that never played, who get no result
	 */
	public static function compute( string $format, array $entrants, array $matches, array $ranking = array() ): array {
		$entrants = array_values( array_unique( array_map( 'intval', $entrants ) ) );

		if ( 'round_robin' === $format ) {
			$res = self::from_round_robin( $entrants, $matches, $ranking );
		} elseif ( 'groups_knockout' === $format || 'knockout' === $format ) {
			$res = self::from_knockout( $entrants, $matches );
		} else {
			$res = array(
				'complete' => false,
				'results'  => array(),
			);
		}

		$res['withdrawn'] = array();
		if ( $res['complete'] ) {
			// A pair that never took the court (withdrew, or gave every walkover) earns nothing.
			$res['withdrawn'] = self::withdrawn( $entrants, $matches );
			foreach ( $res['withdrawn'] as $id ) {
				unset( $res['results'][ $id ] );
			}
		}
		return $res;
	}

	/**
	 * Entrants with no match they actually took part in. A match counts when both sides
	 * were known, it was decided and the pair did not give the walkover. Byes do not count.
	 *
	 * @return int[]
	 */
	private static function withdrawn( array $entrants, array $matches ): array {
		$played = array();
		foreach ( $matches as $m ) {
			$a = (int) ( $m['a'] ?? 0 );
			$b = (int) ( $m['b'] ?? 0 );
			if ( $a < 1 || $b < 1 || empty( $m['winner'] ) ) {
				continue;
			}
			$gave_up = (int) ( $m['walkover_loser'] ?? 0 );
			foreach ( array( $a, $b ) as $id ) {
				if ( $id !== $gave_up ) {
					$played[ $id ] = true;
				}
			}
		}
		return array_values( array_filter( $entrants, static fn( $id ) => ! isset( $played[ $id ] ) ) );
	}

	/**
	 * Points per registration from the results.
	 *
	 * @param array<int,string> $results
	 * @param array<string,int> $scheme
	 * @return array<int,int>
	 */
	public static function points( array $results, array $scheme ): array {
		$out = array();
		foreach ( $results as $reg_id => $key ) {
			$out[ (int) $reg_id ] = PointsScheme::points_for( $scheme, $key );
		}
		return $out;
	}

	/** @return array{complete:bool, results:array<int,string>} */
	private static function from_knockout( array $entrants, array $matches ): array {
		$ko = array_values( array_filter( $matches, static fn( $m ) => 'knockout' === ( $m['phase'] ?? '' ) ) );
		if ( ! $ko ) {
			return array(
				'complete' => false,
				'results'  => array(),
			);
		}

		$total = 0;
		foreach ( $ko as $m ) {
			$total = max( $total, (int) $m['round'] );
		}
		$finals = array_values( array_filter( $ko, static fn( $m ) => (int) $m['round'] === $total ) );
		if ( 1 !== count( $finals ) || empty( $finals[0]['winner'] ) ) {
			return array(
				'complete' => false,
				'results'  => array(),
			);
		}

		// Last match of each entrant = the one in the highest round it reached.
		$last = array();
		foreach ( $ko as $m ) {
			foreach ( array( 'a', 'b' ) as $side ) {
				$id = (int) ( $m[ $side ] ?? 0 );
				if ( $id > 0 && ( ! isset( $last[ $id ] ) || (int) $m['round'] > (int) $last[ $id ]['round'] ) ) {
					$last[ $id ] = $m;
				}
			}
		}

		$results  = array();
		$complete = true;
		foreach ( $entrants as $id ) {
			if ( ! isset( $last[ $id ] ) ) {
				$results[ $id ] = 'participation'; // never reached the bracket (group stage exit).
				continue;
			}
			$m      = $last[ $id ];
			$winner = (int) ( $m['winner'] ?? 0 );
			if ( $winner === $id ) {
				if ( (int) $m['round'] === $total ) {
					$results[ $id ] = 'champion';
				} else {
					$complete       = false; // won a match but the next one is missing: inconsistent data.
					$results[ $id ] = 'participation';
				}
				continue;
			}
			if ( 0 === $winner ) {
				$complete = false; // its last match has no winner yet.
			}
			$results[ $id ] = self::lost_in( $total - (int) $m['round'] );
		}

		return array(
			'complete' => $complete,
			'results'  => $results,
		);
	}

	/** Key for an entrant that lost a match $from_end rounds before the end (0 = the final). */
	private static function lost_in( int $from_end ): string {
		$keys = array( 'runner_up', 'semifinal', 'quarterfinal', 'round_of_16' );
		return $keys[ $from_end ] ?? 'participation';
	}

	/** @return array{complete:bool, results:array<int,string>} */
	private static function from_round_robin( array $entrants, array $matches, array $ranking ): array {
		$rr = array_filter( $matches, static fn( $m ) => 'round_robin' === ( $m['phase'] ?? '' ) );
		if ( ! $rr || count( $entrants ) < 2 ) {
			return array(
				'complete' => false,
				'results'  => array(),
			);
		}
		foreach ( $rr as $m ) {
			if ( empty( $m['winner'] ) ) {
				return array(
					'complete' => false,
					'results'  => array(),
				);
			}
		}

		$position = array();
		foreach ( array_values( array_map( 'intval', $ranking ) ) as $i => $id ) {
			$position[ $id ] = $i + 1;
		}
		$results = array();
		foreach ( $entrants as $id ) {
			if ( ! isset( $position[ $id ] ) ) {
				return array(
					'complete' => false,
					'results'  => array(),
				);
			}
			$results[ $id ] = self::place_key( $position[ $id ] );
		}
		return array(
			'complete' => true,
			'results'  => $results,
		);
	}

	/** Round-robin final position => scheme key (see class doc). */
	private static function place_key( int $position ): string {
		if ( 1 === $position ) {
			return 'champion';
		}
		if ( 2 === $position ) {
			return 'runner_up';
		}
		if ( $position <= 4 ) {
			return 'semifinal';
		}
		if ( $position <= 8 ) {
			return 'quarterfinal';
		}
		if ( $position <= 16 ) {
			return 'round_of_16';
		}
		return 'participation';
	}
}
