<?php
namespace PadelCamps;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin orchestrator. Wires WordPress hooks to the feature classes.
 * Business logic lives in src/Engine (no WordPress dependencies).
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		// Own translation layer first: every later __() call goes through it.
		( new I18n\Translator() )->register();

		Db\Schema::maybe_upgrade();

		( new Rest\Routes() )->register();

		// Public registration form (shortcode + submission handler); also needed
		// on admin-post.php, so it is registered in every context.
		( new Frontend\RegistrationShortcode() )->register();
		( new Frontend\DrawShortcode() )->register();
		( new Frontend\ScheduleShortcode() )->register();
		( new Frontend\TvPage() )->register();
		( new Frontend\Blocks() )->register();
		( new Frontend\PairPage() )->register();

		if ( is_admin() ) {
			$tournament_repo = new Db\TournamentRepository();
			$registrations   = new Admin\RegistrationsView( new Db\RegistrationRepository(), $tournament_repo );
			$registrations->register();
			$tournaments = new Admin\TournamentsPage( $tournament_repo, $registrations );
			$tournaments->register();
			( new Admin\Menu( $tournaments, $registrations ) )->register();
			( new Admin\Translations() )->register();
		}
	}
}
