<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Spanish DNI / NIE: normalization and check-letter validation. Pure logic, no WordPress.
 *
 * The DNI or NIE is the identity of a player: the same person always resolves to the
 * same player, whatever email or phone they use. Stored normalized (uppercase, no
 * spaces or separators).
 *
 *  - DNI: 8 digits + control letter           12345678Z
 *  - NIE: X, Y or Z + 7 digits + control letter  X1234567L
 *
 * The control letter is LETTERS[number mod 23]; for a NIE the first letter counts as
 * 0 (X), 1 (Y) or 2 (Z).
 */
final class Dni {

	private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/** Uppercase and strip spaces, dashes, dots and underscores. Anything but a string gives ''. */
	public static function normalize( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return strtoupper( (string) preg_replace( '/[\s.\-_]+/', '', $value ) );
	}

	/** True for a well-formed DNI or NIE with the right control letter (expects a normalized value). */
	public static function is_valid( string $dni ): bool {
		return null !== self::kind( $dni );
	}

	/** 'dni' | 'nie' for a valid value, null otherwise (expects a normalized value). */
	public static function kind( string $dni ): ?string {
		if ( 1 === preg_match( '/^(\d{8})([A-Z])$/', $dni, $m ) ) {
			return self::LETTERS[ (int) $m[1] % 23 ] === $m[2] ? 'dni' : null;
		}
		if ( 1 === preg_match( '/^([XYZ])(\d{7})([A-Z])$/', $dni, $m ) ) {
			$number = (int) ( strpos( 'XYZ', $m[1] ) . $m[2] );
			return self::LETTERS[ $number % 23 ] === $m[3] ? 'nie' : null;
		}
		return null;
	}

	/** The normalized DNI/NIE when valid, null otherwise. Use this on user input. */
	public static function clean( $value ): ?string {
		$dni = self::normalize( $value );
		return self::is_valid( $dni ) ? $dni : null;
	}
}
