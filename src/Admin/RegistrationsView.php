<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\CommsRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\Csv;
use PadelCamps\Engine\RegistrationStatus;
use PadelCamps\Frontend\RegistrationShortcode;
use PadelCamps\Service\Communication;

defined( 'ABSPATH' ) || exit;

/**
 * Registrations management, shown in two places with the same code:
 *  - PadelCamps > Inscripcions: every tournament (global inbox);
 *  - tournament workspace > Inscripcions tab: one tournament.
 * List with filters, bulk actions, detail screen, CSV export.
 */
final class RegistrationsView {

	public const GLOBAL_SLUG = 'padelcamps-registrations';
	private const PER_PAGE   = 25;

	private RegistrationRepository $regs;
	private TournamentRepository $tournaments;
	private RegistrationEditor $editor;

	public function __construct( RegistrationRepository $regs, TournamentRepository $tournaments ) {
		$this->regs        = $regs;
		$this->tournaments = $tournaments;
		$this->editor      = new RegistrationEditor( $regs, $tournaments );
	}

	public function register(): void {
		foreach ( array( 'reg_bulk', 'reg_save', 'reg_delete', 'reg_export' ) as $action ) {
			add_action( 'admin_post_padelcamps_' . $action, array( $this, 'handle_' . $action ) );
		}
		$this->editor->register();
	}

	// --- Labels (literal calls so the translation scanner finds them) -------

	public static function status_label( string $status ): string {
		$labels = array(
			'pending'   => __( 'Pendent', 'padelcamps' ),
			'confirmed' => __( 'Confirmada', 'padelcamps' ),
			'waitlist'  => __( 'Llista d’espera', 'padelcamps' ),
			'cancelled' => __( 'Cancel·lada', 'padelcamps' ),
		);
		return $labels[ $status ] ?? $status;
	}

	public static function payment_label( string $payment ): string {
		$labels = array(
			'pending'  => __( 'Pendent', 'padelcamps' ),
			'paid'     => __( 'Pagat', 'padelcamps' ),
			'refunded' => __( 'Reemborsat', 'padelcamps' ),
			'free'     => __( 'Gratuït', 'padelcamps' ),
		);
		return $labels[ $payment ] ?? $payment;
	}

	private static function bulk_actions(): array {
		return array(
			'confirm'  => __( 'Confirma', 'padelcamps' ),
			'pending'  => __( 'Passa a pendent', 'padelcamps' ),
			'waitlist' => __( 'Passa a llista d’espera', 'padelcamps' ),
			'cancel'   => __( 'Cancel·la', 'padelcamps' ),
			'paid'     => __( 'Marca com a pagat', 'padelcamps' ),
			'unpaid'   => __( 'Marca el pagament com a pendent', 'padelcamps' ),
			'refunded' => __( 'Marca com a reemborsat', 'padelcamps' ),
			'delete'   => __( 'Elimina', 'padelcamps' ),
		);
	}

