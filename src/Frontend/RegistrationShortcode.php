<?php
namespace PadelCamps\Frontend;

use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\RegistrationForm;
use PadelCamps\Engine\TournamentStatus;
use PadelCamps\Notifications\RegistrationMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Public registration form: [padelcamps_registration id="1"] (or slug="...").
 * Submissions go through admin-post.php and redirect back to the page with a
 * short-lived token, so errors keep what the visitor typed and a refresh never
 * resubmits the form.
 */
final class RegistrationShortcode {

	public const TAG = 'padelcamps_registration';

	private const RATE_LIMIT  = 5;
	private const RATE_WINDOW = 600;
	private const STATE_TTL   = 900;

	private TournamentRepository $tournaments;
	private RegistrationRepository $registrations;

	public function __construct() {
		$this->tournaments   = new TournamentRepository();
		$this->registrations = new RegistrationRepository();
	}

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'admin_post_nopriv_padelcamps_register', array( $this, 'handle' ) );
		add_action( 'admin_post_padelcamps_register', array( $this, 'handle' ) );
	}

	// --- Texts ---------------------------------------------------------------

	public static function default_consent_text( string $key ): string {
		$texts = array(
			'data'   => __( 'Autoritzo l’organització a tractar les meves dades per gestionar el torneig, publicar-ne els resultats i enviar-me comunicacions sobre aquest torneig.', 'padelcamps' ),
			'health' => __( 'Confirmo que em trobo en condicions físiques adequades per practicar pàdel i assumeixo els riscos de l’activitat esportiva.', 'padelcamps' ),
			'image'  => __( 'Autoritzo l’ús de la meva imatge en fotografies i vídeos del torneig per a la seva difusió a la web i a les xarxes socials.', 'padelcamps' ),
		);
		return $texts[ $key ] ?? '';
	}

	private static function error_message( string $code ): string {
		$messages = array(
			'required'       => __( 'Aquest camp és obligatori.', 'padelcamps' ),
			'invalid'        => __( 'El valor introduït no és vàlid.', 'padelcamps' ),
			'invalid_email'  => __( 'Introdueix un correu electrònic vàlid.', 'padelcamps' ),
			'invalid_phone'  => __( 'Introdueix un telèfon vàlid.', 'padelcamps' ),
			'invalid_dni'    => __( 'Introdueix un DNI o NIE vàlid.', 'padelcamps' ),
			'invalid_choice' => __( 'Tria una de les opcions.', 'padelcamps' ),
			'same_email'     => __( 'Cada jugador ha de tenir un correu diferent.', 'padelcamps' ),
		);
		return $messages[ $code ] ?? $messages['invalid'];
	}

	private static function form_error_message( string $code ): string {
		$messages = array(
			'fields'    => __( 'Revisa els camps marcats.', 'padelcamps' ),
			'nonce'     => __( 'La sessió ha caducat. Torna a enviar el formulari.', 'padelcamps' ),
			'rate'      => __( 'Has fet massa intents. Torna-ho a provar d’aquí a una estona.', 'padelcamps' ),
			'duplicate' => __( 'Aquest jugador ja té una inscripció en aquesta categoria.', 'padelcamps' ),
			'closed'    => __( 'Les inscripcions d’aquest torneig no estan obertes.', 'padelcamps' ),
			'server'    => __( 'No s’ha pogut desar la inscripció. Torna-ho a provar.', 'padelcamps' ),
		);
		return $messages[ $code ] ?? $messages['server'];
	}

	public static function availability_label( string $value ): string {
		if ( 'yes' === $value ) {
			return __( 'Disponible', 'padelcamps' );
		}
		if ( 'no' === $value ) {
			return __( 'No disponible', 'padelcamps' );
		}
		if ( 0 === strpos( $value, 'from:' ) ) {
			/* translators: %s: time, e.g. 18:00 */
			return sprintf( __( 'A partir de les %s', 'padelcamps' ), substr( $value, 5 ) );
		}
		if ( 0 === strpos( $value, 'only:' ) ) {
			/* translators: %s: time, e.g. 22:15 */
			return sprintf( __( 'Només a les %s', 'padelcamps' ), substr( $value, 5 ) );
		}
		return $value;
	}

	private static function slot_title( array $slot ): string {
		$dt    = new \DateTimeImmutable( $slot['date'] . ' 12:00:00', wp_timezone() );
		$title = wp_date( 'l, ' . get_option( 'date_format' ), $dt->getTimestamp() );
		return '' !== $slot['label'] ? $title . ' — ' . $slot['label'] : $title;
	}

	// --- Transient state (errors / success) ---------------------------------

	private static function new_token(): string {
		return strtolower( wp_generate_password( 20, false, false ) );
	}

	private static function save_state( array $state ): string {
		$token = self::new_token();
		set_transient( 'padelcamps_form_' . $token, $state, self::STATE_TTL );
		return $token;
	}

	private static function load_state( string $param ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- random read-only token.
		$token = isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ? wp_unslash( $_GET[ $param ] ) : '';
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			return null;
		}
		$state = get_transient( 'padelcamps_form_' . $token );
		return is_array( $state ) ? $state : null;
	}

	// --- Rendering -----------------------------------------------------------

	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$tournament = null;
		if ( absint( $atts['id'] ) ) {
			$tournament = $this->tournaments->find( absint( $atts['id'] ) );
		} elseif ( '' !== $atts['slug'] ) {
			$tournament = $this->tournaments->find_by_slug( sanitize_title( $atts['slug'] ) );
		}

		wp_enqueue_style( 'padelcamps-form', PADELCAMPS_URL . 'assets/css/form.css', array(), PADELCAMPS_VERSION );

		$wrap_open  = '<div class="pc-form-wrap" id="padelcamps-%d">';
		if ( ! $tournament || ! TournamentStatus::is_public( $tournament['status'] ) ) {
			return '<div class="pc-form-wrap"><p class="pc-notice">' . esc_html__( 'Aquest torneig no està disponible.', 'padelcamps' ) . '</p></div>';
		}
		$tid    = (int) $tournament['id'];
		$config = $this->tournaments->form_config( $tournament );
		$html   = sprintf( $wrap_open, $tid );

		// Success page.
		$ok = self::load_state( 'pc_ok' );
		if ( $ok && (int) ( $ok['tournament_id'] ?? 0 ) === $tid ) {
			return $html . $this->render_success( $ok, $config ) . '</div>';
		}

		$open = $this->open_state( $tournament, $config );
		if ( 'open' !== $open['state'] ) {
			return $html . '<p class="pc-notice">' . esc_html( $open['message'] ) . '</p></div>';
		}

		$categories = $this->tournaments->categories( $tid );
		if ( ! $categories ) {
			return $html . '<p class="pc-notice">' . esc_html__( 'Encara no hi ha categories disponibles.', 'padelcamps' ) . '</p></div>';
		}

		$err    = self::load_state( 'pc_e' );
		$err    = $err && (int) ( $err['tournament_id'] ?? 0 ) === $tid ? $err : array();
		$values = $err['values'] ?? array();
		$errors = $err['errors'] ?? array();

		return $html . $this->render_form( $tournament, $config, $categories, $values, $errors, $err['form_errors'] ?? array() ) . '</div>';
	}

	/** @return array{state:string, message:string} */
	private function open_state( array $tournament, array $config ): array {
		$tz    = wp_timezone();
		$opens = RegistrationForm::window_ts( $config['opens_at'], $tz );
		$close = RegistrationForm::window_ts( $config['closes_at'], $tz );
		$state = RegistrationForm::open_state( $tournament['status'], $opens, $close, time() );

		switch ( $state ) {
			case 'not_open_yet':
				/* translators: %s: date and time */
				$message = sprintf( __( 'Les inscripcions s’obriran el %s.', 'padelcamps' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $opens ) );
				break;
			case 'closed':
				/* translators: %s: date and time */
				$message = sprintf( __( 'Les inscripcions van tancar el %s.', 'padelcamps' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $close ) );
				break;
			case 'not_accepting':
				$message = __( 'Les inscripcions d’aquest torneig no estan obertes.', 'padelcamps' );
				break;
			default:
				$message = '';
		}
		return array(
			'state'   => $state,
			'message' => $message,
		);
	}

	private function render_success( array $ok, array $config ): string {
		$h  = '<div class="pc-success" role="status">';
		$h .= '<h2>' . esc_html__( 'Inscripció rebuda', 'padelcamps' ) . '</h2>';
		if ( 'waitlist' === $ok['status'] ) {
			/* translators: %s: category name */
			$h .= '<p>' . esc_html( sprintf( __( 'La categoria «%s» està completa: has quedat a la llista d’espera i et contactarem si queda una plaça lliure.', 'padelcamps' ), $ok['category'] ) ) . '</p>';
		} else {
			/* translators: %s: category name */
			$h .= '<p>' . esc_html( sprintf( __( 'Hem rebut la teva inscripció a la categoria «%s». T’avisarem quan l’organització la confirmi.', 'padelcamps' ), $ok['category'] ) ) . '</p>';
		}
		if ( ! empty( $ok['mailed'] ) ) {
			/* translators: %s: email address */
			$h .= '<p>' . esc_html( sprintf( __( 'T’hem enviat un correu amb el resum a %s.', 'padelcamps' ), $ok['email'] ) ) . '</p>';
		}
		// Nobody on the waiting list should pay before getting a place.
		if ( (float) $ok['amount'] > 0 && 'waitlist' !== $ok['status'] ) {
			$h .= '<h3>' . esc_html__( 'Pagament', 'padelcamps' ) . '</h3>';
			/* translators: %s: amount with currency */
			$h .= '<p>' . esc_html( sprintf( __( 'Import de la inscripció: %s', 'padelcamps' ), number_format_i18n( (float) $ok['amount'], 2 ) . ' €' ) ) . '</p>';
			if ( '' !== $config['payment_instructions'] ) {
				$h .= wpautop( esc_html( $config['payment_instructions'] ) );
			}
		}
		if ( '' !== $config['success_message'] ) {
			$h .= wpautop( esc_html( $config['success_message'] ) );
		}
		return $h . '</div>';
	}

	private function render_form( array $t, array $config, array $categories, array $values, array $errors, array $form_errors ): string {
		$tid    = (int) $t['id'];
		$f      = $config['fields'];
		$counts = $this->registrations->occupied_counts( wp_list_pluck( $categories, 'id' ) );

		$val = static fn( string $name ): string => isset( $values[ $name ] ) && is_string( $values[ $name ] ) ? $values[ $name ] : '';
		$id  = static fn( string $name ): string => 'pc-' . $tid . '-' . $name;

		$field_error = function ( string $name ) use ( $errors, $id ): string {
			if ( empty( $errors[ $name ] ) ) {
				return '';
			}
			return '<p class="pc-error" id="' . esc_attr( $id( $name ) ) . '-err">' . esc_html( self::error_message( (string) $errors[ $name ] ) ) . '</p>';
		};
		$aria = function ( string $name ) use ( $errors, $id ): string {
			return empty( $errors[ $name ] ) ? '' : ' aria-invalid="true" aria-describedby="' . esc_attr( $id( $name ) ) . '-err"';
		};
		$label = function ( string $name, string $text, bool $required ) use ( $id ): string {
			return '<label for="' . esc_attr( $id( $name ) ) . '">' . esc_html( $text ) . ( $required ? ' <span class="pc-req" aria-hidden="true">*</span>' : '' ) . '</label>';
		};
		$input = function ( string $name, string $text, string $type, string $state, array $attrs = array() ) use ( $val, $id, $label, $field_error, $aria ): string {
			if ( 'off' === $state ) {
				return '';
			}
			$required = 'required' === $state;
			$extra    = '';
			foreach ( $attrs as $k => $v ) {
				$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
			}
			return '<div class="pc-field">' . $label( $name, $text, $required )
				. '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id( $name ) ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val( $name ) ) . '"' . ( $required ? ' required' : '' ) . $extra . $aria( $name ) . '>'
				. $field_error( $name ) . '</div>';
		};
		$select = function ( string $name, string $text, string $state, array $options, string $placeholder ) use ( $val, $id, $label, $field_error, $aria ): string {
			if ( 'off' === $state ) {
				return '';
			}
			$required = 'required' === $state;
			$h        = '<div class="pc-field">' . $label( $name, $text, $required );
			$h       .= '<select id="' . esc_attr( $id( $name ) ) . '" name="' . esc_attr( $name ) . '"' . ( $required ? ' required' : '' ) . $aria( $name ) . '>';
			$h       .= '<option value="">' . esc_html( $placeholder ) . '</option>';
			foreach ( $options as $value => $text_option ) {
				$h .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( $val( $name ), (string) $value, false ) . '>' . esc_html( $text_option ) . '</option>';
			}
			return $h . '</select>' . $field_error( $name ) . '</div>';
		};

		$h = '';
		if ( '' !== $config['intro'] ) {
			$h .= '<div class="pc-intro">' . wpautop( esc_html( $config['intro'] ) ) . '</div>';
		}

		$h .= '<form class="pc-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$h .= '<input type="hidden" name="action" value="padelcamps_register">';
		$h .= '<input type="hidden" name="tournament_id" value="' . esc_attr( (string) $tid ) . '">';
		$h .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'padelcamps_register_' . $tid ) ) . '">';
		$h .= '<div class="pc-hp" aria-hidden="true"><label>' . esc_html__( 'Deixa aquest camp buit', 'padelcamps' ) . '<input type="text" name="pc_website" tabindex="-1" autocomplete="off" value=""></label></div>';

		if ( $form_errors ) {
			$h .= '<div class="pc-alert" role="alert"><ul>';
			foreach ( $form_errors as $code ) {
				$h .= '<li>' . esc_html( self::form_error_message( (string) $code ) ) . '</li>';
			}
			$h .= '</ul></div>';
		}
		$h .= '<p class="pc-legend-note">' . esc_html__( '* Camps obligatoris', 'padelcamps' ) . '</p>';

		// Category (with automatic waiting list label).
		$options = array();
		foreach ( $categories as $cat ) {
			$text = $cat['name'];
			if ( (float) $cat['price'] > 0 ) {
				$text .= ' — ' . number_format_i18n( (float) $cat['price'], 2 ) . ' €';
			}
			$full = 'waitlist' === RegistrationForm::initial_status( null === $cat['max_teams'] ? null : (int) $cat['max_teams'], $counts[ (int) $cat['id'] ] ?? 0 );
			if ( $full ) {
				$text .= ' (' . __( 'llista d’espera', 'padelcamps' ) . ')';
			}
			$options[ (int) $cat['id'] ] = $text;
		}
		$h .= '<fieldset><legend>' . esc_html__( 'Categoria', 'padelcamps' ) . '</legend>';
		$h .= $select( 'category_id', __( 'Tria la teva categoria', 'padelcamps' ), 'required', $options, __( 'Selecciona categoria', 'padelcamps' ) );
		$h .= '</fieldset>';

		// Players.
		foreach ( array( 1, 2 ) as $n ) {
			$p  = "p{$n}_";
			$h .= '<fieldset><legend>' . esc_html( sprintf( __( 'Jugador/a %d', 'padelcamps' ), $n ) ) . '</legend>';
			$h .= $input( $p . 'name', __( 'Nom i cognoms', 'padelcamps' ), 'text', 'required', array( 'autocomplete' => 1 === $n ? 'name' : 'off', 'maxlength' => '120' ) );
			$h .= $input( $p . 'email', __( 'Correu electrònic', 'padelcamps' ), 'email', 1 === $n ? 'required' : $f['email2'], array( 'autocomplete' => 1 === $n ? 'email' : 'off', 'maxlength' => '190' ) );
			$h .= $input( $p . 'phone', __( 'Telèfon', 'padelcamps' ), 'tel', $f[ 'phone' . $n ], array( 'autocomplete' => 1 === $n ? 'tel' : 'off', 'maxlength' => '20' ) );
			$h .= $input( $p . 'dni', __( 'DNI o NIE', 'padelcamps' ), 'text', $f['dni'], array( 'maxlength' => '14' ) );
			$h .= $input( $p . 'club', __( 'Club', 'padelcamps' ), 'text', $f['club'], array( 'maxlength' => '120' ) );
			$h .= '</fieldset>';
		}

		// Welcome pack: free text written by the organizer + the choices each player must make.
		$pack = $config['welcome_pack'];
		if ( ! empty( $pack['enabled'] ) && ( '' !== $pack['description'] || $pack['items'] ) ) {
			$h .= '<fieldset class="pc-pack"><legend>' . esc_html( '' !== $pack['title'] ? $pack['title'] : __( 'Welcome pack', 'padelcamps' ) ) . '</legend>';
			if ( '' !== $pack['description'] ) {
				$h .= '<div class="pc-pack-description">' . wpautop( esc_html( $pack['description'] ) ) . '</div>';
			}
			if ( $pack['items'] ) {
				$h .= '<div class="pc-pack-players">';
				foreach ( array( 1, 2 ) as $n ) {
					$h .= '<div class="pc-pack-player"><h4>' . esc_html( sprintf( __( 'Jugador/a %d', 'padelcamps' ), $n ) ) . '</h4>';
					foreach ( $pack['items'] as $i => $item ) {
						$name  = "p{$n}_pack_{$i}";
						$state = $item['required'] ? 'required' : 'optional';
						if ( 'select' === $item['type'] ) {
							$h .= $select( $name, $item['label'], $state, array_combine( $item['options'], $item['options'] ), __( 'Selecciona', 'padelcamps' ) );
						} else {
							$h .= $input( $name, $item['label'], 'text', $state, array( 'maxlength' => '120' ) );
						}
					}
					$h .= '</div>';
				}
				$h .= '</div>';
			}
			$h .= '</fieldset>';
		}

		// Availability.
		if ( ! empty( $config['availability']['enabled'] ) && $config['availability']['slots'] ) {
			$h .= '<fieldset><legend>' . esc_html__( 'Disponibilitat', 'padelcamps' ) . '</legend>';
			if ( '' !== $config['availability']['help'] ) {
				$h .= wpautop( esc_html( $config['availability']['help'] ) );
			}
			$h .= '<div class="pc-availability">';
			foreach ( $config['availability']['slots'] as $i => $slot ) {
				$name = 'av_' . $i;
				$opts = array();
				foreach ( RegistrationForm::slot_options( $slot ) as $value ) {
					$opts[ $value ] = self::availability_label( $value );
				}
				$current = isset( $values['av'][ $i ] ) && is_string( $values['av'][ $i ] ) ? $values['av'][ $i ] : '';
				$h      .= '<div class="pc-field"><label for="' . esc_attr( $id( $name ) ) . '">' . esc_html( self::slot_title( $slot ) ) . ' <span class="pc-req" aria-hidden="true">*</span></label>';
				$h      .= '<select id="' . esc_attr( $id( $name ) ) . '" name="av[' . (int) $i . ']" required' . $aria( $name ) . '>';
				$h      .= '<option value="">' . esc_html__( 'Selecciona', 'padelcamps' ) . '</option>';
				foreach ( $opts as $value => $text ) {
					$h .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
				}
				$h .= '</select>' . $field_error( $name ) . '</div>';
			}
			$h .= '</div></fieldset>';
		}

		// Notes.
		if ( 'off' !== $f['notes'] ) {
			$required = 'required' === $f['notes'];
			$h       .= '<div class="pc-field">' . $label( 'notes', __( 'Observacions', 'padelcamps' ), $required );
			$h       .= '<textarea id="' . esc_attr( $id( 'notes' ) ) . '" name="notes" rows="4" maxlength="1000"' . ( $required ? ' required' : '' ) . $aria( 'notes' ) . '>' . esc_textarea( $val( 'notes' ) ) . '</textarea>' . $field_error( 'notes' ) . '</div>';
		}

		// Consents.
		$privacy = '' !== $config['privacy_url'] ? $config['privacy_url'] : (string) get_privacy_policy_url();
		$h      .= '<div class="pc-consents">';
		foreach ( RegistrationForm::CONSENTS as $key ) {
			$c = $config['consents'][ $key ];
			if ( empty( $c['enabled'] ) ) {
				continue;
			}
			$text    = '' !== $c['text'] ? $c['text'] : self::default_consent_text( $key );
			$name    = 'consent_' . $key;
			$checked = ! empty( $values['consent'][ $key ] );
			$h      .= '<div class="pc-check"><label><input type="checkbox" name="consent[' . esc_attr( $key ) . ']" value="1"' . ( $checked ? ' checked' : '' ) . ( ! empty( $c['required'] ) ? ' required' : '' ) . $aria( $name ) . '> <span>' . esc_html( $text ) . ( ! empty( $c['required'] ) ? ' <span class="pc-req" aria-hidden="true">*</span>' : '' ) . '</span></label>' . $field_error( $name ) . '</div>';
		}
		if ( '' !== $privacy ) {
			$h .= '<p class="pc-privacy"><a href="' . esc_url( $privacy ) . '" target="_blank" rel="noopener">' . esc_html__( 'Política de privacitat', 'padelcamps' ) . '</a></p>';
		}
		$h .= '</div>';

		$h .= '<p><button type="submit" class="pc-submit">' . esc_html__( 'Envia la inscripció', 'padelcamps' ) . '</button></p>';
		return $h . '</form>';
	}

	// --- Submission ------------------------------------------------------------

	private static function post( string $key ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle().
	}

	private function return_url( int $tid ): string {
		$back = wp_get_referer();
		$back = $back ? $back : home_url( '/' );
		return remove_query_arg( array( 'pc_e', 'pc_ok' ), $back );
	}

	private function go( string $url, string $param, array $state, int $tid ): void {
		$token = self::save_state( $state );
		wp_safe_redirect( add_query_arg( $param, $token, $url ) . '#padelcamps-' . $tid );
		exit;
	}

	/** Values worth keeping to prefill the form after an error. */
	private function prefill( array $post ): array {
		$keep = array( 'category_id', 'notes' );
		foreach ( array( 1, 2 ) as $n ) {
			foreach ( array( 'name', 'email', 'phone', 'club', 'dni' ) as $field ) {
				$keep[] = "p{$n}_{$field}";
			}
		}
		$out = array();
		foreach ( $post as $key => $value ) {
			$is_pack = is_string( $key ) && 1 === preg_match( '/^p[12]_pack_\d{1,2}$/', $key );
			if ( ( $is_pack || in_array( $key, $keep, true ) ) && is_string( $value ) ) {
				$out[ $key ] = 'notes' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
		}
		$out['av'] = array();
		foreach ( is_array( $post['av'] ?? null ) ? $post['av'] : array() as $i => $v ) {
			if ( is_string( $v ) ) {
				$out['av'][ (int) $i ] = sanitize_text_field( $v );
			}
		}
		$out['consent'] = array();
		foreach ( is_array( $post['consent'] ?? null ) ? $post['consent'] : array() as $k => $v ) {
			$out['consent'][ sanitize_key( (string) $k ) ] = ! empty( $v );
		}
		return $out;
	}

	private function rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'padelcamps_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_LIMIT ) {
			return true;
		}
		set_transient( $key, $n + 1, self::RATE_WINDOW );
		return false;
	}

	public function handle(): void {
		$tid  = absint( self::post( 'tournament_id' ) );
		$back = $this->return_url( $tid );
		$post = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.

		$fail = function ( array $form_errors, array $errors = array() ) use ( $back, $tid, $post ): void {
			$this->go(
				$back,
				'pc_e',
				array(
					'tournament_id' => $tid,
					'values'        => $this->prefill( $post ),
					'errors'        => $errors,
					'form_errors'   => $form_errors,
				),
				$tid
			);
		};

		if ( ! wp_verify_nonce( (string) self::post( '_wpnonce' ), 'padelcamps_register_' . $tid ) ) {
			$fail( array( 'nonce' ) );
		}

		// Honeypot: bots fill it in. Answer as if it worked, store nothing.
		if ( '' !== trim( (string) self::post( 'pc_website' ) ) ) {
			$this->go(
				$back,
				'pc_ok',
				array(
					'tournament_id' => $tid,
					'status'        => 'pending',
					'category'      => '',
					'amount'        => 0,
					'email'         => '',
					'mailed'        => false,
				),
				$tid
			);
		}

		if ( $this->rate_limited() ) {
			$fail( array( 'rate' ) );
		}

		$tournament = $tid ? $this->tournaments->find( $tid ) : null;
		if ( ! $tournament || ! TournamentStatus::is_public( $tournament['status'] ) ) {
			$fail( array( 'closed' ) );
		}
		$config = $this->tournaments->form_config( $tournament );
		if ( 'open' !== $this->open_state( $tournament, $config )['state'] ) {
			$fail( array( 'closed' ) );
		}

		$categories = $this->tournaments->categories( $tid );
		$result     = RegistrationForm::validate( $config, array_map( 'intval', wp_list_pluck( $categories, 'id' ) ), $post );
		if ( $result['errors'] ) {
			$fail( array( 'fields' ), $result['errors'] );
		}
		$clean = $result['clean'];

		foreach ( $clean['players'] as $player ) {
			if ( $this->registrations->has_active_registration( $clean['category_id'], $player['email'] ) ) {
				$fail( array( 'duplicate' ) );
			}
		}

		$created = $this->registrations->create(
			array(
				'category_id'  => $clean['category_id'],
				'players'      => $clean['players'],
				'notes'        => $clean['notes'],
				'availability' => $clean['availability'],
				'consents'     => $clean['consents'],
				'locale'       => determine_locale(),
			)
		);
		if ( ! $created ) {
			$fail( array( 'server' ) );
		}

		$category = null;
		foreach ( $categories as $cat ) {
			if ( (int) $cat['id'] === $clean['category_id'] ) {
				$category = $cat;
			}
		}

		$mailer = new RegistrationMailer();
		$mailed = $mailer->send_receipt( $tournament, $category, $created, $clean, $config );
		$mailer->notify_organizer( $tournament, $category, $created, $clean, $config );

		$this->go(
			$back,
			'pc_ok',
			array(
				'tournament_id' => $tid,
				'status'        => $created['status'],
				'category'      => $category['name'],
				'amount'        => $created['amount'],
				'email'         => $clean['players'][0]['email'],
				'mailed'        => $mailed,
			),
			$tid
		);
	}
}
