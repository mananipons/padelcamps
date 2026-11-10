<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Results reported by players from their magic link. Pure logic, no WordPress
 * dependencies. Scores are always written in the match orientation (pair A
 * first), so both pairs type the same numbers and reports can be compared.
 *
 * Special endings: '' | wo_a | wo_b (walkover won by A / B) | ret_a | ret_b (A / B retires).
 */
final class ResultReport {

	public const SPECIALS = array( '', 'wo_a', 'wo_b', 'ret_a', 'ret_b' );

	/**
	 * Build a result from per-set inputs.
	 *
	 * @param array $games_a games of pair A per set (strings from the form)
	 * @param array $games_b games of pair B per set
	 * @return array{result:?array, error:?string} error: empty | incomplete_set | invalid | bad_special
	 */
	public static function from_inputs( array $games_a, array $games_b, string $special ): array {
		if ( ! in_array( $special, self::SPECIALS, true ) ) {
			return array( 'result' => null, 'error' => 'bad_special' );
		}
		if ( 'wo_a' === $special || 'wo_b' === $special ) {
			return array( 'result' => array( 'walkover' => substr( $special, 3 ) ), 'error' => null );
		}

		$sets  = array();
		$count = max( count( $games_a ), count( $games_b ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$a = trim( (string) ( $games_a[ $i ] ?? '' ) );
			$b = trim( (string) ( $games_b[ $i ] ?? '' ) );
			if ( '' === $a && '' === $b ) {
				continue;
			}
			if ( '' === $a || '' === $b ) {
				return array( 'result' => null, 'error' => 'incomplete_set' );
			}
			if ( ! preg_match( '/^\d{1,2}$/', $a ) || ! preg_match( '/^\d{1,2}$/', $b ) ) {
				return array( 'result' => null, 'error' => 'invalid' );
			}
			$sets[] = array( (int) $a, (int) $b );
		}

		$result = array( 'sets' => $sets );
		if ( 'ret_a' === $special || 'ret_b' === $special ) {
			$result['retired'] = substr( $special, 4 );
		} elseif ( ! $sets ) {
			return array( 'result' => null, 'error' => 'empty' );
		}
		return array( 'result' => $result, 'error' => null );
	}

	/** Canonical form, so two equivalent results compare equal. */
	public static function normalize( array $result ): array {
		if ( ! empty( $result['walkover'] ) ) {
			return array( 'walkover' => (string) $result['walkover'] );
		}
		$out = array(
			'sets' => array_map(
				static fn( $s ) => array( (int) ( $s[0] ?? 0 ), (int) ( $s[1] ?? 0 ) ),
				array_values( is_array( $result['sets'] ?? null ) ? $result['sets'] : array() )
			),
		);
		if ( ! empty( $result['retired'] ) ) {
			$out['retired'] = (string) $result['retired'];
		}
		return $out;
	}

	public static function same( array $x, array $y ): bool {
		return self::normalize( $x ) === self::normalize( $y );
	}

	/** Text for DrawService::set_result(): "6-4 3-6 10-8" ('' for a walkover). */
	public static function to_text( array $result ): string {
		return ! empty( $result['walkover'] ) ? '' : ScoreParser::format( $result['sets'] ?? array() );
	}

	/** Special code for DrawService::set_result(). */
	public static function special( array $result ): string {
		if ( ! empty( $result['walkover'] ) ) {
			return 'wo_' . $result['walkover'];
		}
		return ! empty( $result['retired'] ) ? 'ret_' . $result['retired'] : '';
	}
}
