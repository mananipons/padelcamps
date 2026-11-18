<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\SeasonRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Service\RankingService;

defined( 'ABSPATH' ) || exit;

/**
 * "Punts del rànquing" section of the Results tab: per category, what each pair earns
 * and the buttons to publish, update or unpublish those points. Nothing reaches the
 * season ranking until the organizer publishes.
 */
final class RankingPanel {

	private TournamentRepository $tournaments;
	private SeasonRepository $seasons;
	private RankingService $service;

	public function __construct( TournamentRepository $tournaments ) {
		$this->tournaments = $tournaments;
		$this->seasons     = new SeasonRepository();
		$this->service     = new RankingService();
	}

	public function register(): void {
		add_action( 'admin_post_padelcamps_ranking_points', array( $this, 'handle' ) );
	}

	private static function url( int $tid, array $extra = array() ): string {
		return TournamentsPage::url( array_merge( array( 'action' => 'edit', 'id' => $tid, 'tab' => 'results', 'sub' => 'points' ), $extra ) );
	}

	/** @return array{0:string,1:string} [badge class, label] */
	private static function state_badge( string $state ): array {
		$map = array(
			'not_eligible' => array( 'muted', __( 'Sense categoria de rànquing', 'padelcamps' ) ),
			'incomplete'   => array( 'warn', __( 'La categoria encara no ha acabat', 'padelcamps' ) ),
			'ready'        => array( 'info', __( 'Llest per publicar', 'padelcamps' ) ),
			'published'    => array( 'ok', __( 'Publicat', 'padelcamps' ) ),
			'outdated'     => array( 'bad', __( 'Desactualitzat', 'padelcamps' ) ),
		);
		return $map[ $state ] ?? array( 'muted', $state );
	}

	private static function error_text( string $code ): string {
		$texts = array(
			'not_found'    => __( 'No s’ha trobat la categoria.', 'padelcamps' ),
			'not_eligible' => __( 'Aquesta categoria no té categoria de rànquing o el torneig no dona punts.', 'padelcamps' ),
			'incomplete'   => __( 'La categoria encara no ha acabat: no es poden publicar els punts.', 'padelcamps' ),
		);
		return $texts[ $code ] ?? '';
	}

