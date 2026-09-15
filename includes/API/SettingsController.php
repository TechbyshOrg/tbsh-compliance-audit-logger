<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Helpers\CronScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Settings REST endpoints.
 */
class SettingsController extends BaseController {

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_logging'       => true,
			'min_severity'         => 'info',
			'anonymize_ips'        => true,
			'auto_evidence'        => true,
			'evidence_frequency'   => 'daily',
			'retain_logs'          => 0,
			'cleanup_on_uninstall' => 'keep',
			'email_alerts'         => false,
			'log_content_events'   => true,
			'log_media_events'     => true,
			'auto_core_checksum'   => false,
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/settings', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'args'                => array(
					'enable_logging'       => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'min_severity'         => array( 'type' => 'string', 'enum' => array( 'info', 'notice', 'warning', 'error', 'critical' ) ),
					'anonymize_ips'        => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'auto_evidence'        => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'evidence_frequency'   => array( 'type' => 'string', 'enum' => array( 'daily', 'weekly' ) ),
					'retain_logs'          => array( 'type' => 'integer', 'minimum' => 0 ),
					'cleanup_on_uninstall' => array( 'type' => 'string', 'enum' => array( 'keep', 'delete' ) ),
					'email_alerts'         => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'log_content_events'   => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'log_media_events'     => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'auto_core_checksum'   => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_read_permission() {
		return $this->check_permission( 'tbsh_cal_manage_settings' );
	}

	public function check_write_permission() {
		return $this->check_permission( 'tbsh_cal_manage_settings' );
	}

	/**
	 * Retrieve settings.
	 */
	public function get_settings( $request ) {
		$settings = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), self::defaults() );
		return $this->success( $settings );
	}

	/**
	 * Update settings (merge-on-write so omitted keys keep previous values).
	 */
	public function update_settings( $request ) {
		$old_settings = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), self::defaults() );
		$new_settings = $old_settings;

		$bool_keys = array( 'enable_logging', 'anonymize_ips', 'auto_evidence', 'email_alerts', 'log_content_events', 'log_media_events', 'auto_core_checksum' );
		foreach ( $bool_keys as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$new_settings[ $key ] = (bool) $request->get_param( $key );
			}
		}

		if ( null !== $request->get_param( 'min_severity' ) ) {
			$new_settings['min_severity'] = sanitize_key( $request->get_param( 'min_severity' ) );
		}
		if ( null !== $request->get_param( 'evidence_frequency' ) ) {
			$new_settings['evidence_frequency'] = sanitize_key( $request->get_param( 'evidence_frequency' ) );
		}
		if ( null !== $request->get_param( 'retain_logs' ) ) {
			$new_settings['retain_logs'] = max( 0, intval( $request->get_param( 'retain_logs' ) ) );
		}
		if ( null !== $request->get_param( 'cleanup_on_uninstall' ) ) {
			$new_settings['cleanup_on_uninstall'] = sanitize_key( $request->get_param( 'cleanup_on_uninstall' ) );
		}

		if ( ! in_array( $new_settings['min_severity'], array( 'info', 'notice', 'warning', 'error', 'critical' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid minimum severity level.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		if ( ! in_array( $new_settings['evidence_frequency'], array( 'daily', 'weekly' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid evidence snapshot frequency.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		if ( ! in_array( $new_settings['cleanup_on_uninstall'], array( 'keep', 'delete' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid uninstall cleanup option.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		/**
		 * Filter settings before save.
		 *
		 * @param array $new_settings Sanitized settings.
		 * @param array $old_settings Previous settings.
		 */
		$new_settings = apply_filters( 'tbsh_cal_settings', $new_settings, $old_settings );

		update_option( 'tbsh_cal_settings', $new_settings );

		// Always resync cron from the saved settings.
		CronScheduler::sync( $new_settings );

		return $this->success( array(
			'settings' => $new_settings,
			'message'  => __( 'Settings saved successfully.', 'tbsh-compliance-audit-logger' ),
		) );
	}
}
