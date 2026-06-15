<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Dashboard\StatsManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Dashboard REST endpoints.
 */
class DashboardController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/dashboard', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
			),
		) );
	}

	/**
	 * Validate read logs capability.
	 */
	public function check_view_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	/**
	 * Get stats endpoint.
	 */
	public function get_stats( $request ) {
		$stats = StatsManager::get_dashboard_stats();
		return $this->success( $stats );
	}
}
