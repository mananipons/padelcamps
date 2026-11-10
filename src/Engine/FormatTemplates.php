<?php
declare( strict_types=1 );

namespace PadelCamps\Engine;

use InvalidArgumentException;

/**
 * Competition format templates. A format is a sequence of phases, so combined
 * formats (groups -> knockout -> consolation) need no dedicated code per variant.
 * Pure logic, no WordPress dependencies: labels live in the admin layer so they
 * go through the translation system.
 *
 * Phase types: groups, knockout, round_robin, americana.
 */
final class FormatTemplates {

	/**
	 * @return array<string, array{phases:string[], registration_mode:string, available:bool, options:string[]}>
	 */
	public static function all(): array {
		return array(
			'groups_knockout' => array(
				'phases'            => array( 'groups', 'knockout' ),
				'registration_mode' => 'pairs',
				'available'         => true,
				'options'           => array( 'advance', 'consolation', 'criteria' ),
			),
			'knockout'        => array(
				'phases'            => array( 'knockout' ),
				'registration_mode' => 'pairs',
				'available'         => true,
				'options'           => array( 'consolation' ),
			),
			'round_robin'     => array(
				'phases'            => array( 'round_robin' ),
				'registration_mode' => 'pairs',
				'available'         => true,
				'options'           => array( 'criteria' ),
			),
			// Phase 2: individual registration, rotating partners.
			'americana'       => array(
				'phases'            => array( 'americana' ),
				'registration_mode' => 'individual',
				'available'         => false,
				'options'           => array(),
			),
		);
	}

	public static function is_available( string $id ): bool {
		$all = self::all();
		return isset( $all[ $id ] ) && $all[ $id ]['available'];
	}

	/**
	 * Phase rows for a category.
	 *
	 * @param array{advance?:int, consolation?:bool} $opts Only applied when the template declares the option.
	 * @return array<int, array{type:string, sort_order:int, settings:array}>
	 */
	public static function build_phases( string $id, array $opts = array() ): array {
		if ( ! self::is_available( $id ) ) {
			throw new InvalidArgumentException( "Unknown or unavailable format template: $id" );
		}
		$tpl         = self::all()[ $id ];
		$advance     = max( 1, min( 4, (int) ( $opts['advance'] ?? 2 ) ) );
		$consolation = ! empty( $opts['consolation'] );

		$phases = array();
		foreach ( $tpl['phases'] as $i => $type ) {
			$settings = array();
			if ( 'groups' === $type && in_array( 'advance', $tpl['options'], true ) ) {
				$settings['advance'] = $advance;
			}
			if ( 'knockout' === $type && in_array( 'consolation', $tpl['options'], true ) ) {
				$settings['consolation'] = $consolation;
			}
			$phases[] = array(
				'type'       => $type,
				'sort_order' => $i + 1,
				'settings'   => $settings,
			);
		}
		return $phases;
	}
}
