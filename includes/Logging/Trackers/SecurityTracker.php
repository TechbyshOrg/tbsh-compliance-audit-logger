<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security-relevant events that are always logged (no toggle):
 * file editor edits, role/capability grants outside set_role(), Super Admin changes,
 * privacy requests, content exports and multisite network changes.
 */
class SecurityTracker extends Tracker {

	/**
	 * File editor save being watched in this request.
	 *
	 * @var array|null
	 */
	private static $pending_edit = null;

	/**
	 * Capability meta changes in this request: "blog:user" => array( user_id, blog_id, before, after ).
	 *
	 * @var array
	 */
	private static $access = array();

	/**
	 * Users whose role was set with set_role() (already logged as role_change): user ID => true.
	 *
	 * @var array
	 */
	private static $role_set = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_edit-theme-plugin-file', array( __CLASS__, 'capture_file_edit' ), 0 );

		add_action( 'add_user_meta', array( __CLASS__, 'capture_caps_add' ), 10, 3 );
		add_action( 'update_user_meta', array( __CLASS__, 'capture_caps_update' ), 10, 4 );
		add_action( 'set_user_role', array( __CLASS__, 'note_role_set' ), 1 );

		add_action( 'granted_super_admin', array( __CLASS__, 'track_super_admin_granted' ) );
		add_action( 'revoked_super_admin', array( __CLASS__, 'track_super_admin_revoked' ) );

