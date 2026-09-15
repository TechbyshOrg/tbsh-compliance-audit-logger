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
				'args'                => array(
					'format'     => array( 'type' => 'string', 'enum' => array( 'csv', 'json' ), 'default' => 'csv' ),
					'date_start' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'date_end'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'category'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'severity'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'user_id'    => array( 'type' => 'integer' ),
					'search'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/exports/download', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_file' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
				'args'                => array(
					'file' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		) );

		register_rest_route( $this->namespace, '/exports/share', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_share_link' ),
				'permission_callback' => array( $this, 'check_export_permission' ),
				'args'                => array(
					'file' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
		) );

		// Public token download (no cookie auth) — gated by expiring token.
		register_rest_route( $this->namespace, '/exports/shared', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_shared' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
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
		BatchExporter::clean_old_exports();
		$exports = BatchExporter::get_available_exports();
		return $this->success( $exports );
	}

	/**
	 * Request a new log export file.
	 */
	public function create_item( $request ) {
		if ( get_transient( 'tbsh_cal_export_cooldown' ) ) {
			return $this->error(
				'tbsh_cal_rate_limited',
				__( 'Please wait before generating another export.', 'tbsh-compliance-audit-logger' ),
				429
			);
		}
		set_transient( 'tbsh_cal_export_cooldown', 1, 30 );

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
	 * Create an expiring read-only share link for an export file.
	 */
	public function create_share_link( $request ) {
		$filename = sanitize_text_field( $request->get_param( 'file' ) );

		if ( ! preg_match( '/^export_[a-zA-Z0-9]+\.(csv|json)$/', $filename ) ) {
			return $this->error( 'tbsh_cal_invalid_filename', __( 'Invalid filename.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		$filepath = BatchExporter::get_export_dir() . $filename;
		if ( ! file_exists( $filepath ) ) {
			return $this->error( 'tbsh_cal_file_not_found', __( 'File not found or expired.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$token = wp_generate_password( 32, false, false );
		set_transient(
			'tbsh_cal_share_' . $token,
			array(
				'file'    => $filename,
				'user_id' => get_current_user_id(),
			),
			2 * HOUR_IN_SECONDS
		);

		$url = rest_url( $this->namespace . '/exports/shared?token=' . rawurlencode( $token ) );

		return $this->success( array(
			'token'      => $token,
			'url'        => $url,
			'expires_in' => 2 * HOUR_IN_SECONDS,
			'message'    => __( 'Share link created. It expires in 2 hours.', 'tbsh-compliance-audit-logger' ),
		) );
	}

	/**
	 * Public download via expiring token.
	 */
	public function download_shared( $request ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) );
		$data  = get_transient( 'tbsh_cal_share_' . $token );

		if ( empty( $data ) || empty( $data['file'] ) ) {
			return $this->error( 'tbsh_cal_invalid_token', __( 'Share link is invalid or expired.', 'tbsh-compliance-audit-logger' ), 403 );
		}

		$filename = $data['file'];
		if ( ! preg_match( '/^export_[a-zA-Z0-9]+\.(csv|json)$/', $filename ) ) {
			return $this->error( 'tbsh_cal_invalid_filename', __( 'Invalid filename.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		return $this->serve_export_file( $filename );
	}

	/**
	 * Serve a capability-locked file download.
	 */
	public function download_file( $request ) {
		$filename = sanitize_text_field( $request->get_param( 'file' ) );

		if ( ! preg_match( '/^export_[a-zA-Z0-9]+\.(csv|json)$/', $filename ) ) {
			return $this->error( 'tbsh_cal_invalid_filename', __( 'Invalid filename.', 'tbsh-compliance-audit-logger' ), 400 );
		}

		return $this->serve_export_file( $filename );
	}

	/**
	 * Stream an export file to the browser.
	 *
	 * @param string $filename Basename.
	 * @return \WP_Error|void
	 */
	private function serve_export_file( $filename ) {
		$filepath = BatchExporter::get_export_dir() . $filename;
		$real_dir = realpath( BatchExporter::get_export_dir() );
		$real_file = realpath( $filepath );

		if ( ! $real_file || ! $real_dir || 0 !== strpos( $real_file, $real_dir ) ) {
			return $this->error( 'tbsh_cal_file_not_found', __( 'File not found or expired.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		if ( ! file_exists( $filepath ) ) {
			return $this->error( 'tbsh_cal_file_not_found', __( 'File not found or expired.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$parts = explode( '.', $filename );
		$ext   = end( $parts );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

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
