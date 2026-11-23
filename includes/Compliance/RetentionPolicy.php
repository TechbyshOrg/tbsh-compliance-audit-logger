<?php
namespace TBSHComplianceAuditLogger\Compliance;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Incidents\IncidentRepository;
use TBSHComplianceAuditLogger\Integrity\ChainRepair;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data retention (daily maintenance).
 *
 * - Logs older than `retain_logs` days: optionally archived to private storage
 *   (gzip JSON Lines, verified before any row is deleted), deleted in batches, then
 *   the hash chain is re-anchored so verification keeps passing.
 * - Resolved/closed incidents older than `retain_incidents` days, with their notes
 *   and evidence links.
 * - Evidence older than `retain_evidence` days, except snapshots linked to an open incident.
 * - Alert and vault delivery history older than `retain_alert_log` days.
 * A value of 0 keeps data forever.
 */
class RetentionPolicy {

	const BATCH = 1000;

	/**
	 * Run every retention rule.
	 *
	 * @param array $settings Settings.
	 * @return array Summary of deleted rows.
	 */
	public static function run( array $settings ) {
		$summary = array(
			'logs'      => self::purge_logs( (int) ( $settings['retain_logs'] ?? 0 ), ! empty( $settings['archive_before_purge'] ) ),
			'incidents' => self::purge_incidents( (int) ( $settings['retain_incidents'] ?? 0 ) ),
			'evidence'  => self::purge_evidence( (int) ( $settings['retain_evidence'] ?? 0 ) ),
			'history'   => self::purge_history( (int) ( $settings['retain_alert_log'] ?? 180 ) ),
		);

		$summary_total = array_sum( array_map( function ( $v ) {
			return is_array( $v ) ? (int) ( $v['deleted'] ?? 0 ) : (int) $v;
		}, $summary ) );

		if ( $summary_total > 0 ) {
			Logger::log(
				'retention_applied',
				'Audit Trail',
				'notice',
				__( 'Data retention policy applied', 'tbsh-compliance-audit-logger' ),
				__( 'Records past their retention period were removed.', 'tbsh-compliance-audit-logger' ),
				array( 'metadata' => $summary, 'compliance_tags' => 'Data Retention, GDPR Art. 5(1)(e)' )
			);
		}
		return $summary;
	}

	/**
	 * Logs.
	 *
	 * @param int  $days    Retention days.
	 * @param bool $archive Archive first.
	 * @return array
	 */
	private static function purge_logs( $days, $archive ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return array( 'deleted' => 0 );
		}
		$table  = Schema::table( 'logs' );
		$cutoff = Time::ago( $days * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE created_at < %s', $table, $cutoff ) );
		if ( ! $max_id ) {
			return array( 'deleted' => 0 );
		}

		$archive_file = null;
		if ( $archive ) {
			$archive_file = self::archive_logs( $table, $cutoff, $max_id );
			if ( is_wp_error( $archive_file ) ) {
				Logger::log( 'retention_failed', 'Audit Trail', 'error', __( 'Log retention skipped', 'tbsh-compliance-audit-logger' ), $archive_file->get_error_message() );
				return array( 'deleted' => 0, 'error' => $archive_file->get_error_message() );
			}
		}

		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n        = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d AND created_at < %s ORDER BY id LIMIT %d', $table, $max_id, $cutoff, self::BATCH ) );
			$deleted += $n;
		} while ( self::BATCH === $n );

		if ( $deleted > 0 ) {
			ChainRepair::reanchor_after_purge();
		}

