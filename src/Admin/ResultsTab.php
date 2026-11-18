<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\ResultRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\ScoreParser;
use PadelCamps\Frontend\PairPage;
use PadelCamps\Service\ResultService;

defined( 'ABSPATH' ) || exit;

/**
 * "Resultats" tab: results reported by players (pending / disputed), the
 * on/off switch, and each pair's magic link (copy, WhatsApp, email, renew).
 */
final class ResultsTab {

	private TournamentRepository $tournaments;
	private ResultRepository $results;
	private RegistrationRepository $regs;
	private RankingPanel $ranking_panel;

	/** Sub-page being shown: review | points | links. */
	private string $sub = 'review';

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments   = $tournaments;
		$this->results       = new ResultRepository();
		$this->regs          = new RegistrationRepository();
		$this->ranking_panel = new RankingPanel( $tournaments );
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_results', array( $this, 'handle' ) );
		$this->ranking_panel->register();
	}

	private static function url( int $tid, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'results' ), $extra ) );
	}

	private function form_open( int $tid, string $op, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="padelcamps_results"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '"><input type="hidden" name="sub" value="' . esc_attr( $this->sub ) . '">';
		wp_nonce_field( 'padelcamps_results' );
	}

	private static function score_text( array $result ): string {
		if ( ! empty( $result['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		$text = ScoreParser::format( $result['sets'] ?? array() );
		return ! empty( $result['retired'] ) ? trim( $text . ' ' . __( '(ret.)', 'padelcamps' ) ) : $text;
	}

	public function render( array $t ): void {
		$tid     = (int) $t['id'];
		$service = new ResultService();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted.
		$sub       = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'review';
		$this->sub = in_array( $sub, array( 'review', 'points', 'links' ), true ) ? $sub : 'review';
		$enabled = $service->players_can_report( $t );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg = isset( $_GET['rmsg'] ) ? sanitize_key( wp_unslash( $_GET['rmsg'] ) ) : '';
		$err = isset( $_GET['rerr'] ) ? sanitize_key( wp_unslash( $_GET['rerr'] ) ) : '';
		// phpcs:enable
		$texts = array(
			'saved'     => __( 'Opció desada.', 'padelcamps' ),
			'applied'   => __( 'Resultat aplicat.', 'padelcamps' ),
			'discarded' => __( 'Resultat descartat.', 'padelcamps' ),
			'renewed'   => __( 'Enllaç renovat: l’anterior ja no funciona.', 'padelcamps' ),
		);
		if ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error"><p>' . esc_html( DrawTab::error( $err ) ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Resultats des del mòbil', 'padelcamps' ) . '</h2>';
		echo '<p>' . esc_html__( 'Cada parella té un enllaç personal per veure els seus partits i entrar-ne el resultat. El resultat s’aplica quan la parella rival el confirma; si no hi estan d’acord, apareix aquí perquè decideixis.', 'padelcamps' ) . '</p>';
		$this->form_open( $tid, 'toggle' );
		echo '<label><input type="checkbox" name="enabled" value="1"' . checked( $enabled, true, false ) . ' onchange="this.form.submit()"> ' . esc_html__( 'Els jugadors poden entrar resultats amb el seu enllaç', 'padelcamps' ) . '</label></form>';

		$this->sub_nav( $tid );
		if ( 'points' === $this->sub ) {
			$this->ranking_panel->render( $t );
		} elseif ( 'links' === $this->sub ) {
			$this->links( $t );
		} else {
			$this->open_reports( $tid );
		}
	}

	/** Sub-pages of this tab, as a boxed group of buttons. */
	private function sub_nav( int $tid ): void {
		$open = count( array_filter( $this->results->open_reports_of_tournament( $tid ), static fn( $r ) => 'played' !== $r['match_status'] ) );
		$subs = array(
			'review' => __( 'Resultats per revisar', 'padelcamps' ),
			'points' => __( 'Punts del rànquing', 'padelcamps' ),
			'links'  => __( 'Enllaços de les parelles', 'padelcamps' ),
		);
		$items = array();
		foreach ( $subs as $key => $label ) {
			$items[] = array(
				'label'   => $label,
				'url'     => self::url( $tid, 'review' === $key ? array() : array( 'sub' => $key ) ),
				'current' => $key === $this->sub,
				'count'   => 'review' === $key ? $open : 0,
			);
		}
		SubNav::render( $items );
	}

	private function open_reports( int $tid ): void {
		$open = array_filter( $this->results->open_reports_of_tournament( $tid ), static fn( $r ) => 'played' !== $r['match_status'] );
		echo '<h3>' . esc_html__( 'Resultats per revisar', 'padelcamps' ) . '</h3>';
		if ( ! $open ) {
			echo '<p class="description">' . esc_html__( 'No n’hi ha cap.', 'padelcamps' ) . '</p>';
			return;
		}
		$ids   = array_merge( wp_list_pluck( $open, 'reg_a_id' ), wp_list_pluck( $open, 'reg_b_id' ) );
		$names = array();
		foreach ( $this->regs->players_for( array_filter( $ids ) ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Partit', 'padelcamps' ) . '</th><th>' . esc_html__( 'Enviat per', 'padelcamps' ) . '</th><th>' . esc_html__( 'Resultat', 'padelcamps' ) . '</th><th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $open as $r ) {
			$match = $r['category_name'] . ' · ' . ( $names[ (int) $r['reg_a_id'] ] ?? '' ) . ' – ' . ( $names[ (int) $r['reg_b_id'] ] ?? '' );
			$state = 'disputed' === $r['status'] ? __( 'En disputa', 'padelcamps' ) : __( 'Pendent de confirmar', 'padelcamps' );
			echo '<tr><td>' . esc_html( $match ) . '</td><td>' . esc_html( $names[ (int) $r['reg_id'] ] ?? '' ) . '</td><td><strong>' . esc_html( self::score_text( $r['result'] ) ) . '</strong></td><td>' . esc_html( $state ) . '</td><td>';
			$this->form_open( $tid, 'apply' );
			echo '<input type="hidden" name="report" value="' . (int) $r['id'] . '"><button class="button button-small button-primary">' . esc_html__( 'Aplica', 'padelcamps' ) . '</button></form> ';
			$this->form_open( $tid, 'discard' );
			echo '<input type="hidden" name="report" value="' . (int) $r['id'] . '"><button class="button button-small">' . esc_html__( 'Descarta', 'padelcamps' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function links( array $t ): void {
		$tid   = (int) $t['id'];
		$pairs = $this->results->confirmed_pairs( $tid );
		echo '<h3>' . esc_html__( 'Enllaços de les parelles', 'padelcamps' ) . '</h3>';
		if ( ! $pairs ) {
			echo '<p class="description">' . esc_html__( 'Apareixeran aquí quan hi hagi inscripcions confirmades.', 'padelcamps' ) . '</p>';
			return;
		}
		$names = array();
		foreach ( $this->regs->players_for( wp_list_pluck( $pairs, 'id' ) ) as $id => $players ) {
			$names[ $id ] = implode( ' / ', wp_list_pluck( $players, 'name' ) );
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th><th>' . esc_html__( 'Parella', 'padelcamps' ) . '</th><th>' . esc_html__( 'Enllaç', 'padelcamps' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $pairs as $p ) {
			$token = '' !== (string) $p['token'] ? (string) $p['token'] : $this->results->token_for( (int) $p['id'] );
			$link  = PairPage::url( $token );
			/* translators: 1: tournament name, 2: link */
			$text  = sprintf( __( 'Hola! Aquest és el vostre enllaç per veure els partits i entrar-ne els resultats a %1$s: %2$s', 'padelcamps' ), $t['name'], $link );
			$phone = preg_replace( '/\D/', '', (string) $p['contact_phone'] );
			$wa    = 'https://wa.me/' . ( $phone ? ( 9 === strlen( $phone ) ? '34' . $phone : $phone ) : '' ) . '?text=' . rawurlencode( $text );
			$mail  = 'mailto:' . rawurlencode( (string) $p['contact_email'] ) . '?subject=' . rawurlencode( $t['name'] ) . '&body=' . rawurlencode( $text );

			echo '<tr><td>' . esc_html( $p['category_name'] ) . '</td><td>' . esc_html( $names[ (int) $p['id'] ] ?? '' ) . '</td>';
			echo '<td><input type="text" readonly class="regular-text pc-link" value="' . esc_attr( $link ) . '" onclick="this.select()"></td><td>';
			echo '<button type="button" class="button button-small pc-copy" data-link="' . esc_attr( $link ) . '">' . esc_html__( 'Copia', 'padelcamps' ) . '</button> ';
			echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $wa ) . '">' . esc_html__( 'WhatsApp', 'padelcamps' ) . '</a> ';
			if ( $p['contact_email'] ) {
				echo '<a class="button button-small" href="' . esc_url( $mail ) . '">' . esc_html__( 'Correu', 'padelcamps' ) . '</a> ';
			}
			$this->form_open( $tid, 'renew', __( 'L’enllaç actual deixarà de funcionar. Continuar?', 'padelcamps' ) );
			echo '<input type="hidden" name="reg" value="' . (int) $p['id'] . '"><button class="button button-small button-link-delete">' . esc_html__( 'Renova', 'padelcamps' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<script>document.querySelectorAll(".pc-copy").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard&&navigator.clipboard.writeText(b.dataset.link);var t=b.textContent;b.textContent="✓";setTimeout(function(){b.textContent=t;},1200);});});</script>';
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_results' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		// phpcs:enable
		$tid = absint( $post( 'tid' ) );
		if ( ! $tid || ! $this->tournaments->find( $tid ) ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}
		$service = new ResultService();
		$args    = array();
		switch ( sanitize_key( $post( 'op' ) ) ) {
			case 'toggle':
				$this->tournaments->save_setting( $tid, 'player_results', '1' === $post( 'enabled' ) );
				$args['rmsg'] = 'saved';
				break;
			case 'apply':
				$error = $service->organizer_apply( $tid, absint( $post( 'report' ) ) );
				$args  = null === $error ? array( 'rmsg' => 'applied' ) : array( 'rerr' => $error );
				break;
			case 'discard':
				$error = $service->organizer_discard( $tid, absint( $post( 'report' ) ) );
				$args  = null === $error ? array( 'rmsg' => 'discarded' ) : array( 'rerr' => $error );
				break;
			case 'renew':
				$reg = absint( $post( 'reg' ) );
				if ( in_array( $reg, array_map( 'intval', wp_list_pluck( $this->results->confirmed_pairs( $tid ), 'id' ) ), true ) ) {
					$this->results->regenerate_token( $reg );
				}
				$args['rmsg'] = 'renewed';
				break;
		}
		$sub = sanitize_key( $post( 'sub' ) );
		if ( in_array( $sub, array( 'points', 'links' ), true ) ) {
			$args['sub'] = $sub;
		}
		wp_safe_redirect( self::url( $tid, $args ) );
		exit;
	}
}
