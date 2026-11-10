<?php
namespace PadelCamps\Notifications;

use PadelCamps\Admin\TournamentsPage;
use PadelCamps\Service\Communication;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails sent when a registration is received: a receipt for the
 * registrant and a notice for the organizer. Every attempt is logged.
 */
final class RegistrationMailer {

	public static function status_label( string $status ): string {
		$labels = array(
			'pending'  => __( 'Pendent de confirmació', 'padelcamps' ),
			'waitlist' => __( 'Llista d’espera', 'padelcamps' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function money( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	/**
	 * Welcome pack choices as text lines (empty array when there is nothing to show).
	 *
	 * @return string[]
	 */
	private static function pack_lines( array $clean ): array {
		$lines = array();
		foreach ( $clean['players'] as $player ) {
			$parts = array();
			foreach ( $player['pack'] as $answer ) {
				if ( '' !== $answer['value'] ) {
					$parts[] = $answer['label'] . ': ' . $answer['value'];
				}
			}
			if ( $parts ) {
				$lines[] = sprintf( __( '%1$s: %2$s', 'padelcamps' ), $player['name'], implode( '; ', $parts ) );
			}
		}
		return $lines ? array_merge( array( __( 'Welcome pack:', 'padelcamps' ) ), $lines ) : array();
	}

	/**
	 * Receipt for the registrant (player 1).
	 *
	 * @param array $reg {id:int, status:string, amount:float}
	 * @param array $clean Validated submission (see Engine\RegistrationForm::validate()).
	 */
	public function send_receipt( array $tournament, array $category, array $reg, array $clean, array $config ): bool {
		$p1 = $clean['players'][0];
		$p2 = $clean['players'][1];

		$lines   = array();
		$lines[] = sprintf( __( 'Hola %s,', 'padelcamps' ), $p1['name'] );
		$lines[] = '';
		$lines[] = sprintf( __( 'Hem rebut la teva inscripció al torneig «%1$s», categoria «%2$s».', 'padelcamps' ), $tournament['name'], $category['name'] );
		$lines[] = sprintf( __( 'Parella: %1$s i %2$s', 'padelcamps' ), $p1['name'], $p2['name'] );
		$pack    = self::pack_lines( $clean );
		if ( $pack ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $pack );
		}
		$lines[] = '';
		$lines[] = 'waitlist' === $reg['status']
			? __( 'La categoria està completa, així que has quedat a la llista d’espera. Et contactarem si queda una plaça lliure.', 'padelcamps' )
			: __( 'Ara està pendent de confirmació per part de l’organització. T’avisarem quan es confirmi.', 'padelcamps' );

		// Nobody on the waiting list should pay before getting a place.
		if ( $reg['amount'] > 0 && 'waitlist' !== $reg['status'] ) {
			$lines[] = '';
			$lines[] = sprintf( __( 'Import de la inscripció: %s', 'padelcamps' ), self::money( (float) $reg['amount'] ) );
			if ( '' !== $config['payment_instructions'] ) {
				$lines[] = __( 'Instruccions de pagament:', 'padelcamps' );
				$lines[] = $config['payment_instructions'];
			}
		}
		$lines[] = '';
		$lines[] = __( 'Gràcies i molta sort!', 'padelcamps' );

		$subject = Mailer::one_line( sprintf( __( 'Inscripció rebuda: %s', 'padelcamps' ), $tournament['name'] ) );
		return $this->send( 'registration_receipt', $p1['email'], $subject, implode( "\n", $lines ), (int) $reg['id'], $tournament );
	}

	/** Notice for the organizer. */
	public function notify_organizer( array $tournament, array $category, array $reg, array $clean, array $config ): bool {
		$to = '' !== $config['notify_email'] ? $config['notify_email'] : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return false;
		}
		$p1 = $clean['players'][0];
		$p2 = $clean['players'][1];

		$lines   = array();
		$lines[] = sprintf( __( 'S’ha rebut una nova inscripció al torneig «%s».', 'padelcamps' ), $tournament['name'] );
		$lines[] = '';
		$lines[] = sprintf( __( 'Categoria: %s', 'padelcamps' ), $category['name'] );
		$lines[] = sprintf( __( 'Jugador/a 1: %1$s (%2$s)', 'padelcamps' ), $p1['name'], $p1['email'] );
		$lines[] = sprintf( __( 'Jugador/a 2: %1$s (%2$s)', 'padelcamps' ), $p2['name'], '' !== $p2['email'] ? $p2['email'] : '—' );
		$lines[] = sprintf( __( 'Estat: %s', 'padelcamps' ), self::status_label( $reg['status'] ) );
		$pack    = self::pack_lines( $clean );
		if ( $pack ) {
			$lines[] = '';
			$lines   = array_merge( $lines, $pack );
		}
		$lines[] = '';
		$lines[] = sprintf(
			__( 'Gestiona el torneig: %s', 'padelcamps' ),
			TournamentsPage::url( array( 'action' => 'edit', 'id' => (int) $tournament['id'], 'tab' => 'registrations' ) )
		);

		$subject = Mailer::one_line( sprintf( __( 'Nova inscripció: %1$s (%2$s)', 'padelcamps' ), $category['name'], $tournament['name'] ) );
		return $this->send( 'registration_organizer', $to, $subject, implode( "\n", $lines ), (int) $reg['id'], $tournament );
	}

	private function send( string $type, string $to, string $subject, string $body, int $registration_id, array $tournament ): bool {
		$comms     = new Communication();
		$signature = $comms->settings( $tournament )['signature'];
		if ( 'registration_receipt' === $type && '' !== $signature ) {
			$body .= "\n\n-- \n" . $signature;
		}
		return Mailer::send(
			array(
				'type'          => $type,
				'to'            => $to,
				'subject'       => $subject,
				'body'          => $body,
				'tournament_id' => (int) $tournament['id'],
				'ref_type'      => 'registration',
				'ref_id'        => $registration_id,
			) + $comms->sender( $tournament )
		);
	}
}
