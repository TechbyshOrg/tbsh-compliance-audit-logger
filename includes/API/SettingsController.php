<?php
namespace TBSHComplianceAuditLogger\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Settings REST endpoints.
 */
class SettingsController extends BaseController {

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
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_read_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	public function check_write_permission() {
		return $this->check_permission( 'tbsh_cal_manage_settings' );
	}

	/**
	 * Retrieve settings.
	 */
	public function get_settings( $request ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		return $this->success( $settings );
	}

	/**
	 * Update settings.
	 */
	public function update_settings( $request ) {
		$old_settings = get_option( 'tbsh_cal_settings', array() );

		// Validate & Sanitize.
		$new_settings = array(
			'enable_logging'      => (bool) $request->get_param( 'enable_logging' ),
			'min_severity'        => sanitize_key( $request->get_param( 'min_severity' ) ?: 'info' ),
			'anonymize_ips'       => (bool) $request->get_param( 'anonymize_ips' ),
			'auto_evidence'       => (bool) $request->get_param( 'auto_evidence' ),
			'evidence_frequency'  => sanitize_key( $request->get_param( 'evidence_frequency' ) ?: 'daily' ),
			'retain_logs'         => max( 0, intval( $request->get_param( 'retain_logs' ) ) ),
			'cleanup_on_uninstall'=> sanitize_key( $request->get_param( 'cleanup_on_uninstall' ) ?: 'keep' ),
		);

		// Validate options range.
		if ( ! in_array( $new_settings['min_severity'], array( 'info', 'notice', 'warning', 'error', 'critical' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid minimum severity level.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		if ( ! in_array( $new_settings['evidence_frequency'], array( 'daily', 'weekly' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid evidence snapshot frequency.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		if ( ! in_array( $new_settings['cleanup_on_uninstall'], array( 'keep', 'delete' ), true ) ) {
			return $this->error( 'tbsh_cal_invalid_setting', __( 'Invalid uninstall cleanup option.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		update_option( 'tbsh_cal_settings', $new_settings );

		// Adjust scheduled cron if frequency changed.
		if ( $old_settings['evidence_frequency'] !== $new_settings['evidence_frequency'] ) {
			wp_clear_scheduled_hook( 'tbsh_cal_cron_job' );
			$recurrence = 'daily';
			if ( 'weekly' === $new_settings['evidence_frequency'] ) {
				$recurrence = 'weekly';
			}
			wp_schedule_event( time(), $recurrence, 'tbsh_cal_cron_job' );
		}

		return $this->success( array(
			'settings' => $new_settings,
			'message'  => __( 'Settings saved successfully.', 'tbsh-compliance-audit-logger' ),
		) );
	}
}
