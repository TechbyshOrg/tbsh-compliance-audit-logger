<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Export\BatchExporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Exporter REST endpoints.
 */
class ExportController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/exports', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/exports/download', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_export_permission() {
		return $this->check_permission( 'tbsh_cal_export_data' );
	}

	/**
	 * Get list of generated export files.
	 */
	public function get_items( $request ) {
		// Clean up files older than 2 hours.
		BatchExporter::clean_old_exports();

		$exports = BatchExporter::get_available_exports();
		return $this->success( $exports );
	}

	/**
	 * Request a new log export file.
	 */
	public function create_item( $request ) {
		$filters = array(
			'date_start' => sanitize_text_field( $request->get_param( 'date_start' ) ),
			'date_end'   => sanitize_text_field( $request->get_param( 'date_end' ) ),
			'category'   => sanitize_text_field( $request->get_param( 'category' ) ),
			'severity'   => sanitize_text_field( $request->get_param( 'severity' ) ),
			'user_id'    => intval( $request->get_param( 'user_id' ) ),
			'search'     => sanitize_text_field( $request->get_param( 'search' ) ),
		);

		$format = sanitize_key( $request->get_param( 'format' ) ?: 'csv' );
		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			$format = 'csv';
		}

		$filename = BatchExporter::generate_export( $filters, $format );

		if ( $filename ) {
			return $this->success( array(
				'filename' => $filename,
				'message'  => __( 'Export generated successfully.', 'tbsh-compliance-audit-logger' ),
			) );
		}

		return $this->error( 'tbsh_cal_export_failed', __( 'Could not generate export file.', 'tbsh-compliance-audit-logger' ), 500 );
	}

	/**
	 * Serve a capability-locked file download.
	 */
	public function download_file( $request ) {
		$filename = sanitize_text_field( $request->get_param( 'file' ) );

		// 1. Enforce strict regex file name pattern to prevent directory traversal.
		if ( ! preg_match( '/^export_[a-zA-Z0-9]+\.(csv|json)$/', $filename ) ) {
			return $this->error( 'tbsh_cal_invalid_filename', __( 'Invalid filename.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		$filepath = BatchExporter::get_export_dir() . $filename;

		// 2. Verify file exists.
		if ( ! file_exists( $filepath ) ) {
			return $this->error( 'tbsh_cal_file_not_found', __( 'File not found or expired.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$parts = explode( '.', $filename );
		$ext   = end( $parts );

		// 3. Clear output buffering to ensure binary transmission integrity.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// 4. Send headers.
		header( 'Content-Description: File Transfer' );
		if ( 'csv' === $ext ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
		} else {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $filepath ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $filepath );
		exit;
	}
}
