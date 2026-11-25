<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\AI\AIAdapter;
use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Alerts\Channels;
use TBSHComplianceAuditLogger\Helpers\CronScheduler;
use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Alerts\SiemForwarder;
use TBSHComplianceAuditLogger\Logging\Exclusions;
use TBSHComplianceAuditLogger\Reporting\ComplianceDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings (tbsh_cal_settings) and encrypted secrets (tbsh_cal_secrets).
 */
class SettingsController extends BaseController {

	/**
	 * Boolean settings.
	 */
	const BOOL_KEYS = array(
		'enable_logging',
		'anonymize_ips',
		'auto_evidence',
		'log_content_events',
		'log_media_events',
		'log_site_structure_events',
		'log_comment_events',
		'log_taxonomy_events',
		'log_woocommerce_events',
		'log_ability_events',
		'auto_core_checksum',
		'auto_file_integrity',
		'digest_attach_report',
		'digest_skip_empty',
		'siem_enabled',
		'email_alerts',
		'slack_enabled',
		'discord_enabled',
		'teams_enabled',
		'telegram_enabled',
		'webhook_enabled',
		'detect_brute_force',
		'detect_privilege_escalation',
		'detect_config_change',
		'detect_integrity',
		'detect_vault_failure',
		'anomaly_detection',
		'archive_before_purge',
		'vault_receiver_enabled',
	);

	/**
	 * Non-negative integer settings (days; 0 = keep forever).
	 */
	const INT_KEYS = array( 'retain_logs', 'retain_incidents', 'retain_evidence', 'retain_alert_log' );

	/**
	 * Compliance frameworks the setup assistant can record.
	 */
	const FRAMEWORKS = array( 'nis2', 'dora', 'iso27001', 'soc2', 'pci_dss', 'gdpr' );

