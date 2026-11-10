<?php
namespace PadelCamps\Service;

use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\ScheduleRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\PublicName;
use PadelCamps\Engine\ScoreParser;

defined( 'ABSPATH' ) || exit;

/**
 * Public order of play: scheduled matches by day, time and court, with the
 * result once played. Only public data (pair names with the tournament privacy
 * mode). Used by the [padelcamps_schedule] shortcode, the TV page and the API.
 */
final class PublicSchedule {

	private TournamentRepository $tournaments;
	private ScheduleRepository $schedule;

	public function __construct() {
		$this->tournaments = new TournamentRepository();
		$this->schedule    = new ScheduleRepository();
	}

	/**
	 * @return array{days: array<int, array{date:string, label:string, matches:array}>, unscheduled:int}
	 */
	public function build( array $t, int $only_category = 0 ): array {
		$tid     = (int) $t['id'];
		$mode    = $this->tournaments->public_names( $t );
		$all     = $this->schedule->matches( $tid );
		$labels  = new MatchLabel( $all );
		$courts  = array_column( $this->schedule->courts( $tid ), 'name', 'id' );
		$matches = array_values(
			array_filter(
				$all,
				static fn( $m ) => 'bye' !== $m['status'] && ( ! $only_category || (int) $m['category_id'] === $only_category )
			)
		);

		$ids   = array_filter( array_merge( wp_list_pluck( $matches, 'reg_a_id' ), wp_list_pluck( $matches, 'reg_b_id' ) ) );
		$names = array();
		foreach ( ( new RegistrationRepository() )->players_for( $ids ) as $id => $players ) {
			$names[ $id ] = PublicName::pair( wp_list_pluck( $players, 'name' ), $mode );
		}

		$court_order = array_flip( array_keys( $courts ) );
		$scheduled   = array_filter( $matches, static fn( $m ) => ! empty( $m['scheduled_at'] ) );
		usort(
			$scheduled,
			static fn( $x, $y ) => array( $x['scheduled_at'], $court_order[ (int) $x['court_id'] ] ?? 99, (int) $x['id'] )
				<=> array( $y['scheduled_at'], $court_order[ (int) $y['court_id'] ] ?? 99, (int) $y['id'] )
		);

		$days = array();
		foreach ( $scheduled as $m ) {
			$date = substr( (string) $m['scheduled_at'], 0, 10 );
			if ( ! isset( $days[ $date ] ) ) {
				$days[ $date ] = array(
					'date'    => $date,
					'label'   => ucfirst( mysql2date( 'l j F', $date . ' 00:00:00' ) ),
					'matches' => array(),
				);
			}
			$score  = is_array( json_decode( (string) $m['score'], true ) ) ? json_decode( (string) $m['score'], true ) : null;
			$winner = null;
			if ( $m['winner_reg_id'] && 'played' === $m['status'] ) {
				$winner = (int) $m['winner_reg_id'] === (int) $m['reg_a_id'] ? 'a' : 'b';
			}
			$days[ $date ]['matches'][] = array(
				'id'       => (int) $m['id'],
				'time'     => substr( (string) $m['scheduled_at'], 11, 5 ),
				'start'    => (string) $m['scheduled_at'],
				'court'    => $m['court_id'] ? ( $courts[ (int) $m['court_id'] ] ?? '' ) : '',
				'category' => $m['category_name'],
				'stage'    => $labels->stage( $m ),
				'a'        => $m['reg_a_id'] ? ( $names[ (int) $m['reg_a_id'] ] ?? '' ) : null,
				'b'        => $m['reg_b_id'] ? ( $names[ (int) $m['reg_b_id'] ] ?? '' ) : null,
				'played'   => 'played' === $m['status'],
				'winner'   => $winner,
				'score'    => self::score_text( $score ),
			);
		}
		ksort( $days );

		return array(
			'days'        => array_values( $days ),
			'unscheduled' => count( $matches ) - count( $scheduled ),
		);
	}

	private static function score_text( ?array $score ): string {
		if ( ! $score ) {
			return '';
		}
		if ( ! empty( $score['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		$text = ScoreParser::format( $score['sets'] ?? array() );
		return ! empty( $score['retired'] ) ? trim( $text . ' ' . __( '(ret.)', 'padelcamps' ) ) : $text;
	}

	/**
	 * Matches for "now and next" (TV): today's matches that started at most
	 * $grace minutes ago or later; if none are left today, the next day with matches.
	 *
	 * @return array{label:string, matches:array}
	 */
	public static function upcoming( array $data, int $limit = 10, int $grace = 90 ): array {
		$now   = current_time( 'timestamp' );
		$today = gmdate( 'Y-m-d', $now );
		$from  = gmdate( 'Y-m-d H:i:s', $now - $grace * 60 );
		foreach ( $data['days'] as $day ) {
			if ( $day['date'] < $today ) {
				continue;
			}
			$list = array_values( array_filter( $day['matches'], static fn( $m ) => $day['date'] > $today || $m['start'] >= $from ) );
			if ( $list ) {
				return array(
					'label'   => $day['date'] === $today ? __( 'Ara i propers partits', 'padelcamps' ) : $day['label'],
					'matches' => array_slice( $list, 0, $limit ),
				);
			}
		}
		return array(
			'label'   => __( 'Propers partits', 'padelcamps' ),
			'matches' => array(),
		);
	}
}
