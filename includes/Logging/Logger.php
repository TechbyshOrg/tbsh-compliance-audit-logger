<?php
namespace TBSHComplianceAuditLogger\Logging;

use TBSHComplianceAuditLogger\Privacy\PrivacyManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized logging engine.
 */
class Logger {

	/**
	 * Log queue to process at shutdown.
	 *
	 * @var array
	 */
	private static $log_queue = array();

	/**
	 * Whether the shutdown flush hook is registered.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'shutdown', array( __CLASS__, 'flush_logs' ) );
	}

	/**
	 * Count queued events matching type and username (for same-request checks).
	 *
	 * @param string $event_type Event type slug.
	 * @param string $username   Username to match.
	 * @return int
	 */
	public static function count_queued( $event_type, $username = '' ) {
		$count = 0;
		foreach ( self::$log_queue as $log ) {
			if ( $log['event_type'] !== $event_type ) {
				continue;
			}
			if ( '' !== $username && $log['username'] !== $username ) {
				continue;
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Log an event.
	 *
	 * @param string $event_type     Action type (e.g. user_login, settings_update)
	 * @param string $event_category Compliance category (e.g. Access Control, Configuration Management)
	 * @param string $severity       Severity level (info, notice, warning, error, critical)
	 * @param string $event_title    Short descriptive title
	 * @param string $event_message  Detailed description
	 * @param array  $args           Additional context parameters
	 */
	public static function log( $event_type, $event_category, $severity, $event_title, $event_message, $args = array() ) {
		$settings = get_option( 'tbsh_cal_settings', array() );

		/**
		 * Filter whether an event should be logged.
		 *
		 * @param bool   $should_log Whether to log.
		 * @param string $event_type Event type.
		 * @param array  $args       Context args.
		 */
		$should_log = apply_filters( 'tbsh_cal_should_log', true, $event_type, $args );
		if ( ! $should_log ) {
			return;
		}

		// Check if logging is enabled.
		if ( isset( $settings['enable_logging'] ) && ! $settings['enable_logging'] ) {
			return;
		}

		// Filter by min severity.
		$min_severity = isset( $settings['min_severity'] ) ? $settings['min_severity'] : 'info';
		if ( self::severity_value( $severity ) < self::severity_value( $min_severity ) ) {
			return;
		}

		/**
		 * Filter log context args before queueing.
		 *
		 * @param array  $args           Context args.
		 * @param string $event_type     Event type.
		 * @param string $event_category Category.
		 * @param string $severity       Severity.
		 */
		$args = apply_filters( 'tbsh_cal_log_args', $args, $event_type, $event_category, $severity );

		// Gather current request context.
		$current_user = wp_get_current_user();
		$user_id      = isset( $args['user_id'] ) ? intval( $args['user_id'] ) : ( $current_user && $current_user->ID ? $current_user->ID : 0 );
		$username     = isset( $args['username'] ) ? sanitize_text_field( $args['username'] ) : ( $current_user && $current_user->ID ? $current_user->user_login : 'system' );
		$role         = 'system';

		if ( $user_id > 0 && $current_user && $current_user->ID === $user_id && ! empty( $current_user->roles ) ) {
			$role = reset( $current_user->roles );
		} elseif ( isset( $args['role'] ) ) {
			$role = sanitize_text_field( $args['role'] );
		}

		$site_id = get_current_blog_id();

		// IP and UA hashes.
		$ip_hash         = PrivacyManager::hash_ip();
		$user_agent_hash = PrivacyManager::hash_user_agent();

		// Request info.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$request_uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$referrer       = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		// Extra args.
		$object_type     = isset( $args['object_type'] ) ? sanitize_text_field( $args['object_type'] ) : '';
		$object_id       = isset( $args['object_id'] ) ? sanitize_text_field( $args['object_id'] ) : '';
		$metadata_json   = isset( $args['metadata'] ) ? wp_json_encode( $args['metadata'] ) : '{}';
		$compliance_tags = isset( $args['compliance_tags'] ) ? sanitize_text_field( $args['compliance_tags'] ) : $event_category;

		// Add to queue.
		self::$log_queue[] = array(
			'event_uuid'       => self::generate_uuid(),
			'event_type'       => $event_type,
			'event_category'   => $event_category,
			'severity'         => $severity,
			'event_title'      => $event_title,
			'event_message'    => $event_message,
			'user_id'          => $user_id,
			'username'         => $username,
			'role'             => $role,
			'site_id'          => $site_id,
			'object_type'      => $object_type,
			'object_id'        => $object_id,
			'ip_hash'          => $ip_hash,
			'user_agent_hash'  => $user_agent_hash,
			'request_method'   => $request_method,
			'request_uri'      => $request_uri,
			'referrer'         => $referrer,
			'metadata_json'    => $metadata_json,
			'compliance_tags'  => $compliance_tags,
			'created_at'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Flush log queue to the database.
	 */
	public static function flush_logs() {
		if ( empty( self::$log_queue ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';
		$lock_name  = 'tbsh_cal_log_flush_' . $wpdb->blogid;

		// Advisory lock so concurrent requests cannot fork the hash chain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) );
		$has_lock = ( '1' === (string) $got_lock );

		// Start transaction to prevent race conditions during insertion and hashing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		$transaction_success = true;

		// Lock tip of chain and fetch last hash.
		$previous_hash = '0000000000000000000000000000000000000000000000000000000000000000';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last_entry = $wpdb->get_row( $wpdb->prepare( "SELECT id, integrity_hash FROM %i ORDER BY id DESC LIMIT 1 FOR UPDATE", $table_name ) );
		if ( $last_entry && ! empty( $last_entry->integrity_hash ) ) {
			$previous_hash = $last_entry->integrity_hash;
		}

		foreach ( self::$log_queue as $log ) {
			$log['previous_hash'] = $previous_hash;

			// Enforce formats to prevent WordPress from casting object_id to integer (%d).
			$formats = array();
			foreach ( $log as $key => $val ) {
				if ( in_array( $key, array( 'user_id', 'site_id' ), true ) ) {
					$formats[] = '%d';
				} else {
					$formats[] = '%s';
				}
			}

			// Insert log entry.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $wpdb->insert( $table_name, $log, $formats );

			if ( $inserted ) {
				$insert_id = $wpdb->insert_id;

				// Calculate canonical hash.
				$canonical_string = implode( '|', array(
					$insert_id,
					$log['event_uuid'],
					$log['event_type'],
					$log['event_category'],
					$log['severity'],
					$log['event_title'],
					$log['event_message'],
					$log['user_id'],
					$log['site_id'],
					$log['object_type'],
					$log['object_id'],
					$log['ip_hash'],
					$log['user_agent_hash'],
					$log['request_method'],
					$log['request_uri'],
					$log['metadata_json'],
					$log['compliance_tags'],
					$previous_hash,
					$log['created_at'],
				) );

				$integrity_hash = hash( 'sha256', $canonical_string );

				// Update log with hash.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$table_name,
					array( 'integrity_hash' => $integrity_hash ),
					array( 'id' => $insert_id )
				);

				if ( false === $updated ) {
					$transaction_success = false;
					break;
				}

				// Cascade current stored hash as previous for next iteration.
				$previous_hash = $integrity_hash;
			} else {
				$transaction_success = false;
				break;
			}
		}

		if ( $transaction_success ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
		}

		if ( $has_lock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}

		// Clear queue.
		self::$log_queue = array();
	}

	/**
	 * Immediately flush any queued logs (activation/deactivation).
	 */
	public static function flush_now() {
		self::flush_logs();
	}

	/**
	 * Map severity slug to integer value.
	 */
	private static function severity_value( $severity ) {
		$levels = array(
			'info'     => 1,
			'notice'   => 2,
			'warning'  => 3,
			'error'    => 4,
			'critical' => 5,
		);
		return isset( $levels[ $severity ] ) ? $levels[ $severity ] : 1;
	}

	/**
	 * Generate a UUID v4.
	 */
	private static function generate_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}
}
