<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Integrity\CoreChecksum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for WordPress core checksum verification.
 */
class ChecksumController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/checksums', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_latest' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_check' ),
				'permission_callback' => array( $this, 'check_verify_permission' ),
			),
		) );
	}

	/**
	 * View permission.
	 */
	public function check_view_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	/**
	 * Run verification permission.
	 */
	public function check_verify_permission() {
		return $this->check_permission( 'tbsh_cal_verify_integrity' );
	}

	/**
	 * Return last result.
	 */
	public function get_latest( $request ) {
		return $this->success( CoreChecksum::get_latest() );
	}

	/**
	 * Run a new check (rate-limited).
	 */
	public function run_check( $request ) {
		if ( get_transient( 'tbsh_cal_checksum_cooldown' ) ) {
			return $this->error(
				'tbsh_cal_rate_limited',
				__( 'Please wait a minute before running another checksum scan.', 'tbsh-compliance-audit-logger' ),
				429
			);
		}
		set_transient( 'tbsh_cal_checksum_cooldown', 1, MINUTE_IN_SECONDS );

		$result = CoreChecksum::verify();
		return $this->success( $result );
	}
}
