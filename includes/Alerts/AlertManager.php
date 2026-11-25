<?php
namespace TBSHComplianceAuditLogger\Alerts;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\CronScheduler;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alert queue: never sends inline. trigger() writes one queued row per ready
 * channel and schedules a single cron event; dispatch() sends with retries.
 */
class AlertManager {

	/**
	 * Severity weights.
	 */
	const SEVERITY = array(
		'info'     => 1,
		'notice'   => 2,
		'warning'  => 3,
		'error'    => 4,
		'critical' => 5,
	);

	/**
	 * Retry backoff in minutes, indexed by attempts already made.
	 */
	const BACKOFF = array( 1 => 5, 2 => 30, 3 => 120 );

	/**
	 * Maximum delivery attempts.
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( CronScheduler::ALERTS_HOOK, array( __CLASS__, 'dispatch' ) );
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Queue an alert on every ready channel.
	 *
	 * @param string $type         Alert type slug (e.g. incident, vault_failure, test).
	 * @param string $severity     info|notice|warning|error|critical.
	 * @param string $subject      Short subject.
	 * @param string $message      Message body (plain text).
	 * @param array  $context      Extra non-secret context (included in webhook payloads).
	 * @param string $throttle_key Optional subject key; identical type+key alerts are throttled for 15 minutes.
	 * @return int Number of deliveries queued.
	 */
	public static function trigger( $type, $severity, $subject, $message, array $context = array(), $throttle_key = '' ) {
		$settings = self::settings();
		$severity = isset( self::SEVERITY[ $severity ] ) ? $severity : 'warning';
		$min      = $settings['alert_min_severity'] ?? 'error';

		if ( self::SEVERITY[ $severity ] < ( self::SEVERITY[ $min ] ?? 4 ) ) {
			return 0;
		}

		$channels = array_values( array_filter( Channels::ALL, function ( $c ) use ( $settings ) {
			return Channels::is_ready( $c, $settings );
		} ) );
		if ( empty( $channels ) ) {
			return 0;
		}

		if ( '' !== $throttle_key ) {
			$transient = 'tbsh_cal_alert_' . md5( $type . '|' . $throttle_key );
			if ( get_transient( $transient ) ) {
				return 0;
			}
			set_transient( $transient, 1, 15 * MINUTE_IN_SECONDS );
		}

		$payload = self::build_payload( $type, $severity, $subject, $message, $context );
		$queued  = 0;

		foreach ( $channels as $channel ) {
			$queued += (int) self::insert_row( $type, $channel, $severity, $subject, $payload, $settings );
		}

		if ( $queued && ! wp_next_scheduled( CronScheduler::ALERTS_HOOK ) ) {
			wp_schedule_single_event( time(), CronScheduler::ALERTS_HOOK );
		}

		return $queued;
	}

	/**
	 * Send a test message to one channel immediately (admin action).
	 *
	 * @param string $channel Channel.
	 * @return array{ok: bool, code: int, message: string}
	 */
	public static function send_test( $channel ) {
		$settings = self::settings();
		if ( ! in_array( $channel, Channels::ALL, true ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => __( 'Unknown channel.', 'tbsh-compliance-audit-logger' ) );
		}

		// Test regardless of the channel toggle, but require configuration.
		$settings[ 'email' === $channel ? 'email_alerts' : $channel . '_enabled' ] = true;
		if ( ! Channels::is_ready( $channel, $settings ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => __( 'This channel is not configured yet. Save its settings first.', 'tbsh-compliance-audit-logger' ) );
		}

		$payload = self::build_payload(
			'test',
			'notice',
			__( 'Test alert', 'tbsh-compliance-audit-logger' ),
			__( 'This is a test message from Compliance Audit Log. If you can read it, the channel works.', 'tbsh-compliance-audit-logger' ),
			array()
		);
		$result  = Channels::send( $channel, $payload, $settings );

		$id = self::insert_row( 'test', $channel, 'notice', $payload['subject'], $payload, $settings );
		if ( $id ) {
			self::record_result( $id, 1, $result );
		}

		return $result;
	}

