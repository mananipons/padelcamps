<?php
namespace PadelCamps\Frontend;

use PadelCamps\Service\PublicDraw;
use PadelCamps\Service\PublicSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * Public order of play: [padelcamps_schedule id="3"] (optional category="6",
 * live="0", find="0"). Matches by day, time and court, with results when played.
 */
final class ScheduleShortcode {

	public const TAG = 'padelcamps_schedule';

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

		$t = ( new PublicDraw() )->tournament( absint( $atts['id'] ) );
		if ( ! $t ) {
			return '<div class="pc-schedule"><p class="pc-draw-notice">' . esc_html__( 'Aquest torneig no està disponible.', 'padelcamps' ) . '</p></div>';
		}
		$category = absint( $atts['category'] );
		$inner    = $this->inner( $t, ( new PublicSchedule() )->build( $t, $category ) );
		return LiveView::wrap( 'schedule', $t, $category, $inner, '0' !== (string) $atts['live'], '0' !== (string) $atts['find'], 'pc-draw pc-schedule' );
	}

	/** Days with their matches (no container). */
	public function inner( array $t, array $data ): string {
		if ( ! $data['days'] ) {
			return '<p class="pc-draw-notice">' . esc_html__( 'Encara no s’han publicat els horaris.', 'padelcamps' ) . '</p>';
		}
		$today = current_time( 'Y-m-d' );
		$h     = '';
		if ( count( $data['days'] ) > 1 ) {
			$h .= '<nav class="pc-draw-cats" aria-label="' . esc_attr__( 'Dies', 'padelcamps' ) . '">';
			foreach ( $data['days'] as $day ) {
				$h .= '<a href="#pc-day-' . (int) $t['id'] . '-' . esc_attr( $day['date'] ) . '"' . ( $day['date'] === $today ? ' class="is-today"' : '' ) . '>' . esc_html( $day['date'] === $today ? __( 'Avui', 'padelcamps' ) : $day['label'] ) . '</a>';
			}
			$h .= '</nav>';
		}
		foreach ( $data['days'] as $day ) {
			$h .= '<section class="pc-day" id="pc-day-' . (int) $t['id'] . '-' . esc_attr( $day['date'] ) . '"><h3>' . esc_html( $day['label'] ) . '</h3><ol class="pc-sched">';
			foreach ( $day['matches'] as $m ) {
				$h .= self::item( $m );
			}
			$h .= '</ol></section>';
		}
		return $h;
	}

	/** One match row: time · court | category · stage | pairs | score. */
	public static function item( array $m ): string {
		$pair = static function ( ?string $name, bool $won ) {
			$text = null === $name ? __( 'Per determinar', 'padelcamps' ) : $name;
			return '<span class="pc-sched-pair pc-m-name' . ( $won ? ' is-winner' : '' ) . ( null === $name ? ' is-placeholder' : '' ) . '">' . esc_html( $text ) . '</span>';
		};
		$h  = '<li class="pc-sched-item pc-m' . ( $m['played'] ? ' is-played' : '' ) . '">';
		$h .= '<span class="pc-sched-when"><strong>' . esc_html( $m['time'] ) . '</strong>' . ( '' !== $m['court'] ? '<span>' . esc_html( $m['court'] ) . '</span>' : '' ) . '</span>';
		$h .= '<span class="pc-sched-what">' . esc_html( $m['category'] ) . ' · ' . esc_html( $m['stage'] ) . '</span>';
		$h .= '<span class="pc-sched-pairs">' . $pair( $m['a'], 'a' === $m['winner'] ) . '<span class="pc-sched-vs">' . esc_html__( 'contra', 'padelcamps' ) . '</span>' . $pair( $m['b'], 'b' === $m['winner'] ) . '</span>';
		$h .= '<span class="pc-sched-score">' . esc_html( $m['score'] ) . '</span>';
		return $h . '</li>';
	}
}
