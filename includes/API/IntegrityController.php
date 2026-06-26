<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Integrity Center REST endpoints.
 */
class IntegrityController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/integrity', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_verification' ),
				'permission_callback' => array( $this, 'check_verify_permission' ),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_view_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	public function check_verify_permission() {
		return $this->check_permission( 'tbsh_cal_verify_integrity' );
	}

	/**
	 * Get status and history.
	 */
	public function get_status( $request ) {
		global $wpdb;
		$table_integrity = $wpdb->prefix . 'tbsh_cal_integrity';

		$latest  = ChainVerifier::get_latest_status();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$history = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, verification_date, status, issues_found 
			 FROM %i 
			 ORDER BY id DESC 
			 LIMIT 20",
			$table_integrity
		) );

		return $this->success( array(
			'latest'  => $latest,
			'history' => $history,
		) );
	}

	/**
	 * Run integrity verification check.
	 */
	public function run_verification( $request ) {
		$result = ChainVerifier::verify_chain();
		return $this->success( $result );
	}
}
