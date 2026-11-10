<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\CommsRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\CommsSettings;
use PadelCamps\Engine\MessageTemplate;
use PadelCamps\Engine\ScheduleDigest;
use PadelCamps\Notifications\Mailer;
use PadelCamps\Service\Communication;

defined( 'ABSPATH' ) || exit;

/**
 * "Comunicació" tab: send each pair its matches and times (only the pairs with
 * changes, or all), free messages to pairs, automatic emails on/off, sender,
 * and the send log.
 */
final class CommunicationTab {

	private TournamentRepository $tournaments;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_comms', array( $this, 'handle' ) );
	}

	private static function url( int $tid, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'communication' ), $extra ) );
	}

	private function form_open( int $tid, string $op, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="padelcamps_comms"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_comms' );
	}

	// --- Labels -------------------------------------------------------------------

	private static function event_labels(): array {
		return array(
			'confirmed'      => array( __( 'Inscripció confirmada', 'padelcamps' ), __( 'Amb l’import i les instruccions de pagament si encara no han pagat, i l’enllaç personal de la parella.', 'padelcamps' ) ),
			'waitlist'       => array( __( 'Passa a la llista d’espera', 'padelcamps' ), '' ),
			'cancelled'      => array( __( 'Inscripció cancel·lada', 'padelcamps' ), '' ),
			'paid'           => array( __( 'Pagament rebut', 'padelcamps' ), __( 'Quan marques una inscripció com a pagada.', 'padelcamps' ) ),
			'result_confirm' => array( __( 'Resultat per confirmar', 'padelcamps' ), __( 'A la parella rival, quan l’altra envia un resultat des del seu enllaç.', 'padelcamps' ) ),
			'result_dispute' => array( __( 'Resultat en disputa', 'padelcamps' ), __( 'A l’organització, quan les parelles no hi estan d’acord.', 'padelcamps' ) ),
		);
	}

	public static function type_label( string $type ): string {
		$labels = array(
			'registration_receipt'   => __( 'Inscripció rebuda', 'padelcamps' ),
			'registration_organizer' => __( 'Nova inscripció (organització)', 'padelcamps' ),
			'reg_confirmed'          => __( 'Inscripció confirmada', 'padelcamps' ),
			'reg_waitlist'           => __( 'Passa a la llista d’espera', 'padelcamps' ),
			'reg_cancelled'          => __( 'Inscripció cancel·lada', 'padelcamps' ),
			'reg_paid'               => __( 'Pagament rebut', 'padelcamps' ),
			'schedule'               => __( 'Partits i horaris', 'padelcamps' ),
			'result_confirm'         => __( 'Resultat per confirmar', 'padelcamps' ),
			'result_dispute'         => __( 'Resultat en disputa', 'padelcamps' ),
			'custom'                 => __( 'Missatge', 'padelcamps' ),
			'test'                   => __( 'Prova', 'padelcamps' ),
		);
		return $labels[ $type ] ?? $type;
	}

	private static function state_label( string $state ): array {
		$labels = array(
			ScheduleDigest::NEW     => array( __( 'Mai enviat', 'padelcamps' ), '#b32d2e' ),
			ScheduleDigest::CHANGED => array( __( 'Amb canvis', 'padelcamps' ), '#996800' ),
			ScheduleDigest::SAME    => array( __( 'Al dia', 'padelcamps' ), '#007017' ),
			ScheduleDigest::NONE    => array( __( 'Sense partits pendents', 'padelcamps' ), '#8c8f94' ),
		);
		return $labels[ $state ] ?? array( $state, '#8c8f94' );
	}

	// --- Draft of the free message (kept between a test send and the real one) -------

	private static function draft_key( int $tid ): string {
		return 'padelcamps_msg_' . get_current_user_id() . '_' . $tid;
	}

	private static function draft( int $tid ): array {
		$d = get_transient( self::draft_key( $tid ) );
		return is_array( $d ) ? $d + array( 'subject' => '', 'body' => '', 'statuses' => array( 'confirmed' ), 'category' => 0 ) : array( 'subject' => '', 'body' => '', 'statuses' => array( 'confirmed' ), 'category' => 0 );
	}

	// --- Rendering ---------------------------------------------------------------------

	public function render( array $t ): void {
		$tid   = (int) $t['id'];
		$comms = new Communication();
		$this->notice();

		echo '<p style="max-width:820px">' . esc_html__( 'Els correus als jugadors s’escriuen en l’idioma en què es van inscriure i surten amb el nom del torneig com a remitent. Les respostes arriben a l’adreça que indiquis a sota.', 'padelcamps' ) . '</p>';

		$this->section_schedules( $t, $comms );
		$this->section_message( $t, $comms );
		$this->section_settings( $t, $comms );
		$this->section_log( $tid );
	}

	private function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$code   = isset( $_GET['cmsg'] ) ? sanitize_key( wp_unslash( $_GET['cmsg'] ) ) : '';
		$pairs  = isset( $_GET['cp'] ) ? absint( $_GET['cp'] ) : 0;
		$sent   = isset( $_GET['cs'] ) ? absint( $_GET['cs'] ) : 0;
		$failed = isset( $_GET['cf'] ) ? absint( $_GET['cf'] ) : 0;
		$extra  = isset( $_GET['cx'] ) ? sanitize_text_field( wp_unslash( $_GET['cx'] ) ) : '';
		// phpcs:enable
		$type = 'success';
		switch ( $code ) {
			case 'saved':
				$text = __( 'Configuració desada.', 'padelcamps' );
				break;
			case 'sent':
				/* translators: 1: number of pairs, 2: number of emails */
				$text = sprintf( __( 'Enviat a %1$d parelles (%2$d correus).', 'padelcamps' ), $pairs, $sent );
				if ( $failed ) {
					$type = 'warning';
					/* translators: %d: number of emails */
					$text .= ' ' . sprintf( _n( '%d correu no s’ha pogut enviar: revisa el registre.', '%d correus no s’han pogut enviar: revisa el registre.', $failed, 'padelcamps' ), $failed );
				}
				break;
			case 'nothing':
				$type = 'info';
				$text = __( 'No hi havia ningú a qui enviar-ho.', 'padelcamps' );
				break;
			case 'test_sent':
				/* translators: %s: email address */
				$text = sprintf( __( 'Prova enviada a %s.', 'padelcamps' ), $extra );
				break;
			case 'test_failed':
				$type = 'error';
				$text = __( 'No s’ha pogut enviar la prova. Revisa la configuració de correu de WordPress.', 'padelcamps' );
				break;
			case 'err_empty':
				$type = 'error';
				$text = __( 'Escriu l’assumpte i el missatge.', 'padelcamps' );
				break;
			case 'err_status':
				$type = 'error';
				$text = __( 'Tria a qui l’envies.', 'padelcamps' );
				break;
			case 'err_keys':
				$type = 'error';
				/* translators: %s: placeholder names */
				$keys = array_filter( array_map( 'sanitize_key', explode( ',', $extra ) ) );
				$text = sprintf( __( 'Aquests camps no existeixen: %s. Revisa’ls abans d’enviar.', 'padelcamps' ), '{' . implode( '}, {', $keys ) . '}' );
				break;
			default:
				return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
	}

	private function category_select( array $t, string $name, int $value, bool $submit = false ): void {
		echo '<select name="' . esc_attr( $name ) . '"' . ( $submit ? ' onchange="this.form.submit()"' : '' ) . '><option value="0">' . esc_html__( 'Totes les categories', 'padelcamps' ) . '</option>';
		foreach ( $this->tournaments->categories( (int) $t['id'] ) as $c ) {
			printf( '<option value="%d"%s>%s</option>', (int) $c['id'], selected( $value, (int) $c['id'], false ), esc_html( $c['name'] ) );
		}
		echo '</select>';
	}

	private function section_schedules( array $t, Communication $comms ): void {
		$tid = (int) $t['id'];
		$cat = isset( $_GET['ccat'] ) ? absint( $_GET['ccat'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = $comms->schedule_overview( $t, $cat );
		$counts = array_fill_keys( array( ScheduleDigest::NEW, ScheduleDigest::CHANGED, ScheduleDigest::SAME, ScheduleDigest::NONE ), 0 );
		foreach ( $rows as $r ) {
			++$counts[ $r['state'] ];
		}
		$pending = $counts[ ScheduleDigest::NEW ] + $counts[ ScheduleDigest::CHANGED ];
		$any     = $pending + $counts[ ScheduleDigest::SAME ];

		echo '<h2>' . esc_html__( 'Partits i horaris', 'padelcamps' ) . '</h2>';
		echo '<p style="max-width:820px">' . esc_html__( 'Cada parella rep un correu amb els seus propers partits (dia, hora, pista i rival) i el seu enllaç personal. Si després canvies horaris o es coneixen nous rivals, aquí veuràs quines parelles tenen canvis i podràs avisar només aquestes.', 'padelcamps' ) . '</p>';

		echo '<form method="get" style="margin-bottom:8px">';
		foreach ( array( 'page' => TournamentsPage::SLUG, 'action' => 'edit', 'id' => $tid, 'tab' => 'communication' ) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		$this->category_select( $t, 'ccat', $cat, true );
		echo '</form>';

		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Encara no hi ha parelles confirmades.', 'padelcamps' ) . '</p>';
			return;
		}

		echo '<p>';
		foreach ( $counts as $state => $n ) {
			list( $label, $color ) = self::state_label( $state );
			echo '<span style="margin-right:14px"><strong style="color:' . esc_attr( $color ) . '">' . (int) $n . '</strong> ' . esc_html( $label ) . '</span>';
		}
		echo '</p><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">';

		$this->form_open( $tid, 'send_schedules' );
		echo '<input type="hidden" name="mode" value="changed"><input type="hidden" name="cat" value="' . (int) $cat . '">';
		/* translators: %d: number of pairs */
		echo '<button class="button button-primary"' . disabled( 0 === $pending, true, false ) . '>' . esc_html( sprintf( _n( 'Envia a %d parella nova o amb canvis', 'Envia a les %d parelles noves o amb canvis', max( 1, $pending ), 'padelcamps' ), $pending ) ) . '</button></form>';

		/* translators: %d: number of pairs */
		$this->form_open( $tid, 'send_schedules', sprintf( __( 'S’enviarà el correu a %d parelles, també a les que ja el tenen al dia. Continuar?', 'padelcamps' ), $any ) );
		echo '<input type="hidden" name="mode" value="all"><input type="hidden" name="cat" value="' . (int) $cat . '">';
		echo '<button class="button"' . disabled( 0 === $any, true, false ) . '>' . esc_html__( 'Torna-ho a enviar a totes', 'padelcamps' ) . '</button></form>';

		$this->form_open( $tid, 'test_schedule' );
		echo '<input type="hidden" name="cat" value="' . (int) $cat . '">';
		echo '<button class="button"' . disabled( 0 === $any, true, false ) . '>' . esc_html__( 'Envia’m un exemple', 'padelcamps' ) . '</button></form>';
		echo '</div>';

		$example = $comms->schedule_example( $t, $cat );
		if ( $example ) {
			/* translators: %s: pair names */
			echo '<details style="margin-bottom:10px;max-width:820px"><summary>' . esc_html( sprintf( __( 'Mostra el correu de %s', 'padelcamps' ), $example['name'] ) ) . '</summary>';
			echo '<div style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin-top:6px"><p><strong>' . esc_html( $example['subject'] ) . '</strong></p><pre style="white-space:pre-wrap;font-family:inherit;margin:0">' . esc_html( $example['body'] ) . '</pre></div></details>';
		}

		echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>' . esc_html__( 'Parella', 'padelcamps' ) . '</th><th>' . esc_html__( 'Partits pendents', 'padelcamps' ) . '</th><th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th><th>' . esc_html__( 'Últim enviament', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			list( $label, $color ) = self::state_label( $r['state'] );
			echo '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . count( $r['items'] ) . '</td><td style="color:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</td><td>' . esc_html( $r['sent_at'] ? mysql2date( 'j M Y · H:i', $r['sent_at'] ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function section_message( array $t, Communication $comms ): void {
		$tid   = (int) $t['id'];
		$draft = self::draft( $tid );
		echo '<h2 style="margin-top:2em">' . esc_html__( 'Missatge a les parelles', 'padelcamps' ) . '</h2>';
		$this->form_open( $tid, 'send_message' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'A qui', 'padelcamps' ) . '</th><td><fieldset>';
		foreach ( array( 'confirmed', 'pending', 'waitlist' ) as $s ) {
			$n = count( $comms->message_recipients( $t, array( $s ) ) );
			printf(
				'<label style="margin-right:14px"><input type="checkbox" name="statuses[]" value="%s"%s> %s (%d)</label>',
				esc_attr( $s ),
				checked( in_array( $s, $draft['statuses'], true ), true, false ),
				esc_html( RegistrationsView::status_label( $s ) ),
				(int) $n
			);
		}
		echo '</fieldset><p>';
		$this->category_select( $t, 'cat', (int) $draft['category'] );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pc-msg-subject">' . esc_html__( 'Assumpte', 'padelcamps' ) . '</label></th><td><input type="text" id="pc-msg-subject" name="subject" class="large-text" maxlength="180" value="' . esc_attr( $draft['subject'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="pc-msg-body">' . esc_html__( 'Missatge', 'padelcamps' ) . '</label></th><td><textarea id="pc-msg-body" name="body" rows="8" class="large-text">' . esc_textarea( $draft['body'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Pots fer servir:', 'padelcamps' ) . ' <code>{nom}</code> ' . esc_html__( '(nom del jugador 1)', 'padelcamps' ) . ', <code>{parella}</code>, <code>{torneig}</code>, <code>{categoria}</code>, <code>{enllac}</code> ' . esc_html__( '(enllaç personal; només per a parelles confirmades)', 'padelcamps' ) . '.</p></td></tr>';
		echo '</table><p>';
		echo '<button class="button" name="test" value="1">' . esc_html__( 'Envia’m una prova', 'padelcamps' ) . '</button> ';
		echo '<button class="button button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'S’enviarà el missatge a totes les parelles triades. Continuar?', 'padelcamps' ) ) ) . ');">' . esc_html__( 'Envia el missatge', 'padelcamps' ) . '</button>';
		echo '</p></form>';
	}

	private function section_settings( array $t, Communication $comms ): void {
		$tid    = (int) $t['id'];
		$s      = $comms->settings( $t );
		$labels = self::event_labels();
		echo '<h2 style="margin-top:2em">' . esc_html__( 'Avisos automàtics i remitent', 'padelcamps' ) . '</h2>';
		$this->form_open( $tid, 'save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Avisos automàtics', 'padelcamps' ) . '</th><td><fieldset>';
		foreach ( CommsSettings::EVENTS as $event ) {
			printf(
				'<label><input type="checkbox" name="auto[%s]" value="1"%s> %s</label>%s<br>',
				esc_attr( $event ),
				checked( CommsSettings::enabled( $s, $event ), true, false ),
				esc_html( $labels[ $event ][0] ),
				'' !== $labels[ $event ][1] ? ' <span class="description">— ' . esc_html( $labels[ $event ][1] ) . '</span>' : ''
			);
		}
		echo '<p class="description">' . esc_html__( 'Els avisos d’inscripció s’envien quan canvies l’estat des de la pestanya Inscripcions (allà també els pots desactivar en cada canvi). El correu de «Inscripció rebuda» s’envia sempre.', 'padelcamps' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Destinataris', 'padelcamps' ) . '</th><td><label><input type="checkbox" name="both_players" value="1"' . checked( $s['both_players'], true, false ) . '> ' . esc_html__( 'Escriu també al jugador 2 si va deixar el correu', 'padelcamps' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="pc-c-from">' . esc_html__( 'Nom del remitent', 'padelcamps' ) . '</label></th><td><input type="text" id="pc-c-from" name="from_name" class="regular-text" maxlength="120" value="' . esc_attr( $s['from_name'] ) . '" placeholder="' . esc_attr( $t['name'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Buit = el nom del torneig. L’adreça del remitent és la de WordPress (o del teu plugin SMTP), perquè els correus no acabin a correu brossa.', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pc-c-reply">' . esc_html__( 'Respostes a', 'padelcamps' ) . '</label></th><td><input type="email" id="pc-c-reply" name="reply_to" class="regular-text" value="' . esc_attr( $s['reply_to'] ) . '" placeholder="' . esc_attr( $comms->organizer_email( $t ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Buit = el correu d’avisos del formulari o, si no n’hi ha, el de l’administrador del web.', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pc-c-sig">' . esc_html__( 'Signatura', 'padelcamps' ) . '</label></th><td><textarea id="pc-c-sig" name="signature" rows="3" class="large-text">' . esc_textarea( $s['signature'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'S’afegeix al final dels correus als jugadors (p. ex. telèfon de l’organització o adreça del club).', 'padelcamps' ) . '</p></td></tr>';

		echo '</table><p><button class="button button-primary">' . esc_html__( 'Desa', 'padelcamps' ) . '</button></p></form>';
	}

	private function section_log( int $tid ): void {
		$rows = ( new CommsRepository() )->log( $tid, 100 );
		echo '<h2 style="margin-top:2em">' . esc_html__( 'Registre d’enviaments', 'padelcamps' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Encara no s’ha enviat cap correu.', 'padelcamps' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Els últims 100. «Enviat» vol dir que WordPress l’ha lliurat al servidor de correu, no que s’hagi llegit.', 'padelcamps' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Data', 'padelcamps' ) . '</th><th>' . esc_html__( 'Tipus', 'padelcamps' ) . '</th><th>' . esc_html__( 'Destinatari', 'padelcamps' ) . '</th><th>' . esc_html__( 'Assumpte', 'padelcamps' ) . '</th><th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$ok = 'sent' === $r['status'];
			echo '<tr><td>' . esc_html( $r['sent_at'] ? mysql2date( 'j M Y · H:i', $r['sent_at'] ) : '—' ) . '</td><td>' . esc_html( self::type_label( (string) $r['type'] ) ) . '</td><td>' . esc_html( (string) $r['recipient'] ) . '</td><td>' . esc_html( (string) $r['subject'] ) . '</td>';
			echo '<td style="color:' . ( $ok ? '#007017' : '#b32d2e' ) . '">' . esc_html( $ok ? __( 'Enviat', 'padelcamps' ) : __( 'Error', 'padelcamps' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// --- Handler -------------------------------------------------------------------------

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_comms' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		$tid  = absint( $post( 'tid' ) );
		$t    = $tid ? $this->tournaments->find( $tid ) : null;
		if ( ! $t ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}
		$comms = new Communication();
		$cat   = absint( $post( 'cat' ) );
		$args  = array();

		switch ( sanitize_key( $post( 'op' ) ) ) {
			case 'save':
				$auto = isset( $_POST['auto'] ) && is_array( $_POST['auto'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['auto'] ) ) ) : array();
				$this->tournaments->save_setting(
					$tid,
					'comms',
					CommsSettings::normalize(
						array(
							'auto'         => array_combine( CommsSettings::EVENTS, array_map( static fn( $e ) => in_array( $e, $auto, true ), CommsSettings::EVENTS ) ),
							'from_name'    => sanitize_text_field( $post( 'from_name' ) ),
							'reply_to'     => sanitize_email( $post( 'reply_to' ) ),
							'signature'    => sanitize_textarea_field( $post( 'signature' ) ),
							'both_players' => '1' === $post( 'both_players' ),
						)
					)
				);
				$args['cmsg'] = 'saved';
				break;

			case 'send_schedules':
				$res  = $comms->send_schedules( $t, 'all' === $post( 'mode' ) ? 'all' : 'changed', $cat );
				$args = self::result_args( $res ) + ( $cat ? array( 'ccat' => $cat ) : array() );
				break;

			case 'test_schedule':
				$example = $comms->schedule_example( $t, $cat );
				$to      = (string) wp_get_current_user()->user_email;
				$ok      = $example && Mailer::send(
					array(
						'type'          => 'test',
						'to'            => $to,
						'subject'       => '[' . __( 'Prova', 'padelcamps' ) . '] ' . $example['subject'],
						'body'          => $example['body'],
						'tournament_id' => $tid,
					) + $comms->sender( $t )
				);
				$args = $ok ? array( 'cmsg' => 'test_sent', 'cx' => $to ) : array( 'cmsg' => 'test_failed' );
				break;

			case 'send_message':
				$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) ) : array();
				$statuses = array_values( array_intersect( $statuses, array( 'confirmed', 'pending', 'waitlist' ) ) );
				$subject  = sanitize_text_field( $post( 'subject' ) );
				$body     = sanitize_textarea_field( $post( 'body' ) );
				set_transient( self::draft_key( $tid ), array( 'subject' => $subject, 'body' => $body, 'statuses' => $statuses, 'category' => $cat ), DAY_IN_SECONDS );

				$unknown = MessageTemplate::unknown( $subject . ' ' . $body );
				if ( '' === trim( $subject ) || '' === trim( $body ) ) {
					$args['cmsg'] = 'err_empty';
				} elseif ( ! $statuses ) {
					$args['cmsg'] = 'err_status';
				} elseif ( $unknown ) {
					$args = array( 'cmsg' => 'err_keys', 'cx' => implode( ',', $unknown ) );
				} elseif ( '1' === $post( 'test' ) ) {
					$to   = (string) wp_get_current_user()->user_email;
					$args = $comms->send_test( $t, $to, $subject, $body, $statuses, $cat ) ? array( 'cmsg' => 'test_sent', 'cx' => $to ) : array( 'cmsg' => 'test_failed' );
				} else {
					$args = self::result_args( $comms->send_message( $t, $subject, $body, $statuses, $cat ) );
					if ( $args['cs'] ?? 0 ) {
						delete_transient( self::draft_key( $tid ) );
					}
				}
				break;
		}
		// phpcs:enable
		wp_safe_redirect( self::url( $tid, $args ) );
		exit;
	}

	private static function result_args( array $res ): array {
		if ( 0 === $res['pairs'] ) {
			return array( 'cmsg' => 'nothing' );
		}
		return array( 'cmsg' => 'sent', 'cp' => $res['pairs'], 'cs' => $res['sent'], 'cf' => $res['failed'] );
	}
}
