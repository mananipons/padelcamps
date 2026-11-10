<?php
namespace PadelCamps\Service;

use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\ResultRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\MatchFormat;
use PadelCamps\Engine\ResultReport;

defined( 'ABSPATH' ) || exit;

/**
 * Results reported by players (magic link) and their confirmation:
 *  - a pair reports → 'pending' until the rival confirms;
 *  - the rival confirms, or reports the same score → applied;
 *  - the rival reports a different score or says it is wrong → 'disputed' (organizer decides).
 * Applying always goes through DrawService::set_result(), so standings,
 * brackets and all the locking rules are the same as in the admin.
 */
final class ResultService {

	private TournamentRepository $tournaments;
	private ResultRepository $results;
	private DrawRepository $draws;
	private DrawService $draw_service;

	public function __construct() {
		$this->tournaments  = new TournamentRepository();
		$this->results      = new ResultRepository();
		$this->draws        = new DrawRepository();
		$this->draw_service = new DrawService( $this->draws );
	}

	public function players_can_report( array $tournament ): bool {
		return false !== ( $this->tournaments->settings( $tournament )['player_results'] ?? true );
	}

	/** Match of the category where $reg plays, or null. */
	private function own_match( array $reg, int $match_id ): ?array {
		$m = $this->draws->find_match( $match_id );
		if ( ! $m || (int) $m['category_id'] !== (int) $reg['category_id'] ) {
			return null;
		}
		return in_array( (int) $reg['id'], array( (int) $m['reg_a_id'], (int) $m['reg_b_id'] ), true ) ? $m : null;
	}

	private function format_of( int $category_id ): array {
		return $this->draw_service->format( $this->draws->phases( $category_id ) );
	}

	/** Apply a result to the match (same path as the admin). @return ?string error code */
	private function apply( array $match, array $result ): ?string {
		$category = $this->tournaments->category( (int) $match['category_id'] );
		if ( ! $category ) {
			return 'not_found';
		}
		return $this->draw_service->set_result( $category, (int) $match['id'], ResultReport::to_text( $result ), ResultReport::special( $result ) );
	}

	/**
	 * A pair reports a result.
	 *
	 * @return string reported | confirmed | disputed | error code (closed, not_found, not_ready, played, score_*, …)
	 */
	public function report( array $tournament, array $reg, int $match_id, array $result ): string {
		if ( ! $this->players_can_report( $tournament ) ) {
			return 'closed';
		}
		$m = $this->own_match( $reg, $match_id );
		if ( ! $m ) {
			return 'not_found';
		}
		if ( 'played' === $m['status'] ) {
			return 'played';
		}
		if ( ! $m['reg_a_id'] || ! $m['reg_b_id'] ) {
			return 'not_ready';
		}
		$check = MatchFormat::evaluate( $result, $this->format_of( (int) $m['category_id'] ) );
		if ( ! $check['valid'] ) {
			return 'score_' . $check['error'];
		}

		$rival_report = null;
		foreach ( $this->results->open_reports( $match_id ) as $open ) {
			if ( (int) $open['reg_id'] !== (int) $reg['id'] ) {
				$rival_report = $open;
				break;
			}
		}

		if ( $rival_report && ResultReport::same( $rival_report['result'], $result ) ) {
			$error = $this->apply( $m, $result );
			if ( null !== $error ) {
				return $error;
			}
			$this->results->close_open( $match_id, 'confirmed' );
			$this->results->add_report( $match_id, (int) $reg['id'], $result, 'confirmed' );
			return 'confirmed';
		}
		if ( $rival_report ) {
			$this->results->close_open( $match_id, 'disputed' );
			$this->results->add_report( $match_id, (int) $reg['id'], $result, 'disputed' );
			( new Communication() )->result_disputed( $match_id );
			return 'disputed';
		}

		// Replace our own previous pending report, if any.
		$this->results->close_open( $match_id, 'superseded' );
		$this->results->add_report( $match_id, (int) $reg['id'], $result, 'pending' );
		( new Communication() )->result_reported( $match_id, (int) $reg['id'], $result );
		return 'reported';
	}

	/** The rival confirms a pending report. @return string confirmed | error code */
	public function confirm( array $reg, int $match_id, int $report_id ): string {
		$m      = $this->own_match( $reg, $match_id );
		$report = $this->results->find_report( $report_id );
		if ( ! $m || ! $report || (int) $report['match_id'] !== $match_id || 'pending' !== $report['status'] || (int) $report['reg_id'] === (int) $reg['id'] ) {
			return 'not_found';
		}
		$error = $this->apply( $m, $report['result'] );
		if ( null !== $error ) {
			return $error;
		}
		$this->results->close_open( $match_id, 'confirmed' );
		return 'confirmed';
	}

	/** The rival says a pending report is wrong. */
	public function dispute( array $reg, int $match_id, int $report_id ): string {
		$m      = $this->own_match( $reg, $match_id );
		$report = $this->results->find_report( $report_id );
		if ( ! $m || ! $report || (int) $report['match_id'] !== $match_id || 'pending' !== $report['status'] || (int) $report['reg_id'] === (int) $reg['id'] ) {
			return 'not_found';
		}
		$this->results->close_open( $match_id, 'disputed' );
		( new Communication() )->result_disputed( $match_id );
		return 'disputed';
	}

	/** Organizer applies one of the reported versions. @return ?string error */
	public function organizer_apply( int $tournament_id, int $report_id ): ?string {
		$report = $this->results->find_report( $report_id );
		$m      = $report ? $this->draws->find_match( (int) $report['match_id'] ) : null;
		$cat    = $m ? $this->tournaments->category( (int) $m['category_id'] ) : null;
		if ( ! $report || ! $m || ! $cat || (int) $cat['tournament_id'] !== $tournament_id ) {
			return 'not_found';
		}
		$error = $this->apply( $m, $report['result'] );
		if ( null !== $error ) {
			return $error;
		}
		$this->results->close_open( (int) $m['id'], 'superseded' );
		$this->results->set_report_status( $report_id, 'confirmed' );
		return null;
	}

	public function organizer_discard( int $tournament_id, int $report_id ): ?string {
		$report = $this->results->find_report( $report_id );
		$m      = $report ? $this->draws->find_match( (int) $report['match_id'] ) : null;
		$cat    = $m ? $this->tournaments->category( (int) $m['category_id'] ) : null;
		if ( ! $report || ! $cat || (int) $cat['tournament_id'] !== $tournament_id ) {
			return 'not_found';
		}
		$this->results->set_report_status( $report_id, 'discarded' );
		return null;
	}
}
