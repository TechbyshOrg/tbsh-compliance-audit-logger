<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for System Health Center REST endpoints.
 */
class HealthController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/health', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'check_health_permission' ),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_health_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	/**
	 * Get system health metrics.
	 */
	public function get_health( $request ) {
		global $wpdb;

		$table_logs      = $wpdb->prefix . 'tbsh_cal_logs';
		$table_evidence  = $wpdb->prefix . 'tbsh_cal_evidence';
		$table_integrity = $wpdb->prefix . 'tbsh_cal_integrity';

		// Counts.
		$log_count      = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs" ) );
		$evidence_count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_evidence" ) );

		// Error/Critical count.
		$recent_errors = intval( $wpdb->get_var(
			"SELECT COUNT(*) FROM $table_logs WHERE severity IN ('critical', 'error')"
		) );

		// Integrity.
		$integrity = ChainVerifier::get_latest_status();

		// Fetch DB Table Details.
		$table_health = array();
		$custom_tables = array( $table_logs, $table_evidence, $table_integrity );

		$total_raw_size = 0;
		foreach ( $custom_tables as $table ) {
			$status = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $table ) );
			if ( $status ) {
				$size = intval( $status->Data_length ) + intval( $status->Index_length );
				$total_raw_size += $size;
				$table_health[ basename( $table ) ] = array(
					'rows'       => intval( $status->Rows ),
					'size'       => size_format( $size ),
					'collation'  => $status->Collation,
					'engine'     => $status->Engine,
					'status'     => __( 'Healthy', 'tbsh-compliance-audit-logger' ),
				);
			} else {
				$table_health[ basename( $table ) ] = array(
					'rows'       => 0,
					'size'       => '0 B',
					'collation'  => '-',
					'engine'     => '-',
					'status'     => __( 'Missing / Error', 'tbsh-compliance-audit-logger' ),
				);
			}
		}

		return $this->success( array(
			'db_size'           => size_format( $total_raw_size ),
			'log_count'         => $log_count,
			'evidence_count'    => $evidence_count,
			'integrity_status'  => $integrity['status'],
			'last_verification' => $integrity['verification_date'],
			'recent_errors'     => $recent_errors,
			'table_health'      => $table_health,
			'environment'       => array(
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'db_version'  => $wpdb->db_version(),
				'multisite'   => is_multisite() ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' ),
			),
		) );
	}
}
