<?php
namespace PadelCamps\Frontend;

use PadelCamps\Service\PublicDraw;

defined( 'ABSPATH' ) || exit;

/**
 * Public groups and brackets: [padelcamps_draw id="3"] or
 * [padelcamps_draw id="3" category="6"]. Shows group standings and matches,
 * the knockout bracket with every future round ("Winner M3"), and a
 * provisional bracket ("1st group A") while the groups are being played.
 * Desktop: rounds as columns. Mobile: one round per screen, swipe between them.
 */
final class DrawShortcode {

	public const TAG = 'padelcamps_draw';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'category' => 0,
				'live'     => '1',
				'find'     => '1',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		LiveView::enqueue();

		$public = new PublicDraw();
		$t      = $public->tournament( absint( $atts['id'] ) );
		if ( ! $t ) {
			return '<div class="pc-draw"><p class="pc-draw-notice">' . esc_html__( 'Aquest torneig no està disponible.', 'padelcamps' ) . '</p></div>';
		}
		$category = absint( $atts['category'] );
		$inner    = $this->inner( $public->build( $t, $category ) );
		return LiveView::wrap( 'draw', $t, $category, $inner, '0' !== (string) $atts['live'], '0' !== (string) $atts['find'], 'pc-draw' );
	}

	/** Categories, groups and brackets (no container): also used by the live refresh and the TV. */
	public function inner( array $data ): string {
		$h = '';
		if ( count( $data['categories'] ) > 1 ) {
			$h .= '<nav class="pc-draw-cats" aria-label="' . esc_attr__( 'Categories', 'padelcamps' ) . '">';
			foreach ( $data['categories'] as $c ) {
				$h .= '<a href="#pc-draw-' . (int) $c['id'] . '">' . esc_html( $c['name'] ) . '</a>';
			}
			$h .= '</nav>';
		}
		foreach ( $data['categories'] as $c ) {
			$h .= $this->category( $c );
		}
		return $h;
	}

	public function category( array $c ): string {
		$h = '<section class="pc-draw-cat" id="pc-draw-' . (int) $c['id'] . '"><h2>' . esc_html( $c['name'] ) . '</h2>';
		if ( ! $c['drawn'] ) {
			return $h . '<p class="pc-draw-notice">' . esc_html__( 'Encara no s’ha fet el sorteig d’aquesta categoria.', 'padelcamps' ) . '</p></section>';
		}

		if ( $c['groups'] ) {
			if ( count( $c['groups'] ) > 1 ) {
				$h .= '<h3>' . esc_html__( 'Fase de grups', 'padelcamps' ) . '</h3>';
			}
			$h .= '<div class="pc-groups">';
			foreach ( $c['groups'] as $g ) {
				$h .= $this->group( $g );
			}
			$h .= '</div>';
		}

		if ( $c['bracket'] && $c['bracket']['rounds'] ) {
			$h .= $this->bracket( (int) $c['id'], $c['bracket'], __( 'Quadre final', 'padelcamps' ) );
		}
		if ( ! empty( $c['consolation']['rounds'] ) ) {
			$h .= $this->bracket( (int) $c['id'] * 1000, $c['consolation'], __( 'Quadre de consolació', 'padelcamps' ) );
		}
		return $h . '</section>';
	}

	private function group( array $g ): string {
		$h  = '<div class="pc-group"><h4>' . esc_html( $g['title'] ) . '</h4>';
		$h .= '<div class="pc-table-wrap"><table class="pc-standings"><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__( 'Parella', 'padelcamps' ) . '</th>';
		$h .= '<th scope="col" title="' . esc_attr__( 'Jugats', 'padelcamps' ) . '">' . esc_html__( 'PJ', 'padelcamps' ) . '</th>';
		$h .= '<th scope="col" title="' . esc_attr__( 'Guanyats', 'padelcamps' ) . '">' . esc_html__( 'G', 'padelcamps' ) . '</th>';
		$h .= '<th scope="col" title="' . esc_attr__( 'Perduts', 'padelcamps' ) . '">' . esc_html__( 'P', 'padelcamps' ) . '</th>';
		$h .= '<th scope="col">' . esc_html__( 'Sets', 'padelcamps' ) . '</th><th scope="col">' . esc_html__( 'Jocs', 'padelcamps' ) . '</th></tr></thead><tbody>';
		foreach ( $g['rows'] as $r ) {
			$h .= '<tr' . ( $r['qualifies'] ? ' class="is-qualified"' : '' ) . '><td>' . (int) $r['position'] . '</td><td class="pc-name">' . esc_html( $r['name'] ) . '</td>';
			$h .= '<td>' . (int) $r['played'] . '</td><td>' . (int) $r['won'] . '</td><td>' . (int) $r['lost'] . '</td>';
			$h .= '<td>' . (int) $r['sets'][0] . '-' . (int) $r['sets'][1] . '</td><td>' . (int) $r['games'][0] . '-' . (int) $r['games'][1] . '</td></tr>';
		}
		$h .= '</tbody></table></div>';

		if ( $g['matches'] ) {
			$h .= '<ul class="pc-group-matches">';
			foreach ( $g['matches'] as $m ) {
				$h .= '<li>' . $this->match_box( $m, null ) . '</li>';
			}
			$h .= '</ul>';
		}
		return $h . '</div>';
	}

	private function bracket( int $cid, array $b, string $title ): string {
		$h = '<h3>' . esc_html( $title ) . '</h3>';
		if ( $b['provisional'] ) {
			$h .= '<p class="pc-draw-notice">' . esc_html__( 'Quadre provisional: les places es completaran quan acabi la fase de grups. L’ordre dels classificats pot variar segons els resultats.', 'padelcamps' ) . '</p>';
		}
		if ( count( $b['rounds'] ) > 1 ) {
			$h .= '<nav class="pc-rounds-nav" aria-label="' . esc_attr__( 'Rondes', 'padelcamps' ) . '">';
			foreach ( $b['rounds'] as $r ) {
				$h .= '<a href="#pc-r-' . $cid . '-' . (int) $r['round'] . '">' . esc_html( $r['label'] ) . '</a>';
			}
			$h .= '</nav>';
		}
		$h .= '<div class="pc-bracket" style="--pc-rounds:' . count( $b['rounds'] ) . '">';
		foreach ( $b['rounds'] as $r ) {
			$h .= '<div class="pc-round" id="pc-r-' . $cid . '-' . (int) $r['round'] . '"><h4>' . esc_html( $r['label'] ) . '</h4><div class="pc-round-matches">';
			foreach ( $r['matches'] as $m ) {
				$h .= $this->match_box( $m, (int) $m['number'] );
			}
			$h .= '</div></div>';
		}
		return $h . '</div>';
	}

	private function side( array $s ): string {
		$classes = 'pc-m-side' . ( ! empty( $s['winner'] ) ? ' is-winner' : '' ) . ( null === $s['name'] ? ' is-placeholder' : '' );
		$h       = '<div class="' . $classes . '"><span class="pc-m-name">';
		$h      .= esc_html( null !== $s['name'] ? $s['name'] : (string) ( $s['placeholder'] ?? '' ) );
		if ( ! empty( $s['hint'] ) ) {
			$h .= ' <small>(' . esc_html( $s['hint'] ) . ')</small>';
		}
		$h .= '</span><span class="pc-m-sets">';
		foreach ( $s['sets'] as $games ) {
			$h .= '<span>' . (int) $games . '</span>';
		}
		return $h . '</span></div>';
	}

	private function match_box( array $m, ?int $number ): string {
		$h = '<div class="pc-m' . ( ! empty( $m['bye'] ) ? ' is-bye' : '' ) . '">';
		if ( null !== $number ) {
			/* translators: %d: match number */
			$h .= '<span class="pc-m-n">' . esc_html( sprintf( __( 'P%d', 'padelcamps' ), $number ) ) . '</span>';
		}
		$h .= $this->side( $m['a'] ) . $this->side( $m['b'] );
		if ( empty( $m['bye'] ) && ( ! empty( $m['when'] ) || ! empty( $m['court'] ) ) ) {
			$h .= '<span class="pc-m-when">' . esc_html( implode( ' · ', array_filter( array( $m['when'] ?? null, $m['court'] ?? null ) ) ) ) . '</span>';
		}
		if ( ! empty( $m['bye'] ) ) {
			$h .= '<span class="pc-m-note">' . esc_html__( 'Passa directament', 'padelcamps' ) . '</span>';
		} elseif ( ! empty( $m['note'] ) ) {
			$h .= '<span class="pc-m-note">' . esc_html( $m['note'] ) . '</span>';
		}
		return $h . '</div>';
	}
}
