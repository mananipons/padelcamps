<?php
namespace PadelCamps\Admin;

use PadelCamps\Db\TournamentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu. Tournament-centric: everything about one tournament lives in its
 * workspace (Tornejos > tabs). Inscripcions/Jugadors/Ajustaments are global
 * views (placeholders for now). Traduccions is added by Translations at priority 20.
 */
final class Menu {

	public const SLUG = 'padelcamps';

	private TournamentsPage $tournaments;
	private RegistrationsView $registrations;
	private SeasonsPage $seasons;
	private PlayersPage $players;

	public function __construct( TournamentsPage $tournaments, RegistrationsView $registrations, SeasonsPage $seasons, PlayersPage $players ) {
		$this->tournaments   = $tournaments;
		$this->registrations = $registrations;
		$this->seasons       = $seasons;
		$this->players       = $players;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'wp_ajax_padelcamps_dismiss_brand', array( Dashboard::class, 'ajax_dismiss_brand' ) );
	}

	/** True on any PadelCamps admin screen (dashboard, tournaments, registrations…). */
	private static function is_plugin_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		return 0 === strpos( $page, 'padelcamps' );
	}

	public function enqueue_assets(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style( 'padelcamps-admin', PADELCAMPS_URL . 'assets/css/admin.css', array(), PADELCAMPS_VERSION . '.' . filemtime( PADELCAMPS_PATH . 'assets/css/admin.css' ) );
	}

	public function body_class( string $classes ): string {
		return self::is_plugin_screen() ? $classes . ' padelcamps-admin' : $classes;
	}

	public function add_menu(): void {
		$cap = 'manage_options';

		add_menu_page(
			__( 'PadelCamps', 'padelcamps' ),
			__( 'PadelCamps', 'padelcamps' ),
			$cap,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-awards',
			58
		);
		// Rename the auto-created first submenu entry.
		add_submenu_page( self::SLUG, __( 'Tauler', 'padelcamps' ), __( 'Tauler', 'padelcamps' ), $cap, self::SLUG, array( $this, 'render_dashboard' ) );

		add_submenu_page( self::SLUG, __( 'Tornejos', 'padelcamps' ), __( 'Tornejos', 'padelcamps' ), $cap, TournamentsPage::SLUG, array( $this->tournaments, 'render' ) );

		add_submenu_page( self::SLUG, __( 'Temporades', 'padelcamps' ), __( 'Temporades', 'padelcamps' ), $cap, SeasonsPage::SLUG, array( $this->seasons, 'render' ) );

		add_submenu_page( self::SLUG, __( 'Inscripcions', 'padelcamps' ), __( 'Inscripcions', 'padelcamps' ), $cap, RegistrationsView::GLOBAL_SLUG, array( $this->registrations, 'render_global' ) );

		add_submenu_page( self::SLUG, __( 'Jugadors', 'padelcamps' ), __( 'Jugadors', 'padelcamps' ), $cap, PlayersPage::SLUG, array( $this->players, 'render' ) );

		$placeholders = array(
			'padelcamps-clubs'    => __( 'Clubs', 'padelcamps' ),
			'padelcamps-settings' => __( 'Ajustaments', 'padelcamps' ),
		);
		foreach ( $placeholders as $slug => $title ) {
			add_submenu_page(
				self::SLUG,
				$title,
				$title,
				$cap,
				$slug,
				function () use ( $title ) {
					$this->render_coming_soon( $title );
				}
			);
		}
	}

	public function render_dashboard(): void {
		( new Dashboard( new TournamentRepository() ) )->render();
	}

	private function render_coming_soon( string $title ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap pc-wrap"><h1>' . esc_html( $title ) . '</h1><div class="pc-panel pc-panel--solo">';
		echo '<p>' . esc_html__( 'Aquesta secció arribarà aviat.', 'padelcamps' ) . '</p></div></div>';
	}
}
