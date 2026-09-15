<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Evidence\Vault;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Evidence Vault REST endpoints.
 */
class EvidenceController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/evidence', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'args'                => array(
					'title' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/evidence/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
				),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_view_permission() {
		return $this->check_permission( 'tbsh_cal_view_evidence' );
	}

	public function check_create_permission() {
		return $this->check_permission( 'tbsh_cal_verify_integrity' );
	}

	/**
	 * Get snapshots list (paginated, without heavy details).
	 */
	public function get_items( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_evidence';

		$page     = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_name ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, evidence_uuid, evidence_type, evidence_title, created_at
			 FROM %i
			 ORDER BY id DESC
			 LIMIT %d OFFSET %d",
			$table_name,
			$per_page,
			$offset
		) );

		$response = $this->success( array(
			'items'       => $results,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		) );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $per_page > 0 ? (int) ceil( $total / $per_page ) : 1 );

		return $response;
	}

	/**
	 * Trigger manual snapshot.
	 */
	public function create_item( $request ) {
		if ( get_transient( 'tbsh_cal_evidence_cooldown' ) ) {
			return $this->error(
				'tbsh_cal_rate_limited',
				__( 'Please wait a moment before capturing another snapshot.', 'tbsh-compliance-audit-logger' ),
				429
			);
		}
		set_transient( 'tbsh_cal_evidence_cooldown', 1, 30 );

		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$id    = Vault::capture_snapshot( 'manual', $title );

		if ( $id ) {
			return $this->success( array(
				'id'      => $id,
				'message' => __( 'System snapshot captured successfully.', 'tbsh-compliance-audit-logger' ),
			) );
		}

		return $this->error( 'tbsh_cal_capture_failed', __( 'Could not capture system snapshot.', 'tbsh-compliance-audit-logger' ), 500 );
	}

	/**
	 * Get single snapshot details.
	 */
	public function get_item( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_evidence';
		$id         = intval( $request['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $table_name, $id ) );

		if ( ! $row ) {
			return $this->error( 'tbsh_cal_not_found', __( 'Evidence snapshot not found.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$row->snapshot_data = json_decode( $row->snapshot_data, true );

		return $this->success( $row );
	}
}
