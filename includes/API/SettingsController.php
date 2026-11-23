<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\AI\AIAdapter;
use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Alerts\Channels;
use TBSHComplianceAuditLogger\Helpers\CronScheduler;
use TBSHComplianceAuditLogger\Helpers\Secrets;

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
		'auto_core_checksum',
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
			'auto_core_checksum'          => false,
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

		// Secrets: write-only, validated.
		$secrets = $request->get_param( 'secrets' );
		$updates = array();
		if ( is_array( $secrets ) ) {
			foreach ( $secrets as $key => $value ) {
				if ( ! in_array( $key, Secrets::KEYS, true ) || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}
				$value = trim( $value );
				if ( in_array( $key, array( 'slack_webhook', 'discord_webhook', 'teams_webhook', 'webhook_url' ), true ) && ( ! wp_http_validate_url( $value ) || 0 !== strpos( $value, 'https://' ) ) ) {
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
		);
		return $settings;
	}
}
