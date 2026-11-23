<?php
namespace TBSHComplianceAuditLogger\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles custom capabilities and permission checks.
 */
class AccessControl {

	/**
	 * Every capability the plugin defines, keyed by the short name exposed to the UI.
	 */
	const CAPS = array(
		'view_logs'              => 'tbsh_cal_view_logs',
		'view_evidence'          => 'tbsh_cal_view_evidence',
		'export_data'            => 'tbsh_cal_export_data',
		'manage_settings'        => 'tbsh_cal_manage_settings',
		'verify_integrity'       => 'tbsh_cal_verify_integrity',
		'view_incidents'         => 'tbsh_cal_view_incidents',
		'manage_incidents'       => 'tbsh_cal_manage_incidents',
		'view_risks'             => 'tbsh_cal_view_risks',
		'manage_risks'           => 'tbsh_cal_manage_risks',
		'view_vendors'           => 'tbsh_cal_view_vendors',
		'manage_vendors'         => 'tbsh_cal_manage_vendors',
		'view_reports'           => 'tbsh_cal_view_reports',
		'manage_reports'         => 'tbsh_cal_manage_reports',
		'manage_vaults'          => 'tbsh_cal_manage_vaults',
		'view_alerts'            => 'tbsh_cal_view_alerts',
		'manage_alerts'          => 'tbsh_cal_manage_alerts',
		'view_analytics'         => 'tbsh_cal_view_analytics',
		'manage_auditor_access'  => 'tbsh_cal_manage_auditor_access',
	);

	/**
	 * Capabilities from the discontinued separate edition, mapped to their replacements.
	 * Used only by the migrator.
	 */
	const LEGACY_CAP_MAP = array(
		'tbsh_cal_pro_view_incidents'   => array( 'tbsh_cal_view_incidents' ),
		'tbsh_cal_pro_manage_incidents' => array( 'tbsh_cal_manage_incidents' ),
		'tbsh_cal_pro_view_risks'       => array( 'tbsh_cal_view_risks' ),
		'tbsh_cal_pro_manage_risks'     => array( 'tbsh_cal_manage_risks' ),
		'tbsh_cal_pro_view_vendors'     => array( 'tbsh_cal_view_vendors' ),
		'tbsh_cal_pro_manage_vendors'   => array( 'tbsh_cal_manage_vendors' ),
		'tbsh_cal_pro_view_reports'     => array( 'tbsh_cal_view_reports' ),
		'tbsh_cal_pro_manage_reports'   => array( 'tbsh_cal_manage_reports' ),
		'tbsh_cal_pro_view_vaults'      => array( 'tbsh_cal_view_evidence' ),
		'tbsh_cal_pro_manage_vaults'    => array( 'tbsh_cal_manage_vaults' ),
		'tbsh_cal_pro_view_alerts'      => array( 'tbsh_cal_view_alerts' ),
		'tbsh_cal_pro_manage_alerts'    => array( 'tbsh_cal_manage_alerts' ),
		'tbsh_cal_pro_view_analytics'   => array( 'tbsh_cal_view_analytics' ),
		'tbsh_cal_pro_view_executive'   => array( 'tbsh_cal_view_analytics' ),
		'tbsh_cal_pro_view_auditor'     => array( 'tbsh_cal_manage_auditor_access' ),
	);

	/**
	 * Grant every capability to the administrator role.
	 */
	public static function init() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::CAPS as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Verify user capability.
	 *
	 * @param string $cap Capability to verify.
	 * @return bool True if authorized.
	 */
	public static function check( $cap ) {
		return current_user_can( $cap );
	}

	/**
	 * Map of short capability name => whether the current user has it (for the UI).
	 *
	 * @return array<string, bool>
	 */
	public static function current_user_caps() {
		$out = array();
		foreach ( self::CAPS as $short => $cap ) {
			$out[ $short ] = current_user_can( $cap );
		}
		return $out;
	}

	/**
	 * Rename legacy capabilities on every role, preserving custom grants.
	 *
	 * @return int Number of role/capability grants migrated.
	 */
	public static function migrate_legacy_caps() {
		$migrated = 0;
		$roles    = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::LEGACY_CAP_MAP as $legacy => $replacements ) {
				if ( ! isset( $role->capabilities[ $legacy ] ) ) {
					continue;
				}
				if ( $role->capabilities[ $legacy ] ) {
					foreach ( $replacements as $new_cap ) {
						$role->add_cap( $new_cap );
					}
					++$migrated;
				}
				$role->remove_cap( $legacy );
			}
		}

		return $migrated;
	}

	/**
	 * Remove every plugin capability (current and legacy) from every role.
	 */
	public static function remove_all() {
		$roles = wp_roles();
		$caps  = array_merge( array_values( self::CAPS ), array_keys( self::LEGACY_CAP_MAP ), array( 'tbsh_cal_pro_manage_settings' ) );

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( isset( $role->capabilities[ $cap ] ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
