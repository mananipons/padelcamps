<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * How player names appear in public pages and the public API (privacy option
 * per tournament). Pure logic, no WordPress dependencies.
 *   full     → "Anna Puig Serra"
 *   initials → "Anna P." (first word + initial of the first surname)
 */
final class PublicName {

	public const MODES = array( 'full', 'initials' );

	public static function format( string $name, string $mode ): string {
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		if ( 'initials' !== $mode || '' === $name ) {
			return $name;
		}
		$parts = explode( ' ', $name );
		if ( count( $parts ) < 2 ) {
			return $name;
		}
		// First surname (second word): "Anna Puig Serra" → "Anna P.".
		preg_match( '/^./u', $parts[1], $m );
		return $parts[0] . ' ' . ( $m[0] ?? '' ) . '.';
	}

	/** "Anna P. / Pere S." */
	public static function pair( array $names, string $mode ): string {
		return implode( ' / ', array_map( static fn( $n ) => self::format( (string) $n, $mode ), $names ) );
	}
}
