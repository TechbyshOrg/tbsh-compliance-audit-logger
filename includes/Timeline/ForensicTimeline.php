<?php
namespace TBSHComplianceAuditLogger\Timeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles investigations timeline data queries.
 */
class ForensicTimeline {

	/**
	 * Get chronological event list for forensics.
	 */
	public static function get_timeline( $filters = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['date_start'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_start'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_end'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_end'] . ' 23:59:59';
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[] = 'event_category = %s';
			$args[]  = $filters['category'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = intval( $filters['user_id'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[] = '(event_title LIKE %s OR event_message LIKE %s OR username LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_clause = implode( ' AND ', $where );

		// Forensics is usually ordered ASC or DESC depending on investigatory timeline flows.
		// Default to DESC to show recent first, but easy paging.
		$limit  = 100;
		$sql    = "SELECT id, created_at, event_uuid, event_type, event_category, severity, event_title, event_message, user_id, username, role, ip_hash, request_method, request_uri 
		           FROM $table_name 
		           WHERE $where_clause 
		           ORDER BY id DESC 
		           LIMIT %d";

		$query_args = array_merge( $args, array( $limit ) );
		$sql = $wpdb->prepare( $sql, $query_args );

		return $wpdb->get_results( $sql );
	}
}
