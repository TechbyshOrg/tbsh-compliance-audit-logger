<?php
namespace TBSHComplianceAuditLogger\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to WordPress core events and logs them.
 */
class EventTracker {

	/**
	 * Options we want to monitor for changes.
	 */
	private static $monitored_options = array(
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'users_can_register',
		'default_role',
		'permalink_structure',
		'admin_email',
		'tbsh_cal_settings',
	);

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// Ensure Logger is initialized.
		Logger::init();

		// User Events.
		add_action( 'wp_login', array( __CLASS__, 'track_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'track_logout' ) );
		add_action( 'wp_login_failed', array( __CLASS__, 'track_login_failed' ) );
		add_action( 'user_register', array( __CLASS__, 'track_user_registration' ) );
		add_action( 'delete_user', array( __CLASS__, 'track_user_deletion' ) );
		add_action( 'profile_update', array( __CLASS__, 'track_profile_update' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'track_role_change' ), 10, 3 );
		add_action( 'password_reset', array( __CLASS__, 'track_password_reset' ), 10, 2 );

		// System Events.
		add_action( 'activated_plugin', array( __CLASS__, 'track_plugin_activation' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'track_plugin_deactivation' ) );
		add_action( 'switch_theme', array( __CLASS__, 'track_theme_activation' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'track_upgrades' ), 10, 2 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'track_core_update' ) );

		// Site Events.
		add_action( 'updated_option', array( __CLASS__, 'track_option_update' ), 10, 3 );
	}

