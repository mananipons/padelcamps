<?php
namespace PadelCamps\Service;

use PadelCamps\Admin\DrawTab;
use PadelCamps\Admin\TournamentsPage;
use PadelCamps\Db\DrawRepository;
use PadelCamps\Db\RegistrationRepository;
use PadelCamps\Db\TournamentRepository;
use PadelCamps\Engine\BracketView;
use PadelCamps\Engine\PublicName;
use PadelCamps\Engine\TournamentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Public draw data (groups, standings, brackets) for the shortcode and the REST
 * API. Only public information: pair names (with the tournament privacy mode),
 * results. Never emails, phones, notes or payments.
 */
final class PublicDraw {

	private TournamentRepository $tournaments;
	private DrawRepository $draws;
	private RegistrationRepository $regs;
	private DrawService $service;

	public function __construct() {
		$this->tournaments = new TournamentRepository();
		$this->draws       = new DrawRepository();
		$this->regs        = new RegistrationRepository();
		$this->service     = new DrawService( $this->draws );
	}

	/** Public tournament by id, or null (drafts are not public). */
	public function tournament( int $id ): ?array {
		$t = $this->tournaments->find( $id );
		return $t && TournamentStatus::is_public( $t['status'] ) ? $t : null;
	}

	private static function ordinal( int $position ): string {
		$labels = array(
			1 => __( '1r', 'padelcamps' ),
			2 => __( '2n', 'padelcamps' ),
			3 => __( '3r', 'padelcamps' ),
			4 => __( '4t', 'padelcamps' ),
		);
		return $labels[ $position ] ?? (string) $position;
	}

	/**
	 * @return array{tournament:array, categories:array}
	 */
	public function build( array $t, int $only_category = 0 ): array {
		$mode = $this->tournaments->public_names( $t );
		$out  = array(
			'tournament' => array(
				'id'   => (int) $t['id'],
				'name' => $t['name'],
				'slug' => $t['slug'],
			),
			'categories' => array(),
		);
		foreach ( $this->tournaments->categories( (int) $t['id'] ) as $c ) {
			if ( $only_category && (int) $c['id'] !== $only_category ) {
				continue;
			}
			$out['categories'][] = $this->category( $c, $mode );
		}
		return $out;
	}

	/** "dissabte 5 des. · 16:00" in the site language, or null. */
	private static function when( ?string $scheduled_at ): ?string {
		return $scheduled_at ? mysql2date( 'l j M · H:i', $scheduled_at ) : null;
	}

