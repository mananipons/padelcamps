<?php
namespace PadelCamps\Engine;

/**
 * Communication settings of a tournament (tournaments.settings['comms']).
 * Pure PHP, no WordPress.
 *
 * auto: automatic emails, each one on/off
 *   confirmed       registration confirmed by the organizer
 *   waitlist        registration moved to the waiting list
 *   cancelled       registration cancelled
 *   paid            payment marked as received
 *   result_confirm  the rival pair has a result to confirm
 *   result_dispute  the organizer: pairs disagree on a result
 * from_name     sender name ('' → tournament name)
 * reply_to      address for replies ('' → form notification address / site admin)
 * signature     text added at the end of every email to players
 * both_players  also write to player 2 when they gave an email
 */
final class CommsSettings {

	public const EVENTS = array( 'confirmed', 'waitlist', 'cancelled', 'paid', 'result_confirm', 'result_dispute' );

	public static function defaults(): array {
		return array(
			'auto'         => array_fill_keys( self::EVENTS, true ),
			'from_name'    => '',
			'reply_to'     => '',
			'signature'    => '',
			'both_players' => true,
		);
	}

	public static function normalize( $raw ): array {
		$out = self::defaults();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$auto = is_array( $raw['auto'] ?? null ) ? $raw['auto'] : array();
		foreach ( self::EVENTS as $event ) {
			if ( array_key_exists( $event, $auto ) ) {
				$out['auto'][ $event ] = (bool) $auto[ $event ];
			}
		}
		// Header values: one line, bounded.
		$out['from_name'] = substr( trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) ( $raw['from_name'] ?? '' ) ) ), 0, 120 );
		$reply            = trim( (string) ( $raw['reply_to'] ?? '' ) );
		$out['reply_to']  = false !== filter_var( $reply, FILTER_VALIDATE_EMAIL ) ? $reply : '';
		$out['signature'] = substr( str_replace( "\r", '', trim( (string) ( $raw['signature'] ?? '' ) ) ), 0, 2000 );
		if ( array_key_exists( 'both_players', $raw ) ) {
			$out['both_players'] = (bool) $raw['both_players'];
		}
		return $out;
	}

	public static function enabled( array $settings, string $event ): bool {
		return ! empty( $settings['auto'][ $event ] );
	}
}
