<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Tournament lifecycle. Linear: each state has one next and one previous.
 * Pure logic, no WordPress dependencies.
 */
final class TournamentStatus {

	public const DRAFT               = 'draft';
	public const REGISTRATION_OPEN   = 'registration_open';
	public const REGISTRATION_CLOSED = 'registration_closed';
	public const DRAWN               = 'drawn';
	public const IN_PROGRESS         = 'in_progress';
	public const FINISHED            = 'finished';

	/** @return string[] in lifecycle order. */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::REGISTRATION_OPEN,
			self::REGISTRATION_CLOSED,
			self::DRAWN,
			self::IN_PROGRESS,
			self::FINISHED,
		);
	}

	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	public static function next( string $status ): ?string {
		$all = self::all();
		$i   = array_search( $status, $all, true );
		return false !== $i && isset( $all[ $i + 1 ] ) ? $all[ $i + 1 ] : null;
	}

	public static function prev( string $status ): ?string {
		$all = self::all();
		$i   = array_search( $status, $all, true );
		return false !== $i && $i > 0 ? $all[ $i - 1 ] : null;
	}

	/** Drafts are hidden from the public API/pages. */
	public static function is_public( string $status ): bool {
		return self::is_valid( $status ) && self::DRAFT !== $status;
	}
}
