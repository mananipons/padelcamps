<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

use InvalidArgumentException;

/**
 * Single-elimination bracket generator. Pure logic, no WordPress dependencies.
 *
 * Input: number of entrants (already ordered by seed: 1 = best).
 * Output: list of matches. Round 1 slots hold seed numbers (null = empty/bye);
 * later rounds start empty and are filled via next_round/next_pos/next_slot.
 * Byes are given to the top seeds and auto-advanced.
 */
final class Bracket {

	/**
	 * Standard seeding order for a bracket of $size (power of 2).
	 * 4 => [1,4,2,3]; 8 => [1,8,4,5,2,7,3,6]. Seeds 1 and 2 only meet in the final.
	 */
	public static function seed_order( int $size ): array {
		$order = array( 1 );
		while ( count( $order ) < $size ) {
			$sum  = count( $order ) * 2 + 1;
			$next = array();
			foreach ( $order as $s ) {
				$next[] = $s;
				$next[] = $sum - $s;
			}
			$order = $next;
		}
		return $order;
	}

	public static function size_for( int $entrants ): int {
		$size = 2;
		while ( $size < $entrants ) {
			$size *= 2;
		}
		return $size;
	}

	/**
	 * @return array<int, array<string, mixed>> flat list of matches.
	 */
	public static function generate( int $entrants ): array {
		if ( $entrants < 2 ) {
			throw new InvalidArgumentException( 'A bracket needs at least 2 entrants.' );
		}

		$size   = self::size_for( $entrants );
		$rounds = (int) log( $size, 2 );
		$order  = self::seed_order( $size );
		$out    = array();

		for ( $r = 1; $r <= $rounds; $r++ ) {
			$count = $size >> $r;
			for ( $p = 0; $p < $count; $p++ ) {
				$match = array(
					'round'      => $r,
					'position'   => $p,
					'seed_a'     => null,
					'seed_b'     => null,
					'next_round' => $r < $rounds ? $r + 1 : null,
					'next_pos'   => $r < $rounds ? intdiv( $p, 2 ) : null,
					'next_slot'  => $r < $rounds ? ( 0 === $p % 2 ? 'a' : 'b' ) : null,
					'is_bye'     => false,
				);
				if ( 1 === $r ) {
					$a = $order[ $p * 2 ];
					$b = $order[ $p * 2 + 1 ];
					$match['seed_a'] = $a <= $entrants ? $a : null;
					$match['seed_b'] = $b <= $entrants ? $b : null;
					$match['is_bye'] = ( null === $match['seed_a'] ) !== ( null === $match['seed_b'] );
				}
				$out[ "{$r}:{$p}" ] = $match;
			}
		}

		// Auto-advance byes into round 2.
		foreach ( $out as $m ) {
			if ( 1 === $m['round'] && $m['is_bye'] ) {
				$winner = $m['seed_a'] ?? $m['seed_b'];
				$key    = "{$m['next_round']}:{$m['next_pos']}";
				$out[ $key ][ 'a' === $m['next_slot'] ? 'seed_a' : 'seed_b' ] = $winner;
			}
		}

		return array_values( $out );
	}
}
