<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\ManualRegistration;
use PadelCamps\Engine\RegistrationStatus;
use PadelCamps\Frontend\RegistrationShortcode;
use PadelCamps\I18n\Catalog;
use PadelCamps\I18n\Translator;
use PadelCamps\Service\Communication;

defined( 'ABSPATH' ) || exit;

/**
 * Registrations entered by the organizer (phone, WhatsApp, at the club) and
 * edits to the player data of an existing registration. Shown inside the
 * Inscripcions views (RegistrationsView).
 */
final class RegistrationEditor {

	private const STATE_TTL = 600;

	private RegistrationRepository $regs;
	private TournamentRepository $tournaments;

	public function __construct( RegistrationRepository $regs, TournamentRepository $tournaments ) {
		$this->regs        = $regs;
		$this->tournaments = $tournaments;
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_reg_add', array( $this, 'handle_add' ) );
		add_action( 'admin_post_padelcamps_reg_players', array( $this, 'handle_players' ) );
	}

	// --- Form state (errors + values kept across the redirect) -------------------------

	private static function state_key(): string {
		return 'padelcamps_regedit_' . get_current_user_id();
	}

	private static function take_state(): array {
		$state = get_transient( self::state_key() );
		delete_transient( self::state_key() );
		return is_array( $state ) ? $state + array( 'errors' => array(), 'input' => array(), 'form' => '' ) : array( 'errors' => array(), 'input' => array(), 'form' => '' );
	}

	private static function fail( string $redirect, array $errors, array $input, string $form = '' ): void {
		set_transient( self::state_key(), array( 'errors' => $errors, 'input' => $input, 'form' => $form ), self::STATE_TTL );
		wp_safe_redirect( $redirect );
		exit;
	}

	private static function error_text( string $code ): string {
		return 'duplicate' === $code
			? __( 'Aquest jugador ja té una inscripció en aquesta categoria.', 'padelcamps' )
			: RegistrationShortcode::error_message( $code );
	}

	// --- Rendering -----------------------------------------------------------------

	/** Categories the organizer can choose: id => label. */
	private function category_options( int $tid ): array {
		$tournaments = $tid > 0 ? array_filter( array( $this->tournaments->find( $tid ) ) ) : $this->tournaments->all();
		$out         = array();
		foreach ( $tournaments as $t ) {
			foreach ( $this->tournaments->categories( (int) $t['id'] ) as $c ) {
				$label = $tid > 0 ? $c['name'] : $t['name'] . ' — ' . $c['name'];
				if ( (float) $c['price'] > 0 ) {
					$label .= ' (' . number_format_i18n( (float) $c['price'], 2 ) . ' €)';
				}
				$out[ (int) $c['id'] ] = $label;
			}
		}
		return $out;
	}

	private static function field_error( array $errors, string $key ): string {
		return isset( $errors[ $key ] ) ? '<p class="description pc-error">' . esc_html( self::error_text( $errors[ $key ] ) ) . '</p>' : '';
	}

