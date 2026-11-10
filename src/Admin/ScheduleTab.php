<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\ScheduleRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\RegistrationForm;
use PadelCamps\Engine\Scheduler;
use PadelCamps\Service\ScheduleService;

defined( 'ABSPATH' ) || exit;

/**
 * "Calendari i pistes" tab: courts, playing windows, automatic scheduling,
 * manual edits and conflict detection for the whole tournament.
 */
final class ScheduleTab {

	private TournamentRepository $tournaments;
	private ScheduleRepository $schedule;
	private ScheduleService $service;
	private RegistrationRepository $regs;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
		$this->schedule    = new ScheduleRepository();
		$this->service     = new ScheduleService( $tournaments, $this->schedule );
		$this->regs        = new RegistrationRepository();
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_schedule', array( $this, 'handle' ) );
	}

	private static function reason( string $code ): string {
		$m = array(
			'no_courts'    => __( 'No hi ha cap pista definida.', 'padelcamps' ),
			'no_slot'      => __( 'No queda cap franja lliure.', 'padelcamps' ),
			'availability' => __( 'Cap franja lliure és compatible amb la disponibilitat de les parelles.', 'padelcamps' ),
			'dependency'   => __( 'Abans cal programar els partits previs.', 'padelcamps' ),
		);
		return $m[ $code ] ?? $code;
	}

	private static function conflict_label( string $type ): string {
		$m = array(
			'court_clash'    => __( 'Dos partits a la mateixa pista i hora', 'padelcamps' ),
			'pair_clash'     => __( 'Una parella té dos partits alhora', 'padelcamps' ),
			'short_rest'     => __( 'Descans massa curt entre partits d’una parella', 'padelcamps' ),
			'outside_window' => __( 'Fora de les franges de joc', 'padelcamps' ),
			'unavailable'    => __( 'La parella no està disponible a aquesta hora', 'padelcamps' ),
		);
		return $m[ $type ] ?? $type;
	}

	private static function url( int $tid, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'schedule' ), $extra ) );
	}

	/** Short label of a match: "Masculí A · Grup B · R2" / "Masculí A · Semifinals". */
	private function match_label( array $m, array $ko_rounds ): string {
		$parts = array( $m['category_name'] );
		if ( in_array( $m['phase_type'], array( 'groups', 'round_robin' ), true ) ) {
			if ( 'groups' === $m['phase_type'] ) {
				$parts[] = sprintf( /* translators: %s: group letter */ __( 'Grup %s', 'padelcamps' ), DrawTab::letter( (int) $m['group_no'] - 1 ) );
			}
			$parts[] = sprintf( /* translators: %d: round number */ __( 'Ronda %d', 'padelcamps' ), (int) $m['round'] );
		} else {
			$key     = (int) $m['category_id'] . ':' . $m['phase_type'];
			$label   = DrawTab::round_label( (int) $m['round'], $ko_rounds[ $key ] ?? (int) $m['round'] );
			$parts[] = 'consolation' === $m['phase_type'] ? __( 'Consolació', 'padelcamps' ) . ' · ' . $label : $label;
		}
		return implode( ' · ', $parts );
	}

	private function form_open( int $tid, string $op, string $confirm = '', bool $inline = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . ( $inline ? ' style="display:inline"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="padelcamps_schedule"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		wp_nonce_field( 'padelcamps_schedule' );
	}

	// --- Rendering --------------------------------------------------------------

	public function render( array $t ): void {
		$tid     = (int) $t['id'];
		$config  = $this->service->config( $t );
		$courts  = $this->schedule->courts( $tid );
		$matches = $this->service->ordered( $tid );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg = isset( $_GET['smsg'] ) ? sanitize_key( wp_unslash( $_GET['smsg'] ) ) : '';
		$n   = isset( $_GET['sn'] ) ? absint( $_GET['sn'] ) : 0;
		// phpcs:enable
		$texts = array(
			'saved'   => __( 'Configuració del calendari desada.', 'padelcamps' ),
			'windows' => __( 'Franges copiades de la disponibilitat del formulari. Revisa’n les hores i desa.', 'padelcamps' ),
			'cleared' => __( 'Horaris esborrats (excepte els partits jugats).', 'padelcamps' ),
			'set'     => __( 'Horari del partit desat.', 'padelcamps' ),
		);
		if ( 'ran' === $msg ) {
			/* translators: %d: number of matches */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'S’ha programat %d partit.', 'S’han programat %d partits.', $n, 'padelcamps' ), $n ) ) . '</p></div>';
		} elseif ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}

		echo '<style>.pc-cal td,.pc-cal th{vertical-align:top}.pc-cal .pc-cell{background:#f0f6fc;border-radius:4px;padding:4px 6px;font-size:12px}.pc-inline{display:flex;gap:6px;flex-wrap:wrap;align-items:center}</style>';

		$this->config_form( $t, $config, $courts );

		$non_bye = array_filter( $matches, static fn( $m ) => 'bye' !== $m['status'] );
		echo '<hr><h2>' . esc_html__( 'Programació', 'padelcamps' ) . '</h2>';
		if ( ! $non_bye ) {
			echo '<p>' . esc_html__( 'Encara no hi ha partits: fes primer el sorteig a la pestanya Sorteig i quadres.', 'padelcamps' ) . '</p>';
			return;
		}
		$pending = count( array_filter( $non_bye, static fn( $m ) => ! $m['scheduled_at'] ) );
		/* translators: 1: matches with a time, 2: total matches */
		echo '<p>' . esc_html( sprintf( __( '%1$d de %2$d partits tenen dia, hora i pista.', 'padelcamps' ), count( $non_bye ) - $pending, count( $non_bye ) ) ) . '</p>';

		echo '<div class="pc-inline" style="margin-bottom:1em">';
		$this->form_open( $tid, 'run', '', true );
		echo '<button class="button button-primary">' . esc_html__( 'Programa els partits sense horari', 'padelcamps' ) . '</button></form>';
		$this->form_open( $tid, 'rerun', __( 'Es tornaran a programar tots els partits no jugats. Els canvis fets a mà es perdran. Continuar?', 'padelcamps' ), true );
		echo '<button class="button">' . esc_html__( 'Torna a programar-ho tot', 'padelcamps' ) . '</button></form>';
		$this->form_open( $tid, 'clear', __( 'Segur que vols esborrar els horaris dels partits no jugats?', 'padelcamps' ), true );
		echo '<button class="button button-link-delete">' . esc_html__( 'Esborra els horaris', 'padelcamps' ) . '</button></form>';
		echo '</div>';

		$ko_rounds = array();
		foreach ( $matches as $m ) {
			if ( in_array( $m['phase_type'], array( 'knockout', 'consolation' ), true ) ) {
				$key               = (int) $m['category_id'] . ':' . $m['phase_type'];
				$ko_rounds[ $key ] = max( $ko_rounds[ $key ] ?? 0, (int) $m['round'] );
			}
		}
		$ids   = array_filter( array_merge( wp_list_pluck( $matches, 'reg_a_id' ), wp_list_pluck( $matches, 'reg_b_id' ) ) );
		$names = array();
		foreach ( $this->regs->players_for( $ids ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}
		$court_name = array_column( $courts, 'name', 'id' );
		$by_id      = array_column( $matches, null, 'id' );
		$vs         = function ( array $m ) use ( $names ): string {
			$a = $m['reg_a_id'] ? ( $names[ (int) $m['reg_a_id'] ] ?? '' ) : __( 'Pendent', 'padelcamps' );
			$b = $m['reg_b_id'] ? ( $names[ (int) $m['reg_b_id'] ] ?? '' ) : __( 'Pendent', 'padelcamps' );
			return $a . ' – ' . $b;
		};

		// Last run: matches that could not be placed.
		$report = get_transient( 'padelcamps_sched_' . $tid . '_' . get_current_user_id() );
		if ( is_array( $report ) && ! empty( $report['unscheduled'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Partits que no s’han pogut programar:', 'padelcamps' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $report['unscheduled'] as $id => $reason ) {
				if ( isset( $by_id[ $id ] ) && ! $by_id[ $id ]['scheduled_at'] ) {
					echo '<li>' . esc_html( $this->match_label( $by_id[ $id ], $ko_rounds ) . ' — ' . $vs( $by_id[ $id ] ) . ': ' . self::reason( $reason ) ) . '</li>';
				}
			}
			echo '</ul></div>';
		}

		$conflicts = $this->service->conflicts( $t );
		if ( $conflicts ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Conflictes a revisar:', 'padelcamps' ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $conflicts as $c ) {
				$labels = array();
				foreach ( $c['matches'] as $id ) {
					if ( isset( $by_id[ $id ] ) ) {
						$labels[] = $this->match_label( $by_id[ $id ], $ko_rounds ) . ' (' . mysql2date( 'D j/n H:i', $by_id[ $id ]['scheduled_at'] ) . ')';
					}
				}
				echo '<li>' . esc_html( self::conflict_label( $c['type'] ) . ': ' . implode( ' / ', $labels ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		$this->calendar( $matches, $courts, $names, $ko_rounds );
		$this->edit_list( $tid, $matches, $courts, $court_name, $ko_rounds, $vs );
	}

	private function config_form( array $t, array $config, array $courts ): void {
		$tid = (int) $t['id'];
		echo '<h2>' . esc_html__( 'Pistes i franges de joc', 'padelcamps' ) . '</h2>';
		$this->form_open( $tid, 'save_config' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="pc-courts">' . esc_html__( 'Pistes', 'padelcamps' ) . '</label></th><td>';
		printf( '<textarea id="pc-courts" name="courts" rows="4" class="regular-text" placeholder="%s">%s</textarea>', esc_attr__( 'Pista 1', 'padelcamps' ), esc_textarea( implode( "\n", wp_list_pluck( $courts, 'name' ) ) ) );
		echo '<p class="description">' . esc_html__( 'Una per línia, en ordre de preferència.', 'padelcamps' ) . '</p></td></tr>';
		$num = static function ( string $name, string $label, int $value, int $min, int $max, string $help ) {
			printf( '<tr><th scope="row"><label for="pc-%1$s">%2$s</label></th><td><input type="number" id="pc-%1$s" name="%1$s" value="%3$d" min="%4$d" max="%5$d" class="small-text"> <span class="description">%6$s</span></td></tr>', esc_attr( $name ), esc_html( $label ), $value, $min, $max, esc_html( $help ) );
		};
		$num( 'duration', __( 'Durada de cada partit', 'padelcamps' ), $config['duration'], 30, 240, __( 'minuts', 'padelcamps' ) );
		$num( 'rest', __( 'Descans mínim entre partits d’una parella', 'padelcamps' ), $config['rest'], 0, 240, __( 'minuts', 'padelcamps' ) );
		$num( 'max_per_day', __( 'Màxim de partits per parella i dia', 'padelcamps' ), $config['max_per_day'], 0, 10, __( '0 = sense límit', 'padelcamps' ) );
		echo '</table>';

		echo '<h3>' . esc_html__( 'Franges de joc', 'padelcamps' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Períodes en què es poden jugar partits. Si el dia i l’etiqueta coincideixen amb una franja de disponibilitat del formulari, es respecta el que hagi contestat cada parella.', 'padelcamps' ) . '</p>';
		echo '<table class="widefat" id="pc-windows" style="max-width:760px"><thead><tr><th>' . esc_html__( 'Dia', 'padelcamps' ) . '</th><th>' . esc_html__( 'Etiqueta', 'padelcamps' ) . '</th><th>' . esc_html__( 'Des de', 'padelcamps' ) . '</th><th>' . esc_html__( 'Fins a', 'padelcamps' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $config['windows'] as $i => $w ) {
			$this->window_row( (string) $i, $w );
		}
		echo '</tbody></table><template id="pc-window-template">';
		$this->window_row( '__i__', array( 'date' => (string) $t['starts_on'], 'label' => '', 'from' => '09:00', 'to' => '14:00' ) );
		echo '</template><p><button type="button" class="button" id="pc-window-add">' . esc_html__( 'Afegeix una franja', 'padelcamps' ) . '</button></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Desa la configuració', 'padelcamps' ) . '</button></p></form>';

		$form_slots = $this->tournaments->form_config( $t )['availability']['slots'];
		if ( $form_slots ) {
			$this->form_open( $tid, 'windows_from_form', __( 'Se substituiran les franges actuals per les del formulari d’inscripció. Continuar?', 'padelcamps' ) );
			echo '<p><button class="button">' . esc_html__( 'Copia les franges de la disponibilitat del formulari', 'padelcamps' ) . '</button></p></form>';
		}

		?>
<script>
(function () {
	var body = document.querySelector('#pc-windows tbody'), tpl = document.getElementById('pc-window-template'), add = document.getElementById('pc-window-add');
	if (!body || !tpl || !add) { return; }
	var next = body.querySelectorAll('tr').length + 1000;
	add.addEventListener('click', function () { var h = document.createElement('tbody'); h.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next++)); body.appendChild(h.querySelector('tr')); });
	body.addEventListener('click', function (e) { if (e.target.classList.contains('pc-window-remove')) { e.target.closest('tr').remove(); } });
})();
</script>
		<?php
	}

	private function window_row( string $i, array $w ): void {
		$n = esc_attr( $i );
		echo '<tr>';
		printf( '<td><input type="date" name="windows[%s][date]" value="%s"></td>', $n, esc_attr( $w['date'] ) );
		printf( '<td><input type="text" name="windows[%s][label]" value="%s" maxlength="60" placeholder="%s"></td>', $n, esc_attr( $w['label'] ), esc_attr__( 'Matí', 'padelcamps' ) );
		printf( '<td><input type="time" name="windows[%s][from]" value="%s"></td>', $n, esc_attr( $w['from'] ) );
		printf( '<td><input type="time" name="windows[%s][to]" value="%s"></td>', $n, esc_attr( $w['to'] ) );
		echo '<td><button type="button" class="button-link button-link-delete pc-window-remove">' . esc_html__( 'Elimina', 'padelcamps' ) . '</button></td></tr>';
	}

	/** One table per day: rows = start times, columns = courts. */
	private function calendar( array $matches, array $courts, array $names, array $ko_rounds ): void {
		if ( ! $courts ) {
			return;
		}
		$by_day = array();
		foreach ( $matches as $m ) {
			if ( $m['scheduled_at'] && $m['court_id'] ) {
				$by_day[ substr( $m['scheduled_at'], 0, 10 ) ][ substr( $m['scheduled_at'], 11, 5 ) ][ (int) $m['court_id'] ][] = $m;
			}
		}
		ksort( $by_day );
		foreach ( $by_day as $day => $times ) {
			ksort( $times );
			echo '<h3>' . esc_html( ucfirst( mysql2date( 'l, ' . get_option( 'date_format' ), $day . ' 00:00:00' ) ) ) . '</h3>';
			echo '<table class="widefat pc-cal" style="max-width:1100px"><thead><tr><th style="width:60px">' . esc_html__( 'Hora', 'padelcamps' ) . '</th>';
			foreach ( $courts as $c ) {
				echo '<th>' . esc_html( $c['name'] ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $times as $time => $cells ) {
				echo '<tr><td><strong>' . esc_html( $time ) . '</strong></td>';
				foreach ( $courts as $c ) {
					echo '<td>';
					foreach ( $cells[ (int) $c['id'] ] ?? array() as $m ) {
						$a = $m['reg_a_id'] ? ( $names[ (int) $m['reg_a_id'] ] ?? '' ) : __( 'Pendent', 'padelcamps' );
						$b = $m['reg_b_id'] ? ( $names[ (int) $m['reg_b_id'] ] ?? '' ) : __( 'Pendent', 'padelcamps' );
						echo '<div class="pc-cell"><strong>' . esc_html( $this->match_label( $m, $ko_rounds ) ) . '</strong><br>' . esc_html( $a ) . '<br>' . esc_html( $b ) . '</div>';
					}
					echo '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	private function edit_list( int $tid, array $matches, array $courts, array $court_name, array $ko_rounds, callable $vs ): void {
		echo '<h3>' . esc_html__( 'Edita l’horari d’un partit', 'padelcamps' ) . '</h3>';
		echo '<details><summary>' . esc_html__( 'Mostra tots els partits', 'padelcamps' ) . '</summary><table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $matches as $m ) {
			if ( 'bye' === $m['status'] ) {
				continue;
			}
			echo '<tr><td>' . esc_html( $this->match_label( $m, $ko_rounds ) ) . '<br><span class="description">' . esc_html( $vs( $m ) ) . '</span></td><td>';
			echo '<span class="pc-inline">';
			$this->form_open( $tid, 'set', '', true );
			echo '<input type="hidden" name="match" value="' . esc_attr( (string) $m['id'] ) . '">';
			printf( '<input type="datetime-local" name="start" value="%s" aria-label="%s"> ', esc_attr( $m['scheduled_at'] ? str_replace( ' ', 'T', substr( $m['scheduled_at'], 0, 16 ) ) : '' ), esc_attr__( 'Dia i hora', 'padelcamps' ) );
			echo '<select name="court" aria-label="' . esc_attr__( 'Pista', 'padelcamps' ) . '"><option value="0">—</option>';
			foreach ( $courts as $c ) {
				printf( '<option value="%d"%s>%s</option>', (int) $c['id'], selected( (int) $m['court_id'], (int) $c['id'], false ), esc_html( $c['name'] ) );
			}
			echo '</select> <button class="button button-small">' . esc_html__( 'Desa', 'padelcamps' ) . '</button></form></span>';
			echo '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	// --- Handler ----------------------------------------------------------------

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_schedule' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		$tid  = absint( $post( 'tid' ) );
		$t    = $tid ? $this->tournaments->find( $tid ) : null;
		if ( ! $t ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}

		$args = array();
		switch ( sanitize_key( $post( 'op' ) ) ) {
			case 'save_config':
				$names = array();
				foreach ( preg_split( '/\r\n|\r|\n/', $post( 'courts' ) ) as $line ) {
					$line = RegistrationForm::clean_text( $line, 80 );
					if ( '' !== $line ) {
						$names[] = $line;
					}
				}
				$this->schedule->sync_courts( $tid, array_slice( $names, 0, 40 ) );
				$windows = isset( $_POST['windows'] ) && is_array( $_POST['windows'] ) ? wp_unslash( $_POST['windows'] ) : array();
				$this->service->save_config(
					$tid,
					array(
						'duration'    => absint( $post( 'duration' ) ),
						'rest'        => absint( $post( 'rest' ) ),
						'max_per_day' => absint( $post( 'max_per_day' ) ),
						'windows'     => array_values( $windows ),
					)
				);
				$args['smsg'] = 'saved';
				break;
			case 'windows_from_form':
				$config            = $this->service->config( $t );
				$config['windows'] = Scheduler::windows_from_availability( $this->tournaments->form_config( $t )['availability']['slots'], $config['duration'] );
				$this->service->save_config( $tid, $config );
				$args['smsg'] = 'windows';
				break;
			case 'run':
			case 'rerun':
				$report = $this->service->run( $t, 'rerun' === $post( 'op' ) );
				set_transient( 'padelcamps_sched_' . $tid . '_' . get_current_user_id(), $report, 30 * MINUTE_IN_SECONDS );
				$args = array(
					'smsg' => 'ran',
					'sn'   => $report['assigned'],
				);
				break;
			case 'clear':
				$this->schedule->clear( $tid );
				delete_transient( 'padelcamps_sched_' . $tid . '_' . get_current_user_id() );
				$args['smsg'] = 'cleared';
				break;
			case 'set':
				$match = absint( $post( 'match' ) );
				$owned = array_filter( $this->schedule->matches( $tid ), static fn( $m ) => (int) $m['id'] === $match );
				if ( $owned ) {
					$start = Scheduler::parse( $post( 'start' ) );
					$court = absint( $post( 'court' ) );
					$valid = in_array( $court, array_map( static fn( $c ) => (int) $c['id'], $this->schedule->courts( $tid ) ), true );
					$this->schedule->set_schedule( $match, null !== $start ? Scheduler::fmt( $start ) . ':00' : null, null !== $start && $valid ? $court : null );
				}
				$args['smsg'] = 'set';
				break;
		}
		// phpcs:enable
		wp_safe_redirect( self::url( $tid, $args ) );
		exit;
	}
}
