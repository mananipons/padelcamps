<?php
namespace PadelCamps\Service;

use PadelCamps\Admin\TournamentsPage;
use PadelCamps\Db\CommsRepository;
use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\ResultRepository;
use PadelCamps\Db\ScheduleRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\CommsSettings;
use PadelCamps\Engine\MessageTemplate;
use PadelCamps\Engine\ScheduleDigest;
use PadelCamps\Engine\ScoreParser;
use PadelCamps\Frontend\PairPage;
use PadelCamps\I18n\Catalog;
use PadelCamps\I18n\Translator;
use PadelCamps\Notifications\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Emails to players and organizer:
 *  - automatic: registration confirmed / waiting list / cancelled, payment
 *    received, result to confirm (to the rival pair), result in dispute (organizer);
 *  - sent by the organizer: each pair's matches and times (all, or only the pairs
 *    whose schedule changed since the last email), and free messages.
 * Emails to players are written in the language they registered in.
 */
final class Communication {

	private TournamentRepository $tournaments;
	private RegistrationRepository $regs;
	private ResultRepository $results;
	private ScheduleRepository $schedule;
	private CommsRepository $comms;

	/** @var array<int, ?array> */
	private array $tournament_cache = array();

	public function __construct() {
		$this->tournaments = new TournamentRepository();
		$this->regs        = new RegistrationRepository();
		$this->results     = new ResultRepository();
		$this->schedule    = new ScheduleRepository();
		$this->comms       = new CommsRepository();
	}

	// --- Settings and sender ---------------------------------------------------------

	public function settings( array $t ): array {
		return CommsSettings::normalize( $this->tournaments->settings( $t )['comms'] ?? null );
	}

	public function organizer_email( array $t ): string {
		$form = $this->tournaments->form_config( $t );
		return '' !== $form['notify_email'] ? $form['notify_email'] : (string) get_option( 'admin_email' );
	}

	/** @return array{from_name:string, reply_to:string} */
	public function sender( array $t ): array {
		$s = $this->settings( $t );
		return array(
			'from_name' => '' !== $s['from_name'] ? $s['from_name'] : (string) $t['name'],
			'reply_to'  => '' !== $s['reply_to'] ? $s['reply_to'] : $this->organizer_email( $t ),
		);
	}

