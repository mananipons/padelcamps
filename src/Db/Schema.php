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

	public const DB_VERSION = '6';
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

		$c = $wpdb->get_charset_collate();
		$t = static fn( string $n ): string => self::table( $n );

		// NOTE: dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
		$sql = array();

		$sql[] = "CREATE TABLE {$t('tournaments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  circuit_id bigint(20) unsigned DEFAULT NULL,
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
  KEY circuit_id (circuit_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('categories')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tournament_id bigint(20) unsigned NOT NULL,
  name varchar(120) NOT NULL,
  format varchar(30) NOT NULL DEFAULT 'groups_knockout',
  registration_mode varchar(12) NOT NULL DEFAULT 'pairs',
  max_teams smallint(5) unsigned DEFAULT NULL,
  price decimal(8,2) NOT NULL DEFAULT 0.00,
  seed varchar(64) DEFAULT NULL,
  sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY tournament_id (tournament_id)
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
  phone varchar(40) DEFAULT NULL,
  level decimal(4,2) DEFAULT NULL,
  club varchar(190) DEFAULT NULL,
  PRIMARY KEY  (id),
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

		update_option( self::OPTION, self::DB_VERSION );
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
