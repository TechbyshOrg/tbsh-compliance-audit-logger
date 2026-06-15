<?php
namespace TBSHComplianceAuditLogger\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base Controller for REST API endpoints.
 */
abstract class BaseController extends \WP_REST_Controller {

	/**
	 * Namespace for REST endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'tbsh-compliance-audit-logger/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {}

	/**
	 * Check capability permissions helper.
	 *
	 * @param string $cap Capability name.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	protected function check_permission( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error(
				'tbsh_cal_forbidden',
				__( 'You do not have permission to access this endpoint.', 'tbsh-compliance-audit-logger' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Return clean success JSON.
	 */
	protected function success( $data = array(), $status = 200 ) {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Return standard error JSON.
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
