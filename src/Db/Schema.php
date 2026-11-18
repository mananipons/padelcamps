<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Custom tables (prefix {wpdb->prefix}pc_). Own tables instead of CPT/meta:
 * fast standings/bracket queries and a clean model for a future SaaS split.
 *
 * Model: tournament -> categories -> phases (format as a sequence of phases)
 *        category -> registrations (+ registration_players) -> matches.
 * A registration is one pair (or one individual in americana, phase 2).
 * Only 'confirmed' registrations enter a draw.
 */
final class Schema {

	public const DB_VERSION = '11';
	private const OPTION    = 'padelcamps_db_version';

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'pc_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (string) get_option( self::OPTION, '' );
		$c    = $wpdb->get_charset_collate();
		$t    = static fn( string $n ): string => self::table( $n );

		// NOTE: dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
		$sql = array();

		$sql[] = "CREATE TABLE {$t('tournaments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  circuit_id bigint(20) unsigned DEFAULT NULL,
  season_id bigint(20) unsigned DEFAULT NULL,
  counts_for_ranking tinyint(1) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  starts_on date DEFAULT NULL,
  ends_on date DEFAULT NULL,
  venue varchar(190) DEFAULT NULL,
  description text,
  settings longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY circuit_id (circuit_id),
  KEY season_id (season_id)
) $c;";

		// Seasons group tournaments for the ranking. `points` is the JSON points scheme
		// (see Engine/PointsScheme). Ranking categories are the season's own category
		// names (1a/2a/3a, B+/B/C…) that tournament categories are linked to.
		$sql[] = "CREATE TABLE {$t('seasons')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  starts_on date DEFAULT NULL,
  ends_on date DEFAULT NULL,
  points longtext,
  public_names varchar(10) NOT NULL DEFAULT 'initials',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;";

		// Ranking points per player. Published by the organizer per tournament category
		// (result = a points scheme key, category_id/registration_id set) or added by hand
		// (result = 'adjustment', category_id NULL, `note` says why). Season and ranking
		// category are copied here so the ranking is a plain sum.
		$sql[] = "CREATE TABLE {$t('ranking_points')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  ranking_category_id bigint(20) unsigned NOT NULL,
  tournament_id bigint(20) unsigned DEFAULT NULL,
  category_id bigint(20) unsigned DEFAULT NULL,
  registration_id bigint(20) unsigned DEFAULT NULL,
  player_id bigint(20) unsigned NOT NULL,
  points int(11) NOT NULL DEFAULT 0,
  result varchar(20) NOT NULL,
  note varchar(190) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY season_rc (season_id,ranking_category_id),
  KEY player_id (player_id),
  KEY category_id (category_id),
  KEY tournament_id (tournament_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('ranking_categories')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  season_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY season_id (season_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('categories')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tournament_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  format varchar(30) NOT NULL DEFAULT 'groups_knockout',
  registration_mode varchar(12) NOT NULL DEFAULT 'pairs',
  ranking_category_id bigint(20) unsigned DEFAULT NULL,
  points_published_at datetime DEFAULT NULL,
  max_teams smallint(5) unsigned DEFAULT NULL,
  price decimal(8,2) NOT NULL DEFAULT 0.00,
  seed varchar(64) DEFAULT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY tournament_id (tournament_id),
  KEY ranking_category_id (ranking_category_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('phases')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 1,
  settings longtext,
  PRIMARY KEY  (id),
  KEY category_id (category_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('players')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  email varchar(190) NOT NULL,
  dni varchar(20) DEFAULT NULL,
  phone varchar(40) DEFAULT NULL,
  level decimal(4,2) DEFAULT NULL,
  club varchar(190) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dni (dni),
  KEY email (email)
) $c;";

		$sql[] = "CREATE TABLE {$t('registrations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  payment_status varchar(20) NOT NULL DEFAULT 'pending',
  amount decimal(8,2) NOT NULL DEFAULT 0.00,
  paid_at datetime DEFAULT NULL,
  contact_name varchar(190) DEFAULT NULL,
  contact_email varchar(190) DEFAULT NULL,
  contact_phone varchar(40) DEFAULT NULL,
  rating decimal(5,2) DEFAULT NULL,
  seed_no smallint(5) unsigned DEFAULT NULL,
  group_no smallint(5) unsigned DEFAULT NULL,
  answers longtext,
  notes text,
  token varchar(64) DEFAULT NULL,
  schedule_hash varchar(32) DEFAULT NULL,
  schedule_sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY category_id (category_id),
  KEY status (status),
  KEY payment_status (payment_status),
  UNIQUE KEY token (token)
) $c;";

		// Results reported by players from their magic link (pending until the rival confirms).
		$sql[] = "CREATE TABLE {$t('result_reports')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  match_id bigint(20) unsigned NOT NULL,
  reg_id bigint(20) unsigned NOT NULL,
  result longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  resolved_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY match_id (match_id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('registration_players')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  registration_id bigint(20) unsigned NOT NULL,
  player_id bigint(20) unsigned NOT NULL,
  position tinyint(3) unsigned NOT NULL DEFAULT 1,
  amount decimal(8,2) NOT NULL DEFAULT 0.00,
  payment_status varchar(20) NOT NULL DEFAULT 'pending',
  paid_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY registration_id (registration_id),
  KEY player_id (player_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('courts')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tournament_id bigint(20) unsigned NOT NULL,
  name varchar(80) NOT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY tournament_id (tournament_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('matches')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  phase_id bigint(20) unsigned DEFAULT NULL,
  group_no smallint(5) unsigned DEFAULT NULL,
  round smallint(5) unsigned NOT NULL,
  position smallint(5) unsigned NOT NULL,
  reg_a_id bigint(20) unsigned DEFAULT NULL,
  reg_b_id bigint(20) unsigned DEFAULT NULL,
  winner_reg_id bigint(20) unsigned DEFAULT NULL,
  next_match_id bigint(20) unsigned DEFAULT NULL,
  next_slot char(1) DEFAULT NULL,
  loser_next_match_id bigint(20) unsigned DEFAULT NULL,
  loser_next_slot char(1) DEFAULT NULL,
  score longtext,
  status varchar(20) NOT NULL DEFAULT 'scheduled',
  court_id bigint(20) unsigned DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  token_a varchar(64) DEFAULT NULL,
  token_b varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY category_id (category_id),
  KEY phase_id (phase_id),
  KEY scheduled_at (scheduled_at),
  UNIQUE KEY token_a (token_a),
  UNIQUE KEY token_b (token_b)
) $c;";

		$sql[] = "CREATE TABLE {$t('draw_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  phase_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  seed varchar(64) NOT NULL,
  params longtext,
  result longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY category_id (category_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('reschedule_requests')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  match_id bigint(20) unsigned NOT NULL,
  requested_by_reg_id bigint(20) unsigned NOT NULL,
  proposed_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY match_id (match_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('notifications_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  channel varchar(20) NOT NULL DEFAULT 'email',
  tournament_id bigint(20) unsigned DEFAULT NULL,
  type varchar(40) NOT NULL,
  recipient varchar(190) NOT NULL,
  subject varchar(190) DEFAULT NULL,
  ref_type varchar(20) DEFAULT NULL,
  ref_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY ref (ref_type,ref_id),
  KEY tournament_id (tournament_id)
) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::drop_legacy_tables();

		// v9: the DNI/NIE became the identity of a player. Recover the ones already given.
		if ( '' !== $from && version_compare( $from, '9', '<' ) ) {
			self::backfill_player_dni();
		}

		// v11: each player pays their own fee. Split the pair amounts and payments.
		if ( '' !== $from && version_compare( $from, '11', '<' ) ) {
			self::backfill_player_payments();
		}

		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * Fill `players.dni` from the DNI/NIE each registration stored in its answers
	 * (answers.players[position - 1].dni). Only valid values are taken, only for players
	 * without a DNI, and never one that already belongs to another player: those cases are
	 * duplicates of the same person and are left for the organizer to merge.
	 *
	 * @return int number of players updated
	 */
	/**
	 * v11 migration: the fee became per player. Until v10 a registration's amount
	 * and payment were for the whole pair, and the category price was per pair.
	 * Nothing changes in money terms:
	 *  - each player gets an equal share of the pair amount (cents to player 1)
	 *    and the pair's payment state and date;
	 *  - a category price becomes the price per player (price / players per
	 *    registration: 2 for pairs), so new registrations cost the same as before.
	 * Safe to run again: players that already have an amount are skipped, and the
	 * price change is guarded by the `padelcamps_price_per_player` option.
	 *
	 * @return int registrations migrated
	 */
	public static function backfill_player_payments(): int {
		global $wpdb;
		$r  = self::table( 'registrations' );
		$rp = self::table( 'registration_players' );
		$c  = self::table( 'categories' );

		$regs = $wpdb->get_results(
			"SELECT r.id, r.amount, r.payment_status, r.paid_at FROM {$r} r
			WHERE NOT EXISTS ( SELECT 1 FROM {$rp} x WHERE x.registration_id = r.id AND x.amount > 0 )",
			ARRAY_A
		) ?: array();
		$done = 0;
		foreach ( $regs as $reg ) {
			$players = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$rp} WHERE registration_id = %d ORDER BY position ASC, id ASC", (int) $reg['id'] ) );
			if ( ! $players ) {
				continue;
			}
			$amount = (float) $reg['amount'];
			$shares = \PadelCamps\Engine\RegistrationStatus::split( $amount, count( $players ) );
			$state  = $amount > 0 ? (string) $reg['payment_status'] : 'free';
			if ( ! \PadelCamps\Engine\RegistrationStatus::is_valid_player_payment( $state ) ) {
				$state = 'pending';
			}
			foreach ( $players as $i => $rp_id ) {
				$wpdb->update(
					$rp,
					array(
						'amount'         => $shares[ $i ],
						'payment_status' => $state,
						'paid_at'        => 'paid' === $state ? $reg['paid_at'] : null,
					),
					array( 'id' => (int) $rp_id )
				);
			}
			++$done;
		}

		// Category price per player (pairs: half of the old per-pair price). Once only.
		if ( ! get_option( 'padelcamps_price_per_player' ) ) {
			$wpdb->query( "UPDATE {$c} SET price = ROUND(price / 2, 2) WHERE registration_mode = 'pairs' AND price > 0" );
			update_option( 'padelcamps_price_per_player', current_time( 'mysql' ), false );
		}
		return $done;
	}

	public static function backfill_player_dni(): int {
		global $wpdb;
		$p  = self::table( 'players' );
		$rp = self::table( 'registration_players' );
		$r  = self::table( 'registrations' );

		$taken = array();
		foreach ( $wpdb->get_col( "SELECT dni FROM {$p} WHERE dni IS NOT NULL" ) as $dni ) {
			$taken[ (string) $dni ] = true;
		}
		$rows    = $wpdb->get_results(
			"SELECT rp.player_id, rp.position, r.answers FROM {$rp} rp
			INNER JOIN {$r} r ON r.id = rp.registration_id
			INNER JOIN {$p} pl ON pl.id = rp.player_id AND pl.dni IS NULL
			ORDER BY r.id ASC, rp.position ASC",
			ARRAY_A
		) ?: array();
		$done    = array();
		$updated = 0;
		foreach ( $rows as $row ) {
			$player_id = (int) $row['player_id'];
			if ( isset( $done[ $player_id ] ) ) {
				continue;
			}
			$answers = json_decode( (string) $row['answers'], true );
			$dni     = \PadelCamps\Engine\Dni::clean( $answers['players'][ (int) $row['position'] - 1 ]['dni'] ?? '' );
			if ( null === $dni || isset( $taken[ $dni ] ) ) {
				continue;
			}
			$wpdb->update( $p, array( 'dni' => $dni ), array( 'id' => $player_id ) );
			$taken[ $dni ]     = true;
			$done[ $player_id ] = true;
			++$updated;
		}
		return $updated;
	}

	/**
	 * v1 created `pc_teams`, replaced by `pc_registrations` in v2. dbDelta never
	 * drops tables, so remove it here, but only when it holds no data.
	 */
	private static function drop_legacy_tables(): void {
		global $wpdb;
		$legacy = self::table( 'teams' );
		if ( $legacy !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy ) ) ) ) {
			return;
		}
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy}" ) ) {
			$wpdb->query( "DROP TABLE {$legacy}" );
		}
	}
}
