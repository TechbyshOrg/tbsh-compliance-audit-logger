<?php
namespace TBSHComplianceAuditLogger\Alerts;

use TBSHComplianceAuditLogger\API\SettingsController;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Near-real-time forwarding of audit log entries to a SIEM / log platform.
 *
 * The log table itself is the queue: a cursor remembers the last forwarded ID, so
 * nothing is lost while the destination is down (entries are retried with backoff)
 * and nothing is sent twice after success. Each forwarded entry carries its chain
 * hash so the receiving system can prove it matches the local tamper-evident log.
 *
 * Transports: HTTPS JSON (optionally bearer-authenticated and HMAC-signed), Splunk HTTP
 * Event Collector, and RFC 5424 syslog over TLS or TCP (RFC 6587 octet counting).
 * Private and loopback destinations are refused unless the
 * tbsh_cal_allow_private_siem_endpoints filter returns true.
 */
class SiemForwarder {

	const HOOK         = 'tbsh_cal_siem_forward';
	const STATE_OPTION = 'tbsh_cal_siem_state';
	const LOCK         = 'tbsh_cal_siem_lock';
	const TRANSPORTS   = array( 'https', 'splunk_hec', 'syslog_tls', 'syslog_tcp' );
	const BATCH        = 200;

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
	 * Whether this request logged something.
	 *
	 * @var bool
	 */
	private static $dirty = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'dispatch' ) );
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'dispatch' ) );
		add_action( 'tbsh_cal_event_logged', array( __CLASS__, 'mark_dirty' ) );
		// After Logger::flush_logs() (priority 10) so the rows exist.
		add_action( 'shutdown', array( __CLASS__, 'maybe_schedule' ), 20 );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	private static function settings() {
		return wp_parse_args( get_option( 'tbsh_cal_settings', array() ), SettingsController::defaults() );
	}

	/**
	 * Whether forwarding is enabled and configured.
	 *
	 * @param array|null $settings Settings.
	 * @return bool
	 */
	public static function is_ready( $settings = null ) {
		$settings = $settings ?? self::settings();
		if ( empty( $settings['siem_enabled'] ) ) {
			return false;
		}
		$transport = $settings['siem_transport'] ?? 'https';
		if ( in_array( $transport, array( 'syslog_tls', 'syslog_tcp' ), true ) ) {
			return '' !== (string) ( $settings['siem_host'] ?? '' ) && (int) ( $settings['siem_port'] ?? 0 ) > 0;
		}
		return Secrets::has( 'siem_url' ) && ( 'splunk_hec' !== $transport || Secrets::has( 'siem_token' ) );
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Save state.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Highest log ID.
	 *
	 * @return int
	 */
	private static function max_id() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Schema::table( 'logs' ) ) );
	}

	/**
	 * Start forwarding from "now" when it is switched on or the destination changes
	 * (history is not back-filled).
	 *
	 * @param array $old Old settings.
	 * @param array $new New settings.
	 */
	public static function on_settings_saved( array $old, array $new ) {
		$switched_on = empty( $old['siem_enabled'] ) && ! empty( $new['siem_enabled'] );
		$moved       = ( $old['siem_transport'] ?? '' ) !== ( $new['siem_transport'] ?? '' ) || ( $old['siem_host'] ?? '' ) !== ( $new['siem_host'] ?? '' );
		if ( $switched_on || ( ! empty( $new['siem_enabled'] ) && $moved ) ) {
			$state           = self::state();
			$state['cursor'] = self::max_id();
			unset( $state['last_error'], $state['last_error_at'], $state['failing_since'], $state['next_retry_at'], $state['backoff'] );
			self::save_state( $state );
		}
		if ( empty( $new['siem_enabled'] ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Something was logged in this request.
	 */
	public static function mark_dirty() {
		self::$dirty = true;
	}

	/**
	 * Schedule a forwarding run after new entries were written.
	 */
	public static function maybe_schedule() {
		if ( ! self::$dirty || ! self::is_ready() || wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$state = self::state();
		$at    = max( time(), (int) ( $state['next_retry_at'] ?? 0 ) );
		wp_schedule_single_event( $at, self::HOOK );
	}

	/**
	 * Forward pending entries (cron).
	 *
	 * @return array{ok: bool, sent: int, message: string}
	 */
	public static function dispatch() {
		$settings = self::settings();
		if ( ! self::is_ready( $settings ) ) {
			return array( 'ok' => false, 'sent' => 0, 'message' => 'not configured' );
		}
		$state = self::state();
		if ( (int) ( $state['next_retry_at'] ?? 0 ) > time() || get_transient( self::LOCK ) ) {
			return array( 'ok' => false, 'sent' => 0, 'message' => 'waiting' );
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		global $wpdb;
		$table = Schema::table( 'logs' );
		if ( ! isset( $state['cursor'] ) ) {
			$state['cursor'] = self::max_id();
		}
		$min   = self::SEVERITY[ $settings['siem_min_severity'] ?? 'info' ] ?? 1;
		$until = microtime( true ) + 20;
		$sent  = 0;
		$ok    = true;
		$error = '';

		while ( microtime( true ) < $until ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND integrity_hash IS NOT NULL ORDER BY id ASC LIMIT %d', $table, (int) $state['cursor'], self::BATCH ) );
			if ( ! $rows ) {
				break;
			}
			$events = array();
			foreach ( $rows as $row ) {
				if ( ( self::SEVERITY[ $row->severity ] ?? 1 ) >= $min ) {
					$events[] = self::event( $row );
				}
			}
			if ( $events ) {
				$result = self::send( $events, $settings );
				if ( ! $result['ok'] ) {
					$ok    = false;
					$error = $result['message'];
					break;
				}
				$sent += count( $events );
			}
			$state['cursor'] = (int) end( $rows )->id;
			self::save_state( $state );
			if ( count( $rows ) < self::BATCH ) {
				break;
			}
		}

		if ( $ok ) {
			if ( $sent ) {
				$state['sent_total']      = (int) ( $state['sent_total'] ?? 0 ) + $sent;
				$state['last_success_at'] = Time::now();
			}
			unset( $state['failing_since'], $state['next_retry_at'], $state['backoff'] );
		} else {
			self::record_failure( $state, $error );
		}
		self::save_state( $state );
		delete_transient( self::LOCK );

		// More pending (time budget used up): continue shortly.
		if ( $ok && self::max_id() > (int) $state['cursor'] && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}

		return array( 'ok' => $ok, 'sent' => $sent, 'message' => $ok ? 'ok' : $error );
	}

	/**
	 * Back off and alert once an outage lasts an hour.
	 *
	 * @param array  $state State (by reference).
	 * @param string $error Error.
	 */
	private static function record_failure( array &$state, $error ) {
		$backoff                = min( HOUR_IN_SECONDS, max( 5 * MINUTE_IN_SECONDS, 2 * (int) ( $state['backoff'] ?? 0 ) ) );
		$state['backoff']       = $backoff;
		$state['next_retry_at'] = time() + $backoff;
		$state['last_error']    = wp_html_excerpt( sanitize_text_field( $error ), 300, '…' );
		$state['last_error_at'] = Time::now();
		if ( empty( $state['failing_since'] ) ) {
			$state['failing_since'] = time();
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( $state['next_retry_at'], self::HOOK );

		if ( time() - (int) $state['failing_since'] >= HOUR_IN_SECONDS && ! get_transient( 'tbsh_cal_siem_alerted' ) ) {
			set_transient( 'tbsh_cal_siem_alerted', 1, 6 * HOUR_IN_SECONDS );
			/* translators: %s: error message */
			$message = sprintf( __( 'Audit log forwarding to the SIEM has been failing for over an hour. Entries are kept and will be sent when the destination is reachable. Last error: %s', 'tbsh-compliance-audit-logger' ), $state['last_error'] );
			Logger::log( 'siem_forward_failed', 'Operational Security', 'error', __( 'SIEM forwarding failing', 'tbsh-compliance-audit-logger' ), $message, array( 'object_type' => 'siem' ) );
			AlertManager::trigger( 'siem_failure', 'error', __( 'SIEM forwarding failing', 'tbsh-compliance-audit-logger' ), $message, array(), 'siem' );
		}
	}

	/**
	 * Send a test event now.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function send_test() {
		$settings                 = self::settings();
		$settings['siem_enabled'] = true;
		if ( ! self::is_ready( $settings ) ) {
			return array( 'ok' => false, 'message' => __( 'Save the destination settings first.', 'tbsh-compliance-audit-logger' ) );
		}
		$event = array(
			'id'          => 0,
			'uuid'        => wp_generate_uuid4(),
			'time'        => wp_date( 'c' ),
			'site'        => home_url( '/' ),
			'site_id'     => get_current_blog_id(),
			'event_type'  => 'siem_test',
			'category'    => 'Operational Security',
			'severity'    => 'notice',
			'title'       => 'SIEM test event',
			'message'     => 'Test event from Compliance Audit Log.',
			'integrity'   => array( 'hash' => '', 'previous_hash' => '' ),
		);
		$result = self::send( array( $event ), $settings );
		return array(
			'ok'      => $result['ok'],
			'message' => $result['ok']
				? __( 'Test event delivered.', 'tbsh-compliance-audit-logger' )
				/* translators: %s: error */
				: sprintf( __( 'Test event failed: %s', 'tbsh-compliance-audit-logger' ), $result['message'] ),
		);
	}

	/**
	 * Status for the settings screen.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::state();
		$ready = self::is_ready();
		return array(
			'ready'           => $ready,
			'backlog'         => $ready && isset( $state['cursor'] ) ? max( 0, self::max_id() - (int) $state['cursor'] ) : 0,
			'sent_total'      => (int) ( $state['sent_total'] ?? 0 ),
			'last_success_at' => $state['last_success_at'] ?? null,
			'last_error'      => $state['last_error'] ?? null,
			'last_error_at'   => $state['last_error_at'] ?? null,
			'failing'         => ! empty( $state['failing_since'] ),
		);
	}

	/**
	 * Convert a log row to the forwarded event shape.
	 *
	 * @param object $row Log row.
	 * @return array
	 */
	private static function event( $row ) {
		$time     = date_create( $row->created_at, wp_timezone() );
		$metadata = Repository::json( $row->metadata_json );
		return array(
			'id'          => (int) $row->id,
			'uuid'        => $row->event_uuid,
			'time'        => $time ? $time->format( 'c' ) : $row->created_at,
			'site'        => home_url( '/' ),
			'site_id'     => (int) $row->site_id,
			'event_type'  => $row->event_type,
			'category'    => $row->event_category,
			'severity'    => $row->severity,
			'title'       => $row->event_title,
			'message'     => $row->event_message,
			'user'        => array(
				'id'   => (int) $row->user_id,
				'name' => $row->username,
				'role' => $row->role,
			),
			'object'      => array(
				'type' => $row->object_type,
				'id'   => $row->object_id,
			),
			'ip_hash'     => $row->ip_hash,
			'request'     => array(
				'method' => $row->request_method,
				'uri'    => $row->request_uri,
			),
			'origin'      => array(
				'actor'   => (string) ( $metadata['_actor'] ?? '' ),
				'via'     => (string) ( $metadata['_via'] ?? '' ),
				'ability' => (string) ( $metadata['_ability'] ?? '' ),
			),
			'tags'        => $row->compliance_tags,
			'metadata'    => $metadata,
			'integrity'   => array(
				'hash'          => $row->integrity_hash,
				'previous_hash' => $row->previous_hash,
			),
		);
	}

	/**
	 * Send events with the configured transport.
	 *
	 * @param array $events   Events.
	 * @param array $settings Settings.
	 * @return array{ok: bool, message: string}
	 */
	private static function send( array $events, array $settings ) {
		$transport = in_array( $settings['siem_transport'] ?? '', self::TRANSPORTS, true ) ? $settings['siem_transport'] : 'https';
		switch ( $transport ) {
			case 'splunk_hec':
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				$body = '';
				foreach ( $events as $event ) {
					$body .= wp_json_encode( array(
						'time'       => strtotime( $event['time'] ),
						'host'       => $host,
						'source'     => 'wordpress',
						'sourcetype' => 'tbsh:audit',
						'event'      => $event,
					) ) . "\n";
				}
				return self::post( Secrets::get( 'siem_url' ), $body, array( 'Authorization' => 'Splunk ' . Secrets::get( 'siem_token' ) ) );

			case 'syslog_tls':
			case 'syslog_tcp':
				return self::syslog( $events, (string) $settings['siem_host'], (int) $settings['siem_port'], 'syslog_tls' === $transport );

			default:
				$body      = wp_json_encode( array(
					'source' => 'tbsh-compliance-audit-logger',
					'site'   => home_url( '/' ),
					'events' => $events,
				) );
				$timestamp = (string) time();
				$headers   = array( 'X-TBSH-Timestamp' => $timestamp );
				$token     = Secrets::get( 'siem_token' );
				if ( '' !== $token ) {
					$headers['Authorization']    = 'Bearer ' . $token;
					$headers['X-TBSH-Signature'] = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $token );
				}
				return self::post( Secrets::get( 'siem_url' ), $body, $headers );
		}
	}

	/**
	 * Whether private destinations are allowed.
	 *
	 * @return bool
	 */
	private static function allow_private() {
		/**
		 * Allow forwarding to private, loopback or reserved addresses (internal SIEM collectors).
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'tbsh_cal_allow_private_siem_endpoints', false );
	}

	/**
	 * HTTPS POST.
	 *
	 * @param string $url     URL.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @return array
	 */
	private static function post( $url, $body, array $headers ) {
		if ( 0 !== strpos( (string) $url, 'https://' ) || ( ! self::allow_private() && ! wp_http_validate_url( $url ) ) ) {
			return array( 'ok' => false, 'message' => 'The endpoint must be a public https URL.' );
		}
		$args     = array(
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array_merge( array( 'Content-Type' => 'application/json; charset=utf-8' ), $headers ),
			'body'        => $body,
		);
		$response = self::allow_private() ? wp_remote_post( $url, $args ) : wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'      => $code >= 200 && $code < 300,
			'message' => $code >= 200 && $code < 300 ? 'ok' : 'HTTP ' . $code . ': ' . wp_html_excerpt( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 200 ),
		);
	}

	/**
	 * RFC 5424 syslog over TCP/TLS with octet-counting framing.
	 *
	 * @param array  $events Events.
	 * @param string $host   Host.
	 * @param int    $port   Port.
	 * @param bool   $tls    Use TLS.
	 * @return array
	 */
	private static function syslog( array $events, $host, $port, $tls ) {
		$ips = \TBSHComplianceAuditLogger\Helpers\Network::resolve( $host );
		if ( ! $ips ) {
			return array( 'ok' => false, 'message' => 'The syslog host could not be resolved.' );
		}
		if ( ! self::allow_private() ) {
			foreach ( $ips as $ip ) {
				if ( ! \TBSHComplianceAuditLogger\Helpers\Network::is_public_ip( $ip ) ) {
					return array( 'ok' => false, 'message' => 'The syslog host resolves to a private or reserved address.' );
				}
			}
		}

		$context = stream_context_create( array(
			'ssl' => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
				'peer_name'        => $host,
				'SNI_enabled'      => true,
			),
		) );
		$errno  = 0;
		$errstr = '';
		$target = ( $tls ? 'tls://' : 'tcp://' ) . ( false !== strpos( $ips[0], ':' ) ? '[' . $ips[0] . ']' : $ips[0] ) . ':' . (int) $port;
		$socket = @stream_socket_client( $target, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Error returned below.
		if ( ! $socket ) {
			return array( 'ok' => false, 'message' => 'Connection failed: ' . ( $errstr ? $errstr : (string) $errno ) );
		}
		stream_set_timeout( $socket, 5 );

		$hostname = preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$codes    = array( 'critical' => 2, 'error' => 3, 'warning' => 4, 'notice' => 5, 'info' => 6 );
		$payload  = '';
		foreach ( $events as $event ) {
			$pri      = 16 * 8 + ( $codes[ $event['severity'] ] ?? 6 ); // Facility local0.
			$msgid    = substr( preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $event['event_type'] ), 0, 32 );
			$line     = '<' . $pri . '>1 ' . $event['time'] . ' ' . ( $hostname ? $hostname : '-' ) . ' tbsh-audit - ' . ( $msgid ? $msgid : '-' ) . ' - ' . wp_json_encode( $event );
			$payload .= strlen( $line ) . ' ' . $line;
		}

		$written = 0;
		$length  = strlen( $payload );
		while ( $written < $length ) {
			$n = fwrite( $socket, substr( $payload, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $n || 0 === $n ) {
				fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return array( 'ok' => false, 'message' => 'The connection closed while sending.' );
			}
			$written += $n;
		}
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'ok' => true, 'message' => 'ok' );
	}
}
