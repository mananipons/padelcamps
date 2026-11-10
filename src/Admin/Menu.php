<?php
namespace PadelCamps\Admin;

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

	public function __construct( TournamentsPage $tournaments, RegistrationsView $registrations ) {
		$this->tournaments   = $tournaments;
		$this->registrations = $registrations;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
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

		add_submenu_page( self::SLUG, __( 'Inscripcions', 'padelcamps' ), __( 'Inscripcions', 'padelcamps' ), $cap, RegistrationsView::GLOBAL_SLUG, array( $this->registrations, 'render_global' ) );

		$placeholders = array(
			'padelcamps-players'  => __( 'Jugadors', 'padelcamps' ),
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'PadelCamps', 'padelcamps' ) . '</h1>';
		echo '<p>' . esc_html__( 'La gestió de tornejos arribarà aviat.', 'padelcamps' ) . '</p></div>';
	}

	private function render_coming_soon( string $title ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html__( 'Aquesta secció arribarà aviat.', 'padelcamps' ) . '</p></div>';
	}
}
