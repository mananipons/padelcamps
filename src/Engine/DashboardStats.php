<?php
namespace PadelCamps\Engine;

/**
 * Small decisions behind the dashboard. Pure PHP, no WordPress.
 */
final class DashboardStats {

	/** Lower = more "active". */
	private const STATUS_RANK = array(
		'in_progress'         => 0,
		'drawn'               => 1,
		'registration_closed' => 2,
		'registration_open'   => 3,
		'finished'            => 4,
		'draft'               => 5,
	);

	/**
	 * Tournament the dashboard opens on: the one being played, then the drawn one,
	 * then the next with registrations, then finished, then drafts. Ties: the one
	 * whose start date is closest to today (no date goes last), then the newest.
	 *
	 * @param array<int, array{id:int|string, status:string, starts_on:?string}> $tournaments
	 */
	public static function pick_active( array $tournaments, string $today ): ?int {
		if ( ! $tournaments ) {
			return null;
		}
		$today_ts = strtotime( $today . ' 00:00:00 UTC' );
		$key      = static function ( array $t ) use ( $today_ts ): array {
			$rank = self::STATUS_RANK[ $t['status'] ] ?? 9;
			$ts   = ! empty( $t['starts_on'] ) ? strtotime( $t['starts_on'] . ' 00:00:00 UTC' ) : false;
			$dist = false === $ts ? PHP_INT_MAX : (int) abs( $ts - $today_ts );
			return array( $rank, $dist, -(int) $t['id'] );
		};
		usort( $tournaments, static fn( $a, $b ) => $key( $a ) <=> $key( $b ) );
		return (int) $tournaments[0]['id'];
	}

	/**
	 * Where we are in time.
	 *
	 * @return array{state:string, days:int, day:int, total:int}
	 *   state: unknown (no date) | before (days to go) | during (day X of total) | after (days since end)
	 */
	public static function countdown( ?string $starts_on, ?string $ends_on, string $today ): array {
		if ( empty( $starts_on ) ) {
			return array( 'state' => 'unknown', 'days' => 0, 'day' => 0, 'total' => 0 );
		}
		$day   = 86400;
		$t     = (int) strtotime( $today . ' 00:00:00 UTC' );
		$start = (int) strtotime( $starts_on . ' 00:00:00 UTC' );
		$end   = ! empty( $ends_on ) && $ends_on >= $starts_on ? (int) strtotime( $ends_on . ' 00:00:00 UTC' ) : $start;
		$total = intdiv( $end - $start, $day ) + 1;
		if ( $t < $start ) {
			return array( 'state' => 'before', 'days' => intdiv( $start - $t, $day ), 'day' => 0, 'total' => $total );
		}
		if ( $t <= $end ) {
			return array( 'state' => 'during', 'days' => 0, 'day' => intdiv( $t - $start, $day ) + 1, 'total' => $total );
		}
		return array( 'state' => 'after', 'days' => intdiv( $t - $end, $day ), 'day' => 0, 'total' => $total );
	}

	/** Whole percentage, 0 when there is no total, never above 100. */
	public static function percent( int $part, int $total ): int {
		if ( $total <= 0 ) {
			return 0;
		}
		return (int) min( 100, max( 0, floor( 100 * $part / $total ) ) );
	}
}
