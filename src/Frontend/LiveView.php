<?php
namespace PadelCamps\Frontend;

use PadelCamps\Service\PublicDraw;
use PadelCamps\Service\PublicSchedule;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing of the public views (draws, order of play, TV):
 *  - render(): the inner HTML of a view, always built on the server;
 *  - wrap(): the container with live refresh (data-pc-live → REST view endpoint,
 *    replaced only when the content hash changes) and the "find your pair" box.
 * The REST endpoint GET /padelcamps/v1/tournaments/{id}/view?type=… returns
 * {hash, html} using the same render(), so the page and the refresh never differ.
 */
final class LiveView {

	public const TYPES    = array( 'draw', 'schedule', 'tv' );
	public const INTERVAL = 30;

	public static function enqueue(): void {
		wp_enqueue_style( 'padelcamps-draw', PADELCAMPS_URL . 'assets/css/draw.css', array(), PADELCAMPS_VERSION );
		wp_enqueue_script( 'padelcamps-public', PADELCAMPS_URL . 'assets/js/public.js', array(), PADELCAMPS_VERSION, true );
		wp_localize_script(
			'padelcamps-public',
			'padelcampsPublic',
			array(
				/* translators: %d: number of matches found */
				'found'   => __( '%d partits trobats', 'padelcamps' ),
				'none'    => __( 'Cap partit amb aquest nom', 'padelcamps' ),
				'updated' => __( 'Actualitzat', 'padelcamps' ),
			)
		);
	}

	/** Inner HTML of a view, or '' when the tournament is not public. */
	public static function render( string $type, array $t, int $category = 0 ): string {
		switch ( $type ) {
			case 'schedule':
				return ( new ScheduleShortcode() )->inner( $t, ( new PublicSchedule() )->build( $t, $category ) );
			case 'tv':
				return TvPage::inner( $t );
			default:
				return ( new DrawShortcode() )->inner( ( new PublicDraw() )->build( $t, $category ) );
		}
	}

	public static function hash( string $html ): string {
		return substr( md5( $html ), 0, 16 );
	}

	/** Container with live refresh and (optionally) the find box. */
	public static function wrap( string $type, array $t, int $category, string $inner, bool $live, bool $find, string $class ): string {
		$url = add_query_arg(
			array_filter(
				array(
					'type'     => $type,
					'category' => $category ?: null,
				)
			),
			rest_url( 'padelcamps/v1/tournaments/' . (int) $t['id'] . '/view' )
		);
		$h  = '<div class="' . esc_attr( $class ) . '"';
		$h .= $live ? ' data-pc-live="' . esc_url( $url ) . '" data-pc-interval="' . (int) self::INTERVAL . '" data-pc-hash="' . esc_attr( self::hash( $inner ) ) . '"' : '';
		$h .= ' data-pc-tid="' . (int) $t['id'] . '">';
		if ( $find ) {
			$h .= '<div class="pc-find-wrap"><label class="pc-find-label" for="pc-find-' . esc_attr( $type . '-' . (int) $t['id'] ) . '">' . esc_html__( 'Busca la teva parella', 'padelcamps' ) . '</label>';
			$h .= '<input type="search" class="pc-find" id="pc-find-' . esc_attr( $type . '-' . (int) $t['id'] ) . '" placeholder="' . esc_attr__( 'Nom o cognom', 'padelcamps' ) . '" autocomplete="off">';
			$h .= '<span class="pc-find-count" aria-live="polite"></span>';
			if ( $live ) {
				$h .= '<span class="pc-live-dot" title="' . esc_attr__( 'Es refresca sol', 'padelcamps' ) . '">' . esc_html__( 'En directe', 'padelcamps' ) . '</span>';
			}
			$h .= '</div>';
		}
		return $h . '<div class="pc-live-body">' . $inner . '</div></div>';
	}
}