	public function render( array $t ): void {
		$tid = (int) $t['id'];

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$msg = isset( $_GET['pmsg'] ) ? sanitize_key( wp_unslash( $_GET['pmsg'] ) ) : '';
		$err = isset( $_GET['perr'] ) ? sanitize_key( wp_unslash( $_GET['perr'] ) ) : '';
		// phpcs:enable
		if ( 'published' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Punts publicats al rànquing.', 'padelcamps' ) . '</p></div>';
		} elseif ( 'unpublished' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Punts retirats del rànquing.', 'padelcamps' ) . '</p></div>';
		}
		if ( '' !== $err && '' !== self::error_text( $err ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::error_text( $err ) ) . '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Punts del rànquing', 'padelcamps' ) . '</h3>';
		if ( empty( $t['season_id'] ) || empty( $t['counts_for_ranking'] ) ) {
			echo '<p class="description">' . esc_html__( 'Aquest torneig no dona punts per al rànquing. Ho pots canviar a Configuració.', 'padelcamps' ) . '</p>';
			return;
		}
		$season = $this->seasons->find( (int) $t['season_id'] );
		if ( ! $season ) {
			return;
		}
		/* translators: %s: season name */
		echo '<p class="description">' . esc_html( sprintf( __( 'Temporada: %s. Els punts només entren al rànquing quan els publiques; si després es corregeix un resultat, aquí t’avisarem perquè els actualitzis.', 'padelcamps' ), $season['name'] ) ) . '</p>';

		$categories = $this->tournaments->categories( $tid );
		if ( ! $categories ) {
			echo '<p class="description">' . esc_html__( 'Encara no hi ha cap categoria.', 'padelcamps' ) . '</p>';
			return;
		}

		$rc_names = wp_list_pluck( $this->seasons->ranking_categories( (int) $season['id'] ), 'name', 'id' );
		$labels   = SeasonsPage::points_labels();

		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
		echo '<th>' . esc_html__( 'Categoria', 'padelcamps' ) . '</th><th>' . esc_html__( 'Categoria de rànquing', 'padelcamps' ) . '</th><th>' . esc_html__( 'Estat', 'padelcamps' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $categories as $cat ) {
			$cid                  = (int) $cat['id'];
			$eval                 = $this->service->evaluate( $cat );
			list( $class, $text ) = self::state_badge( $eval['state'] );
			echo '<tr><td><strong>' . esc_html( $cat['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $rc_names[ (int) $cat['ranking_category_id'] ] ?? '—' ) . '</td>';
			echo '<td><span class="pc-badge pc-badge--' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
			if ( 'published' === $eval['state'] && ! empty( $cat['points_published_at'] ) ) {
				echo ' <span class="description">' . esc_html( mysql2date( get_option( 'date_format' ), $cat['points_published_at'] ) ) . '</span>';
			}
			if ( 'outdated' === $eval['state'] ) {
				echo '<p class="description">' . esc_html( $eval['complete'] ? __( 'Els resultats han canviat després de publicar.', 'padelcamps' ) : __( 'S’ha reobert un resultat: la categoria ja no està acabada.', 'padelcamps' ) ) . '</p>';
			}
			if ( $eval['complete'] && $eval['pairs'] ) {
				echo '<details><summary>' . esc_html__( 'Veure els punts', 'padelcamps' ) . '</summary><table class="widefat striped"><tbody>';
				foreach ( $eval['pairs'] as $reg_id => $pair ) {
					echo '<tr><td>' . esc_html( $pair['names'] ?: '#' . $reg_id ) . '</td><td>';
					if ( null === $pair['result'] ) {
						echo '<em>' . esc_html__( 'Baixa: sense punts', 'padelcamps' ) . '</em></td><td>—';
					} else {
						echo esc_html( $labels[ $pair['result'] ] ?? $pair['result'] ) . '</td><td><strong>' . (int) $pair['points'] . '</strong>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table></details>';
			}
			echo '</td><td>';
			if ( in_array( $eval['state'], array( 'ready', 'outdated' ), true ) && $eval['complete'] ) {
				$this->form( $tid, $cid, 'publish' );
				echo '<button class="button button-small button-primary">' . esc_html( 'ready' === $eval['state'] ? __( 'Publica els punts', 'padelcamps' ) : __( 'Actualitza els punts', 'padelcamps' ) ) . '</button></form> ';
			}
			if ( in_array( $eval['state'], array( 'published', 'outdated' ), true ) ) {
				$this->form( $tid, $cid, 'unpublish', __( 'Segur que vols retirar aquests punts del rànquing?', 'padelcamps' ) );
				echo '<button class="button button-small">' . esc_html__( 'Retira els punts', 'padelcamps' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function form( int $tid, int $cid, string $op, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="padelcamps_ranking_points"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="tid" value="' . esc_attr( (string) $tid ) . '"><input type="hidden" name="cat" value="' . esc_attr( (string) $cid ) . '">';
		wp_nonce_field( 'padelcamps_ranking_points' );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( 'padelcamps_ranking_points' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$post = static fn( string $k ): string => isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		// phpcs:enable
		$tid = absint( $post( 'tid' ) );
		$cid = absint( $post( 'cat' ) );
		if ( ! $tid || ! $this->tournaments->find( $tid ) ) {
			wp_safe_redirect( TournamentsPage::url( array( 'msg' => 'not_found' ) ) );
			exit;
		}
		$category = $cid ? $this->tournaments->category( $cid ) : null;
		if ( ! $category || (int) $category['tournament_id'] !== $tid ) {
			wp_safe_redirect( self::url( $tid, array( 'perr' => 'not_found' ) ) );
			exit;
		}
		$op    = sanitize_key( $post( 'op' ) );
		$error = 'unpublish' === $op ? $this->service->unpublish( $cid ) : ( 'publish' === $op ? $this->service->publish( $cid ) : 'not_found' );
		wp_safe_redirect( self::url( $tid, null === $error ? array( 'pmsg' => 'unpublish' === $op ? 'unpublished' : 'published' ) : array( 'perr' => $error ) ) );
		exit;
	}
}
