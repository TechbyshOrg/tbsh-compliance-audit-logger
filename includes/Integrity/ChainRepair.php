<?php
namespace TBSHComplianceAuditLogger\Integrity;

use TBSHComplianceAuditLogger\Privacy\PrivacyManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repairs hash chain after retention purges.
 */
class ChainRepair {

	/**
	 * Re-anchor the oldest remaining log to the genesis previous_hash,
	 * then rebuild integrity hashes from that row forward.
	 */
	public static function reanchor_after_purge() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oldest_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM %i ORDER BY id ASC LIMIT 1",
			$table_name
		) );

		if ( ! $oldest_id ) {
			return;
		}

		// Rebuild from the oldest remaining row (genesis previous_hash).
		PrivacyManager::rebuild_hash_chain_from( intval( $oldest_id ) );
	}
}
