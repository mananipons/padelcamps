<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Bracket data ready to display. Pure logic, no WordPress dependencies.
 *
 * - build(): numbers every knockout match (P1, P2… in round order) and tells,
 *   for each empty slot of a later round, which match feeds it ("winner of P3").
 * - provisional(): the bracket of a groups+knockout category BEFORE the groups
 *   end, with slots like "1st of group A" (synthetic ids: (group+1)*100 + position).
 *
 * Match row (input): ['round' => int, 'position' => int, 'a' => ?int, 'b' => ?int,
 *                     'winner' => ?int, 'status' => string, 'score' => ?array]
 */
final class BracketView {

	/**
	 * @return array<int, array<int, array>> round => matches, each with number, from_a, from_b added.
	 */
	public static function build( array $rows, int $offset = 0 ): array {
		usort( $rows, static fn( $x, $y ) => array( (int) $x['round'], (int) $x['position'] ) <=> array( (int) $y['round'], (int) $y['position'] ) );
		$number = array();
		$n      = $offset;
		foreach ( $rows as $row ) {
			$number[ $row['round'] . ':' . $row['position'] ] = ++$n;
		}
		$rounds = array();
		foreach ( $rows as $row ) {
			$r   = (int) $row['round'];
			$p   = (int) $row['position'];
			$row = array_merge(
				$row,
				array(
					'number' => $number[ $r . ':' . $p ],
					'from_a' => $r > 1 ? ( $number[ ( $r - 1 ) . ':' . ( $p * 2 ) ] ?? null ) : null,
					'from_b' => $r > 1 ? ( $number[ ( $r - 1 ) . ':' . ( $p * 2 + 1 ) ] ?? null ) : null,
				)
			);
			$rounds[ $r ][] = $row;
		}
		ksort( $rounds );
		return $rounds;
	}

	/**
	 * Placeholder knockout for groups that have not finished yet. Placement uses
	 * the same rules as the real one (no same-group clash in round 1, opposite halves).
	 *
	 * @param int[] $group_sizes 0-based group => number of teams
	 * @return array<int, array> rows for build(); a/b are synthetic ids (see decode()).
	 */
	public static function provisional( array $group_sizes, int $advance ): array {
		$tables = array();
		foreach ( $group_sizes as $g => $size ) {
			for ( $p = 1; $p <= min( $advance, (int) $size ); $p++ ) {
				$tables[ $g ][] = array(
					'team'      => self::encode( (int) $g, $p ),
					'position'  => $p,
					'played'    => 0,
					'won'       => 0,
					'set_diff'  => 0,
					'game_diff' => 0,
				);
			}
		}
		$q = Qualification::knockout_entrants( $tables, $advance );
		if ( count( $q['entrants'] ) < 2 ) {
			return array();
		}
		return array_map(
			static fn( $m ) => array(
				'round'    => $m['round'],
				'position' => $m['position'],
				'a'        => $m['a'],
				'b'        => $m['b'],
				'winner'   => $m['winner'],
				'status'   => $m['is_bye'] ? 'bye' : 'scheduled',
				'score'    => null,
			),
			DrawPlan::knockout( $q['entrants'] )
		);
	}

	public static function encode( int $group, int $position ): int {
		return ( $group + 1 ) * 100 + $position;
	}

	/** @return array{0:int,1:int} [0-based group, position] */
	public static function decode( int $id ): array {
		return array( intdiv( $id, 100 ) - 1, $id % 100 );
	}
}
