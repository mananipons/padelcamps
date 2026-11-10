<?php
namespace PadelCamps\Service;

use PadelCamps\Admin\DrawTab;

defined( 'ABSPATH' ) || exit;

/**
 * Human label of a match stage: "Grup A · Ronda 2", "Lligueta · Ronda 3",
 * "Semifinals", "Consolació · Final". Match rows need phase_type, round,
 * group_no and category_id (as returned by ScheduleRepository::matches()).
 */
final class MatchLabel {

	/** @var array<string,int> "category:phase_type" => last round */
	private array $rounds = array();

	/** @param array $matches every match of the tournament (to know how many knockout rounds each bracket has). */
	public function __construct( array $matches ) {
		foreach ( $matches as $m ) {
			if ( in_array( $m['phase_type'], array( 'knockout', 'consolation' ), true ) ) {
				$key                  = (int) $m['category_id'] . ':' . $m['phase_type'];
				$this->rounds[ $key ] = max( $this->rounds[ $key ] ?? 0, (int) $m['round'] );
			}
		}
	}

	public function stage( array $m ): string {
		$round = (int) $m['round'];
		if ( 'groups' === $m['phase_type'] ) {
			/* translators: 1: group letter, 2: round number */
			return sprintf( __( 'Grup %1$s · Ronda %2$d', 'padelcamps' ), DrawTab::letter( (int) $m['group_no'] - 1 ), $round );
		}
		if ( 'round_robin' === $m['phase_type'] ) {
			/* translators: %d: round number */
			return sprintf( __( 'Ronda %d', 'padelcamps' ), $round );
		}
		$label = DrawTab::round_label( $round, $this->rounds[ (int) $m['category_id'] . ':' . $m['phase_type'] ] ?? $round );
		return 'consolation' === $m['phase_type'] ? __( 'Consolació', 'padelcamps' ) . ' · ' . $label : $label;
	}
}
