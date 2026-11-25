<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Integrity\ExtensionIntegrity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin, theme and uploads file integrity scans.
 */
class FileIntegrityController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/file-integrity', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => $this->cap( 'tbsh_cal_view_logs' ),
			),
		) );

		register_rest_route( $this->namespace, '/file-integrity/scan', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => $this->cap( 'tbsh_cal_verify_integrity' ),
				'args'                => array(
					'step' => array( 'type' => 'string', 'enum' => array( 'start', 'continue' ), 'default' => 'continue' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/file-integrity/baseline', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept_baseline' ),
				'permission_callback' => $this->cap( 'tbsh_cal_verify_integrity' ),
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'plugin', 'theme', 'mu-plugins' ) ),
					'id'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );
	}

	/**
	 * Last result and progress.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		return $this->success( ExtensionIntegrity::status() );
	}

	/**
	 * Start or continue a scan; each call processes up to ~15 seconds of work.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function scan( $request ) {
		if ( 'start' === $request->get_param( 'step' ) ) {
			ExtensionIntegrity::start( 'manual' );
		}
		return $this->success( ExtensionIntegrity::process( 15 ) );
	}

	/**
	 * Accept current files as a component's baseline: one step of about 15 seconds.
	 * Call again while `accept.done` is false.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept_baseline( $request ) {
		$result = ExtensionIntegrity::accept( (string) $request->get_param( 'type' ), (string) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$status           = ExtensionIntegrity::status();
		$status['accept'] = $result;
		if ( $result['done'] ) {
			$status['message'] = __( 'Baseline updated. Future scans compare against the current files.', 'tbsh-compliance-audit-logger' );
		}
		return $this->success( $status );
	}
}
