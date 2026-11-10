<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Match format and score validation. Pure logic, no WordPress dependencies.
 *
 * Format: best_of (1|3|5 sets), games per set (6 by default; 7-6 with tie-break),
 * deciding set played as a normal set or as a super tie-break (to 10, by 2).
 *
 * Result shape:
 *   [ 'sets' => [[6,4],[3,6],[10,8]], 'walkover' => null|'a'|'b' (side that WINS by W.O.),
 *     'retired' => null|'a'|'b' (side that RETIRES) ]
 * A super tie-break counts as one set and as a 1-0 in games.
 * A walkover counts as winning every needed set 6-0 (games-per-set to 0).
 */
final class MatchFormat {

	public static function defaults(): array {
		return array(
			'best_of'  => 3,
			'games'    => 6,
			'deciding' => 'super_tiebreak',
			'points'   => 10,
		);
	}

	public static function normalize( array $raw ): array {
		$f = self::defaults();
		if ( in_array( (int) ( $raw['best_of'] ?? 0 ), array( 1, 3, 5 ), true ) ) {
			$f['best_of'] = (int) $raw['best_of'];
		}
		$games = (int) ( $raw['games'] ?? 0 );
		if ( $games >= 4 && $games <= 9 ) {
			$f['games'] = $games;
		}
		if ( in_array( $raw['deciding'] ?? '', array( 'set', 'super_tiebreak' ), true ) ) {
			$f['deciding'] = $raw['deciding'];
		}
		$points = (int) ( $raw['points'] ?? 0 );
		if ( $points >= 7 && $points <= 15 ) {
			$f['points'] = $points;
		}
		if ( 1 === $f['best_of'] ) {
			$f['deciding'] = 'set'; // A single set is never a super tie-break.
		}
		return $f;
	}

	public static function sets_to_win( array $f ): int {
		return intdiv( $f['best_of'], 2 ) + 1;
	}

	/** Complete set? Normal set: 6-0..6-4, 7-5, 7-6. Super tie-break: first to N by 2. */
	public static function is_complete_set( int $a, int $b, array $f, bool $super ): bool {
		if ( $a < 0 || $b < 0 || $a === $b ) {
			return false;
		}
		$max = max( $a, $b );
		$min = min( $a, $b );
		if ( $super ) {
			return $max >= $f['points'] && ( $max - $min ) >= 2 && ( $max === $f['points'] || $max - $min === 2 );
		}
		$g = $f['games'];
		return ( $max === $g && $min <= $g - 2 ) || ( $max === $g + 1 && ( $min === $g - 1 || $min === $g ) );
	}

	/**
	 * @return array{valid:bool, error:?string, winner:?string, sets:array{0:int,1:int}, games:array{0:int,1:int}}
	 *   error: invalid_set | too_many_sets | incomplete | bad_side | empty
	 */
	public static function evaluate( array $result, array $f ): array {
		$out  = array(
			'valid'  => false,
			'error'  => null,
			'winner' => null,
			'sets'   => array( 0, 0 ),
			'games'  => array( 0, 0 ),
		);
		$need = self::sets_to_win( $f );

		$walkover = $result['walkover'] ?? null;
		if ( null !== $walkover ) {
			if ( ! in_array( $walkover, array( 'a', 'b' ), true ) ) {
				$out['error'] = 'bad_side';
				return $out;
			}
			$i                  = 'a' === $walkover ? 0 : 1;
			$out['sets'][ $i ]  = $need;
			$out['games'][ $i ] = $need * $f['games'];
			$out['winner']      = $walkover;
			$out['valid']       = true;
			return $out;
		}

		$retired = $result['retired'] ?? null;
		if ( null !== $retired && ! in_array( $retired, array( 'a', 'b' ), true ) ) {
			$out['error'] = 'bad_side';
			return $out;
		}

		$sets  = is_array( $result['sets'] ?? null ) ? array_values( $result['sets'] ) : array();
		$count = count( $sets );
		if ( 0 === $count && null === $retired ) {
			$out['error'] = 'empty';
			return $out;
		}

		foreach ( $sets as $i => $set ) {
			if ( max( $out['sets'] ) >= $need ) {
				$out['error'] = 'too_many_sets';
				return $out;
			}
			$a     = (int) ( $set[0] ?? -1 );
			$b     = (int) ( $set[1] ?? -1 );
			$super = 'super_tiebreak' === $f['deciding'] && $out['sets'][0] === $need - 1 && $out['sets'][1] === $need - 1;
			$last  = $i === $count - 1;

			if ( ! self::is_complete_set( $a, $b, $f, $super ) ) {
				// Only the last set of a retirement may be unfinished.
				if ( null !== $retired && $last && $a >= 0 && $b >= 0 ) {
					if ( ! $super ) {
						$out['games'][0] += $a;
						$out['games'][1] += $b;
					}
					continue;
				}
				$out['error'] = 'invalid_set';
				return $out;
			}
			$w = $a > $b ? 0 : 1;
			++$out['sets'][ $w ];
			if ( $super ) {
				++$out['games'][ $w ];
			} else {
				$out['games'][0] += $a;
				$out['games'][1] += $b;
			}
		}

		if ( null !== $retired ) {
			if ( max( $out['sets'] ) >= $need ) {
				$out['error'] = 'too_many_sets'; // Already decided: it was not a retirement.
				return $out;
			}
			$out['winner'] = 'a' === $retired ? 'b' : 'a';
			$out['valid']  = true;
			return $out;
		}

		if ( max( $out['sets'] ) < $need ) {
			$out['error'] = 'incomplete';
			return $out;
		}
		$out['winner'] = $out['sets'][0] > $out['sets'][1] ? 'a' : 'b';
		$out['valid']  = true;
		return $out;
	}
}
