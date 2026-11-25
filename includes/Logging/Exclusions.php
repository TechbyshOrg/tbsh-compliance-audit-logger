<?php
namespace TBSHComplianceAuditLogger\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-defined logging exclusions (users, roles, event types).
 *
 * Exclusions only reduce routine noise: events at error severity or above and the
 * PROTECTED event types are always recorded, so detection rules, integrity evidence
 * and changes to the plugin's own configuration can never be silenced.
 */
class Exclusions {

	/**
	 * Event types that can never be excluded.
	 */
	const PROTECTED_EVENTS = array(
		'failed_login',
		'excessive_failed_logins',
		'role_change',
		'user_access_changed',
		'privilege_escalation',
		'super_admin_granted',
		'super_admin_revoked',
		'file_edited',
		'option_update',
		'secrets_updated',
		'chain_compromised',
		'core_checksum_check',
		'file_integrity_check',
		'plugin_activation',
		'plugin_deactivation',
		'plugin_deletion',
		'personal_data_erased',
		'incident_created',
		'session_terminated',
		'sessions_terminated_all',
	);

	/**
	 * Severities that are never excluded.
	 */
	const ALWAYS_SEVERITIES = array( 'error', 'critical' );

	/**
	 * Whether an event matches an exclusion rule.
	 *
	 * @param array  $settings   Plugin settings.
	 * @param string $event_type Event type.
	 * @param string $severity   Severity.
	 * @param int    $user_id    Acting user ID (0 for system/anonymous).
	 * @param string $username   Acting username.
	 * @return bool
	 */
	public static function is_excluded( array $settings, $event_type, $severity, $user_id, $username ) {
		if ( in_array( $severity, self::ALWAYS_SEVERITIES, true ) || in_array( $event_type, self::PROTECTED_EVENTS, true ) ) {
			return false;
		}

		$types = self::list_value( $settings['exclude_event_types'] ?? '' );
		if ( $types && in_array( $event_type, $types, true ) ) {
			return true;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		$users = self::list_value( $settings['exclude_users'] ?? '' );
		if ( $users && in_array( strtolower( (string) $username ), array_map( 'strtolower', $users ), true ) ) {
			return true;
		}

		$roles = isset( $settings['exclude_roles'] ) && is_array( $settings['exclude_roles'] ) ? $settings['exclude_roles'] : array();
		if ( $roles ) {
			$user = get_userdata( (int) $user_id );
			if ( $user && array_intersect( (array) $user->roles, $roles ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split a comma/whitespace-separated setting into a list.
	 *
	 * @param string|array $value Value.
	 * @return string[]
	 */
	public static function list_value( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', $value ) ) );
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $value ) ) ) );
	}

	/**
	 * Normalise submitted exclusion settings.
	 *
	 * @param array $input Raw values (exclude_users, exclude_roles, exclude_event_types).
	 * @return array|\WP_Error Normalised values, or an error naming protected event types.
	 */
	public static function sanitize( array $input ) {
		$out = array();

		if ( array_key_exists( 'exclude_users', $input ) ) {
			$users                = array_unique( array_filter( array_map( 'sanitize_user', self::list_value( $input['exclude_users'] ) ) ) );
			$out['exclude_users'] = implode( ', ', array_slice( $users, 0, 100 ) );
		}

		if ( array_key_exists( 'exclude_roles', $input ) ) {
			$roles                = is_array( $input['exclude_roles'] ) ? $input['exclude_roles'] : array();
			$wp_roles             = wp_roles();
			$out['exclude_roles'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $roles ), array( $wp_roles, 'is_role' ) ) ) );
		}

		if ( array_key_exists( 'exclude_event_types', $input ) ) {
			$types     = array_unique( array_filter( array_map( 'sanitize_key', self::list_value( $input['exclude_event_types'] ) ) ) );
			$protected = array_values( array_intersect( $types, self::PROTECTED_EVENTS ) );
			if ( $protected ) {
				return new \WP_Error(
					'tbsh_cal_invalid_setting',
					/* translators: %s: comma-separated event types */
					sprintf( __( 'These event types are always logged and cannot be excluded: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $protected ) ),
					array( 'status' => 400 )
				);
			}
			$out['exclude_event_types'] = implode( ', ', array_slice( $types, 0, 200 ) );
		}

		return $out;
	}
}
