<?php
namespace TBSHComplianceAuditLogger\Analytics;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores each authenticated user action against that user's baseline and logs a
 * `behavior_anomaly` event when the score reaches the threshold.
 *
 * Score components (max 100): unusual hour (+25), never-performed action (+40) or
 * rarely performed action (+20), burst above normal hourly rate (+25).
 */
class AnomalyDetector {

	const THRESHOLD     = 60;
	const MIN_DAYS      = 5;
	const IGNORED_TYPES = array( 'behavior_anomaly', 'incident_created', 'incident_updated' );

	/**
	 * Guard against recursion.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_event_logged', array( __CLASS__, 'on_event' ) );
	}

	/**
	 * Score a queued log entry.
	 *
	 * @param array $entry Log entry.
	 */
	public static function on_event( $entry ) {
		if ( self::$running ) {
			return;
		}
		$user_id = (int) ( $entry['user_id'] ?? 0 );
		$type    = (string) ( $entry['event_type'] ?? '' );
		if ( $user_id <= 0 || in_array( $type, self::IGNORED_TYPES, true ) ) {
			return;
		}

		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( empty( $settings['anomaly_detection'] ) ) {
			return;
		}

		$result = self::score( $user_id, $type, $entry['created_at'] ?? Time::now() );
		if ( null === $result || $result['score'] < self::THRESHOLD ) {
			return;
		}

		// One anomaly per user + action type per hour.
		$throttle = 'tbsh_cal_anom_' . $user_id . '_' . md5( $type );
		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, HOUR_IN_SECONDS );

		self::$running = true;
		Logger::log(
			'behavior_anomaly',
			'Security Monitoring',
			$result['score'] >= 85 ? 'error' : 'warning',
			__( 'Unusual user behaviour detected', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: username, 2: score, 3: action, 4: reasons */
			sprintf( __( 'User "%1$s" scored %2$d/100 for "%3$s": %4$s', 'tbsh-compliance-audit-logger' ), $entry['username'] ?? '', $result['score'], $type, implode( '; ', $result['reasons'] ) ),
			array(
				'user_id'         => $user_id,
				'username'        => $entry['username'] ?? '',
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'compliance_tags' => 'Security Monitoring, ISO 27001 A.8.16',
				'metadata'        => array(
					'score'      => $result['score'],
					'reasons'    => $result['reasons'],
					'event_type' => $type,
					'source_log' => $entry['event_uuid'] ?? '',
				),
			)
		);
		self::$running = false;
	}

	/**
	 * Score an action.
	 *
	 * @param int    $user_id  User.
	 * @param string $type     Event type.
	 * @param string $datetime Site-local datetime of the action.
	 * @return array|null { score, reasons } or null when there is no usable baseline.
	 */
	public static function score( $user_id, $type, $datetime ) {
		$baseline = BehaviorBaseline::get( $user_id );
		if ( ! $baseline || (int) ( $baseline['active_days'] ?? 0 ) < self::MIN_DAYS ) {
			return null;
		}

		$score   = 0;
		$reasons = array();

		$hour = (int) substr( (string) $datetime, 11, 2 );
		if ( ! in_array( $hour, (array) ( $baseline['usual_hours'] ?? array() ), true ) ) {
			$score    += 25;
			/* translators: %02d: hour */
			$reasons[] = sprintf( __( 'outside usual hours (%02d:00)', 'tbsh-compliance-audit-logger' ), $hour );
		}

		$counts = (array) ( $baseline['event_counts'] ?? array() );
		$total  = max( 1, (int) ( $baseline['total'] ?? 1 ) );
		$seen   = (int) ( $counts[ $type ] ?? 0 );
		if ( 0 === $seen ) {
			$score    += 40;
			$reasons[] = __( 'action never performed by this user before', 'tbsh-compliance-audit-logger' );
		} elseif ( $seen / $total < 0.02 ) {
			$score    += 20;
			$reasons[] = __( 'rarely performed action', 'tbsh-compliance-audit-logger' );
		}

		$recent = self::recent_count( $user_id );
		$limit  = max( 20, 3 * (float) ( $baseline['avg_hourly_actions'] ?? 0 ) );
		if ( $recent > $limit ) {
			$score    += 25;
			/* translators: %d: actions in the last hour */
			$reasons[] = sprintf( __( 'burst of %d actions in the last hour', 'tbsh-compliance-audit-logger' ), $recent );
		}

		return array(
			'score'   => min( 100, $score ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Actions by the user in the last hour (stored + queued in this request).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	private static function recent_count( $user_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $user_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cache[ $user_id ] = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND created_at >= %s',
				Schema::table( 'logs' ),
				$user_id,
				Time::ago( HOUR_IN_SECONDS )
			) );
		}
		return $cache[ $user_id ] + Logger::count_queued_for_user( '', $user_id );
	}

	/**
	 * Recent anomaly events for the dashboard page.
	 *
	 * @param array $args page, per_page, user_id.
	 * @return array
	 */
	public static function list( array $args ) {
		global $wpdb;
		$table = Schema::table( 'logs' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = 'event_type = %s';
		$params = array( $table, 'behavior_anomaly' );
		if ( ! empty( $args['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, username, severity, event_message, metadata_json, created_at FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$meta           = Repository::json( $row['metadata_json'] );
			$row['score']   = (int) ( $meta['score'] ?? 0 );
			$row['reasons'] = (array) ( $meta['reasons'] ?? array() );
			$row['action']  = (string) ( $meta['event_type'] ?? '' );
			unset( $row['metadata_json'] );
		}

		$result = Repository::paged( $rows, $total, $per_page, $page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result['last_7_days']       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_type = %s AND created_at >= %s', $table, 'behavior_anomaly', Time::ago( 7 * DAY_IN_SECONDS ) ) );
		$result['baselines']         = BehaviorBaseline::all();
		$result['baselines_updated'] = get_option( 'tbsh_cal_baselines_updated', null );
		$result['enabled']           = ! empty( get_option( 'tbsh_cal_settings', array() )['anomaly_detection'] );
		return $result;
	}
}
