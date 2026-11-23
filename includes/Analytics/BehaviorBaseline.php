<?php
namespace TBSHComplianceAuditLogger\Analytics;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-user behaviour baselines over the last 30 days, aggregated in SQL.
 *
 * Stored in user meta `tbsh_cal_behavior_baseline`:
 * usual_hours[], hour_share{h:pct}, event_counts{type:n}, total, active_days,
 * avg_daily_actions, avg_hourly_actions, updated_at.
 */
class BehaviorBaseline {

	const META_KEY = 'tbsh_cal_behavior_baseline';
	const DAYS     = 30;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'maybe_update' ) );
	}

	/**
	 * Daily refresh when anomaly detection is enabled.
	 *
	 * @param array $settings Settings.
	 */
	public static function maybe_update( $settings ) {
		if ( ! empty( $settings['anomaly_detection'] ) ) {
			self::update_baselines();
		}
	}

	/**
	 * Rebuild baselines for every user active in the window.
	 *
	 * @return int Users updated.
	 */
	public static function update_baselines() {
		global $wpdb;
		$table  = Schema::table( 'logs' );
		$cutoff = Time::ago( self::DAYS * DAY_IN_SECONDS );
		$ignore = 'behavior_anomaly';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totals = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, COUNT(*) AS total, COUNT(DISTINCT DATE(created_at)) AS days, COUNT(DISTINCT DATE_FORMAT(created_at, %s)) AS hours_active
			 FROM %i WHERE user_id > 0 AND created_at >= %s AND event_type <> %s GROUP BY user_id',
			'%Y-%m-%d %H',
			$table,
			$cutoff,
			$ignore
		), OBJECT_K );

		if ( empty( $totals ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hours = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, HOUR(created_at) AS h, COUNT(*) AS c FROM %i WHERE user_id > 0 AND created_at >= %s AND event_type <> %s GROUP BY user_id, h',
			$table,
			$cutoff,
			$ignore
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$types = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, event_type, COUNT(*) AS c FROM %i WHERE user_id > 0 AND created_at >= %s AND event_type <> %s GROUP BY user_id, event_type',
			$table,
			$cutoff,
			$ignore
		) );

		$hour_map = array();
		foreach ( (array) $hours as $row ) {
			$hour_map[ (int) $row->user_id ][ (int) $row->h ] = (int) $row->c;
		}
		$type_map = array();
		foreach ( (array) $types as $row ) {
			$type_map[ (int) $row->user_id ][ $row->event_type ] = (int) $row->c;
		}

		$updated = 0;
		foreach ( $totals as $user_id => $row ) {
			$user_id = (int) $user_id;
			$total   = max( 1, (int) $row->total );
			$share   = array();
			$usual   = array();
			foreach ( $hour_map[ $user_id ] ?? array() as $h => $c ) {
				$pct          = round( 100 * $c / $total, 1 );
				$share[ $h ] = $pct;
				if ( $pct >= 3 ) {
					$usual[] = $h;
				}
			}
			sort( $usual );

			update_user_meta(
				$user_id,
				self::META_KEY,
				array(
					'usual_hours'        => $usual,
					'hour_share'         => $share,
					'event_counts'       => $type_map[ $user_id ] ?? array(),
					'total'              => (int) $row->total,
					'active_days'        => (int) $row->days,
					'avg_daily_actions'  => round( (int) $row->total / max( 1, (int) $row->days ), 1 ),
					'avg_hourly_actions' => round( (int) $row->total / max( 1, (int) $row->hours_active ), 1 ),
					'updated_at'         => Time::now(),
				)
			);
			++$updated;
		}

		update_option( 'tbsh_cal_baselines_updated', Time::now(), false );
		return $updated;
	}

	/**
	 * Baseline for a user.
	 *
	 * @param int $user_id User.
	 * @return array|null
	 */
	public static function get( $user_id ) {
		$baseline = get_user_meta( (int) $user_id, self::META_KEY, true );
		return is_array( $baseline ) ? $baseline : null;
	}

	/**
	 * All stored baselines (for the anomaly page).
	 *
	 * @param int $limit Max users.
	 * @return array
	 */
	public static function all( $limit = 50 ) {
		$users = get_users( array(
			'meta_key' => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'number'   => $limit,
			'fields'   => array( 'ID', 'user_login', 'display_name' ),
		) );
		$out = array();
		foreach ( $users as $user ) {
			$baseline = self::get( $user->ID );
			if ( ! $baseline ) {
				continue;
			}
			$out[] = array(
				'user_id'            => (int) $user->ID,
				'user_login'         => $user->user_login,
				'display_name'       => $user->display_name,
				'usual_hours'        => $baseline['usual_hours'] ?? array(),
				'avg_daily_actions'  => $baseline['avg_daily_actions'] ?? 0,
				'active_days'        => $baseline['active_days'] ?? 0,
				'distinct_actions'   => count( $baseline['event_counts'] ?? array() ),
				'updated_at'         => $baseline['updated_at'] ?? null,
			);
		}
		return $out;
	}
}
