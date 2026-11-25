<?php
namespace TBSHComplianceAuditLogger\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database table definitions.
 *
 * Installation and upgrades are orchestrated by Migrator, which must run any
 * legacy table renames before create_tables() so dbDelta upgrades the renamed
 * tables in place instead of creating empty duplicates.
 */
class Schema {

	/**
	 * Current database schema version.
	 */
	const DB_VERSION = '2.0.0';

	/**
	 * Logical table keys => table suffix (after $wpdb->prefix).
	 */
	const TABLES = array(
		'logs'              => 'tbsh_cal_logs',
		'evidence'          => 'tbsh_cal_evidence',
		'integrity'         => 'tbsh_cal_integrity',
		'incidents'         => 'tbsh_cal_incidents',
		'incident_notes'    => 'tbsh_cal_incident_notes',
		'incident_evidence' => 'tbsh_cal_incident_evidence',
		'risks'             => 'tbsh_cal_risks',
		'vendors'           => 'tbsh_cal_vendors',
		'reports'           => 'tbsh_cal_reports',
		'vaults'            => 'tbsh_cal_vaults',
		'vault_logs'        => 'tbsh_cal_vault_logs',
		'alert_log'         => 'tbsh_cal_alert_log',
		'auditor_access'    => 'tbsh_cal_auditor_access',
		'resilience'        => 'tbsh_cal_resilience',
	);

	/**
	 * Tables from the discontinued separate edition => logical key of their replacement.
	 */
	const LEGACY_TABLES = array(
		'tbsh_cal_pro_incidents'         => 'incidents',
		'tbsh_cal_pro_incident_notes'    => 'incident_notes',
		'tbsh_cal_pro_incident_evidence' => 'incident_evidence',
		'tbsh_cal_pro_risks'             => 'risks',
		'tbsh_cal_pro_vendors'           => 'vendors',
		'tbsh_cal_pro_reports'           => 'reports',
		'tbsh_cal_pro_vaults'            => 'vaults',
		'tbsh_cal_pro_vault_logs'        => 'vault_logs',
		'tbsh_cal_pro_alerts'            => 'alert_log',
		'tbsh_cal_pro_auditor_access'    => 'auditor_access',
		'tbsh_cal_pro_resilience'        => 'resilience',
	);

