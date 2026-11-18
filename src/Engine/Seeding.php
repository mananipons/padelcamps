<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

/**
 * How strong an entrant is for seeding and pots. Pure logic, no WordPress.
 *
 * The draw (Draw / DrawPlan) already orders entrants by `rating`, highest first, ties by
 * id. This turns two inputs into that single number:
 *
 *   1. the points the pair has in the season ranking of the category (sum of its two
 *      players, from other tournaments), which decide the order;
 *   2. the manual rating the organizer typed in the registration, which only breaks ties.
 *
 *   strength = season points + manual rating / 1000      (manual rating is at most 999.99,
 *                                                         so it can never outweigh 1 point)
 *
 * With no season points anywhere the order is exactly the old one (by manual rating).
 */
final class Seeding {

	public const MAX_MANUAL = 999.99;

	/** Sorting number of one entrant. */
	public static function strength( int $points, ?float $manual ): float {
		$manual = null === $manual ? 0.0 : max( 0.0, min( self::MAX_MANUAL, $manual ) );
		return (float) $points + $manual / 1000;
	}

	/**
	 * @param array<int, array{id:int|string, rating?:mixed}> $entrants as returned by DrawRepository::confirmed_entrants()
	 * @param array<int,int> $points registration id => season points (a missing id counts as 0)
	 * @return array<int, array> the same entrants, in the same order, with:
	 *   rating        the strength (what the draw sorts by),
	 *   manual_rating the organizer's rating as it was (float|null),
	 *   season_points the points used (int)
	 */
	public static function apply( array $entrants, array $points ): array {
		foreach ( $entrants as $i => $e ) {
			$manual = isset( $e['rating'] ) && is_numeric( $e['rating'] ) ? (float) $e['rating'] : null;
			$pts    = (int) ( $points[ (int) $e['id'] ] ?? 0 );

			$entrants[ $i ]['manual_rating'] = $manual;
			$entrants[ $i ]['season_points'] = $pts;
			$entrants[ $i ]['rating']        = self::strength( $pts, $manual );
		}
		return $entrants;
	}

	/**
	 * Points of a pair: the sum of what each of its players has.
	 *
	 * @param int[]          $player_ids       players of the registration
	 * @param array<int,int> $points_by_player player id => points (a missing id counts as 0)
	 */
	public static function pair_points( array $player_ids, array $points_by_player ): int {
		$sum = 0;
		foreach ( array_unique( array_map( 'intval', $player_ids ) ) as $pid ) {
			$sum += (int) ( $points_by_player[ $pid ] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Entrants strongest first, ties by id (the order the draw uses).
	 *
	 * @param array<int, array> $entrants with `rating` (see apply())
	 */
	public static function ordered( array $entrants ): array {
		usort( $entrants, static fn( $a, $b ) => ( (float) $b['rating'] <=> (float) $a['rating'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
		return $entrants;
	}
}