	private function tournament( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->tournament_cache ) ) {
			$this->tournament_cache[ $id ] = $this->tournaments->find( $id );
		}
		return $this->tournament_cache[ $id ];
	}

	// --- Helpers -------------------------------------------------------------------------

	/** Addresses of a registration: contact (player 1) and, if enabled, player 2. @return string[] */
	private function addresses( array $reg, array $s ): array {
		$out = array();
		$add = static function ( $email ) use ( &$out ): void {
			$email = trim( (string) $email );
			if ( '' !== $email && is_email( $email ) && ! in_array( strtolower( $email ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $email;
			}
		};
		$add( $reg['contact_email'] ?? '' );
		if ( $s['both_players'] ) {
			foreach ( $reg['players'] ?? array() as $p ) {
				$add( $p['email'] ?? '' );
			}
		}
		return $out;
	}

	private static function lang( array $reg ): string {
		return Catalog::lang_of( (string) ( $reg['answers']['locale'] ?? '' ) );
	}

	private static function pair_name( array $reg ): string {
		return implode( ' / ', wp_list_pluck( $reg['players'] ?? array(), 'name' ) );
	}

	/** "Anna i Pau" (first names), for greetings. */
	private static function greeting_names( array $reg ): string {
		$names = array_values( array_filter( array_map( array( MessageTemplate::class, 'first_name' ), wp_list_pluck( $reg['players'] ?? array(), 'name' ) ) ) );
		if ( count( $names ) >= 2 ) {
			/* translators: 1: first name, 2: first name */
			return sprintf( __( '%1$s i %2$s', 'padelcamps' ), $names[0], $names[1] );
		}
		return $names[0] ?? (string) ( $reg['contact_name'] ?? '' );
	}

	private static function money( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	private static function score_text( array $result ): string {
		if ( ! empty( $result['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		$text = ScoreParser::format( $result['sets'] ?? array() );
		return ! empty( $result['retired'] ) ? trim( $text . ' ' . __( '(ret.)', 'padelcamps' ) ) : $text;
	}

	private function link( int $reg_id ): string {
		return PairPage::url( $this->results->token_for( $reg_id ) );
	}

	/**
	 * Write (in the pair's language) and send one email to every address of a registration.
	 *
	 * @param callable(): array{0:string, 1:string} $compose returns [subject, body]
	 * @return array{sent:int, failed:int}
	 */
	private function deliver( array $t, array $reg, string $type, callable $compose ): array {
		$s                       = $this->settings( $t );
		$sender                  = $this->sender( $t );
		list( $subject, $body )  = Translator::in_lang( self::lang( $reg ), $compose );
		if ( '' !== $s['signature'] ) {
			$body .= "\n\n-- \n" . $s['signature'];
		}
		$out = array( 'sent' => 0, 'failed' => 0 );
		foreach ( $this->addresses( $reg, $s ) as $to ) {
			$ok = Mailer::send(
				array(
					'type'          => $type,
					'to'            => $to,
					'subject'       => $subject,
					'body'          => $body,
					'tournament_id' => (int) $t['id'],
					'ref_type'      => 'registration',
					'ref_id'        => (int) $reg['id'],
				) + $sender
			);
			++$out[ $ok ? 'sent' : 'failed' ];
		}
		return $out;
	}

	private function to_organizer( array $t, string $type, string $subject, string $body ): bool {
		return Mailer::send(
			array(
				'type'          => $type,
				'to'            => $this->organizer_email( $t ),
				'subject'       => $subject,
				'body'          => $body,
				'tournament_id' => (int) $t['id'],
				'from_name'     => $this->sender( $t )['from_name'],
			)
		);
	}

	// --- Automatic: registrations -----------------------------------------------------

	/**
	 * After the organizer changes registration statuses.
	 *
	 * @param array<int, array{status:string, payment_status:string}> $before CommsRepository::states() taken before the change
	 * @return int emails sent
	 */
	public function after_status_change( array $before, string $new_status ): int {
		$events = array( 'confirmed' => 'confirmed', 'waitlist' => 'waitlist', 'cancelled' => 'cancelled' );
		if ( ! isset( $events[ $new_status ] ) ) {
			return 0;
		}
		$event = $events[ $new_status ];
		$sent  = 0;
		foreach ( $before as $id => $old ) {
			if ( $old['status'] === $new_status ) {
				continue;
			}
			$reg = $this->regs->find( (int) $id );
			$t   = $reg ? $this->tournament( (int) $reg['tournament_id'] ) : null;
			if ( ! $reg || ! $t || $reg['status'] !== $new_status || ! CommsSettings::enabled( $this->settings( $t ), $event ) ) {
				continue;
			}
			$sent += $this->deliver( $t, $reg, 'reg_' . $event, fn() => $this->compose_status( $t, $reg, $event ) )['sent'];
		}
		return $sent;
	}

	/** After marking payments as received. @return int emails sent */
	public function after_payment( array $before ): int {
		$sent = 0;
		foreach ( $before as $id => $old ) {
			if ( 'paid' === $old['payment_status'] ) {
				continue;
			}
			$reg = $this->regs->find( (int) $id );
			$t   = $reg ? $this->tournament( (int) $reg['tournament_id'] ) : null;
			if ( ! $reg || ! $t || 'paid' !== $reg['payment_status'] || ! CommsSettings::enabled( $this->settings( $t ), 'paid' ) ) {
				continue;
			}
			$sent += $this->deliver( $t, $reg, 'reg_paid', fn() => $this->compose_status( $t, $reg, 'paid' ) )['sent'];
		}
		return $sent;
	}

	/** @return array{0:string, 1:string} [subject, body] */
	private function compose_status( array $t, array $reg, string $event ): array {
		$lines   = array( sprintf( __( 'Hola %s,', 'padelcamps' ), self::greeting_names( $reg ) ), '' );
		$tname   = (string) $t['name'];
		$cname   = (string) $reg['category_name'];
		switch ( $event ) {
			case 'confirmed':
				$subject = sprintf( __( 'Inscripció confirmada: %s', 'padelcamps' ), $tname );
				/* translators: 1: tournament, 2: category */
				$lines[] = sprintf( __( 'La vostra inscripció al torneig «%1$s», categoria «%2$s», està confirmada.', 'padelcamps' ), $tname, $cname );
				$names   = wp_list_pluck( $reg['players'] ?? array(), 'name' );
				if ( count( $names ) >= 2 ) {
					$lines[] = sprintf( __( 'Parella: %1$s i %2$s', 'padelcamps' ), $names[0], $names[1] );
				}
				if ( (float) $reg['amount'] > 0 && 'pending' === $reg['payment_status'] ) {
					$lines[] = '';
					$lines[] = sprintf( __( 'Import de la inscripció: %s', 'padelcamps' ), self::money( (float) $reg['amount'] ) );
					$howto   = $this->tournaments->form_config( $t )['payment_instructions'];
					if ( '' !== $howto ) {
						$lines[] = __( 'Instruccions de pagament:', 'padelcamps' );
						$lines[] = $howto;
					}
				}
				$lines[] = '';
				$lines[] = __( 'Quan es publiquin els partits, els veureu en aquest enllaç personal (no el compartiu):', 'padelcamps' );
				$lines[] = $this->link( (int) $reg['id'] );
				$lines[] = '';
				$lines[] = __( 'Gràcies i molta sort!', 'padelcamps' );
				break;
			case 'waitlist':
				$subject = sprintf( __( 'Llista d’espera: %s', 'padelcamps' ), $tname );
				/* translators: 1: tournament, 2: category */
				$lines[] = sprintf( __( 'La vostra inscripció al torneig «%1$s», categoria «%2$s», és a la llista d’espera. Us avisarem si queda una plaça lliure.', 'padelcamps' ), $tname, $cname );
				break;
			case 'cancelled':
				$subject = sprintf( __( 'Inscripció cancel·lada: %s', 'padelcamps' ), $tname );
				/* translators: 1: tournament, 2: category */
				$lines[] = sprintf( __( 'La vostra inscripció al torneig «%1$s», categoria «%2$s», s’ha cancel·lat. Si creieu que és un error, responeu aquest correu.', 'padelcamps' ), $tname, $cname );
				break;
			default: // paid
				$subject = sprintf( __( 'Pagament rebut: %s', 'padelcamps' ), $tname );
				/* translators: 1: tournament, 2: amount */
				$lines[] = sprintf( __( 'Hem rebut el pagament de la vostra inscripció al torneig «%1$s» (%2$s). Gràcies!', 'padelcamps' ), $tname, self::money( (float) $reg['amount'] ) );
		}
		return array( $subject, implode( "\n", $lines ) );
	}

	// --- Automatic: results --------------------------------------------------------------

	/** Match row with its category and tournament, or null. */
	private function match_context( int $match_id ): ?array {
		$m   = ( new DrawRepository() )->find_match( $match_id );
		$cat = $m ? $this->tournaments->category( (int) $m['category_id'] ) : null;
		$t   = $cat ? $this->tournament( (int) $cat['tournament_id'] ) : null;
		return $m && $cat && $t ? array( $m, $cat, $t ) : null;
	}

	private function stage_of( array $t, int $match_id ): string {
		$all = $this->schedule->matches( (int) $t['id'] );
		foreach ( $all as $row ) {
			if ( (int) $row['id'] === $match_id ) {
				return ( new MatchLabel( $all ) )->stage( $row );
			}
		}
		return '';
	}

	/** A pair reported a result: ask the rival pair to confirm it. */
	public function result_reported( int $match_id, int $reporter_id, array $result ): void {
		$ctx = $this->match_context( $match_id );
		if ( ! $ctx ) {
			return;
		}
		list( $m, , $t ) = $ctx;
		if ( ! CommsSettings::enabled( $this->settings( $t ), 'result_confirm' ) ) {
			return;
		}
		$rival_id = (int) $m['reg_a_id'] === $reporter_id ? (int) $m['reg_b_id'] : (int) $m['reg_a_id'];
		$rival    = $rival_id ? $this->regs->find( $rival_id ) : null;
		$reporter = $this->regs->find( $reporter_id );
		if ( ! $rival || ! $reporter ) {
			return;
		}
		$this->deliver(
			$t,
			$rival,
			'result_confirm',
			function () use ( $t, $m, $rival, $reporter, $result ): array {
				$lines   = array( sprintf( __( 'Hola %s,', 'padelcamps' ), self::greeting_names( $rival ) ), '' );
				/* translators: 1: pair, 2: match stage, 3: score */
				$lines[] = sprintf( __( 'La parella %1$s ha enviat el resultat del vostre partit (%2$s): %3$s', 'padelcamps' ), self::pair_name( $reporter ), $this->stage_of( $t, (int) $m['id'] ), self::score_text( $result ) );
				$lines[] = '';
				$lines[] = __( 'Si és correcte, confirmeu-lo al vostre enllaç. Si no, indiqueu-ho i l’organització el revisarà:', 'padelcamps' );
				$lines[] = $this->link( (int) $rival['id'] );
				return array( sprintf( __( 'Resultat per confirmar: %s', 'padelcamps' ), $t['name'] ), implode( "\n", $lines ) );
			}
		);
	}

	/** The pairs disagree on a result: tell the organizer. */
	public function result_disputed( int $match_id ): void {
		$ctx = $this->match_context( $match_id );
		if ( ! $ctx ) {
			return;
		}
		list( $m, $cat, $t ) = $ctx;
		if ( ! CommsSettings::enabled( $this->settings( $t ), 'result_dispute' ) ) {
			return;
		}
		$names = array();
		foreach ( $this->regs->players_for( array( (int) $m['reg_a_id'], (int) $m['reg_b_id'] ) ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}
		$lines   = array( __( 'Les dues parelles no coincideixen en el resultat d’un partit.', 'padelcamps' ), '' );
		$lines[] = sprintf( __( 'Categoria: %s', 'padelcamps' ), $cat['name'] );
		/* translators: 1: pair, 2: pair, 3: match stage */
		$lines[] = sprintf( __( 'Partit: %1$s contra %2$s (%3$s)', 'padelcamps' ), $names[ (int) $m['reg_a_id'] ] ?? '', $names[ (int) $m['reg_b_id'] ] ?? '', $this->stage_of( $t, (int) $m['id'] ) );
		$lines[] = '';
		$lines[] = sprintf( __( 'Revisa’l i aplica el resultat correcte: %s', 'padelcamps' ), TournamentsPage::url( array( 'action' => 'edit', 'id' => (int) $t['id'], 'tab' => 'results' ) ) );
		/* translators: 1: category, 2: tournament */
		$this->to_organizer( $t, 'result_dispute', sprintf( __( 'Resultat en disputa: %1$s (%2$s)', 'padelcamps' ), $cat['name'], $t['name'] ), implode( "\n", $lines ) );
	}

	// --- Schedules -------------------------------------------------------------------------

	/**
	 * Every confirmed pair with its upcoming matches and whether the last email is still valid.
	 *
	 * @return array<int, array{reg:array, items:array, fingerprint:string, state:string, sent_at:?string}>
	 */
	public function schedule_overview( array $t, int $category_id = 0 ): array {
		$tid    = (int) $t['id'];
		$all    = $this->schedule->matches( $tid );
		$labels = new MatchLabel( $all );
		$courts = array_column( $this->schedule->courts( $tid ), 'name', 'id' );
		$sent   = $this->comms->schedule_sent( $tid );
		$ids    = array_keys( $sent );
		$names  = array();
		foreach ( $this->regs->players_for( $ids ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}

		$by_reg = array_fill_keys( $ids, array() );
		foreach ( $all as $m ) {
			if ( in_array( $m['status'], array( 'played', 'bye' ), true ) ) {
				continue;
			}
			foreach ( array( 'reg_a_id' => 'reg_b_id', 'reg_b_id' => 'reg_a_id' ) as $own => $other ) {
				$rid = (int) $m[ $own ];
				if ( $rid && isset( $by_reg[ $rid ] ) ) {
					$by_reg[ $rid ][] = array(
						'id'          => (int) $m['id'],
						'at'          => $m['scheduled_at'] ?: null,
						'court'       => (int) $m['court_id'],
						'rival'       => (int) $m[ $other ],
						'court_name'  => $m['court_id'] ? ( $courts[ (int) $m['court_id'] ] ?? '' ) : '',
						'rival_name'  => $m[ $other ] ? ( $names[ (int) $m[ $other ] ] ?? '' ) : '',
						'stage'       => $labels->stage( $m ),
						'category_id' => (int) $m['category_id'],
					);
				}
			}
		}

		$rows = array();
		foreach ( $this->comms->registration_ids( $tid, array( 'confirmed' ), $category_id ) as $rid ) {
			$items = $by_reg[ $rid ] ?? array();
			usort( $items, static fn( $x, $y ) => array( null === $x['at'], (string) $x['at'], $x['id'] ) <=> array( null === $y['at'], (string) $y['at'], $y['id'] ) );
			$fp           = ScheduleDigest::fingerprint( $items );
			$rows[ $rid ] = array(
				'reg_id'      => $rid,
				'name'        => $names[ $rid ] ?? '',
				'items'       => $items,
				'fingerprint' => $fp,
				'state'       => ScheduleDigest::state( $fp, $sent[ $rid ]['hash'] ?? null ),
				'sent_at'     => $sent[ $rid ]['sent_at'] ?? null,
			);
		}
		return $rows;
	}

	/** @return array{0:string, 1:string} [subject, body] of a pair's schedule email (in the current language). */
	private function compose_schedule( array $t, array $reg, array $row ): array {
		$changed = ScheduleDigest::CHANGED === $row['state'];
		$lines   = array( sprintf( __( 'Hola %s,', 'padelcamps' ), self::greeting_names( $reg ) ), '' );
		if ( $changed ) {
			$lines[] = __( 'Hi ha canvis en els vostres partits respecte del darrer correu.', 'padelcamps' );
		}
		/* translators: 1: tournament, 2: category */
		$lines[] = sprintf( __( 'Aquests són els vostres propers partits al torneig «%1$s» (%2$s):', 'padelcamps' ), $t['name'], $reg['category_name'] );
		foreach ( $row['items'] as $item ) {
			$when = array();
			if ( $item['at'] ) {
				$when[] = DateText::day_time( $item['at'] );
			}
			if ( '' !== $item['court_name'] ) {
				$when[] = $item['court_name'];
			}
			$lines[] = '';
			$lines[] = '· ' . ( $when ? implode( ' · ', $when ) : __( 'Horari per confirmar', 'padelcamps' ) );
			$rival   = '' !== $item['rival_name'] ? $item['rival_name'] : __( 'Rival per determinar', 'padelcamps' );
			$lines[] = '  ' . $item['stage'] . ' — ' . __( 'contra', 'padelcamps' ) . ' ' . $rival;
		}
		$lines[] = '';
		$lines[] = __( 'Al vostre enllaç personal hi teniu els partits sempre al dia i hi podeu entrar els resultats:', 'padelcamps' );
		$lines[] = $this->link( (int) $reg['id'] );
		$lines[] = __( 'Aquest enllaç és personal de la vostra parella: no el compartiu.', 'padelcamps' );

		$subject = $changed
			? sprintf( __( 'Canvis en els vostres partits: %s', 'padelcamps' ), $t['name'] )
			: sprintf( __( 'Els vostres partits: %s', 'padelcamps' ), $t['name'] );
		return array( $subject, implode( "\n", $lines ) );
	}

	/**
	 * Send each pair its matches.
	 *
	 * @param string $mode 'changed' (never sent or changed since) | 'all'
	 * @return array{pairs:int, sent:int, failed:int}
	 */
	public function send_schedules( array $t, string $mode, int $category_id = 0 ): array {
		self::long_request();
		$out = array( 'pairs' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( $this->schedule_overview( $t, $category_id ) as $rid => $row ) {
			if ( ScheduleDigest::NONE === $row['state'] || ( 'all' !== $mode && ! ScheduleDigest::needs_sending( $row['state'] ) ) ) {
				continue;
			}
			$reg = $this->regs->find( $rid );
			if ( ! $reg ) {
				continue;
			}
			$res = $this->deliver( $t, $reg, 'schedule', fn() => $this->compose_schedule( $t, $reg, $row ) );
			if ( $res['sent'] > 0 ) {
				$this->comms->mark_schedule_sent( $rid, $row['fingerprint'] );
			}
			++$out['pairs'];
			$out['sent']   += $res['sent'];
			$out['failed'] += $res['failed'];
		}
		return $out;
	}

	/** Example schedule email (first pair with matches), in the admin's language. @return ?array{name:string, subject:string, body:string} */
	public function schedule_example( array $t, int $category_id = 0 ): ?array {
		foreach ( $this->schedule_overview( $t, $category_id ) as $rid => $row ) {
			if ( ScheduleDigest::NONE === $row['state'] ) {
				continue;
			}
			$reg = $this->regs->find( $rid );
			if ( $reg ) {
				list( $subject, $body ) = $this->compose_schedule( $t, $reg, $row );
				return array( 'name' => $row['name'], 'subject' => $subject, 'body' => $this->with_signature( $t, $body ) );
			}
		}
		return null;
	}

	private function with_signature( array $t, string $body ): string {
		$s = $this->settings( $t );
		return '' !== $s['signature'] ? $body . "\n\n-- \n" . $s['signature'] : $body;
	}

	// --- Free messages ----------------------------------------------------------------------

	/** @return array<string, string> placeholder values for a registration. */
	private function vars( array $t, array $reg ): array {
		$players = wp_list_pluck( $reg['players'] ?? array(), 'name' );
		return array(
			'nom'       => MessageTemplate::first_name( (string) ( $players[0] ?? $reg['contact_name'] ?? '' ) ),
			'parella'   => self::pair_name( $reg ),
			'torneig'   => (string) $t['name'],
			'categoria' => (string) $reg['category_name'],
			'enllac'    => 'confirmed' === $reg['status'] ? $this->link( (int) $reg['id'] ) : '',
		);
	}

	/** @return int[] */
	public function message_recipients( array $t, array $statuses, int $category_id = 0 ): array {
		return $this->comms->registration_ids( (int) $t['id'], $statuses, $category_id );
	}

	/** @return array{pairs:int, sent:int, failed:int} */
	public function send_message( array $t, string $subject, string $body, array $statuses, int $category_id = 0 ): array {
		self::long_request();
		$out = array( 'pairs' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( $this->message_recipients( $t, $statuses, $category_id ) as $rid ) {
			$reg = $this->regs->find( $rid );
			if ( ! $reg ) {
				continue;
			}
			$vars = $this->vars( $t, $reg );
			$res  = $this->deliver( $t, $reg, 'custom', static fn() => array( MessageTemplate::render( $subject, $vars ), MessageTemplate::render( $body, $vars ) ) );
			++$out['pairs'];
			$out['sent']   += $res['sent'];
			$out['failed'] += $res['failed'];
		}
		return $out;
	}

	/** Send a test copy to $to, filled in with the first recipient's data (or sample values). */
	public function send_test( array $t, string $to, string $subject, string $body, array $statuses, int $category_id = 0 ): bool {
		$ids  = $this->message_recipients( $t, $statuses, $category_id );
		$reg  = $ids ? $this->regs->find( $ids[0] ) : null;
		$vars = $reg ? $this->vars( $t, $reg ) : array(
			'nom'       => 'Anna',
			'parella'   => 'Anna Puig / Pau Roca',
			'torneig'   => (string) $t['name'],
			'categoria' => '—',
			'enllac'    => home_url( '/' ),
		);
		return Mailer::send(
			array(
				'type'          => 'test',
				'to'            => $to,
				'subject'       => '[' . __( 'Prova', 'padelcamps' ) . '] ' . MessageTemplate::render( $subject, $vars ),
				'body'          => $this->with_signature( $t, MessageTemplate::render( $body, $vars ) ),
				'tournament_id' => (int) $t['id'],
			) + $this->sender( $t )
		);
	}

	/** Sending many emails can take a while. */
	private static function long_request(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be disabled on the host.
		}
	}
}
