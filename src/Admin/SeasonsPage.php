<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\SeasonRepository;
use PadelCamps\Engine\PointsScheme;
use PadelCamps\Engine\PublicRanking;

defined( 'ABSPATH' ) || exit;

/**
 * Seasons screen: a season groups the tournaments that count for one ranking.
 * It holds the points scheme and the season's own ranking categories
 * (1a/2a/3a, B+/B/C… depending on the area) that tournament categories link to.
 */
final class SeasonsPage {

	public const SLUG = 'padelcamps-seasons';

	private SeasonRepository $repo;

	public function __construct( SeasonRepository $repo ) {
		$this->repo = $repo;
	}

	public function register(): void {
		foreach ( array( 'save_season', 'delete_season' ) as $action ) {
			add_action( 'admin_post_padelcamps_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	// --- Labels (literal calls so the translation scanner finds them) -------

	/** @return array<string,string> scheme key => label */
	public static function points_labels(): array {
		return array(
			'champion'      => __( 'Campions', 'padelcamps' ),
			'runner_up'     => __( 'Finalistes', 'padelcamps' ),
			'semifinal'     => __( 'Semifinalistes', 'padelcamps' ),
			'quarterfinal'  => __( 'Quarts de final', 'padelcamps' ),
			'round_of_16'   => __( 'Vuitens de final', 'padelcamps' ),
			'participation' => __( 'Participació (la resta)', 'padelcamps' ),
		);
	}

	/** @return array{0:string, 1:string} [notice type, text] */
	private static function message( string $code ): array {
		$messages = array(
			'created'       => array( 'success', __( 'Temporada creada.', 'padelcamps' ) ),
			'saved'         => array( 'success', __( 'Temporada desada.', 'padelcamps' ) ),
			'saved_warn'    => array( 'warning', __( 'Temporada desada, però hi ha un resultat que dona més punts que un de millor. Revisa l’esquema de punts.', 'padelcamps' ) ),
			'deleted'       => array( 'success', __( 'Temporada eliminada. Els seus tornejos es conserven, però ja no compten per a cap rànquing, i se n’han eliminat els punts.', 'padelcamps' ) ),
			'err_name'      => array( 'error', __( 'Cal indicar un nom.', 'padelcamps' ) ),
			'err_dates'     => array( 'error', __( 'La data de final no pot ser anterior a la d’inici.', 'padelcamps' ) ),
			'err_duplicate' => array( 'error', __( 'Hi ha dues categories de rànquing amb el mateix nom.', 'padelcamps' ) ),
			'not_found'     => array( 'error', __( 'No s’ha trobat la temporada.', 'padelcamps' ) ),
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
		// phpcs:enable

		if ( 'new' === $action ) {
			$this->render_form( null );
			return;
		}
		if ( 'edit' === $action ) {
			$season = $id ? $this->repo->find( $id ) : null;
			if ( ! $season ) {
				$this->redirect( array( 'msg' => 'not_found' ) );
			}
			$this->render_form( $season );
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
		list( $type, $text ) = self::message( $code );
		if ( '' !== $text ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private function render_list(): void {
		$rows = $this->repo->all();

		echo '<div class="wrap pc-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Temporades', 'padelcamps' ) . '</h1> ';
		echo '<a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Afegeix una temporada', 'padelcamps' ) . '</a>';
		echo '<hr class="wp-header-end">';
		$this->render_notice();

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Encara no hi ha cap temporada. Una temporada agrupa els tornejos que compten per a un mateix rànquing.', 'padelcamps' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nom', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Dates', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Tornejos', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'Categories de rànquing', 'padelcamps' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$edit  = self::url( array( 'action' => 'edit', 'id' => (int) $row['id'] ) );
			$dates = self::fmt_date( $row['starts_on'] );
			if ( $row['ends_on'] ) {
				$dates .= ' – ' . self::fmt_date( $row['ends_on'] );
			}
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $row['name'] ) . '</a></strong>';
			echo '<div class="row-actions"><span class="edit"><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edita', 'padelcamps' ) . '</a></span></div></td>';
			echo '<td>' . esc_html( $dates ) . '</td>';
			/* translators: 1: tournaments that count for the ranking, 2: tournaments in the season */
			echo '<td>' . esc_html( sprintf( __( '%1$d de %2$d amb punts', 'padelcamps' ), (int) $row['ranked_count'], (int) $row['tournaments_count'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $row['categories_count'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_form( ?array $season ): void {
		$is_new     = null === $season;
		$scheme     = $is_new ? PointsScheme::defaults() : $this->repo->scheme( $season );
		$categories = $is_new ? array() : $this->repo->ranking_categories( (int) $season['id'] );

		echo '<div class="wrap pc-wrap"><h1 class="pc-title">' . ( $is_new ? esc_html__( 'Nova temporada', 'padelcamps' ) : esc_html( $season['name'] ) ) . '</h1>';
		echo '<p class="pc-back"><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'Totes les temporades', 'padelcamps' ) . '</a></p>';
		$this->render_notice();

		echo '<div class="pc-panel pc-panel--solo">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_save_season">';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $is_new ? 0 : (int) $season['id'] ) ) . '">';
		wp_nonce_field( 'padelcamps_save_season' );

		echo '<h2>' . esc_html__( 'Dades generals', 'padelcamps' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$this->row_text( 'name', __( 'Nom', 'padelcamps' ), $season['name'] ?? '', true );
		$this->row_text( 'starts_on', __( 'Data d’inici', 'padelcamps' ), $season['starts_on'] ?? '', false, 'date' );
		$this->row_text( 'ends_on', __( 'Data de final', 'padelcamps' ), $season['ends_on'] ?? '', false, 'date' );
		echo '</table>';

		$mode = $is_new ? 'initials' : $this->repo->public_names( $season );
		echo '<hr><h2>' . esc_html__( 'Rànquing públic', 'padelcamps' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="pc-public-names">' . esc_html__( 'Noms al rànquing públic', 'padelcamps' ) . '</label></th><td><select id="pc-public-names" name="public_names">';
		printf( '<option value="initials"%s>%s</option>', selected( $mode, 'initials', false ), esc_html__( 'Nom i inicial del primer cognom (Anna P.)', 'padelcamps' ) );
		printf( '<option value="full"%s>%s</option>', selected( $mode, 'full', false ), esc_html__( 'Nom i cognoms complets', 'padelcamps' ) );
		echo '</select><p class="description">' . esc_html__( 'El rànquing públic només mostra el nom, els tornejos jugats i els punts: mai el DNI, el correu, el telèfon ni el club. Només hi surten els punts publicats i els ajustos manuals.', 'padelcamps' ) . '</p></td></tr>';
		if ( ! $is_new ) {
			$sid = (int) $season['id'];
			echo '<tr><th scope="row">' . esc_html__( 'Shortcode', 'padelcamps' ) . '</th><td><code>[padelcamps_ranking season="' . $sid . '"]</code>';
			echo '<p class="description">' . esc_html__( 'Enganxa’l en una pàgina. També hi ha el bloc «PadelCamps: rànquing» a l’editor. Amb category="ID" fixes una categoria de rànquing.', 'padelcamps' ) . '</p></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'API pública (JSON)', 'padelcamps' ) . '</th><td><code>' . esc_html( rest_url( 'padelcamps/v1/seasons/' . $sid . '/ranking' ) ) . '</code></td></tr>';
		}
		echo '</table>';

		echo '<hr><h2>' . esc_html__( 'Punts per resultat', 'padelcamps' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Punts que rep cada jugador segons la ronda més llunyana que assoleix la seva parella en una categoria. Una temporada acumula els punts de tots els seus tornejos amb punts.', 'padelcamps' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		foreach ( self::points_labels() as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="pc-points-%1$s">%2$s</label></th><td><input type="number" min="0" max="%3$d" step="1" id="pc-points-%1$s" name="points[%1$s]" value="%4$d" class="small-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				PointsScheme::MAX_POINTS,
				(int) $scheme[ $key ]
			);
		}
		echo '</table>';

		echo '<hr><h2>' . esc_html__( 'Categories de rànquing', 'padelcamps' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Els noms de categoria de la teva zona (1a, 2a, 3a… o B+, B, C…). Cada categoria d’un torneig s’enllaça amb una d’aquestes, i el rànquing es fa separat per categoria. Deixa un nom buit per eliminar-lo: les categories de torneig enllaçades quedaran sense categoria de rànquing i se’n retiraran els punts publicats.', 'padelcamps' ) . '</p>';
		echo '<div id="pc-rc-rows">';
		foreach ( $categories as $rc ) {
			$uses = $this->repo->ranking_category_usage( (int) $rc['id'] );
			echo '<p class="pc-rc-row"><input type="text" name="rc[' . (int) $rc['id'] . ']" value="' . esc_attr( $rc['name'] ) . '" maxlength="120" class="regular-text" aria-label="' . esc_attr__( 'Categoria de rànquing', 'padelcamps' ) . '">';
			if ( $uses > 0 ) {
				/* translators: %d: tournament categories linked to this ranking category */
				echo ' <span class="description">' . esc_html( sprintf( _n( '%d categoria de torneig enllaçada', '%d categories de torneig enllaçades', $uses, 'padelcamps' ), $uses ) ) . '</span>';
			}
			echo '</p>';
		}
		$blank = $categories ? 1 : 3;
		for ( $i = 0; $i < $blank; $i++ ) {
			echo '<p class="pc-rc-row"><input type="text" name="rc_new[]" value="" maxlength="120" class="regular-text" placeholder="' . esc_attr__( 'Nova categoria', 'padelcamps' ) . '" aria-label="' . esc_attr__( 'Nova categoria de rànquing', 'padelcamps' ) . '"></p>';
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="pc-rc-add">' . esc_html__( 'Afegeix una altra categoria', 'padelcamps' ) . '</button></p>';

		printf( '<p><button type="submit" class="button button-primary">%s</button></p>', $is_new ? esc_html__( 'Crea la temporada', 'padelcamps' ) : esc_html__( 'Desa la temporada', 'padelcamps' ) );
		echo '</form>';

		echo '<script>(function(){var b=document.getElementById("pc-rc-add"),w=document.getElementById("pc-rc-rows");if(!b||!w)return;b.addEventListener("click",function(){var r=w.querySelector("input[name=\'rc_new[]\']");if(!r)return;var p=r.parentNode.cloneNode(true);p.querySelector("input").value="";w.appendChild(p);p.querySelector("input").focus();});})();</script>';

		if ( ! $is_new ) {
			echo '<hr><h2>' . esc_html__( 'Zona de perill', 'padelcamps' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Eliminar la temporada no esborra els tornejos: només deixen d’estar-hi enllaçats i de comptar per al rànquing. Els punts publicats de la temporada s’eliminen.', 'padelcamps' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Segur que vols eliminar aquesta temporada?', 'padelcamps' ) ) . '\');">';
			echo '<input type="hidden" name="action" value="padelcamps_delete_season">';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) (int) $season['id'] ) . '">';
			wp_nonce_field( 'padelcamps_delete_season' );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Elimina la temporada', 'padelcamps' ) . '</button></form>';
		}
		echo '</div></div>';
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

	/** @return array<int|string,string> raw posted array of strings (non-strings dropped). */
	private static function post_list( string $key ): array {
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in guard(), sanitized by the caller.
		return array_filter( $raw, 'is_string' );
	}

	private static function clean_date( string $value ): ?string {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $value;
		}
		return null;
	}

	private static function clean_name( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, 120 );
	}

	public function handle_save_season(): void {
		$this->guard( 'padelcamps_save_season' );

		$id   = absint( self::post_str( 'id' ) );
		$back = $id ? array( 'action' => 'edit', 'id' => $id ) : array( 'action' => 'new' );
		$data = array(
			'name'      => sanitize_text_field( self::post_str( 'name' ) ),
			'starts_on' => self::clean_date( self::post_str( 'starts_on' ) ),
			'ends_on'   => self::clean_date( self::post_str( 'ends_on' ) ),
			'points'    => PointsScheme::normalize( self::post_list( 'points' ) ),
		);
		// Only when the form had the selector; unknown values fall back to the private mode.
		if ( isset( $_POST['public_names'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$data['public_names'] = PublicRanking::mode( self::post_str( 'public_names' ) );
		}

		if ( '' === $data['name'] ) {
			$this->redirect( $back + array( 'msg' => 'err_name' ) );
		}
		if ( $data['starts_on'] && $data['ends_on'] && $data['ends_on'] < $data['starts_on'] ) {
			$this->redirect( $back + array( 'msg' => 'err_dates' ) );
		}
		if ( $id && ! $this->repo->find( $id ) ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}

		// Ranking categories: existing ones by id (empty name = remove), plus new ones.
		$valid_ids = $id ? array_map( 'intval', wp_list_pluck( $this->repo->ranking_categories( $id ), 'id' ) ) : array();
		$existing  = array();
		foreach ( self::post_list( 'rc' ) as $rc_id => $name ) {
			$name = self::clean_name( $name );
			if ( '' !== $name && in_array( (int) $rc_id, $valid_ids, true ) ) {
				$existing[ (int) $rc_id ] = $name;
			}
		}
		$new = array();
		foreach ( self::post_list( 'rc_new' ) as $name ) {
			$name = self::clean_name( $name );
			if ( '' !== $name ) {
				$new[] = $name;
			}
		}
		$seen = array();
		foreach ( array_merge( array_values( $existing ), $new ) as $name ) {
			$key = mb_strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				$this->redirect( $back + array( 'msg' => 'err_duplicate' ) );
			}
			$seen[ $key ] = true;
		}

		if ( $id ) {
			$this->repo->update( $id, $data );
		} else {
			$id = $this->repo->create( $data );
		}
		$this->repo->sync_ranking_categories( $id, $existing, $new );

		$msg = ! PointsScheme::is_descending( $data['points'] ) ? 'saved_warn' : ( $back['action'] === 'new' ? 'created' : 'saved' );
		$this->redirect( array( 'action' => 'edit', 'id' => $id, 'msg' => $msg ) );
	}

	public function handle_delete_season(): void {
		$this->guard( 'padelcamps_delete_season' );

		$id = absint( self::post_str( 'id' ) );
		if ( ! $id || ! $this->repo->find( $id ) ) {
			$this->redirect( array( 'msg' => 'not_found' ) );
		}
		$this->repo->delete( $id );
		$this->redirect( array( 'msg' => 'deleted' ) );
	}
}
