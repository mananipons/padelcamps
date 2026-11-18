<?php
namespace PadelCamps\Frontend;

use PadelCamps\Service\PublicDraw;
use PadelCamps\Service\PublicSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * Club TV screen: https://site/?padelcamps_tv=ID (public tournaments only).
 * Standalone full-screen page: clock, "now and next" matches and one category
 * at a time (groups + brackets) rotating every few seconds. Refreshes itself
 * through the same live view endpoint as the shortcodes.
 */
final class TvPage {

	public const PARAM  = 'padelcamps_tv';
	public const ROTATE = 20;

	public static function url( int $tournament_id ): string {
		return add_query_arg( self::PARAM, $tournament_id, home_url( '/' ) );
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 0 );
	}

	public function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only page.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$t = ( new PublicDraw() )->tournament( absint( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( ! $t ) {
			status_header( 404 );
			echo '<!doctype html><meta charset="utf-8"><title>404</title><p>' . esc_html__( 'Aquest torneig no està disponible.', 'padelcamps' ) . '</p>';
			exit;
		}
		$inner = self::inner( $t );
		$i18n  = array(
			/* translators: %d: number of matches found */
			'found'   => __( '%d partits trobats', 'padelcamps' ),
			'none'    => __( 'Cap partit amb aquest nom', 'padelcamps' ),
			'updated' => __( 'Actualitzat', 'padelcamps' ),
		);

		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( $t['name'] ) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url( PADELCAMPS_URL . 'assets/css/draw.css?ver=' . PADELCAMPS_VERSION . '.' . filemtime( PADELCAMPS_PATH . 'assets/css/draw.css' ) ) . '">';
		echo '<link rel="stylesheet" href="' . esc_url( PADELCAMPS_URL . 'assets/css/tv.css?ver=' . PADELCAMPS_VERSION . '.' . filemtime( PADELCAMPS_PATH . 'assets/css/tv.css' ) ) . '">';
		echo '</head><body class="pc-tv-body">';
		echo str_replace( '<div class="pc-draw pc-tv"', '<div class="pc-draw pc-tv" data-pc-rotate="' . (int) self::ROTATE . '"', LiveView::wrap( 'tv', $t, 0, $inner, true, false, 'pc-draw pc-tv' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by LiveView/inner().
		echo '<script>var padelcampsPublic = ' . wp_json_encode( $i18n ) . ';</script>';
		echo '<script src="' . esc_url( PADELCAMPS_URL . 'assets/js/public.js?ver=' . PADELCAMPS_VERSION . '.' . filemtime( PADELCAMPS_PATH . 'assets/js/public.js' ) ) . '"></script>';
		echo '</body></html>';
		exit;
	}

	/** TV content: header, now/next list, one panel per category. */
	public static function inner( array $t ): string {
		$draw     = ( new PublicDraw() )->build( $t );
		$schedule = PublicSchedule::upcoming( ( new PublicSchedule() )->build( $t ) );
		$renderer = new DrawShortcode();

		$h  = '<header class="pc-tv-head"><h1>' . esc_html( $t['name'] ) . '</h1><span class="pc-tv-clock" aria-hidden="true"></span></header>';
		$h .= '<div class="pc-tv-grid"><section class="pc-tv-next"><h2>' . esc_html( $schedule['label'] ) . '</h2>';
		if ( $schedule['matches'] ) {
			$h .= '<ol class="pc-sched">';
			foreach ( $schedule['matches'] as $m ) {
				$h .= ScheduleShortcode::item( $m );
			}
			$h .= '</ol>';
		} else {
			$h .= '<p class="pc-draw-notice">' . esc_html__( 'No hi ha partits programats.', 'padelcamps' ) . '</p>';
		}
		$h .= '</section><section class="pc-tv-panels">';
		$drawn = array_filter( $draw['categories'], static fn( $c ) => $c['drawn'] );
		foreach ( $drawn as $c ) {
			$h .= '<div class="pc-tv-panel">' . $renderer->category( $c ) . '</div>';
		}
		if ( ! $drawn ) {
			$h .= '<p class="pc-draw-notice">' . esc_html__( 'Encara no s’ha fet el sorteig.', 'padelcamps' ) . '</p>';
		}
		return $h . '</section></div>';
	}
}
