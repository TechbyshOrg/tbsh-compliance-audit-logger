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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$log_count      = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_logs ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$evidence_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table_evidence ) ) );

		// Error/Critical count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_errors = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE severity IN ('critical', 'error')",
			$table_logs
		) ) );

		// Integrity.
		$integrity = ChainVerifier::get_latest_status();

		// Fetch DB Table Details.
		$table_health = array();
		$custom_tables = array_map( array( \TBSHComplianceAuditLogger\Database\Schema::class, 'table' ), array_keys( \TBSHComplianceAuditLogger\Database\Schema::TABLES ) );

		$total_raw_size = 0;
		foreach ( $custom_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$checksum = \TBSHComplianceAuditLogger\Integrity\CoreChecksum::get_latest();
		$next_cron = wp_next_scheduled( 'tbsh_cal_cron_job' );

		return $this->success( array(
			'db_size'           => size_format( $total_raw_size ),
			'log_count'         => $log_count,
			'evidence_count'    => $evidence_count,
			'integrity_status'  => $integrity['status'],
			'last_verification' => $integrity['verification_date'],
			'recent_errors'     => $recent_errors,
			'table_health'      => $table_health,
			'cron_next_run'     => $next_cron ? gmdate( 'Y-m-d H:i:s', $next_cron ) : null,
			'core_checksum'     => $checksum,
			'platform_checks'   => self::platform_checks(),
			'environment'       => array(
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'db_version'  => $wpdb->db_version(),
				'multisite'   => is_multisite() ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' ),
			),
		) );
	}

	/**
	 * Environment checks relevant to the plugin's features.
	 *
	 * @return array List of { key, label, status: pass|warn|fail, detail }.
	 */
	private static function platform_checks() {
		$checks = array();
		$add    = function ( $key, $label, $status, $detail ) use ( &$checks ) {
			$checks[] = array( 'key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail );
		};

		$public = \TBSHComplianceAuditLogger\Helpers\Storage::is_publicly_readable();
		$add(
			'private_storage',
			__( 'Private file storage', 'tbsh-compliance-audit-logger' ),
			true === $public ? 'fail' : ( false === $public ? 'pass' : 'warn' ),
			true === $public
				? __( 'Files in uploads/tbsh-compliance-private/ can be downloaded from the web. Add a deny rule for that folder to your web server (e.g. nginx: location ^~ /wp-content/uploads/tbsh-compliance-private/ { deny all; }).', 'tbsh-compliance-audit-logger' )
				: ( false === $public ? __( 'Reports, archives and vault packages are not web-accessible.', 'tbsh-compliance-audit-logger' ) : __( 'Could not test web access to the private folder (loopback request failed).', 'tbsh-compliance-audit-logger' ) )
		);

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$add(
			'cron',
			__( 'Scheduled tasks (WP-Cron)', 'tbsh-compliance-audit-logger' ),
			wp_next_scheduled( \TBSHComplianceAuditLogger\Helpers\CronScheduler::MAINTENANCE_HOOK ) ? 'pass' : 'fail',
			$cron_disabled
				? __( 'DISABLE_WP_CRON is set: make sure a real cron job calls wp-cron.php, or alerts, retention and vault syncs will not run.', 'tbsh-compliance-audit-logger' )
				: __( 'Maintenance, alert delivery and vault syncs are scheduled.', 'tbsh-compliance-audit-logger' )
		);

		$add(
			'encryption_key',
			__( 'Credential encryption key', 'tbsh-compliance-audit-logger' ),
			\TBSHComplianceAuditLogger\Helpers\Crypto::has_dedicated_key() ? 'pass' : 'warn',
			\TBSHComplianceAuditLogger\Helpers\Crypto::has_dedicated_key()
				? __( 'A dedicated TBSH_CAL_ENCRYPTION_KEY is defined.', 'tbsh-compliance-audit-logger' )
				: __( 'Credentials are encrypted with a key derived from your WordPress salts. Define TBSH_CAL_ENCRYPTION_KEY in wp-config.php so rotating salts does not invalidate stored credentials.', 'tbsh-compliance-audit-logger' )
		);

		$add(
			'sodium',
			__( 'Sodium cryptography', 'tbsh-compliance-audit-logger' ),
			extension_loaded( 'sodium' ) ? 'pass' : 'warn',
			extension_loaded( 'sodium' )
				? __( 'Native sodium extension available.', 'tbsh-compliance-audit-logger' )
				: __( 'Using the slower WordPress compatibility library; large vault packages will take longer to encrypt.', 'tbsh-compliance-audit-logger' )
		);

		$add(
			'zlib',
			__( 'Compression (zlib)', 'tbsh-compliance-audit-logger' ),
			function_exists( 'gzopen' ) ? 'pass' : 'fail',
			function_exists( 'gzopen' ) ? __( 'Available for archives and vault packages.', 'tbsh-compliance-audit-logger' ) : __( 'Required for log archives and off-site vault packages.', 'tbsh-compliance-audit-logger' )
		);

		$add(
			'ftp',
			__( 'FTP extension', 'tbsh-compliance-audit-logger' ),
			function_exists( 'ftp_connect' ) ? 'pass' : 'warn',
			function_exists( 'ftp_connect' ) ? __( 'FTP/FTPS vaults can be used.', 'tbsh-compliance-audit-logger' ) : __( 'FTP/FTPS vaults are unavailable on this server; S3-compatible and remote-site vaults still work.', 'tbsh-compliance-audit-logger' )
		);

		return $checks;
	}
}
