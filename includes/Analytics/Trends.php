<?php
namespace TBSHComplianceAuditLogger\Analytics;

use TBSHComplianceAuditLogger\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily activity trends. Buckets are generated in site-local time so labels and
 * values always align (one value per label).
 */
class Trends {

	/**
	 * Trend series for the last N days.
	 *
	 * @param int $days 7–90.
	 * @return array
	 */
	public static function get( $days = 30 ) {
		$days  = max( 7, min( 90, (int) $days ) );
		$cache = 'tbsh_cal_trends_' . $days;
		$hit   = get_transient( $cache );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		global $wpdb;
		$tz     = wp_timezone();
		$today  = new \DateTimeImmutable( 'today', $tz );
		$start  = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$labels = array();
		for ( $d = $start; $d <= $today; $d = $d->modify( '+1 day' ) ) {
			$labels[] = $d->format( 'Y-m-d' );
		}
		$zero  = array_fill_keys( $labels, 0 );
		$since = $start->format( 'Y-m-d 00:00:00' );

		$series = function ( $sql, ...$args ) use ( $wpdb, $zero ) {
			$out = $zero;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) as $row ) {
				if ( isset( $out[ $row->d ] ) ) {
					$out[ $row->d ] = (int) $row->c;
				}
			}
			return array_values( $out );
		};

		$events    = $series( 'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM %i WHERE created_at >= %s GROUP BY d', Schema::table( 'logs' ), $since );
		$critical  = $series( "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM %i WHERE created_at >= %s AND severity IN ('error','critical') GROUP BY d", Schema::table( 'logs' ), $since );
		$incidents = $series( 'SELECT DATE(created_at) AS d, COUNT(*) AS c FROM %i WHERE created_at >= %s GROUP BY d', Schema::table( 'incidents' ), $since );
		$failed    = $series( "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM %i WHERE created_at >= %s AND event_type = 'failed_login' GROUP BY d", Schema::table( 'logs' ), $since );

		// Open risks per day: created on/before the day and not mitigated/closed before the day ended.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$risks      = $wpdb->get_results( $wpdb->prepare( 'SELECT status, created_at, updated_at FROM %i', Schema::table( 'risks' ) ) );
		$open_risks = array();
		foreach ( $labels as $day ) {
			$end   = $day . ' 23:59:59';
			$count = 0;
			foreach ( (array) $risks as $risk ) {
				if ( $risk->created_at > $end ) {
					continue;
				}
				if ( 'active' === $risk->status || $risk->updated_at > $end ) {
					++$count;
				}
			}
			$open_risks[] = $count;
		}

		// Top categories in the window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$categories = $wpdb->get_results( $wpdb->prepare( 'SELECT event_category AS name, COUNT(*) AS count FROM %i WHERE created_at >= %s GROUP BY event_category ORDER BY count DESC LIMIT 8', Schema::table( 'logs' ), $since ), ARRAY_A );

		$result = array(
			'days'          => $days,
			'labels'        => $labels,
			'events'        => $events,
			'critical'      => $critical,
			'incidents'     => $incidents,
			'failed_logins' => $failed,
			'open_risks'    => $open_risks,
			'categories'    => $categories,
			'totals'        => array(
				'events'        => array_sum( $events ),
				'critical'      => array_sum( $critical ),
				'incidents'     => array_sum( $incidents ),
				'failed_logins' => array_sum( $failed ),
			),
		);

		set_transient( $cache, $result, HOUR_IN_SECONDS );
		return $result;
	}
}
