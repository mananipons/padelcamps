<?php
namespace PadelCamps\Engine;

/**
 * Tiny {placeholder} templates for messages written by the organizer.
 * Unknown placeholders are left as they are (so a typo is visible, not silently
 * removed). Pure PHP, no WordPress.
 */
final class MessageTemplate {

	/** Placeholder names accepted in organizer messages. */
	public const KEYS = array( 'nom', 'parella', 'torneig', 'categoria', 'enllac' );

	/** @param array<string, string> $vars */
	public static function render( string $template, array $vars ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static fn( array $m ): string => array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0],
			$template
		);
	}

	/** @return string[] placeholder names used in the template, in order of first use. */
	public static function keys( string $template ): array {
		preg_match_all( '/\{([a-z_]+)\}/', $template, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/** @return string[] placeholders that are not in KEYS. */
	public static function unknown( string $template ): array {
		return array_values( array_diff( self::keys( $template ), self::KEYS ) );
	}

	/** First name of a full name ("Anna Puig Roca" → "Anna"). */
	public static function first_name( string $name ): string {
		$name = trim( $name );
		$pos  = strpos( $name, ' ' );
		return false === $pos ? $name : substr( $name, 0, $pos );
	}
}
