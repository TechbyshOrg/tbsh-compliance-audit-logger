<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Compliance\AuditorAccess;

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
	 * Read-only capabilities are also granted to a validated auditor link; the
	 * auditor gate (AuditorAccess::gate) has already restricted such requests to an
	 * allowlist of GET routes before this runs.
	 *
	 * @param string $cap Capability name.
	 * @return bool|\WP_Error True if authorized, WP_Error otherwise.
	 */
	protected function check_permission( $cap ) {
		if ( current_user_can( $cap ) ) {
			return true;
		}

		if ( AuditorAccess::is_active() && in_array( $cap, AuditorAccess::READ_CAPS, true ) ) {
			return true;
		}

		return new \WP_Error(
			'tbsh_cal_forbidden',
			__( 'You do not have permission to access this endpoint.', 'tbsh-compliance-audit-logger' ),
			array( 'status' => rest_authorization_required_code() )
		);
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

	/**
	 * Convert a WP_Error-or-value result into a REST response.
	 *
	 * @param mixed $result Result.
	 * @param int   $status Success status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function respond( $result, $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}
		return $this->success( $result, $status );
	}

	/**
	 * Standard pagination args for collection routes.
	 *
	 * @return array
	 */
	protected function pagination_args() {
		return array(
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
			'search'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
		);
	}

	/**
	 * ID path arg definition.
	 *
	 * @return array
	 */
	protected function id_arg() {
		return array(
			'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
		);
	}

	/**
	 * Build a permission callback for a capability.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	protected function cap( $cap ) {
		return function () use ( $cap ) {
			return $this->check_permission( $cap );
		};
	}
}
