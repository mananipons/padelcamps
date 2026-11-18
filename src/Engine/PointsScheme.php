<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Season points scheme: how many ranking points a player earns according to the
 * furthest round their pair reached in a category. Pure logic, no WordPress.
 *
 * Keys (the furthest result reached):
 *  - champion       wins the final
 *  - runner_up      loses the final
 *  - semifinal      loses a semifinal
 *  - quarterfinal   loses a quarterfinal
 *  - round_of_16    loses a round of 16
 *  - participation  anything else (group stage exit, first rounds of bigger brackets…)
 *
 * Labels live in the admin layer so they go through the translation system.
 */
final class PointsScheme {

	public const MAX_POINTS = 100000;

	/** @return string[] scheme keys, best result first. */
	public static function keys(): array {
		return array( 'champion', 'runner_up', 'semifinal', 'quarterfinal', 'round_of_16', 'participation' );
	}

	/** @return array<string,int> */
	public static function defaults(): array {
		return array(
			'champion'      => 100,
			'runner_up'     => 70,
			'semifinal'     => 50,
			'quarterfinal'  => 30,
			'round_of_16'   => 20,
			'participation' => 10,
		);
	}

	/**
	 * Clean any input (decoded JSON, form values) into a complete scheme: unknown keys
	 * are dropped, missing or invalid values fall back to the default, and points are
	 * whole numbers between 0 and MAX_POINTS.
	 *
	 * @param mixed $raw
	 * @return array<string,int>
	 */
	public static function normalize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$scheme = self::defaults();
		foreach ( self::keys() as $key ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}
			if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d{1,9}$/', $value ) ) ) {
				$scheme[ $key ] = max( 0, min( self::MAX_POINTS, (int) $value ) );
			}
		}
		return $scheme;
	}

	/**
	 * True when no result earns more than a better one (champion >= runner_up >= …).
	 * The admin warns about it but does not forbid it: a circuit may reward differently.
	 *
	 * @param array<string,int> $scheme
	 */
	public static function is_descending( array $scheme ): bool {
		$previous = null;
		foreach ( self::keys() as $key ) {
			$points = (int) ( $scheme[ $key ] ?? 0 );
			if ( null !== $previous && $points > $previous ) {
				return false;
			}
			$previous = $points;
		}
		return true;
	}

	/** Points of one result key, 0 for an unknown key. @param array<string,int> $scheme */
	public static function points_for( array $scheme, string $key ): int {
		return (int) ( $scheme[ $key ] ?? 0 );
	}
}
