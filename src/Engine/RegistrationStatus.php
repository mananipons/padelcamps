<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Registration lifecycle and payment states, plus the money summary an
 * organizer needs. Pure logic, no WordPress dependencies.
 *
 * Status:  pending (waiting for the organizer) | confirmed | waitlist | cancelled
 * Payment: pending | paid | refunded | free (price 0, nothing to pay)
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

	/** @return string[] */
	public static function all(): array {
		return array( self::PENDING, self::CONFIRMED, self::WAITLIST, self::CANCELLED );
	}

	/** @return string[] */
	public static function payments(): array {
		return array( self::PAYMENT_PENDING, self::PAYMENT_PAID, self::PAYMENT_REFUNDED, self::PAYMENT_FREE );
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
	 * @return array{count:int, status:array<string,int>, payment:array<string,int>, paid_total:float, due_total:float}
	 *         paid_total: money collected. due_total: money still expected (unpaid, and not cancelled/waitlisted).
	 */
	public static function summarize( array $groups ): array {
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
			if ( self::PAYMENT_PAID === $pay ) {
				$out['paid_total'] += $total;
			}
			if ( self::PAYMENT_PENDING === $pay && self::occupies_place( $status ) ) {
				$out['due_total'] += $total;
			}
		}
		return $out;
	}
}