	private static function badge( string $text, string $color ): string {
		return '<span class="pc-badge pc-badge--' . esc_attr( $color ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function status_badge( string $status ): string {
		$colors = array(
			'pending'   => 'warn',
			'confirmed' => 'ok',
			'waitlist'  => 'info',
			'cancelled' => 'muted',
		);
		return self::badge( self::status_label( $status ), $colors[ $status ] ?? 'muted' );
	}

	private static function payment_badge( string $payment ): string {
		$colors = array(
			'pending'  => 'warn',
			'paid'     => 'ok',
			'refunded' => 'bad',
			'free'     => 'muted',
		);
		return self::badge( self::payment_label( $payment ), $colors[ $payment ] ?? 'muted' );
	}

	private static function money( $amount ): string {
		return number_format_i18n( (float) $amount, 2 ) . ' €';
	}

	private static function when( ?string $mysql ): string {
		return $mysql ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql ) : '—';
	}

	// --- Context / URLs ---------------------------------------------------------

	/** @return array<string,mixed> query args that open this view. */
	private static function base( int $tournament_id ): array {
		if ( $tournament_id > 0 ) {
			return array(
				'page'   => TournamentsPage::SLUG,
				'action' => 'edit',
				'id'     => $tournament_id,
				'tab'    => 'registrations',
			);
		}
		return array( 'page' => self::GLOBAL_SLUG );
	}

	public static function url( int $tournament_id, array $extra = array() ): string {
		return add_query_arg( array_merge( self::base( $tournament_id ), $extra ), admin_url( 'admin.php' ) );
	}

	/** Filters from the query string (GET). */
	private function read_filters( int $fixed_tournament ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$f = array(
			'tournament_id'  => $fixed_tournament > 0 ? $fixed_tournament : ( isset( $_GET['tournament'] ) ? absint( $_GET['tournament'] ) : 0 ),
			'category_id'    => isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0,
			'status'         => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'payment_status' => isset( $_GET['pay'] ) ? sanitize_key( wp_unslash( $_GET['pay'] ) ) : '',
			's'              => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		// phpcs:enable
		return $f;
	}

	/** Filter values as URL args (only non-empty ones). */
	private static function filter_args( array $f ): array {
		$map = array(
			'tournament_id'  => 'tournament',
			'category_id'    => 'category',
			'status'         => 'status',
			'payment_status' => 'pay',
			's'              => 's',
		);
		$out = array();
		foreach ( $map as $key => $arg ) {
			if ( ! empty( $f[ $key ] ) ) {
				$out[ $arg ] = $f[ $key ];
			}
		}
		return $out;
	}

	// --- Entry points -----------------------------------------------------------

	public function render_global(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		echo '<div class="wrap pc-wrap"><h1>' . esc_html__( 'Inscripcions', 'padelcamps' ) . '</h1>';
		echo '<div class="pc-panel pc-panel--solo">';
		$this->render( 0 );
		echo '</div></div>';
	}

	public function render_tournament( array $tournament ): void {
		$this->render( (int) $tournament['id'] );
	}

	private function render( int $tid ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$reg_id = isset( $_GET['reg'] ) ? absint( $_GET['reg'] ) : 0;
		$this->render_notice();
		if ( isset( $_GET['reg'] ) && 'new' === $_GET['reg'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->editor->render_new( $tid );
			return;
		}
		if ( $reg_id ) {
			$this->render_detail( $reg_id, $tid );
			return;
		}
		$this->render_list( $tid );
	}

	// --- Notices ----------------------------------------------------------------

	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, whitelisted codes.
		$code  = isset( $_GET['rmsg'] ) ? sanitize_key( wp_unslash( $_GET['rmsg'] ) ) : '';
		$count = isset( $_GET['rn'] ) ? absint( $_GET['rn'] ) : 0;
		$extra = isset( $_GET['rc'] ) ? sanitize_text_field( wp_unslash( $_GET['rc'] ) ) : '';
		$mails = isset( $_GET['rm'] ) ? absint( $_GET['rm'] ) : 0;
		// phpcs:enable

		$type = 'success';
		switch ( $code ) {
			case 'updated':
				$text = $count > 0
					/* translators: %d: number of registrations */
					? sprintf( _n( '%d inscripció actualitzada.', '%d inscripcions actualitzades.', $count, 'padelcamps' ), $count )
					: __( 'No hi ha hagut cap canvi.', 'padelcamps' );
				break;
			case 'deleted':
				/* translators: %d: number of registrations */
				$text = sprintf( _n( '%d inscripció eliminada.', '%d inscripcions eliminades.', max( 1, $count ), 'padelcamps' ), max( 1, $count ) );
				break;
			case 'saved':
				$text = __( 'Inscripció desada.', 'padelcamps' );
				break;
			case 'added':
				$text = __( 'Inscripció afegida.', 'padelcamps' );
				break;
			case 'players_saved':
				$text = __( 'Dades dels jugadors desades.', 'padelcamps' );
				break;
			case 'none_selected':
				$type = 'error';
				$text = __( 'Tria almenys una inscripció.', 'padelcamps' );
				break;
			case 'not_found':
				$type = 'error';
				$text = __( 'No s’ha trobat la inscripció.', 'padelcamps' );
				break;
			default:
				return;
		}
		if ( $mails > 0 ) {
			/* translators: %d: number of emails */
			$text .= ' ' . sprintf( _n( 'S’ha enviat %d correu.', 'S’han enviat %d correus.', $mails, 'padelcamps' ), $mails );
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );

		if ( '' !== $extra ) {
			/* translators: %s: category names */
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( sprintf( __( 'Atenció: hi ha més inscripcions que places a: %s.', 'padelcamps' ), $extra ) ) );
		}
	}

	// --- List -------------------------------------------------------------------

	private function render_list( int $tid ): void {
		$f     = $this->read_filters( $tid );
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = $this->regs->count( $f );
		$rows  = $this->regs->search( $f, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$plrs  = $this->regs->players_for( wp_list_pluck( $rows, 'id' ) );

		echo '<div class="pc-head"><a class="button button-primary" href="' . esc_url( self::url( $tid, array( 'reg' => 'new' ) ) ) . '">' . esc_html__( 'Afegeix una inscripció', 'padelcamps' ) . '</a></div>';
		if ( $tid > 0 ) {
			$this->category_stats_table( $tid );
		}
		$this->summary_line( $this->regs->summary( $f ) );
		$this->filter_form( $tid, $f );

		if ( ! $rows ) {
			echo '<p class="pc-empty">' . esc_html__( 'No hi ha cap inscripció que coincideixi amb el filtre.', 'padelcamps' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pc-bulk-form">';
		echo '<input type="hidden" name="action" value="padelcamps_reg_bulk">';
		echo '<input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		foreach ( self::filter_args( $f ) as $key => $value ) {
			echo '<input type="hidden" name="f_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		wp_nonce_field( 'padelcamps_reg_bulk' );

		echo '<div class="pc-bulk"><label class="screen-reader-text" for="pc-bulk-action">' . esc_html__( 'Acció en bloc', 'padelcamps' ) . '</label>';
		echo '<select name="bulk" id="pc-bulk-action"><option value="">' . esc_html__( 'Accions en bloc', 'padelcamps' ) . '</option>';
		foreach ( self::bulk_actions() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'Aplica', 'padelcamps' ) . '</button> ';
		self::notify_checkbox();
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="pc-check-all" aria-label="' . esc_attr__( 'Selecciona-ho tot', 'padelcamps' ) . '"></td>';
		echo '<th class="pc-col-id">#</th>';
		echo '<th>' . esc_html__( 'Jugadors', 'padelcamps' ) . '</th>';
		if ( 0 === $tid ) {
			echo '<th>' . esc_html__( 'Torneig', 'padelcamps' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Pagament', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Data', 'padelcamps' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$players = $plrs[ $id ] ?? array();
			$detail  = self::url( $tid, array_merge( self::filter_args( $f ), array( 'reg' => $id ) ) );
			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( (string) $id ) . '"></th>';
			echo '<td>' . esc_html( (string) $id ) . '</td>';
			echo '<td><strong><a href="' . esc_url( $detail ) . '">';
			echo esc_html( implode( ' + ', wp_list_pluck( $players, 'name' ) ) ?: $row['contact_name'] );
			echo '</a></strong><br><span class="pc-muted">' . esc_html( (string) $row['contact_email'] ) . '</span></td>';
			if ( 0 === $tid ) {
				echo '<td>' . esc_html( $row['tournament_name'] ) . '</td>';
			}
			echo '<td>' . esc_html( $row['category_name'] ) . '</td>';
			echo '<td>' . self::status_badge( $row['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			echo '<td>' . self::payment_badge( $row['payment_status'] ) . ( (float) $row['amount'] > 0 ? ' ' . esc_html( self::money( $row['amount'] ) ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( self::when( $row['created_at'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></form>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%', self::url( $tid, self::filter_args( $f ) ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			echo '</div></div>';
		}

		// Select all + confirm before deleting.
		echo '<script>(function(){var a=document.getElementById("pc-check-all"),f=document.getElementById("pc-bulk-form");if(!f){return;}a.addEventListener("change",function(){f.querySelectorAll("input[name=\'ids[]\']").forEach(function(c){c.checked=a.checked;});});f.addEventListener("submit",function(e){var s=document.getElementById("pc-bulk-action").value;if(s==="delete"&&!confirm(' . wp_json_encode( __( 'Segur que vols eliminar les inscripcions seleccionades? Aquesta acció no es pot desfer.', 'padelcamps' ) ) . ')){e.preventDefault();}});})();</script>';
	}

	private function category_stats_table( int $tid ): void {
		$stats = $this->regs->category_stats( $tid );
		if ( ! $stats ) {
			return;
		}
		echo '<h2 class="pc-h2-first">' . esc_html__( 'Ocupació per categoria', 'padelcamps' ) . '</h2>';
		echo '<table class="widefat striped pc-narrow"><thead><tr><th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th><th>' . esc_html__( 'Places ocupades', 'padelcamps' ) . '</th><th>' . esc_html__( 'Llista d’espera', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $stats as $s ) {
			$max = null === $s['max_teams'] ? '∞' : (string) (int) $s['max_teams'];
			echo '<tr><td>' . esc_html( $s['name'] ) . '</td><td>' . esc_html( (int) $s['occupied'] . ' / ' . $max ) . '</td><td>' . esc_html( (string) (int) $s['waitlist'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function summary_line( array $s ): void {
		/* translators: 1: total, 2: pending, 3: confirmed, 4: waiting list, 5: cancelled */
		$counts = sprintf(
			__( '%1$d inscripcions · %2$d pendents · %3$d confirmades · %4$d en llista d’espera · %5$d cancel·lades', 'padelcamps' ),
			$s['count'],
			$s['status']['pending'],
			$s['status']['confirmed'],
			$s['status']['waitlist'],
			$s['status']['cancelled']
		);
		/* translators: 1: amount collected, 2: amount still to collect */
		$money = sprintf( __( 'Cobrat: %1$s · Pendent de cobrar: %2$s', 'padelcamps' ), self::money( $s['paid_total'] ), self::money( $s['due_total'] ) );
		echo '<div class="pc-summary"><strong>' . esc_html( $counts ) . '</strong><span>' . esc_html( $money ) . '</span></div>';
	}

	private function filter_form( int $tid, array $f ): void {
		echo '<form method="get" class="pc-toolbar">';
		foreach ( self::base( $tid ) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}

		if ( 0 === $tid ) {
			echo '<select name="tournament" aria-label="' . esc_attr__( 'Torneig', 'padelcamps' ) . '"><option value="0">' . esc_html__( 'Tots els tornejos', 'padelcamps' ) . '</option>';
			foreach ( $this->tournaments->all() as $t ) {
				printf( '<option value="%d"%s>%s</option>', (int) $t['id'], selected( (int) $f['tournament_id'], (int) $t['id'], false ), esc_html( $t['name'] ) );
			}
			echo '</select> ';
		}

		$categories = array();
		if ( $f['tournament_id'] > 0 ) {
			$categories = $this->tournaments->categories( (int) $f['tournament_id'] );
		}
		if ( $categories ) {
			echo '<select name="category" aria-label="' . esc_attr__( 'Categoria', 'padelcamps' ) . '"><option value="0">' . esc_html__( 'Totes les categories', 'padelcamps' ) . '</option>';
			foreach ( $categories as $c ) {
				printf( '<option value="%d"%s>%s</option>', (int) $c['id'], selected( (int) $f['category_id'], (int) $c['id'], false ), esc_html( $c['name'] ) );
			}
			echo '</select> ';
		}

		echo '<select name="status" aria-label="' . esc_attr__( 'Estat', 'padelcamps' ) . '"><option value="">' . esc_html__( 'Tots els estats', 'padelcamps' ) . '</option>';
		foreach ( RegistrationStatus::all() as $status ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $status ), selected( $f['status'], $status, false ), esc_html( self::status_label( $status ) ) );
		}
		echo '</select> ';

		echo '<select name="pay" aria-label="' . esc_attr__( 'Pagament', 'padelcamps' ) . '"><option value="">' . esc_html__( 'Tots els pagaments', 'padelcamps' ) . '</option>';
		foreach ( RegistrationStatus::payments() as $pay ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $pay ), selected( $f['payment_status'], $pay, false ), esc_html( self::payment_label( $pay ) ) );
		}
		echo '</select> ';

		printf( '<input type="search" name="s" value="%s" placeholder="%s" aria-label="%s"> ', esc_attr( $f['s'] ), esc_attr__( 'Cerca per nom o correu', 'padelcamps' ), esc_attr__( 'Cerca', 'padelcamps' ) );
		echo '<button class="button">' . esc_html__( 'Filtra', 'padelcamps' ) . '</button> ';

		$export = wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => 'padelcamps_reg_export' ), self::filter_args( $f ) ),
				admin_url( 'admin-post.php' )
			),
			'padelcamps_reg_export'
		);
		echo '<a class="button pc-toolbar__end" href="' . esc_url( $export ) . '">' . esc_html__( 'Exporta a CSV', 'padelcamps' ) . '</a>';
		echo '</form>';
	}

	// --- Detail -----------------------------------------------------------------

	private function render_detail( int $id, int $tid ): void {
		$r = $this->regs->find( $id );
		if ( ! $r || ( $tid > 0 && (int) $r['tournament_id'] !== $tid ) ) {
			echo '<p>' . esc_html__( 'No s’ha trobat la inscripció.', 'padelcamps' ) . '</p>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$back = self::url( $tid, self::filter_args( $this->read_filters( $tid ) ) );
		$a    = $r['answers'];

		echo '<p><a href="' . esc_url( $back ) . '">← ' . esc_html__( 'Totes les inscripcions', 'padelcamps' ) . '</a></p>';
		/* translators: %d: registration number */
		echo '<h2>' . esc_html( sprintf( __( 'Inscripció #%d', 'padelcamps' ), $id ) ) . ' ' . self::status_badge( $r['status'] ) . ' ' . self::payment_badge( $r['payment_status'] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<table class="widefat striped pc-narrow pc-narrow--wide"><tbody>';
		$this->kv( __( 'Torneig', 'padelcamps' ), $r['tournament_name'] );
		$this->kv( __( 'Categoria', 'padelcamps' ), $r['category_name'] );
		$this->kv( __( 'Data d’inscripció', 'padelcamps' ), self::when( $r['created_at'] ) );
		$this->kv( __( 'Import', 'padelcamps' ), self::money( $r['amount'] ) );
		if ( ! empty( $r['paid_at'] ) ) {
			$this->kv( __( 'Data de pagament', 'padelcamps' ), self::when( $r['paid_at'] ) );
		}
		if ( ! empty( $a['locale'] ) ) {
			$this->kv( __( 'Idioma', 'padelcamps' ), (string) $a['locale'] );
		}
		$this->kv( __( 'Origen', 'padelcamps' ), 'admin' === ( $a['source'] ?? '' ) ? __( 'Afegida per l’organització', 'padelcamps' ) : __( 'Formulari públic', 'padelcamps' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Jugadors', 'padelcamps' ) . '</h3>';
		echo '<table class="widefat striped pc-narrow pc-narrow--wide"><thead><tr><th></th>';
		foreach ( $r['players'] as $i => $p ) {
			echo '<th>' . esc_html( sprintf( __( 'Jugador/a %d', 'padelcamps' ), $i + 1 ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = array(
			__( 'Nom i cognoms', 'padelcamps' )   => static fn( $p ) => esc_html( $p['name'] ),
			__( 'Correu electrònic', 'padelcamps' ) => static fn( $p ) => $p['email'] ? '<a href="mailto:' . esc_attr( $p['email'] ) . '">' . esc_html( $p['email'] ) . '</a>' : '—',
			__( 'Telèfon', 'padelcamps' )         => static fn( $p ) => esc_html( $p['phone'] ?: '—' ),
			__( 'Club', 'padelcamps' )            => static fn( $p ) => esc_html( $p['club'] ?: '—' ),
		);
		foreach ( $rows as $label => $cell ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $r['players'] as $p ) {
				echo '<td>' . $cell( $p ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in each cell.
			}
			echo '</tr>';
		}
		// DNI and welcome pack answers live in the registration answers, per player.
		$answers_players = is_array( $a['players'] ?? null ) ? $a['players'] : array();
		echo '<tr><th scope="row">' . esc_html__( 'DNI o NIE', 'padelcamps' ) . '</th>';
		foreach ( $r['players'] as $i => $p ) {
			echo '<td>' . esc_html( ( $answers_players[ $i ]['dni'] ?? '' ) ?: '—' ) . '</td>';
		}
		echo '</tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Welcome pack', 'padelcamps' ) . '</th>';
		foreach ( $r['players'] as $i => $p ) {
			$pack  = is_array( $answers_players[ $i ]['pack'] ?? null ) ? $answers_players[ $i ]['pack'] : array();
			$lines = array();
			foreach ( $pack as $item ) {
				$lines[] = '<strong>' . esc_html( (string) ( $item['label'] ?? '' ) ) . ':</strong> ' . esc_html( '' !== ( $item['value'] ?? '' ) ? (string) $item['value'] : '—' );
			}
			// Registrations made before the free-form welcome pack stored a shirt size.
			if ( ! empty( $answers_players[ $i ]['shirt'] ) ) {
				$lines[] = '<strong>' . esc_html__( 'Talla de dessuadora', 'padelcamps' ) . ':</strong> ' . esc_html( (string) $answers_players[ $i ]['shirt'] );
			}
			echo '<td>' . ( $lines ? implode( '<br>', $lines ) : '—' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</tr></tbody></table>';
		$this->editor->render_players_form( $r, $tid );

		if ( ! empty( $a['availability'] ) && is_array( $a['availability'] ) ) {
			echo '<h3>' . esc_html__( 'Disponibilitat', 'padelcamps' ) . '</h3><table class="widefat striped pc-narrow pc-narrow--wide"><tbody>';
			foreach ( $a['availability'] as $slot ) {
				$title = (string) ( $slot['date'] ?? '' );
				if ( ! empty( $slot['label'] ) ) {
					$title .= ' — ' . $slot['label'];
				}
				$this->kv( $title, RegistrationShortcode::availability_label( (string) ( $slot['value'] ?? '' ) ) );
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $a['notes'] ) ) {
			echo '<h3>' . esc_html__( 'Observacions', 'padelcamps' ) . '</h3><p>' . nl2br( esc_html( (string) $a['notes'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.
		}

		if ( ! empty( $a['consents'] ) && is_array( $a['consents'] ) ) {
			$labels = FormTab::consent_labels();
			$given  = array();
			foreach ( array_keys( $a['consents'] ) as $key ) {
				$given[] = $labels[ $key ] ?? $key;
			}
			echo '<h3>' . esc_html__( 'Consentiments', 'padelcamps' ) . '</h3><p>' . esc_html( implode( ', ', $given ) );
			if ( ! empty( $a['consented_at'] ) ) {
				echo ' — ' . esc_html( self::when( (string) $a['consented_at'] ) );
			}
			echo '</p>';
		}

		$this->detail_form( $r, $tid );
	}

	private function kv( string $label, string $value ): void {
		echo '<tr><th scope="row" class="pc-key">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function detail_form( array $r, int $tid ): void {
		$id = (int) $r['id'];
		echo '<h3>' . esc_html__( 'Gestió', 'padelcamps' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_reg_save">';
		echo '<input type="hidden" name="reg" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_reg_save' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="pc-r-status">' . esc_html__( 'Estat', 'padelcamps' ) . '</label></th><td><select id="pc-r-status" name="status">';
		foreach ( RegistrationStatus::all() as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $r['status'], $s, false ), esc_html( self::status_label( $s ) ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="pc-r-pay">' . esc_html__( 'Pagament', 'padelcamps' ) . '</label></th><td>';
		if ( (float) $r['amount'] > 0 ) {
			echo '<select id="pc-r-pay" name="payment">';
			foreach ( array( 'pending', 'paid', 'refunded' ) as $p ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $p ), selected( $r['payment_status'], $p, false ), esc_html( self::payment_label( $p ) ) );
			}
			echo '</select>';
		} else {
			echo esc_html( self::payment_label( 'free' ) );
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="pc-r-rating">' . esc_html__( 'Puntuació', 'padelcamps' ) . '</label></th><td>';
		printf( '<input type="number" step="0.01" min="0" max="99.99" id="pc-r-rating" name="rating" value="%s" class="small-text"><p class="description">%s</p></td></tr>', esc_attr( null === $r['rating'] ? '' : (string) $r['rating'] ), esc_html__( 'Nivell de la parella. S’usarà per ordenar els caps de sèrie al sorteig.', 'padelcamps' ) );

		echo '<tr><th scope="row"><label for="pc-r-notes">' . esc_html__( 'Notes de l’organització', 'padelcamps' ) . '</label></th><td>';
		printf( '<textarea id="pc-r-notes" name="notes" rows="4" class="large-text">%s</textarea><p class="description">%s</p></td></tr>', esc_textarea( (string) $r['notes'] ), esc_html__( 'Només les veu l’organització.', 'padelcamps' ) );
		echo '</table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Desa els canvis', 'padelcamps' ) . '</button> ';
		self::notify_checkbox();
		echo '</p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Segur que vols eliminar aquesta inscripció i les dades dels jugadors? Aquesta acció no es pot desfer.', 'padelcamps' ) ) ) . ');">';
		echo '<input type="hidden" name="action" value="padelcamps_reg_delete">';
		echo '<input type="hidden" name="reg" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_reg_delete' );
		echo '<button class="button button-link-delete">' . esc_html__( 'Elimina la inscripció', 'padelcamps' ) . '</button></form>';
	}

	/** "Email the players" checkbox next to status/payment actions (on by default). */
	private static function notify_checkbox(): void {
		echo '<label class="pc-notify"><input type="checkbox" name="notify" value="1" checked> ' . esc_html__( 'Avisa els jugadors per correu', 'padelcamps' ) . '</label>';
		echo ' <span class="description">' . esc_html__( '(confirmació, llista d’espera, cancel·lació i pagament rebut, segons la pestanya Comunicació del torneig)', 'padelcamps' ) . '</span>';
	}

	/**
	 * Emails after a status/payment change, if the organizer left "notify" on.
	 *
	 * @return int emails sent
	 */
	private function notify( array $before, string $status = '', bool $payment = false ): int {
		if ( '1' !== self::post_str( 'notify' ) || ! $before ) {
			return 0;
		}
		$comms = new Communication();
		$sent  = '' !== $status ? $comms->after_status_change( $before, $status ) : 0;
		return $sent + ( $payment ? $comms->after_payment( $before ) : 0 );
	}

	// --- Handlers ---------------------------------------------------------------

	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( $action );
	}

	private static function post_str( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
	}

	private function go( int $tid, array $extra ): void {
		wp_safe_redirect( self::url( $tid, $extra ) );
		exit;
	}

	public function handle_reg_bulk(): void {
		$this->guard( 'padelcamps_reg_bulk' );

		$tid    = absint( self::post_str( 'tid' ) );
		$bulk   = sanitize_key( self::post_str( 'bulk' ) );
		$keep   = array();
		foreach ( array( 'tournament', 'category', 'status', 'pay', 's' ) as $arg ) {
			$value = self::post_str( 'f_' . $arg );
			if ( '' !== $value ) {
				$keep[ $arg ] = sanitize_text_field( $value );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in guard(); ids are cast to int.
		$raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$ids = $this->regs->filter_ids( $raw, $tid );
		if ( ! $ids || '' === $bulk ) {
			$this->go( $tid, $keep + array( 'rmsg' => 'none_selected' ) );
		}

		$changed = 0;
		$check   = false;
		$mails   = 0;
		$before  = ( new CommsRepository() )->states( $ids );
		switch ( $bulk ) {
			case 'confirm':
				$changed = $this->regs->set_status( $ids, RegistrationStatus::CONFIRMED );
				$check   = true;
				$mails   = $this->notify( $before, RegistrationStatus::CONFIRMED );
				break;
			case 'pending':
				$changed = $this->regs->set_status( $ids, RegistrationStatus::PENDING );
				$check   = true;
				break;
			case 'waitlist':
				$changed = $this->regs->set_status( $ids, RegistrationStatus::WAITLIST );
				$mails   = $this->notify( $before, RegistrationStatus::WAITLIST );
				break;
			case 'cancel':
				$changed = $this->regs->set_status( $ids, RegistrationStatus::CANCELLED );
				$mails   = $this->notify( $before, RegistrationStatus::CANCELLED );
				break;
			case 'paid':
				$changed = $this->regs->set_payment( $ids, RegistrationStatus::PAYMENT_PAID );
				$mails   = $this->notify( $before, '', true );
				break;
			case 'unpaid':
				$changed = $this->regs->set_payment( $ids, RegistrationStatus::PAYMENT_PENDING );
				break;
			case 'refunded':
				$changed = $this->regs->set_payment( $ids, RegistrationStatus::PAYMENT_REFUNDED );
				break;
			case 'delete':
				$count = $this->regs->delete( $ids );
				$this->go( $tid, $keep + array( 'rmsg' => 'deleted', 'rn' => $count ) );
				break;
			default:
				$this->go( $tid, $keep + array( 'rmsg' => 'none_selected' ) );
		}

		$args = $keep + array( 'rmsg' => 'updated', 'rn' => $changed );
		if ( $mails ) {
			$args['rm'] = $mails;
		}
		if ( $check ) {
			$over = $this->regs->over_capacity_categories( $ids );
			if ( $over ) {
				$args['rc'] = implode( ', ', $over );
			}
		}
		$this->go( $tid, $args );
	}

	public function handle_reg_save(): void {
		$this->guard( 'padelcamps_reg_save' );

		$tid = absint( self::post_str( 'tid' ) );
		$id  = absint( self::post_str( 'reg' ) );
		$ids = $this->regs->filter_ids( array( $id ), $tid );
		if ( ! $ids ) {
			$this->go( $tid, array( 'rmsg' => 'not_found' ) );
		}

		$before = ( new CommsRepository() )->states( $ids );
		$status = sanitize_key( self::post_str( 'status' ) );
		if ( RegistrationStatus::is_valid( $status ) ) {
			$this->regs->set_status( $ids, $status );
		}
		$payment = sanitize_key( self::post_str( 'payment' ) );
		if ( in_array( $payment, array( 'pending', 'paid', 'refunded' ), true ) ) {
			$this->regs->set_payment( $ids, $payment );
		}
		$mails = $this->notify( $before, RegistrationStatus::is_valid( $status ) ? $status : '', 'paid' === $payment );

		$rating_raw = trim( str_replace( ',', '.', self::post_str( 'rating' ) ) );
		$rating     = '' !== $rating_raw && is_numeric( $rating_raw ) ? max( 0.0, min( 99.99, round( (float) $rating_raw, 2 ) ) ) : null;
		$this->regs->update_details( $id, $rating, sanitize_textarea_field( self::post_str( 'notes' ) ) );

		$args = array( 'reg' => $id, 'rmsg' => 'saved' );
		if ( $mails ) {
			$args['rm'] = $mails;
		}
		$over = in_array( $status, array( RegistrationStatus::CONFIRMED, RegistrationStatus::PENDING ), true ) ? $this->regs->over_capacity_categories( $ids ) : array();
		if ( $over ) {
			$args['rc'] = implode( ', ', $over );
		}
		$this->go( $tid, $args );
	}

	public function handle_reg_delete(): void {
		$this->guard( 'padelcamps_reg_delete' );

		$tid = absint( self::post_str( 'tid' ) );
		$ids = $this->regs->filter_ids( array( absint( self::post_str( 'reg' ) ) ), $tid );
		if ( ! $ids ) {
			$this->go( $tid, array( 'rmsg' => 'not_found' ) );
		}
		$count = $this->regs->delete( $ids );
		$this->go( $tid, array( 'rmsg' => 'deleted', 'rn' => $count ) );
	}

	/** CSV download of the registrations that match the current filters. */
	public function handle_reg_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_reg_export' );

		$f = $this->read_filters( 0 ); // Same GET args as the list: tournament, category, status, pay, s.

		$rows = array();
		for ( $offset = 0; ; $offset += 200 ) {
			$chunk = $this->regs->search( $f, 200, $offset );
			if ( ! $chunk ) {
				break;
			}
			$players = $this->regs->players_for( wp_list_pluck( $chunk, 'id' ) );
			foreach ( $chunk as $row ) {
				$answers         = json_decode( (string) $row['answers'], true );
				$row['answers']  = is_array( $answers ) ? $answers : array();
				$row['players']  = $players[ (int) $row['id'] ] ?? array();
				$rows[]          = $row;
			}
			if ( count( $chunk ) < 200 ) {
				break;
			}
		}

		// Welcome pack columns: every distinct option label, once per player.
		$pack_labels = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['answers']['players'] ?? array() ) as $pl ) {
				foreach ( (array) ( $pl['pack'] ?? array() ) as $item ) {
					$label = (string) ( $item['label'] ?? '' );
					if ( '' !== $label && ! in_array( $label, $pack_labels, true ) ) {
						$pack_labels[] = $label;
					}
				}
			}
		}

		$player_n = static fn( int $n ): string => sprintf( __( 'Jugador/a %d', 'padelcamps' ), $n );
		$header   = array(
			'ID',
			__( 'Torneig', 'padelcamps' ),
			__( 'Categoria', 'padelcamps' ),
			__( 'Estat', 'padelcamps' ),
			__( 'Pagament', 'padelcamps' ),
			__( 'Import (€)', 'padelcamps' ),
			__( 'Data de pagament', 'padelcamps' ),
			__( 'Data d’inscripció', 'padelcamps' ),
		);
		foreach ( array( 1, 2 ) as $n ) {
			foreach ( array( __( 'Nom i cognoms', 'padelcamps' ), __( 'Correu electrònic', 'padelcamps' ), __( 'Telèfon', 'padelcamps' ), __( 'Club', 'padelcamps' ), __( 'DNI o NIE', 'padelcamps' ) ) as $col ) {
				$header[] = $player_n( $n ) . ' – ' . $col;
			}
		}
		foreach ( $pack_labels as $label ) {
			foreach ( array( 1, 2 ) as $n ) {
				$header[] = $player_n( $n ) . ' – ' . $label;
			}
		}
		array_push(
			$header,
			__( 'Observacions', 'padelcamps' ),
			__( 'Disponibilitat', 'padelcamps' ),
			__( 'Consentiments', 'padelcamps' ),
			__( 'Notes de l’organització', 'padelcamps' ),
			__( 'Puntuació', 'padelcamps' )
		);

		$consent_labels = FormTab::consent_labels();
		$slug           = 'tots';
		if ( $f['tournament_id'] > 0 ) {
			$t    = $this->tournaments->find( (int) $f['tournament_id'] );
			$slug = $t ? $t['slug'] : $slug;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'inscripcions-' . $slug . '-' . gmdate( 'Y-m-d' ) . '.csv' ) . '"' );

		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads accents correctly.
		echo Csv::row( $header ); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV output, cells are neutralized by Csv::cell().

		foreach ( $rows as $row ) {
			$players = $row['players'];
			$ap      = (array) ( $row['answers']['players'] ?? array() );
			$line    = array(
				$row['id'],
				$row['tournament_name'],
				$row['category_name'],
				self::status_label( $row['status'] ),
				self::payment_label( $row['payment_status'] ),
				number_format( (float) $row['amount'], 2, '.', '' ),
				$row['paid_at'] ?? '',
				$row['created_at'],
			);
			foreach ( array( 0, 1 ) as $i ) {
				$p      = $players[ $i ] ?? array();
				$line[] = $p['name'] ?? '';
				$line[] = $p['email'] ?? '';
				$line[] = $p['phone'] ?? '';
				$line[] = $p['club'] ?? '';
				$line[] = $ap[ $i ]['dni'] ?? '';
			}
			foreach ( $pack_labels as $label ) {
				foreach ( array( 0, 1 ) as $i ) {
					$value = '';
					foreach ( (array) ( $ap[ $i ]['pack'] ?? array() ) as $item ) {
						if ( ( $item['label'] ?? '' ) === $label ) {
							$value = (string) ( $item['value'] ?? '' );
						}
					}
					$line[] = $value;
				}
			}
			$slots = array();
			foreach ( (array) ( $row['answers']['availability'] ?? array() ) as $slot ) {
				$slots[] = trim( ( $slot['date'] ?? '' ) . ' ' . ( $slot['label'] ?? '' ) ) . ': ' . RegistrationShortcode::availability_label( (string) ( $slot['value'] ?? '' ) );
			}
			$consents = array();
			foreach ( array_keys( (array) ( $row['answers']['consents'] ?? array() ) ) as $key ) {
				$consents[] = $consent_labels[ $key ] ?? $key;
			}
			array_push(
				$line,
				$row['answers']['notes'] ?? '',
				implode( ' | ', $slots ),
				implode( ', ', $consents ),
				$row['notes'] ?? '',
				$row['rating'] ?? ''
			);
			echo Csv::row( $line ); // phpcs:ignore WordPress.Security.EscapeOutput -- see above.
		}
		exit;
	}
}
