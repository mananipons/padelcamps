<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * The public ranking table of one season category. Pure logic, no WordPress.
 *
 * It only ever carries what may be public: the display name (full or "Anna P.", see
 * PublicName), tournaments played and points. Never an id, DNI, email, phone or club.
 */
final class PublicRanking {

	/**
	 * @param array<int, array{name:string, points:int, tournaments:int}> $totals ranking rows, best first
	 *        (extra keys such as player_id or club are ignored and never copied)
	 * @return array<int, array{position:int, name:string, tournaments:int, points:int}>
	 *         only players with more than 0 points; ties share the position (1, 1, 3…)
	 */
	public static function rows( array $totals, string $mode ): array {
		$mode = in_array( $mode, PublicName::MODES, true ) ? $mode : 'initials';

		$sorted = array_values(
			array_filter( $totals, static fn( $r ) => (int) ( $r['points'] ?? 0 ) > 0 )
		);
		// Best first; ties keep the order they came in (the repository sorts them by name).
		usort( $sorted, static fn( $a, $b ) => (int) $b['points'] <=> (int) $a['points'] );

		$out      = array();
		$position = 0;
		$previous = null;
		foreach ( $sorted as $i => $row ) {
			$points = (int) $row['points'];
			if ( $points !== $previous ) {
				$position = $i + 1;
				$previous = $points;
			}
			$out[] = array(
				'position'    => $position,
				'name'        => PublicName::format( (string) ( $row['name'] ?? '' ), $mode ),
				'tournaments' => (int) ( $row['tournaments'] ?? 0 ),
				'points'      => $points,
			);
		}
		return $out;
	}

	/** The mode to use: anything unknown falls back to the private one (initials). */
	public static function mode( $value ): string {
		return is_string( $value ) && in_array( $value, PublicName::MODES, true ) ? $value : 'initials';
	}
}
