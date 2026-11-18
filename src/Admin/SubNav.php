<?php
namespace PadelCamps\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Sub-page switcher inside a tab (categories in "Sorteig i quadres", sections in
 * "Resultats"): a boxed group of button-like links, so it reads as navigation
 * and not as part of the content below.
 */
final class SubNav {

	/**
	 * @param array<int, array{label:string, url:string, current:bool, count?:int, key?:string}> $items
	 * `key` adds data-sub="key" so a page can switch panes with JS (see FormTab).
	 */
	public static function render( array $items, string $aria_label = '' ): void {
		if ( ! $items ) {
			return;
		}
		echo '<nav class="pc-subnav"' . ( '' !== $aria_label ? ' aria-label="' . esc_attr( $aria_label ) . '"' : '' ) . '>';
		foreach ( $items as $item ) {
			printf(
				'<a class="pc-subnav__item%s" href="%s"%s%s>%s',
				! empty( $item['current'] ) ? ' is-current' : '',
				esc_url( $item['url'] ),
				! empty( $item['current'] ) ? ' aria-current="page"' : '',
				isset( $item['key'] ) ? ' data-sub="' . esc_attr( $item['key'] ) . '"' : '',
				esc_html( $item['label'] )
			);
			if ( ! empty( $item['count'] ) ) {
				echo ' <span class="pc-count">' . (int) $item['count'] . '</span>';
			}
			echo '</a>';
		}
		echo '</nav>';
	}
}
