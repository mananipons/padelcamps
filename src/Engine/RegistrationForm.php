<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Registration form: per-tournament configuration (defaults + sanitizing) and
 * validation of a submission. Pure logic, no WordPress dependencies. Messages
 * are NOT here: this returns error codes, the presentation layer translates them.
 *
 * Config shape (stored in tournaments.settings['form']): see defaults().
 * Availability answers are stored self-contained (date + label + value) so they
 * stay valid if the organizer edits the slots later.
 */
final class RegistrationForm {

	public const STATES       = array( 'off', 'optional', 'required' );
	public const CONFIGURABLE = array( 'phone1', 'phone2', 'email2', 'club', 'dni', 'notes' );
	public const CONSENTS     = array( 'data', 'health', 'image' );
	public const MODES        = array( 'yesno', 'from' );
	public const PACK_TYPES   = array( 'select', 'text' );
	public const MAX_SLOTS    = 60;
	public const MAX_PACK     = 10;

	public static function defaults(): array {
		return array(
			'intro'                => '',
			'success_message'      => '',
			'payment_instructions' => '',
			'opens_at'             => '',
			'closes_at'            => '',
			'notify_email'         => '',
			'privacy_url'          => '',
			'fields'               => array(
				'phone1' => 'required',
				'phone2' => 'optional',
				'email2' => 'required',
				'club'   => 'off',
				'dni'    => 'off',
				'notes'  => 'optional',
			),
			// Free-form welcome pack, written by the organizer for each tournament.
			// items: [ ['label' => 'Talla de dessuadora', 'type' => 'select'|'text',
			//           'options' => ['XS','S',...], 'required' => bool], ... ]
			// Every player answers each item; answers are stored self-contained (label + value).
			'welcome_pack'         => array(
				'enabled'     => false,
				'title'       => '',
				'description' => '',
				'items'       => array(),
			),
			'availability'         => array(
				'enabled' => false,
				'help'    => '',
				'slots'   => array(),
			),
			'consents'             => array(
				'data'   => array( 'enabled' => true, 'required' => true, 'text' => '' ),
				'health' => array( 'enabled' => true, 'required' => true, 'text' => '' ),
				'image'  => array( 'enabled' => false, 'required' => false, 'text' => '' ),
			),
		);
	}

	// --- Sanitizing helpers (pure PHP) --------------------------------------

