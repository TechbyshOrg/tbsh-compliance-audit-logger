<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Alerts\SiemForwarder;
use TBSHComplianceAuditLogger\Reporting\ComplianceDigest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance digest, SIEM forwarding and setup assistant endpoints.
 */
class AutomationController extends BaseController {

	/**
	 * Option holding the setup assistant state (pending|complete|dismissed).
	 */
	const SETUP_OPTION = 'tbsh_cal_setup_state';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/digest/preview', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'digest_preview' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
		) );
		register_rest_route( $this->namespace, '/digest/send', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'digest_send' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
		) );

		register_rest_route( $this->namespace, '/siem', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'siem_status' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
		) );
		register_rest_route( $this->namespace, '/siem/test', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'siem_test' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
		) );

		register_rest_route( $this->namespace, '/setup', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'setup_get' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'setup_set' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
				'args'                => array(
					'state' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'pending', 'complete', 'dismissed' ) ),
				),
			),
		) );
	}

	/**
	 * Digest preview HTML.
	 *
	 * @return \WP_REST_Response
	 */
	public function digest_preview() {
		return $this->success( array(
			'html'       => ComplianceDigest::preview(),
			'recipients' => ComplianceDigest::recipients( wp_parse_args( get_option( 'tbsh_cal_settings', array() ), SettingsController::defaults() ) ),
		) );
	}

	/**
	 * Send the digest now.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function digest_send() {
		$result = ComplianceDigest::send( 'manual' );
		return $result['ok']
			? $this->success( array( 'message' => $result['message'] ) )
			: $this->error( 'tbsh_cal_digest_failed', $result['message'], 400 );
	}

	/**
	 * SIEM forwarding status.
	 *
	 * @return \WP_REST_Response
	 */
	public function siem_status() {
		return $this->success( SiemForwarder::status() );
	}

	/**
	 * Send a SIEM test event.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function siem_test() {
		$result = SiemForwarder::send_test();
		return $result['ok']
			? $this->success( array( 'message' => $result['message'] ) )
			: $this->error( 'tbsh_cal_siem_failed', $result['message'], 400 );
	}

	/**
	 * Setup assistant state.
	 *
	 * @return \WP_REST_Response
	 */
	public function setup_get() {
		return $this->success( array( 'state' => self::setup_state() ) );
	}

	/**
	 * Update the setup assistant state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function setup_set( $request ) {
		$state = (string) $request->get_param( 'state' );
		update_option( self::SETUP_OPTION, $state, false );
		if ( 'complete' === $state ) {
			\TBSHComplianceAuditLogger\Logging\Logger::log(
				'setup_completed',
				'Configuration Management',
				'notice',
				__( 'Setup assistant completed', 'tbsh-compliance-audit-logger' ),
				__( 'The compliance setup assistant was completed and its recommended settings applied.', 'tbsh-compliance-audit-logger' ),
				array( 'object_type' => 'settings' )
			);
		}
		return $this->success( array( 'state' => $state ) );
	}

	/**
	 * Current setup state ("complete" for installs that predate the assistant).
	 *
	 * @return string
	 */
	public static function setup_state() {
		$state = get_option( self::SETUP_OPTION, 'complete' );
		return in_array( $state, array( 'pending', 'complete', 'dismissed' ), true ) ? $state : 'complete';
	}
}
