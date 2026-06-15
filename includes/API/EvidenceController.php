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
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/evidence/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
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
		return $this->check_permission( 'tbsh_cal_verify_integrity' ); // Requires higher capability
	}

	/**
	 * Get snapshots list (without heavy details).
	 */
	public function get_items( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_evidence';

		$results = $wpdb->get_results(
			"SELECT id, evidence_uuid, evidence_type, evidence_title, created_at 
			 FROM $table_name 
			 ORDER BY id DESC"
		);

		return $this->success( $results );
	}

	/**
	 * Trigger manual snapshot.
	 */
	public function create_item( $request ) {
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

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ) );

		if ( ! $row ) {
			return $this->error( 'tbsh_cal_not_found', __( 'Evidence snapshot not found.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$row->snapshot_data = json_decode( $row->snapshot_data, true );

		return $this->success( $row );
	}
}
