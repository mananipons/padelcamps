<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\CategoryBulk;
use PadelCamps\Engine\FormatTemplates;
use PadelCamps\Engine\MatchFormat;
use PadelCamps\Engine\TournamentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Tournaments screen: list + per-tournament workspace with tabs that follow
 * the tournament lifecycle. Step 1 implements Summary and Configuration
 * (general data, categories, format template); the rest are placeholders.
 */
final class TournamentsPage {

	public const SLUG = 'padelcamps-tournaments';

	private TournamentRepository $repo;
	private FormTab $form_tab;
	private RegistrationsView $registrations;
	private DrawTab $draw_tab;
	private ScheduleTab $schedule_tab;
	private ResultsTab $results_tab;
	private CommunicationTab $comms_tab;

	public function __construct( TournamentRepository $repo, RegistrationsView $registrations ) {
		$this->repo          = $repo;
		$this->form_tab      = new FormTab( $repo );
		$this->registrations = $registrations;
		$this->draw_tab      = new DrawTab( $repo );
		$this->schedule_tab  = new ScheduleTab( $repo );
		$this->results_tab   = new ResultsTab( $repo );
		$this->comms_tab     = new CommunicationTab( $repo );
	}

	public function register(): void {
		$this->results_tab->register();
		$this->comms_tab->register();
		$this->form_tab->register();
		$this->draw_tab->register();
		$this->schedule_tab->register();
		foreach ( array( 'save_tournament', 'delete_tournament', 'set_status', 'save_category', 'delete_category', 'bulk_categories' ) as $action ) {
			add_action( 'admin_post_padelcamps_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	// --- Labels (literal calls so the translation scanner finds them) -------

	public static function status_label( string $status ): string {
		$labels = array(
			'draft'               => __( 'Esborrany', 'padelcamps' ),
			'registration_open'   => __( 'Inscripcions obertes', 'padelcamps' ),
			'registration_closed' => __( 'Inscripcions tancades', 'padelcamps' ),
			'drawn'               => __( 'Sorteig fet', 'padelcamps' ),
			'in_progress'         => __( 'En joc', 'padelcamps' ),
			'finished'            => __( 'Finalitzat', 'padelcamps' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function template_label( string $id ): string {
		$labels = array(
			'groups_knockout' => __( 'Grups + quadre final', 'padelcamps' ),
			'knockout'        => __( 'Eliminatòria directa', 'padelcamps' ),
			'round_robin'     => __( 'Lligueta (tots contra tots)', 'padelcamps' ),
			'americana'       => __( 'Americana (fase 2)', 'padelcamps' ),
		);
		return $labels[ $id ] ?? $id;
	}

	public static function template_description( string $id ): string {
		$labels = array(
			'groups_knockout' => __( 'Fase de grups i, després, eliminatòria amb els classificats.', 'padelcamps' ),
			'knockout'        => __( 'Quadre d’eliminatòria directa amb caps de sèrie.', 'padelcamps' ),
			'round_robin'     => __( 'Totes les parelles juguen contra totes en una sola classificació.', 'padelcamps' ),
			'americana'       => __( 'Inscripció individual i parelles rotatives a cada ronda. Disponible més endavant.', 'padelcamps' ),
		);
		return $labels[ $id ] ?? '';
	}

	/** Tie-break orders offered in the UI (key => Engine\Standings criteria). */
	public static function criteria_presets(): array {
		return array(
			'sets'   => array( 'wins', 'set_diff', 'game_diff', 'head_to_head' ),
			'direct' => array( 'wins', 'head_to_head', 'set_diff', 'game_diff' ),
		);
	}

	public static function phase_label( string $type ): string {
		$labels = array(
			'groups'      => __( 'Grups', 'padelcamps' ),
			'knockout'    => __( 'Quadre', 'padelcamps' ),
			'round_robin' => __( 'Lligueta', 'padelcamps' ),
			'americana'   => __( 'Americana', 'padelcamps' ),
		);
		return $labels[ $type ] ?? $type;
	}

	/** @return array<string, array{0:string, 1:bool}> tab slug => [label, implemented]. */
	private static function tabs(): array {
		return array(
			'summary'       => array( __( 'Resum', 'padelcamps' ), true ),
			'config'        => array( __( 'Configuració', 'padelcamps' ), true ),
			'form'          => array( __( 'Formulari', 'padelcamps' ), true ),
			'registrations' => array( __( 'Inscripcions', 'padelcamps' ), true ),
			'draw'          => array( __( 'Sorteig i quadres', 'padelcamps' ), true ),
			'schedule'      => array( __( 'Calendari i pistes', 'padelcamps' ), true ),
			'results'       => array( __( 'Resultats', 'padelcamps' ), true ),
			'communication' => array( __( 'Comunicació', 'padelcamps' ), true ),
			'public'        => array( __( 'Públic', 'padelcamps' ), true ),
		);
	}

	/** @return array{0:string, 1:string} [notice type, text] */
	private static function message( string $code ): array {
		$messages = array(
			'created'          => array( 'success', __( 'Torneig creat. Ara pots afegir-hi categories.', 'padelcamps' ) ),
			'saved'            => array( 'success', __( 'Torneig desat.', 'padelcamps' ) ),
			'deleted'          => array( 'success', __( 'Torneig eliminat.', 'padelcamps' ) ),
			'status'           => array( 'success', __( 'Estat del torneig actualitzat.', 'padelcamps' ) ),
			'form_saved'       => array( 'success', __( 'Formulari desat.', 'padelcamps' ) ),
			'category_saved'   => array( 'success', __( 'Categoria desada.', 'padelcamps' ) ),
			'category_deleted' => array( 'success', __( 'Categoria eliminada.', 'padelcamps' ) ),
			'err_name'         => array( 'error', __( 'Cal indicar un nom.', 'padelcamps' ) ),
			'err_dates'        => array( 'error', __( 'La data de final no pot ser anterior a la d’inici.', 'padelcamps' ) ),
			'err_template'     => array( 'error', __( 'El format triat no està disponible.', 'padelcamps' ) ),
			'err_status'       => array( 'error', __( 'No es pot canviar a aquest estat.', 'padelcamps' ) ),
			'category_locked'  => array( 'error', __( 'Aquesta categoria ja té partits: no se’n pot canviar el format.', 'padelcamps' ) ),
			'category_in_use'  => array( 'error', __( 'Aquesta categoria té inscripcions i no es pot eliminar.', 'padelcamps' ) ),
			'err_confirm_name' => array( 'error', __( 'El nom no coincideix: no s’ha eliminat res.', 'padelcamps' ) ),
			'not_found'        => array( 'error', __( 'No s’ha trobat el torneig.', 'padelcamps' ) ),
		);
		return $messages[ $code ] ?? array( 'info', '' );
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

	// --- Rendering ----------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'summary';
		// phpcs:enable

		if ( 'new' === $action ) {
			$this->render_workspace( null, 'config' );
			return;
		}
		if ( 'delete' === $action ) {
			$tournament = $id ? $this->repo->find( $id ) : null;
			if ( ! $tournament ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
			$this->render_delete( $tournament );
			return;
		}
		if ( 'edit' === $action ) {
			$tournament = $id ? $this->repo->find( $id ) : null;
			if ( ! $tournament ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
			$tab = isset( self::tabs()[ $tab ] ) ? $tab : 'summary';
			$this->render_workspace( $tournament, $tab );
			return;
		}
		$this->render_list();
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted codes.
		$code = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		if ( 'categories_bulk' === $code ) {
			$this->bulk_notice();
			return;
		}
		list( $type, $text ) = self::message( $code );
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	/** Result of a bulk edit: how many changed, and what could not be applied where. */
	private function bulk_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$n      = isset( $_GET['bn'] ) ? absint( $_GET['bn'] ) : 0;
		$locked = isset( $_GET['bl'] ) ? sanitize_text_field( wp_unslash( $_GET['bl'] ) ) : '';
		$na     = isset( $_GET['bna'] ) ? sanitize_text_field( wp_unslash( $_GET['bna'] ) ) : '';
		// phpcs:enable
		$text = $n > 0
			/* translators: %d: number of categories */
			? sprintf( _n( '%d categoria actualitzada.', '%d categories actualitzades.', $n, 'padelcamps' ), $n )
			: __( 'No hi ha hagut cap canvi.', 'padelcamps' );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $n > 0 ? 'success' : 'info', esc_html( $text ) );
		if ( '' !== $locked ) {
			/* translators: %s: category names with what could not change */
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( sprintf( __( 'No s’ha pogut canviar tot a: %s. El format i els desempats es bloquegen quan la categoria té partits; la consolació i els classificats, quan ja hi ha quadre final.', 'padelcamps' ), $locked ) ) );
		}
		if ( '' !== $na ) {
			/* translators: %s: category names */
			printf( '<div class="notice notice-info"><p>%s</p></div>', esc_html( sprintf( __( 'Algunes opcions no s’apliquen al format d’aquestes categories: %s.', 'padelcamps' ), $na ) ) );
		}
	}

	private static function bulk_key_label( string $key ): string {
		$labels = array(
			'consolation' => __( 'consolació', 'padelcamps' ),
			'advance'     => __( 'classificats per grup', 'padelcamps' ),
			'format'      => __( 'format dels partits', 'padelcamps' ),
			'criteria'    => __( 'desempats', 'padelcamps' ),
		);
		return $labels[ $key ] ?? $key;
	}

	private function render_list(): void {
		$rows = $this->repo->all();

		echo '<div class="wrap pc-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Tornejos', 'padelcamps' ) . '</h1> ';
		echo '<a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Afegeix un torneig', 'padelcamps' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$this->render_notice();

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Encara no hi ha cap torneig.', 'padelcamps' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nom', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Dates', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Lloc', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Categories', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Inscripcions', 'padelcamps' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$edit  = self::url( array( 'action' => 'edit', 'id' => (int) $row['id'] ) );
			$dates = self::fmt_date( $row['starts_on'] );
			if ( $row['ends_on'] && $row['ends_on'] !== $row['starts_on'] ) {
				$dates .= ' – ' . self::fmt_date( $row['ends_on'] );
			}
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $row['name'] ) . '</a></strong>';
			echo '<div class="row-actions"><span class="edit"><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edita', 'padelcamps' ) . '</a> | </span>';
			echo '<span class="trash"><a class="submitdelete" href="' . esc_url( self::url( array( 'action' => 'delete', 'id' => (int) $row['id'] ) ) ) . '">' . esc_html__( 'Elimina', 'padelcamps' ) . '</a></span></div></td>';
			echo '<td>' . $this->status_badge( $row['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in status_badge().
			echo '<td>' . esc_html( $dates ) . '</td>';
			echo '<td>' . esc_html( $row['venue'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['categories_count'] ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['registrations_count'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function status_badge( string $status ): string {
		return sprintf(
			'<span class="pc-badge pc-badge--%s">%s</span>',
			esc_attr( sanitize_html_class( $status ) ),
			esc_html( self::status_label( $status ) )
		);
	}

	private function render_workspace( ?array $tournament, string $tab ): void {
		$is_new = null === $tournament;

		echo '<div class="wrap pc-wrap"><h1 class="pc-title">';
		echo $is_new ? esc_html__( 'Nou torneig', 'padelcamps' ) : esc_html( $tournament['name'] ) . ' ' . $this->status_badge( $tournament['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		echo '</h1>';
		echo '<p class="pc-back"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Tots els tornejos', 'padelcamps' ) . '</a></p>';
		$this->render_notice();

		if ( ! $is_new ) {
			echo '<nav class="nav-tab-wrapper pc-tabs">';
			foreach ( self::tabs() as $slug => $def ) {
				printf(
					'<a href="%s" class="nav-tab%s"%s>%s</a>',
					esc_url( self::url( array( 'action' => 'edit', 'id' => (int) $tournament['id'], 'tab' => $slug ) ) ),
					$slug === $tab ? ' nav-tab-active' : '',
					$def[1] ? '' : ' style="color:#8c8f94"',
					esc_html( $def[0] )
				);
			}
			echo '</nav>';
		}

		// Everything inside a tab sits in one padded card, attached to the tab strip.
		echo '<div class="pc-panel' . ( $is_new ? ' pc-panel--solo' : '' ) . '">';
		if ( $is_new || 'config' === $tab ) {
			$this->tab_config( $tournament );
		} elseif ( 'summary' === $tab ) {
			$this->tab_summary( $tournament );
		} elseif ( 'form' === $tab ) {
			$this->form_tab->render( $tournament );
		} elseif ( 'public' === $tab ) {
			( new PublicTab( $this->repo ) )->render( $tournament );
		} elseif ( 'results' === $tab ) {
			$this->results_tab->render( $tournament );
		} elseif ( 'communication' === $tab ) {
			$this->comms_tab->render( $tournament );
		} elseif ( 'schedule' === $tab ) {
			$this->schedule_tab->render( $tournament );
		} elseif ( 'draw' === $tab ) {
			$this->draw_tab->render( $tournament );
		} elseif ( 'registrations' === $tab ) {
			$this->registrations->render_tournament( $tournament );
		} else {
			echo '<p>' . esc_html__( 'Aquesta secció arribarà aviat.', 'padelcamps' ) . '</p>';
		}
		echo '</div></div>';
	}

	private function tab_summary( array $t ): void {
		$id         = (int) $t['id'];
		$categories = $this->repo->categories( $id );
		$regs       = $this->repo->registrations_count( $id );
		$next       = TournamentStatus::next( $t['status'] );
		$prev       = TournamentStatus::prev( $t['status'] );

		echo '<h2>' . esc_html__( 'Estat del torneig', 'padelcamps' ) . '</h2><ol>';
		foreach ( TournamentStatus::all() as $s ) {
			$label = esc_html( self::status_label( $s ) );
			echo '<li>' . ( $s === $t['status'] ? '<strong>' . $label . '</strong>' : $label ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</ol>';

		echo '<div class="pc-actions">';
		foreach ( array( 'prev' => $prev, 'next' => $next ) as $dir => $target ) {
			if ( null === $target ) {
				continue;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="padelcamps_set_status">';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
			echo '<input type="hidden" name="dir" value="' . esc_attr( $dir ) . '">';
			wp_nonce_field( 'padelcamps_set_status' );
			/* translators: %s: tournament status name */
			$label = 'next' === $dir
				? sprintf( __( 'Passa a «%s»', 'padelcamps' ), self::status_label( $target ) )
				: sprintf( __( 'Torna a «%s»', 'padelcamps' ), self::status_label( $target ) );
			printf( '<button class="button%s">%s</button>', 'next' === $dir ? ' button-primary' : '', esc_html( $label ) );
			echo '</form>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Llista de pendents', 'padelcamps' ) . '</h2><ul>';
		$checks = array(
			array( ! empty( $t['starts_on'] ), __( 'Dates definides', 'padelcamps' ) ),
			array( ! empty( $t['venue'] ), __( 'Lloc definit', 'padelcamps' ) ),
			array( count( $categories ) > 0, __( 'Almenys una categoria', 'padelcamps' ) ),
		);
		foreach ( $checks as $check ) {
			echo '<li>' . ( $check[0] ? '✔' : '✘' ) . ' ' . esc_html( $check[1] ) . '</li>';
		}
		echo '</ul>';

		/* translators: 1: number of categories, 2: number of registrations */
		echo '<p>' . esc_html( sprintf( __( '%1$d categories · %2$d inscripcions', 'padelcamps' ), count( $categories ), $regs ) ) . '</p>';

		echo '<hr><h2>' . esc_html__( 'Zona de perill', 'padelcamps' ) . '</h2>';
		echo '<p>' . esc_html__( 'Elimina el torneig amb totes les seves dades: categories, inscripcions, jugadors que no siguin en cap altre torneig, quadres, partits, resultats, horaris i registre de correus.', 'padelcamps' ) . '</p>';
		echo '<p><a class="button button-link-delete" href="' . esc_url( self::url( array( 'action' => 'delete', 'id' => $id ) ) ) . '">' . esc_html__( 'Elimina el torneig…', 'padelcamps' ) . '</a></p>';
	}

	/** Confirmation screen: what will be deleted, and the tournament name typed again. */
	private function render_delete( array $t ): void {
		$id = (int) $t['id'];
		$s  = $this->repo->deletion_summary( $id );
		echo '<div class="wrap pc-wrap"><h1 class="pc-title">' . esc_html( sprintf( /* translators: %s: tournament name */ __( 'Eliminar «%s»', 'padelcamps' ), $t['name'] ) ) . '</h1>';
		echo '<p class="pc-back"><a href="' . esc_url( self::url( array( 'action' => 'edit', 'id' => $id ) ) ) . '">← ' . esc_html__( 'Torna al torneig', 'padelcamps' ) . '</a></p>';
		$this->render_notice();
		echo '<div class="pc-panel pc-panel--solo">';
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Aquesta acció no es pot desfer.', 'padelcamps' ) . '</strong> ' . esc_html__( 'S’esborrarà tot el que hi ha a continuació. Si vols conservar les inscripcions, exporta-les abans a CSV des de la pestanya Inscripcions.', 'padelcamps' ) . '</p></div>';
		echo '<ul style="list-style:disc;padding-left:20px">';
		$lines = array(
			/* translators: %d: number */
			sprintf( _n( '%d categoria', '%d categories', $s['categories'], 'padelcamps' ), $s['categories'] ),
			/* translators: %d: number */
			sprintf( _n( '%d inscripció', '%d inscripcions', $s['registrations'], 'padelcamps' ), $s['registrations'] ),
			/* translators: %d: number */
			sprintf( _n( '%d jugador (els que també siguin en altres tornejos es conserven)', '%d jugadors (els que també siguin en altres tornejos es conserven)', $s['players'], 'padelcamps' ), $s['players'] ),
			/* translators: 1: matches, 2: played matches */
			sprintf( __( '%1$d partits (%2$d amb resultat), quadres i horaris', 'padelcamps' ), $s['matches'], $s['played'] ),
			/* translators: %d: number */
			sprintf( _n( '%d correu al registre d’enviaments', '%d correus al registre d’enviaments', $s['emails'], 'padelcamps' ), $s['emails'] ),
		);
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_delete_tournament"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'padelcamps_delete_tournament' );
		echo '<p><label for="pc-del-name">' . esc_html( sprintf( /* translators: %s: tournament name */ __( 'Per confirmar, escriu el nom del torneig: %s', 'padelcamps' ), $t['name'] ) ) . '</label><br>';
		echo '<input type="text" id="pc-del-name" name="confirm_name" class="regular-text" autocomplete="off" required></p>';
		echo '<p><button class="button button-primary pc-danger">' . esc_html__( 'Elimina definitivament', 'padelcamps' ) . '</button></p></form>';
		echo '</div></div>';
	}

	private function tab_config( ?array $t ): void {
		$is_new = null === $t;

		echo '<h2>' . esc_html__( 'Dades generals', 'padelcamps' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_save_tournament">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $is_new ? 0 : (int) $t['id'] ) ) . '">';
		wp_nonce_field( 'padelcamps_save_tournament' );
		echo '<table class="form-table" role="presentation">';
		$this->row_text( 'name', __( 'Nom', 'padelcamps' ), $t['name'] ?? '', true );
		$this->row_text( 'starts_on', __( 'Data d’inici', 'padelcamps' ), $t['starts_on'] ?? '', false, 'date' );
		$this->row_text( 'ends_on', __( 'Data de final', 'padelcamps' ), $t['ends_on'] ?? '', false, 'date' );
		$this->row_text( 'venue', __( 'Lloc', 'padelcamps' ), $t['venue'] ?? '' );
		echo '<tr><th scope="row"><label for="pc-description">' . esc_html__( 'Descripció', 'padelcamps' ) . '</label></th><td>';
		echo '<textarea id="pc-description" name="description" rows="4" class="large-text">' . esc_textarea( $t['description'] ?? '' ) . '</textarea></td></tr>';
		$mode = $is_new ? 'full' : $this->repo->public_names( $t );
		echo '<tr><th scope="row"><label for="pc-public-names">' . esc_html__( 'Noms als quadres públics', 'padelcamps' ) . '</label></th><td><select id="pc-public-names" name="public_names">';
		printf( '<option value="full"%s>%s</option>', selected( $mode, 'full', false ), esc_html__( 'Nom i cognoms complets', 'padelcamps' ) );
		printf( '<option value="initials"%s>%s</option>', selected( $mode, 'initials', false ), esc_html__( 'Nom i inicial del primer cognom (Anna P.)', 'padelcamps' ) );
		echo '</select><p class="description">' . esc_html__( 'S’aplica a la web i a l’API pública. Les dades de contacte mai es publiquen.', 'padelcamps' ) . '</p></td></tr>';
		echo '</table>';
		printf( '<p><button type="submit" class="button button-primary">%s</button></p>', $is_new ? esc_html__( 'Crea el torneig', 'padelcamps' ) : esc_html__( 'Desa el torneig', 'padelcamps' ) );
		echo '</form>';

		if ( $is_new ) {
			return;
		}
		$this->categories_section( $t );
	}

	private function row_text( string $name, string $label, string $value, bool $required = false, string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="pc-%1$s">%2$s</label></th><td><input type="%3$s" id="pc-%1$s" name="%1$s" value="%4$s" class="regular-text"%5$s></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? ' required' : ''
		);
	}

	private function categories_section( array $t ): void {
		$tid        = (int) $t['id'];
		$categories = $this->repo->categories( $tid );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$edit_id  = isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0;
		$editing  = $edit_id ? $this->repo->category( $edit_id ) : null;
		$editing  = $editing && (int) $editing['tournament_id'] === $tid ? $editing : null;
		$edit_opt = array(
			'advance'     => 2,
			'consolation' => false,
			'format'      => MatchFormat::defaults(),
			'criteria'    => 'sets',
		);
		$locked    = $editing && $this->repo->category_has_matches( (int) $editing['id'] );
		$ko_locked = $editing && $this->repo->knockout_has_matches( (int) $editing['id'] );
		$ko_dis    = $ko_locked ? ' disabled' : '';
		if ( $editing ) {
			foreach ( $this->repo->phases( (int) $editing['id'] ) as $phase ) {
				$settings = json_decode( (string) $phase['settings'], true ) ?: array();
				if ( isset( $settings['advance'] ) ) {
					$edit_opt['advance'] = (int) $settings['advance'];
				}
				if ( ! empty( $settings['consolation'] ) ) {
					$edit_opt['consolation'] = true;
				}
				if ( is_array( $settings['format'] ?? null ) ) {
					$edit_opt['format'] = MatchFormat::normalize( $settings['format'] );
				}
				if ( is_array( $settings['criteria'] ?? null ) ) {
					$edit_opt['criteria'] = array_search( $settings['criteria'], self::criteria_presets(), true ) ?: 'sets';
				}
			}
		}

		echo '<hr><h2>' . esc_html__( 'Categories', 'padelcamps' ) . '</h2>';
		if ( ! $categories ) {
			echo '<p>' . esc_html__( 'Encara no hi ha cap categoria.', 'padelcamps' ) . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
			echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="pc-cat-all" aria-label="' . esc_attr__( 'Selecciona-ho tot', 'padelcamps' ) . '"></td>';
			echo '<th>' . esc_html__( 'Nom', 'padelcamps' ) . '</th>';
			echo '<th>' . esc_html__( 'Format', 'padelcamps' ) . '</th>';
			echo '<th>' . esc_html__( 'Fases', 'padelcamps' ) . '</th>';
			echo '<th>' . esc_html__( 'Places', 'padelcamps' ) . '</th>';
			echo '<th>' . esc_html__( 'Preu', 'padelcamps' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
			foreach ( $categories as $cat ) {
				$cid = (int) $cat['id'];
				echo '<tr>';
				echo '<th scope="row" class="check-column"><input type="checkbox" class="pc-cat-check" name="cats[]" value="' . $cid . '" form="pc-cat-bulk" aria-label="' . esc_attr( $cat['name'] ) . '"></th>';
				echo '<td><strong>' . esc_html( $cat['name'] ) . '</strong></td>';
				echo '<td>' . esc_html( self::template_label( $cat['format'] ) ) . '</td>';
				echo '<td>' . esc_html( $this->phase_summary( $this->repo->phases( $cid ) ) ) . '</td>';
				echo '<td>' . esc_html( null === $cat['max_teams'] ? '∞' : (string) (int) $cat['max_teams'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $cat['price'], 2 ) ) . ' €</td>';
				echo '<td>';
				echo '<a href="' . esc_url( self::url( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config', 'category' => $cid ) ) . '#pc-category-form' ) . '">' . esc_html__( 'Edita', 'padelcamps' ) . '</a> | ';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Segur que vols eliminar aquesta categoria?', 'padelcamps' ) ) . '\');">';
				echo '<input type="hidden" name="action" value="padelcamps_delete_category">';
				echo '<input type="hidden" name="tournament_id" value="' . esc_attr( (string) $tid ) . '">';
				echo '<input type="hidden" name="category_id" value="' . esc_attr( (string) $cid ) . '">';
				wp_nonce_field( 'padelcamps_delete_category' );
				echo '<button class="button-link button-link-delete">' . esc_html__( 'Elimina', 'padelcamps' ) . '</button></form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			$this->bulk_categories_form( $tid );
		}

		echo '<h3 id="pc-category-form">' . ( $editing ? esc_html__( 'Edita la categoria', 'padelcamps' ) : esc_html__( 'Afegeix una categoria', 'padelcamps' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_save_category">';
		echo '<input type="hidden" name="tournament_id" value="' . esc_attr( (string) $tid ) . '">';
		echo '<input type="hidden" name="category_id" value="' . esc_attr( (string) ( $editing ? (int) $editing['id'] : 0 ) ) . '">';
		wp_nonce_field( 'padelcamps_save_category' );
		echo '<table class="form-table" role="presentation">';
		$this->row_text( 'cat_name', __( 'Nom', 'padelcamps' ), $editing['name'] ?? '', true );

		$current = $editing['format'] ?? 'groups_knockout';
		echo '<tr><th scope="row"><label for="pc-cat-template">' . esc_html__( 'Format', 'padelcamps' ) . '</label></th><td>';
		echo '<select id="pc-cat-template" name="cat_template">';
		foreach ( FormatTemplates::all() as $tpl_id => $tpl ) {
			printf(
				'<option value="%s" data-options="%s"%s%s>%s</option>',
				esc_attr( $tpl_id ),
				esc_attr( implode( ' ', $tpl['options'] ) ),
				selected( $current, $tpl_id, false ),
				$tpl['available'] ? '' : ' disabled',
				esc_html( self::template_label( $tpl_id ) )
			);
		}
		echo '</select>';
		foreach ( array_keys( FormatTemplates::all() ) as $tpl_id ) {
			echo '<p class="description" data-tpl="' . esc_attr( $tpl_id ) . '">' . esc_html( self::template_description( $tpl_id ) ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr data-opt="advance"><th scope="row"><label for="pc-cat-advance">' . esc_html__( 'Classificats per grup', 'padelcamps' ) . '</label></th><td>';
		echo '<select id="pc-cat-advance" name="cat_advance"' . $ko_dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- literal.
		for ( $n = 1; $n <= 4; $n++ ) {
			printf( '<option value="%1$d"%2$s>%1$d</option>', $n, selected( $edit_opt['advance'], $n, false ) );
		}
		echo '</select></td></tr>';

		echo '<tr data-opt="consolation"><th scope="row">' . esc_html__( 'Consolació', 'padelcamps' ) . '</th><td><label>';
		printf( '<input type="checkbox" name="cat_consolation" value="1"%s%s> ', checked( $edit_opt['consolation'], true, false ), $ko_dis ); // phpcs:ignore WordPress.Security.EscapeOutput -- literal.
		echo esc_html__( 'Inclou quadre de consolació', 'padelcamps' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Hi juguen les parelles que perden el primer partit del quadre final (les que passen directament no).', 'padelcamps' ) . '</p>';
		if ( $ko_locked ) {
			echo '<p class="description">' . esc_html__( 'El quadre final ja està generat: per canviar la consolació o els classificats, esborra’l primer a Sorteig i quadres.', 'padelcamps' ) . '</p>';
		}
		echo '</td></tr>';

		// Match format and tie-breaks: fixed once the category has matches.
		$dis = $locked ? ' disabled' : '';
		$f   = $edit_opt['format'];
		echo '<tr><th scope="row">' . esc_html__( 'Format dels partits', 'padelcamps' ) . '</th><td><span class="pc-inline">';
		echo '<select name="fmt_best_of" aria-label="' . esc_attr__( 'Sets', 'padelcamps' ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $dis is a literal.
		printf( '<option value="3"%s>%s</option>', selected( $f['best_of'], 3, false ), esc_html__( 'Al millor de 3 sets', 'padelcamps' ) );
		printf( '<option value="1"%s>%s</option>', selected( $f['best_of'], 1, false ), esc_html__( 'A 1 set', 'padelcamps' ) );
		echo '</select><select name="fmt_games" aria-label="' . esc_attr__( 'Jocs per set', 'padelcamps' ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( array( 6, 4, 9 ) as $g ) {
			/* translators: %d: games per set */
			printf( '<option value="%1$d"%2$s>%3$s</option>', $g, selected( $f['games'], $g, false ), esc_html( sprintf( __( 'Sets a %d jocs', 'padelcamps' ), $g ) ) );
		}
		echo '</select><select name="fmt_deciding" aria-label="' . esc_attr__( 'Set decisiu', 'padelcamps' ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<option value="super_tiebreak"%s>%s</option>', selected( $f['deciding'], 'super_tiebreak', false ), esc_html__( 'Set decisiu: super tie-break', 'padelcamps' ) );
		printf( '<option value="set"%s>%s</option>', selected( $f['deciding'], 'set', false ), esc_html__( 'Set decisiu: set normal', 'padelcamps' ) );
		echo '</select><select name="fmt_points" aria-label="' . esc_attr__( 'Punts del super tie-break', 'padelcamps' ) . '"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( array( 10, 7 ) as $p ) {
			/* translators: %d: points */
			printf( '<option value="%1$d"%2$s>%3$s</option>', $p, selected( $f['points'], $p, false ), esc_html( sprintf( __( 'Super tie-break a %d punts', 'padelcamps' ), $p ) ) );
		}
		echo '</select></span>';
		if ( $locked ) {
			echo '<p class="description">' . esc_html__( 'No es pot canviar perquè la categoria ja té partits.', 'padelcamps' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr data-opt="criteria"><th scope="row"><label for="pc-cat-criteria">' . esc_html__( 'Desempats de la classificació', 'padelcamps' ) . '</label></th><td>';
		echo '<select id="pc-cat-criteria" name="cat_criteria"' . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<option value="sets"%s>%s</option>', selected( $edit_opt['criteria'], 'sets', false ), esc_html__( 'Victòries → diferència de sets → diferència de jocs → enfrontament directe', 'padelcamps' ) );
		printf( '<option value="direct"%s>%s</option>', selected( $edit_opt['criteria'], 'direct', false ), esc_html__( 'Victòries → enfrontament directe → diferència de sets → diferència de jocs', 'padelcamps' ) );
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="pc-cat-max">' . esc_html__( 'Places (0 = sense límit)', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="number" min="0" id="pc-cat-max" name="cat_max_teams" value="%d" class="small-text"></td></tr>', (int) ( $editing['max_teams'] ?? 0 ) );

		echo '<tr><th scope="row"><label for="pc-cat-price">' . esc_html__( 'Preu per inscripció (€)', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="number" min="0" step="0.01" id="pc-cat-price" name="cat_price" value="%s" class="small-text"></td></tr>', esc_attr( number_format( (float) ( $editing['price'] ?? 0 ), 2, '.', '' ) ) );
		echo '</table>';

		printf( '<p><button type="submit" class="button button-primary">%s</button> ', $editing ? esc_html__( 'Desa la categoria', 'padelcamps' ) : esc_html__( 'Afegeix la categoria', 'padelcamps' ) );
		if ( $editing ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config' ) ) ) . '">' . esc_html__( 'Cancel·la', 'padelcamps' ) . '</a>';
		}
		echo '</p></form>';

		// Show only the options that the selected format supports.
		echo '<script>(function(){var s=document.getElementById("pc-cat-template");if(!s)return;function u(){var o=s.options[s.selectedIndex];var a=(o.getAttribute("data-options")||"").split(" ");document.querySelectorAll("[data-opt]").forEach(function(r){r.style.display=a.indexOf(r.getAttribute("data-opt"))>-1?"":"none";});document.querySelectorAll("[data-tpl]").forEach(function(p){p.style.display=p.getAttribute("data-tpl")===s.value?"":"none";});}s.addEventListener("change",u);u();})();</script>';
	}

	/** "Edit selected categories" panel: every field starts at "keep". */
	private function bulk_categories_form( int $tid ): void {
		$keep   = '<option value="">' . esc_html__( '— No canviïs —', 'padelcamps' ) . '</option>';
		$select = static function ( string $name, string $label, array $options ) use ( $keep ): void {
			echo '<label class="pc-bulk-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">' . $keep; // phpcs:ignore WordPress.Security.EscapeOutput -- $keep is escaped.
			foreach ( $options as $value => $text ) {
				printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( $text ) );
			}
			echo '</select></label>';
		};

		echo '<details class="pc-details" id="pc-cat-bulk-box"><summary class="button">' . esc_html__( 'Edita en bloc les categories seleccionades', 'padelcamps' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pc-cat-bulk" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="padelcamps_bulk_categories"><input type="hidden" name="tournament_id" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_bulk_categories' );
		echo '<p class="description">' . esc_html__( 'Marca les categories a la taula i tria només el que vulguis canviar; la resta es manté.', 'padelcamps' ) . '</p>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-end">';
		$select( 'b_consolation', __( 'Consolació', 'padelcamps' ), array( 'on' => __( 'Inclou quadre de consolació', 'padelcamps' ), 'off' => __( 'Sense consolació', 'padelcamps' ) ) );
		$select( 'b_advance', __( 'Classificats per grup', 'padelcamps' ), array( 1 => '1', 2 => '2', 3 => '3', 4 => '4' ) );
		$select( 'b_best_of', __( 'Sets', 'padelcamps' ), array( 3 => __( 'Al millor de 3 sets', 'padelcamps' ), 1 => __( 'A 1 set', 'padelcamps' ) ) );
		$games = array();
		foreach ( array( 6, 4, 9 ) as $g ) {
			/* translators: %d: games per set */
			$games[ $g ] = sprintf( __( 'Sets a %d jocs', 'padelcamps' ), $g );
		}
		$select( 'b_games', __( 'Jocs per set', 'padelcamps' ), $games );
		$select( 'b_deciding', __( 'Set decisiu', 'padelcamps' ), array( 'super_tiebreak' => __( 'Set decisiu: super tie-break', 'padelcamps' ), 'set' => __( 'Set decisiu: set normal', 'padelcamps' ) ) );
		$points = array();
		foreach ( array( 10, 7 ) as $p ) {
			/* translators: %d: points */
			$points[ $p ] = sprintf( __( 'Super tie-break a %d punts', 'padelcamps' ), $p );
		}
		$select( 'b_points', __( 'Punts del super tie-break', 'padelcamps' ), $points );
		$select( 'b_criteria', __( 'Desempats de la classificació', 'padelcamps' ), array( 'sets' => __( 'Victòries → diferència de sets → diferència de jocs → enfrontament directe', 'padelcamps' ), 'direct' => __( 'Victòries → enfrontament directe → diferència de sets → diferència de jocs', 'padelcamps' ) ) );
		echo '<label class="pc-bulk-field"><span>' . esc_html__( 'Places (0 = sense límit)', 'padelcamps' ) . '</span><input type="number" min="0" name="b_max_teams" class="small-text" placeholder="—"></label>';
		echo '<label class="pc-bulk-field"><span>' . esc_html__( 'Preu per inscripció (€)', 'padelcamps' ) . '</span><input type="number" min="0" step="0.01" name="b_price" class="small-text" placeholder="—"></label>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'El preu nou només afecta les inscripcions que arribin a partir d’ara. El format i els desempats no es poden canviar en categories amb partits; la consolació i els classificats, sí, fins que es genera el quadre final.', 'padelcamps' ) . '</p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Aplica a les seleccionades', 'padelcamps' ) . '</button></p></form></details>';
		echo '<script>(function(){var a=document.getElementById("pc-cat-all"),f=document.getElementById("pc-cat-bulk");if(!a||!f)return;a.addEventListener("change",function(){document.querySelectorAll(".pc-cat-check").forEach(function(c){c.checked=a.checked;});});f.addEventListener("submit",function(e){if(!document.querySelector(".pc-cat-check:checked")){e.preventDefault();alert(' . wp_json_encode( __( 'Marca almenys una categoria a la taula.', 'padelcamps' ) ) . ');}});})();</script>';
	}

	private function phase_summary( array $phases ): string {
		$parts = array();
		foreach ( $phases as $phase ) {
			$settings = json_decode( (string) $phase['settings'], true ) ?: array();
			$label    = self::phase_label( $phase['type'] );
			if ( 'groups' === $phase['type'] && ! empty( $settings['advance'] ) ) {
				/* translators: %d: teams that advance from each group */
				$label .= ' (' . sprintf( __( 'passen %d per grup', 'padelcamps' ), (int) $settings['advance'] ) . ')';
			}
			if ( 'knockout' === $phase['type'] && ! empty( $settings['consolation'] ) ) {
				$label .= ' + ' . __( 'consolació', 'padelcamps' );
			}
			$parts[] = $label;
		}
		return implode( ' → ', $parts );
	}

	// --- Handlers -----------------------------------------------------------

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( $action );
	}

	private static function post_str( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	private static function clean_date( string $value ): ?string {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $value;
		}
		return null;
	}

	public function handle_save_tournament(): void {
		$this->guard( 'padelcamps_save_tournament' );

		$id   = absint( self::post_str( 'id' ) );
		$data = array(
			'name'        => sanitize_text_field( self::post_str( 'name' ) ),
			'starts_on'   => self::clean_date( self::post_str( 'starts_on' ) ),
			'ends_on'     => self::clean_date( self::post_str( 'ends_on' ) ),
			'venue'       => sanitize_text_field( self::post_str( 'venue' ) ),
			'description' => sanitize_textarea_field( self::post_str( 'description' ) ),
		);
		$back = $id ? array( 'action' => 'edit', 'id' => $id, 'tab' => 'config' ) : array( 'action' => 'new' );

		if ( '' === $data['name'] ) {
			$this->redirect( $back + array( 'msg' => 'err_name' ) );
		}
		if ( $data['starts_on'] && $data['ends_on'] && $data['ends_on'] < $data['starts_on'] ) {
			$this->redirect( $back + array( 'msg' => 'err_dates' ) );
		}

		if ( $id ) {
			if ( ! $this->repo->find( $id ) ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
			$this->repo->update( $id, $data );
			$this->repo->save_setting( $id, 'public_names', 'initials' === self::post_str( 'public_names' ) ? 'initials' : 'full' );
			$this->redirect( array( 'action' => 'edit', 'id' => $id, 'tab' => 'config', 'msg' => 'saved' ) );
		}

		$new_id = $this->repo->create( $data );
		$this->repo->save_setting( $new_id, 'public_names', 'initials' === self::post_str( 'public_names' ) ? 'initials' : 'full' );
		$this->redirect( array( 'action' => 'edit', 'id' => $new_id, 'tab' => 'config', 'msg' => 'created' ) );
	}

	public function handle_set_status(): void {
		$this->guard( 'padelcamps_set_status' );

		$id         = absint( self::post_str( 'id' ) );
		$tournament = $id ? $this->repo->find( $id ) : null;
		if ( ! $tournament ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$target = 'prev' === self::post_str( 'dir' )
			? TournamentStatus::prev( $tournament['status'] )
			: TournamentStatus::next( $tournament['status'] );

		if ( null === $target ) {
			$this->redirect( array( 'action' => 'edit', 'id' => $id, 'msg' => 'err_status' ) );
		}
		$this->repo->set_status( $id, $target );
		$this->redirect( array( 'action' => 'edit', 'id' => $id, 'msg' => 'status' ) );
	}

	public function handle_delete_tournament(): void {
		$this->guard( 'padelcamps_delete_tournament' );

		$id         = absint( self::post_str( 'id' ) );
		$tournament = $id ? $this->repo->find( $id ) : null;
		if ( ! $tournament ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		// The organizer must type the name again: deleting removes registrations and results for good.
		$typed = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( self::post_str( 'confirm_name' ) ) ) );
		$name  = trim( (string) preg_replace( '/\s+/u', ' ', (string) $tournament['name'] ) );
		if ( '' === $typed || $typed !== $name ) {
			$this->redirect( array( 'action' => 'delete', 'id' => $id, 'msg' => 'err_confirm_name' ) );
		}
		$this->repo->delete( $id );
		$this->redirect( array( 'msg' => 'deleted' ) );
	}

	public function handle_save_category(): void {
		$this->guard( 'padelcamps_save_category' );

		$tid        = absint( self::post_str( 'tournament_id' ) );
		$cid        = absint( self::post_str( 'category_id' ) );
		$tournament = $tid ? $this->repo->find( $tid ) : null;
		if ( ! $tournament ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}

		$back     = array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config' );
		$name     = sanitize_text_field( self::post_str( 'cat_name' ) );
		$template = sanitize_key( self::post_str( 'cat_template' ) );
		if ( '' === $name ) {
			$this->redirect( $back + array( 'msg' => 'err_name' ) );
		}
		if ( ! FormatTemplates::is_available( $template ) ) {
			$this->redirect( $back + array( 'msg' => 'err_template' ) );
		}

		$existing = null;
		if ( $cid ) {
			$existing = $this->repo->category( $cid );
			if ( ! $existing || (int) $existing['tournament_id'] !== $tid ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
		}
		$has_matches = $existing && $this->repo->category_has_matches( $cid );
		if ( $has_matches && $existing['format'] !== $template ) {
			$this->redirect( $back + array( 'msg' => 'category_locked' ) );
		}

		$max   = absint( self::post_str( 'cat_max_teams' ) );
		$price = round( (float) str_replace( ',', '.', self::post_str( 'cat_price' ) ), 2 );
		$price = max( 0.0, min( 99999.0, $price ) );

		$saved_id = $this->repo->save_category(
			array(
				'id'                => $cid,
				'tournament_id'     => $tid,
				'name'              => $name,
				'format'            => $template,
				'registration_mode' => FormatTemplates::all()[ $template ]['registration_mode'],
				'max_teams'         => $max > 0 ? $max : null,
				'price'             => $price,
			)
		);

		// Phases are rebuilt unless the category already has matches (kept as they are).
		if ( ! $has_matches ) {
			$format   = MatchFormat::normalize(
				array(
					'best_of'  => absint( self::post_str( 'fmt_best_of' ) ),
					'games'    => absint( self::post_str( 'fmt_games' ) ),
					'deciding' => sanitize_key( self::post_str( 'fmt_deciding' ) ),
					'points'   => absint( self::post_str( 'fmt_points' ) ),
				)
			);
			$criteria = self::criteria_presets()[ sanitize_key( self::post_str( 'cat_criteria' ) ) ] ?? self::criteria_presets()['sets'];
			$phases   = FormatTemplates::build_phases(
				$template,
				array(
					'advance'     => absint( self::post_str( 'cat_advance' ) ),
					'consolation' => '1' === self::post_str( 'cat_consolation' ),
				)
			);
			foreach ( $phases as &$phase ) {
				$phase['settings']['format'] = $format;
				if ( in_array( $phase['type'], array( 'groups', 'round_robin' ), true ) ) {
					$phase['settings']['criteria'] = $criteria;
				}
			}
			unset( $phase );
			$this->repo->replace_phases( $saved_id, $phases );
		} elseif ( ! $this->repo->knockout_has_matches( $cid ) ) {
			// With matches the phases stay, but consolation and teams per group can still
			// change until the final bracket exists (they are only read when it is built).
			$this->apply_phase_changes(
				$existing,
				CategoryBulk::changes(
					array(
						'consolation' => '1' === self::post_str( 'cat_consolation' ) ? 'on' : 'off',
						'advance'     => self::post_str( 'cat_advance' ),
					),
					self::criteria_presets()
				)
			);
		}
		$this->redirect( $back + array( 'msg' => 'category_saved' ) );
	}

	/** Apply CategoryBulk phase changes to one category. @return array CategoryBulk::apply() result */
	private function apply_phase_changes( array $category, array $changes ): array {
		$cid    = (int) $category['id'];
		$result = CategoryBulk::apply(
			(string) $category['format'],
			$this->repo->phases_decoded( $cid ),
			$changes,
			array(
				'matches'  => $this->repo->category_has_matches( $cid ),
				'knockout' => $this->repo->knockout_has_matches( $cid ),
			),
			self::criteria_presets()
		);
		foreach ( $result['settings'] as $phase_id => $settings ) {
			$this->repo->update_phase_settings( (int) $phase_id, $settings );
		}
		return $result;
	}

	public function handle_bulk_categories(): void {
		$this->guard( 'padelcamps_bulk_categories' );
		$tid  = absint( self::post_str( 'tournament_id' ) );
		$back = array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config' );
		if ( ! $tid || ! $this->repo->find( $tid ) ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$input = array();
		foreach ( array( 'consolation', 'advance', 'best_of', 'games', 'deciding', 'points', 'criteria', 'max_teams', 'price' ) as $key ) {
			$input[ $key ] = sanitize_text_field( self::post_str( 'b_' . $key ) );
		}
		$changes = CategoryBulk::changes( $input, self::criteria_presets() );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard(); cast to int.
		$ids = isset( $_POST['cats'] ) && is_array( $_POST['cats'] ) ? array_map( 'absint', wp_unslash( $_POST['cats'] ) ) : array();

		$updated = 0;
		$locked  = array();
		$na      = array();
		foreach ( $this->repo->categories( $tid ) as $cat ) {
			if ( ! in_array( (int) $cat['id'], $ids, true ) || ! CategoryBulk::has_changes( $changes ) ) {
				continue;
			}
			$res = $this->apply_phase_changes( $cat, $changes );
			$this->repo->update_category_numbers( (int) $cat['id'], $changes['max_teams'], $changes['price'] );
			$numbers = ( null !== $changes['max_teams'] && (int) $changes['max_teams'] !== (int) $cat['max_teams'] )
				|| ( null !== $changes['price'] && (float) $changes['price'] !== (float) $cat['price'] );
			if ( $res['settings'] || $numbers ) {
				++$updated;
			}
			if ( $res['locked'] ) {
				$locked[] = $cat['name'] . ' (' . implode( ', ', array_map( array( self::class, 'bulk_key_label' ), $res['locked'] ) ) . ')';
			}
			if ( $res['na'] ) {
				$na[] = $cat['name'] . ' (' . implode( ', ', array_map( array( self::class, 'bulk_key_label' ), $res['na'] ) ) . ')';
			}
		}
		$args = array( 'msg' => 'categories_bulk', 'bn' => $updated );
		if ( $locked ) {
			$args['bl'] = implode( '; ', $locked );
		}
		if ( $na ) {
			$args['bna'] = implode( '; ', $na );
		}
		$this->redirect( $back + $args );
	}

	public function handle_delete_category(): void {
		$this->guard( 'padelcamps_delete_category' );

		$tid      = absint( self::post_str( 'tournament_id' ) );
		$cid      = absint( self::post_str( 'category_id' ) );
		$category = $cid ? $this->repo->category( $cid ) : null;
		$back     = array( 'action' => 'edit', 'id' => $tid, 'tab' => 'config' );

		if ( ! $category || (int) $category['tournament_id'] !== $tid ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		if ( $this->repo->category_registrations_count( $cid ) > 0 ) {
			$this->redirect( $back + array( 'msg' => 'category_in_use' ) );
		}
		$this->repo->delete_category( $cid );
		$this->redirect( $back + array( 'msg' => 'category_deleted' ) );
	}
}
