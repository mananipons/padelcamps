<?php
namespace PadelCamps\Service;

use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RankingRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\SeasonRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\PointsScheme;
use PadelCamps\Engine\RoundReached;

defined( 'ABSPATH' ) || exit;

/**
 * Season ranking points of a tournament category: reads the real matches, asks
 * Engine\RoundReached what each pair reached and turns it into points per player.
 * Points only reach the ranking when the organizer publishes them.
 *
 * States of a category (see evaluate()):
 *  - not_eligible  the tournament has no season, does not count, or the category has no ranking category
 *  - incomplete    the final / the league is not finished yet
 *  - ready         complete, not published
 *  - published     published and still equal to what the results say
 *  - outdated      published, but the results changed since (publish again to update)
 */
final class RankingService {

	private DrawRepository $draws;
	private TournamentRepository $tournaments;
	private RankingRepository $ranking;
	private SeasonRepository $seasons;
	private RegistrationRepository $regs;

	public function __construct( ?DrawRepository $draws = null, ?TournamentRepository $tournaments = null, ?RankingRepository $ranking = null, ?SeasonRepository $seasons = null, ?RegistrationRepository $regs = null ) {
		$this->draws       = $draws ?? new DrawRepository();
		$this->tournaments = $tournaments ?? new TournamentRepository();
		$this->ranking     = $ranking ?? new RankingRepository();
		$this->seasons     = $seasons ?? new SeasonRepository();
		$this->regs        = $regs ?? new RegistrationRepository();
	}

	/**
	 * @param array $category row of pc_categories (fresh: points_published_at is read from it)
	 * @return array{state:string, complete:bool, rows:array, withdrawn:array, pairs:array}
	 *   rows: [registration_id, player_id, points, result]; pairs: registration id => ['names'=>string, 'result'=>?string, 'points'=>?int]
	 */
	public function evaluate( array $category ): array {
		$empty = array(
			'state'     => 'not_eligible',
			'complete'  => false,
			'rows'      => array(),
			'withdrawn' => array(),
			'pairs'     => array(),
		);

		$tournament = $this->tournaments->find( (int) $category['tournament_id'] );
		$season     = $tournament && ! empty( $tournament['season_id'] ) && ! empty( $tournament['counts_for_ranking'] ) ? $this->seasons->find( (int) $tournament['season_id'] ) : null;
		$rc         = $season && ! empty( $category['ranking_category_id'] ) ? $this->seasons->ranking_category( (int) $category['ranking_category_id'] ) : null;
		if ( ! $season || ! $rc || (int) $rc['season_id'] !== (int) $season['id'] ) {
			return $empty;
		}

		$cid       = (int) $category['id'];
		$phases    = $this->draws->phases( $cid );
		$entrants  = array_map( 'intval', wp_list_pluck( $this->draws->confirmed_entrants( $cid ), 'id' ) );
		$types     = array();
		foreach ( array( 'group', 'knockout', 'consolation' ) as $slot ) {
			if ( ! empty( $phases[ $slot ] ) ) {
				$types[ (int) $phases[ $slot ]['id'] ] = (string) $phases[ $slot ]['type'];
			}
		}

		$all     = $this->draws->matches( $cid );
		$matches = array();
		foreach ( $all as $m ) {
			$type = $types[ (int) $m['phase_id'] ] ?? null;
			if ( null === $type ) {
				continue;
			}
			$a        = $m['reg_a_id'] ? (int) $m['reg_a_id'] : null;
			$b        = $m['reg_b_id'] ? (int) $m['reg_b_id'] : null;
			$walkover = is_array( $m['score'] ) ? ( $m['score']['walkover'] ?? null ) : null; // side that WINS by walkover.
			$matches[] = array(
				'phase'          => $type,
				'round'          => (int) $m['round'],
				'a'              => $a,
				'b'              => $b,
				'winner'         => $m['winner_reg_id'] ? (int) $m['winner_reg_id'] : null,
				'walkover_loser' => 'a' === $walkover ? $b : ( 'b' === $walkover ? $a : null ),
			);
		}

		$ranking = array();
		if ( 'round_robin' === $category['format'] ) {
			$service = new DrawService( $this->draws );
			$tables  = $service->standings( $cid, $all, $service->format( $phases ) );
			if ( 1 === count( $tables ) ) {
				$ranking = array_map( 'intval', wp_list_pluck( array_values( $tables )[0], 'team' ) );
			}
		}

		$res     = RoundReached::compute( (string) $category['format'], $entrants, $matches, $ranking );
		$players = $this->regs->players_for( $entrants );
		$scheme  = $this->seasons->scheme( $season );
		$points  = RoundReached::points( $res['results'], $scheme );

		$pairs = array();
		foreach ( $entrants as $reg_id ) {
			$names = implode( ' / ', wp_list_pluck( $players[ $reg_id ] ?? array(), 'name' ) );
			$pairs[ $reg_id ] = array(
				'names'  => $names,
				'result' => $res['results'][ $reg_id ] ?? null,
				'points' => $points[ $reg_id ] ?? null,
			);
		}

		$rows = array();
		foreach ( $res['results'] as $reg_id => $result ) {
			foreach ( $players[ $reg_id ] ?? array() as $player ) {
				$rows[] = array(
					'registration_id' => (int) $reg_id,
					'player_id'       => (int) $player['id'],
					'points'          => $points[ $reg_id ],
					'result'          => $result,
				);
			}
		}

		$published = ! empty( $category['points_published_at'] );
		if ( ! $res['complete'] ) {
			$state = $published ? 'outdated' : 'incomplete';
		} elseif ( ! $published ) {
			$state = 'ready';
		} else {
			$state = self::signature( $rows ) === $this->ranking->category_signature( $cid ) ? 'published' : 'outdated';
		}

		return array(
			'state'     => $state,
			'complete'  => $res['complete'],
			'rows'      => $rows,
			'withdrawn' => $res['withdrawn'],
			'pairs'     => $pairs,
		);
	}

	/** @return string[] "player_id:points:result", sorted (same shape as RankingRepository::category_signature()). */
	private static function signature( array $rows ): array {
		$sig = array_map( static fn( $r ) => (int) $r['player_id'] . ':' . (int) $r['points'] . ':' . $r['result'], $rows );
		sort( $sig );
		return $sig;
	}

	/**
	 * Publish (or re-publish) the points of a category.
	 *
	 * @return ?string error code: not_found | not_eligible | incomplete, or null when published
	 */
	public function publish( int $category_id ): ?string {
		$category = $this->tournaments->category( $category_id );
		if ( ! $category ) {
			return 'not_found';
		}
		$eval = $this->evaluate( $category );
		if ( 'not_eligible' === $eval['state'] ) {
			return 'not_eligible';
		}
		if ( ! $eval['complete'] ) {
			return 'incomplete'; // also when published points went stale because a result was reopened.
		}
		$tournament = $this->tournaments->find( (int) $category['tournament_id'] );
		$this->ranking->publish( $category_id, (int) $category['tournament_id'], (int) $tournament['season_id'], (int) $category['ranking_category_id'], $eval['rows'] );
		return null;
	}

	public function unpublish( int $category_id ): ?string {
		if ( ! $this->tournaments->category( $category_id ) ) {
			return 'not_found';
		}
		$this->ranking->unpublish( $category_id );
		return null;
	}
}
