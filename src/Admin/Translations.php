<?php
namespace PadelCamps\Admin;

use PadelCamps\I18n\Catalog;
use PadelCamps\I18n\Translator;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page to translate/edit every string of the plugin, per language.
 * Strings are discovered by scanning the plugin source (see I18n\Catalog).
 */
final class Translations {

	public const SLUG   = 'padelcamps-translations';
	private const NONCE = 'padelcamps_save_translations';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_padelcamps_save_translations', array( $this, 'handle_save' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Traduccions', 'padelcamps' ),
			__( 'Traduccions', 'padelcamps' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	private static function sanitize_lang( $value ): string {
		$langs = Translator::languages();
		$lang  = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( isset( $langs[ $lang ] ) ) {
			return $lang;
		}
		foreach ( array_keys( $langs ) as $code ) {
			if ( Translator::SOURCE_LANG !== $code ) {
				return $code;
			}
		}
		return Translator::SOURCE_LANG;
	}

	/** Allowed inline HTML inside translations. */
	private static function allowed_html(): array {
		return array(
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$langs        = Translator::languages();
		$lang         = self::sanitize_lang( $_GET['lang'] ?? '' );
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$only_missing = ! empty( $_GET['missing'] );
		$saved        = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
		$rejected     = isset( $_GET['rejected'] ) ? absint( $_GET['rejected'] ) : 0;
		// phpcs:enable

		$is_source = Translator::SOURCE_LANG === $lang;
		$catalog   = Catalog::scan( PADELCAMPS_PATH );
		$overrides = Translator::overrides( $lang );
		$shipped   = Translator::shipped( $lang );

		$total   = count( $catalog );
		$missing = 0;
		$rows    = array();
		foreach ( $catalog as $key => $entry ) {
			$has_value = ! empty( $overrides[ $key ] ) || ! empty( $shipped[ $key ] );
			$is_missing = ! $is_source && ! $has_value;
			if ( $is_missing ) {
				++$missing;
			}
			if ( $only_missing && ! $is_missing ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = $entry['msgid'] . ' ' . ( $entry['plural'] ?? '' ) . ' ' . wp_json_encode( $overrides[ $key ] ?? '' ) . ' ' . wp_json_encode( $shipped[ $key ] ?? '' );
				if ( false === stripos( $haystack, $search ) ) {
					continue;
				}
			}
			$rows[] = $entry;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Traduccions de PadelCamps', 'padelcamps' ) . '</h1>';

		if ( null !== $saved ) {
			if ( $saved > 0 ) {
				/* translators: %d: number of strings */
				$msg = sprintf( _n( '%d cadena actualitzada.', '%d cadenes actualitzades.', $saved, 'padelcamps' ), $saved );
			} else {
				$msg = __( 'No hi ha hagut cap canvi.', 'padelcamps' );
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( $rejected > 0 ) {
			/* translators: %d: number of strings */
			$msg = sprintf(
				_n(
					"No s'ha desat %d cadena perquè els marcadors de posició no coincideixen amb l'original.",
					"No s'han desat %d cadenes perquè els marcadors de posició no coincideixen amb l'original.",
					$rejected,
					'padelcamps'
				),
				$rejected
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Aquí pots traduir o canviar el text de qualsevol cadena del plugin. Els teus canvis tenen prioritat sobre les traduccions que inclou el plugin. Deixa un camp buit per tornar al text per defecte.', 'padelcamps' ) . '</p>';
		if ( $is_source ) {
			echo '<p><em>' . esc_html__( 'Estàs editant el text original en català: els canvis substitueixen el text que es mostra.', 'padelcamps' ) . '</em></p>';
		}

		// Filters (GET).
		echo '<form method="get" style="margin:1em 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="pc-lang"><strong>' . esc_html__( 'Idioma', 'padelcamps' ) . '</strong></label> ';
		echo '<select id="pc-lang" name="lang">';
		foreach ( $langs as $code => $name ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $lang, $code, false ), esc_html( $name ) );
		}
		echo '</select> ';
		echo '<label for="pc-s" class="screen-reader-text">' . esc_html__( 'Cerca', 'padelcamps' ) . '</label>';
		printf( '<input type="search" id="pc-s" name="s" value="%s" placeholder="%s"> ', esc_attr( $search ), esc_attr__( 'Cerca', 'padelcamps' ) );
		printf( '<label><input type="checkbox" name="missing" value="1"%s> %s</label> ', checked( $only_missing, true, false ), esc_html__( 'Només sense traduir', 'padelcamps' ) );
		echo '<button class="button">' . esc_html__( 'Filtra', 'padelcamps' ) . '</button> ';
		/* translators: 1: total strings, 2: strings without translation */
		echo '<span style="margin-left:1em;color:#646970">' . esc_html( sprintf( __( '%1$d cadenes · %2$d sense traduir', 'padelcamps' ), $total, $missing ) ) . '</span>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No hi ha cap cadena que coincideixi amb el filtre.', 'padelcamps' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="padelcamps_save_translations">';
		echo '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		wp_nonce_field( self::NONCE );

		echo '<table class="widefat striped" style="table-layout:fixed"><thead><tr>';
		echo '<th style="width:28%">' . esc_html__( 'Original (català)', 'padelcamps' ) . '</th>';
		echo '<th style="width:28%">' . esc_html__( 'Text per defecte', 'padelcamps' ) . '</th>';
		echo '<th>' . esc_html__( 'La teva traducció', 'padelcamps' ) . '</th>';
		echo '<th style="width:12%">' . esc_html__( 'Ubicació', 'padelcamps' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $entry ) {
			$this->render_row( $entry, $lang, $overrides, $shipped );
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Desa els canvis', 'padelcamps' ) . '</button></p>';
		echo '</form></div>';
	}

	private function render_row( array $entry, string $lang, array $overrides, array $shipped ): void {
		$key       = $entry['key'];
		$hash      = md5( $key );
		$is_source = Translator::SOURCE_LANG === $lang;
		$ov        = $overrides[ $key ] ?? null;
		$sh        = $shipped[ $key ] ?? null;
		$refs      = array_slice( $entry['refs'], 0, 2 );

		echo '<tr><td>';
		echo esc_html( $entry['msgid'] );
		if ( null !== $entry['plural'] ) {
			echo '<br><span style="color:#646970">' . esc_html( $entry['plural'] ) . '</span>';
		}
		if ( null !== $entry['context'] ) {
			echo '<br><code>' . esc_html( $entry['context'] ) . '</code>';
		}
		echo '</td><td>';

		if ( null !== $entry['plural'] ) {
			$def = $is_source ? array( $entry['msgid'], $entry['plural'] ) : ( is_array( $sh ) ? $sh : array( '', '' ) );
			echo esc_html( (string) ( $def[0] ?? '' ) ) . '<br><span style="color:#646970">' . esc_html( (string) ( $def[1] ?? '' ) ) . '</span>';
			echo '</td><td>';
			$cur = is_array( $ov ) ? $ov : array( '', '' );
			echo '<label>' . esc_html__( 'Singular', 'padelcamps' ) . '</label>';
			$this->field( "tr[$hash][v]", (string) ( $cur[0] ?? '' ), (string) ( $def[0] ?? '' ) );
			echo '<label>' . esc_html__( 'Plural', 'padelcamps' ) . '</label>';
			$this->field( "tr[$hash][p]", (string) ( $cur[1] ?? '' ), (string) ( $def[1] ?? '' ) );
		} else {
			$def = $is_source ? $entry['msgid'] : ( is_string( $sh ) ? $sh : '' );
			echo esc_html( $def );
			echo '</td><td>';
			$this->field( "tr[$hash][v]", is_string( $ov ) ? $ov : '', $def );
		}

		echo '</td><td>';
		foreach ( $refs as $ref ) {
			echo '<code style="word-break:break-all">' . esc_html( $ref ) . '</code><br>';
		}
		echo '</td></tr>';
	}

	private function field( string $name, string $value, string $placeholder ): void {
		printf(
			'<textarea name="%s" rows="2" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $name ),
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tens permisos per fer això.', 'padelcamps' ) );
		}
		check_admin_referer( self::NONCE );

		$lang    = self::sanitize_lang( isset( $_POST['lang'] ) ? wp_unslash( $_POST['lang'] ) : '' );
		$catalog = array();
		foreach ( Catalog::scan( PADELCAMPS_PATH ) as $entry ) {
			$catalog[ md5( $entry['key'] ) ] = $entry;
		}

		$overrides = Translator::overrides( $lang );
		$before    = $overrides;
		$rejected  = 0;
		$posted    = isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ? wp_unslash( $_POST['tr'] ) : array();

		foreach ( $posted as $hash => $vals ) {
			if ( ! isset( $catalog[ $hash ] ) || ! is_array( $vals ) ) {
				continue;
			}
			$entry = $catalog[ $hash ];
			$key   = $entry['key'];
			$v     = isset( $vals['v'] ) && is_string( $vals['v'] ) ? trim( wp_kses( $vals['v'], self::allowed_html() ) ) : '';

			if ( null !== $entry['plural'] ) {
				$p = isset( $vals['p'] ) && is_string( $vals['p'] ) ? trim( wp_kses( $vals['p'], self::allowed_html() ) ) : '';
				if ( '' === $v && '' === $p ) {
					unset( $overrides[ $key ] );
					continue;
				}
				if ( ( '' !== $v && ! Catalog::placeholders_match( $entry['msgid'], $v ) )
					|| ( '' !== $p && ! Catalog::placeholders_match( $entry['plural'], $p ) ) ) {
					++$rejected;
					continue;
				}
				$overrides[ $key ] = array( $v, $p );
				continue;
			}

			if ( '' === $v ) {
				unset( $overrides[ $key ] );
				continue;
			}
			if ( ! Catalog::placeholders_match( $entry['msgid'], $v ) ) {
				++$rejected;
				continue;
			}
			$overrides[ $key ] = $v;
		}

		$changed = 0;
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $overrides ) ) ) as $k ) {
			if ( ( $before[ $k ] ?? null ) !== ( $overrides[ $k ] ?? null ) ) {
				++$changed;
			}
		}
		if ( $changed ) {
			Translator::save_overrides( $lang, $overrides );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::SLUG,
					'lang'     => $lang,
					'saved'    => $changed,
					'rejected' => $rejected,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
