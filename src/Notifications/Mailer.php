<?php
namespace PadelCamps\Notifications;

use PadelCamps\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text email through wp_mail(), with the tournament's sender name and
 * reply-to address. Every attempt is written to notifications_log.
 * The From address is left to WordPress / the SMTP plugin on purpose: changing
 * it would break SPF/DKIM on most hosts. Only the visible name changes.
 */
final class Mailer {

	/** Subjects/headers must never contain line breaks. */
	public static function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * @param array{type:string, to:string, subject:string, body:string, tournament_id?:int, ref_type?:string, ref_id?:int, from_name?:string, reply_to?:string} $msg
	 */
	public static function send( array $msg ): bool {
		global $wpdb;
		$to      = trim( (string) $msg['to'] );
		$subject = self::one_line( (string) $msg['subject'] );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply   = (string) ( $msg['reply_to'] ?? '' );
		if ( '' !== $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$from_name = self::one_line( (string) ( $msg['from_name'] ?? '' ) );
		$set_name  = static fn(): string => $from_name;
		if ( '' !== $from_name ) {
			add_filter( 'wp_mail_from_name', $set_name, 99 );
		}

		$sent = '' !== $to && is_email( $to ) && wp_mail( $to, $subject, (string) $msg['body'], $headers );

		if ( '' !== $from_name ) {
			remove_filter( 'wp_mail_from_name', $set_name, 99 );
		}

		$wpdb->insert(
			Schema::table( 'notifications_log' ),
			array(
				'channel'       => 'email',
				'tournament_id' => ! empty( $msg['tournament_id'] ) ? (int) $msg['tournament_id'] : null,
				'type'          => (string) $msg['type'],
				'recipient'     => substr( $to, 0, 190 ),
				'subject'       => substr( $subject, 0, 190 ),
				'ref_type'      => $msg['ref_type'] ?? null,
				'ref_id'        => isset( $msg['ref_id'] ) ? (int) $msg['ref_id'] : null,
				'status'        => $sent ? 'sent' : 'failed',
				'sent_at'       => current_time( 'mysql' ),
			)
		);
		return (bool) $sent;
	}
}
