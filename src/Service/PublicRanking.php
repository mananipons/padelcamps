<?php
namespace PadelCamps\Service;

use PadelCamps\Db\RankingRepository;
use PadelCamps\Db\SeasonRepository;
use PadelCamps\Engine\PublicRanking as PublicRankingTable;

defined( 'ABSPATH' ) || exit;

/**
 * Public season ranking (shortcode, block and REST API share it). Season rankings are
 * public by nature: what is published is only what the organizer published (points of
 * published categories + manual adjustments), and what leaves is only the display name,
 * tournaments played and points (Engine\PublicRanking). Names follow the season's
 * privacy option (initials by default).
 */
final class PublicRanking {

	private SeasonRepository $seasons;
	private RankingRepository $ranking;

	public function __construct( ?SeasonRepository $seasons = null, ?RankingRepository $ranking = null ) {
		$this->seasons = $seasons ?? new SeasonRepository();
		$this->ranking = $ranking ?? new RankingRepository();
	}

	/** A season by id; id 0 = the current one (the one running today, else the latest). */
	public function season( int $id ): ?array {
		return $id > 0 ? $this->seasons->find( $id ) : $this->seasons->current();
	}

	/**
	 * @param int $category ranking category id; 0 or one that is not of this season = the first one
	 * @return array{
	 *   season: array{id:int, name:string, starts_on:?string, ends_on:?string},
	 *   categories: array<int, array{id:int, name:string}>,
	 *   category: ?array{id:int, name:string},
	 *   ranking: array<int, array{position:int, name:string, tournaments:int, points:int}>
	 * }
	 */
	public function build( array $season, int $category = 0 ): array {
		$sid  = (int) $season['id'];
		$cats = array_map(
			static fn( $c ) => array(
				'id'   => (int) $c['id'],
				'name' => (string) $c['name'],
			),
			$this->seasons->ranking_categories( $sid )
		);

		$chosen = null;
		foreach ( $cats as $c ) {
			if ( $c['id'] === $category ) {
				$chosen = $c;
			}
		}
		$chosen = $chosen ?? ( $cats[0] ?? null );

		return array(
			'season'     => array(
				'id'        => $sid,
				'name'      => (string) $season['name'],
				'starts_on' => $season['starts_on'] ?: null,
				'ends_on'   => $season['ends_on'] ?: null,
			),
			'categories' => $cats,
			'category'   => $chosen,
			'ranking'    => $chosen ? PublicRankingTable::rows( $this->ranking->totals( $sid, $chosen['id'] ), $this->seasons->public_names( $season ) ) : array(),
		);
	}
}