	private function category( array $c, string $mode ): array {
		$cid     = (int) $c['id'];
		$courts  = array_column( ( new \PadelCamps\Db\ScheduleRepository() )->courts( (int) $c['tournament_id'] ), 'name', 'id' );
		$phases  = $this->draws->phases( $cid );
		$matches = $this->draws->matches( $cid );
		$format  = $this->service->format( $phases );
		$groups  = $this->draws->current_groups( $cid );

		$ids = array_merge( $this->draws->current_seeds( $cid ), wp_list_pluck( $matches, 'reg_a_id' ), wp_list_pluck( $matches, 'reg_b_id' ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$names = array();
		foreach ( $this->regs->players_for( $ids ) as $id => $players ) {
			$names[ $id ] = PublicName::pair( wp_list_pluck( $players, 'name' ), $mode );
		}

		$data = array(
			'id'           => $cid,
			'name'         => $c['name'],
			'format'       => $c['format'],
			'format_label' => TournamentsPage::template_label( $c['format'] ),
			'drawn'        => (bool) $matches,
			'groups'       => array(),
			'bracket'      => null,
			'consolation'  => null,
		);

		// Groups / round robin.
		$tables = array();
		if ( $phases['group'] && $matches ) {
			$gid     = (int) $phases['group']['id'];
			$advance = 'groups' === $phases['group']['type'] ? (int) ( $phases['group']['settings']['advance'] ?? 2 ) : 0;
			$tables  = $this->service->standings( $cid, $matches, $format );
			foreach ( $tables as $g => $rows ) {
				$group_matches = array();
				foreach ( $matches as $m ) {
					if ( (int) $m['phase_id'] === $gid && (int) $m['group_no'] - 1 === $g ) {
						$group_matches[] = $this->match( $m, $names, $courts );
					}
				}
				$data['groups'][] = array(
					'key'     => DrawTab::letter( $g ),
					'title'   => 'round_robin' === $phases['group']['type'] ? __( 'Classificació', 'padelcamps' ) : sprintf( /* translators: %s: group letter */ __( 'Grup %s', 'padelcamps' ), DrawTab::letter( $g ) ),
					'rows'    => array_map(
						static fn( $r ) => array(
							'position'  => $r['position'],
							'name'      => $names[ $r['team'] ] ?? '',
							'played'    => $r['played'],
							'won'       => $r['won'],
							'lost'      => $r['lost'],
							'sets'      => array( $r['sets_for'], $r['sets_against'] ),
							'games'     => array( $r['games_for'], $r['games_against'] ),
							'qualifies' => $advance > 0 && $r['position'] <= $advance,
						),
						$rows
					),
					'matches' => $group_matches,
				);
			}
		}

		// Knockout: real bracket, or provisional one while the groups are being played.
		if ( $phases['knockout'] ) {
			$kid = (int) $phases['knockout']['id'];
			$ko  = array_values( array_filter( $matches, static fn( $m ) => (int) $m['phase_id'] === $kid ) );
			if ( $ko ) {
				$to_rows = static fn( array $list ) => array_map(
					static fn( $m ) => array(
						'id'       => (int) $m['id'],
						'round'    => (int) $m['round'],
						'position' => (int) $m['position'],
						'a'        => null === $m['reg_a_id'] ? null : (int) $m['reg_a_id'],
						'b'        => null === $m['reg_b_id'] ? null : (int) $m['reg_b_id'],
						'winner'   => null === $m['winner_reg_id'] ? null : (int) $m['winner_reg_id'],
						'status'   => $m['status'],
						'score'    => $m['score'],
						'when'     => self::when( $m['scheduled_at'] ),
						'court'    => $m['court_id'] ? ( $courts[ (int) $m['court_id'] ] ?? null ) : null,
					),
					$list
				);
				$main            = BracketView::build( $to_rows( $ko ) );
				$data['bracket'] = $this->bracket( $main, static fn( $id ) => $names[ $id ] ?? null, false );

				// Consolation: numbering continues after the main bracket; empty first-round
				// slots say which main match their loser comes from ("Perdedor P3").
				$cons = $phases['consolation'] ? array_values( array_filter( $matches, static fn( $m ) => (int) $m['phase_id'] === (int) $phases['consolation']['id'] ) ) : array();
				if ( $cons ) {
					$number = array();
					foreach ( $main as $round ) {
						foreach ( $round as $m ) {
							$number[ $m['id'] ] = $m['number'];
						}
					}
					$loser_from = array();
					foreach ( $ko as $m ) {
						if ( $m['loser_next_match_id'] ) {
							$loser_from[ (int) $m['loser_next_match_id'] ][ (string) $m['loser_next_slot'] ] = $number[ (int) $m['id'] ] ?? null;
						}
					}
					$rows = array_map(
						static fn( $r ) => $r + array(
							'loser_from_a' => $loser_from[ $r['id'] ]['a'] ?? null,
							'loser_from_b' => $loser_from[ $r['id'] ]['b'] ?? null,
						),
						$to_rows( $cons )
					);
					$data['consolation'] = $this->bracket( BracketView::build( $rows, count( $ko ) ), static fn( $id ) => $names[ $id ] ?? null, false );
				}
			} elseif ( $phases['group'] && 'groups' === $phases['group']['type'] && $groups ) {
				$advance = (int) ( $phases['group']['settings']['advance'] ?? 2 );
				$rows    = BracketView::provisional( array_map( 'count', $groups ), $advance );
				$leaders = array();
				foreach ( $tables as $g => $table ) {
					$any_played = (bool) array_filter( $table, static fn( $r ) => $r['played'] > 0 );
					foreach ( $table as $r ) {
						if ( $any_played ) {
							$leaders[ BracketView::encode( $g, $r['position'] ) ] = $names[ $r['team'] ] ?? null;
						}
					}
				}
				$label = static function ( $id ) {
					list( $g, $p ) = BracketView::decode( (int) $id );
					/* translators: 1: ordinal position (1r, 2n…), 2: group letter */
					return sprintf( __( '%1$s grup %2$s', 'padelcamps' ), self::ordinal( $p ), DrawTab::letter( $g ) );
				};
				$data['bracket'] = $this->bracket( BracketView::build( $rows ), $label, true, $leaders );
			}
		}
		return $data;
	}

	/** Public representation of a match side-by-side score. */
	private function match( array $m, array $names, array $courts ): array {
		$score = $m['score'];
		$sets  = is_array( $score['sets'] ?? null ) ? $score['sets'] : array();
		$a     = null === $m['reg_a_id'] ? null : (int) $m['reg_a_id'];
		$b     = null === $m['reg_b_id'] ? null : (int) $m['reg_b_id'];
		$w     = null === $m['winner_reg_id'] ? null : (int) $m['winner_reg_id'];
		return array(
			'round'  => (int) $m['round'],
			'status' => $m['status'],
			'note'   => self::note( $score ),
			'when'   => self::when( $m['scheduled_at'] ),
			'court'  => $m['court_id'] ? ( $courts[ (int) $m['court_id'] ] ?? null ) : null,
			'a'      => array(
				'name'   => $a ? ( $names[ $a ] ?? '' ) : null,
				'winner' => $a && $w === $a,
				'sets'   => array_map( static fn( $s ) => (int) $s[0], $sets ),
			),
			'b'      => array(
				'name'   => $b ? ( $names[ $b ] ?? '' ) : null,
				'winner' => $b && $w === $b,
				'sets'   => array_map( static fn( $s ) => (int) $s[1], $sets ),
			),
		);
	}

	private static function note( ?array $score ): ?string {
		if ( ! $score ) {
			return null;
		}
		if ( ! empty( $score['walkover'] ) ) {
			return __( 'W.O.', 'padelcamps' );
		}
		return ! empty( $score['retired'] ) ? __( '(ret.)', 'padelcamps' ) : null;
	}

	/**
	 * @param callable $name_of id => ?string (real name, or a placeholder label for provisional brackets)
	 * @param array<int,?string> $leaders provisional only: slot id => pair currently in that position
	 */
	private function bracket( array $rounds, callable $name_of, bool $provisional, array $leaders = array() ): array {
		$total = $rounds ? max( array_keys( $rounds ) ) : 0;
		$out   = array(
			'provisional' => $provisional,
			'rounds'      => array(),
		);
		foreach ( $rounds as $r => $matches ) {
			$list = array();
			foreach ( $matches as $m ) {
				$score = $m['score'];
				$sets  = is_array( $score['sets'] ?? null ) ? $score['sets'] : array();
				$side  = function ( string $slot, int $i ) use ( $m, $sets, $name_of, $provisional, $leaders ) {
					$id    = $m[ $slot ];
					$from  = $m[ 'from_' . $slot ];
					$loser = $m[ 'loser_from_' . $slot ] ?? null;
					if ( null === $id ) {
						if ( $loser ) {
							/* translators: %d: match number */
							$placeholder = sprintf( __( 'Perdedor P%d', 'padelcamps' ), $loser );
						} elseif ( 'bye' === $m['status'] ) {
							$placeholder = '—';
						} else {
							/* translators: %d: match number */
							$placeholder = $from ? sprintf( __( 'Guanyador P%d', 'padelcamps' ), $from ) : __( 'Pendent', 'padelcamps' );
						}
						return array( 'name' => null, 'placeholder' => $placeholder, 'hint' => null, 'winner' => false, 'sets' => array() );
					}
					return array(
						'name'        => $provisional ? null : $name_of( $id ),
						'placeholder' => $provisional ? $name_of( $id ) : null,
						'hint'        => $provisional ? ( $leaders[ $id ] ?? null ) : null,
						'winner'      => ! $provisional && null !== $m['winner'] && (int) $m['winner'] === (int) $id && 'bye' !== $m['status'],
						'sets'        => array_map( static fn( $s ) => (int) $s[ $i ], $sets ),
					);
				};
				$list[] = array(
					'number' => $m['number'],
					'status' => $m['status'],
					'bye'    => 'bye' === $m['status'],
					'note'   => self::note( $score ),
					'when'   => $m['when'] ?? null,
					'court'  => $m['court'] ?? null,
					'a'      => $side( 'a', 0 ),
					'b'      => $side( 'b', 1 ),
				);
			}
			$out['rounds'][] = array(
				'round'   => $r,
				'label'   => DrawTab::round_label( $r, $total ),
				'matches' => $list,
			);
		}
		return $out;
	}
}