	/** UTF-8 substring that does not depend on the mbstring extension. */
	private static function cut( string $s, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $length );
		}
		return implode( '', array_slice( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) ?: array(), $start, $length ) );
	}

	private static function len( string $s ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s );
		}
		return count( preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	public static function clean_text( $value, int $max = 190 ): string {
		$value = is_string( $value ) ? $value : '';
		$value = strip_tags( $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return self::cut( $value, 0, $max );
	}

	/** Keeps line breaks; strips tags. */
	public static function clean_multiline( $value, int $max = 2000 ): string {
		$value = is_string( $value ) ? $value : '';
		$value = strip_tags( str_replace( "\r\n", "\n", $value ) );
		$value = trim( (string) preg_replace( "/[ \t]+/", ' ', $value ) );
		$value = (string) preg_replace( "/\n{3,}/", "\n\n", $value );
		return self::cut( $value, 0, $max );
	}

	public static function clean_email( $value ): ?string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( '' === $value || strlen( $value ) > 190 || false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}
		return $value;
	}

	public static function clean_phone( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( ! preg_match( '/^[0-9 +().\-]{6,20}$/', $value ) || strlen( (string) preg_replace( '/\D/', '', $value ) ) < 6 ) {
			return null;
		}
		return $value;
	}

	public static function clean_dni( $value ): ?string {
		$value = is_string( $value ) ? strtoupper( str_replace( array( ' ', '-' ), '', trim( $value ) ) ) : '';
		return preg_match( '/^[A-Z0-9]{5,12}$/', $value ) ? $value : null;
	}

	private static function valid_date( $value ): bool {
		return is_string( $value )
			&& preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Parse "18:00, 19:00, =22:15" into ['18:00','19:00','=22:15'] (valid entries only). */
	public static function parse_times( $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/[,;\n]+/', (string) $value );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( preg_match( '/^=?([01]\d|2[0-3]):[0-5]\d$/', $part ) && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	// --- Config --------------------------------------------------------------

	/** Sanitize raw (admin POST or stored) config on top of the defaults. */
	public static function normalize( array $raw ): array {
		$cfg = self::defaults();

		$cfg['intro']                = self::clean_multiline( $raw['intro'] ?? '', 2000 );
		$cfg['success_message']      = self::clean_multiline( $raw['success_message'] ?? '', 2000 );
		$cfg['payment_instructions'] = self::clean_multiline( $raw['payment_instructions'] ?? '', 1000 );

		foreach ( array( 'opens_at', 'closes_at' ) as $key ) {
			$v           = $raw[ $key ] ?? '';
			$cfg[ $key ] = is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v ) ? $v : '';
		}
		$cfg['notify_email'] = self::clean_email( $raw['notify_email'] ?? '' ) ?? '';

		$url = $raw['privacy_url'] ?? '';
		if ( is_string( $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $url ) ) {
			$cfg['privacy_url'] = $url;
		}

		foreach ( self::CONFIGURABLE as $key ) {
			$state = $raw['fields'][ $key ] ?? null;
			if ( is_string( $state ) && in_array( $state, self::STATES, true ) ) {
				$cfg['fields'][ $key ] = $state;
			}
		}

		$cfg['welcome_pack'] = self::normalize_pack( self::legacy_pack( $raw ) );

		$av                            = $raw['availability'] ?? array();
		$cfg['availability']['enabled'] = ! empty( $av['enabled'] );
		$cfg['availability']['help']    = self::clean_multiline( $av['help'] ?? '', 1000 );
		$slots                          = array();
		foreach ( is_array( $av['slots'] ?? null ) ? $av['slots'] : array() as $slot ) {
			if ( ! is_array( $slot ) || ! self::valid_date( $slot['date'] ?? null ) ) {
				continue;
			}
			$mode  = in_array( $slot['mode'] ?? '', self::MODES, true ) ? $slot['mode'] : 'yesno';
			$times = 'from' === $mode ? self::parse_times( $slot['times'] ?? '' ) : array();
			if ( 'from' === $mode && ! $times ) {
				$mode = 'yesno';
			}
			$slots[] = array(
				'date'  => $slot['date'],
				'label' => self::clean_text( $slot['label'] ?? '', 60 ),
				'mode'  => $mode,
				'times' => $times,
			);
			if ( count( $slots ) >= self::MAX_SLOTS ) {
				break;
			}
		}
		$cfg['availability']['slots'] = $slots;

		foreach ( self::CONSENTS as $key ) {
			$c = $raw['consents'][ $key ] ?? null;
			if ( is_array( $c ) ) {
				$cfg['consents'][ $key ] = array(
					'enabled'  => ! empty( $c['enabled'] ),
					'required' => ! empty( $c['required'] ),
					'text'     => self::clean_multiline( $c['text'] ?? '', 600 ),
				);
			}
		}

		return $cfg;
	}

	/** Parse "XS, S, M" (or an array) into a de-duplicated list of short option labels. */
	public static function parse_options( $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/[,;\n]+/', (string) $value );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = self::clean_text( $part, 40 );
			if ( '' !== $part && ! in_array( $part, $out, true ) ) {
				$out[] = $part;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Configs saved before the free-form welcome pack existed asked for a shirt
	 * size (fields.shirt + shirt_sizes). Turn that into a pack item so nothing is lost.
	 */
	private static function legacy_pack( array $raw ): array {
		if ( isset( $raw['welcome_pack'] ) && is_array( $raw['welcome_pack'] ) ) {
			return $raw['welcome_pack'];
		}
		$state = $raw['fields']['shirt'] ?? 'off';
		if ( ! in_array( $state, array( 'optional', 'required' ), true ) ) {
			return array();
		}
		return array(
			'enabled' => true,
			'items'   => array(
				array(
					'label'    => 'Talla de dessuadora',
					'type'     => 'select',
					'options'  => $raw['shirt_sizes'] ?? array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ),
					'required' => 'required' === $state,
				),
			),
		);
	}

	private static function normalize_pack( array $raw ): array {
		$pack = array(
			'enabled'     => ! empty( $raw['enabled'] ),
			'title'       => self::clean_text( $raw['title'] ?? '', 80 ),
			'description' => self::clean_multiline( $raw['description'] ?? '', 1500 ),
			'items'       => array(),
		);
		foreach ( is_array( $raw['items'] ?? null ) ? $raw['items'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = self::clean_text( $item['label'] ?? '', 80 );
			if ( '' === $label ) {
				continue;
			}
			$type    = in_array( $item['type'] ?? '', self::PACK_TYPES, true ) ? $item['type'] : 'select';
			$options = 'select' === $type ? self::parse_options( $item['options'] ?? '' ) : array();
			if ( 'select' === $type && ! $options ) {
				$type = 'text'; // A choice without choices makes no sense.
			}
			$pack['items'][] = array(
				'label'    => $label,
				'type'     => $type,
				'options'  => $options,
				'required' => ! empty( $item['required'] ),
			);
			if ( count( $pack['items'] ) >= self::MAX_PACK ) {
				break;
			}
		}
		return $pack;
	}

	/** Allowed answer values for an availability slot. */
	public static function slot_options( array $slot ): array {
		if ( 'from' !== ( $slot['mode'] ?? 'yesno' ) ) {
			return array( 'yes', 'no' );
		}
		$opts = array();
		foreach ( $slot['times'] ?? array() as $time ) {
			$opts[] = 0 === strpos( $time, '=' ) ? 'only:' . substr( $time, 1 ) : 'from:' . $time;
		}
		$opts[] = 'no';
		return $opts;
	}

	// --- Opening window / capacity ------------------------------------------

	/** 'Y-m-d\TH:i' in the given timezone => unix timestamp (null when empty/invalid). */
	public static function window_ts( string $value, DateTimeZone $tz ): ?int {
		if ( '' === $value ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, $tz );
		return $dt ? $dt->getTimestamp() : null;
	}

	/** @return string 'open' | 'not_open_yet' | 'closed' | 'not_accepting' */
	public static function open_state( string $tournament_status, ?int $opens_ts, ?int $closes_ts, int $now ): string {
		if ( TournamentStatus::REGISTRATION_OPEN !== $tournament_status ) {
			return 'not_accepting';
		}
		if ( null !== $opens_ts && $now < $opens_ts ) {
			return 'not_open_yet';
		}
		if ( null !== $closes_ts && $now > $closes_ts ) {
			return 'closed';
		}
		return 'open';
	}

	/** Registrations in a full category go to the waiting list. */
	public static function initial_status( ?int $max_teams, int $occupied ): string {
		return ( null !== $max_teams && $max_teams > 0 && $occupied >= $max_teams ) ? 'waitlist' : 'pending';
	}

	// --- Validation ----------------------------------------------------------

	/**
	 * @param int[] $category_ids Categories of this tournament that accept registrations.
	 * @return array{errors: array<string,string>, clean: array}
	 *   errors: field name => code (required|invalid_email|invalid_phone|invalid_dni|invalid_choice|invalid|same_email)
	 */
	public static function validate( array $cfg, array $category_ids, array $post ): array {
		$errors = array();
		$clean  = array(
			'category_id'  => 0,
			'players'      => array(),
			'notes'        => '',
			'availability' => array(),
			'consents'     => array(),
		);

		$category = isset( $post['category_id'] ) ? (int) $post['category_id'] : 0;
		if ( in_array( $category, $category_ids, true ) ) {
			$clean['category_id'] = $category;
		} else {
			$errors['category_id'] = 'required';
		}

		$f = $cfg['fields'];
		foreach ( array( 1, 2 ) as $n ) {
			$p      = "p{$n}_";
			$player = array(
				'name'  => '',
				'email' => '',
				'phone' => '',
				'club'  => '',
				'dni'   => '',
				'pack'  => array(),
			);

			$player['name'] = self::clean_text( $post[ $p . 'name' ] ?? '', 120 );
			if ( '' === $player['name'] ) {
				$errors[ $p . 'name' ] = 'required';
			} elseif ( self::len( $player['name'] ) < 2 ) {
				$errors[ $p . 'name' ] = 'invalid';
			}

			$email_state = 1 === $n ? 'required' : $f['email2'];
			self::field( $errors, $p . 'email', $email_state, $post[ $p . 'email' ] ?? '', 'invalid_email', static fn( $v ) => self::clean_email( $v ), $player['email'] );
			self::field( $errors, $p . 'phone', $f[ 'phone' . $n ], $post[ $p . 'phone' ] ?? '', 'invalid_phone', static fn( $v ) => self::clean_phone( $v ), $player['phone'] );
			self::field( $errors, $p . 'dni', $f['dni'], $post[ $p . 'dni' ] ?? '', 'invalid_dni', static fn( $v ) => self::clean_dni( $v ), $player['dni'] );
			self::field( $errors, $p . 'club', $f['club'], $post[ $p . 'club' ] ?? '', 'invalid', static fn( $v ) => self::clean_text( $v, 120 ) ?: null, $player['club'] );

			// Welcome pack: every player answers each item the organizer defined.
			if ( ! empty( $cfg['welcome_pack']['enabled'] ) ) {
				foreach ( $cfg['welcome_pack']['items'] as $i => $item ) {
					$name  = "{$p}pack_{$i}";
					$given = is_string( $post[ $name ] ?? null ) ? trim( $post[ $name ] ) : '';
					$value = '';
					if ( '' === $given ) {
						if ( $item['required'] ) {
							$errors[ $name ] = 'required';
						}
					} elseif ( 'select' === $item['type'] ) {
						if ( in_array( $given, $item['options'], true ) ) {
							$value = $given;
						} else {
							$errors[ $name ] = 'invalid_choice';
						}
					} else {
						$value = self::clean_text( $given, 120 );
					}
					$player['pack'][] = array(
						'label' => $item['label'],
						'value' => $value,
					);
				}
			}

			$clean['players'][] = $player;
		}

		if ( '' !== $clean['players'][0]['email'] && $clean['players'][0]['email'] === $clean['players'][1]['email'] ) {
			$errors['p2_email'] = 'same_email';
		}

		if ( 'off' !== $f['notes'] ) {
			$notes = self::clean_multiline( $post['notes'] ?? '', 1000 );
			if ( '' === $notes && 'required' === $f['notes'] ) {
				$errors['notes'] = 'required';
			}
			$clean['notes'] = $notes;
		}

		if ( ! empty( $cfg['availability']['enabled'] ) ) {
			$given = is_array( $post['av'] ?? null ) ? $post['av'] : array();
			foreach ( $cfg['availability']['slots'] as $i => $slot ) {
				$value = isset( $given[ $i ] ) && is_string( $given[ $i ] ) ? $given[ $i ] : '';
				if ( in_array( $value, self::slot_options( $slot ), true ) ) {
					$clean['availability'][] = array(
						'date'  => $slot['date'],
						'label' => $slot['label'],
						'value' => $value,
					);
				} else {
					$errors[ 'av_' . $i ] = 'required';
				}
			}
		}

		$given = is_array( $post['consent'] ?? null ) ? $post['consent'] : array();
		foreach ( self::CONSENTS as $key ) {
			$c = $cfg['consents'][ $key ];
			if ( empty( $c['enabled'] ) ) {
				continue;
			}
			if ( ! empty( $given[ $key ] ) ) {
				$clean['consents'][ $key ] = true;
			} elseif ( ! empty( $c['required'] ) ) {
				$errors[ 'consent_' . $key ] = 'required';
			}
		}

		return array(
			'errors' => $errors,
			'clean'  => $clean,
		);
	}

	/** Shared handling for an optional/required text field with a cleaner callback. */
	private static function field( array &$errors, string $name, string $state, $raw, string $invalid_code, callable $cleaner, string &$target ): void {
		if ( 'off' === $state ) {
			return;
		}
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			if ( 'required' === $state ) {
				$errors[ $name ] = 'required';
			}
			return;
		}
		$value = $cleaner( $raw );
		if ( null === $value ) {
			$errors[ $name ] = $invalid_code;
			return;
		}
		$target = $value;
	}
}
