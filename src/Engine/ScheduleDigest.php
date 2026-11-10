<?php
namespace PadelCamps\Engine;

/**
 * Fingerprint of a pair's upcoming matches, to know whether the schedule
 * sent by email is still valid. Only what the pair cares about counts:
 * which match, when, where and against whom. Pure PHP, no WordPress.
 */
final class ScheduleDigest {

	public const NONE    = 'none';    // No upcoming match: nothing to send.
	public const NEW     = 'new';     // Never sent.
	public const CHANGED = 'changed'; // Sent, but something changed since.
	public const SAME    = 'same';    // Up to date.

	/**
	 * @param array<int, array{id:int, at:?string, court:int, rival:int}> $items upcoming (unplayed) matches
	 */
	public static function fingerprint( array $items ): string {
		if ( ! $items ) {
			return '';
		}
		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = (int) $item['id'] . '|' . (string) ( $item['at'] ?? '' ) . '|' . (int) ( $item['court'] ?? 0 ) . '|' . (int) ( $item['rival'] ?? 0 );
		}
		sort( $rows );
		return substr( md5( implode( "\n", $rows ) ), 0, 16 );
	}

	public static function state( string $current, ?string $sent ): string {
		if ( '' === $current ) {
			return self::NONE;
		}
		if ( null === $sent || '' === $sent ) {
			return self::NEW;
		}
		return $current === $sent ? self::SAME : self::CHANGED;
	}

	/** Whether a pair in this state gets the email when sending "only changes". */
	public static function needs_sending( string $state ): bool {
		return self::NEW === $state || self::CHANGED === $state;
	}
}
