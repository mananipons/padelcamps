<?php
namespace PadelCamps\Service;

use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RankingRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\SeasonRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\Seeding;

defined( 'ABSPATH' ) || exit;

/**
 * Entrants of a category with the strength the draw sorts them by (see Engine\Seeding).
 *
 * When the tournament belongs to a season that counts for the ranking and the category is
 * linked to a ranking category, each pair is ordered by the season points of its two
 * players in that ranking category (points from OTHER tournaments, plus manual
 * adjustments) and the organizer's manual rating only breaks ties. In any other case the
 * entrants are returned untouched: the draw keeps using the manual rating alone.
 */
final class SeedingService {

	private DrawRepository $draws;
	private TournamentRepository $tournaments;
	private SeasonRepository $seasons;
	private RankingRepository $ranking;
	private RegistrationRepository $regs;

	public function __construct( ?DrawRepository $draws = null, ?TournamentRepository $tournaments = null, ?SeasonRepository $seasons = null, ?RankingRepository $ranking = null, ?RegistrationRepository $regs = null ) {
		$this->draws       = $draws ?? new DrawRepository();
		$this->tournaments = $tournaments ?? new TournamentRepository();
		$this->seasons     = $seasons ?? new SeasonRepository();
		$this->ranking     = $ranking ?? new RankingRepository();
		$this->regs        = $regs ?? new RegistrationRepository();
	}

	/**
	 * The season and ranking category that feed the seeding of a category, or null when the
	 * draw only uses the manual rating (no season, tournament not counting, or no ranking category).
	 *
	 * @return ?array{season: array, ranking_category: array}
	 */
	public function source( array $category ): ?array {
		$tournament = $this->tournaments->find( (int) $category['tournament_id'] );
		if ( ! $tournament || empty( $tournament['season_id'] ) || empty( $tournament['counts_for_ranking'] ) || empty( $category['ranking_category_id'] ) ) {
			return null;
		}
		$season = $this->seasons->find( (int) $tournament['season_id'] );
		$rc     = $this->seasons->ranking_category( (int) $category['ranking_category_id'] );
		if ( ! $season || ! $rc || (int) $rc['season_id'] !== (int) $season['id'] ) {
			return null;
		}
		return array(
			'season'           => $season,
			'ranking_category' => $rc,
		);
	}

	/**
	 * Confirmed entrants of the category, as DrawRepository::confirmed_entrants() returns them,
	 * with `rating` replaced by the strength (and `manual_rating` / `season_points` added) when
	 * the season applies. Otherwise exactly the plain entrants.
	 */
	public function entrants( array $category ): array {
		$entrants = $this->draws->confirmed_entrants( (int) $category['id'] );
		$source   = $this->source( $category );
		if ( ! $source || ! $entrants ) {
			return $entrants;
		}

		$players   = $this->regs->players_for( wp_list_pluck( $entrants, 'id' ) );
		$by_player = $this->ranking->player_points(
			array_map( 'intval', array_merge( array(), ...array_map( static fn( $list ) => wp_list_pluck( $list, 'id' ), array_values( $players ) ) ) ),
			(int) $source['season']['id'],
			(int) $source['ranking_category']['id'],
			(int) $category['tournament_id']
		);

		$points = array();
		foreach ( $entrants as $e ) {
			$points[ (int) $e['id'] ] = Seeding::pair_points( array_map( 'intval', wp_list_pluck( $players[ (int) $e['id'] ] ?? array(), 'id' ) ), $by_player );
		}
		return Seeding::apply( $entrants, $points );
	}
}
