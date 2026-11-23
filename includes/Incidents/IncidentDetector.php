<?php
namespace TBSHComplianceAuditLogger\Incidents;

use TBSHComplianceAuditLogger\Helpers\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens incidents from logged events.
 *
 * Subscribes to `tbsh_cal_event_logged` instead of re-hooking WordPress, so detection
 * reuses the events the tracker already records. Each rule is de-duplicated per
 * subject and time window with a detection key.
 */
class IncidentDetector {

	/**
	 * Options whose change opens a configuration incident.
	 */
	const WATCHED_OPTIONS = array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_event_logged', array( __CLASS__, 'on_event' ) );
	}

	/**
	 * Rule definitions: event type => rule config.
	 *
	 * @return array
	 */
	public static function rules() {
		return array(
			'excessive_failed_logins' => array(
				'rule'     => 'brute_force',
				'setting'  => 'detect_brute_force',
				'severity' => 'critical',
				'window'   => HOUR_IN_SECONDS,
				'title'    => __( 'Brute-force login attack', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'NIS2 Art. 21(2)(b), ISO 27001 A.5.25, SOC 2 CC7.3',
			),
			'privilege_escalation'    => array(
				'rule'     => 'privilege_escalation',
				'setting'  => 'detect_privilege_escalation',
				'severity' => 'critical',
				'window'   => DAY_IN_SECONDS,
				'title'    => __( 'Administrator privileges granted', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'NIS2 Art. 21(2)(i), ISO 27001 A.5.18, SOC 2 CC6.3',
			),
			'option_update'           => array(
				'rule'     => 'config_change',
				'setting'  => 'detect_config_change',
				'severity' => 'error',
				'window'   => HOUR_IN_SECONDS,
				'title'    => __( 'Critical site setting changed', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'ISO 27001 A.8.9, SOC 2 CC8.1, DORA Art. 9',
			),
			'core_checksum_check'     => array(
				'rule'     => 'core_integrity',
				'setting'  => 'detect_integrity',
				'severity' => 'critical',
				'window'   => DAY_IN_SECONDS,
				'title'    => __( 'WordPress core files modified', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'ISO 27001 A.8.7, SOC 2 CC7.1, NIS2 Art. 21(2)(e)',
			),
			'chain_compromised'       => array(
				'rule'     => 'log_tampering',
				'setting'  => 'detect_integrity',
				'severity' => 'critical',
				'window'   => DAY_IN_SECONDS,
				'title'    => __( 'Audit log tampering detected', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'ISO 27001 A.8.15, SOC 2 CC7.2, DORA Art. 12',
			),
			'vault_sync_failed'       => array(
				'rule'     => 'vault_failure',
				'setting'  => 'detect_vault_failure',
				'severity' => 'error',
				'window'   => DAY_IN_SECONDS,
				'title'    => __( 'Off-site evidence backup failing', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'DORA Art. 12, ISO 27001 A.8.13',
			),
			'behavior_anomaly'        => array(
				'rule'     => 'anomaly',
				'setting'  => 'anomaly_detection',
				'severity' => 'error',
				'window'   => DAY_IN_SECONDS,
				'title'    => __( 'High-risk user behaviour anomaly', 'tbsh-compliance-audit-logger' ),
				'mapping'  => 'ISO 27001 A.8.16, SOC 2 CC7.2',
			),
		);
	}

	/**
	 * Handle a queued log entry.
	 *
	 * @param array $entry Log entry.
	 */
	public static function on_event( $entry ) {
		$rules = self::rules();
		$type  = $entry['event_type'] ?? '';
		if ( ! isset( $rules[ $type ] ) ) {
			return;
		}

		$rule     = $rules[ $type ];
		$settings = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), \TBSHComplianceAuditLogger\API\SettingsController::defaults() );
		if ( empty( $settings[ $rule['setting'] ] ) ) {
			return;
		}

		$meta    = Repository::json( $entry['metadata_json'] ?? '' );
		$subject = '';

		switch ( $type ) {
			case 'excessive_failed_logins':
				$subject = (string) ( $entry['username'] ?? '' );
				break;
			case 'privilege_escalation':
				$subject = (string) ( $entry['object_id'] ?? '' );
				break;
			case 'option_update':
				$subject = (string) ( $meta['option'] ?? $entry['object_id'] ?? '' );
				if ( ! in_array( $subject, self::WATCHED_OPTIONS, true ) ) {
					return;
				}
				break;
			case 'core_checksum_check':
				if ( 'fail' !== ( $meta['status'] ?? '' ) ) {
					return;
				}
				$subject = 'core';
				break;
			case 'chain_compromised':
				$subject = 'chain';
				break;
			case 'vault_sync_failed':
				$subject = (string) ( $entry['object_id'] ?? '' );
				break;
			case 'behavior_anomaly':
				if ( (int) ( $meta['score'] ?? 0 ) < 85 ) {
					return;
				}
				$subject = (string) ( $entry['object_id'] ?? '' );
				break;
		}

		$key = $rule['rule'] . ':' . md5( $subject ) . ':' . (int) floor( time() / $rule['window'] );
		if ( IncidentRepository::exists_by_key( $key ) ) {
			return;
		}

		IncidentRepository::create(
			array(
				'event_title'         => $rule['title'],
				'description'         => (string) ( $entry['event_message'] ?? '' ),
				'severity'            => $rule['severity'],
				'incident_type'       => $rule['rule'],
				'compliance_mappings' => $rule['mapping'],
				'detection_key'       => $key,
				'affected_systems'    => self::affected( $type, $subject ),
				'detected_at'         => $entry['created_at'] ?? '',
				'source'              => 'automatic: ' . $type,
				'owner_id'            => 0,
			)
		);
	}

	/**
	 * Short description of what is affected.
	 *
	 * @param string $type    Event type.
	 * @param string $subject Subject.
	 * @return string
	 */
	private static function affected( $type, $subject ) {
		switch ( $type ) {
			case 'excessive_failed_logins':
				/* translators: %s: username */
				return sprintf( __( 'Login endpoint; targeted account "%s"', 'tbsh-compliance-audit-logger' ), $subject );
			case 'privilege_escalation':
			case 'behavior_anomaly':
				/* translators: %s: user id */
				return sprintf( __( 'User account #%s', 'tbsh-compliance-audit-logger' ), $subject );
			case 'option_update':
				/* translators: %s: option name */
				return sprintf( __( 'Site setting "%s"', 'tbsh-compliance-audit-logger' ), $subject );
			case 'core_checksum_check':
				return __( 'WordPress core files', 'tbsh-compliance-audit-logger' );
			case 'chain_compromised':
				return __( 'Audit log database table', 'tbsh-compliance-audit-logger' );
			case 'vault_sync_failed':
				/* translators: %s: vault id */
				return sprintf( __( 'Off-site vault #%s', 'tbsh-compliance-audit-logger' ), $subject );
		}
		return '';
	}
}
