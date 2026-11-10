<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Counts for the dashboard: one tournament in detail, and totals for all of them.
 * Cancelled registrations never count as participants.
 */
final class StatsRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/** @return array<string, mixed> */
	public function tournament( int $tid, string $today, string $now ): array {
		global $wpdb;
		$c  = self::t( 'categories' );
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );
		$m  = self::t( 'matches' );

		$regs = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(r.status = 'confirmed') AS confirmed,
					SUM(r.status = 'pending') AS pending,
					SUM(r.status = 'waitlist') AS waitlist,
					SUM(r.status = 'cancelled') AS cancelled,
					COALESCE(SUM(CASE WHEN r.payment_status = 'paid' AND r.status <> 'cancelled' THEN r.amount END), 0) AS paid,
					COALESCE(SUM(CASE WHEN r.payment_status = 'pending' AND r.status IN ('pending','confirmed') THEN r.amount END), 0) AS unpaid,
					SUM(r.payment_status = 'pending' AND r.status = 'confirmed' AND r.amount > 0) AS unpaid_confirmed
				FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d",
				$tid
			),
			ARRAY_A
		) ?: array();

		$players = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT rp.player_id) FROM {$rp} rp INNER JOIN {$r} r ON r.id = rp.registration_id INNER JOIN {$c} c ON c.id = r.category_id
				WHERE c.tournament_id = %d AND r.status <> 'cancelled'",
				$tid
			)
		);

		$matches = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(m.status = 'played') AS played,
					SUM(m.status <> 'played' AND m.scheduled_at IS NULL) AS unscheduled,
					SUM(DATE(m.scheduled_at) = %s) AS today,
					SUM(DATE(m.scheduled_at) = %s AND m.status = 'played') AS today_played
				FROM {$m} m INNER JOIN {$c} c ON c.id = m.category_id WHERE c.tournament_id = %d AND m.status <> 'bye'",
				$today,
				$today,
				$tid
			),
			ARRAY_A
		) ?: array();

		$next = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT m.scheduled_at, m.court_id, c.name AS category_name, co.name AS court_name
				FROM ' . $m . ' m INNER JOIN ' . $c . ' c ON c.id = m.category_id LEFT JOIN ' . self::t( 'courts' ) . " co ON co.id = m.court_id
				WHERE c.tournament_id = %d AND m.status NOT IN ('played','bye') AND m.scheduled_at >= %s
				ORDER BY m.scheduled_at, co.sort_order LIMIT 1",
				$tid,
				$now
			),
			ARRAY_A
		);

		$reports = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(rr.status = \'pending\') AS pending, SUM(rr.status = \'disputed\') AS disputed FROM ' . self::t( 'result_reports' ) . " rr
				INNER JOIN {$m} m ON m.id = rr.match_id INNER JOIN {$c} c ON c.id = m.category_id
				WHERE c.tournament_id = %d AND m.status <> 'played' AND rr.status IN ('pending','disputed')",
				$tid
			),
			ARRAY_A
		) ?: array();

		$emails = $wpdb->get_row(
			$wpdb->prepare( 'SELECT SUM(status = \'sent\') AS sent, SUM(status <> \'sent\') AS failed FROM ' . self::t( 'notifications_log' ) . ' WHERE tournament_id = %d', $tid ),
			ARRAY_A
		) ?: array();

		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.name, c.format, c.max_teams,
					(SELECT COUNT(*) FROM {$r} r WHERE r.category_id = c.id AND r.status = 'confirmed') AS confirmed,
					(SELECT COUNT(*) FROM {$r} r WHERE r.category_id = c.id AND r.status = 'pending') AS pending,
					(SELECT COUNT(*) FROM {$r} r WHERE r.category_id = c.id AND r.status = 'waitlist') AS waitlist,
					(SELECT COUNT(*) FROM {$m} m WHERE m.category_id = c.id AND m.status <> 'bye') AS matches,
					(SELECT COUNT(*) FROM {$m} m WHERE m.category_id = c.id AND m.status = 'played') AS played
				FROM {$c} c WHERE c.tournament_id = %d ORDER BY c.sort_order, c.id",
				$tid
			),
			ARRAY_A
		) ?: array();

		$capacity  = 0;
		$unlimited = false;
		foreach ( $categories as $cat ) {
			if ( null === $cat['max_teams'] ) {
				$unlimited = true;
			} else {
				$capacity += (int) $cat['max_teams'];
			}
		}

		$i = static fn( array $row, string $k ): int => (int) ( $row[ $k ] ?? 0 );
		return array(
			'confirmed'        => $i( $regs, 'confirmed' ),
			'pending'          => $i( $regs, 'pending' ),
			'waitlist'         => $i( $regs, 'waitlist' ),
			'cancelled'        => $i( $regs, 'cancelled' ),
			'paid'             => (float) ( $regs['paid'] ?? 0 ),
			'unpaid'           => (float) ( $regs['unpaid'] ?? 0 ),
			'unpaid_confirmed' => $i( $regs, 'unpaid_confirmed' ),
			'players'          => $players,
			'capacity'         => $capacity,
			'unlimited'        => $unlimited,
			'matches'          => $i( $matches, 'total' ),
			'played'           => $i( $matches, 'played' ),
			'unscheduled'      => $i( $matches, 'unscheduled' ),
			'today'            => $i( $matches, 'today' ),
			'today_played'     => $i( $matches, 'today_played' ),
			'next'             => $next ?: null,
			'reports_pending'  => $i( $reports, 'pending' ),
			'reports_disputed' => $i( $reports, 'disputed' ),
			'emails_sent'      => $i( $emails, 'sent' ),
			'emails_failed'    => $i( $emails, 'failed' ),
			'categories'       => $categories,
		);
	}

	/** Totals over every tournament. */
	public function global(): array {
		global $wpdb;
		$t  = self::t( 'tournaments' );
		$c  = self::t( 'categories' );
		$r  = self::t( 'registrations' );
		$rp = self::t( 'registration_players' );
		$m  = self::t( 'matches' );
		return array(
			'tournaments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
			'live'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('registration_open','registration_closed','drawn','in_progress')" ),
			'registrations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$r} WHERE status <> 'cancelled'" ),
			'players'       => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT rp.player_id) FROM {$rp} rp INNER JOIN {$r} r ON r.id = rp.registration_id WHERE r.status <> 'cancelled'" ),
			'played'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'played'" ),
			'paid'          => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$r} WHERE payment_status = 'paid' AND status <> 'cancelled'" ),
			'clubs'         => (int) $wpdb->get_var(
				'SELECT COUNT(DISTINCT LOWER(TRIM(p.club))) FROM ' . self::t( 'players' ) . " p INNER JOIN {$rp} rp ON rp.player_id = p.id INNER JOIN {$r} r ON r.id = rp.registration_id
				WHERE r.status <> 'cancelled' AND p.club IS NOT NULL AND TRIM(p.club) <> ''"
			),
			'categories'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c}" ),
		);
	}
}
