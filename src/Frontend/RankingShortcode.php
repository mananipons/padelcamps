<?php
namespace PadelCamps\Frontend;

use PadelCamps\Service\PublicRanking;

defined( 'ABSPATH' ) || exit;

/**
 * Public season ranking: [padelcamps_ranking] (current season) or
 * [padelcamps_ranking season="3" category="12"]. Without category="" the visitor picks
 * the category with links (?pc_rank_cat=ID). Server-rendered: no JavaScript needed.
 * Only name, tournaments played and points are shown (see Service\PublicRanking).
 */
final class RankingShortcode {

	public const TAG   = 'padelcamps_ranking';
	public const QUERY = 'pc_rank_cat';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'season'   => 0,
				'category' => 0,
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		wp_enqueue_style( 'padelcamps-draw', PADELCAMPS_URL . 'assets/css/draw.css', array(), PADELCAMPS_VERSION );

		$service = new PublicRanking();
		$season  = $service->season( absint( $atts['season'] ) );
		if ( ! $season ) {
			return '<div class="pc-draw pc-ranking"><p class="pc-draw-notice">' . esc_html__( 'Aquest rànquing no està disponible.', 'padelcamps' ) . '</p></div>';
		}

		$fixed    = absint( $atts['category'] );
		$requested = $fixed ?: ( isset( $_GET[ self::QUERY ] ) ? absint( $_GET[ self::QUERY ] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public filter, absint().
		return $this->inner( $service->build( $season, $requested ), 0 === $fixed );
	}

	/** Container with the season title, the category links (when the visitor can choose) and the table. */
	public function inner( array $data, bool $with_nav = true ): string {
		$sid = (int) $data['season']['id'];
		$h   = '<div class="pc-draw pc-ranking" id="pc-ranking-' . $sid . '"><h2>' . esc_html( $data['season']['name'] ) . '</h2>';

		if ( ! $data['category'] ) {
			return $h . '<p class="pc-draw-notice">' . esc_html__( 'Aquesta temporada encara no té categories de rànquing.', 'padelcamps' ) . '</p></div>';
		}

		if ( $with_nav && count( $data['categories'] ) > 1 ) {
			$h .= '<nav class="pc-draw-cats" aria-label="' . esc_attr__( 'Categories', 'padelcamps' ) . '">';
			foreach ( $data['categories'] as $c ) {
				$active = $c['id'] === $data['category']['id'];
				$url    = add_query_arg( self::QUERY, $c['id'] ) . '#pc-ranking-' . $sid;
				$h     .= '<a href="' . esc_url( $url ) . '"' . ( $active ? ' class="is-active" aria-current="true"' : '' ) . '>' . esc_html( $c['name'] ) . '</a>';
			}
			$h .= '</nav>';
		}

		$h .= '<section class="pc-draw-cat"><h3>' . esc_html( $data['category']['name'] ) . '</h3>';
		if ( ! $data['ranking'] ) {
			return $h . '<p class="pc-draw-notice">' . esc_html__( 'Encara no hi ha cap punt en aquest rànquing.', 'padelcamps' ) . '</p></section></div>';
		}
		$h .= '<div class="pc-table-wrap"><table class="pc-standings"><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__( 'Jugador', 'padelcamps' ) . '</th><th scope="col">' . esc_html__( 'Tornejos', 'padelcamps' ) . '</th><th scope="col">' . esc_html__( 'Punts', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $data['ranking'] as $row ) {
			$h .= '<tr><td>' . (int) $row['position'] . '</td><td class="pc-name">' . esc_html( $row['name'] ) . '</td><td>' . (int) $row['tournaments'] . '</td><td><strong>' . (int) $row['points'] . '</strong></td></tr>';
		}
		return $h . '</tbody></table></div></section></div>';
	}
}