	/**
	 * Fully prefixed table name for a logical key.
	 *
	 * @param string $key Logical table key.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;
		return $wpdb->prefix . self::TABLES[ $key ];
	}

	/**
	 * Run the installation process (kept for backwards compatibility; delegates to Migrator).
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 */
	public static function install( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $sites as $blog_id ) {
				switch_to_blog( $blog_id );
				Migrator::run();
				restore_current_blog();
			}
		} else {
			Migrator::run();
		}
	}

	/**
	 * Upgrade when the schema is behind (no reactivation needed).
	 */
	public static function maybe_upgrade() {
		Migrator::maybe_run();
	}

	/**
	 * Create or upgrade every table with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t = array();
		foreach ( array_keys( self::TABLES ) as $key ) {
			$t[ $key ] = self::table( $key );
		}

		$sql = array();

		$sql[] = "CREATE TABLE {$t['logs']} (
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
			actor varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY actor (actor),
			KEY event_type (event_type),
			KEY event_category (event_category),
			KEY severity (severity),
			KEY event_type_created (event_type,created_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['evidence']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			evidence_uuid varchar(36) NOT NULL,
			evidence_type varchar(100) NOT NULL,
			evidence_title varchar(255) NOT NULL,
			snapshot_data longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY evidence_uuid (evidence_uuid)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['integrity']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			check_type varchar(30) NOT NULL DEFAULT 'chain',
			verification_date datetime NOT NULL,
			status varchar(20) NOT NULL,
			issues_found int(11) DEFAULT 0,
			verification_details longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY check_type (check_type)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['incidents']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			incident_uuid varchar(36) NOT NULL,
			severity varchar(20) NOT NULL,
			status varchar(20) NOT NULL,
			incident_type varchar(100) NOT NULL,
			event_title varchar(255) NOT NULL,
			description text DEFAULT NULL,
			assigned_user_id bigint(20) unsigned DEFAULT 0,
			owner_id bigint(20) unsigned DEFAULT 0,
			affected_systems text DEFAULT NULL,
			timeline_json longtext DEFAULT NULL,
			resolution_notes text DEFAULT NULL,
			root_cause text DEFAULT NULL,
			impact_assessment text DEFAULT NULL,
			compliance_mappings varchar(255) DEFAULT '',
			detection_key varchar(191) DEFAULT '',
			source_log_id bigint(20) unsigned DEFAULT 0,
			nis2_significant tinyint(1) DEFAULT 0,
			cross_border tinyint(1) DEFAULT 0,
			detected_at datetime DEFAULT NULL,
			resolved_at datetime DEFAULT NULL,
			nis2_json longtext DEFAULT NULL,
			ai_analysis_json longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status),
			KEY severity (severity),
			KEY incident_type (incident_type),
			KEY incident_uuid (incident_uuid),
			KEY detection_key (detection_key)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['incident_notes']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			incident_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			note_content text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY incident_id (incident_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['incident_evidence']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			incident_id bigint(20) unsigned NOT NULL,
			evidence_uuid varchar(36) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY incident_evidence (incident_id,evidence_uuid),
			KEY evidence_uuid (evidence_uuid)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['risks']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			description text NOT NULL,
			category varchar(50) NOT NULL,
			likelihood int(11) DEFAULT 3,
			impact int(11) DEFAULT 3,
			score int(11) DEFAULT 9,
			mitigation_plan text DEFAULT NULL,
			owner_id bigint(20) unsigned DEFAULT 0,
			review_date date DEFAULT NULL,
			status varchar(20) NOT NULL,
			compliance_mappings varchar(255) DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY category (category),
			KEY status (status),
			KEY score (score)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['vendors']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			service_type varchar(100) NOT NULL,
			data_access_level varchar(50) DEFAULT 'none',
			criticality varchar(50) DEFAULT 'medium',
			risk_rating varchar(50) DEFAULT 'medium',
			contact_info text DEFAULT NULL,
			contract_renewal_date date DEFAULT NULL,
			review_date date DEFAULT NULL,
			status varchar(20) NOT NULL,
			linked_slug varchar(191) DEFAULT '',
			tracking_data_json longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name(191)),
			KEY criticality (criticality),
			KEY review_date (review_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['reports']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			report_type varchar(50) NOT NULL,
			report_title varchar(255) DEFAULT '',
			format varchar(10) NOT NULL,
			file_name varchar(191) DEFAULT '',
			status varchar(20) NOT NULL,
			content_hash varchar(64) DEFAULT '',
			digital_signature varchar(64) DEFAULT '',
			metadata_json longtext DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY report_type (report_type)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['vaults']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vault_type varchar(50) NOT NULL,
			label varchar(191) DEFAULT '',
			is_active tinyint(1) DEFAULT 0,
			credentials_encrypted text DEFAULT NULL,
			schedule varchar(20) DEFAULT 'daily',
			status varchar(20) DEFAULT 'unknown',
			last_error text DEFAULT NULL,
			last_sync_at datetime DEFAULT NULL,
			last_synced_log_id bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['vault_logs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			vault_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			details text DEFAULT NULL,
			remote_path varchar(255) DEFAULT '',
			file_size bigint(20) DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY vault_id (vault_id),
			KEY created_at (created_at)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['alert_log']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			alert_type varchar(100) NOT NULL,
			channel varchar(50) NOT NULL,
			recipient_masked varchar(255) DEFAULT '',
			severity varchar(20) DEFAULT 'info',
			subject varchar(255) DEFAULT '',
			status varchar(20) NOT NULL,
			payload_json longtext DEFAULT NULL,
			response_message text DEFAULT NULL,
			attempts int(11) DEFAULT 0,
			next_attempt_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status_next (status,next_attempt_at),
			KEY channel (channel)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['auditor_access']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL DEFAULT '',
			token_hint varchar(8) DEFAULT '',
			label varchar(191) DEFAULT '',
			created_by bigint(20) unsigned DEFAULT 0,
			expires_at datetime NOT NULL,
			status varchar(20) NOT NULL,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$t['resilience']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			scenario varchar(50) NOT NULL DEFAULT 'tabletop',
			status varchar(20) NOT NULL DEFAULT 'planned',
			scheduled_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			rto_target_min int(11) DEFAULT NULL,
			rto_actual_min int(11) DEFAULT NULL,
			rpo_target_min int(11) DEFAULT NULL,
			rpo_actual_min int(11) DEFAULT NULL,
			outcome varchar(20) DEFAULT '',
			notes text DEFAULT NULL,
			created_by bigint(20) unsigned DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Whether a (fully prefixed) table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Whether a column exists on a table.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}
}