		return array( 'deleted' => $deleted, 'archive' => $archive_file ? basename( $archive_file ) : null );
	}

	/**
	 * Stream expiring logs into a verified gzip archive.
	 *
	 * @param string $table  Logs table.
	 * @param string $cutoff Cutoff.
	 * @param int    $max_id Highest id to include.
	 * @return string|\WP_Error Archive path.
	 */
	private static function archive_logs( $table, $cutoff, $max_id ) {
		global $wpdb;
		$dir = Storage::dir( 'archives' );
		if ( ! $dir || ! function_exists( 'gzopen' ) ) {
			return new \WP_Error( 'tbsh_cal_archive', __( 'The log archive could not be written (storage or zlib unavailable); no logs were deleted.', 'tbsh-compliance-audit-logger' ) );
		}

		$path    = $dir . Storage::unique_name( 'logs-until-' . substr( $cutoff, 0, 10 ), 'jsonl.gz' );
		$gz      = gzopen( $path, 'wb6' );
		$written = 0;
		$last    = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND id <= %d AND created_at < %s ORDER BY id ASC LIMIT %d', $table, $last, $max_id, $cutoff, self::BATCH ), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( false === gzwrite( $gz, wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
					gzclose( $gz );
					wp_delete_file( $path );
					return new \WP_Error( 'tbsh_cal_archive', __( 'Writing the log archive failed; no logs were deleted.', 'tbsh-compliance-audit-logger' ) );
				}
				$last = (int) $row['id'];
				++$written;
			}
		} while ( count( $rows ) === self::BATCH );
		gzclose( $gz );

		// Verify: re-read and count lines.
		$read = 0;
		$in   = gzopen( $path, 'rb' );
		while ( $in && ! gzeof( $in ) ) {
			if ( false !== gzgets( $in ) ) {
				++$read;
			}
		}
		if ( $in ) {
			gzclose( $in );
		}
		if ( $read !== $written ) {
			wp_delete_file( $path );
			return new \WP_Error( 'tbsh_cal_archive', __( 'The log archive could not be verified; no logs were deleted.', 'tbsh-compliance-audit-logger' ) );
		}
		return $path;
	}

	/**
	 * Resolved/closed incidents.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	private static function purge_incidents( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff  = Time::ago( $days * DAY_IN_SECONDS );
		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE status IN ('resolved','closed') AND COALESCE(resolved_at, updated_at) < %s LIMIT %d", Schema::table( 'incidents' ), $cutoff, self::BATCH ) );
			IncidentRepository::delete_ids( $ids );
			$deleted += count( $ids );
		} while ( count( $ids ) === self::BATCH );
		return $deleted;
	}

	/**
	 * Evidence snapshots not linked to an open incident.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	private static function purge_evidence( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff   = Time::ago( $days * DAY_IN_SECONDS );
		$evidence = Schema::table( 'evidence' );
		$links    = Schema::table( 'incident_evidence' );
		$deleted  = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$uuids = $wpdb->get_col( $wpdb->prepare(
				"SELECT e.evidence_uuid FROM %i e WHERE e.created_at < %s AND NOT EXISTS (
					SELECT 1 FROM %i l JOIN %i i ON i.id = l.incident_id WHERE l.evidence_uuid = e.evidence_uuid AND i.status NOT IN ('resolved','closed')
				) LIMIT %d",
				$evidence,
				$cutoff,
				$links,
				Schema::table( 'incidents' ),
				self::BATCH
			) );
			if ( empty( $uuids ) ) {
				break;
			}
			$in = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE evidence_uuid IN ($in)", array_merge( array( $links ), $uuids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE evidence_uuid IN ($in)", array_merge( array( $evidence ), $uuids ) ) );
		} while ( count( $uuids ) === self::BATCH );
		return $deleted;
	}

	/**
	 * Alert and vault delivery history.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	private static function purge_history( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$cutoff  = Time::ago( $days * DAY_IN_SECONDS );
		$deleted = 0;
		foreach ( array( 'alert_log', 'vault_logs' ) as $key ) {
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created_at < %s AND status NOT IN ('queued','retry','sending') LIMIT %d", Schema::table( $key ), $cutoff, self::BATCH ) );
				$deleted += $n;
			} while ( self::BATCH === $n );
		}
		return $deleted;
	}
}
