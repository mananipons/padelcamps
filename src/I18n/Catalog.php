<?php
declare( strict_types=1 );

namespace PadelCamps\I18n;

/**
 * Finds every translatable string of the plugin by tokenizing its PHP source
 * (no build step, no gettext tools). Pure logic, no WordPress dependencies.
 *
 * Source language of all strings is Catalan. Only calls whose text domain is
 * 'padelcamps' and whose texts are plain string literals are detected.
 */
final class Catalog {

	public const DOMAIN = 'padelcamps';

	/** function => [msgid arg, plural arg|null, context arg|null, domain arg] */
	private const FUNCTIONS = array(
		'__'            => array( 0, null, null, 1 ),
		'_e'            => array( 0, null, null, 1 ),
		'esc_html__'    => array( 0, null, null, 1 ),
		'esc_html_e'    => array( 0, null, null, 1 ),
		'esc_attr__'    => array( 0, null, null, 1 ),
		'esc_attr_e'    => array( 0, null, null, 1 ),
		'_x'            => array( 0, null, 1, 2 ),
		'_ex'           => array( 0, null, 1, 2 ),
		'esc_html_x'    => array( 0, null, 1, 2 ),
		'esc_attr_x'    => array( 0, null, 1, 2 ),
		'_n'            => array( 0, 1, null, 3 ),
		'_nx'           => array( 0, 1, 3, 4 ),
	);

	private const EXCLUDED_DIRS = array( 'tests/', 'vendor/', 'languages/', 'node_modules/' );

	/** Key used to store/lookup a string: "context\x04msgid" (same as .mo files). */
	public static function make_key( string $msgid, ?string $context = null ): string {
		return null === $context || '' === $context ? $msgid : $context . "\x04" . $msgid;
	}

	/** 'ca_ES' / 'ca' / 'es-ES' => 'ca' / 'ca' / 'es'. */
	public static function lang_of( string $locale ): string {
		$parts = preg_split( '/[_-]/', strtolower( $locale ) );
		return $parts[0] ?? '';
	}

	/**
	 * True when both strings use the same printf conversions (same count and
	 * types, order may differ if positional). Prevents ArgumentCountError/garbage
	 * at runtime when a translator drops or adds a placeholder.
	 */
	public static function placeholders_match( string $original, string $translation ): bool {
		return self::placeholder_types( $original ) === self::placeholder_types( $translation );
	}

	private static function placeholder_types( string $s ): array {
		$s = str_replace( '%%', '', $s );
		preg_match_all( "/%(?:\\d+\\$)?[-+ 0]*(?:'.)?\\d*(?:\\.\\d+)?([bcdeEfFgGosuxX])/", $s, $m );
		$types = $m[1];
		sort( $types );
		return $types;
	}

	/**
	 * @return array<string, array{key:string, msgid:string, plural:?string, context:?string, refs:string[]}>
	 */
	public static function scan( string $dir ): array {
		$root  = rtrim( str_replace( '\\', '/', $dir ), '/' );
		$files = array();
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$files[] = str_replace( '\\', '/', $file->getPathname() );
			}
		}
		sort( $files );

		$entries = array();
		foreach ( $files as $path ) {
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			foreach ( self::EXCLUDED_DIRS as $excluded ) {
				if ( 0 === strpos( $relative, $excluded ) ) {
					continue 2;
				}
			}
			$code = (string) file_get_contents( $path );
			foreach ( self::scan_source( $code, $relative ) as $found ) {
				$key = self::make_key( $found['msgid'], $found['context'] );
				if ( ! isset( $entries[ $key ] ) ) {
					$entries[ $key ] = array(
						'key'     => $key,
						'msgid'   => $found['msgid'],
						'plural'  => $found['plural'],
						'context' => $found['context'],
						'refs'    => array(),
					);
				}
				if ( ! in_array( $found['ref'], $entries[ $key ]['refs'], true ) ) {
					$entries[ $key ]['refs'][] = $found['ref'];
				}
			}
		}
		return $entries;
	}

	/**
	 * @return array<int, array{msgid:string, plural:?string, context:?string, ref:string}>
	 */
	public static function scan_source( string $code, string $file ): array {
		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$found  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( self::FUNCTIONS[ $t[1] ] ) ) {
				continue;
			}
			$prev = self::prev_significant( $tokens, $i );
			if ( null !== $prev && is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue; // Method call/definition, not the global function.
			}
			$next_i = self::next_significant_index( $tokens, $i );
			if ( null === $next_i || '(' !== $tokens[ $next_i ] ) {
				continue;
			}

			$args = self::parse_args( $tokens, $next_i );
			list( $m, $p, $c, $d ) = self::FUNCTIONS[ $t[1] ];
			if ( ( $args[ $d ] ?? null ) !== self::DOMAIN || ! isset( $args[ $m ] ) || null === $args[ $m ] ) {
				continue;
			}
			if ( null !== $p && ( ! isset( $args[ $p ] ) || null === $args[ $p ] ) ) {
				continue;
			}
			$found[] = array(
				'msgid'   => $args[ $m ],
				'plural'  => null !== $p ? $args[ $p ] : null,
				'context' => null !== $c ? ( $args[ $c ] ?? null ) : null,
				'ref'     => $file . ':' . $t[2],
			);
		}
		return $found;
	}

	/** Literal value of each top-level argument (null when not a plain string literal). */
	private static function parse_args( array $tokens, int $open_index ): array {
		$count = count( $tokens );
		$depth = 1;
		$args  = array();
		$cur   = array();

		for ( $j = $open_index + 1; $j < $count; $j++ ) {
			$tk = $tokens[ $j ];
			if ( is_array( $tk ) && in_array( $tk[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( is_string( $tk ) ) {
				if ( '(' === $tk || '[' === $tk || '{' === $tk ) {
					++$depth;
				} elseif ( ')' === $tk || ']' === $tk || '}' === $tk ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( ',' === $tk && 1 === $depth ) {
					$args[] = self::literal( $cur );
					$cur    = array();
					continue;
				}
			}
			$cur[] = $tk;
		}
		if ( $cur ) {
			$args[] = self::literal( $cur );
		}
		return $args;
	}

	private static function literal( array $tokens ): ?string {
		if ( 1 !== count( $tokens ) || ! is_array( $tokens[0] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[0][0] ) {
			return null;
		}
		$lit  = $tokens[0][1];
		$body = substr( $lit, 1, -1 );
		if ( "'" === $lit[0] ) {
			return strtr( $body, array( '\\\\' => '\\', "\\'" => "'" ) );
		}
		return stripcslashes( $body );
	}

	private static function prev_significant( array $tokens, int $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$tk = $tokens[ $j ];
			if ( is_array( $tk ) && in_array( $tk[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $tk;
		}
		return null;
	}

	private static function next_significant_index( array $tokens, int $i ): ?int {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$tk = $tokens[ $j ];
			if ( is_array( $tk ) && in_array( $tk[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return null;
	}
}
