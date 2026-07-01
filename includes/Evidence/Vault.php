<?php
namespace TBSHComplianceAuditLogger\Evidence;

use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles capturing, storing, and exporting system snapshots as evidence.
 */
class Vault {

	/**
	 * Capture a system snapshot.
	 *
	 * @param string $type  Manual or automatic.
	 * @param string $title Snapshot description.
	 * @return int|bool Inserted ID or false.
	 */
	public static function capture_snapshot( $type = 'automatic', $title = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_evidence';

		if ( empty( $title ) ) {
			$title = 'automatic' === $type ? __( 'Daily Compliance Snapshot', 'tbsh-compliance-audit-logger' ) : __( 'Manual Audit Snapshot', 'tbsh-compliance-audit-logger' );
		}

		$snapshot_data = self::gather_system_info();
		$uuid          = self::generate_uuid();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table_name,
			array(
				'evidence_uuid' => $uuid,
				'evidence_type' => $type,
				'evidence_title' => $title,
				'snapshot_data' => wp_json_encode( $snapshot_data ),
				'created_at'    => current_time( 'mysql' ),
			)
		);

		if ( $inserted ) {
			Logger::log(
				'evidence_captured',
				'Security Monitoring',
				'info',
				__( 'Evidence snapshot captured', 'tbsh-compliance-audit-logger' ),
				/* translators: %s: evidence title */
				sprintf( __( 'System evidence snapshot "%s" was successfully recorded.', 'tbsh-compliance-audit-logger' ), $title ),
				array(
					'object_type'     => 'evidence',
					'object_id'       => $uuid,
					'compliance_tags' => 'Security Monitoring, Configuration Management, Audit Trail',
				)
			);
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Gather all system settings, configurations, plugins, themes, etc.
	 */
	private static function gather_system_info() {
		global $wp_version, $wpdb;

		// 1. Plugins.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}

		$plugin_list = array();
		foreach ( $all_plugins as $file => $data ) {
			$plugin_list[] = array(
				'name'       => $data['Name'],
				'version'    => $data['Version'],
				'file'       => $file,
				'is_active'  => in_array( $file, $active_plugins, true ),
				'plugin_uri' => $data['PluginURI'],
			);
		}

		// 2. Themes.
		$all_themes    = wp_get_themes();
		$active_theme  = wp_get_theme();
		$theme_list    = array();
		foreach ( $all_themes as $slug => $theme ) {
			$theme_list[] = array(
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'slug'      => $slug,
				'is_active' => $slug === $active_theme->get_stylesheet(),
			);
		}

		// 3. User Roles & Capabilities.
		$roles = array();
		$wp_roles_obj = wp_roles();
		if ( $wp_roles_obj && ! empty( $wp_roles_obj->roles ) ) {
			foreach ( $wp_roles_obj->roles as $role_slug => $role_data ) {
				$roles[ $role_slug ] = array(
					'name'         => $role_data['name'],
					'capabilities' => array_keys( array_filter( $role_data['capabilities'] ) ),
				);
			}
		}

		// 4. DB Sizing.
		$db_size = 0;
		$tables  = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status  = $wpdb->get_results( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		if ( ! empty( $status ) ) {
			foreach ( $status as $table ) {
				$size = intval( $table->Data_length ) + intval( $table->Index_length );
				$db_size += $size;
				$tables[ $table->Name ] = array(
					'rows' => intval( $table->Rows ),
					'size' => $size,
				);
			}
		}

		// 5. Security Settings.
		$security = array(
			'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wp_debug_display'  => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'script_debug'      => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'is_ssl'            => is_ssl(),
			'force_ssl_admin'   => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
			'php_version'       => PHP_VERSION,
			'db_version'        => $wpdb->db_version(),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'mysql_mode'        => $wpdb->get_var( "SELECT @@sql_mode" ),
		);

		// 6. Site Settings.
		$site_config = array(
			'blogname'            => get_option( 'blogname' ),
			'siteurl'             => get_option( 'siteurl' ),
			'home'                => get_option( 'home' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'users_can_register'  => get_option( 'users_can_register' ),
			'default_role'        => get_option( 'default_role' ),
			'is_multisite'        => is_multisite(),
		);

		return array(
			'wp_version'    => $wp_version,
			'php_version'   => PHP_VERSION,
			'plugins'       => $plugin_list,
			'themes'        => $theme_list,
			'roles'         => $roles,
			'database'      => array(
				'total_size' => $db_size,
				'tables'     => $tables,
			),
			'security'      => $security,
			'site_config'   => $site_config,
			'captured_at'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate a UUID v4.
	 */
	private static function generate_uuid() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ) | 0x4000,
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
		);
	}
}
