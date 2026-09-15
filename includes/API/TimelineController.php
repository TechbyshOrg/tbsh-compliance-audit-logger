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
				'args'                => array(
					'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'   => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
					'date_start' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'date_end'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'category'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'severity'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'user_id'    => array( 'type' => 'integer' ),
					'search'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
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
			'severity'   => sanitize_text_field( $request->get_param( 'severity' ) ),
			'user_id'    => intval( $request->get_param( 'user_id' ) ),
			'search'     => sanitize_text_field( $request->get_param( 'search' ) ),
			'page'       => intval( $request->get_param( 'page' ) ?: 1 ),
			'per_page'   => intval( $request->get_param( 'per_page' ) ?: 50 ),
		);

		$timeline = ForensicTimeline::get_timeline( $filters );
		return $this->success( $timeline );
	}
}
