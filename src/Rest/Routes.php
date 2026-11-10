<?php
namespace PadelCamps\Rest;

use PadelCamps\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * REST API (namespace padelcamps/v1). It is the boundary between the engine and
 * every UI (admin, public blocks, TV screen, mobile magic links, future SaaS).
 */
final class Routes {

	public const NS = 'padelcamps/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/tournaments',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_tournaments' ),
				'permission_callback' => '__return_true', // Public: published tournaments only.
			)
		);
		register_rest_route(
			self::NS,
			'/tournaments/(?P<id>\d+)/draw',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_draw' ),
				'permission_callback' => '__return_true', // Public data only; drafts return 404.
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'category' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		$args = array(
			'id'       => array(
				'type'     => 'integer',
				'required' => true,
			),
			'category' => array(
				'type'    => 'integer',
				'default' => 0,
			),
		);
		register_rest_route(
			self::NS,
			'/tournaments/(?P<id>\d+)/schedule',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schedule' ),
				'permission_callback' => '__return_true', // Public data only; drafts return 404.
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NS,
			'/tournaments/(?P<id>\d+)/view',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_view' ),
				'permission_callback' => '__return_true', // Rendered public views for live refresh.
				'args'                => $args + array(
					'type' => array(
						'type'    => 'string',
						'enum'    => \PadelCamps\Frontend\LiveView::TYPES,
						'default' => 'draw',
					),
				),
			)
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'padelcamps_not_found', __( 'Aquest torneig no està disponible.', 'padelcamps' ), array( 'status' => 404 ) );
	}

	/** Public order of play (no personal data). */
	public function get_schedule( \WP_REST_Request $request ) {
		$t = ( new \PadelCamps\Service\PublicDraw() )->tournament( (int) $request['id'] );
		if ( ! $t ) {
			return self::not_found();
		}
		$response = new \WP_REST_Response( ( new \PadelCamps\Service\PublicSchedule() )->build( $t, (int) $request->get_param( 'category' ) ) );
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}

	/** Server-rendered HTML of a public view + its hash (the page only swaps it when the hash changes). */
	public function get_view( \WP_REST_Request $request ) {
		$t = ( new \PadelCamps\Service\PublicDraw() )->tournament( (int) $request['id'] );
		if ( ! $t ) {
			return self::not_found();
		}
		$html     = \PadelCamps\Frontend\LiveView::render( (string) $request->get_param( 'type' ), $t, (int) $request->get_param( 'category' ) );
		$response = new \WP_REST_Response(
			array(
				'hash' => \PadelCamps\Frontend\LiveView::hash( $html ),
				'html' => $html,
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=15' );
		return $response;
	}

	/** Public groups/standings/brackets of a tournament (no personal data). */
	public function get_draw( \WP_REST_Request $request ) {
		$public = new \PadelCamps\Service\PublicDraw();
		$t      = $public->tournament( (int) $request['id'] );
		if ( ! $t ) {
			return new \WP_Error( 'padelcamps_not_found', __( 'Aquest torneig no està disponible.', 'padelcamps' ), array( 'status' => 404 ) );
		}
		$response = new \WP_REST_Response( $public->build( $t, (int) $request->get_param( 'category' ) ) );
		$response->header( 'Cache-Control', 'public, max-age=30' );
		return $response;
	}

	public function list_tournaments(): \WP_REST_Response {
		global $wpdb;
		$table = Schema::table( 'tournaments' );
		$rows  = $wpdb->get_results(
			"SELECT id, name, slug, status, starts_on, ends_on, venue FROM {$table} WHERE status <> 'draft' ORDER BY starts_on DESC",
			ARRAY_A
		);
		return new \WP_REST_Response( $rows ?: array() );
	}
}
