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

		$today = date( 'Y-m-d' );

		// 1. Counts.
		$total_events = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs" ) );
		$events_today = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_logs WHERE created_at >= %s",
			$today . ' 00:00:00'
		) ) );

		$critical_events = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_logs WHERE severity IN ('critical', 'error')"
		) );

		$failed_logins = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_logs WHERE event_type = 'failed_login'"
		) );

		$evidence_count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_evidence" ) );

		// 2. Integrity.
		$integrity = ChainVerifier::get_latest_status();

		// 3. Most Active Users (Limit 5).
		$active_users = $wpdb->get_results(
			"SELECT username, user_id, COUNT(*) as count 
			 FROM $table_logs 
			 WHERE username != 'system' 
			 GROUP BY username, user_id 
			 ORDER BY count DESC 
			 LIMIT 5"
		);

		// 4. Most Common Events (Limit 5).
		$common_events = $wpdb->get_results(
			"SELECT event_title as name, COUNT(*) as count 
			 FROM $table_logs 
			 GROUP BY event_title 
			 ORDER BY count DESC 
			 LIMIT 5"
		);

		// 5. Recent Activity (Limit 10).
		$recent_activity = $wpdb->get_results(
			"SELECT id, created_at, username, event_title, severity, event_category, compliance_tags 
			 FROM $table_logs 
			 ORDER BY id DESC 
			 LIMIT 10"
		);

		// 6. DB Size.
		$db_size = 0;
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
