<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Timeline\ForensicTimeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Forensic Timeline REST endpoints.
 */
class TimelineController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/timeline', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_timeline' ),
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
	 * Get timeline entries.
	 */
	public function get_timeline( $request ) {
		$filters = array(
			'date_start' => sanitize_text_field( $request->get_param( 'date_start' ) ),
			'date_end'   => sanitize_text_field( $request->get_param( 'date_end' ) ),
			'category'   => sanitize_text_field( $request->get_param( 'category' ) ),
			'user_id'    => intval( $request->get_param( 'user_id' ) ),
			'search'     => sanitize_text_field( $request->get_param( 'search' ) ),
		);

		$timeline = ForensicTimeline::get_timeline( $filters );
		return $this->success( $timeline );
	}
}
