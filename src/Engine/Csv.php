<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * CSV writer helpers. Registrations come from a public form, so every cell is
 * treated as untrusted: values that a spreadsheet would run as a formula
 * (leading = @ tab CR, or + / - followed by anything but a number-like text)
 * get a leading apostrophe. Pure logic, no WordPress dependencies.
 *
 * The default separator is ";" because Excel in Catalan/Spanish locales
 * does not split on commas.
 */
final class Csv {

	/** Neutralize spreadsheet formulas and quote the cell when needed. */
	public static function cell( $value, string $sep = ';' ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = str_replace( "\0", '', $value );

		$formula = 1 === preg_match( '/^[=@\t\r]/', $value )
			|| ( 1 === preg_match( '/^[+\-]/', $value ) && 1 !== preg_match( '/^[+\-][\d\s().\-]*$/', $value ) );
		if ( $formula ) {
			$value = "'" . $value;
		}

		if ( false !== strpbrk( $value, $sep . "\"\n\r" ) ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/** One CSV line (with CRLF) from a list of cells. */
	public static function row( array $cells, string $sep = ';' ): string {
		return implode( $sep, array_map( static fn( $c ) => self::cell( $c, $sep ), $cells ) ) . "\r\n";
	}
}
