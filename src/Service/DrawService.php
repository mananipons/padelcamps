<?php
namespace PadelCamps\Service;

use PadelCamps\Db\DrawRepository;
use PadelCamps\Engine\DrawPlan;
use PadelCamps\Engine\Groups;
use PadelCamps\Engine\MatchFormat;
use PadelCamps\Engine\Qualification;
use PadelCamps\Engine\ScoreParser;
use PadelCamps\Engine\Standings;

defined( 'ABSPATH' ) || exit;

/**
 * Draw and results workflow for one category. Uses the pure Engine for every
 * decision and DrawRepository for storage. Methods return null on success or
 * an error code (the admin layer turns it into a message).
 *
 * Rules:
 *  - Only 'confirmed' registrations enter the draw.
 *  - Once any match has a result, the draw is locked (no redraw / swap / clear).
 *  - Group results cannot change once the final bracket exists (clear it first).
 *  - A knockout result cannot change if the next match is already played.
 */
final class DrawService {

	private DrawRepository $draws;

	public function __construct( DrawRepository $draws ) {
		$this->draws = $draws;
	}

	public function format( array $phases ): array {
		$phase = $phases['group'] ?? $phases['knockout'];
		return MatchFormat::normalize( is_array( $phase['settings']['format'] ?? null ) ? $phase['settings']['format'] : array() );
	}

	/** Tie-break criteria of the category (group phase settings), or the default order. */
	public function criteria( array $phases ): array {
		$criteria = $phases['group']['settings']['criteria'] ?? null;
		return is_array( $criteria ) && $criteria ? array_values( $criteria ) : Standings::DEFAULT_CRITERIA;
	}

	public function draw( array $category, string $seed, int $group_size ): ?string {
		$cid = (int) $category['id'];
		if ( $this->draws->has_results( $cid ) ) {
			return 'has_results';
		}
		$entrants = $this->draws->confirmed_entrants( $cid );
		if ( count( $entrants ) < 2 ) {
			return 'not_enough';
		}
		$phases = $this->draws->phases( $cid );
		if ( ! $phases['group'] && ! $phases['knockout'] ) {
			return 'no_phases';
		}

		$this->draws->clear( $cid );
		$by_rating = DrawPlan::by_rating( $entrants );

		if ( $phases['group'] ) {
			$groups = 'round_robin' === $phases['group']['type']
				? array( 0 => $by_rating )
				: DrawPlan::assign_groups( $entrants, $group_size, $seed );
			$this->draws->save_groups( $cid, $groups, $by_rating );
			$this->draws->insert_group_matches( $cid, (int) $phases['group']['id'], Groups::matches( $groups ) );
			$phase_id = (int) $phases['group']['id'];
			$result   = array( 'groups' => $groups );
		} else {
			$seeds = DrawPlan::assign_seeds( $entrants, $seed );
			$this->draws->save_seeds( $cid, $seeds );
			$this->insert_main_knockout( $cid, $phases, $seeds );
			$phase_id = (int) $phases['knockout']['id'];
			$result   = array( 'seeds' => $seeds );
		}

		$this->draws->set_category_seed( $cid, $seed );
		$this->draws->log(
			$cid,
			$phase_id,
			$seed,
			array(
				'action'     => 'draw',
				'entrants'   => array_map( static fn( $e ) => array( (int) $e['id'], null === $e['rating'] ? null : (float) $e['rating'] ), $entrants ),
				'group_size' => $group_size,
				'format'     => $category['format'],
			),
			$result
		);
		return null;
	}

	/** Rebuild the matches of the current assignment (no randomness). */
	private function rebuild( int $cid ): void {
		$phases = $this->draws->phases( $cid );
		$this->delete_brackets( $cid, $phases );
		if ( $phases['group'] ) {
			$this->draws->delete_phase_matches( $cid, (int) $phases['group']['id'] );
			$this->draws->insert_group_matches( $cid, (int) $phases['group']['id'], Groups::matches( $this->draws->current_groups( $cid ) ) );
		} elseif ( $phases['knockout'] ) {
			$this->insert_main_knockout( $cid, $phases, $this->draws->current_seeds( $cid ) );
		}
	}

	public function swap( array $category, int $x, int $y ): ?string {
		$cid = (int) $category['id'];
		if ( $this->draws->has_results( $cid ) ) {
			return 'has_results';
		}
		$seeds = $this->draws->current_seeds( $cid );
		if ( $x === $y || ! in_array( $x, $seeds, true ) || ! in_array( $y, $seeds, true ) ) {
			return 'bad_swap';
		}
		$phases = $this->draws->phases( $cid );
		if ( $phases['group'] ) {
			$groups = DrawPlan::swap_in_groups( $this->draws->current_groups( $cid ), $x, $y, array_flip( $seeds ) );
			$this->draws->save_groups( $cid, $groups, $seeds );
		} else {
			$this->draws->save_seeds( $cid, DrawPlan::swap_in_seeds( $seeds, $x, $y ) );
		}
		$this->rebuild( $cid );
		$this->draws->log( $cid, null, (string) $category['seed'], array( 'action' => 'swap', 'swap' => array( $x, $y ) ), array() );
		return null;
	}

