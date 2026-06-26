<?php
namespace TBSHComplianceAuditLogger\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles batch processing and generation of CSV/JSON log exports.
 */
class BatchExporter {

	/**
	 * Get the export directory path.
	 */
	public static function get_export_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/tbsh-compliance-exports/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Write an index.php and .htaccess to protect files from direct web access.
			file_put_contents( $dir . 'index.php', '<?php // Silence' );
			file_put_contents( $dir . '.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	/**
	 * Run the exporter and generate the file.
	 *
	 * @param array  $filters Filter options (date_start, date_end, category, severity, user_id).
	 * @param string $format  Format (csv or json).
	 * @return string|bool Filename of the generated export, or false on error.
	 */
	public static function generate_export( $filters = array(), $format = 'csv' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		// 1. Build Query Clauses.
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['date_start'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_start'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_end'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_end'] . ' 23:59:59';
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[] = 'event_category = %s';
			$args[]  = $filters['category'];
		}
		if ( ! empty( $filters['severity'] ) ) {
			$where[] = 'severity = %s';
			$args[]  = $filters['severity'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = intval( $filters['user_id'] );
		}
		if ( ! empty( $filters['search'] ) ) {
			$where[] = '(event_title LIKE %s OR event_message LIKE %s OR username LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		$where_clause = implode( ' AND ', $where );

		// 2. Prepare export file.
		$uuid     = wp_generate_password( 24, false, false );
		$filename = 'export_' . $uuid . '.' . $format;
		$filepath = self::get_export_dir() . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$file_handle = fopen( $filepath, 'w' );
		if ( ! $file_handle ) {
			return false;
		}

		$batch_size = 1000;
		$offset     = 0;

		// 3. Write Header based on format.
		if ( 'csv' === $format ) {
			// Add UTF-8 BOM.
			fprintf( $file_handle, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
			fputcsv( $file_handle, array(
				'ID',
				'UUID',
				'Timestamp',
				'Event Type',
				'Category',
				'Severity',
				'Title',
				'Message',
				'Actor ID',
				'Actor Username',
				'Role',
				'Site ID',
				'Object Type',
				'Object ID',
				'IP Hash',
				'UA Hash',
				'Request Method',
				'Request URI',
				'Compliance Tags',
				'Integrity Hash',
				'Previous Hash',
			) );
		} elseif ( 'json' === $format ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $file_handle, "[\n" );
		}

		$first_row = true;

		// 4. Batch Query and Write.
		while ( true ) {
			$sql = "SELECT * FROM %i WHERE $where_clause ORDER BY id DESC LIMIT %d OFFSET %d";
			$query_args = array_merge( array( $table_name ), $args, array( $batch_size, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, $query_args );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$logs = $wpdb->get_results( $sql );

			if ( empty( $logs ) ) {
				break;
			}

			foreach ( $logs as $log ) {
				if ( 'csv' === $format ) {
					fputcsv( $file_handle, array(
						$log->id,
						$log->event_uuid,
						$log->created_at,
						$log->event_type,
						$log->event_category,
						$log->severity,
						$log->event_title,
						$log->event_message,
						$log->user_id,
						$log->username,
						$log->role,
						$log->site_id,
						$log->object_type,
						$log->object_id,
						$log->ip_hash,
						$log->user_agent_hash,
						$log->request_method,
						$log->request_uri,
						$log->compliance_tags,
						$log->integrity_hash,
						$log->previous_hash,
					) );
				} elseif ( 'json' === $format ) {
					if ( ! $first_row ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						fwrite( $file_handle, ",\n" );
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fwrite( $file_handle, wp_json_encode( $log, JSON_PRETTY_PRINT ) );
					$first_row = false;
				}
			}

			$offset += $batch_size;

			// Clear memory.
			$wpdb->flush();
			unset( $logs );
		}

		// Close format.
		if ( 'json' === $format ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $file_handle, "\n]\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $file_handle );

		return $filename;
	}

	/**
	 * List currently available export files in the vault.
	 */
	public static function get_available_exports() {
		$dir   = self::get_export_dir();
		$files = glob( $dir . 'export_*.*' );
		$list  = array();

		foreach ( $files as $file ) {
			$basename = basename( $file );
			$parts    = explode( '.', $basename );
			$ext      = end( $parts );
			$list[]   = array(
				'filename'   => $basename,
				'created_at' => gmdate( 'Y-m-d H:i:s', filemtime( $file ) ),
				'size'       => filesize( $file ),
				'format'     => $ext,
			);
		}

		// Sort by file time descending.
		usort( $list, function ( $a, $b ) {
			return strcmp( $b['created_at'], $a['created_at'] );
		} );

		return $list;
	}

	/**
	 * Clean up export files older than 2 hours.
	 */
	public static function clean_old_exports() {
		$dir   = self::get_export_dir();
		$files = glob( $dir . 'export_*.*' );
		$now   = time();

		foreach ( $files as $file ) {
			if ( $now - filemtime( $file ) > 7200 ) { // 2 hours
				wp_delete_file( $file );
			}
		}
	}
}
