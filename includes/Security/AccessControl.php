<?php
namespace TBSHComplianceAuditLogger\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles custom capabilities and permissions check.
 */
class AccessControl {

	/**
	 * Register capabilities.
	 */
	public static function init() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'tbsh_cal_view_logs' );
			$role->add_cap( 'tbsh_cal_view_evidence' );
			$role->add_cap( 'tbsh_cal_export_data' );
			$role->add_cap( 'tbsh_cal_manage_settings' );
			$role->add_cap( 'tbsh_cal_verify_integrity' );
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
}