		add_action( 'export_wp', array( __CLASS__, 'track_content_export' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'track_privacy_request_created' ), 10, 3 );
		add_action( 'user_request_action_confirmed', array( __CLASS__, 'track_privacy_request_confirmed' ), 20 );
		add_action( 'wp_privacy_personal_data_export_file_created', array( __CLASS__, 'track_personal_data_exported' ), 10, 4 );
		add_action( 'wp_privacy_personal_data_erased', array( __CLASS__, 'track_personal_data_erased' ) );

		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( __CLASS__, 'track_site_created' ), 20 );
			add_action( 'wp_delete_site', array( __CLASS__, 'track_site_deleted' ) );
			add_action( 'wp_update_site', array( __CLASS__, 'track_site_updated' ), 10, 2 );
			add_action( 'update_site_option', array( __CLASS__, 'track_network_option' ), 10, 4 );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Theme and plugin file editor
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Record the target file's hash before core saves it (core checks nonce and capability itself).
	 */
	public static function capture_file_edit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Read-only; wp_edit_theme_plugin_file() verifies the nonce before writing.
		$file   = isset( $_POST['file'] ) ? (string) wp_unslash( $_POST['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Path checked with validate_file() and realpath().
		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $file || 0 !== validate_file( $file ) ) {
			return;
		}

		if ( '' !== $plugin ) {
			if ( ! current_user_can( 'edit_plugins' ) ) {
				return;
			}
			$kind    = 'plugin';
			$subject = $plugin;
			$base    = WP_PLUGIN_DIR;
		} elseif ( '' !== $theme ) {
			$wp_theme = wp_get_theme( $theme );
			if ( ! current_user_can( 'edit_themes' ) || ! $wp_theme->exists() ) {
				return;
			}
			$kind    = 'theme';
			$subject = $theme;
			$base    = $wp_theme->get_stylesheet_directory();
		} else {
			return;
		}

		$real_base = realpath( $base );
		$real_file = realpath( $base . '/' . $file );
		if ( ! $real_base || ! $real_file || 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_base ) ) ) ) {
			return;
		}

		self::$pending_edit = array(
			'path'    => $real_file,
			'hash'    => (string) hash_file( 'sha256', $real_file ),
			'file'    => sanitize_text_field( $file ),
			'kind'    => $kind,
			'subject' => $subject,
		);
		add_action( 'shutdown', array( __CLASS__, 'track_file_edit' ), 1 );
	}

	/**
	 * Log the edit if the file content actually changed (rejected or rolled-back saves are ignored).
	 */
	public static function track_file_edit() {
		$edit               = self::$pending_edit;
		self::$pending_edit = null;
		if ( ! $edit ) {
			return;
		}

		clearstatcache( true, $edit['path'] );
		$hash = is_readable( $edit['path'] ) ? (string) hash_file( 'sha256', $edit['path'] ) : '';
		if ( $hash === $edit['hash'] ) {
			return;
		}

		if ( 'plugin' === $edit['kind'] ) {
			$title = __( 'Plugin file edited', 'tbsh-compliance-audit-logger' );
			/* translators: 1: file path, 2: plugin file */
			$message = sprintf( __( 'File "%1$s" of plugin "%2$s" was edited in the WordPress file editor.', 'tbsh-compliance-audit-logger' ), $edit['file'], $edit['subject'] );
		} else {
			$title = __( 'Theme file edited', 'tbsh-compliance-audit-logger' );
			/* translators: 1: file path, 2: theme directory name */
			$message = sprintf( __( 'File "%1$s" of theme "%2$s" was edited in the WordPress file editor.', 'tbsh-compliance-audit-logger' ), $edit['file'], $edit['subject'] );
		}

		self::write( self::entry(
			'file_edited',
			'Change Management',
			'error',
			$title,
			$message,
			array(
				'object_type'     => $edit['kind'],
				'object_id'       => $edit['file'],
				'compliance_tags' => 'Change Management, Security Monitoring',
				'metadata'        => array(
					$edit['kind']  => $edit['subject'],
					'file'         => $edit['file'],
					'sha256_from'  => $edit['hash'],
					'sha256_to'    => $hash,
				),
			)
		) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Roles and capabilities
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Capability meta added.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	public static function capture_caps_add( $user_id, $meta_key, $value ) {
		self::capture_caps( $user_id, $meta_key, $value );
	}

	/**
	 * Capability meta about to be updated.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	public static function capture_caps_update( $meta_id, $user_id, $meta_key, $value ) {
		self::capture_caps( $user_id, $meta_key, $value );
	}

	/**
	 * Remember the first "before" and last "after" capability set of a user in this request.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	private static function capture_caps( $user_id, $meta_key, $value ) {
		$blog_id = self::capability_blog( $meta_key );
		if ( null === $blog_id ) {
			return;
		}

		$key = $blog_id . ':' . (int) $user_id;
		if ( ! isset( self::$access[ $key ] ) ) {
			$before               = get_user_meta( $user_id, $meta_key, true );
			self::$access[ $key ] = array(
				'user_id' => (int) $user_id,
				'blog_id' => $blog_id,
				'before'  => is_array( $before ) ? $before : array(),
			);
			if ( doing_action( 'shutdown' ) ) {
				self::$access[ $key ]['after'] = is_array( $value ) ? $value : array();
				self::resolve_access_changes();
				return;
			}
			self::defer( array( __CLASS__, 'resolve_access_changes' ) );
		}
		self::$access[ $key ]['after'] = is_array( $value ) ? $value : array();
	}

	/**
	 * Site ID a capabilities meta key belongs to, or null for other keys.
	 *
	 * @param string $meta_key Meta key.
	 * @return int|null
	 */
	private static function capability_blog( $meta_key ) {
		global $wpdb;
		if ( ! is_string( $meta_key ) || ! preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:(\d+)_)?capabilities$/', $meta_key, $m ) ) {
			return null;
		}
		if ( ! empty( $m[1] ) ) {
			return (int) $m[1];
		}
		return is_multisite() ? (int) get_main_site_id() : 1;
	}

	/**
	 * Role set through set_role(): EventTracker already logs it as role_change.
	 *
	 * @param int $user_id User ID.
	 */
	public static function note_role_set( $user_id ) {
		self::$role_set[ (int) $user_id ] = true;
	}

	/**
	 * Log role/capability changes that did not go through set_role().
	 */
	public static function resolve_access_changes() {
		$changes      = self::$access;
		self::$access = array();
		$roles        = wp_roles();

		foreach ( $changes as $change ) {
			if ( isset( self::$role_set[ $change['user_id'] ] ) ) {
				continue;
			}

			$before  = array_keys( array_filter( $change['before'] ) );
			$after   = array_keys( array_filter( $change['after'] ) );
			$granted = array_values( array_diff( $after, $before ) );
			$revoked = array_values( array_diff( $before, $after ) );
			if ( ! $granted && ! $revoked ) {
				continue;
			}

			$split = function ( $names ) use ( $roles ) {
				$out = array( 'roles' => array(), 'caps' => array() );
				foreach ( $names as $name ) {
					$out[ $roles->is_role( $name ) ? 'roles' : 'caps' ][] = sanitize_key( $name );
				}
				return $out;
			};
			$added   = $split( $granted );
			$removed = $split( $revoked );

			$parts = array();
			if ( $added['roles'] ) {
				/* translators: %s: comma-separated role names */
				$parts[] = sprintf( __( 'roles added: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $added['roles'] ) );
			}
			if ( $removed['roles'] ) {
				/* translators: %s: comma-separated role names */
				$parts[] = sprintf( __( 'roles removed: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $removed['roles'] ) );
			}
			if ( $added['caps'] ) {
				/* translators: %s: comma-separated capability names */
				$parts[] = sprintf( __( 'capabilities granted: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $added['caps'] ) );
			}
			if ( $removed['caps'] ) {
				/* translators: %s: comma-separated capability names */
				$parts[] = sprintf( __( 'capabilities revoked: %s', 'tbsh-compliance-audit-logger' ), implode( ', ', $removed['caps'] ) );
			}

			$username = self::user_label( $change['user_id'] );
			self::write( self::entry(
				'user_access_changed',
				'Access Control',
				'warning',
				__( 'User roles or capabilities changed', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: username, 2: list of changes */
				sprintf( __( 'Access changed for user "%1$s": %2$s.', 'tbsh-compliance-audit-logger' ), $username, implode( '; ', $parts ) ),
				array(
					'object_type'     => 'user',
					'object_id'       => $change['user_id'],
					'compliance_tags' => 'Access Control, Authorization',
					'metadata'        => array(
						'site_id'       => $change['blog_id'],
						'roles_added'   => $added['roles'],
						'roles_removed' => $removed['roles'],
						'caps_granted'  => $added['caps'],
						'caps_revoked'  => $removed['caps'],
					),
				)
			) );

			$elevated = array_values( array_intersect( array_merge( $added['roles'], $added['caps'] ), self::sensitive_grants() ) );
			if ( $elevated ) {
				self::write( self::entry(
					'privilege_escalation',
					'Security Monitoring',
					'critical',
					__( 'Privilege escalation detected', 'tbsh-compliance-audit-logger' ),
					/* translators: 1: username, 2: role or capability names */
					sprintf( __( 'User "%1$s" was granted elevated access: %2$s.', 'tbsh-compliance-audit-logger' ), $username, implode( ', ', $elevated ) ),
					array(
						'object_type'     => 'user',
						'object_id'       => $change['user_id'],
						'compliance_tags' => 'Security Monitoring, Access Control, Authorization',
						'metadata'        => array( 'granted' => $elevated ),
					)
				) );
			}
		}
	}

	/**
	 * Roles and capabilities whose direct grant counts as privilege escalation.
	 *
	 * @return string[]
	 */
	private static function sensitive_grants() {
		/**
		 * Filter the role and capability names whose grant outside the normal role
		 * assignment (set_role) is logged as privilege_escalation.
		 *
		 * @param string[] $grants Role and capability names.
		 */
		return (array) apply_filters(
			'tbsh_cal_sensitive_capabilities',
			array(
				'administrator',
				'manage_options',
				'install_plugins',
				'activate_plugins',
				'edit_plugins',
				'install_themes',
				'edit_themes',
				'edit_users',
				'create_users',
				'promote_users',
				'delete_users',
				'unfiltered_upload',
				'update_core',
				'manage_network',
				'manage_network_options',
				'manage_network_users',
				'manage_sites',
			)
		);
	}

	/**
	 * Super Admin granted.
	 *
	 * @param int $user_id User ID.
	 */
	public static function track_super_admin_granted( $user_id ) {
		$username = self::user_label( $user_id );
		$args     = array(
			'object_type'     => 'user',
			'object_id'       => (int) $user_id,
			'compliance_tags' => 'Access Control, Authorization',
		);

		self::write( self::entry(
			'super_admin_granted',
			'Access Control',
			'warning',
			__( 'Super Admin privileges granted', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: username */
			sprintf( __( 'User "%s" was granted network Super Admin privileges.', 'tbsh-compliance-audit-logger' ), $username ),
			$args
		) );

		$args['compliance_tags'] = 'Security Monitoring, Access Control, Authorization';
		self::write( self::entry(
			'privilege_escalation',
			'Security Monitoring',
			'critical',
			__( 'Privilege escalation detected', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: username */
			sprintf( __( 'User "%s" was granted network Super Admin privileges.', 'tbsh-compliance-audit-logger' ), $username ),
			$args
		) );
	}

	/**
	 * Super Admin revoked.
	 *
	 * @param int $user_id User ID.
	 */
	public static function track_super_admin_revoked( $user_id ) {
		self::write( self::entry(
			'super_admin_revoked',
			'Access Control',
			'warning',
			__( 'Super Admin privileges revoked', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: username */
			sprintf( __( 'Network Super Admin privileges were revoked from user "%s".', 'tbsh-compliance-audit-logger' ), self::user_label( $user_id ) ),
			array(
				'object_type'     => 'user',
				'object_id'       => (int) $user_id,
				'compliance_tags' => 'Access Control, Authorization',
			)
		) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Data protection: content exports and privacy requests
	 * (request IDs only; requester email addresses are never logged)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Tools > Export download.
	 *
	 * @param array $args Export arguments.
	 */
	public static function track_content_export( $args ) {
		$content = isset( $args['content'] ) ? sanitize_key( (string) $args['content'] ) : 'all';
		self::write( self::entry(
			'content_exported',
			'Operational Security',
			'notice',
			__( 'Site content exported', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: exported content type (all, post, page, ...) */
			sprintf( __( 'Site content was exported with the WordPress export tool (content: %s).', 'tbsh-compliance-audit-logger' ), $content ),
			array(
				'object_type'     => 'export',
				'object_id'       => $content,
				'compliance_tags' => 'Operational Security, Data Protection',
			)
		) );
	}

	/**
	 * New privacy request.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether this is an update.
	 */
	public static function track_privacy_request_created( $post_id, $post, $update ) {
		if ( $update || ! $post instanceof \WP_Post || 'user_request' !== $post->post_type ) {
			return;
		}
		self::write( self::privacy_entry(
			'privacy_request_created',
			'notice',
			__( 'Privacy request created', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: request ID, 2: request type */
			__( 'Privacy request #%1$d (%2$s) was created.', 'tbsh-compliance-audit-logger' ),
			$post
		) );
	}

	/**
	 * Requester confirmed a privacy request.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function track_privacy_request_confirmed( $request_id ) {
		$post = get_post( $request_id );
		if ( ! $post || 'user_request' !== $post->post_type ) {
			return;
		}
		self::write( self::privacy_entry(
			'privacy_request_confirmed',
			'info',
			__( 'Privacy request confirmed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: request ID, 2: request type */
			__( 'Privacy request #%1$d (%2$s) was confirmed by the requester.', 'tbsh-compliance-audit-logger' ),
			$post
		) );
	}

	/**
	 * Personal data export file generated.
	 *
	 * @param string $archive_pathname Archive path.
	 * @param string $archive_url      Archive URL.
	 * @param string $html_pathname    HTML report path.
	 * @param int    $request_id       Request ID.
	 */
	public static function track_personal_data_exported( $archive_pathname, $archive_url, $html_pathname, $request_id ) {
		$post = get_post( $request_id );
		if ( ! $post ) {
			return;
		}
		self::write( self::privacy_entry(
			'personal_data_exported',
			'notice',
			__( 'Personal data exported', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: request ID, 2: request type */
			__( 'A personal data export file was generated for privacy request #%1$d (%2$s).', 'tbsh-compliance-audit-logger' ),
			$post
		) );
	}

	/**
	 * Personal data erasure completed.
	 *
	 * @param int $request_id Request ID.
	 */
	public static function track_personal_data_erased( $request_id ) {
		$post = get_post( $request_id );
		if ( ! $post ) {
			return;
		}
		self::write( self::privacy_entry(
			'personal_data_erased',
			'warning',
			__( 'Personal data erased', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: request ID, 2: request type */
			__( 'Personal data was erased for privacy request #%1$d (%2$s).', 'tbsh-compliance-audit-logger' ),
			$post
		) );
	}

	/**
	 * Build a privacy request entry.
	 *
	 * @param string   $type     Event type.
	 * @param string   $severity Severity.
	 * @param string   $title    Title.
	 * @param string   $format   Message format (%1$d request ID, %2$s request type).
	 * @param \WP_Post $post     Request post.
	 * @return array
	 */
	private static function privacy_entry( $type, $severity, $title, $format, $post ) {
		$action = sanitize_key( $post->post_name );
		$labels = array(
			'export_personal_data' => __( 'personal data export', 'tbsh-compliance-audit-logger' ),
			'remove_personal_data' => __( 'personal data erasure', 'tbsh-compliance-audit-logger' ),
		);

		return self::entry(
			$type,
			'Operational Security',
			$severity,
			$title,
			sprintf( $format, (int) $post->ID, $labels[ $action ] ?? $action ),
			array(
				'object_type'     => 'privacy_request',
				'object_id'       => (int) $post->ID,
				'compliance_tags' => 'Data Protection, GDPR',
				'metadata'        => array( 'request_type' => $action ),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Multisite network
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Site added to the network.
	 *
	 * @param \WP_Site $site Site.
	 */
	public static function track_site_created( $site ) {
		self::write( self::site_entry(
			'site_created',
			'notice',
			__( 'Network site created', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: site ID, 2: site address */
			__( 'Site #%1$d (%2$s) was created in the network.', 'tbsh-compliance-audit-logger' ),
			$site
		) );
	}

	/**
	 * Site deleted from the network.
	 *
	 * @param \WP_Site $site Site.
	 */
	public static function track_site_deleted( $site ) {
		self::write( self::site_entry(
			'site_deleted',
			'warning',
			__( 'Network site deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: site ID, 2: site address */
			__( 'Site #%1$d (%2$s) was deleted from the network.', 'tbsh-compliance-audit-logger' ),
			$site
		) );
	}

	/**
	 * Site address or status (public, archived, spam, deleted, mature) changed.
	 *
	 * @param \WP_Site $new_site New site.
	 * @param \WP_Site $old_site Old site.
	 */
	public static function track_site_updated( $new_site, $old_site ) {
		if ( ! $new_site instanceof \WP_Site || ! $old_site instanceof \WP_Site ) {
			return;
		}

		$changes = array();
		foreach ( array( 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted' ) as $field ) {
			if ( (string) $old_site->$field !== (string) $new_site->$field ) {
				$changes[ $field ] = array(
					'from' => self::display( $old_site->$field ),
					'to'   => self::display( $new_site->$field ),
				);
			}
		}
		if ( ! $changes ) {
			return;
		}

		$summary = array();
		foreach ( $changes as $field => $change ) {
			$summary[] = $field . ': ' . $change['from'] . ' → ' . $change['to'];
		}

		$entry                         = self::site_entry(
			'site_updated',
			'warning',
			__( 'Network site changed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: site ID, 2: site address, 3: list of changes */
			__( 'Site #%1$d (%2$s) was changed: %3$s.', 'tbsh-compliance-audit-logger' ),
			$new_site,
			implode( ', ', $summary )
		);
		$entry['args']['metadata']['changes'] = $changes;
		self::write( $entry );
	}

	/**
	 * Monitored network option changed.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      New value.
	 * @param mixed  $old_value  Old value.
	 * @param int    $network_id Network ID.
	 */
	public static function track_network_option( $option, $value, $old_value, $network_id ) {
		/**
		 * Filter which network (site) options are logged when changed.
		 *
		 * @param string[] $options Network option names.
		 */
		$watched = (array) apply_filters(
			'tbsh_cal_monitored_network_options',
			array( 'registration', 'add_new_users', 'upload_filetypes', 'fileupload_maxk', 'blog_upload_space', 'admin_email', 'illegal_names', 'limited_email_domains', 'banned_email_domains', 'menu_items' )
		);
		if ( ! in_array( $option, $watched, true ) ) {
			return;
		}

		self::write( self::entry(
			'network_option_update',
			'Configuration Management',
			'notice',
			__( 'Network setting changed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: option name, 2: old value, 3: new value */
			sprintf( __( 'Network setting "%1$s" updated from "%2$s" to "%3$s".', 'tbsh-compliance-audit-logger' ), $option, self::display( $old_value ), self::display( $value ) ),
			array(
				'object_type' => 'network_option',
				'object_id'   => $option,
				'metadata'    => array(
					'option'     => $option,
					'network_id' => (int) $network_id,
					'from'       => self::display( $old_value ),
					'to'         => self::display( $value ),
				),
			)
		) );
	}

	/**
	 * Build a network site entry.
	 *
	 * @param string   $type     Event type.
	 * @param string   $severity Severity.
	 * @param string   $title    Title.
	 * @param string   $format   Message format (%1$d site ID, %2$s address, optional %3$s).
	 * @param \WP_Site $site     Site.
	 * @param string   $extra    Optional third format value.
	 * @return array
	 */
	private static function site_entry( $type, $severity, $title, $format, $site, $extra = '' ) {
		$address = $site->domain . $site->path;
		return self::entry(
			$type,
			'Configuration Management',
			$severity,
			$title,
			sprintf( $format, (int) $site->blog_id, $address, $extra ),
			array(
				'object_type'     => 'site',
				'object_id'       => (int) $site->blog_id,
				'compliance_tags' => 'Configuration Management, Change Management',
				'metadata'        => array( 'address' => $address ),
			)
		);
	}
}
