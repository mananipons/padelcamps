<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Group / round-robin standings. Pure logic, no WordPress dependencies.
 *
 * Ranking: tie-break criteria applied in order (configurable). Whenever a
 * criterion splits a tie into smaller groups, ranking restarts from the first
 * criterion inside each smaller group (so a 3-way tie that becomes 2-way is
 * then decided by the head-to-head between those two). Last resort: the input
 * order of $team_ids (pass them by seed, so the better seed ranks higher).
 *
 * Criteria: wins | set_diff | game_diff | head_to_head | sets_for | games_for
 */
final class Standings {

	public const DEFAULT_CRITERIA = array( 'wins', 'set_diff', 'game_diff', 'head_to_head' );
	public const CRITERIA         = array( 'wins', 'set_diff', 'game_diff', 'head_to_head', 'sets_for', 'games_for' );

	/**
	 * @param int[] $team_ids In seed order (used as last tie-break).
	 * @param array<int, array{a:int, b:int, result:?array}> $matches Matches without a valid result are ignored.
	 * @param string[] $criteria
	 * @return array<int, array{team:int, position:int, played:int, won:int, lost:int, sets_for:int, sets_against:int, set_diff:int, games_for:int, games_against:int, game_diff:int}>
	 */
	public static function compute( array $team_ids, array $matches, array $format, array $criteria = self::DEFAULT_CRITERIA ): array {
		$team_ids = array_values( array_map( 'intval', $team_ids ) );
		$criteria = array_values( array_filter( $criteria, static fn( $c ) => in_array( $c, self::CRITERIA, true ) ) ) ?: self::DEFAULT_CRITERIA;

		$rows = array();
		foreach ( $team_ids as $t ) {
			$rows[ $t ] = array(
				'team'          => $t,
				'position'      => 0,
				'played'        => 0,
				'won'           => 0,
				'lost'          => 0,
				'sets_for'      => 0,
				'sets_against'  => 0,
				'set_diff'      => 0,
				'games_for'     => 0,
				'games_against' => 0,
				'game_diff'     => 0,
			);
		}

		$results = array(); // [a, b, winner_team]
		foreach ( $matches as $m ) {
			$a = (int) ( $m['a'] ?? 0 );
			$b = (int) ( $m['b'] ?? 0 );
			if ( ! isset( $rows[ $a ], $rows[ $b ] ) || $a === $b || ! is_array( $m['result'] ?? null ) ) {
				continue;
			}
			$e = MatchFormat::evaluate( $m['result'], $format );
			if ( ! $e['valid'] ) {
				continue;
			}
			$winner = 'a' === $e['winner'] ? $a : $b;
			$loser  = 'a' === $e['winner'] ? $b : $a;
			foreach ( array( array( $a, 0, 1 ), array( $b, 1, 0 ) ) as list( $t, $mine, $theirs ) ) {
				++$rows[ $t ]['played'];
				$rows[ $t ]['sets_for']      += $e['sets'][ $mine ];
				$rows[ $t ]['sets_against']  += $e['sets'][ $theirs ];
				$rows[ $t ]['games_for']     += $e['games'][ $mine ];
				$rows[ $t ]['games_against'] += $e['games'][ $theirs ];
			}
			++$rows[ $winner ]['won'];
			++$rows[ $loser ]['lost'];
			$results[] = array( $a, $b, $winner );
		}
		foreach ( $rows as $t => $row ) {
			$rows[ $t ]['set_diff']  = $row['sets_for'] - $row['sets_against'];
			$rows[ $t ]['game_diff'] = $row['games_for'] - $row['games_against'];
		}

		$order = self::rank( $team_ids, 0, $rows, $results, $criteria );
		$out   = array();
		foreach ( $order as $i => $t ) {
			$row             = $rows[ $t ];
			$row['position'] = $i + 1;
			$out[]           = $row;
		}
		return $out;
	}

	private static function value( string $criterion, int $team, array $subset, array $rows, array $results ): int {
		switch ( $criterion ) {
			case 'wins':
				return $rows[ $team ]['won'];
			case 'set_diff':
				return $rows[ $team ]['set_diff'];
			case 'game_diff':
				return $rows[ $team ]['game_diff'];
			case 'sets_for':
				return $rows[ $team ]['sets_for'];
			case 'games_for':
				return $rows[ $team ]['games_for'];
			case 'head_to_head':
				$wins = 0;
				foreach ( $results as list( $a, $b, $winner ) ) {
					if ( $winner === $team && in_array( $a, $subset, true ) && in_array( $b, $subset, true ) ) {
						++$wins;
					}
				}
				return $wins;
		}
		return 0;
	}

	/** @param int[] $ids tied teams in seed order */
	private static function rank( array $ids, int $ci, array $rows, array $results, array $criteria ): array {
		if ( count( $ids ) <= 1 || $ci >= count( $criteria ) ) {
			return $ids;
		}
		$buckets = array();
		foreach ( $ids as $t ) {
			$buckets[ self::value( $criteria[ $ci ], $t, $ids, $rows, $results ) ][] = $t;
		}
		krsort( $buckets, SORT_NUMERIC );

		$out = array();
		foreach ( $buckets as $group ) {
			// Smaller tie: start again from the first criterion. Same tie: next criterion.
			$next = count( $group ) < count( $ids ) ? 0 : $ci + 1;
			foreach ( self::rank( $group, $next, $rows, $results, $criteria ) as $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}
}
