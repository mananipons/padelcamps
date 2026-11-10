<?php
namespace PadelCamps\Engine;

/**
 * Changes applied to several categories at once (and the phase options of a
 * single category that already has matches). Pure PHP, no WordPress.
 *
 * What can still change once a category has matches:
 *  - consolation and teams per group: only read when the final bracket is
 *    created, so they can change until the knockout phase has matches;
 *  - match format and tie-breaks: locked as soon as there is any match
 *    (results already entered depend on them).
 */
final class CategoryBulk {

	public const PHASE_KEYS = array( 'consolation', 'advance', 'format', 'criteria' );

	/**
	 * Bulk form input → changes. null = keep the current value.
	 *
	 * @return array{consolation:?bool, advance:?int, format:array, criteria:?string, max_teams:?int, price:?float}
	 */
	public static function changes( array $in, array $criteria_presets ): array {
		$consolation = $in['consolation'] ?? '';
		$advance     = (int) ( $in['advance'] ?? 0 );
		$format      = array();
		if ( in_array( (int) ( $in['best_of'] ?? 0 ), array( 1, 3 ), true ) ) {
			$format['best_of'] = (int) $in['best_of'];
		}
		if ( in_array( (int) ( $in['games'] ?? 0 ), array( 4, 6, 9 ), true ) ) {
			$format['games'] = (int) $in['games'];
		}
		if ( in_array( $in['deciding'] ?? '', array( 'super_tiebreak', 'set' ), true ) ) {
			$format['deciding'] = $in['deciding'];
		}
		if ( in_array( (int) ( $in['points'] ?? 0 ), array( 7, 10 ), true ) ) {
			$format['points'] = (int) $in['points'];
		}
		$max   = trim( (string) ( $in['max_teams'] ?? '' ) );
		$price = trim( str_replace( ',', '.', (string) ( $in['price'] ?? '' ) ) );
		return array(
			'consolation' => 'on' === $consolation ? true : ( 'off' === $consolation ? false : null ),
			'advance'     => $advance >= 1 && $advance <= 4 ? $advance : null,
			'format'      => $format,
			'criteria'    => isset( $criteria_presets[ $in['criteria'] ?? '' ] ) ? (string) $in['criteria'] : null,
			'max_teams'   => '' !== $max && ctype_digit( $max ) ? (int) $max : null,
			'price'       => '' !== $price && is_numeric( $price ) ? max( 0.0, min( 99999.0, round( (float) $price, 2 ) ) ) : null,
		);
	}

	public static function has_changes( array $c ): bool {
		return null !== $c['consolation'] || null !== $c['advance'] || array() !== $c['format'] || null !== $c['criteria'] || null !== $c['max_teams'] || null !== $c['price'];
	}

	/**
	 * Apply the phase changes to one category.
	 *
	 * @param string $template category format (FormatTemplates id)
	 * @param array<int, array{id:int, type:string, settings:array}> $phases
	 * @param array{matches:bool, knockout:bool} $locks whether the category has any match / knockout matches
	 * @param array<string, string[]> $criteria_presets preset id => criteria list
	 * @return array{settings: array<int, array>, applied: string[], locked: string[], na: string[]}
	 *         settings: phase id => new settings (only phases that changed)
	 */
	public static function apply( string $template, array $phases, array $changes, array $locks, array $criteria_presets ): array {
		$options = FormatTemplates::all()[ $template ]['options'] ?? array();
		$out     = array( 'settings' => array(), 'applied' => array(), 'locked' => array(), 'na' => array() );
		$wanted  = array(
			'consolation' => null !== $changes['consolation'],
			'advance'     => null !== $changes['advance'],
			'format'      => array() !== $changes['format'],
			'criteria'    => null !== $changes['criteria'],
		);
		$rules   = array(
			// key => [option the template must have (null = all), locked?, phase types it touches]
			'consolation' => array( 'consolation', $locks['knockout'], array( 'knockout' ) ),
			'advance'     => array( 'advance', $locks['knockout'], array( 'groups' ) ),
			'format'      => array( null, $locks['matches'], null ),
			'criteria'    => array( 'criteria', $locks['matches'], array( 'groups', 'round_robin' ) ),
		);

		$settings = array();
		foreach ( $phases as $p ) {
			$settings[ (int) $p['id'] ] = is_array( $p['settings'] ) ? $p['settings'] : array();
		}
		$original = $settings;

		foreach ( $rules as $key => list( $option, $locked, $types ) ) {
			if ( ! $wanted[ $key ] ) {
				continue;
			}
			if ( null !== $option && ! in_array( $option, $options, true ) ) {
				$out['na'][] = $key;
				continue;
			}
			if ( $locked ) {
				$out['locked'][] = $key;
				continue;
			}
			foreach ( $phases as $p ) {
				$id = (int) $p['id'];
				if ( null !== $types && ! in_array( $p['type'], $types, true ) ) {
					continue;
				}
				switch ( $key ) {
					case 'consolation':
						$settings[ $id ]['consolation'] = $changes['consolation'];
						break;
					case 'advance':
						$settings[ $id ]['advance'] = $changes['advance'];
						break;
					case 'format':
						$current                   = is_array( $settings[ $id ]['format'] ?? null ) ? $settings[ $id ]['format'] : array();
						$settings[ $id ]['format'] = MatchFormat::normalize( array_merge( MatchFormat::normalize( $current ), $changes['format'] ) );
						break;
					case 'criteria':
						$settings[ $id ]['criteria'] = $criteria_presets[ $changes['criteria'] ];
						break;
				}
			}
			$out['applied'][] = $key;
		}

		foreach ( $settings as $id => $s ) {
			if ( $s !== $original[ $id ] ) {
				$out['settings'][ $id ] = $s;
			}
		}
		return $out;
	}
}