	public function clear( array $category ): ?string {
		$cid = (int) $category['id'];
		if ( $this->draws->has_results( $cid ) ) {
			return 'has_results';
		}
		$this->draws->clear( $cid );
		$this->draws->log( $cid, null, (string) $category['seed'], array( 'action' => 'clear' ), array() );
		return null;
	}

	/** @return array<int, array> 0-based group => Standings rows */
	public function standings( int $cid, array $matches, array $format ): array {
		$phases = $this->draws->phases( $cid );
		if ( ! $phases['group'] ) {
			return array();
		}
		$by_group = array();
		foreach ( $matches as $m ) {
			if ( (int) $m['phase_id'] === (int) $phases['group']['id'] ) {
				$by_group[ (int) $m['group_no'] - 1 ][] = array(
					'a'      => (int) $m['reg_a_id'],
					'b'      => (int) $m['reg_b_id'],
					'result' => 'played' === $m['status'] ? $m['score'] : null,
				);
			}
		}
		$tables = array();
		foreach ( $this->draws->current_groups( $cid ) as $g => $ids ) {
			$tables[ $g ] = Standings::compute( $ids, $by_group[ $g ] ?? array(), $format, $this->criteria( $phases ) );
		}
		return $tables;
	}

	public function build_final( array $category ): ?string {
		$cid    = (int) $category['id'];
		$phases = $this->draws->phases( $cid );
		if ( ! $phases['group'] || 'groups' !== $phases['group']['type'] || ! $phases['knockout'] ) {
			return 'no_phases';
		}
		$gid   = (int) $phases['group']['id'];
		$kid   = (int) $phases['knockout']['id'];
		$all   = $this->draws->matches( $cid );
		$group = array_filter( $all, static fn( $m ) => (int) $m['phase_id'] === $gid );
		if ( ! $group || count( array_filter( $group, static fn( $m ) => 'played' === $m['status'] ) ) < count( $group ) ) {
			return 'groups_incomplete';
		}
		if ( $this->draws->count_matches( $cid, $kid ) > 0 ) {
			return 'final_exists';
		}
		$tables    = $this->standings( $cid, $all, $this->format( $phases ) );
		$advance   = (int) ( $phases['group']['settings']['advance'] ?? 2 );
		$seed_rank = array();
		foreach ( $this->draws->current_seeds( $cid ) as $i => $id ) {
			$seed_rank[ $id ] = $i + 1;
		}
		$q = Qualification::knockout_entrants( $tables, $advance, $seed_rank );
		if ( count( $q['entrants'] ) < 2 ) {
			return 'not_enough';
		}
		$this->insert_main_knockout( $cid, $phases, $q['entrants'] );
		$this->draws->log( $cid, $kid, (string) $category['seed'], array( 'action' => 'final', 'advance' => $advance ), array( 'seeds' => $q['entrants'] ) );
		return null;
	}

	public function clear_final( array $category ): ?string {
		$cid    = (int) $category['id'];
		$phases = $this->draws->phases( $cid );
		if ( ! $phases['knockout'] || ! $phases['group'] ) {
			return 'no_phases';
		}
		$kid = (int) $phases['knockout']['id'];
		if ( $this->draws->has_results( $cid, $kid ) || ( $phases['consolation'] && $this->draws->has_results( $cid, (int) $phases['consolation']['id'] ) ) ) {
			return 'has_results';
		}
		$this->delete_brackets( $cid, $phases );
		return null;
	}