	/**
	 * Enumerated settings.
	 *
	 * @return array
	 */
	private static function enums() {
		$severities = array( 'info', 'notice', 'warning', 'error', 'critical' );
		return array(
			'min_severity'         => $severities,
			'alert_min_severity'   => $severities,
			'evidence_frequency'   => array( 'daily', 'weekly' ),
			'cleanup_on_uninstall' => array( 'keep', 'delete' ),
			'ai_provider'          => array_merge( array( 'none' ), array_keys( AIAdapter::PROVIDERS ) ),
			'digest_frequency'     => ComplianceDigest::FREQUENCIES,
			'siem_transport'       => SiemForwarder::TRANSPORTS,
			'siem_min_severity'    => $severities,
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_logging'              => true,
			'min_severity'                => 'info',
			'anonymize_ips'               => true,
			'auto_evidence'               => true,
			'evidence_frequency'          => 'daily',
			'retain_logs'                 => 0,
			'cleanup_on_uninstall'        => 'keep',
			'log_content_events'          => true,
			'log_media_events'            => true,
			'log_site_structure_events'   => true,
			'log_comment_events'          => true,
			'log_taxonomy_events'         => true,
			'log_woocommerce_events'      => true,
			'log_ability_events'          => true,
			'auto_core_checksum'          => false,
			'auto_file_integrity'         => false,
			// Exclusions (never applied to error/critical or protected events).
			'exclude_users'               => '',
			'exclude_roles'               => array(),
			'exclude_event_types'         => '',
			// Compliance digest.
			'digest_frequency'            => 'off',
			'digest_recipients'           => '',
			'digest_attach_report'        => true,
			'digest_skip_empty'           => false,
			// SIEM forwarding.
			'siem_enabled'                => false,
			'siem_transport'              => 'https',
			'siem_min_severity'           => 'info',
			'siem_host'                   => '',
			'siem_port'                   => 6514,
			// Frameworks chosen in the setup assistant.
			'frameworks'                  => array(),
			// Alerts.
			'email_alerts'                => false,
			'alert_emails'                => '',
			'alert_min_severity'          => 'error',
			'slack_enabled'               => false,
			'discord_enabled'             => false,
			'teams_enabled'               => false,
			'telegram_enabled'            => false,
			'telegram_chat_id'            => '',
			'webhook_enabled'             => false,
			// Detection.
			'detect_brute_force'          => true,
			'detect_privilege_escalation' => true,
			'detect_config_change'        => true,
			'detect_integrity'            => true,
			'detect_vault_failure'        => true,
			'anomaly_detection'           => false,
			// AI (off by default).
			'ai_provider'                 => 'none',
			'ai_model'                    => '',
			// Retention.
			'retain_incidents'            => 0,
			'retain_evidence'             => 0,
			'retain_alert_log'            => 180,
			'archive_before_purge'        => true,
			// Vault receiver.
			'vault_receiver_enabled'      => false,
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$args = array();
		foreach ( self::BOOL_KEYS as $key ) {
			$args[ $key ] = array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' );
		}
		foreach ( self::INT_KEYS as $key ) {
			$args[ $key ] = array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 36500 );
		}
		foreach ( self::enums() as $key => $values ) {
			$args[ $key ] = array( 'type' => 'string', 'enum' => $values );
		}
		$args['alert_emails']     = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' );
		$args['telegram_chat_id'] = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		$args['ai_model']         = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		$args['exclude_users']       = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' );
		$args['exclude_event_types'] = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' );
		$args['exclude_roles']       = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );
		$args['digest_recipients']   = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' );
		$args['siem_host']           = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
		$args['siem_port']           = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 65535 );
		$args['frameworks']          = array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => self::FRAMEWORKS ) );
		$args['secrets']          = array( 'type' => 'object', 'description' => 'Write-only secret values; empty strings are ignored.' );
		$args['clear_secrets']    = array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => Secrets::KEYS ) );

		register_rest_route( $this->namespace, '/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
				'args'                => $args,
			),
		) );

		register_rest_route( $this->namespace, '/alerts/test', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_alert' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_alerts' ),
				'args'                => array(
					'channel' => array( 'type' => 'string', 'required' => true, 'enum' => Channels::ALL ),
				),
			),
		) );
	}

	/**
	 * Settings with masked secrets.
	 */
	public function get_settings( $request ) {
		return $this->success( self::payload() );
	}

	/**
	 * Merge-on-write update: omitted keys keep their previous values.
	 */
	public function update_settings( $request ) {
		$old = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), self::defaults() );
		$new = $old;

		foreach ( self::BOOL_KEYS as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$new[ $key ] = (bool) $request->get_param( $key );
			}
		}
		foreach ( self::INT_KEYS as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$new[ $key ] = max( 0, min( 36500, (int) $request->get_param( $key ) ) );
			}
		}
		foreach ( self::enums() as $key => $values ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				if ( ! in_array( $value, $values, true ) ) {
					/* translators: %s: setting key */
					return $this->error( 'tbsh_cal_invalid_setting', sprintf( __( 'Invalid value for %s.', 'tbsh-compliance-audit-logger' ), $key ), 400 );
				}
				$new[ $key ] = $value;
			}
		}

		if ( null !== $request->get_param( 'alert_emails' ) ) {
			$emails = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $request->get_param( 'alert_emails' ) ) ) );
			$bad    = array_filter( $emails, function ( $e ) {
				return ! is_email( $e );
			} );
			if ( $bad ) {
				/* translators: %s: invalid addresses */
				return $this->error( 'tbsh_cal_invalid_setting', sprintf( __( 'Invalid email address: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $bad ) ), 400 );
			}
			$new['alert_emails'] = implode( ', ', array_slice( $emails, 0, 20 ) );
		}
		if ( null !== $request->get_param( 'telegram_chat_id' ) ) {
			$new['telegram_chat_id'] = preg_replace( '/[^0-9@A-Za-z_\-]/', '', (string) $request->get_param( 'telegram_chat_id' ) );
		}
		if ( null !== $request->get_param( 'ai_model' ) ) {
			$new['ai_model'] = preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) $request->get_param( 'ai_model' ) );
		}

		// Logging exclusions.
		$exclusion_input = array();
		foreach ( array( 'exclude_users', 'exclude_roles', 'exclude_event_types' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$exclusion_input[ $key ] = $request->get_param( $key );
			}
		}
		if ( $exclusion_input ) {
			$exclusions = Exclusions::sanitize( $exclusion_input );
			if ( is_wp_error( $exclusions ) ) {
				return $exclusions;
			}
			$new = array_merge( $new, $exclusions );
		}

		// Compliance digest recipients.
		if ( null !== $request->get_param( 'digest_recipients' ) ) {
			$emails = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $request->get_param( 'digest_recipients' ) ) ) );
			$bad    = array_filter( $emails, function ( $e ) {
				return ! is_email( $e );
			} );
			if ( $bad ) {
				/* translators: %s: invalid addresses */
				return $this->error( 'tbsh_cal_invalid_setting', sprintf( __( 'Invalid email address: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $bad ) ), 400 );
			}
			$new['digest_recipients'] = implode( ', ', array_slice( $emails, 0, 20 ) );
		}

		// SIEM syslog destination.
		if ( null !== $request->get_param( 'siem_host' ) ) {
			$host = trim( (string) $request->get_param( 'siem_host' ) );
			if ( '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host ) ) {
				return $this->error( 'tbsh_cal_invalid_setting', __( 'The syslog host must be a host name or IP address.', 'tbsh-compliance-audit-logger' ), 400 );
			}
			$new['siem_host'] = strtolower( $host );
		}
		if ( null !== $request->get_param( 'siem_port' ) ) {
			$new['siem_port'] = max( 1, min( 65535, (int) $request->get_param( 'siem_port' ) ) );
		}

		if ( null !== $request->get_param( 'frameworks' ) ) {
			$new['frameworks'] = array_values( array_unique( array_intersect( (array) $request->get_param( 'frameworks' ), self::FRAMEWORKS ) ) );
		}

		// Secrets: write-only, validated.
		$secrets = $request->get_param( 'secrets' );
		$updates = array();
		if ( is_array( $secrets ) ) {
			foreach ( $secrets as $key => $value ) {
				if ( ! in_array( $key, Secrets::KEYS, true ) || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}
				$value = trim( $value );
				if ( in_array( $key, array( 'slack_webhook', 'discord_webhook', 'teams_webhook', 'webhook_url', 'siem_url' ), true ) && ( ! wp_http_validate_url( $value ) || 0 !== strpos( $value, 'https://' ) ) ) {
					/* translators: %s: secret key */
					return $this->error( 'tbsh_cal_invalid_setting', sprintf( __( '%s must be a public https URL.', 'tbsh-compliance-audit-logger' ), $key ), 400 );
				}
				if ( 'ai_endpoint' === $key && ! preg_match( '#^https?://#', $value ) ) {
					return $this->error( 'tbsh_cal_invalid_setting', __( 'The AI endpoint must be an http(s) URL.', 'tbsh-compliance-audit-logger' ), 400 );
				}
				$updates[ $key ] = $value;
			}
		}
		$clear = $request->get_param( 'clear_secrets' );
		if ( is_array( $clear ) ) {
			foreach ( $clear as $key ) {
				if ( in_array( $key, Secrets::KEYS, true ) ) {
					$updates[ $key ] = '';
				}
			}
		}

		/**
		 * Filter settings before save.
		 *
		 * @param array $new Sanitized settings.
		 * @param array $old Previous settings.
		 */
		$new = apply_filters( 'tbsh_cal_settings', $new, $old );

		update_option( 'tbsh_cal_settings', $new );
		if ( $updates ) {
			Secrets::set_many( $updates );
			\TBSHComplianceAuditLogger\Logging\Logger::log(
				'secrets_updated',
				'Configuration Management',
				'notice',
				__( 'Integration credentials changed', 'tbsh-compliance-audit-logger' ),
				/* translators: %s: secret names */
				sprintf( __( 'Updated credentials: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', array_keys( $updates ) ) ),
				array( 'object_type' => 'settings', 'metadata' => array( 'keys' => array_keys( $updates ) ) )
			);
		}

		CronScheduler::sync( $new );
		\TBSHComplianceAuditLogger\Vendors\SupplyChain::flush();
		ComplianceDigest::on_settings_saved( $old, $new );
		SiemForwarder::on_settings_saved( $old, $new );

		$payload            = self::payload();
		$payload['message'] = __( 'Settings saved successfully.', 'tbsh-compliance-audit-logger' );
		return $this->success( $payload );
	}

	/**
	 * Send a test alert.
	 */
	public function test_alert( $request ) {
		$result = AlertManager::send_test( $request->get_param( 'channel' ) );
		return $result['ok']
			? $this->success( array( 'ok' => true, 'message' => __( 'Test alert delivered.', 'tbsh-compliance-audit-logger' ) ) )
			/* translators: %s: error */
			: $this->error( 'tbsh_cal_alert_failed', sprintf( __( 'Test alert failed: %s', 'tbsh-compliance-audit-logger' ), $result['message'] ), 400 );
	}

	/**
	 * Settings + masked secrets + capability hints for the UI.
	 *
	 * @return array
	 */
	public static function payload() {
		$settings            = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), self::defaults() );
		$settings['secrets'] = Secrets::masked();
		$settings['meta']    = array(
			'ai_providers'       => array_map( function ( $p ) {
				return array( 'label' => $p['label'], 'default_model' => $p['model'] );
			}, AIAdapter::PROVIDERS ),
			'admin_email'        => get_option( 'admin_email' ),
			'receiver_url'       => rest_url( 'tbsh-compliance-audit-logger/v1/vault-receiver' ),
			'dedicated_key'      => \TBSHComplianceAuditLogger\Helpers\Crypto::has_dedicated_key(),
			'roles'              => array_map( 'translate_user_role', wp_roles()->get_names() ),
			'protected_events'   => Exclusions::PROTECTED_EVENTS,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
			'digest_last_sent'   => ComplianceDigest::state()['last_sent'] ?? null,
			'setup_state'        => AutomationController::setup_state(),
		);
		return $settings;
	}
}
