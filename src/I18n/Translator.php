<?php
namespace PadelCamps\I18n;

defined( 'ABSPATH' ) || exit;

/**
 * Own translation layer. Source strings are Catalan (the main language).
 * Lookup order for a string, per language:
 *   1. overrides saved from the admin "Traduccions" page (option)
 *   2. translations shipped with the plugin (languages/{lang}.php)
 *   3. the Catalan source string itself
 * Works through the gettext filters, so no .mo/.po compilation is needed.
 */
final class Translator {

	public const DOMAIN      = 'padelcamps';
	public const OPTION      = 'padelcamps_translations';
	public const SOURCE_LANG = 'ca';

	/** @var array<string, array<string, string|array>> */
	private static array $cache = array();

	/** @return array<string, string> lang code => native name. */
	public static function languages(): array {
		return apply_filters(
			'padelcamps_languages',
			array(
				'ca' => 'Català',
				'es' => 'Español',
				'en' => 'English',
			)
		);
	}

	/** Language forced by in_lang() (e.g. an email in the recipient's language), or null. */
	private static ?string $forced = null;

	public static function current_lang(): string {
		return self::$forced ?? Catalog::lang_of( determine_locale() );
	}

	/**
	 * Run $fn with every plugin string in $lang ('' = current language), e.g. to
	 * write an email in the language the player registered in.
	 *
	 * @return mixed whatever $fn returns
	 */
	public static function in_lang( string $lang, callable $fn ) {
		$lang = strtolower( $lang );
		if ( '' === $lang || ! isset( self::languages()[ $lang ] ) ) {
			return $fn();
		}
		$previous     = self::$forced;
		self::$forced = $lang;
		try {
			return $fn();
		} finally {
			self::$forced = $previous;
		}
	}

	public function register(): void {
		add_filter( 'gettext', array( $this, 'gettext' ), 10, 3 );
		add_filter( 'gettext_with_context', array( $this, 'gettext_with_context' ), 10, 4 );
		add_filter( 'ngettext', array( $this, 'ngettext' ), 10, 5 );
		add_filter( 'ngettext_with_context', array( $this, 'ngettext_with_context' ), 10, 6 );
	}

	public function gettext( $translation, $text, $domain ) {
		return $this->single( $translation, Catalog::make_key( $text ), $domain );
	}

	public function gettext_with_context( $translation, $text, $context, $domain ) {
		return $this->single( $translation, Catalog::make_key( $text, $context ), $domain );
	}

	public function ngettext( $translation, $single, $plural, $number, $domain ) {
		return $this->plural( $translation, Catalog::make_key( $single ), $number, $domain );
	}

	public function ngettext_with_context( $translation, $single, $plural, $number, $context, $domain ) {
		return $this->plural( $translation, Catalog::make_key( $single, $context ), $number, $domain );
	}

	private function single( $translation, string $key, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $translation;
		}
		$entry = self::map( self::current_lang() )[ $key ] ?? null;
		return is_string( $entry ) && '' !== $entry ? $entry : $translation;
	}

	private function plural( $translation, string $key, $number, $domain ) {
		if ( self::DOMAIN !== $domain ) {
			return $translation;
		}
		$entry = self::map( self::current_lang() )[ $key ] ?? null;
		if ( is_array( $entry ) ) {
			// Catalan, Spanish and English share the same 2-form rule (n != 1).
			$form = 1 === (int) $number ? 0 : 1;
			$text = $entry[ $form ] ?? '';
			if ( '' !== $text ) {
				return $text;
			}
		}
		return $translation;
	}

	/** Translations shipped with the plugin for a language. */
	public static function shipped( string $lang ): array {
		if ( ! preg_match( '/^[a-z]{2,3}$/', $lang ) ) {
			return array();
		}
		$file = PADELCAMPS_PATH . 'languages/' . $lang . '.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = include $file;
		return is_array( $data ) ? $data : array();
	}

	/** Overrides saved from the admin page for a language. */
	public static function overrides( string $lang ): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $lang ] ) && is_array( $all[ $lang ] ) ? $all[ $lang ] : array();
	}

	/** Effective map: shipped translations, then overrides on top. */
	public static function map( string $lang ): array {
		if ( ! isset( self::$cache[ $lang ] ) ) {
			self::$cache[ $lang ] = array_merge( self::shipped( $lang ), self::overrides( $lang ) );
		}
		return self::$cache[ $lang ];
	}

	public static function save_overrides( string $lang, array $overrides ): void {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( $overrides ) {
			$all[ $lang ] = $overrides;
		} else {
			unset( $all[ $lang ] );
		}
		update_option( self::OPTION, $all, false );
		unset( self::$cache[ $lang ] );
	}
}
