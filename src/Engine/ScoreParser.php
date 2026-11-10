<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Score text <-> sets. Accepts "6-4 3-6 10-8", "6/4, 3/6", "6:4;7:5".
 * Pure logic, no WordPress dependencies. Validity (6-5 is not a set…) is
 * MatchFormat's job; this only reads the numbers.
 */
final class ScoreParser {

	/** @return array<int, array{0:int,1:int}>|null null when the text is not a list of scores. */
	public static function parse( string $text ): ?array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		if ( ! preg_match_all( '/(\d{1,2})\s*[-\/:]\s*(\d{1,2})/', $text, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$rest = (string) preg_replace( '/(\d{1,2})\s*[-\/:]\s*(\d{1,2})/', '', $text );
		if ( '' !== trim( $rest, " \t,;" ) ) {
			return null; // Something else than scores and separators.
		}
		return array_map( static fn( $x ) => array( (int) $x[1], (int) $x[2] ), $m );
	}

	public static function format( array $sets ): string {
		return implode( ' ', array_map( static fn( $s ) => (int) $s[0] . '-' . (int) $s[1], $sets ) );
	}
}
