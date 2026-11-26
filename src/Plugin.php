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

		// Updates from GitHub Releases (also runs from wp-cron, so not admin-only).
		( new Updater() )->register();

		// Public registration form (shortcode + submission handler); also needed
		// on admin-post.php, so it is registered in every context.
		( new Frontend\RegistrationShortcode() )->register();
		( new Frontend\DrawShortcode() )->register();
		( new Frontend\ScheduleShortcode() )->register();
		( new Frontend\RankingShortcode() )->register();
		( new Frontend\TvPage() )->register();
		( new Frontend\Blocks() )->register();
		( new Frontend\PairPage() )->register();

		if ( is_admin() ) {
			$tournament_repo = new Db\TournamentRepository();
			$registrations   = new Admin\RegistrationsView( new Db\RegistrationRepository(), $tournament_repo );
			$registrations->register();
			$season_repo = new Db\SeasonRepository();
			$tournaments = new Admin\TournamentsPage( $tournament_repo, $registrations, $season_repo );
			$tournaments->register();
			$seasons = new Admin\SeasonsPage( $season_repo );
			$seasons->register();
			$players = new Admin\PlayersPage( new Db\PlayerRepository(), $season_repo, new Db\RankingRepository() );
			$players->register();
			( new Admin\Menu( $tournaments, $registrations, $seasons, $players ) )->register();
			( new Admin\Translations() )->register();
		}
	}
}
