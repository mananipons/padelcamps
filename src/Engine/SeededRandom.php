<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * Deterministic PRNG (mulberry32). Same seed string => same sequence on every
 * platform, so a draw can be reproduced and audited. No WordPress dependencies.
 */
final class SeededRandom {

	private int $state;

	public function __construct( string $seed ) {
		$this->state = crc32( $seed ) & 0xFFFFFFFF;
	}

	/** 32-bit integer multiply without overflowing PHP's 64-bit int into float. */
	private static function imul( int $a, int $b ): int {
		$a  &= 0xFFFFFFFF;
		$b  &= 0xFFFFFFFF;
		$ah  = ( $a >> 16 ) & 0xFFFF;
		$al  = $a & 0xFFFF;
		return ( ( $al * $b ) + ( ( ( $ah * $b ) & 0xFFFF ) << 16 ) ) & 0xFFFFFFFF;
	}

	/** Next 32-bit unsigned integer. */
	public function next_u32(): int {
		$this->state = ( $this->state + 0x6D2B79F5 ) & 0xFFFFFFFF;
		$t           = $this->state;
		$t           = self::imul( $t ^ ( $t >> 15 ), $t | 1 );
		$t          ^= ( $t + self::imul( $t ^ ( $t >> 7 ), $t | 61 ) ) & 0xFFFFFFFF;
		return ( $t ^ ( $t >> 14 ) ) & 0xFFFFFFFF;
	}

	/** Integer in [0, $max). */
	public function next_int( int $max ): int {
		return $this->next_u32() % $max;
	}

	/** Deterministic Fisher-Yates shuffle (returns a re-indexed copy). */
	public function shuffle( array $items ): array {
		$items = array_values( $items );
		for ( $i = count( $items ) - 1; $i > 0; $i-- ) {
			$j           = $this->next_int( $i + 1 );
			$tmp         = $items[ $i ];
			$items[ $i ] = $items[ $j ];
			$items[ $j ] = $tmp;
		}
		return $items;
	}
}