	/**
	 * Save (or clear, when both inputs are empty) a match result.
	 *
	 * @param string $special '' | wo_a | wo_b | ret_a | ret_b
	 */
	public function set_result( array $category, int $match_id, string $text, string $special ): ?string {
		$cid   = (int) $category['id'];
		$match = $this->draws->find_match( $match_id );
		if ( ! $match || (int) $match['category_id'] !== $cid ) {
			return 'not_found';
		}
		if ( 'bye' === $match['status'] || null === $match['reg_a_id'] || null === $match['reg_b_id'] ) {
			return 'not_ready';
		}
		$phases   = $this->draws->phases( $cid );
		$is_group = $phases['group'] && (int) $match['phase_id'] === (int) $phases['group']['id'];
		if ( $is_group && $phases['knockout'] && $this->draws->count_matches( $cid, (int) $phases['knockout']['id'] ) > 0 ) {
			return 'final_exists';
		}

		$next = null;
		if ( ! $is_group && $match['next_match_id'] ) {
			$next = $this->draws->find_match( (int) $match['next_match_id'] );
		}

		$text = trim( $text );
		if ( '' === $text && '' === $special ) {
			if ( $next && 'played' === $next['status'] ) {
				return 'next_played';
			}
			$blocked = $this->place_loser( $match, null, false );
			if ( null !== $blocked ) {
				return $blocked;
			}
			if ( $next ) {
				$this->draws->set_slot( (int) $next['id'], (string) $match['next_slot'], null );
			}
			$this->place_loser( $match, null, true );
			$this->draws->update_result( $match_id, null, null, 'scheduled' );
			return null;
		}

		$sets = ScoreParser::parse( $text );
		if ( null === $sets ) {
			return 'invalid_score';
		}
		$result = array( 'sets' => $sets );
		switch ( $special ) {
			case 'wo_a':
				$result = array( 'walkover' => 'a' );
				break;
			case 'wo_b':
				$result = array( 'walkover' => 'b' );
				break;
			case 'ret_a':
				$result['retired'] = 'a';
				break;
			case 'ret_b':
				$result['retired'] = 'b';
				break;
		}
		$e = MatchFormat::evaluate( $result, $this->format( $phases ) );
		if ( ! $e['valid'] ) {
			return 'score_' . $e['error'];
		}
		$winner = 'a' === $e['winner'] ? (int) $match['reg_a_id'] : (int) $match['reg_b_id'];
		$loser  = $winner === (int) $match['reg_a_id'] ? (int) $match['reg_b_id'] : (int) $match['reg_a_id'];

		// Check everything first, then write: never leave half an update.
		if ( $next && 'played' === $next['status'] && (int) $match['winner_reg_id'] !== $winner ) {
			return 'next_played';
		}
		$blocked = $this->place_loser( $match, $loser, false );
		if ( null !== $blocked ) {
			return $blocked;
		}
		if ( $next ) {
			$this->draws->set_slot( (int) $next['id'], (string) $match['next_slot'], $winner );
		}
		$this->place_loser( $match, $loser, true );
		$this->draws->update_result( $match_id, $result, $winner, 'played' );
		return null;
	}

	/**
	 * Send the loser of a main round-1 match to the consolation bracket (or take
	 * it back with $loser = null). If its consolation match is a bye, the pair
	 * walks straight to the next consolation round.
	 *
	 * @param bool $apply false = only check whether it is allowed.
	 * @return ?string error code (next_played) or null
	 */
	private function place_loser( array $match, ?int $loser, bool $apply ): ?string {
		if ( empty( $match['loser_next_match_id'] ) ) {
			return null;
		}
		$target = $this->draws->find_match( (int) $match['loser_next_match_id'] );
		if ( ! $target ) {
			return null;
		}
		$slot    = 'a' === $match['loser_next_slot'] ? 'a' : 'b';
		$current = $target[ 'reg_' . $slot . '_id' ] ? (int) $target[ 'reg_' . $slot . '_id' ] : null;
		if ( $current === $loser ) {
			return null;
		}
		if ( 'bye' === $target['status'] ) {
			$after = $target['next_match_id'] ? $this->draws->find_match( (int) $target['next_match_id'] ) : null;
			if ( $after && 'played' === $after['status'] ) {
				return 'next_played';
			}
			if ( $apply ) {
				$this->draws->set_slot( (int) $target['id'], $slot, $loser );
				$this->draws->set_winner( (int) $target['id'], $loser );
				if ( $after ) {
					$this->draws->set_slot( (int) $after['id'], (string) $target['next_slot'], $loser );
				}
			}
			return null;
		}
		if ( 'played' === $target['status'] ) {
			return 'next_played';
		}
		if ( $apply ) {
			$this->draws->set_slot( (int) $target['id'], $slot, $loser );
		}
		return null;
	}

	/**
	 * Main knockout bracket from ids in seed order, plus the consolation bracket
	 * when the knockout phase has it enabled.
	 */
	private function insert_main_knockout( int $cid, array $phases, array $seeded_ids ): void {
		$kid = (int) $phases['knockout']['id'];
		$ko  = DrawPlan::knockout( $seeded_ids );
		$this->draws->insert_knockout( $cid, $kid, $ko );

		if ( empty( $phases['knockout']['settings']['consolation'] ) ) {
			return;
		}
		$plan = DrawPlan::consolation( $ko );
		if ( ! $plan['matches'] ) {
			return;
		}
		$cons = $this->draws->ensure_consolation_phase( $cid, $phases['knockout'] );
		$r1   = array();
		foreach ( $this->draws->matches( $cid ) as $m ) {
			if ( (int) $m['phase_id'] === $kid && 1 === (int) $m['round'] ) {
				$r1[ (int) $m['position'] ] = (int) $m['id'];
			}
		}
		$this->draws->insert_consolation( $cid, (int) $cons['id'], $plan, $r1 );
	}

	/** Delete the knockout and consolation matches of a category. */
	private function delete_brackets( int $cid, array $phases ): void {
		foreach ( array( 'knockout', 'consolation' ) as $key ) {
			if ( $phases[ $key ] ) {
				$this->draws->delete_phase_matches( $cid, (int) $phases[ $key ]['id'] );
			}
		}
	}
}
