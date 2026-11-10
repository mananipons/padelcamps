<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\StatsRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\DashboardStats;
use PadelCamps\Engine\ScheduleDigest;
use PadelCamps\Engine\TournamentStatus;
use PadelCamps\Service\Communication;
use PadelCamps\Service\DateText;

defined( 'ABSPATH' ) || exit;

/**
 * PadelCamps > Tauler: the active tournament (or the one chosen) with its key
 * numbers in large type, what needs doing, progress per category, and totals
 * for all tournaments.
 */
final class Dashboard {

	private TournamentRepository $tournaments;
	private StatsRepository $stats;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
		$this->stats       = new StatsRepository();
	}

	private static function money( float $amount ): string {
		return number_format_i18n( $amount, 0 === (int) round( fmod( $amount * 100, 100 ) ) ? 0 : 2 ) . ' €';
	}

	private static function tab_url( int $tid, string $tab, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => $tab ), $extra ) );
	}

	/** One big number. $bar = percentage for a progress bar, or null. */
	private static function kpi( string $label, string $value, string $sub = '', ?int $bar = null, string $url = '', string $tone = '' ): void {
		$tag = '' !== $url ? 'a' : 'div';
		echo '<' . $tag . ' class="pc-kpi' . ( '' !== $tone ? ' pc-kpi--' . esc_attr( $tone ) : '' ) . '"' . ( '' !== $url ? ' href="' . esc_url( $url ) . '"' : '' ) . '>';
		echo '<span class="pc-kpi__label">' . esc_html( $label ) . '</span>';
		echo '<span class="pc-kpi__value">' . esc_html( $value ) . '</span>';
		if ( '' !== $sub ) {
			echo '<span class="pc-kpi__sub">' . esc_html( $sub ) . '</span>';
		}
		if ( null !== $bar ) {
			echo '<span class="pc-bar" role="presentation"><span style="width:' . (int) $bar . '%"></span></span>';
		}
		echo '</' . $tag . '>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all = $this->tournaments->all();
		echo '<div class="wrap pc-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Tauler', 'padelcamps' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		if ( ! $all ) {
			echo '<div class="pc-panel pc-panel--solo pc-empty-state"><h2>' . esc_html__( 'Encara no hi ha cap torneig', 'padelcamps' ) . '</h2>';
			echo '<p>' . esc_html__( 'Crea el primer torneig, afegeix-hi categories i publica el formulari d’inscripció.', 'padelcamps' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( TournamentsPage::url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Afegeix un torneig', 'padelcamps' ) . '</a></p></div></div>';
			return;
		}

		echo '<div class="pc-panel pc-panel--solo pc-dash">';
		$today  = current_time( 'Y-m-d' );
		$chosen = isset( $_GET['t'] ) ? absint( $_GET['t'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$ids    = array_map( 'intval', array_column( $all, 'id' ) );
		$tid    = in_array( $chosen, $ids, true ) ? $chosen : (int) DashboardStats::pick_active( $all, $today );
		$t      = $this->tournaments->find( $tid );

		// Tournament switcher.
		if ( count( $all ) > 1 ) {
			echo '<form method="get" class="pc-dash-switch"><input type="hidden" name="page" value="' . esc_attr( Menu::SLUG ) . '">';
			echo '<label for="pc-dash-t">' . esc_html__( 'Torneig', 'padelcamps' ) . '</label> <select id="pc-dash-t" name="t" onchange="this.form.submit()">';
			foreach ( $all as $row ) {
				printf( '<option value="%d"%s>%s — %s</option>', (int) $row['id'], selected( $tid, (int) $row['id'], false ), esc_html( $row['name'] ), esc_html( TournamentsPage::status_label( $row['status'] ) ) );
			}
			echo '</select><noscript><button class="button">' . esc_html__( 'Mostra', 'padelcamps' ) . '</button></noscript></form>';
		}

		$s = $this->stats->tournament( $tid, $today, current_time( 'mysql' ) );
		$this->hero( $t, $today );
		$this->kpis( $t, $s );
		$this->todo( $t, $s );
		$this->categories( $t, $s );
		$this->totals();
		echo '</div></div>';
	}

	private function hero( array $t, string $today ): void {
		$tid  = (int) $t['id'];
		$cd   = DashboardStats::countdown( $t['starts_on'], $t['ends_on'], $today );
		$when = array();
		if ( $t['starts_on'] ) {
			$when[] = mysql2date( get_option( 'date_format' ), $t['starts_on'] ) . ( $t['ends_on'] && $t['ends_on'] !== $t['starts_on'] ? ' – ' . mysql2date( get_option( 'date_format' ), $t['ends_on'] ) : '' );
		}
		if ( $t['venue'] ) {
			$when[] = $t['venue'];
		}
		switch ( $cd['state'] ) {
			case 'before':
				/* translators: %d: days */
				$count = 0 === $cd['days'] ? __( 'Comença avui', 'padelcamps' ) : sprintf( _n( 'Falta %d dia', 'Falten %d dies', $cd['days'], 'padelcamps' ), $cd['days'] );
				break;
			case 'during':
				/* translators: 1: day number, 2: number of days */
				$count = $cd['total'] > 1 ? sprintf( __( 'En joc · dia %1$d de %2$d', 'padelcamps' ), $cd['day'], $cd['total'] ) : __( 'Es juga avui', 'padelcamps' );
				break;
			case 'after':
				/* translators: %d: days */
				$count = sprintf( _n( 'Va acabar fa %d dia', 'Va acabar fa %d dies', max( 1, $cd['days'] ), 'padelcamps' ), max( 1, $cd['days'] ) );
				break;
			default:
				$count = __( 'Sense dates', 'padelcamps' );
		}

		echo '<section class="pc-hero">';
		echo '<div><p class="pc-hero__eyebrow">' . esc_html( TournamentsPage::status_label( $t['status'] ) ) . '</p>';
		echo '<h2 class="pc-hero__title">' . esc_html( $t['name'] ) . '</h2>';
		if ( $when ) {
			echo '<p class="pc-hero__meta">' . esc_html( implode( ' · ', $when ) ) . '</p>';
		}
		echo '</div><div class="pc-hero__side"><span class="pc-hero__count">' . esc_html( $count ) . '</span>';
		echo '<a class="button button-primary" href="' . esc_url( TournamentsPage::url( array( 'action' => 'edit', 'id' => $tid ) ) ) . '">' . esc_html__( 'Obre el torneig', 'padelcamps' ) . '</a></div>';
		echo '</section>';
	}

	private function kpis( array $t, array $s ): void {
		$tid = (int) $t['id'];
		echo '<div class="pc-kpis">';

		$occupied = $s['confirmed'] + $s['pending'];
		if ( $s['unlimited'] || 0 === $s['capacity'] ) {
			$sub = __( 'Sense límit de places', 'padelcamps' );
			$bar = null;
		} else {
			$bar = DashboardStats::percent( $occupied, $s['capacity'] );
			/* translators: 1: occupied places, 2: total places, 3: percentage */
			$sub = sprintf( __( '%1$d de %2$d places ocupades · %3$d%%', 'padelcamps' ), $occupied, $s['capacity'], $bar );
		}
		self::kpi( __( 'Parelles confirmades', 'padelcamps' ), (string) $s['confirmed'], $sub, $bar, self::tab_url( $tid, 'registrations' ) );

		/* translators: %d: number of registrations */
		self::kpi( __( 'Jugadors', 'padelcamps' ), (string) $s['players'], sprintf( _n( '%d inscripció activa', '%d inscripcions actives', $occupied + $s['waitlist'], 'padelcamps' ), $occupied + $s['waitlist'] ) );

		/* translators: %d: number on the waiting list */
		self::kpi( __( 'Pendents de confirmar', 'padelcamps' ), (string) $s['pending'], sprintf( __( '%d a la llista d’espera', 'padelcamps' ), $s['waitlist'] ), null, self::tab_url( $tid, 'registrations', array( 'status' => 'pending' ) ), $s['pending'] > 0 ? 'warn' : '' );

		/* translators: %s: amount */
		self::kpi( __( 'Cobrat', 'padelcamps' ), self::money( $s['paid'] ), sprintf( __( 'Pendent de cobrar: %s', 'padelcamps' ), self::money( $s['unpaid'] ) ), null, self::tab_url( $tid, 'registrations', array( 'pay' => 'pending' ) ) );

		$progress = DashboardStats::percent( $s['played'], $s['matches'] );
		/* translators: 1: total matches, 2: percentage */
		self::kpi( __( 'Partits jugats', 'padelcamps' ), (string) $s['played'], $s['matches'] ? sprintf( __( 'de %1$d · %2$d%% del torneig', 'padelcamps' ), $s['matches'], $progress ) : __( 'Encara no hi ha sorteig', 'padelcamps' ), $s['matches'] ? $progress : null, self::tab_url( $tid, 'draw' ) );

		if ( $s['next'] ) {
			$next = DateText::day_time( (string) $s['next']['scheduled_at'] ) . ( $s['next']['court_name'] ? ' · ' . $s['next']['court_name'] : '' );
			/* translators: %s: day, time and court */
			$sub = sprintf( __( 'Pròxim: %s', 'padelcamps' ), $next );
		} else {
			$sub = $s['matches'] ? __( 'No queda cap partit programat', 'padelcamps' ) : '';
		}
		/* translators: %d: matches already played today */
		self::kpi( __( 'Partits avui', 'padelcamps' ), (string) $s['today'], $s['today'] ? sprintf( __( '%d jugats', 'padelcamps' ), $s['today_played'] ) . ( '' !== $sub ? ' · ' . $sub : '' ) : $sub, null, self::tab_url( $tid, 'schedule' ) );

		$review = $s['reports_pending'] + $s['reports_disputed'];
		/* translators: %d: disputed results */
		self::kpi( __( 'Resultats per revisar', 'padelcamps' ), (string) $review, sprintf( _n( '%d en disputa', '%d en disputa', $s['reports_disputed'], 'padelcamps' ), $s['reports_disputed'] ), null, self::tab_url( $tid, 'results' ), $s['reports_disputed'] > 0 ? 'alert' : '' );

		/* translators: %d: failed emails */
		self::kpi( __( 'Correus enviats', 'padelcamps' ), (string) $s['emails_sent'], $s['emails_failed'] ? sprintf( _n( '%d no s’ha pogut enviar', '%d no s’han pogut enviar', $s['emails_failed'], 'padelcamps' ), $s['emails_failed'] ) : __( 'Cap error', 'padelcamps' ), null, self::tab_url( $tid, 'communication' ), $s['emails_failed'] ? 'alert' : '' );

		echo '</div>';
	}

	/** Things the organizer should look at, with a link to each. */
	private function todo( array $t, array $s ): void {
		$tid   = (int) $t['id'];
		$items = array();
		if ( $s['pending'] ) {
			/* translators: %d: registrations */
			$items[] = array( sprintf( _n( '%d inscripció pendent de confirmar', '%d inscripcions pendents de confirmar', $s['pending'], 'padelcamps' ), $s['pending'] ), self::tab_url( $tid, 'registrations', array( 'status' => 'pending' ) ) );
		}
		if ( $s['unpaid_confirmed'] ) {
			/* translators: %d: pairs */
			$items[] = array( sprintf( _n( '%d parella confirmada encara no ha pagat', '%d parelles confirmades encara no han pagat', $s['unpaid_confirmed'], 'padelcamps' ), $s['unpaid_confirmed'] ), self::tab_url( $tid, 'registrations', array( 'status' => 'confirmed', 'pay' => 'pending' ) ) );
		}
		if ( $s['reports_disputed'] ) {
			/* translators: %d: results */
			$items[] = array( sprintf( _n( '%d resultat en disputa', '%d resultats en disputa', $s['reports_disputed'], 'padelcamps' ), $s['reports_disputed'] ), self::tab_url( $tid, 'results' ) );
		}
		$drawable = in_array( $t['status'], array( TournamentStatus::REGISTRATION_CLOSED, TournamentStatus::DRAWN, TournamentStatus::IN_PROGRESS ), true );
		foreach ( $s['categories'] as $c ) {
			if ( $drawable && 0 === (int) $c['matches'] && (int) $c['confirmed'] >= 2 ) {
				/* translators: %s: category */
				$items[] = array( sprintf( __( 'Falta fer el sorteig de %s', 'padelcamps' ), $c['name'] ), self::tab_url( $tid, 'draw', array( 'cat' => (int) $c['id'] ) ) );
			}
		}
		if ( $s['unscheduled'] && $s['matches'] ) {
			/* translators: %d: matches */
			$items[] = array( sprintf( _n( '%d partit sense dia ni hora', '%d partits sense dia ni hora', $s['unscheduled'], 'padelcamps' ), $s['unscheduled'] ), self::tab_url( $tid, 'schedule' ) );
		}
		if ( $s['matches'] > $s['played'] ) {
			$changed = 0;
			foreach ( ( new Communication() )->schedule_overview( $t ) as $row ) {
				$changed += ScheduleDigest::needs_sending( $row['state'] ) ? 1 : 0;
			}
			if ( $changed ) {
				/* translators: %d: pairs */
				$items[] = array( sprintf( _n( '%d parella no té els horaris al dia per correu', '%d parelles no tenen els horaris al dia per correu', $changed, 'padelcamps' ), $changed ), self::tab_url( $tid, 'communication' ) );
			}
		}
		if ( TournamentStatus::DRAFT === $t['status'] ) {
			$items[] = array( __( 'El torneig és un esborrany: obre les inscripcions quan estigui a punt', 'padelcamps' ), TournamentsPage::url( array( 'action' => 'edit', 'id' => $tid ) ) );
		}

		echo '<section class="pc-dash-block"><h2>' . esc_html__( 'Per fer', 'padelcamps' ) . '</h2>';
		if ( ! $items ) {
			echo '<p class="pc-muted">' . esc_html__( 'Tot al dia.', 'padelcamps' ) . '</p></section>';
			return;
		}
		echo '<ul class="pc-todo">';
		foreach ( $items as $item ) {
			echo '<li><a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a></li>';
		}
		echo '</ul></section>';
	}

	private function categories( array $t, array $s ): void {
		if ( ! $s['categories'] ) {
			return;
		}
		echo '<section class="pc-dash-block"><h2>' . esc_html__( 'Per categoria', 'padelcamps' ) . '</h2>';
		echo '<table class="widefat striped pc-dash-table"><thead><tr><th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th><th>' . esc_html__( 'Parelles', 'padelcamps' ) . '</th><th>' . esc_html__( 'Pendents', 'padelcamps' ) . '</th><th>' . esc_html__( 'Llista d’espera', 'padelcamps' ) . '</th><th>' . esc_html__( 'Partits', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $s['categories'] as $c ) {
			$max     = null === $c['max_teams'] ? '∞' : (string) (int) $c['max_teams'];
			$fill    = null === $c['max_teams'] ? null : DashboardStats::percent( (int) $c['confirmed'] + (int) $c['pending'], (int) $c['max_teams'] );
			$matches = (int) $c['matches'];
			$pct     = DashboardStats::percent( (int) $c['played'], $matches );
			echo '<tr><td><strong>' . esc_html( $c['name'] ) . '</strong><br><span class="pc-muted">' . esc_html( TournamentsPage::template_label( $c['format'] ) ) . '</span></td>';
			echo '<td>' . (int) $c['confirmed'] . ' / ' . esc_html( $max ) . ( null !== $fill ? '<span class="pc-bar pc-bar--sm"><span style="width:' . (int) $fill . '%"></span></span>' : '' ) . '</td>';
			echo '<td>' . (int) $c['pending'] . '</td><td>' . (int) $c['waitlist'] . '</td>';
			echo '<td>' . ( $matches ? (int) $c['played'] . ' / ' . $matches . '<span class="pc-bar pc-bar--sm"><span style="width:' . (int) $pct . '%"></span></span>' : '<span class="pc-muted">' . esc_html__( 'Sense sorteig', 'padelcamps' ) . '</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private function totals(): void {
		$g = $this->stats->global();
		echo '<section class="pc-dash-block"><h2>' . esc_html__( 'Tots els tornejos', 'padelcamps' ) . '</h2><div class="pc-kpis pc-kpis--sm">';
		/* translators: %d: tournaments open or being played */
		self::kpi( __( 'Tornejos', 'padelcamps' ), (string) $g['tournaments'], sprintf( _n( '%d en marxa', '%d en marxa', $g['live'], 'padelcamps' ), $g['live'] ), null, TournamentsPage::url() );
		self::kpi( __( 'Inscripcions', 'padelcamps' ), (string) $g['registrations'], '', null, RegistrationsView::url( 0 ) );
		self::kpi( __( 'Jugadors diferents', 'padelcamps' ), (string) $g['players'] );
		self::kpi( __( 'Clubs', 'padelcamps' ), (string) $g['clubs'] );
		self::kpi( __( 'Partits jugats', 'padelcamps' ), (string) $g['played'] );
		self::kpi( __( 'Total cobrat', 'padelcamps' ), self::money( $g['paid'] ) );
		echo '</div></section>';
	}
}
