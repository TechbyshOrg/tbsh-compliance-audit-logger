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

		// Application passwords / REST auth.
		add_action( 'wp_create_application_password', array( __CLASS__, 'track_app_password_created' ), 10, 4 );
		add_action( 'wp_delete_application_password', array( __CLASS__, 'track_app_password_deleted' ), 10, 2 );
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'track_app_password_failed' ) );

		// System Events.
		add_action( 'activated_plugin', array( __CLASS__, 'track_plugin_activation' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'track_plugin_deactivation' ) );
		add_action( 'deleted_plugin', array( __CLASS__, 'track_plugin_deletion' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'track_theme_activation' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'track_upgrades' ), 10, 2 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'track_core_update' ) );

		// Content & media (optional via settings).
		add_action( 'transition_post_status', array( __CLASS__, 'track_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'track_post_deleted' ), 10, 2 );
		add_action( 'add_attachment', array( __CLASS__, 'track_attachment_added' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'track_attachment_deleted' ) );

		// Site Events.
		add_action( 'updated_option', array( __CLASS__, 'track_option_update' ), 10, 3 );
	}

	/**
	 * Get filterable monitored options list.
	 *
	 * @return array
	 */
	private static function get_monitored_options() {
		/**
		 * Filter which options trigger configuration change logs.
		 *
		 * @param array $options Option names.
		 */
		return apply_filters( 'tbsh_cal_monitored_options', self::$monitored_options );
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
			/* translators: %s: username */
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
			/* translators: %s: username */
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
		$username = sanitize_user( $username );

		// Log the failure — store attempted username so alerts can query it.
		Logger::log(
			'failed_login',
			'Authentication',
			'warning',
			__( 'Failed login attempt', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: username */
			sprintf( __( 'Failed login attempt for username "%s".', 'tbsh-compliance-audit-logger' ), $username ),
			array(
				'object_type' => 'user',
				'username'    => $username,
				'role'        => 'anonymous',
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
		$time_limit = gmdate( 'Y-m-d H:i:s', time() - 900 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$db_failures = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE event_type = 'failed_login' AND username = %s AND created_at > %s",
			$table_name,
			$username,
			$time_limit
		) ) );

		// Include the current request queue (not yet flushed).
		$failures = $db_failures + Logger::count_queued( 'failed_login', $username );

		if ( $failures >= 5 ) {
			// Avoid duplicate critical alerts in the same request window.
			$alerted = get_transient( 'tbsh_cal_bf_' . md5( $username ) );
			if ( $alerted ) {
				return;
			}
			set_transient( 'tbsh_cal_bf_' . md5( $username ), 1, 15 * MINUTE_IN_SECONDS );

			Logger::log(
				'excessive_failed_logins',
				'Security Monitoring',
				'critical',
				__( 'Excessive login failures detected', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: number of failures, 2: username */
				sprintf( __( 'Multiple failed login attempts (%1$d) detected for user "%2$s" in the last 15 minutes.', 'tbsh-compliance-audit-logger' ), $failures, $username ),
				array(
					'username'        => $username,
					'compliance_tags' => 'Security Monitoring, Incident Detection',
				)
			);

			/**
			 * Fires when excessive failed logins are detected.
			 *
			 * @param string $username Attempted username.
			 * @param int    $failures Failure count.
			 */
			do_action( 'tbsh_cal_excessive_failed_logins', $username, $failures );
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
			/* translators: 1: username, 2: user ID */
			sprintf( __( 'New user registered: "%1$s" (ID: %2$d).', 'tbsh-compliance-audit-logger' ), $user->user_login, $user_id ),
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
			/* translators: 1: username, 2: user ID */
			sprintf( __( 'User account deleted: "%1$s" (ID: %2$d).', 'tbsh-compliance-audit-logger' ), $name, $user_id ),
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
				/* translators: %s: username */
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
				/* translators: %s: username */
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
			/* translators: 1: username, 2: old role(s), 3: new role */
			sprintf( __( 'Role changed for user "%1$s" from [%2$s] to [%3$s].', 'tbsh-compliance-audit-logger' ), $username, $old_role_str, $role ),
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
				/* translators: %s: username */
				sprintf( __( 'User "%s" was granted Administrator privileges.', 'tbsh-compliance-audit-logger' ), $username ),
				array(
					'object_type'     => 'user',
					'object_id'       => $user_id,
					'compliance_tags' => 'Security Monitoring, Access Control, Authorization',
				)
			);

			if ( class_exists( '\TBSHComplianceAuditLogger\Alerts\EmailAlerts' ) ) {
				\TBSHComplianceAuditLogger\Alerts\EmailAlerts::on_privilege_escalation( $username );
			}
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
			/* translators: %s: username */
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
			/* translators: %s: plugin file path */
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
			/* translators: %s: plugin file path */
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
			/* translators: %s: theme name */
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
					/* translators: 1: action performed, 2: plugin file path */
					sprintf( __( 'Plugin %1$s: "%2$s".', 'tbsh-compliance-audit-logger' ), $action, $plugin ),
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
					/* translators: 1: action performed, 2: theme name */
					sprintf( __( 'Theme %1$s: "%2$s".', 'tbsh-compliance-audit-logger' ), $action, $theme ),
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
			/* translators: %s: WordPress version number */
			sprintf( __( 'WordPress updated to version %s.', 'tbsh-compliance-audit-logger' ), $wp_version ),
			array(
				'object_type'     => 'core',
				'object_id'       => $wp_version,
				'compliance_tags' => 'Change Management, Operational Security',
			)
		);
	}

	/**
	 * Track plugin deletion.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $deleted Whether deletion succeeded.
	 */
	public static function track_plugin_deletion( $plugin, $deleted = true ) {
		Logger::log(
			'plugin_deletion',
			'Change Management',
			'warning',
			__( 'Plugin deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: plugin file path */
			sprintf( __( 'Plugin deleted: "%s".', 'tbsh-compliance-audit-logger' ), $plugin ),
			array(
				'object_type' => 'plugin',
				'object_id'   => $plugin,
				'metadata'    => array( 'deleted' => (bool) $deleted ),
			)
		);
	}

	/**
	 * Track application password creation.
	 */
	public static function track_app_password_created( $user_id, $item, $new_password, $args = array() ) {
		$user     = get_userdata( $user_id );
		$app_name = '';
		if ( is_array( $item ) && isset( $item['name'] ) ) {
			$app_name = $item['name'];
		} elseif ( is_array( $args ) && isset( $args['name'] ) ) {
			$app_name = $args['name'];
		}
		Logger::log(
			'app_password_created',
			'Authentication',
			'warning',
			__( 'Application password created', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: app name, 2: username */
			sprintf( __( 'Application password "%1$s" created for user "%2$s".', 'tbsh-compliance-audit-logger' ), $app_name, $user ? $user->user_login : '#' . $user_id ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'metadata'    => array( 'app_name' => $app_name ),
			)
		);
	}

	/**
	 * Track application password deletion.
	 */
	public static function track_app_password_deleted( $user_id, $item ) {
		$user = get_userdata( $user_id );
		$name = is_array( $item ) && isset( $item['name'] ) ? $item['name'] : '';
		Logger::log(
			'app_password_deleted',
			'Authentication',
			'info',
			__( 'Application password deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: app name, 2: username */
			sprintf( __( 'Application password "%1$s" deleted for user "%2$s".', 'tbsh-compliance-audit-logger' ), $name, $user ? $user->user_login : '#' . $user_id ),
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
			)
		);
	}

	/**
	 * Track failed application password authentication.
	 *
	 * @param \WP_Error $error Error object.
	 */
	public static function track_app_password_failed( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : __( 'Unknown authentication failure.', 'tbsh-compliance-audit-logger' );
		Logger::log(
			'app_password_failed',
			'Authentication',
			'warning',
			__( 'Application password authentication failed', 'tbsh-compliance-audit-logger' ),
			$message,
			array(
				'compliance_tags' => 'Authentication, Security Monitoring',
			)
		);
	}

	/**
	 * Track post status transitions for public post types.
	 */
	public static function track_post_status( $new_status, $old_status, $post ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( isset( $settings['log_content_events'] ) && ! $settings['log_content_events'] ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}

		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post->post_type, $public_types, true ) ) {
			return;
		}

		// Ignore auto-draft noise.
		if ( in_array( $new_status, array( 'auto-draft', 'inherit' ), true ) && in_array( $old_status, array( 'new', 'auto-draft' ), true ) ) {
			return;
		}

		Logger::log(
			'post_status_change',
			'Change Management',
			'info',
			__( 'Content status changed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: post title, 2: old status, 3: new status */
			sprintf( __( '"%1$s" changed from %2$s to %3$s.', 'tbsh-compliance-audit-logger' ), $post->post_title, $old_status, $new_status ),
			array(
				'object_type' => $post->post_type,
				'object_id'   => $post->ID,
				'metadata'    => array(
					'from' => $old_status,
					'to'   => $new_status,
				),
			)
		);
	}

	/**
	 * Track post deletion.
	 */
	public static function track_post_deleted( $post_id, $post = null ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( isset( $settings['log_content_events'] ) && ! $settings['log_content_events'] ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post ) {
			return;
		}
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post->post_type, $public_types, true ) ) {
			return;
		}

		Logger::log(
			'post_deleted',
			'Change Management',
			'warning',
			__( 'Content deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: post type, 2: post title, 3: post ID */
			sprintf( __( '%1$s "%2$s" (ID %3$d) was deleted.', 'tbsh-compliance-audit-logger' ), $post->post_type, $post->post_title, $post_id ),
			array(
				'object_type' => $post->post_type,
				'object_id'   => $post_id,
			)
		);
	}

	/**
	 * Track media upload.
	 */
	public static function track_attachment_added( $attachment_id ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( isset( $settings['log_media_events'] ) && ! $settings['log_media_events'] ) {
			return;
		}
		$file = get_attached_file( $attachment_id );
		Logger::log(
			'media_uploaded',
			'Change Management',
			'info',
			__( 'Media uploaded', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: attachment ID, 2: filename */
			sprintf( __( 'Media file uploaded (ID %1$d): %2$s.', 'tbsh-compliance-audit-logger' ), $attachment_id, $file ? basename( $file ) : '' ),
			array(
				'object_type' => 'attachment',
				'object_id'   => $attachment_id,
			)
		);
	}

	/**
	 * Track media deletion.
	 */
	public static function track_attachment_deleted( $attachment_id ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( isset( $settings['log_media_events'] ) && ! $settings['log_media_events'] ) {
			return;
		}
		Logger::log(
			'media_deleted',
			'Change Management',
			'warning',
			__( 'Media deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: %d: attachment ID */
			sprintf( __( 'Media attachment ID %d was deleted.', 'tbsh-compliance-audit-logger' ), $attachment_id ),
			array(
				'object_type' => 'attachment',
				'object_id'   => $attachment_id,
			)
		);
	}

	/**
	 * Track core settings options updates.
	 */
	public static function track_option_update( $option, $old_value, $value ) {
		if ( ! in_array( $option, self::get_monitored_options(), true ) ) {
			return;
		}

		// Format display values to avoid printing big settings array.
		$old_disp = is_scalar( $old_value ) ? $old_value : '[Array/Object]';
		$new_disp = is_scalar( $value ) ? $value : '[Array/Object]';

		$metadata = array(
			'option' => $option,
		);

		// For plugin settings, log only changed keys — never dump full arrays.
		if ( 'tbsh_cal_settings' === $option && is_array( $old_value ) && is_array( $value ) ) {
			$changed = array();
			$keys    = array_unique( array_merge( array_keys( $old_value ), array_keys( $value ) ) );
			foreach ( $keys as $key ) {
				$from = isset( $old_value[ $key ] ) ? $old_value[ $key ] : null;
				$to   = isset( $value[ $key ] ) ? $value[ $key ] : null;
				if ( $from !== $to ) {
					$changed[ $key ] = array(
						'from' => is_scalar( $from ) ? $from : '[complex]',
						'to'   => is_scalar( $to ) ? $to : '[complex]',
					);
				}
			}
			$metadata['changed_keys'] = $changed;
		} elseif ( is_scalar( $old_value ) && is_scalar( $value ) ) {
			$metadata['from'] = $old_value;
			$metadata['to']   = $value;
		} else {
			$metadata['from'] = '[complex]';
			$metadata['to']   = '[complex]';
		}

		Logger::log(
			'option_update',
			'Configuration Management',
			'info',
			__( 'System setting changed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: option name, 2: old value, 3: new value */
			sprintf( __( 'Setting "%1$s" updated from "%2$s" to "%3$s".', 'tbsh-compliance-audit-logger' ), $option, $old_disp, $new_disp ),
			array(
				'object_type' => 'option',
				'object_id'   => $option,
				'metadata'    => $metadata,
			)
		);
	}
}
