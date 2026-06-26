<?php
namespace TBSHComplianceAuditLogger\Dashboard;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gathers aggregated database stats.
 */
class StatsManager {

	/**
	 * Compute all dashboard stats.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats() {
		global $wpdb;
		$table_logs     = $wpdb->prefix . 'tbsh_cal_logs';
		$table_evidence = $wpdb->prefix . 'tbsh_cal_evidence';

		$today = gmdate( 'Y-m-d' );

		// 1. Counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_events = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_logs ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events_today = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE created_at >= %s",
			$table_logs,
			$today . ' 00:00:00'
		) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$critical_events = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE severity IN ('critical', 'error')",
			$table_logs
		) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$failed_logins = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE event_type = 'failed_login'",
			$table_logs
		) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$evidence_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_evidence ) ) );

		// 2. Integrity.
		$integrity = ChainVerifier::get_latest_status();

		// 3. Most Active Users (Limit 5).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$active_users = $wpdb->get_results( $wpdb->prepare(
			"SELECT username, user_id, COUNT(*) as count 
			 FROM %i 
			 WHERE username != 'system' 
			 GROUP BY username, user_id 
			 ORDER BY count DESC 
			 LIMIT 5",
			$table_logs
		) );

		// 4. Most Common Events (Limit 5).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$common_events = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_title as name, COUNT(*) as count 
			 FROM %i 
			 GROUP BY event_title 
			 ORDER BY count DESC 
			 LIMIT 5",
			$table_logs
		) );

		// 5. Recent Activity (Limit 10).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_activity = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, created_at, username, event_title, severity, event_category, compliance_tags 
			 FROM %i 
			 ORDER BY id DESC 
			 LIMIT 10",
			$table_logs
		) );

		// 6. DB Size.
		$db_size = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status  = $wpdb->get_results( "SHOW TABLE STATUS LIKE '{$wpdb->prefix}tbsh_cal_%'" );
		if ( ! empty( $status ) ) {
			foreach ( $status as $table ) {
				$db_size += intval( $table->Data_length ) + intval( $table->Index_length );
			}
		}

		return array(
			'total_events'       => $total_events,
			'events_today'       => $events_today,
			'critical_events'    => $critical_events,
			'failed_logins'      => $failed_logins,
			'integrity_status'   => $integrity,
			'evidence_count'     => $evidence_count,
			'most_active_users'  => $active_users,
			'most_common_events' => $common_events,
			'recent_activity'    => $recent_activity,
			'db_size'            => $db_size,
		);
	}
}