	/** Two-column table with the inputs of both players. */
	private static function players_table( array $values, array $errors ): void {
		$fields = array(
			'name'  => array( __( 'Nom i cognoms', 'padelcamps' ), 'text', 120 ),
			'email' => array( __( 'Correu electrònic', 'padelcamps' ), 'email', 190 ),
			'phone' => array( __( 'Telèfon', 'padelcamps' ), 'tel', 20 ),
			'club'  => array( __( 'Club', 'padelcamps' ), 'text', 120 ),
		);
		echo '<table class="widefat pc-players"><thead><tr><th></th>';
		foreach ( array( 1, 2 ) as $n ) {
			echo '<th>' . esc_html( sprintf( __( 'Jugador/a %d', 'padelcamps' ), $n ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $fields as $key => $def ) {
			echo '<tr><th scope="row">' . esc_html( $def[0] ) . ( 'name' === $key ? ' *' : '' ) . '</th>';
			foreach ( array( 1, 2 ) as $n ) {
				$name = "p{$n}_{$key}";
				printf(
					'<td><input type="%s" name="%s" value="%s" maxlength="%d" aria-label="%s"%s>%s</td>',
					esc_attr( $def[1] ),
					esc_attr( $name ),
					esc_attr( (string) ( $values[ $name ] ?? '' ) ),
					(int) $def[2],
					esc_attr( sprintf( __( 'Jugador/a %d', 'padelcamps' ), $n ) . ' · ' . $def[0] ),
					'name' === $key ? ' required' : '',
					self::field_error( $errors, $name ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in field_error().
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** "Add a registration" screen. */
	public function render_new( int $tid ): void {
		$state   = self::take_state();
		$errors  = $state['errors'];
		$v       = $state['input'];
		$options = $this->category_options( $tid );
		$langs   = Translator::languages();
		$site    = Catalog::lang_of( get_locale() );

		echo '<p><a href="' . esc_url( RegistrationsView::url( $tid ) ) . '">← ' . esc_html__( 'Totes les inscripcions', 'padelcamps' ) . '</a></p>';
		echo '<h2 class="pc-h2-first">' . esc_html__( 'Afegeix una inscripció', 'padelcamps' ) . '</h2>';
		echo '<p class="pc-lead">' . esc_html__( 'Per a parelles que s’inscriuen per telèfon, WhatsApp o al club. Només calen els noms; el correu serveix per enviar-los els avisos i els horaris.', 'padelcamps' ) . '</p>';
		if ( isset( $errors['_form'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::error_text( $errors['_form'] ) ) . '</p></div>';
		} elseif ( $errors ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Revisa els camps marcats.', 'padelcamps' ) . '</p></div>';
		}
		if ( ! $options ) {
			echo '<p>' . esc_html__( 'Primer cal crear alguna categoria a la pestanya Configuració.', 'padelcamps' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_reg_add"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_reg_add' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="pc-n-cat">' . esc_html__( 'Categoria', 'padelcamps' ) . ' *</label></th><td><select id="pc-n-cat" name="category_id" required>';
		if ( count( $options ) > 1 ) {
			echo '<option value="">' . esc_html__( 'Selecciona categoria', 'padelcamps' ) . '</option>';
		}
		foreach ( $options as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( (int) ( $v['category_id'] ?? 0 ), (int) $id, false ), esc_html( $label ) );
		}
		echo '</select>' . self::field_error( $errors, 'category_id' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<tr><th scope="row">' . esc_html__( 'Jugadors', 'padelcamps' ) . '</th><td>';
		self::players_table( $v, $errors );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pc-n-status">' . esc_html__( 'Estat', 'padelcamps' ) . '</label></th><td><select id="pc-n-status" name="status">';
		foreach ( ManualRegistration::STATUSES as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $v['status'] ?? 'confirmed', $s, false ), esc_html( RegistrationsView::status_label( $s ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Si la categoria ja és plena, t’avisarem, però la inscripció es desa igualment.', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pc-n-pay">' . esc_html__( 'Pagament', 'padelcamps' ) . '</label></th><td><select id="pc-n-pay" name="payment">';
		foreach ( ManualRegistration::PAYMENTS as $p ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $p ), selected( $v['payment'] ?? 'pending', $p, false ), esc_html( RegistrationsView::payment_label( $p ) ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Només compta si la categoria té preu.', 'padelcamps' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="pc-n-lang">' . esc_html__( 'Idioma dels correus', 'padelcamps' ) . '</label></th><td><select id="pc-n-lang" name="lang">';
		foreach ( $langs as $code => $native ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $v['lang'] ?? $site, $code, false ), esc_html( $native ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="pc-n-rating">' . esc_html__( 'Puntuació', 'padelcamps' ) . '</label></th><td><input type="text" inputmode="decimal" id="pc-n-rating" name="rating" class="small-text" value="' . esc_attr( (string) ( $v['rating'] ?? '' ) ) . '">' . self::field_error( $errors, 'rating' ) . '<p class="description">' . esc_html__( 'Nivell de la parella. S’usarà per ordenar els caps de sèrie al sorteig.', 'padelcamps' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<tr><th scope="row"><label for="pc-n-notes">' . esc_html__( 'Notes de l’organització', 'padelcamps' ) . '</label></th><td><textarea id="pc-n-notes" name="notes" rows="3" class="large-text">' . esc_textarea( (string) ( $v['notes'] ?? '' ) ) . '</textarea><p class="description">' . esc_html__( 'Només les veu l’organització.', 'padelcamps' ) . '</p></td></tr>';
		echo '</table>';

		echo '<p class="pc-submit"><button class="button button-primary">' . esc_html__( 'Afegeix la inscripció', 'padelcamps' ) . '</button> ';
		echo '<label class="pc-notify"><input type="checkbox" name="notify" value="1"' . checked( ! $v || ! empty( $v['notify'] ), true, false ) . '> ' . esc_html__( 'Avisa els jugadors per correu', 'padelcamps' ) . '</label>';
		echo ' <span class="description">' . esc_html__( '(si està confirmada o a la llista d’espera i tenen correu)', 'padelcamps' ) . '</span></p></form>';
	}

	/** Edit the players of a registration (inside the detail screen). */
	public function render_players_form( array $r, int $tid ): void {
		$state  = self::take_state();
		$errors = 'players' === $state['form'] ? $state['errors'] : array();
		$values = 'players' === $state['form'] ? $state['input'] : array();
		if ( ! $values ) {
			foreach ( $r['players'] as $i => $p ) {
				$n = $i + 1;
				foreach ( array( 'name', 'email', 'phone', 'club' ) as $k ) {
					$values[ "p{$n}_{$k}" ] = (string) ( $p[ $k ] ?? '' );
				}
			}
		}
		$shared = $this->regs->shared_player_registrations( (int) $r['id'] );

		echo '<details class="pc-details"' . ( $errors ? ' open' : '' ) . '><summary class="button">' . esc_html__( 'Edita les dades dels jugadors', 'padelcamps' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_reg_players"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '"><input type="hidden" name="reg" value="' . (int) $r['id'] . '">';
		wp_nonce_field( 'padelcamps_reg_players' );
		if ( $errors ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Revisa els camps marcats.', 'padelcamps' ) . '</p></div>';
		}
		if ( $shared > 0 ) {
			/* translators: %d: number of other registrations */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( 'Algun d’aquests jugadors també és en %d inscripció més: els canvis s’hi veuran també.', 'Algun d’aquests jugadors també és en %d inscripcions més: els canvis s’hi veuran també.', $shared, 'padelcamps' ), $shared ) ) . '</p></div>';
		}
		self::players_table( $values, $errors );
		echo '<p><button class="button button-primary">' . esc_html__( 'Desa els jugadors', 'padelcamps' ) . '</button></p></form></details>';
	}

	// --- Handlers ----------------------------------------------------------------------

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( $action );
	}

	/** Text fields of the request (unslashed). */
	private static function input(): array {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		foreach ( $_POST as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) ) {
				$out[ $key ] = wp_unslash( $value );
			}
		}
		return $out;
	}

	public function handle_add(): void {
		self::guard( 'padelcamps_reg_add' );
		$input = self::input();
		$tid   = absint( $input['tid'] ?? 0 );
		$back  = RegistrationsView::url( $tid, array( 'reg' => 'new' ) );

		$result = ManualRegistration::validate( $input, array_keys( $this->category_options( $tid ) ), array_keys( Translator::languages() ) );
		$keep   = array_intersect_key( $input, array_flip( array( 'category_id', 'status', 'payment', 'lang', 'rating', 'notes', 'notify', 'p1_name', 'p1_email', 'p1_phone', 'p1_club', 'p2_name', 'p2_email', 'p2_phone', 'p2_club' ) ) );
		if ( $result['errors'] ) {
			self::fail( $back, $result['errors'], $keep );
		}
		$clean = $result['clean'];
		foreach ( $clean['players'] as $p ) {
			if ( $this->regs->has_active_registration( $clean['category_id'], $p['email'] ) ) {
				self::fail( $back, array( '_form' => 'duplicate' ), $keep );
			}
		}

		$created = $this->regs->create(
			array(
				'category_id'    => $clean['category_id'],
				'players'        => $clean['players'],
				'notes'          => '',
				'availability'   => array(),
				'consents'       => array(),
				'locale'         => $clean['lang'],
				'status'         => $clean['status'],
				'payment_status' => $clean['payment'],
				'rating'         => $clean['rating'],
				'org_notes'      => $clean['notes'],
				'source'         => 'admin',
			)
		);
		if ( ! $created ) {
			self::fail( $back, array( '_form' => 'server' ), $keep );
		}
		$id   = (int) $created['id'];
		$args = array( 'reg' => $id, 'rmsg' => 'added' );

		if ( ! empty( $input['notify'] ) && in_array( $clean['status'], array( RegistrationStatus::CONFIRMED, RegistrationStatus::WAITLIST ), true ) ) {
			$sent = ( new Communication() )->after_status_change( array( $id => array( 'status' => '', 'payment_status' => '' ) ), $clean['status'] );
			if ( $sent ) {
				$args['rm'] = $sent;
			}
		}
		if ( RegistrationStatus::WAITLIST !== $clean['status'] ) {
			$over = $this->regs->over_capacity_categories( array( $id ) );
			if ( $over ) {
				$args['rc'] = implode( ', ', $over );
			}
		}
		wp_safe_redirect( RegistrationsView::url( $tid, $args ) );
		exit;
	}

	public function handle_players(): void {
		self::guard( 'padelcamps_reg_players' );
		$input = self::input();
		$tid   = absint( $input['tid'] ?? 0 );
		$id    = absint( $input['reg'] ?? 0 );
		if ( ! $this->regs->filter_ids( array( $id ), $tid ) ) {
			wp_safe_redirect( RegistrationsView::url( $tid, array( 'rmsg' => 'not_found' ) ) );
			exit;
		}
		$back   = RegistrationsView::url( $tid, array( 'reg' => $id ) );
		$result = ManualRegistration::players( $input );
		if ( $result['errors'] ) {
			self::fail( $back, $result['errors'], array_intersect_key( $input, array_flip( array( 'p1_name', 'p1_email', 'p1_phone', 'p1_club', 'p2_name', 'p2_email', 'p2_phone', 'p2_club' ) ) ), 'players' );
		}
		$this->regs->update_players( $id, $result['players'] );
		wp_safe_redirect( RegistrationsView::url( $tid, array( 'reg' => $id, 'rmsg' => 'players_saved' ) ) );
		exit;
	}
}
