<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\DrawPlan;
use PadelCamps\Engine\ScoreParser;
use PadelCamps\Engine\TournamentStatus;
use PadelCamps\Service\DrawService;

defined( 'ABSPATH' ) || exit;

/**
 * "Sorteig i quadres" tab: one category at a time. Draw (seed visible, can be
 * redone), manual swaps, group standings, match results entered by the
 * organizer, final bracket generated from the groups.
 */
final class DrawTab {

	private TournamentRepository $tournaments;
	private DrawRepository $draws;
	private RegistrationRepository $regs;
	private DrawService $service;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
		$this->draws       = new DrawRepository();
		$this->regs        = new RegistrationRepository();
		$this->service     = new DrawService( $this->draws );
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_draw', array( $this, 'handle' ) );
	}

	// --- Texts ------------------------------------------------------------------

	private static function message( string $code ): string {
		$m = array(
			'drawn'         => __( 'Sorteig fet.', 'padelcamps' ),
			'swapped'       => __( 'Parelles intercanviades.', 'padelcamps' ),
			'cleared'       => __( 'Sorteig esborrat.', 'padelcamps' ),
			'final'         => __( 'Quadre final generat.', 'padelcamps' ),
			'final_cleared' => __( 'Quadre final esborrat.', 'padelcamps' ),
			'result'        => __( 'Resultat desat.', 'padelcamps' ),
		);
		return $m[ $code ] ?? '';
	}

	public static function error( string $code ): string {
		$m = array(
			'has_results'         => __( 'Ja hi ha resultats: el sorteig està bloquejat.', 'padelcamps' ),
			'not_enough'          => __( 'Calen almenys 2 inscripcions confirmades.', 'padelcamps' ),
			'no_phases'           => __( 'Aquesta categoria no té cap fase configurada.', 'padelcamps' ),
			'bad_swap'            => __( 'Tria dues parelles diferents.', 'padelcamps' ),
			'groups_incomplete'   => __( 'Encara falten resultats de la fase de grups.', 'padelcamps' ),
			'final_exists'        => __( 'Ja hi ha quadre final: esborra’l per poder canviar els resultats de grups.', 'padelcamps' ),
			'not_ready'           => __( 'Aquest partit encara no té les dues parelles.', 'padelcamps' ),
			'next_played'         => __( 'El partit següent ja s’ha jugat: no es pot canviar aquest resultat.', 'padelcamps' ),
			'invalid_score'       => __( 'No s’entén el resultat. Escriu-lo així: 6-4 3-6 10-8.', 'padelcamps' ),
			'score_invalid_set'   => __( 'Algun set no és vàlid (per exemple, 6-5).', 'padelcamps' ),
			'score_too_many_sets' => __( 'Hi ha més sets dels necessaris.', 'padelcamps' ),
			'score_incomplete'    => __( 'Falten sets: el partit encara no té guanyador.', 'padelcamps' ),
			'score_empty'         => __( 'Escriu el resultat o tria W.O. o retirada.', 'padelcamps' ),
			'not_found'           => __( 'No s’ha trobat el partit o la categoria.', 'padelcamps' ),
		);
		return $m[ $code ] ?? __( 'No s’ha pogut fer l’acció.', 'padelcamps' );
	}

	public static function round_label( int $round, int $total ): string {
		switch ( DrawPlan::round_key( $round, $total ) ) {
			case 'final':
				return __( 'Final', 'padelcamps' );
			case 'semi':
				return __( 'Semifinals', 'padelcamps' );
			case 'quarter':
				return __( 'Quarts de final', 'padelcamps' );
			case 'r16':
				return __( 'Vuitens de final', 'padelcamps' );
		}
		/* translators: %d: round number */
		return sprintf( __( 'Ronda %d', 'padelcamps' ), $round );
	}

	public static function letter( int $g ): string {
		return $g < 26 ? chr( 65 + $g ) : (string) ( $g + 1 );
	}

	private static function url( int $tid, int $cid, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'draw', 'cat' => $cid ), $extra ) );
	}

	/** reg id => "Name 1 / Name 2" */
	private function names( array $ids ): array {
		$out = array();
		foreach ( $this->regs->players_for( $ids ) as $id => $players ) {
			$out[ $id ] = $players ? implode( ' / ', wp_list_pluck( $players, 'name' ) ) : '#' . $id;
		}
		return $out;
	}

	/** Result as text: "6-4 3-6 10-8", "W.O.", "6-4 2-3 (ret.)". */
	private static function score_text( ?array $score ): string {
		if ( ! $score ) {
			return '';
		}
		if ( ! empty( $score['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		$text = ScoreParser::format( $score['sets'] ?? array() );
		return ! empty( $score['retired'] ) ? trim( $text . ' ' . __( '(ret.)', 'padelcamps' ) ) : $text;
	}

	// --- Rendering --------------------------------------------------------------

	public function render( array $t ): void {
		$tid        = (int) $t['id'];
		$categories = $this->tournaments->categories( $tid );
		if ( ! $categories ) {
			echo '<p>' . esc_html__( 'Crea primer alguna categoria a la pestanya Configuració.', 'padelcamps' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing / display.
		$cid  = isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0;
		$msg  = isset( $_GET['dmsg'] ) ? sanitize_key( wp_unslash( $_GET['dmsg'] ) ) : '';
		$err  = isset( $_GET['derr'] ) ? sanitize_key( wp_unslash( $_GET['derr'] ) ) : '';
		// phpcs:enable
		$category = $categories[0];
		foreach ( $categories as $c ) {
			if ( (int) $c['id'] === $cid ) {
				$category = $c;
			}
		}
		$cid = (int) $category['id'];

		echo '<style>.pc-ko{display:flex;gap:16px;overflow-x:auto;align-items:flex-start}.pc-ko-round{min-width:260px}.pc-ko-match{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 10px;margin:0 0 10px}.pc-ko-match .w{font-weight:600}.pc-q td{background:#f0f6fc}.pc-inline{display:flex;gap:4px;flex-wrap:wrap;align-items:center}.pc-inline input[type=text]{width:110px}.pc-inline select{max-width:170px}</style>';

		echo '<ul class="subsubsub" style="float:none;margin-bottom:1em">';
		foreach ( $categories as $i => $c ) {
			printf(
				'<li>%s<a href="%s"%s>%s</a></li>',
				$i ? ' | ' : '',
				esc_url( self::url( $tid, (int) $c['id'] ) ),
				(int) $c['id'] === $cid ? ' class="current" aria-current="page"' : '',
				esc_html( $c['name'] )
			);
		}
		echo '</ul>';

		if ( '' !== $msg && '' !== self::message( $msg ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::message( $msg ) ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::error( $err ) ) . '</p></div>';
		}

		$entrants = $this->draws->confirmed_entrants( $cid );
		$pending  = $this->draws->pending_count( $cid );
		$phases   = $this->draws->phases( $cid );
		$matches  = $this->draws->matches( $cid );
		$drawn    = (bool) $matches;
		$locked   = $this->draws->has_results( $cid );
		$names    = $this->names( wp_list_pluck( $entrants, 'id' ) );

		echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 12px;margin:0 0 1em;max-width:760px">';
		echo '<p style="margin:4px 0">' . esc_html__( 'Per mostrar els quadres al web, enganxa aquest shortcode en una pàgina:', 'padelcamps' ) . '</p>';
		echo '<p style="margin:4px 0"><code>[padelcamps_draw id="' . esc_html( (string) $tid ) . '"]</code> ' . esc_html__( 'totes les categories', 'padelcamps' ) . '</p>';
		echo '<p style="margin:4px 0"><code>[padelcamps_draw id="' . esc_html( (string) $tid ) . '" category="' . esc_html( (string) $cid ) . '"]</code> ' . esc_html__( 'només aquesta categoria', 'padelcamps' ) . '</p>';
		echo '</div>';

		echo '<h2>' . esc_html( $category['name'] ) . ' <span style="font-weight:400;color:#646970">· ' . esc_html( TournamentsPage::template_label( $category['format'] ) ) . '</span></h2>';
		/* translators: %d: number of confirmed pairs */
		echo '<p>' . esc_html( sprintf( _n( '%d parella confirmada.', '%d parelles confirmades.', count( $entrants ), 'padelcamps' ), count( $entrants ) ) );
		if ( $pending > 0 ) {
			/* translators: %d: number of pending registrations */
			echo ' <strong>' . esc_html( sprintf( _n( 'Hi ha %d inscripció pendent: no entra al sorteig fins que la confirmis.', 'Hi ha %d inscripcions pendents: no entren al sorteig fins que les confirmis.', $pending, 'padelcamps' ), $pending ) ) . '</strong>';
		}
		echo '</p>';
		if ( in_array( $t['status'], array( TournamentStatus::DRAFT, TournamentStatus::REGISTRATION_OPEN ), true ) ) {
			echo '<p class="description">' . esc_html__( 'Les inscripcions encara estan obertes: si fas el sorteig ara, les noves inscripcions confirmades no hi entraran fins que el refacis.', 'padelcamps' ) . '</p>';
		}

		if ( ! $drawn ) {
			$this->draw_form( $tid, $category, $phases, false );
			return;
		}

		/* translators: %s: seed text */
		echo '<p>' . esc_html( sprintf( __( 'Llavor del sorteig: «%s». Amb la mateixa llavor i les mateixes parelles, el sorteig surt idèntic.', 'padelcamps' ), (string) $category['seed'] ) );
		$log = $this->draws->last_log( $cid );
		if ( $log ) {
			/* translators: %s: date and time */
			echo ' ' . esc_html( sprintf( __( 'Darrer canvi: %s.', 'padelcamps' ), mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['created_at'] ) ) );
		}
		echo '</p>';

		if ( $locked ) {
			echo '<p class="description">' . esc_html__( 'Ja hi ha resultats: el sorteig està bloquejat (no es pot refer ni intercanviar parelles).', 'padelcamps' ) . '</p>';
		} else {
			$this->draw_form( $tid, $category, $phases, true );
			$this->swap_form( $tid, $cid, $entrants, $names, $phases );
		}

		$format = $this->service->format( $phases );
		if ( $phases['group'] ) {
			$this->render_groups( $tid, $category, $phases, $matches, $names, $format );
		}
		if ( $phases['knockout'] ) {
			$this->render_knockout( $tid, $category, $phases, $matches, $names );
		}
		if ( $phases['consolation'] ) {
			$this->render_knockout( $tid, $category, $phases, $matches, $names, 'consolation' );
		}
	}

	private function form_open( int $tid, int $cid, string $op, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . ' style="display:inline">';
		echo '<input type="hidden" name="action" value="padelcamps_draw">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		echo '<input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '">';
		echo '<input type="hidden" name="cat" value="' . esc_attr( (string) $cid ) . '">';
		wp_nonce_field( 'padelcamps_draw' );
	}

	private function draw_form( int $tid, array $category, array $phases, bool $redo ): void {
		$cid  = (int) $category['id'];
		$seed = 'pc-' . strtolower( wp_generate_password( 8, false, false ) );
		echo '<div class="pc-inline" style="margin:0.6em 0">';
		$this->form_open( $tid, $cid, 'draw', $redo ? __( 'Segur que vols refer el sorteig? Es perdran els grups i el quadre actuals.', 'padelcamps' ) : '' );
		echo '<label for="pc-seed">' . esc_html__( 'Llavor', 'padelcamps' ) . '</label> ';
		printf( '<input type="text" id="pc-seed" name="seed" value="%s" maxlength="60"> ', esc_attr( $seed ) );
		if ( $phases['group'] && 'groups' === $phases['group']['type'] ) {
			echo '<label for="pc-gsize">' . esc_html__( 'Parelles per grup', 'padelcamps' ) . '</label> <select id="pc-gsize" name="group_size">';
			foreach ( array( 3, 4, 5, 6 ) as $n ) {
				printf( '<option value="%1$d"%2$s>%1$d</option>', $n, selected( 4, $n, false ) );
			}
			echo '</select> ';
		}
		echo '<button class="button button-primary">' . ( $redo ? esc_html__( 'Refés el sorteig', 'padelcamps' ) : esc_html__( 'Fes el sorteig', 'padelcamps' ) ) . '</button></form>';
		if ( $redo ) {
			$this->form_open( $tid, $cid, 'clear', __( 'Segur que vols esborrar el sorteig?', 'padelcamps' ) );
			echo '<button class="button button-link-delete">' . esc_html__( 'Esborra el sorteig', 'padelcamps' ) . '</button></form>';
		}
		echo '</div>';
	}

	private function swap_form( int $tid, int $cid, array $entrants, array $names, array $phases ): void {
		$label = function ( array $e ) use ( $names, $phases ): string {
			$name = $names[ (int) $e['id'] ] ?? '#' . $e['id'];
			if ( $phases['group'] && null !== $e['group_no'] ) {
				/* translators: 1: pair names, 2: group letter */
				return sprintf( __( '%1$s (grup %2$s)', 'padelcamps' ), $name, self::letter( (int) $e['group_no'] - 1 ) );
			}
			/* translators: 1: pair names, 2: seed number */
			return sprintf( __( '%1$s (cap de sèrie %2$d)', 'padelcamps' ), $name, (int) $e['seed_no'] );
		};
		echo '<div class="pc-inline" style="margin:0.6em 0">';
		$this->form_open( $tid, $cid, 'swap' );
		echo '<span>' . esc_html__( 'Intercanvia', 'padelcamps' ) . '</span> ';
		foreach ( array( 'x', 'y' ) as $k ) {
			echo '<select name="' . esc_attr( $k ) . '" aria-label="' . esc_attr__( 'Parella', 'padelcamps' ) . '">';
			foreach ( $entrants as $e ) {
				printf( '<option value="%d">%s</option>', (int) $e['id'], esc_html( $label( $e ) ) );
			}
			echo '</select> ';
		}
		echo '<button class="button">' . esc_html__( 'Intercanvia', 'padelcamps' ) . '</button></form></div>';
	}

	private function result_form( int $tid, int $cid, array $m, array $names ): void {
		$score   = $m['score'];
		$text    = $score && empty( $score['walkover'] ) ? ScoreParser::format( $score['sets'] ?? array() ) : '';
		$special = '';
		if ( $score && ! empty( $score['walkover'] ) ) {
			$special = 'wo_' . $score['walkover'];
		} elseif ( $score && ! empty( $score['retired'] ) ) {
			$special = 'ret_' . $score['retired'];
		}
		$a = $names[ (int) $m['reg_a_id'] ] ?? '';
		$b = $names[ (int) $m['reg_b_id'] ] ?? '';

		echo '<span class="pc-inline">';
		$this->form_open( $tid, $cid, 'result' );
		echo '<input type="hidden" name="match" value="' . esc_attr( (string) $m['id'] ) . '">';
		printf( '<input type="text" name="score" value="%s" placeholder="6-4 6-3" aria-label="%s"> ', esc_attr( $text ), esc_attr__( 'Resultat', 'padelcamps' ) );
		echo '<select name="special" aria-label="' . esc_attr__( 'Final especial', 'padelcamps' ) . '"><option value="">—</option>';
		$options = array(
			/* translators: %s: pair names */
			'wo_a'  => sprintf( __( 'W.O. a favor de %s', 'padelcamps' ), $a ),
			'wo_b'  => sprintf( __( 'W.O. a favor de %s', 'padelcamps' ), $b ),
			/* translators: %s: pair names */
			'ret_a' => sprintf( __( 'Es retira %s', 'padelcamps' ), $a ),
			'ret_b' => sprintf( __( 'Es retira %s', 'padelcamps' ), $b ),
		);
		foreach ( $options as $value => $text_option ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $special, $value, false ), esc_html( $text_option ) );
		}
		echo '</select> <button class="button button-small">' . esc_html__( 'Desa', 'padelcamps' ) . '</button></form></span>';
	}

	private function render_groups( int $tid, array $category, array $phases, array $matches, array $names, array $format ): void {
		$cid     = (int) $category['id'];
		$gid     = (int) $phases['group']['id'];
		$advance = 'groups' === $phases['group']['type'] ? (int) ( $phases['group']['settings']['advance'] ?? 2 ) : 0;
		$tables  = $this->service->standings( $cid, $matches, $format );
		$gm      = array_values( array_filter( $matches, static fn( $m ) => (int) $m['phase_id'] === $gid ) );
		$played  = count( array_filter( $gm, static fn( $m ) => 'played' === $m['status'] ) );
		$ko_ids  = $phases['knockout'] ? (int) $phases['knockout']['id'] : 0;
		$has_ko  = $ko_ids && (bool) array_filter( $matches, static fn( $m ) => (int) $m['phase_id'] === $ko_ids );

		foreach ( $tables as $g => $rows ) {
			$title = 'round_robin' === $phases['group']['type'] ? __( 'Classificació', 'padelcamps' ) : sprintf( /* translators: %s: group letter */ __( 'Grup %s', 'padelcamps' ), self::letter( $g ) );
			echo '<h3>' . esc_html( $title ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th style="width:30px">#</th><th>' . esc_html__( 'Parella', 'padelcamps' ) . '</th><th>' . esc_html__( 'Jugats', 'padelcamps' ) . '</th><th>' . esc_html__( 'Guanyats', 'padelcamps' ) . '</th><th>' . esc_html__( 'Perduts', 'padelcamps' ) . '</th><th>' . esc_html__( 'Sets', 'padelcamps' ) . '</th><th>' . esc_html__( 'Jocs', 'padelcamps' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$q = $advance > 0 && $row['position'] <= $advance;
				echo '<tr' . ( $q ? ' class="pc-q"' : '' ) . '><td>' . esc_html( (string) $row['position'] ) . '</td><td>' . esc_html( $names[ $row['team'] ] ?? '#' . $row['team'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['played'] ) . '</td><td>' . esc_html( (string) $row['won'] ) . '</td><td>' . esc_html( (string) $row['lost'] ) . '</td>';
				echo '<td>' . esc_html( $row['sets_for'] . '-' . $row['sets_against'] ) . '</td><td>' . esc_html( $row['games_for'] . '-' . $row['games_against'] ) . '</td></tr>';
			}
			echo '</tbody></table>';

			echo '<table class="widefat" style="max-width:760px;margin-top:6px"><tbody>';
			foreach ( $gm as $m ) {
				if ( (int) $m['group_no'] - 1 !== $g ) {
					continue;
				}
				$a = (int) $m['reg_a_id'];
				$b = (int) $m['reg_b_id'];
				$w = (int) $m['winner_reg_id'];
				echo '<tr><td style="width:70px">' . esc_html( sprintf( /* translators: %d: round number */ __( 'Ronda %d', 'padelcamps' ), (int) $m['round'] ) ) . '</td>';
				echo '<td>' . ( $w === $a ? '<strong>' : '' ) . esc_html( $names[ $a ] ?? '' ) . ( $w === $a ? '</strong>' : '' ) . ' <span style="color:#646970">' . esc_html__( 'contra', 'padelcamps' ) . '</span> ' . ( $w === $b ? '<strong>' : '' ) . esc_html( $names[ $b ] ?? '' ) . ( $w === $b ? '</strong>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td style="width:120px">' . esc_html( self::score_text( $m['score'] ) ) . '</td><td>';
				if ( ! $has_ko ) {
					$this->result_form( $tid, $cid, $m, $names );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( 'groups' === $phases['group']['type'] && $phases['knockout'] ) {
			echo '<p style="margin-top:1em">';
			if ( ! $has_ko ) {
				if ( $played < count( $gm ) ) {
					/* translators: 1: matches played, 2: total matches */
					echo esc_html( sprintf( __( 'Per generar el quadre final cal tenir tots els resultats de grups (%1$d de %2$d).', 'padelcamps' ), $played, count( $gm ) ) );
				} else {
					$this->form_open( $tid, $cid, 'final' );
					echo '<button class="button button-primary">' . esc_html__( 'Genera el quadre final', 'padelcamps' ) . '</button></form>';
				}
			}
			echo '</p>';
		}
	}

	private function render_knockout( int $tid, array $category, array $phases, array $matches, array $names, string $key = 'knockout' ): void {
		$cid = (int) $category['id'];
		$kid = (int) $phases[ $key ]['id'];
		$ko  = array_values( array_filter( $matches, static fn( $m ) => (int) $m['phase_id'] === $kid ) );
		if ( ! $ko ) {
			return;
		}
		$names  = $names + $this->names( array_filter( array_merge( wp_list_pluck( $ko, 'reg_a_id' ), wp_list_pluck( $ko, 'reg_b_id' ) ) ) );
		$rounds = array();
		foreach ( $ko as $m ) {
			$rounds[ (int) $m['round'] ][] = $m;
		}
		ksort( $rounds );
		$total = max( array_keys( $rounds ) );

		echo '<h3>' . ( 'consolation' === $key ? esc_html__( 'Quadre de consolació', 'padelcamps' ) : esc_html__( 'Quadre final', 'padelcamps' ) ) . '</h3>';
		if ( 'consolation' === $key ) {
			echo '<p class="description">' . esc_html__( 'Les parelles que perden el primer partit del quadre final hi entren soles en desar el resultat.', 'padelcamps' ) . '</p>';
		}
		$cons_results = $phases['consolation'] && $this->draws->has_results( $cid, (int) $phases['consolation']['id'] );
		if ( 'knockout' === $key && $phases['group'] && ! $this->draws->has_results( $cid, $kid ) && ! $cons_results ) {
			echo '<p>';
			$this->form_open( $tid, $cid, 'clear_final', __( 'Segur que vols esborrar el quadre final?', 'padelcamps' ) );
			echo '<button class="button button-link-delete">' . esc_html__( 'Esborra el quadre final', 'padelcamps' ) . '</button></form></p>';
		}

		echo '<div class="pc-ko">';
		foreach ( $rounds as $r => $list ) {
			echo '<div class="pc-ko-round"><h4>' . esc_html( self::round_label( $r, $total ) ) . '</h4>';
			foreach ( $list as $m ) {
				$w = (int) $m['winner_reg_id'];
				echo '<div class="pc-ko-match">';
				foreach ( array( 'reg_a_id', 'reg_b_id' ) as $side ) {
					$id   = null === $m[ $side ] ? 0 : (int) $m[ $side ];
					$name = $id ? ( $names[ $id ] ?? '#' . $id ) : ( 'bye' === $m['status'] ? '—' : __( 'Pendent', 'padelcamps' ) );
					echo '<div' . ( $id && $w === $id ? ' class="w"' : '' ) . '>' . esc_html( $name ) . '</div>';
				}
				if ( 'bye' === $m['status'] ) {
					echo '<div class="description">' . esc_html__( 'Passa directament', 'padelcamps' ) . '</div>';
				} else {
					$text = self::score_text( $m['score'] );
					if ( '' !== $text ) {
						echo '<div class="description">' . esc_html( $text ) . '</div>';
					}
					if ( $m['reg_a_id'] && $m['reg_b_id'] ) {
						$this->result_form( $tid, $cid, $m, $names );
					}
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// --- Handler ----------------------------------------------------------------

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_draw' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		// phpcs:enable
		$tid      = absint( $post( 'tid' ) );
		$cid      = absint( $post( 'cat' ) );
		$category = $cid ? $this->tournaments->category( $cid ) : null;
		if ( ! $category || (int) $category['tournament_id'] !== $tid ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}

		$ok = '';
		switch ( sanitize_key( $post( 'op' ) ) ) {
			case 'draw':
				$seed = sanitize_text_field( $post( 'seed' ) );
				$seed = '' !== $seed ? substr( $seed, 0, 60 ) : 'pc-' . strtolower( wp_generate_password( 8, false, false ) );
				$size = max( 3, min( 6, absint( $post( 'group_size' ) ) ?: 4 ) );
				$err  = $this->service->draw( $category, $seed, $size );
				$ok   = 'drawn';
				break;
			case 'swap':
				$err = $this->service->swap( $category, absint( $post( 'x' ) ), absint( $post( 'y' ) ) );
				$ok  = 'swapped';
				break;
			case 'clear':
				$err = $this->service->clear( $category );
				$ok  = 'cleared';
				break;
			case 'final':
				$err = $this->service->build_final( $category );
				$ok  = 'final';
				break;
			case 'clear_final':
				$err = $this->service->clear_final( $category );
				$ok  = 'final_cleared';
				break;
			case 'result':
				$special = sanitize_key( $post( 'special' ) );
				$special = in_array( $special, array( 'wo_a', 'wo_b', 'ret_a', 'ret_b' ), true ) ? $special : '';
				$err     = $this->service->set_result( $category, absint( $post( 'match' ) ), sanitize_text_field( $post( 'score' ) ), $special );
				$ok      = 'result';
				break;
			default:
				$err = 'unknown';
		}

		wp_safe_redirect( self::url( $tid, $cid, null === $err ? array( 'dmsg' => $ok ) : array( 'derr' => $err ) ) );
		exit;
	}
}
