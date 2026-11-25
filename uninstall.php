<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Data is removed only when "Delete all data on uninstall" is selected in Settings.
 *
 * @package TBSHComplianceAuditLogger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Database/Schema.php';
require_once __DIR__ . '/includes/Security/AccessControl.php';
require_once __DIR__ . '/includes/Helpers/CronScheduler.php';
require_once __DIR__ . '/includes/Helpers/Storage.php';

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\CronScheduler;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Security\AccessControl;

$tbsh_cal_settings = get_option( 'tbsh_cal_settings', array() );

if ( isset( $tbsh_cal_settings['cleanup_on_uninstall'] ) && 'delete' === $tbsh_cal_settings['cleanup_on_uninstall'] ) {
	if ( is_multisite() ) {
		$tbsh_cal_sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $tbsh_cal_sites as $tbsh_cal_blog_id ) {
			switch_to_blog( $tbsh_cal_blog_id );
			tbsh_cal_uninstall_site();
			restore_current_blog();
		}
	} else {
		tbsh_cal_uninstall_site();
	}
}

/**
 * Remove every table, option, capability, cron hook, user meta and file for the current site.
 */
function tbsh_cal_uninstall_site() {
	global $wpdb;

	$tables = array();
	foreach ( array_keys( Schema::TABLES ) as $key ) {
		$tables[] = Schema::table( $key );
	}
	foreach ( array_keys( Schema::LEGACY_TABLES ) as $legacy ) {
		$tables[] = $wpdb->prefix . $legacy;
		$tables[] = $wpdb->prefix . $legacy . '_migrated_bak';
	}

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	AccessControl::remove_all();

	$options = array(
		'tbsh_cal_settings',
		'tbsh_cal_secrets',
		'tbsh_cal_privacy_salt',
		'tbsh_cal_db_version',
		'tbsh_cal_migration_state',
		'tbsh_cal_core_checksum_result',
		'tbsh_cal_notice_merged',
		'tbsh_cal_needs_setup',
		'tbsh_cal_baselines_updated',
		'tbsh_cal_pro_settings',
		'tbsh_cal_file_integrity_state',
		'tbsh_cal_file_integrity_result',
		'tbsh_cal_digest_state',
		'tbsh_cal_siem_state',
		'tbsh_cal_setup_state',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'tbsh_cal_behavior_baseline' ) );

	CronScheduler::clear_all();
	Storage::delete_all();
}
