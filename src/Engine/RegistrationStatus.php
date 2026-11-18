<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Registration lifecycle and payment states, plus the money summary an
 * organizer needs. Pure logic, no WordPress dependencies.
 *
 * Status:  pending (waiting for the organizer) | confirmed | waitlist | cancelled
 * Payment is per player (each player pays their own fee): pending | paid | refunded | free.
 * The registration (the pair) keeps an aggregate of its players: the same values plus
 * `partial` (someone paid, someone still has to). See aggregate().
 * Pending and confirmed registrations hold a place; waitlist and cancelled do not.
 */
final class RegistrationStatus {

	public const PENDING   = 'pending';
	public const CONFIRMED = 'confirmed';
	public const WAITLIST  = 'waitlist';
	public const CANCELLED = 'cancelled';

	public const PAYMENT_PENDING  = 'pending';
	public const PAYMENT_PAID     = 'paid';
	public const PAYMENT_REFUNDED = 'refunded';
	public const PAYMENT_FREE     = 'free';
	public const PAYMENT_PARTIAL  = 'partial'; // Registration only: some players paid, others not yet.

	/** @return string[] */
	public static function all(): array {
		return array( self::PENDING, self::CONFIRMED, self::WAITLIST, self::CANCELLED );
	}

	/** Payment states of a registration (pair), including the aggregate `partial`. @return string[] */
	public static function payments(): array {
		return array( self::PAYMENT_PENDING, self::PAYMENT_PARTIAL, self::PAYMENT_PAID, self::PAYMENT_REFUNDED, self::PAYMENT_FREE );
	}

	/** Payment states a single player can have. @return string[] */
	public static function player_payments(): array {
		return array( self::PAYMENT_PENDING, self::PAYMENT_PAID, self::PAYMENT_REFUNDED, self::PAYMENT_FREE );
	}

	public static function is_valid_player_payment( string $payment ): bool {
		return in_array( $payment, self::player_payments(), true );
	}

	/**
	 * Registration payment from its players' payments.
	 * Free players (nothing to pay) are ignored; none left → free. All refunded → refunded.
	 * Among the rest: nobody paid → pending; nobody pending → paid; otherwise partial.
	 *
	 * @param string[] $players player payment states
	 */
	public static function aggregate( array $players ): string {
		$due = array_values( array_filter( $players, static fn( $p ) => self::PAYMENT_FREE !== $p ) );
		if ( ! $due ) {
			return self::PAYMENT_FREE;
		}
		$active = array_values( array_filter( $due, static fn( $p ) => self::PAYMENT_REFUNDED !== $p ) );
		if ( ! $active ) {
			return self::PAYMENT_REFUNDED;
		}
		$paid    = count( array_filter( $active, static fn( $p ) => self::PAYMENT_PAID === $p ) );
		$pending = count( $active ) - $paid;
		if ( 0 === $paid ) {
			return self::PAYMENT_PENDING;
		}
		return 0 === $pending ? self::PAYMENT_PAID : self::PAYMENT_PARTIAL;
	}

	/**
	 * Split a pair's total into per-player amounts (cents go to the first players),
	 * so the parts always add up to the total. Used to migrate per-pair amounts.
	 *
	 * @return float[]
	 */
	public static function split( float $total, int $players ): array {
		if ( $players <= 0 ) {
			return array();
		}
		$cents = (int) round( $total * 100 );
		$base  = intdiv( $cents, $players );
		$extra = $cents - $base * $players;
		$out   = array();
		for ( $i = 0; $i < $players; $i++ ) {
			$out[] = round( ( $base + ( $i < $extra ? 1 : 0 ) ) / 100, 2 );
		}
		return $out;
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	public static function is_valid_payment( string $payment ): bool {
		return in_array( $payment, self::payments(), true );
	}

	public static function occupies_place( string $status ): bool {
		return self::PENDING === $status || self::CONFIRMED === $status;
	}

	/** True when more places are taken than the category allows (null/0 = unlimited). */
	public static function over_capacity( ?int $max_teams, int $occupied ): bool {
		return null !== $max_teams && $max_teams > 0 && $occupied > $max_teams;
	}

	/**
	 * @param array<int, array{status:string, payment_status:string, n:int|string, total:float|string}> $groups
	 *        Rows grouped by (status, payment_status) with their count and amount sum.
	 * @param array<int, array{status:string, payment_status:string, total:float|string}>|null $money
	 *        Optional per-player rows grouped by (registration status, player payment) with the amount sum.
	 *        When given, the money comes from them (each player pays their own fee).
	 * @return array{count:int, status:array<string,int>, payment:array<string,int>, paid_total:float, due_total:float}
	 *         paid_total: money collected. due_total: money still expected (unpaid, and not cancelled/waitlisted).
	 */
	public static function summarize( array $groups, ?array $money = null ): array {
		$out = array(
			'count'      => 0,
			'status'     => array_fill_keys( self::all(), 0 ),
			'payment'    => array_fill_keys( self::payments(), 0 ),
			'paid_total' => 0.0,
			'due_total'  => 0.0,
		);
		foreach ( $groups as $g ) {
			$n      = (int) $g['n'];
			$total  = (float) $g['total'];
			$status = (string) $g['status'];
			$pay    = (string) $g['payment_status'];

			$out['count'] += $n;
			if ( isset( $out['status'][ $status ] ) ) {
				$out['status'][ $status ] += $n;
			}
			if ( isset( $out['payment'][ $pay ] ) ) {
				$out['payment'][ $pay ] += $n;
			}
			if ( null === $money ) {
				self::add_money( $out, $status, $pay, $total );
			}
		}
		foreach ( $money ?? array() as $m ) {
			self::add_money( $out, (string) $m['status'], (string) $m['payment_status'], (float) $m['total'] );
		}
		return $out;
	}

	/** Paid money counts as collected; pending money only while the registration holds a place. */
	private static function add_money( array &$out, string $status, string $pay, float $total ): void {
		if ( self::PAYMENT_PAID === $pay ) {
			$out['paid_total'] += $total;
		}
		if ( self::PAYMENT_PENDING === $pay && self::occupies_place( $status ) ) {
			$out['due_total'] += $total;
		}
	}
}
