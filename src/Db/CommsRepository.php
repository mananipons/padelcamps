<?php
namespace PadelCamps\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Data for the communication block: who to write to, what was last sent
 * (schedule fingerprint per pair) and the send log of a tournament.
 */
final class CommsRepository {

	private static function t( string $name ): string {
		return Schema::table( $name );
	}

	/** @param int[] $ids @return array<int, array{status:string, payment_status:string}> */
	public function states( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT id, status, payment_status FROM ' . self::t( 'registrations' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) ?: array();
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = array( 'status' => $row['status'], 'payment_status' => $row['payment_status'] );
		}
		return $out;
	}

	/** Registration ids of a tournament with one of the statuses (and category, if given). @return int[] */
	public function registration_ids( int $tournament_id, array $statuses, int $category_id = 0 ): array {
		global $wpdb;
		$statuses = array_values( array_intersect( $statuses, array( 'pending', 'confirmed', 'waitlist', 'cancelled' ) ) );
		if ( ! $statuses ) {
			return array();
		}
		$in  = implode( ',', array_map( static fn( $s ) => "'" . $s . "'", $statuses ) );
		$sql = $wpdb->prepare(
			'SELECT r.id FROM ' . self::t( 'registrations' ) . ' r INNER JOIN ' . self::t( 'categories' ) . " c ON c.id = r.category_id
			WHERE c.tournament_id = %d AND r.status IN ({$in})",
			$tournament_id
		);
		if ( $category_id ) {
			$sql .= $wpdb->prepare( ' AND r.category_id = %d', $category_id );
		}
		return array_map( 'intval', $wpdb->get_col( $sql . ' ORDER BY c.sort_order, c.id, r.id' ) );
	}

	/** Last schedule sent to each confirmed pair: reg id => {hash, sent_at}. */
	public function schedule_sent( int $tournament_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.schedule_hash, r.schedule_sent_at FROM ' . self::t( 'registrations' ) . ' r INNER JOIN ' . self::t( 'categories' ) . " c ON c.id = r.category_id
				WHERE c.tournament_id = %d AND r.status = 'confirmed'",
				$tournament_id
			),
			ARRAY_A
		) ?: array();
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = array( 'hash' => (string) $row['schedule_hash'], 'sent_at' => $row['schedule_sent_at'] );
		}
		return $out;
	}

	public function mark_schedule_sent( int $reg_id, string $hash ): void {
		global $wpdb;
		$wpdb->update(
			self::t( 'registrations' ),
			array( 'schedule_hash' => $hash, 'schedule_sent_at' => current_time( 'mysql' ) ),
			array( 'id' => $reg_id )
		);
	}

	/** Send log of a tournament, newest first (older rows without tournament_id are found through their registration). */
	public function log( int $tournament_id, int $limit = 100 ): array {
		global $wpdb;
		$l = self::t( 'notifications_log' );
		$r = self::t( 'registrations' );
		$c = self::t( 'categories' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$l} l
				WHERE l.tournament_id = %d
				   OR ( l.tournament_id IS NULL AND l.ref_type = 'registration' AND l.ref_id IN (
				        SELECT r.id FROM {$r} r INNER JOIN {$c} c ON c.id = r.category_id WHERE c.tournament_id = %d ) )
				ORDER BY l.id DESC LIMIT %d",
				$tournament_id,
				$tournament_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