	/**
	 * Send due deliveries (cron).
	 */
	public static function dispatch() {
		global $wpdb;
		$table    = Schema::table( 'alert_log' );
		$settings = self::settings();
		$now      = Time::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, channel, payload_json, attempts FROM %i WHERE status IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT 25",
			$table,
			$now
		) );

		foreach ( (array) $rows as $row ) {
			// Claim the row so concurrent runs never double-send.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'sending' WHERE id = %d AND status IN ('queued','retry')", $table, $row->id ) );
			if ( 1 !== (int) $claimed ) {
				continue;
			}

			$payload = Repository::json( $row->payload_json );
			$result  = empty( $payload )
				? array( 'ok' => false, 'code' => 0, 'message' => 'Missing payload.' )
				: Channels::send( $row->channel, $payload, $settings );

			self::record_result( (int) $row->id, (int) $row->attempts + 1, $result );
		}

		// More due rows left? Keep draining.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','retry') AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", $table, Time::now() ) );
		if ( $pending > 0 && ! wp_next_scheduled( CronScheduler::ALERTS_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, CronScheduler::ALERTS_HOOK );
		}

		// Next retry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_attempt_at) FROM %i WHERE status = 'retry'", $table ) );
		if ( $next && ! wp_next_scheduled( CronScheduler::ALERTS_HOOK ) ) {
			wp_schedule_single_event( max( time() + 60, (int) Time::to_timestamp( $next ) ), CronScheduler::ALERTS_HOOK );
		}
	}

	/**
	 * Delivery history for the Alert Center.
	 *
	 * @param array $args Filters: status, channel, page, per_page.
	 * @return array
	 */
	public static function history( array $args ) {
		global $wpdb;
		$table = Schema::table( 'alert_log' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = array( '1=1' );
		$params = array( $table );
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = sanitize_key( $args['channel'] );
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where_sql", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, alert_type, channel, recipient_masked, severity, subject, status, response_message, attempts, next_attempt_at, sent_at, created_at FROM %i WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d",
			array_merge( $params, array( $per_page, $offset ) )
		), ARRAY_A );

		// Summary counts for the last 30 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$summary = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i WHERE created_at >= %s GROUP BY status', $table, Time::ago( 30 * DAY_IN_SECONDS ) ), OBJECT_K );

		$result            = Repository::paged( (array) $rows, $total, $per_page, $page );
		$result['summary'] = array(
			'sent'    => isset( $summary['sent'] ) ? (int) $summary['sent']->c : 0,
			'failed'  => isset( $summary['failed'] ) ? (int) $summary['failed']->c : 0,
			'pending' => ( isset( $summary['queued'] ) ? (int) $summary['queued']->c : 0 ) + ( isset( $summary['retry'] ) ? (int) $summary['retry']->c : 0 ),
		);
		$result['channels'] = array();
		foreach ( Channels::ALL as $channel ) {
			$result['channels'][ $channel ] = Channels::is_ready( $channel, self::settings() );
		}

		return $result;
	}

	/**
	 * Build the alert payload. Contains no secrets.
	 *
	 * @param string $type     Type.
	 * @param string $severity Severity.
	 * @param string $subject  Subject.
	 * @param string $message  Message.
	 * @param array  $context  Context.
	 * @return array
	 */
	public static function build_payload( $type, $severity, $subject, $message, array $context ) {
		$route = isset( $context['incident_id'] ) ? 'incidents' : 'logs';
		return array(
			'type'      => sanitize_key( $type ),
			'severity'  => $severity,
			'subject'   => wp_strip_all_tags( (string) $subject ),
			'message'   => wp_strip_all_tags( (string) $message ),
			'context'   => $context,
			'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'  => home_url( '/' ),
			'time'      => Time::now(),
			'link'      => admin_url( 'admin.php?page=tbsh-compliance-audit-logger#/' . $route ),
		);
	}

	/**
	 * Insert a queued delivery row.
	 *
	 * @return int Row ID or 0.
	 */
	private static function insert_row( $type, $channel, $severity, $subject, array $payload, array $settings ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			Schema::table( 'alert_log' ),
			array(
				'alert_type'       => sanitize_key( $type ),
				'channel'          => $channel,
				'recipient_masked' => mb_substr( Channels::recipient_hint( $channel, $settings ), 0, 255 ),
				'severity'         => $severity,
				'subject'          => mb_substr( (string) $subject, 0, 255 ),
				'status'           => 'queued',
				'payload_json'     => wp_json_encode( $payload ),
				'attempts'         => 0,
				'created_at'       => Time::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Persist a delivery result and schedule a retry when appropriate.
	 *
	 * @param int   $id       Row ID.
	 * @param int   $attempts Attempts made including this one.
	 * @param array $result   Send result.
	 */
	private static function record_result( $id, $attempts, array $result ) {
		global $wpdb;

		$data = array(
			'attempts'         => $attempts,
			'response_message' => mb_substr( trim( ( $result['code'] ? 'HTTP ' . $result['code'] . ' ' : '' ) . $result['message'] ), 0, 500 ),
		);

		if ( $result['ok'] ) {
			$data['status']          = 'sent';
			$data['sent_at']         = Time::now();
			$data['next_attempt_at'] = null;
		} elseif ( $attempts < self::MAX_ATTEMPTS && isset( self::BACKOFF[ $attempts ] ) ) {
			$data['status']          = 'retry';
			$data['next_attempt_at'] = Time::from_now( self::BACKOFF[ $attempts ] * MINUTE_IN_SECONDS );
		} else {
			$data['status']          = 'failed';
			$data['next_attempt_at'] = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'alert_log' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	private static function settings() {
		return wp_parse_args( get_option( 'tbsh_cal_settings', array() ), \TBSHComplianceAuditLogger\API\SettingsController::defaults() );
	}
}
