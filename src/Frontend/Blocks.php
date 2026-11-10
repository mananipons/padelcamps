<?php
namespace PadelCamps\Frontend;

use PadelCamps\Db\TournamentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg blocks: thin, server-rendered wrappers of the shortcodes
 * (padelcamps/draw, padelcamps/schedule, padelcamps/registration).
 * Titles and editor labels go through the plugin translation layer.
 */
final class Blocks {

	public function register(): void {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	public function init(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'padelcamps-blocks',
			PADELCAMPS_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			PADELCAMPS_VERSION,
			true
		);
		wp_register_style( 'padelcamps-draw-editor', PADELCAMPS_URL . 'assets/css/draw.css', array(), PADELCAMPS_VERSION );
		wp_register_style( 'padelcamps-form-editor', PADELCAMPS_URL . 'assets/css/form.css', array(), PADELCAMPS_VERSION );

		$id       = array( 'type' => 'number', 'default' => 0 );
		$category = array( 'type' => 'number', 'default' => 0 );
		$flag     = array( 'type' => 'boolean', 'default' => true );

		register_block_type(
			'padelcamps/draw',
			array(
				'title'           => __( 'PadelCamps: quadres i classificacions', 'padelcamps' ),
				'description'     => __( 'Grups, classificacions i quadres d’un torneig, actualitzats en directe.', 'padelcamps' ),
				'category'        => 'widgets',
				'icon'            => 'awards',
				'editor_script'   => 'padelcamps-blocks',
				'editor_style'    => 'padelcamps-draw-editor',
				'attributes'      => array( 'id' => $id, 'category' => $category, 'live' => $flag, 'find' => $flag ),
				'render_callback' => static fn( $a ) => ( new DrawShortcode() )->render( self::atts( $a ) ),
			)
		);
		register_block_type(
			'padelcamps/schedule',
			array(
				'title'           => __( 'PadelCamps: ordre de joc', 'padelcamps' ),
				'description'     => __( 'Partits per dia, hora i pista, amb els resultats.', 'padelcamps' ),
				'category'        => 'widgets',
				'icon'            => 'calendar-alt',
				'editor_script'   => 'padelcamps-blocks',
				'editor_style'    => 'padelcamps-draw-editor',
				'attributes'      => array( 'id' => $id, 'category' => $category, 'live' => $flag, 'find' => $flag ),
				'render_callback' => static fn( $a ) => ( new ScheduleShortcode() )->render( self::atts( $a ) ),
			)
		);
		register_block_type(
			'padelcamps/registration',
			array(
				'title'           => __( 'PadelCamps: formulari d’inscripció', 'padelcamps' ),
				'description'     => __( 'Formulari públic d’inscripció d’un torneig.', 'padelcamps' ),
				'category'        => 'widgets',
				'icon'            => 'clipboard',
				'editor_script'   => 'padelcamps-blocks',
				'editor_style'    => 'padelcamps-form-editor',
				'attributes'      => array( 'id' => $id ),
				'render_callback' => static fn( $a ) => ( new RegistrationShortcode() )->render( array( 'id' => (int) ( $a['id'] ?? 0 ) ) ),
			)
		);
	}

	/** Block attributes → shortcode attributes. */
	private static function atts( array $a ): array {
		return array(
			'id'       => (int) ( $a['id'] ?? 0 ),
			'category' => (int) ( $a['category'] ?? 0 ),
			'live'     => ! isset( $a['live'] ) || $a['live'] ? '1' : '0',
			'find'     => ! isset( $a['find'] ) || $a['find'] ? '1' : '0',
		);
	}

	/** Tournaments, categories and labels for the block inspector. */
	public function editor_data(): void {
		$repo        = new TournamentRepository();
		$tournaments = array();
		foreach ( $repo->all() as $t ) {
			$tournaments[] = array(
				'id'         => (int) $t['id'],
				'name'       => $t['name'],
				'categories' => array_map(
					static fn( $c ) => array(
						'id'   => (int) $c['id'],
						'name' => $c['name'],
					),
					$repo->categories( (int) $t['id'] )
				),
			);
		}
		wp_localize_script(
			'padelcamps-blocks',
			'padelcampsBlocks',
			array(
				'tournaments' => $tournaments,
				'labels'      => array(
					'settings'   => __( 'Configuració', 'padelcamps' ),
					'tournament' => __( 'Torneig', 'padelcamps' ),
					'choose'     => __( 'Tria un torneig a la barra lateral.', 'padelcamps' ),
					'category'   => __( 'Categoria', 'padelcamps' ),
					'all'        => __( 'Totes les categories', 'padelcamps' ),
					'live'       => __( 'Actualització en directe', 'padelcamps' ),
					'find'       => __( 'Cercador «Busca la teva parella»', 'padelcamps' ),
				),
			)
		);
	}
}
