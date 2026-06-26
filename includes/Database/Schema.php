<?php
namespace TBSHComplianceAuditLogger\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles database table installation and updates.
 */
class Schema {

	/**
	 * Run the installation process.
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 */
	public static function install( $network_wide = false ) {
		global $wpdb;

		if ( is_multisite() && $network_wide ) {
			// Get all blogs and install tables for each.
			$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				self::create_tables();
				restore_current_blog();
			}
		} else {
			self::create_tables();
		}
	}

	/**
	 * Create the required database tables.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// 1. Logs Table.
		$table_logs = $wpdb->prefix . 'tbsh_cal_logs';
		$sql_logs   = "CREATE TABLE $table_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid varchar(36) NOT NULL,
			event_type varchar(100) NOT NULL,
			event_category varchar(50) NOT NULL,
			severity varchar(20) NOT NULL,
			event_title varchar(255) NOT NULL,
			event_message text NOT NULL,
			user_id bigint(20) unsigned DEFAULT 0,
			username varchar(60) DEFAULT '',
			role varchar(50) DEFAULT '',
			site_id bigint(20) unsigned DEFAULT 1,
			object_type varchar(50) DEFAULT '',
			object_id varchar(100) DEFAULT '',
			ip_hash varchar(64) DEFAULT '',
			user_agent_hash varchar(64) DEFAULT '',
			request_method varchar(10) DEFAULT '',
			request_uri text DEFAULT NULL,
			referrer text DEFAULT NULL,
			metadata_json longtext DEFAULT NULL,
			compliance_tags varchar(255) DEFAULT '',
			integrity_hash varchar(64) DEFAULT '',
			previous_hash varchar(64) DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY event_type (event_type),
			KEY event_category (event_category),
			KEY severity (severity)
		) $charset_collate;";

		dbDelta( $sql_logs );

		// 2. Evidence Table.
		$table_evidence = $wpdb->prefix . 'tbsh_cal_evidence';
		$sql_evidence   = "CREATE TABLE $table_evidence (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			evidence_uuid varchar(36) NOT NULL,
			evidence_type varchar(100) NOT NULL,
			evidence_title varchar(255) NOT NULL,
			snapshot_data longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql_evidence );

		// 3. Integrity Table.
		$table_integrity = $wpdb->prefix . 'tbsh_cal_integrity';
		$sql_integrity   = "CREATE TABLE $table_integrity (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			verification_date datetime NOT NULL,
			status varchar(20) NOT NULL,
			issues_found int(11) DEFAULT 0,
			verification_details longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql_integrity );
	}
}
