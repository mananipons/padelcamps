<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Court and time scheduling. Pure logic, no WordPress dependencies.
 *
 * Config (tournaments.settings['schedule'], see normalize()):
 *   duration     minutes per match (also the grid step)
 *   rest         minimum minutes between two matches of the same pair
 *   max_per_day  max matches per pair and day (0 = no limit)
 *   windows      playing periods: [['date' => 'Y-m-d', 'label' => 'Matí', 'from' => 'H:i', 'to' => 'H:i'], ...]
 *
 * A window's (date, label) is the same key as the availability slots of the
 * registration form, so a pair that answered "Dissabte – Matí: no disponible"
 * is not scheduled in the window "Dissabte – Matí". No answer = available.
 *
 * Greedy and deterministic: matches in the given order, each one in the
 * earliest free slot (time, then court order) that respects courts, rest,
 * availability, matches per day and dependencies (a match never starts before
 * the matches that feed it have finished + rest).
 */
final class Scheduler {

	public static function defaults(): array {
		return array(
			'duration'    => 90,
			'rest'        => 30,
			'max_per_day' => 0,
			'windows'     => array(),
		);
	}

	public static function normalize( array $raw ): array {
		$c = self::defaults();
		$d = (int) ( $raw['duration'] ?? 0 );
		if ( $d >= 30 && $d <= 240 ) {
			$c['duration'] = $d;
		}
		if ( isset( $raw['rest'] ) && (int) $raw['rest'] >= 0 && (int) $raw['rest'] <= 240 ) {
			$c['rest'] = (int) $raw['rest'];
		}
		if ( isset( $raw['max_per_day'] ) && (int) $raw['max_per_day'] >= 0 && (int) $raw['max_per_day'] <= 10 ) {
			$c['max_per_day'] = (int) $raw['max_per_day'];
		}
		foreach ( is_array( $raw['windows'] ?? null ) ? $raw['windows'] : array() as $w ) {
			if ( ! is_array( $w ) ) {
				continue;
			}
			$date = (string) ( $w['date'] ?? '' );
			$from = (string) ( $w['from'] ?? '' );
			$to   = (string) ( $w['to'] ?? '' );
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				continue;
			}
			if ( null === self::hm( $from ) || null === self::hm( $to ) || self::hm( $from ) >= self::hm( $to ) ) {
				continue;
			}
			$c['windows'][] = array(
				'date'  => $date,
				'label' => RegistrationForm::clean_text( $w['label'] ?? '', 60 ),
				'from'  => $from,
				'to'    => $to,
			);
			if ( count( $c['windows'] ) >= 100 ) {
				break;
			}
		}
		usort( $c['windows'], static fn( $x, $y ) => array( $x['date'], $x['from'] ) <=> array( $y['date'], $y['from'] ) );
		return $c;
	}

	/** Lowercase without depending on mbstring (accents compared as-is). */
	private static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/** 'H:i' → minutes since midnight, or null. */
	public static function hm( string $time ): ?int {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/** Absolute minutes for a date + minutes of day (UTC math, only differences matter). */
	public static function abs( string $date, int $minutes ): int {
		$d = new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		return intdiv( $d->getTimestamp(), 60 ) + $minutes;
	}

	/** Absolute minutes → 'Y-m-d H:i'. */
	public static function fmt( int $abs ): string {
		return gmdate( 'Y-m-d H:i', $abs * 60 );
	}

	/** 'Y-m-d H:i' (or 'Y-m-d H:i:s') → absolute minutes, or null. */
	public static function parse( string $datetime ): ?int {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $datetime, $m ) || null === self::hm( $m[2] ) ) {
			return null;
		}
		return self::abs( $m[1], (int) self::hm( $m[2] ) );
	}

	/**
	 * Candidate start times, earliest first.
	 *
	 * @return array<int, array{start:int, window:array}>
	 */
	public static function starts( array $config ): array {
		$out = array();
		foreach ( $config['windows'] as $w ) {
			$from = (int) self::hm( $w['from'] );
			$to   = (int) self::hm( $w['to'] );
			for ( $t = $from; $t + $config['duration'] <= $to; $t += $config['duration'] ) {
				$out[] = array(
					'start'  => self::abs( $w['date'], $t ),
					'window' => $w,
				);
			}
		}
		usort( $out, static fn( $x, $y ) => $x['start'] <=> $y['start'] );
		return $out;
	}

	/** Is the pair available at minute-of-day $minute inside window $w? */
	public static function available( array $answers, array $w, int $minute ): bool {
		$key = self::lower( trim( (string) $w['label'] ) );
		foreach ( $answers as $a ) {
			if ( ( $a['date'] ?? '' ) !== $w['date'] || self::lower( trim( (string) ( $a['label'] ?? '' ) ) ) !== $key ) {
				continue;
			}
			$value = (string) ( $a['value'] ?? '' );
			if ( 'no' === $value ) {
				return false;
			}
			if ( 0 === strpos( $value, 'from:' ) ) {
				return $minute >= (int) self::hm( substr( $value, 5 ) );
			}
			if ( 0 === strpos( $value, 'only:' ) ) {
				return $minute === self::hm( substr( $value, 5 ) );
			}
			return true;
		}
		return true;
	}

	/** Window containing [start, start+duration], or null. */
	private static function window_at( array $config, int $start ): ?array {
		foreach ( $config['windows'] as $w ) {
			$from = self::abs( $w['date'], (int) self::hm( $w['from'] ) );
			$to   = self::abs( $w['date'], (int) self::hm( $w['to'] ) );
			if ( $start >= $from && $start + $config['duration'] <= $to ) {
				return $w;
			}
		}
		return null;
	}

	private static function overlaps_with_rest( array $intervals, int $s, int $e, int $rest ): bool {
		foreach ( $intervals as list( $is, $ie ) ) {
			if ( $s < $ie + $rest && $e + $rest > $is ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, array{id:int, a:?int, b:?int, after?:int[], fixed?:?array{start:string, court:int}}> $matches
	 *        In priority order. 'after' = ids of matches that must finish first.
	 *        'fixed' = keep as is (already scheduled / played).
	 * @param int[] $courts court ids in preference order
	 * @param array<int, array> $availability reg id => availability answers [{date, label, value}]
	 * @return array{assigned: array<int, array{start:string, court:int}>, unscheduled: array<int, string>}
	 *         unscheduled reason: no_courts | no_slot | availability | dependency
	 */
	public static function schedule( array $config, array $matches, array $courts, array $availability ): array {
		$d        = $config['duration'];
		$rest     = $config['rest'];
		$court_iv = array_fill_keys( $courts, array() );
		$pair_iv  = array();
		$per_day  = array();
		$end_of   = array();
		$out      = array(
			'assigned'    => array(),
			'unscheduled' => array(),
		);

		$book = static function ( array $m, int $start, int $court ) use ( &$court_iv, &$pair_iv, &$per_day, &$end_of, $d ) {
			$court_iv[ $court ][] = array( $start, $start + $d );
			foreach ( array( $m['a'] ?? null, $m['b'] ?? null ) as $p ) {
				if ( $p ) {
					$pair_iv[ $p ][] = array( $start, $start + $d );
					$day             = intdiv( $start, 1440 );
					$per_day[ $p ][ $day ] = ( $per_day[ $p ][ $day ] ?? 0 ) + 1;
				}
			}
			$end_of[ (int) $m['id'] ] = $start + $d;
		};

		// Fixed matches first: they occupy courts and pairs.
		foreach ( $matches as $m ) {
			if ( ! empty( $m['fixed'] ) ) {
				$start = self::parse( (string) $m['fixed']['start'] );
				if ( null !== $start ) {
					$book( $m, $start, (int) $m['fixed']['court'] );
				}
			}
		}
		if ( ! $courts ) {
			foreach ( $matches as $m ) {
				if ( empty( $m['fixed'] ) ) {
					$out['unscheduled'][ (int) $m['id'] ] = 'no_courts';
				}
			}
			return $out;
		}

		$starts = self::starts( $config );
		foreach ( $matches as $m ) {
			if ( ! empty( $m['fixed'] ) ) {
				continue;
			}
			$id       = (int) $m['id'];
			$earliest = PHP_INT_MIN;
			foreach ( $m['after'] ?? array() as $dep ) {
				if ( ! isset( $end_of[ (int) $dep ] ) ) {
					$out['unscheduled'][ $id ] = 'dependency';
					continue 2;
				}
				$earliest = max( $earliest, $end_of[ (int) $dep ] + $rest );
			}

			$blocked_by_availability = false;
			foreach ( $starts as $slot ) {
				$t = $slot['start'];
				if ( $t < $earliest ) {
					continue;
				}
				$pairs_ok = true;
				foreach ( array( $m['a'] ?? null, $m['b'] ?? null ) as $p ) {
					if ( ! $p ) {
						continue;
					}
					if ( self::overlaps_with_rest( $pair_iv[ $p ] ?? array(), $t, $t + $d, $rest ) ) {
						$pairs_ok = false;
						break;
					}
					if ( $config['max_per_day'] > 0 && ( $per_day[ $p ][ intdiv( $t, 1440 ) ] ?? 0 ) >= $config['max_per_day'] ) {
						$pairs_ok = false;
						break;
					}
				}
				if ( ! $pairs_ok ) {
					continue;
				}
				$minute = $t - self::abs( $slot['window']['date'], 0 );
				foreach ( array( $m['a'] ?? null, $m['b'] ?? null ) as $p ) {
					if ( $p && ! self::available( $availability[ $p ] ?? array(), $slot['window'], $minute ) ) {
						$blocked_by_availability = true;
						continue 2;
					}
				}
				foreach ( $courts as $court ) {
					if ( ! self::overlaps_with_rest( $court_iv[ $court ], $t, $t + $d, 0 ) ) {
						$book( $m, $t, $court );
						$out['assigned'][ $id ] = array(
							'start' => self::fmt( $t ),
							'court' => $court,
						);
						continue 3;
					}
				}
			}
			$out['unscheduled'][ $id ] = $blocked_by_availability ? 'availability' : 'no_slot';
		}
		return $out;
	}

	/**
	 * Problems in a schedule (manual edits included).
	 *
	 * @param array<int, array{id:int, a:?int, b:?int, start:string, court:?int}> $scheduled
	 * @return array<int, array{type:string, matches:int[], pair?:int}>
	 *         type: court_clash | pair_clash | short_rest | outside_window | unavailable
	 */
	public static function conflicts( array $config, array $scheduled, array $availability ): array {
		$d    = $config['duration'];
		$list = array();
		foreach ( $scheduled as $m ) {
			$s = self::parse( (string) $m['start'] );
			if ( null !== $s ) {
				$m['_s'] = $s;
				$list[]  = $m;
			}
		}
		usort( $list, static fn( $x, $y ) => array( $x['_s'], $x['id'] ) <=> array( $y['_s'], $y['id'] ) );

		$out = array();
		$n   = count( $list );
		for ( $i = 0; $i < $n; $i++ ) {
			$x = $list[ $i ];
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$y = $list[ $j ];
				if ( $y['_s'] >= $x['_s'] + $d + $config['rest'] ) {
					break;
				}
				$overlap = $y['_s'] < $x['_s'] + $d;
				if ( $overlap && $x['court'] && $x['court'] === $y['court'] ) {
					$out[] = array( 'type' => 'court_clash', 'matches' => array( $x['id'], $y['id'] ) );
				}
				foreach ( array_intersect( array_filter( array( $x['a'], $x['b'] ) ), array_filter( array( $y['a'], $y['b'] ) ) ) as $p ) {
					$out[] = array( 'type' => $overlap ? 'pair_clash' : 'short_rest', 'matches' => array( $x['id'], $y['id'] ), 'pair' => (int) $p );
				}
			}
			$w = self::window_at( $config, $x['_s'] );
			if ( ! $w ) {
				if ( $config['windows'] ) {
					$out[] = array( 'type' => 'outside_window', 'matches' => array( $x['id'] ) );
				}
				continue;
			}
			$minute = $x['_s'] - self::abs( $w['date'], 0 );
			foreach ( array_filter( array( $x['a'], $x['b'] ) ) as $p ) {
				if ( ! self::available( $availability[ $p ] ?? array(), $w, $minute ) ) {
					$out[] = array( 'type' => 'unavailable', 'matches' => array( $x['id'] ), 'pair' => (int) $p );
				}
			}
		}
		return $out;
	}

	/**
	 * Suggested playing windows from the registration form availability slots.
	 * Morning 09:00–14:00, afternoon 16:00–21:00, evening/night 19:00–23:00,
	 * other labels 09:00–21:00. "From a time" slots: from the earliest time
	 * until the latest time + one match.
	 */
	public static function windows_from_availability( array $slots, int $duration ): array {
		$out = array();
		foreach ( $slots as $slot ) {
			$label = self::lower( (string) ( $slot['label'] ?? '' ) );
			$from  = '09:00';
			$to    = '21:00';
			if ( preg_match( '/mat|morning|mañana|manana/u', $label ) ) {
				$to = '14:00';
			} elseif ( preg_match( '/tard|afternoon|tarde/u', $label ) ) {
				$from = '16:00';
			} elseif ( preg_match( '/nit|vespre|night|evening|noche/u', $label ) ) {
				$from = '19:00';
				$to   = '23:00';
			}
			if ( 'from' === ( $slot['mode'] ?? '' ) && ! empty( $slot['times'] ) ) {
				$mins = array_map( static fn( $t ) => (int) self::hm( ltrim( (string) $t, '=' ) ), $slot['times'] );
				$from = sprintf( '%02d:%02d', intdiv( min( $mins ), 60 ), min( $mins ) % 60 );
				$end  = min( 23 * 60 + 59, max( $mins ) + $duration );
				$to   = sprintf( '%02d:%02d', intdiv( $end, 60 ), $end % 60 );
			}
			$out[] = array(
				'date'  => (string) $slot['date'],
				'label' => (string) ( $slot['label'] ?? '' ),
				'from'  => $from,
				'to'    => $to,
			);
		}
		return $out;
	}
}
