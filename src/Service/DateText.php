<?php
namespace PadelCamps\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Dates written through the plugin's own translations, so an email in Spanish
 * says "Sábado 5 de diciembre" even when the site language is Catalan
 * (mysql2date()/date_i18n() always use the site language).
 */
final class DateText {

	private static function weekday( int $n ): string {
		$days = array(
			__( 'diumenge', 'padelcamps' ),
			__( 'dilluns', 'padelcamps' ),
			__( 'dimarts', 'padelcamps' ),
			__( 'dimecres', 'padelcamps' ),
			__( 'dijous', 'padelcamps' ),
			__( 'divendres', 'padelcamps' ),
			__( 'dissabte', 'padelcamps' ),
		);
		return $days[ $n ] ?? '';
	}

	private static function month( int $n ): string {
		$months = array(
			1  => __( 'gener', 'padelcamps' ),
			2  => __( 'febrer', 'padelcamps' ),
			3  => __( 'març', 'padelcamps' ),
			4  => __( 'abril', 'padelcamps' ),
			5  => __( 'maig', 'padelcamps' ),
			6  => __( 'juny', 'padelcamps' ),
			7  => __( 'juliol', 'padelcamps' ),
			8  => __( 'agost', 'padelcamps' ),
			9  => __( 'setembre', 'padelcamps' ),
			10 => __( 'octubre', 'padelcamps' ),
			11 => __( 'novembre', 'padelcamps' ),
			12 => __( 'desembre', 'padelcamps' ),
		);
		return $months[ $n ] ?? '';
	}

	/** "Dissabte 5 desembre · 09:00" from a MySQL datetime. */
	public static function day_time( string $mysql ): string {
		$ts = strtotime( $mysql );
		if ( false === $ts ) {
			return $mysql;
		}
		/* translators: 1: weekday, 2: day of the month, 3: month name */
		$day = sprintf( __( '%1$s %2$d %3$s', 'padelcamps' ), self::weekday( (int) gmdate( 'w', $ts ) ), (int) gmdate( 'j', $ts ), self::month( (int) gmdate( 'n', $ts ) ) );
		$day = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $day, 0, 1 ) ) . mb_substr( $day, 1 ) : ucfirst( $day );
		return $day . ' · ' . gmdate( 'H:i', $ts );
	}
}
