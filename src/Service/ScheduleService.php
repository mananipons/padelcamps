<?php
namespace PadelCamps\Service;

use PadelCamps\Db\ScheduleRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduling workflow for a whole tournament (all categories share the courts).
 * Group matches first (by round, so every pair plays a little each day), then
 * knockout rounds. Knockout round 1 waits for every group match of its category.
 */
final class ScheduleService {

	private TournamentRepository $tournaments;
	private ScheduleRepository $schedule;

	public function __construct( TournamentRepository $tournaments, ScheduleRepository $schedule ) {
		$this->tournaments = $tournaments;
		$this->schedule    = $schedule;
	}

	public function config( array $t ): array {
		$raw = $this->tournaments->settings( $t )['schedule'] ?? array();
		return Scheduler::normalize( is_array( $raw ) ? $raw : array() );
	}

	public function save_config( int $tid, array $raw ): void {
		$this->tournaments->save_setting( $tid, 'schedule', Scheduler::normalize( $raw ) );
	}

	/** Matches in scheduling priority order, with the 'after' dependencies. */
	public function ordered( int $tid ): array {
		$all = $this->schedule->matches( $tid );
		usort(
			$all,
			static fn( $x, $y ) => array( (int) $x['phase_sort'], (int) $x['round'], (int) $x['category_sort'], (int) $x['category_id'], (int) $x['group_no'], (int) $x['position'], (int) $x['id'] )
				<=> array( (int) $y['phase_sort'], (int) $y['round'], (int) $y['category_sort'], (int) $y['category_id'], (int) $y['group_no'], (int) $y['position'], (int) $y['id'] )
		);
		return $all;
	}

	/**
	 * @return array{assigned:int, unscheduled:array<int,string>}
	 */
	public function run( array $t, bool $reset ): array {
		$tid = (int) $t['id'];
		if ( $reset ) {
			$this->schedule->clear( $tid );
		}
		$config  = $this->config( $t );
		$courts  = array_map( static fn( $c ) => (int) $c['id'], $this->schedule->courts( $tid ) );
		$matches = $this->ordered( $tid );

		// Who feeds each match: winners (next_match_id) and, for the consolation
		// bracket, losers (loser_next_match_id). Byes are not played, so a match fed
		// by a bye waits for whatever feeds that bye.
		$group_ids_of = array();
		$sources      = array();
		$status       = array();
		foreach ( $matches as $m ) {
			$status[ (int) $m['id'] ] = $m['status'];
			if ( in_array( $m['phase_type'], array( 'groups', 'round_robin' ), true ) ) {
				$group_ids_of[ (int) $m['category_id'] ][] = (int) $m['id'];
			}
			foreach ( array( 'next_match_id', 'loser_next_match_id' ) as $link ) {
				if ( ! empty( $m[ $link ] ) ) {
					$sources[ (int) $m[ $link ] ][] = (int) $m['id'];
				}
			}
		}
		$deps = function ( int $id, int $depth = 0 ) use ( &$deps, $sources, $status ): array {
			$out = array();
			foreach ( $sources[ $id ] ?? array() as $src ) {
				if ( 'bye' === ( $status[ $src ] ?? '' ) ) {
					if ( $depth < 10 ) {
						$out = array_merge( $out, $deps( $src, $depth + 1 ) );
					}
				} else {
					$out[] = $src;
				}
			}
			return $out;
		};

		$input = array();
		foreach ( $matches as $m ) {
			if ( 'bye' === $m['status'] ) {
				continue;
			}
			$id    = (int) $m['id'];
			$after = array_values( array_unique( $deps( $id ) ) );
			if ( 'knockout' === $m['phase_type'] && 1 === (int) $m['round'] ) {
				$after = array_merge( $after, $group_ids_of[ (int) $m['category_id'] ] ?? array() );
			}
			$fixed = null;
			if ( $m['scheduled_at'] && $m['court_id'] ) {
				$fixed = array(
					'start' => (string) $m['scheduled_at'],
					'court' => (int) $m['court_id'],
				);
			} elseif ( 'played' === $m['status'] ) {
				continue; // Played without a time: nothing to plan.
			}
			$input[] = array(
				'id'    => $id,
				'a'     => $m['reg_a_id'] ? (int) $m['reg_a_id'] : null,
				'b'     => $m['reg_b_id'] ? (int) $m['reg_b_id'] : null,
				'after' => $after,
				'fixed' => $fixed,
			);
		}

		$res = Scheduler::schedule( $config, $input, $courts, $this->schedule->availability( $tid ) );
		foreach ( $res['assigned'] as $id => $a ) {
			$this->schedule->set_schedule( $id, $a['start'] . ':00', $a['court'] );
		}
		return array(
			'assigned'    => count( $res['assigned'] ),
			'unscheduled' => $res['unscheduled'],
		);
	}

	/** Problems in the current schedule. */
	public function conflicts( array $t ): array {
		$tid       = (int) $t['id'];
		$scheduled = array();
		foreach ( $this->schedule->matches( $tid ) as $m ) {
			if ( $m['scheduled_at'] && 'bye' !== $m['status'] ) {
				$scheduled[] = array(
					'id'    => (int) $m['id'],
					'a'     => $m['reg_a_id'] ? (int) $m['reg_a_id'] : null,
					'b'     => $m['reg_b_id'] ? (int) $m['reg_b_id'] : null,
					'start' => (string) $m['scheduled_at'],
					'court' => $m['court_id'] ? (int) $m['court_id'] : null,
				);
			}
		}
		return Scheduler::conflicts( $this->config( $t ), $scheduled, $this->schedule->availability( $tid ) );
	}
}
