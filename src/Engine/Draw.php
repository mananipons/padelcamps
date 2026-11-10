<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Deterministic draw (sorteig). Same teams + same seed string => same result,
 * so organisers can justify and reproduce any draw. No WordPress dependencies.
 *
 * A team is an array: ['id' => int, 'rating' => float, 'club' => string|null].
 * Higher rating = stronger.
 */
final class Draw {

	/**
	 * Order teams for a knockout bracket: the top $seeded teams (by rating) get
	 * seeds 1..$seeded; the rest are shuffled deterministically.
	 *
	 * @return array<int, array> teams with a 'seed' key (1-based), ordered by seed.
	 */
	public static function seed_teams( array $teams, int $seeded, string $seed ): array {
		usort(
			$teams,
			static fn( $a, $b ) => ( $b['rating'] <=> $a['rating'] ) ?: ( $a['id'] <=> $b['id'] )
		);
		$seeded = max( 0, min( $seeded, count( $teams ) ) );
		$top    = array_slice( $teams, 0, $seeded );
		$rest   = ( new SeededRandom( $seed ) )->shuffle( array_slice( $teams, $seeded ) );

		$ordered = array_merge( $top, $rest );
		foreach ( $ordered as $i => $team ) {
			$ordered[ $i ]['seed'] = $i + 1;
		}
		return $ordered;
	}

	/**
	 * Distribute teams into groups by pots: teams sorted by rating are cut into
	 * pots of $groups teams; each pot is shuffled and dealt one per group.
	 * Keeps groups balanced in level (group sizes differ by at most 1).
	 *
	 * Clubs (optional, $team['clubs'] = string[]): pairs from the same club are
	 * kept apart when possible. Each team of a pot goes to the free group with
	 * the fewest club clashes; ties keep the drawn order. Without clubs the
	 * result is exactly the plain pot deal.
	 *
	 * @return array<int, array<int, array>> group index (0-based) => teams.
	 */
	public static function groups( array $teams, int $groups, string $seed ): array {
		usort(
			$teams,
			static fn( $a, $b ) => ( $b['rating'] <=> $a['rating'] ) ?: ( $a['id'] <=> $b['id'] )
		);
		$rng    = new SeededRandom( $seed );
		$result = array_fill( 0, $groups, array() );
		$clubs  = static fn( array $t ): array => array_values( array_filter( array_map( static fn( $c ) => strtolower( trim( (string) $c ) ), (array) ( $t['clubs'] ?? array() ) ) ) );

		foreach ( array_chunk( $teams, $groups ) as $pot ) {
			$pot  = $rng->shuffle( $pot );
			$free = range( 0, $groups - 1 );
			foreach ( $pot as $team ) {
				$mine = $clubs( $team );
				$best = $free[0];
				$min  = PHP_INT_MAX;
				foreach ( $free as $g ) {
					$clash = 0;
					foreach ( $result[ $g ] as $other ) {
						$clash += count( array_intersect( $mine, $clubs( $other ) ) );
					}
					if ( $clash < $min ) {
						$min  = $clash;
						$best = $g;
					}
				}
				$result[ $best ][] = $team;
				$free              = array_values( array_diff( $free, array( $best ) ) );
			}
		}
		return $result;
	}

	/** Number of same-club pairs sharing a group (0 = perfectly separated). */
	public static function club_clashes( array $groups ): int {
		$n = 0;
		foreach ( $groups as $teams ) {
			$teams = array_values( $teams );
			for ( $i = 0; $i < count( $teams ); $i++ ) {
				for ( $j = $i + 1; $j < count( $teams ); $j++ ) {
					$a  = array_map( 'strtolower', array_filter( (array) ( $teams[ $i ]['clubs'] ?? array() ) ) );
					$b  = array_map( 'strtolower', array_filter( (array) ( $teams[ $j ]['clubs'] ?? array() ) ) );
					$n += array_intersect( $a, $b ) ? 1 : 0;
				}
			}
		}
		return $n;
	}
}
