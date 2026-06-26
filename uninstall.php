<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package TBSHComplianceAuditLogger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'tbsh_cal_settings', array() );

// Check if cleanup is requested.
if ( isset( $settings['cleanup_on_uninstall'] ) && 'delete' === $settings['cleanup_on_uninstall'] ) {
	global $wpdb;

	if ( is_multisite() ) {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $sites as $blog_id ) {
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

	// Unschedule any cron tasks.
	wp_clear_scheduled_hook( 'tbsh_cal_cron_job' );
}

/**
 * Drop custom plugin tables.
 */
function tbsh_cal_drop_tables() {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_logs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_evidence" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tbsh_cal_integrity" );
}
