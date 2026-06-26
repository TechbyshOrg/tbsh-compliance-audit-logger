<?php
namespace TBSHComplianceAuditLogger\Integrity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the cryptographic audit trail chain.
 */
class ChainVerifier {

	/**
	 * Run the cryptographic chain verification.
	 *
	 * @return array Verification results.
	 */
	public static function verify_chain() {
		global $wpdb;
		$table_logs      = $wpdb->prefix . 'tbsh_cal_logs';
		$table_integrity = $wpdb->prefix . 'tbsh_cal_integrity';

		$batch_size    = 1000;
		$offset        = 0;
		$previous_hash = '0000000000000000000000000000000000000000000000000000000000000000';
		$issues_found  = 0;
		$broken_logs   = array();
		$total_logs    = 0;

		// Fetch the total count of logs.
		$total_logs = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_logs" ) );

		// Cap the verification scan to the latest 5,000 logs to prevent PHP execution timeouts.
		$max_scan = 5000;
		$start_id = 0;

		if ( $total_logs > $max_scan ) {
			// Find the boundary row ID at the offset.
			$boundary_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, integrity_hash FROM $table_logs ORDER BY id DESC LIMIT 1 OFFSET %d",
				$max_scan
			) );
			if ( $boundary_row ) {
				$start_id      = intval( $boundary_row->id );
				$previous_hash = $boundary_row->integrity_hash ?: $previous_hash;
			}
		}

		$total_checked = ( $total_logs > $max_scan ) ? $max_scan : $total_logs;

		if ( $total_logs > 0 ) {
			while ( true ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM $table_logs WHERE id > %d ORDER BY id ASC LIMIT %d OFFSET %d",
					$start_id,
					$batch_size,
					$offset
				) );

				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					// 1. Verify previous hash matches.
					if ( $row->previous_hash !== $previous_hash ) {
						$issues_found++;
						$broken_logs[] = array(
							'id'      => $row->id,
							'title'   => $row->event_title,
							'reason'  => sprintf( __( 'Previous hash mismatch. Stored: "%1$s", Calculated: "%2$s"', 'tbsh-compliance-audit-logger' ), $row->previous_hash, $previous_hash ),
							'type'    => 'previous_hash_mismatch',
						);
					}

					// 2. Recalculate canonical hash.
					$canonical_string = implode( '|', array(
						$row->id,
						$row->event_uuid,
						$row->event_type,
						$row->event_category,
						$row->severity,
						$row->event_title,
						$row->event_message,
						$row->user_id,
						$row->site_id,
						$row->object_type,
						$row->object_id,
						$row->ip_hash,
						$row->user_agent_hash,
						$row->request_method,
						$row->request_uri,
						$row->metadata_json,
						$row->compliance_tags,
						$row->previous_hash, // verify with stored previous hash
						$row->created_at,
					) );

					$calculated_hash = hash( 'sha256', $canonical_string );

					// 3. Verify current hash matches.
					if ( $row->integrity_hash !== $calculated_hash ) {
						$issues_found++;
						$broken_logs[] = array(
							'id'      => $row->id,
							'title'   => $row->event_title,
							'reason'  => sprintf( __( 'Hash mismatch. Stored: "%1$s", Calculated: "%2$s"', 'tbsh-compliance-audit-logger' ), $row->integrity_hash, $calculated_hash ),
							'type'    => 'hash_mismatch',
						);
					}

					// Cascade current stored hash as previous for next iteration.
					$previous_hash = $row->integrity_hash;
				}

				$offset += $batch_size;

				// Prevent execution timeouts for very large log datasets.
				if ( $issues_found > 100 ) {
					// Cap log errors list at 100 to prevent database text column bloat.
					break;
				}
			}
		}

		$status = 'verified';
		if ( $issues_found > 0 ) {
			$status = 'compromised';
		}

		$details = array(
			'total_checked' => $total_checked,
			'broken_count'  => $issues_found,
			'broken_logs'   => $broken_logs,
		);

		// Record result in integrity table.
		$wpdb->insert(
			$table_integrity,
			array(
				'verification_date'    => current_time( 'mysql' ),
				'status'               => $status,
				'issues_found'         => $issues_found,
				'verification_details' => wp_json_encode( $details ),
				'created_at'           => current_time( 'mysql' ),
			)
		);

		return array(
			'status'       => $status,
			'issues_found' => $issues_found,
			'details'      => $details,
			'date'         => current_time( 'mysql' ),
		);
	}

	/**
	 * Get the status of the latest verification.
	 */
	public static function get_latest_status() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_integrity';
		$row        = $wpdb->get_row( "SELECT * FROM $table_name ORDER BY id DESC LIMIT 1" );

		if ( ! $row ) {
			return array(
				'status'            => 'unverified',
				'verification_date' => null,
				'issues_found'      => 0,
				'details'           => array(),
			);
		}

		return array(
			'status'            => $row->status,
			'verification_date' => $row->verification_date,
			'issues_found'      => intval( $row->issues_found ),
			'details'           => json_decode( $row->verification_details, true ),
		);
	}
}