	/**
	 * Track user login.
	 */
	public static function track_login( $user_login, $user ) {
		Logger::log(
			'user_login',
			'Authentication',
			'info',
			__( 'User logged in', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'User "%s" logged in successfully.', 'tbsh-compliance-audit-logger' ), $user_login ),
			array(
				'object_type' => 'user',
				'object_id'   => $user->ID,
				'metadata'    => array(
					'user_login' => $user_login,
					'email'      => $user->user_email,
				),
			)
		);
	}

	/**
	 * Track user logout.
	 */
	public static function track_logout() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return;
		}

		Logger::log(
			'user_logout',
			'Authentication',
			'info',
			__( 'User logged out', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'User "%s" logged out.', 'tbsh-compliance-audit-logger' ), $user->user_login ),
			array(
				'object_type' => 'user',
				'object_id'   => $user->ID,
			)
		);
	}

	/**
	 * Track login failure.
	 */
	public static function track_login_failed( $username ) {
		// Log the failure.
		Logger::log(
			'failed_login',
			'Authentication',
			'warning',
			__( 'Failed login attempt', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Failed login attempt for username "%s".', 'tbsh-compliance-audit-logger' ), $username ),
			array(
				'object_type' => 'user',
				'metadata'    => array( 'attempted_username' => $username ),
			)
		);

		// Check for excessive failed logins.
		self::check_excessive_failed_logins( $username );
	}

	/**
	 * Scan database for too many login failures to report a Security Alert.
	 */
	private static function check_excessive_failed_logins( $username ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		// Query failures for this username in the last 15 minutes.
		$time_limit = date( 'Y-m-d H:i:s', strtotime( '-15 minutes' ) );
		$failures   = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE event_type = 'failed_login' AND username = %s AND created_at > %s",
			$username,
			$time_limit
		) );

		if ( intval( $failures ) >= 5 ) {
			Logger::log(
				'excessive_failed_logins',
				'Security Monitoring',
				'critical',
				__( 'Excessive login failures detected', 'tbsh-compliance-audit-logger' ),
				sprintf( __( 'Multiple failed login attempts (%1$d) detected for user "%2$s" in the last 15 minutes.', 'tbsh-compliance-audit-logger' ), $failures, $username ),
				array(
					'compliance_tags' => 'Security Monitoring, Incident Detection',
				)
			);
		}
	}

	/**
	 * Track registration.
	 */
	public static function track_user_registration( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		Logger::log(
			'user_registration',
			'Identity Management',
			'info',
			__( 'New user registered', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'New user registered: "%s" (ID: %d).', 'tbsh-compliance-audit-logger' ), $user->user_login, $user_id ),
			array(
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'compliance_tags' => 'Access Control, Identity Management',
			)
		);
	}

	/**
	 * Track user deletion.
	 */
	public static function track_user_deletion( $user_id ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->user_login : '#' . $user_id;

		Logger::log(
			'user_deletion',
			'Access Control',
			'warning',
			__( 'User deleted', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'User account deleted: "%s" (ID: %d).', 'tbsh-compliance-audit-logger' ), $name, $user_id ),
			array(
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'compliance_tags' => 'Access Control, Identity Management',
			)
		);
	}

	/**
	 * Track user profile updates.
	 */
	public static function track_profile_update( $user_id, $old_user_data ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$changes = array();

		// Check password change.
		if ( $user->user_pass !== $old_user_data->user_pass ) {
			Logger::log(
				'password_change',
				'Identity Management',
				'warning',
				__( 'User password changed', 'tbsh-compliance-audit-logger' ),
				sprintf( __( 'Password was changed for user "%s".', 'tbsh-compliance-audit-logger' ), $user->user_login ),
				array(
					'object_type' => 'user',
					'object_id'   => $user_id,
				)
			);
			return;
		}

		// Other changes.
		if ( $user->user_email !== $old_user_data->user_email ) {
			$changes['email'] = array( 'from' => $old_user_data->user_email, 'to' => $user->user_email );
		}

		if ( ! empty( $changes ) ) {
			Logger::log(
				'profile_update',
				'Identity Management',
				'info',
				__( 'User profile updated', 'tbsh-compliance-audit-logger' ),
				sprintf( __( 'Profile details updated for user "%s".', 'tbsh-compliance-audit-logger' ), $user->user_login ),
				array(
					'object_type' => 'user',
					'object_id'   => $user_id,
					'metadata'    => $changes,
				)
			);
		}
	}

	/**
	 * Track user role modification.
	 */
	public static function track_role_change( $user_id, $role, $old_roles ) {
		$user     = get_userdata( $user_id );
		$username = $user ? $user->user_login : '#' . $user_id;

		$old_role_str = implode( ', ', $old_roles );

		Logger::log(
			'role_change',
			'Access Control',
			'warning',
			__( 'User role modified', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Role changed for user "%s" from [%s] to [%s].', 'tbsh-compliance-audit-logger' ), $username, $old_role_str, $role ),
			array(
				'object_type'     => 'user',
				'object_id'       => $user_id,
				'compliance_tags' => 'Access Control, Authorization',
				'metadata'        => array(
					'old_roles' => $old_roles,
					'new_role'  => $role,
				),
			)
		);

		// Check for Privilege Escalation / Administrator creation.
		if ( 'administrator' === $role ) {
			Logger::log(
				'privilege_escalation',
				'Security Monitoring',
				'critical',
				__( 'Privilege escalation detected', 'tbsh-compliance-audit-logger' ),
				sprintf( __( 'User "%s" was granted Administrator privileges.', 'tbsh-compliance-audit-logger' ), $username ),
				array(
					'object_type'     => 'user',
					'object_id'       => $user_id,
					'compliance_tags' => 'Security Monitoring, Access Control, Authorization',
				)
			);
		}
	}

	/**
	 * Track password reset.
	 */
	public static function track_password_reset( $user, $new_pass ) {
		Logger::log(
			'password_reset',
			'Identity Management',
			'warning',
			__( 'Password reset completed', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Password was reset for user "%s".', 'tbsh-compliance-audit-logger' ), $user->user_login ),
			array(
				'object_type' => 'user',
				'object_id'   => $user->ID,
			)
		);
	}

	/**
	 * Track plugin activation.
	 */
	public static function track_plugin_activation( $plugin ) {
		Logger::log(
			'plugin_activation',
			'Change Management',
			'info',
			__( 'Plugin activated', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Plugin activated: "%s".', 'tbsh-compliance-audit-logger' ), $plugin ),
			array(
				'object_type' => 'plugin',
				'object_id'   => $plugin,
			)
		);
	}

	/**
	 * Track plugin deactivation.
	 */
	public static function track_plugin_deactivation( $plugin ) {
		Logger::log(
			'plugin_deactivation',
			'Change Management',
			'warning',
			__( 'Plugin deactivated', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Plugin deactivated: "%s".', 'tbsh-compliance-audit-logger' ), $plugin ),
			array(
				'object_type' => 'plugin',
				'object_id'   => $plugin,
			)
		);
	}

	/**
	 * Track theme switch.
	 */
	public static function track_theme_activation( $new_name ) {
		Logger::log(
			'theme_activation',
			'Change Management',
			'info',
			__( 'Theme switched', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Active theme changed to "%s".', 'tbsh-compliance-audit-logger' ), $new_name ),
			array(
				'object_type' => 'theme',
				'object_id'   => $new_name,
			)
		);
	}

	/**
	 * Track plugin and theme installs / updates.
	 */
	public static function track_upgrades( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['action'] ) || empty( $hook_extra['type'] ) ) {
			return;
		}

		$action = $hook_extra['action']; // install or update
		$type   = $hook_extra['type'];   // plugin, theme, or core

		if ( 'plugin' === $type ) {
			$plugins = isset( $hook_extra['plugins'] ) ? $hook_extra['plugins'] : array();
			if ( empty( $plugins ) && isset( $hook_extra['plugin'] ) ) {
				$plugins = array( $hook_extra['plugin'] );
			}
			foreach ( $plugins as $plugin ) {
				Logger::log(
					'plugin_' . $action,
					'Change Management',
					'info',
					'plugin' === $action ? __( 'Plugin installed', 'tbsh-compliance-audit-logger' ) : __( 'Plugin updated', 'tbsh-compliance-audit-logger' ),
					sprintf( __( 'Plugin %s: "%s".', 'tbsh-compliance-audit-logger' ), $action, $plugin ),
					array(
						'object_type' => 'plugin',
						'object_id'   => $plugin,
					)
				);
			}
		} elseif ( 'theme' === $type ) {
			$themes = isset( $hook_extra['themes'] ) ? $hook_extra['themes'] : array();
			if ( empty( $themes ) && isset( $hook_extra['theme'] ) ) {
				$themes = array( $hook_extra['theme'] );
			}
			foreach ( $themes as $theme ) {
				Logger::log(
					'theme_' . $action,
					'Change Management',
					'info',
					'theme' === $action ? __( 'Theme installed', 'tbsh-compliance-audit-logger' ) : __( 'Theme updated', 'tbsh-compliance-audit-logger' ),
					sprintf( __( 'Theme %s: "%s".', 'tbsh-compliance-audit-logger' ), $action, $theme ),
					array(
						'object_type' => 'theme',
						'object_id'   => $theme,
					)
				);
			}
		}
	}

	/**
	 * Track Core update.
	 */
	public static function track_core_update( $wp_version ) {
		Logger::log(
			'core_update',
			'Change Management',
			'info',
			__( 'WordPress Core updated', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'WordPress updated to version %s.', 'tbsh-compliance-audit-logger' ), $wp_version ),
			array(
				'object_type'     => 'core',
				'object_id'       => $wp_version,
				'compliance_tags' => 'Change Management, Operational Security',
			)
		);
	}

	/**
	 * Track core settings options updates.
	 */
	public static function track_option_update( $option, $old_value, $value ) {
		if ( ! in_array( $option, self::$monitored_options, true ) ) {
			return;
		}

		// Format display values to avoid printing big settings array.
		$old_disp = is_scalar( $old_value ) ? $old_value : '[Array/Object]';
		$new_disp = is_scalar( $value ) ? $value : '[Array/Object]';

		Logger::log(
			'option_update',
			'Configuration Management',
			'info',
			__( 'System setting changed', 'tbsh-compliance-audit-logger' ),
			sprintf( __( 'Setting "%1$s" updated from "%2$s" to "%3$s".', 'tbsh-compliance-audit-logger' ), $option, $old_disp, $new_disp ),
			array(
				'object_type' => 'option',
				'object_id'   => $option,
				'metadata'    => array(
					'option' => $option,
					'from'   => $old_value,
					'to'     => $value,
				),
			)
		);
	}
}
