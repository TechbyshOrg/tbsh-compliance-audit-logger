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
	 * Transient holding the cached summary.
	 */
	const CACHE_KEY = 'tbsh_cal_dashboard_summary';

	/**
	 * Days covered by the "most active" lists.
	 */
	const TOP_DAYS = 30;

	/**
	 * Compute all dashboard stats.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats() {
		global $wpdb;
		$table_logs     = $wpdb->prefix . 'tbsh_cal_logs';
		$table_evidence = $wpdb->prefix . 'tbsh_cal_evidence';

		$today_start = \TBSHComplianceAuditLogger\Helpers\Time::today_start();

		// 1. Counts that read the whole log are cached briefly: on large logs each one
		// takes seconds, and the dashboard is the plugin's landing page.
		$summary = self::summary( $table_logs, $table_evidence );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events_today = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE created_at >= %s",
			$table_logs,
			$today_start
		) ) );

		// 2. Integrity.
		$integrity = ChainVerifier::get_latest_status();

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
		$status  = $wpdb->get_results( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $wpdb->esc_like( $wpdb->prefix . 'tbsh_cal_' ) . '%' ) );
		if ( ! empty( $status ) ) {
			foreach ( $status as $table ) {
				$db_size += intval( $table->Data_length ) + intval( $table->Index_length );
			}
		}

		return array(
			'total_events'       => $summary['total_events'],
			'events_today'       => $events_today,
			'critical_events'    => $summary['critical_events'],
			'failed_logins'      => $summary['failed_logins'],
			'integrity_status'   => $integrity,
			'evidence_count'     => $summary['evidence_count'],
			'most_active_users'  => $summary['most_active_users'],
			'most_common_events' => $summary['most_common_events'],
			'top_since'          => $summary['top_since'],
			'recent_activity'    => $recent_activity,
			'db_size'            => $db_size,
		);
	}

	/**
	 * All-time totals and the most active users and actions of the last 30 days.
	 *
	 * Cached for five minutes per site.
	 *
	 * @param string $table_logs     Log table.
	 * @param string $table_evidence Evidence table.
	 * @return array
	 */
	private static function summary( $table_logs, $table_evidence ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['top_since'] ) ) {
			return $cached;
		}

		global $wpdb;
		$since = \TBSHComplianceAuditLogger\Helpers\Time::ago( self::TOP_DAYS * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$summary = array(
			'total_events'       => intval( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_logs ) ) ),
			'critical_events'    => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE severity IN ('critical', 'error')", $table_logs ) ) ),
			'failed_logins'      => intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event_type = 'failed_login'", $table_logs ) ) ),
			'evidence_count'     => intval( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_evidence ) ) ),
			'most_active_users'  => $wpdb->get_results( $wpdb->prepare(
				"SELECT username, user_id, COUNT(*) as count
				 FROM %i
				 WHERE created_at >= %s AND username != 'system'
				 GROUP BY username, user_id
				 ORDER BY count DESC
				 LIMIT 5",
				$table_logs,
				$since
			) ),
			'most_common_events' => $wpdb->get_results( $wpdb->prepare(
				'SELECT event_title as name, COUNT(*) as count
				 FROM %i
				 WHERE created_at >= %s
				 GROUP BY event_title
				 ORDER BY count DESC
				 LIMIT 5',
				$table_logs,
				$since
			) ),
			'top_since'          => substr( $since, 0, 10 ),
		);
		// phpcs:enable

		set_transient( self::CACHE_KEY, $summary, 5 * MINUTE_IN_SECONDS );
		return $summary;
	}
}
