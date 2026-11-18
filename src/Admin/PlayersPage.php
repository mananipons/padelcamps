<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\PlayerRepository;
use PadelCamps\Db\RankingRepository;
use PadelCamps\Db\SeasonRepository;
use PadelCamps\Engine\Dni;
use PadelCamps\Engine\PlayerEdit;
use PadelCamps\Frontend\RegistrationShortcode;

defined( 'ABSPATH' ) || exit;

/**
 * Players screen. A player is a person identified by their DNI/NIE across all tournaments.
 * Tabs: Jugadors (search, profile with registrations and points, edit, merge, erase) and
 * Rànquing (season ranking per category). Manual points adjustments live in the profile.
 */
final class PlayersPage {

	public const SLUG     = 'padelcamps-players';
	private const PER_PAGE = 25;
	private const STATE_TTL = 600;

	private PlayerRepository $players;
	private SeasonRepository $seasons;
	private RankingRepository $ranking;

	public function __construct( PlayerRepository $players, SeasonRepository $seasons, RankingRepository $ranking ) {
		$this->players = $players;
		$this->seasons = $seasons;
		$this->ranking = $ranking;
	}

	public function register(): void {
		foreach ( array( 'save_player', 'merge_players', 'delete_player', 'add_adjustment', 'delete_adjustment' ) as $action ) {
			add_action( 'admin_post_padelcamps_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	// --- Labels (literal calls so the translation scanner finds them) -------

	/** @return array{0:string,1:string} [notice type, text] */
	private static function message( string $code ): array {
		$messages = array(
			'saved'         => array( 'success', __( 'Jugador desat.', 'padelcamps' ) ),
			'merged'        => array( 'success', __( 'Jugadors fusionats. Les inscripcions i els punts de l’altre jugador ara són d’aquest.', 'padelcamps' ) ),
			'deleted'       => array( 'success', __( 'Jugador eliminat, amb els seus punts.', 'padelcamps' ) ),
			'adj_added'     => array( 'success', __( 'Ajust de punts afegit.', 'padelcamps' ) ),
			'adj_deleted'   => array( 'success', __( 'Ajust de punts eliminat.', 'padelcamps' ) ),
			'not_found'     => array( 'error', __( 'No s’ha trobat el jugador.', 'padelcamps' ) ),
			'err_fields'    => array( 'error', __( 'Revisa els camps marcats.', 'padelcamps' ) ),
			'err_same'      => array( 'error', __( 'Cal triar un altre jugador: no es pot fusionar amb ell mateix.', 'padelcamps' ) ),
			'err_dni'       => array( 'error', __( 'Aquests dos jugadors tenen un DNI o NIE diferent: són persones diferents i no es poden fusionar.', 'padelcamps' ) ),
			'err_same_reg'  => array( 'error', __( 'Aquests dos jugadors són a la mateixa inscripció: no es poden fusionar.', 'padelcamps' ) ),
			'err_in_use'    => array( 'error', __( 'Aquest jugador té inscripcions. Per esborrar-lo, elimina primer les seves inscripcions.', 'padelcamps' ) ),
			'err_adjust'    => array( 'error', __( 'L’ajust no és vàlid: cal una temporada i categoria de rànquing, uns punts diferents de zero i un motiu.', 'padelcamps' ) ),
			'err_adj_gone'  => array( 'error', __( 'No s’ha trobat aquest ajust.', 'padelcamps' ) ),
		);
		return $messages[ $code ] ?? array( 'info', '' );
	}

	private static function status_label( string $status ): string {
		return RegistrationsView::status_label( $status );
	}

	/** @return array<string,string> result key => label (the points scheme labels + manual adjustment) */
	private static function result_labels(): array {
		return SeasonsPage::points_labels() + array( RankingRepository::ADJUSTMENT => __( 'Ajust manual', 'padelcamps' ) );
	}

	// --- URLs ---------------------------------------------------------------

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private function redirect( array $args ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function fmt_date( ?string $date ): string {
		return $date ? mysql2date( get_option( 'date_format' ), $date . ' 00:00:00' ) : '—';
	}

	// --- Form state (errors + typed values survive the redirect) -------------

	private static function state_key(): string {
		return 'padelcamps_player_form_' . get_current_user_id();
	}

	private static function take_state(): array {
		$state = get_transient( self::state_key() );
		delete_transient( self::state_key() );
		return is_array( $state ) ? $state + array( 'errors' => array(), 'input' => array(), 'player' => 0 ) : array( 'errors' => array(), 'input' => array(), 'player' => 0 );
	}

	private function fail( int $player_id, array $errors, array $input ): void {
		set_transient( self::state_key(), array( 'errors' => $errors, 'input' => $input, 'player' => $player_id ), self::STATE_TTL );
		$this->redirect( array( 'action' => 'view', 'id' => $player_id, 'msg' => 'err_fields' ) );
	}

	// --- Rendering ----------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		// phpcs:enable

		if ( in_array( $action, array( 'view', 'merge' ), true ) ) {
			$player = $id ? $this->players->find( $id ) : null;
			if ( ! $player ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
			if ( 'merge' === $action ) {
				$this->render_merge( $player );
			} else {
				$this->render_profile( $player );
			}
			return;
		}

		echo '<div class="wrap pc-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Jugadors', 'padelcamps' ) . '</h1><hr class="wp-header-end">';
		$this->render_notice();
		echo '<nav class="nav-tab-wrapper pc-tabs">';
		foreach ( array( 'list' => __( 'Jugadors', 'padelcamps' ), 'ranking' => __( 'Rànquing', 'padelcamps' ) ) as $slug => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( 'list' === $slug ? array() : array( 'tab' => $slug ) ) ), $slug === $tab || ( 'list' === $slug && 'ranking' !== $tab ) ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav><div class="pc-panel">';
		if ( 'ranking' === $tab ) {
			$this->render_ranking();
		} else {
			$this->render_list();
		}
		echo '</div></div>';
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted codes.
		$code = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		list( $type, $text ) = self::message( $code );
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$no_dni = ! empty( $_GET['no_dni'] );
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$result = $this->players->search( array( 's' => $s, 'no_dni' => $no_dni ), $paged, self::PER_PAGE );

		echo '<form method="get" class="pc-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Cerca per nom, DNI, correu, telèfon o club', 'padelcamps' ) . '" class="regular-text"> ';
		echo '<label><input type="checkbox" name="no_dni" value="1"' . checked( $no_dni, true, false ) . '> ' . esc_html__( 'Només sense DNI', 'padelcamps' ) . '</label> ';
		echo '<button class="button">' . esc_html__( 'Filtra', 'padelcamps' ) . '</button></form>';
		/* translators: %d: number of players */
		echo '<p class="description">' . esc_html( sprintf( _n( '%d jugador', '%d jugadors', $result['total'], 'padelcamps' ), $result['total'] ) ) . '</p>';

		if ( ! $result['rows'] ) {
			echo '<p>' . esc_html__( 'No hi ha cap jugador amb aquests filtres.', 'padelcamps' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( __( 'Nom', 'padelcamps' ), __( 'DNI o NIE', 'padelcamps' ), __( 'Correu electrònic', 'padelcamps' ), __( 'Club', 'padelcamps' ), __( 'Inscripcions', 'padelcamps' ), __( 'Punts', 'padelcamps' ) ) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $result['rows'] as $row ) {
			$url = self::url( array( 'action' => 'view', 'id' => (int) $row['id'] ) );
			echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( $row['name'] ) . '</a></strong></td>';
			echo '<td>' . ( $row['dni'] ? esc_html( $row['dni'] ) : '<span class="pc-badge pc-badge--warn">' . esc_html__( 'Sense DNI', 'padelcamps' ) . '</span>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.
			echo '<td>' . esc_html( $row['email'] ?: '—' ) . '</td><td>' . esc_html( $row['club'] ?: '—' ) . '</td>';
			echo '<td>' . (int) $row['registrations'] . '</td><td>' . (int) $row['points'] . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
	}

	private function render_ranking(): void {
		$all = $this->seasons->all();
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'Encara no hi ha cap temporada. Crea’n una a Temporades per fer un rànquing.', 'padelcamps' ) . '</p>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$season_id = isset( $_GET['season'] ) ? absint( $_GET['season'] ) : 0;
		$rc_id     = isset( $_GET['rc'] ) ? absint( $_GET['rc'] ) : 0;
		// phpcs:enable
		$season = $season_id ? $this->seasons->find( $season_id ) : $this->seasons->current();
		$season = $season ?: $all[0];
		$cats   = $this->seasons->ranking_categories( (int) $season['id'] );
		$chosen = null;
		foreach ( $cats as $cat ) {
			if ( (int) $cat['id'] === $rc_id ) {
				$chosen = $cat;
			}
		}
		$chosen = $chosen ?: ( $cats[0] ?? null );

		echo '<form method="get" class="pc-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="ranking">';
		echo '<select name="season" aria-label="' . esc_attr__( 'Temporada', 'padelcamps' ) . '">';
		foreach ( $all as $row ) {
			printf( '<option value="%d"%s>%s</option>', (int) $row['id'], selected( (int) $season['id'], (int) $row['id'], false ), esc_html( $row['name'] ) );
		}
		echo '</select> <select name="rc" aria-label="' . esc_attr__( 'Categoria de rànquing', 'padelcamps' ) . '">';
		foreach ( $cats as $cat ) {
			printf( '<option value="%d"%s>%s</option>', (int) $cat['id'], selected( (int) ( $chosen['id'] ?? 0 ), (int) $cat['id'], false ), esc_html( $cat['name'] ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'Mostra', 'padelcamps' ) . '</button></form>';

		if ( ! $chosen ) {
			echo '<p>' . esc_html__( 'Aquesta temporada encara no té categories de rànquing.', 'padelcamps' ) . '</p>';
			return;
		}
		$rows = $this->ranking->totals( (int) $season['id'], (int) $chosen['id'] );
		echo '<p class="description">' . esc_html__( 'Només compten els punts publicats i els ajustos manuals. Els empats comparteixen posició.', 'padelcamps' ) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Encara no hi ha cap punt en aquest rànquing.', 'padelcamps' ) . '</p>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:60px">#</th><th>' . esc_html__( 'Jugador', 'padelcamps' ) . '</th><th>' . esc_html__( 'Club', 'padelcamps' ) . '</th><th>' . esc_html__( 'Tornejos', 'padelcamps' ) . '</th><th>' . esc_html__( 'Punts', 'padelcamps' ) . '</th></tr></thead><tbody>';
		$pos      = 0;
		$previous = null;
		foreach ( $rows as $i => $row ) {
			if ( $row['points'] !== $previous ) {
				$pos      = $i + 1;
				$previous = $row['points'];
			}
			echo '<tr><td>' . (int) $pos . '</td><td><strong><a href="' . esc_url( self::url( array( 'action' => 'view', 'id' => $row['player_id'] ) ) ) . '">' . esc_html( $row['name'] ) . '</a></strong></td>';
			echo '<td>' . esc_html( $row['club'] ?: '—' ) . '</td><td>' . (int) $row['tournaments'] . '</td><td><strong>' . (int) $row['points'] . '</strong></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function field_error( array $errors, string $key ): string {
		return isset( $errors[ $key ] ) ? '<p class="description pc-error">' . esc_html( RegistrationShortcode::error_message( $errors[ $key ] ) ) . '</p>' : '';
	}

	private function render_profile( array $player ): void {
		$pid   = (int) $player['id'];
		$state = self::take_state();
		$errs  = (int) $state['player'] === $pid ? $state['errors'] : array();
		$vals  = (int) $state['player'] === $pid && $state['input'] ? $state['input'] : array(
			'name'  => $player['name'],
			'dni'   => (string) $player['dni'],
			'email' => $player['email'],
			'phone' => (string) $player['phone'],
			'club'  => (string) $player['club'],
		);

		echo '<div class="wrap pc-wrap"><h1 class="pc-title">' . esc_html( $player['name'] ) . '</h1>';
		echo '<p class="pc-back"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Tots els jugadors', 'padelcamps' ) . '</a></p>';
		$this->render_notice();
		echo '<div class="pc-panel pc-panel--solo">';

		// Data.
		echo '<h2>' . esc_html__( 'Dades', 'padelcamps' ) . '</h2>';
		if ( null === $player['dni'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Aquest jugador no té DNI o NIE (és anterior a l’obligatorietat). Afegeix-lo perquè sigui identificable; si és la mateixa persona que un altre jugador, fusiona’ls.', 'padelcamps' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="padelcamps_save_player"><input type="hidden" name="id" value="' . $pid . '">';
		wp_nonce_field( 'padelcamps_save_player' );
		echo '<table class="form-table" role="presentation">';
		$rows = array(
			'name'  => array( __( 'Nom i cognoms', 'padelcamps' ), 'text', 120, true ),
			'dni'   => array( __( 'DNI o NIE', 'padelcamps' ), 'text', 14, null !== $player['dni'] ),
			'email' => array( __( 'Correu electrònic', 'padelcamps' ), 'email', 190, false ),
			'phone' => array( __( 'Telèfon', 'padelcamps' ), 'tel', 20, false ),
			'club'  => array( __( 'Club', 'padelcamps' ), 'text', 190, false ),
		);
		foreach ( $rows as $key => $def ) {
			printf(
				'<tr><th scope="row"><label for="pc-pl-%1$s">%2$s</label></th><td><input type="%3$s" id="pc-pl-%1$s" name="%1$s" value="%4$s" maxlength="%5$d" class="regular-text"%6$s>%7$s</td></tr>',
				esc_attr( $key ),
				esc_html( $def[0] ) . ( $def[3] ? ' *' : '' ),
				esc_attr( $def[1] ),
				esc_attr( (string) ( $vals[ $key ] ?? '' ) ),
				(int) $def[2],
				$def[3] ? ' required' : '',
				$this->field_error( $errs, $key ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in field_error().
			);
		}
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Desa el jugador', 'padelcamps' ) . '</button></p></form>';

		// Registrations.
		echo '<hr><h2>' . esc_html__( 'Inscripcions', 'padelcamps' ) . '</h2>';
		$regs = $this->players->registrations( $pid );
		if ( ! $regs ) {
			echo '<p class="description">' . esc_html__( 'Aquest jugador no és a cap inscripció.', 'padelcamps' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Torneig', 'padelcamps' ) . '</th><th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th><th>' . esc_html__( 'Data', 'padelcamps' ) . '</th><th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th></tr></thead><tbody>';
			foreach ( $regs as $r ) {
				echo '<tr><td><a href="' . esc_url( TournamentsPage::url( array( 'action' => 'edit', 'id' => (int) $r['tournament_id'] ) ) ) . '">' . esc_html( $r['tournament_name'] ) . '</a></td>';
				echo '<td>' . esc_html( $r['category_name'] ) . '</td><td>' . esc_html( self::fmt_date( $r['starts_on'] ) ) . '</td><td>' . esc_html( self::status_label( $r['status'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Points.
		echo '<hr><h2>' . esc_html__( 'Punts del rànquing', 'padelcamps' ) . '</h2>';
		$points = $this->players->points( $pid );
		$labels = self::result_labels();
		if ( ! $points ) {
			echo '<p class="description">' . esc_html__( 'Encara no té punts.', 'padelcamps' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Temporada', 'padelcamps' ) . '</th><th>' . esc_html__( 'Categoria de rànquing', 'padelcamps' ) . '</th><th>' . esc_html__( 'Origen', 'padelcamps' ) . '</th><th>' . esc_html__( 'Punts', 'padelcamps' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $points as $row ) {
				$manual = RankingRepository::ADJUSTMENT === $row['result'];
				$origin = $manual ? $labels[ $row['result'] ] . ( $row['note'] ? ': ' . $row['note'] : '' ) : trim( (string) $row['tournament_name'] . ' · ' . (string) $row['category_name'] . ' · ' . ( $labels[ $row['result'] ] ?? $row['result'] ), ' ·' );
				echo '<tr><td>' . esc_html( (string) $row['season_name'] ) . '</td><td>' . esc_html( (string) $row['ranking_category_name'] ) . '</td><td>' . esc_html( $origin ) . '</td><td><strong>' . (int) $row['points'] . '</strong></td><td>';
				if ( $manual ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Segur que vols eliminar aquest ajust?', 'padelcamps' ) ) . '\');"><input type="hidden" name="action" value="padelcamps_delete_adjustment"><input type="hidden" name="player" value="' . $pid . '"><input type="hidden" name="adjustment" value="' . (int) $row['id'] . '">';
					wp_nonce_field( 'padelcamps_delete_adjustment' );
					echo '<button class="button-link button-link-delete">' . esc_html__( 'Elimina', 'padelcamps' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$this->render_adjustment_form( $pid );

		// Merge.
		echo '<hr><h2>' . esc_html__( 'Fusiona amb un altre jugador', 'padelcamps' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Si aquesta persona està duplicada, indica l’identificador (número) o el DNI de l’altre jugador. Es veurà un resum abans de fusionar. Les inscripcions i els punts de l’altre jugador passen a aquest.', 'padelcamps' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="action" value="merge"><input type="hidden" name="id" value="' . $pid . '">';
		echo '<input type="text" name="other" class="regular-text" placeholder="' . esc_attr__( 'Identificador o DNI de l’altre jugador', 'padelcamps' ) . '" aria-label="' . esc_attr__( 'Altre jugador', 'padelcamps' ) . '"> <button class="button">' . esc_html__( 'Continua', 'padelcamps' ) . '</button></form>';
		/* translators: %d: player id */
		echo '<p class="description">' . esc_html( sprintf( __( 'Identificador d’aquest jugador: %d', 'padelcamps' ), $pid ) ) . '</p>';

		// Danger zone.
		echo '<hr><h2>' . esc_html__( 'Zona de perill', 'padelcamps' ) . '</h2>';
		if ( $this->players->is_unused( $pid ) ) {
			echo '<p class="description">' . esc_html__( 'Aquest jugador no és a cap inscripció: es pot eliminar, amb els seus punts i ajustos.', 'padelcamps' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Segur que vols eliminar aquest jugador? No es pot desfer.', 'padelcamps' ) ) . '\');"><input type="hidden" name="action" value="padelcamps_delete_player"><input type="hidden" name="id" value="' . $pid . '">';
			wp_nonce_field( 'padelcamps_delete_player' );
			echo '<button class="button button-link-delete">' . esc_html__( 'Elimina el jugador', 'padelcamps' ) . '</button></form>';
		} else {
			echo '<p class="description">' . esc_html__( 'Per esborrar aquest jugador (per exemple, per una petició de supressió de dades), elimina les seves inscripcions: quan no en queda cap, el jugador i els seus punts s’esborren.', 'padelcamps' ) . '</p>';
		}
		echo '</div></div>';
	}

	private function render_adjustment_form( int $pid ): void {
		$options = array();
		foreach ( $this->seasons->all() as $season ) {
			foreach ( $this->seasons->ranking_categories( (int) $season['id'] ) as $rc ) {
				$options[ (int) $rc['id'] ] = $season['name'] . ' — ' . $rc['name'];
			}
		}
		echo '<h3>' . esc_html__( 'Afegeix un ajust de punts', 'padelcamps' ) . '</h3>';
		if ( ! $options ) {
			echo '<p class="description">' . esc_html__( 'Per ajustar punts cal que hi hagi una temporada amb categories de rànquing.', 'padelcamps' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Per corregir punts a mà (bonificació o sanció). Els punts negatius resten. El motiu queda registrat.', 'padelcamps' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-toolbar"><input type="hidden" name="action" value="padelcamps_add_adjustment"><input type="hidden" name="player" value="' . $pid . '">';
		wp_nonce_field( 'padelcamps_add_adjustment' );
		echo '<select name="ranking_category" aria-label="' . esc_attr__( 'Categoria de rànquing', 'padelcamps' ) . '" required><option value="">' . esc_html__( '— Temporada i categoria —', 'padelcamps' ) . '</option>';
		foreach ( $options as $id => $label ) {
			printf( '<option value="%d">%s</option>', (int) $id, esc_html( $label ) );
		}
		echo '</select> <input type="number" name="points" step="1" min="-' . (int) PlayerEdit::MAX_ADJUSTMENT . '" max="' . (int) PlayerEdit::MAX_ADJUSTMENT . '" class="small-text" placeholder="' . esc_attr__( 'Punts', 'padelcamps' ) . '" aria-label="' . esc_attr__( 'Punts', 'padelcamps' ) . '" required> ';
		echo '<input type="text" name="note" maxlength="190" class="regular-text" placeholder="' . esc_attr__( 'Motiu', 'padelcamps' ) . '" aria-label="' . esc_attr__( 'Motiu', 'padelcamps' ) . '" required> ';
		echo '<button class="button">' . esc_html__( 'Afegeix l’ajust', 'padelcamps' ) . '</button></form>';
	}

	private function render_merge( array $keep ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup.
		$ref = isset( $_GET['other'] ) ? Dni::normalize( sanitize_text_field( wp_unslash( $_GET['other'] ) ) ) : '';
		$back = self::url( array( 'action' => 'view', 'id' => (int) $keep['id'] ) );
		$other = '' !== $ref ? $this->players->find_by_ref( $ref ) : null;
		if ( ! $other ) {
			$this->redirect( array( 'action' => 'view', 'id' => (int) $keep['id'], 'msg' => 'not_found' ) );
		}
		if ( (int) $other['id'] === (int) $keep['id'] ) {
			$this->redirect( array( 'action' => 'view', 'id' => (int) $keep['id'], 'msg' => 'err_same' ) );
		}
		$blocked = null !== $keep['dni'] && null !== $other['dni'];

		echo '<div class="wrap pc-wrap"><h1 class="pc-title">' . esc_html__( 'Fusiona jugadors', 'padelcamps' ) . '</h1>';
		echo '<p class="pc-back"><a href="' . esc_url( $back ) . '">← ' . esc_html( $keep['name'] ) . '</a></p><div class="pc-panel pc-panel--solo">';
		echo '<p>' . esc_html__( 'Es conservarà el primer jugador. El segon s’esborra: les seves inscripcions i els seus punts passen al primer, que també agafa el DNI, telèfon i club que li falten.', 'padelcamps' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th></th><th>' . esc_html__( 'Es conserva', 'padelcamps' ) . '</th><th>' . esc_html__( 'S’esborra', 'padelcamps' ) . '</th></tr></thead><tbody>';
		$fields = array(
			'name'  => __( 'Nom i cognoms', 'padelcamps' ),
			'dni'   => __( 'DNI o NIE', 'padelcamps' ),
			'email' => __( 'Correu electrònic', 'padelcamps' ),
			'phone' => __( 'Telèfon', 'padelcamps' ),
			'club'  => __( 'Club', 'padelcamps' ),
		);
		foreach ( $fields as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( ( (string) $keep[ $key ] ) ?: '—' ) . '</td><td>' . esc_html( ( (string) $other[ $key ] ) ?: '—' ) . '</td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Inscripcions', 'padelcamps' ) . '</th><td>' . count( $this->players->registrations( (int) $keep['id'] ) ) . '</td><td>' . count( $this->players->registrations( (int) $other['id'] ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Files de punts', 'padelcamps' ) . '</th><td>' . count( $this->players->points( (int) $keep['id'] ) ) . '</td><td>' . count( $this->players->points( (int) $other['id'] ) ) . '</td></tr>';
		echo '</tbody></table>';

		if ( $blocked ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::message( 'err_dni' )[1] ) . '</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Segur que vols fusionar aquests jugadors? No es pot desfer.', 'padelcamps' ) ) . '\');"><input type="hidden" name="action" value="padelcamps_merge_players"><input type="hidden" name="keep" value="' . (int) $keep['id'] . '"><input type="hidden" name="remove" value="' . (int) $other['id'] . '">';
			wp_nonce_field( 'padelcamps_merge_players' );
			echo '<p><button class="button button-primary">' . esc_html__( 'Fusiona els jugadors', 'padelcamps' ) . '</button> <a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel·la', 'padelcamps' ) . '</a></p></form>';
		}
		echo '</div></div>';
	}

	// --- Handlers ----------------------------------------------------------

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( $action );
	}

	private static function post_str( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	public function handle_save_player(): void {
		$this->guard( 'padelcamps_save_player' );
		$id     = absint( self::post_str( 'id' ) );
		$player = $id ? $this->players->find( $id ) : null;
		if ( ! $player ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$input  = array(
			'name'  => self::post_str( 'name' ),
			'dni'   => self::post_str( 'dni' ),
			'email' => self::post_str( 'email' ),
			'phone' => self::post_str( 'phone' ),
			'club'  => self::post_str( 'club' ),
		);
		$result = PlayerEdit::validate( $input, null !== $player['dni'] );
		if ( $result['errors'] ) {
			$this->fail( $id, $result['errors'], $input );
		}
		if ( 'dni_taken' === $this->players->update( $id, $result['clean'] ) ) {
			$this->fail( $id, array( 'dni' => 'dni_taken' ), $input );
		}
		$this->redirect( array( 'action' => 'view', 'id' => $id, 'msg' => 'saved' ) );
	}

	public function handle_merge_players(): void {
		$this->guard( 'padelcamps_merge_players' );
		$keep   = absint( self::post_str( 'keep' ) );
		$remove = absint( self::post_str( 'remove' ) );
		$map    = array(
			'not_found'         => 'not_found',
			'same'              => 'err_same',
			'dni_conflict'      => 'err_dni',
			'same_registration' => 'err_same_reg',
		);
		$error = $this->players->merge( $keep, $remove );
		if ( null !== $error ) {
			$this->redirect( $keep && $this->players->find( $keep ) ? array( 'action' => 'view', 'id' => $keep, 'msg' => $map[ $error ] ?? 'not_found' ) : array( 'msg' => 'not_found' ) );
		}
		$this->redirect( array( 'action' => 'view', 'id' => $keep, 'msg' => 'merged' ) );
	}

	public function handle_delete_player(): void {
		$this->guard( 'padelcamps_delete_player' );
		$id    = absint( self::post_str( 'id' ) );
		$error = $this->players->delete_unused( $id );
		if ( 'in_use' === $error ) {
			$this->redirect( array( 'action' => 'view', 'id' => $id, 'msg' => 'err_in_use' ) );
		}
		$this->redirect( array( 'msg' => null === $error ? 'deleted' : 'not_found' ) );
	}

	public function handle_add_adjustment(): void {
		$this->guard( 'padelcamps_add_adjustment' );
		$pid    = absint( self::post_str( 'player' ) );
		$player = $pid ? $this->players->find( $pid ) : null;
		if ( ! $player ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$back   = array( 'action' => 'view', 'id' => $pid );
		$result = PlayerEdit::adjustment( array( 'points' => self::post_str( 'points' ), 'note' => self::post_str( 'note' ) ) );
		$rc     = $this->seasons->ranking_category( absint( self::post_str( 'ranking_category' ) ) );
		if ( $result['errors'] || ! $rc ) {
			$this->redirect( $back + array( 'msg' => 'err_adjust' ) );
		}
		$this->ranking->add_adjustment( (int) $rc['season_id'], (int) $rc['id'], $pid, $result['clean']['points'], $result['clean']['note'] );
		$this->redirect( $back + array( 'msg' => 'adj_added' ) );
	}

	public function handle_delete_adjustment(): void {
		$this->guard( 'padelcamps_delete_adjustment' );
		$pid  = absint( self::post_str( 'player' ) );
		$back = array( 'action' => 'view', 'id' => $pid );
		if ( ! $pid || ! $this->players->find( $pid ) ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$ok = $this->ranking->delete_adjustment_of_player( absint( self::post_str( 'adjustment' ) ), $pid );
		$this->redirect( $back + array( 'msg' => $ok ? 'adj_deleted' : 'err_adj_gone' ) );
	}
}
