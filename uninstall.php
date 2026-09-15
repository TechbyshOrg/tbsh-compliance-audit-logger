<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package TBSHComplianceAuditLogger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tbsh_cal_settings = get_option( 'tbsh_cal_settings', array() );

// Check if cleanup is requested.
if ( isset( $tbsh_cal_settings['cleanup_on_uninstall'] ) && 'delete' === $tbsh_cal_settings['cleanup_on_uninstall'] ) {
	global $wpdb;

	if ( is_multisite() ) {
		$tbsh_cal_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $tbsh_cal_sites as $blog_id ) {
			switch_to_blog( $blog_id );
			tbsh_cal_drop_tables();
			restore_current_blog();
		}
	} else {
		tbsh_cal_drop_tables();
	}

	// Remove capabilities from roles.
	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->remove_cap( 'tbsh_cal_view_logs' );
		$role->remove_cap( 'tbsh_cal_view_evidence' );
		$role->remove_cap( 'tbsh_cal_export_data' );
		$role->remove_cap( 'tbsh_cal_manage_settings' );
		$role->remove_cap( 'tbsh_cal_verify_integrity' );
	}

	// Delete general option entries.
	delete_option( 'tbsh_cal_settings' );
	delete_option( 'tbsh_cal_privacy_salt' );
	delete_option( 'tbsh_cal_db_version' );
	delete_option( 'tbsh_cal_core_checksum_result' );

	// Unschedule any cron tasks.
	wp_clear_scheduled_hook( 'tbsh_cal_cron_job' );
}

/**
 * Drop custom plugin tables.
 */
function tbsh_cal_drop_tables() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_logs" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_evidence" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_integrity" );
}
