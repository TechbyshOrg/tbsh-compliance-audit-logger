<?php
namespace TBSHComplianceAuditLogger\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles privacy, hashing, and integration with WordPress privacy tools.
 */
class PrivacyManager {

	/**
	 * Get the secret salt for privacy hashing.
	 */
	public static function get_salt() {
		$salt = get_option( 'tbsh_cal_privacy_salt' );
		if ( ! $salt ) {
			$salt = wp_generate_password( 64, true, true );
			update_option( 'tbsh_cal_privacy_salt', $salt );
		}
		return $salt;
	}

	/**
	 * Irreversibly hash an IP address using SHA256 and salt.
	 */
	public static function hash_ip( $ip = '' ) {
		if ( empty( $ip ) ) {
			$ip = self::get_user_ip();
		}
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( empty( $settings['anonymize_ips'] ) ) {
			return $ip; // Return raw if anonymization is disabled.
		}
		return hash_hmac( 'sha256', $ip, self::get_salt() );
	}

	/**
	 * Irreversibly hash a User Agent.
	 */
	public static function hash_user_agent( $ua = '' ) {
		if ( empty( $ua ) ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
		}
		return hash_hmac( 'sha256', $ua, self::get_salt() );
	}

	/**
	 * Get client IP address.
	 */
	public static function get_user_ip() {
		$ip = '127.0.0.1';
		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return sanitize_text_field( $ip );
	}

	/**
	 * Initialize WordPress GDPR hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register personal data exporter.
	 */
	public static function register_exporter( $exporters ) {
		$exporters['tbsh-compliance-audit-logger'] = array(
			'exporter_friendly_name' => __( 'Compliance Audit Logs', 'tbsh-compliance-audit-logger' ),
			'callback'               => array( __CLASS__, 'personal_data_exporter' ),
		);
		return $exporters;
	}

	/**
	 * Register personal data eraser.
	 */
	public static function register_eraser( $erasers ) {
		$erasers['tbsh-compliance-audit-logger'] = array(
			'eraser_friendly_name' => __( 'Compliance Audit Logs Eraser', 'tbsh-compliance-audit-logger' ),
			'callback'             => array( __CLASS__, 'personal_data_eraser' ),
		);
		return $erasers;
	}

	/**
	 * Personal data exporter callback.
	 */
	public static function personal_data_exporter( $email_address, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$table_name = $wpdb->prefix . 'tbsh_cal_logs';
		$limit      = 100;
		$offset     = ( $page - 1 ) * $limit;

		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_name WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$user->ID,
			$limit,
			$offset
		) );

		$data_to_export = array();
		foreach ( $logs as $log ) {
			$data_to_export[] = array(
				'group_id'    => 'tbsh-cal-logs',
				'group_label' => __( 'Compliance Audit Logs', 'tbsh-compliance-audit-logger' ),
				'item_id'     => 'log-' . $log->id,
				'data'        => array(
					array(
						'name'  => __( 'Date', 'tbsh-compliance-audit-logger' ),
						'value' => $log->created_at,
					),
					array(
						'name'  => __( 'Event Type', 'tbsh-compliance-audit-logger' ),
						'value' => $log->event_type,
					),
					array(
						'name'  => __( 'Category', 'tbsh-compliance-audit-logger' ),
						'value' => $log->event_category,
					),
					array(
						'name'  => __( 'Message', 'tbsh-compliance-audit-logger' ),
						'value' => $log->event_message,
					),
				),
			);
		}

		return array(
			'data' => $data_to_export,
			'done' => count( $logs ) < $limit,
		);
	}

	/**
	 * Personal data eraser callback.
	 * Replaces usernames/user IDs and recalculates the hash chain.
	 */
	public static function personal_data_eraser( $email_address, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$table_name = $wpdb->prefix . 'tbsh_cal_logs';
		$limit      = 50; // Smaller batch for CPU intensive hash recalculation
		$offset     = ( $page - 1 ) * $limit;

		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_name WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
			$user->ID,
			$limit,
			$offset
		) );

		$items_removed = 0;
		if ( ! empty( $logs ) ) {
			foreach ( $logs as $log ) {
				// Anonymize user info
				$wpdb->update(
					$table_name,
					array(
						'user_id'  => 0,
						'username' => 'Anonymized User',
						'role'     => 'none',
					),
					array( 'id' => $log->id ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				$items_removed++;
			}

			// Since we updated entries, we need to rebuild the entire hash chain from the first modified ID to prevent integrity issues.
			$first_modified_id = $logs[0]->id;
			self::rebuild_hash_chain_from( $first_modified_id );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array( __( 'Anonymized personal details in compliance logs and updated the cryptographic integrity chain.', 'tbsh-compliance-audit-logger' ) ),
			'done'           => count( $logs ) < $limit,
		);
	}

	/**
	 * Rebuilds the hash chain starting from a specific ID.
	 */
	public static function rebuild_hash_chain_from( $start_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		// Lock table to avoid race conditions during rebuild.
		$wpdb->query( 'LOCK TABLES ' . $table_name . ' WRITE' );

		// Fetch all rows from start_id
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table_name WHERE id >= %d ORDER BY id ASC",
			$start_id
		) );

		foreach ( $rows as $row ) {
			// Fetch previous hash
			$previous_hash = '0000000000000000000000000000000000000000000000000000000000000000';
			if ( $row->id > 1 ) {
				$prev = $wpdb->get_var( $wpdb->prepare(
					"SELECT integrity_hash FROM $table_name WHERE id < %d ORDER BY id DESC LIMIT 1",
					$row->id
				) );
				if ( $prev ) {
					$previous_hash = $prev;
				}
			}

			// Calculate canonical hash.
			$canonical_string = implode( '|', array(
				$row->id,
				$row->event_uuid,
				$row->event_type,
				$row->event_category,
				$row->severity,
				$row->event_title,
				$row->event_message,
				$row->user_id,
				$row->site_id,
				$row->object_type,
				$row->object_id,
				$row->ip_hash,
				$row->user_agent_hash,
				$row->request_method,
				$row->request_uri,
				$row->metadata_json,
				$row->compliance_tags,
				$previous_hash,
				$row->created_at,
			) );

			$new_hash = hash( 'sha256', $canonical_string );

			$wpdb->update(
				$table_name,
				array(
					'previous_hash'  => $previous_hash,
					'integrity_hash' => $new_hash,
				),
				array( 'id' => $row->id )
			);
		}

		$wpdb->query( 'UNLOCK TABLES' );
	}
}
