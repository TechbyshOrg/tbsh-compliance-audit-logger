<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site structure and design changes (setting: log_site_structure_events):
 * navigation menus, widgets, theme customisations, Customizer publishes and
 * Site Editor entities (templates, template parts, global styles, navigation,
 * synced patterns, additional CSS).
 */
class SiteStructureTracker extends Tracker {

	/**
	 * Setting that switches this tracker on.
	 */
	const SETTING = 'log_site_structure_events';

	/**
	 * Objects already logged in this request (dedupe repeated saves).
	 *
	 * @var array
	 */
	private static $seen = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_create_nav_menu', array( __CLASS__, 'track_menu_created' ), 10, 2 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'track_menu_updated' ) );
		add_action( 'wp_delete_nav_menu', array( __CLASS__, 'track_menu_deleted' ) );

		add_action( 'updated_option', array( __CLASS__, 'track_option' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'track_option_added' ), 10, 2 );
		add_action( 'customize_save_after', array( __CLASS__, 'track_customizer_publish' ) );

		add_action( 'wp_after_insert_post', array( __CLASS__, 'track_design_saved' ), 10, 4 );
		add_action( 'deleted_post', array( __CLASS__, 'track_design_deleted' ), 10, 2 );
	}

	/**
	 * Log once per object per request.
	 *
	 * @param string $key Object key.
	 * @return bool True the first time.
	 */
	private static function first( $key ) {
		if ( isset( self::$seen[ $key ] ) ) {
			return false;
		}
		self::$seen[ $key ] = true;
		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Navigation menus (classic)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Menu created.
	 *
	 * @param int   $menu_id   Menu term ID.
	 * @param array $menu_data Menu data.
	 */
	public static function track_menu_created( $menu_id, $menu_data = array() ) {
		if ( ! self::enabled( self::SETTING ) ) {
			return;
		}
		self::$seen[ 'menu:' . (int) $menu_id ] = true;
		$name = isset( $menu_data['menu-name'] ) ? sanitize_text_field( $menu_data['menu-name'] ) : self::menu_name( $menu_id );

		self::write( self::entry(
			'menu_created',
			'Change Management',
			'info',
			__( 'Navigation menu created', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: menu name */
			sprintf( __( 'Navigation menu "%s" was created.', 'tbsh-compliance-audit-logger' ), $name ),
			array(
				'object_type' => 'nav_menu',
				'object_id'   => (int) $menu_id,
			)
		) );
	}

	/**
	 * Menu or its items saved.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	public static function track_menu_updated( $menu_id ) {
		if ( ! self::enabled( self::SETTING ) || ! self::first( 'menu:' . (int) $menu_id ) ) {
			return;
		}

		self::write( self::entry(
			'menu_updated',
			'Change Management',
			'info',
			__( 'Navigation menu updated', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: menu name */
			sprintf( __( 'Navigation menu "%s" was updated.', 'tbsh-compliance-audit-logger' ), self::menu_name( $menu_id ) ),
			array(
				'object_type' => 'nav_menu',
				'object_id'   => (int) $menu_id,
			)
		) );
	}

	/**
	 * Menu deleted.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	public static function track_menu_deleted( $menu_id ) {
		if ( ! self::enabled( self::SETTING ) ) {
			return;
		}

		self::write( self::entry(
			'menu_deleted',
			'Change Management',
			'warning',
			__( 'Navigation menu deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: %d: menu ID */
			sprintf( __( 'Navigation menu ID %d was deleted.', 'tbsh-compliance-audit-logger' ), (int) $menu_id ),
			array(
				'object_type' => 'nav_menu',
				'object_id'   => (int) $menu_id,
			)
		) );
	}

	/**
	 * Menu name, or "#ID".
	 *
	 * @param int $menu_id Menu ID.
	 * @return string
	 */
	private static function menu_name( $menu_id ) {
		$menu = wp_get_nav_menu_object( $menu_id );
		return $menu ? $menu->name : '#' . (int) $menu_id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Widgets and theme customisations (options)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * First widget of a type or first customisation of a theme (option created).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public static function track_option_added( $option, $value ) {
		self::track_option( $option, array(), $value );
	}

	/**
	 * Widget and theme_mods option changes.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 */
	public static function track_option( $option, $old_value, $value ) {
		$is_widgets    = ( 'sidebars_widgets' === $option || 0 === strpos( $option, 'widget_' ) );
		$is_theme_mods = ( 0 === strpos( $option, 'theme_mods_' ) );
		if ( ! $is_widgets && ! $is_theme_mods ) {
			return;
		}
		// Customizer publishes are logged once as customizer_published; theme switches move widgets/mods themselves.
		if ( did_action( 'customize_save' ) || did_action( 'switch_theme' ) ) {
			return;
		}
		if ( ! self::enabled( self::SETTING ) || ! self::first( 'option:' . $option ) ) {
			return;
		}

		$old     = is_array( $old_value ) ? $old_value : array();
		$new     = is_array( $value ) ? $value : array();
		$changed = self::diff_keys( $old, $new );
		if ( ! $changed ) {
			return;
		}

		if ( 'sidebars_widgets' === $option ) {
			unset( $changed['array_version'] );
			self::write( self::entry(
				'widgets_updated',
				'Change Management',
				'info',
				__( 'Widgets changed', 'tbsh-compliance-audit-logger' ),
				/* translators: %s: comma-separated widget area IDs */
				sprintf( __( 'Widgets were added, removed or moved in: %s.', 'tbsh-compliance-audit-logger' ), implode( ', ', array_keys( $changed ) ) ),
				array(
					'object_type' => 'widget_area',
					'object_id'   => $option,
					'metadata'    => array( 'areas' => array_keys( $changed ) ),
				)
			) );
			return;
		}

		if ( $is_widgets ) {
			$base = substr( $option, strlen( 'widget_' ) );
			unset( $changed['_multiwidget'] );
			self::write( self::entry(
				'widgets_updated',
				'Change Management',
				'info',
				__( 'Widgets changed', 'tbsh-compliance-audit-logger' ),
				/* translators: %s: widget type (id base) */
				sprintf( __( 'Settings of "%s" widgets were changed.', 'tbsh-compliance-audit-logger' ), $base ),
				array(
					'object_type' => 'widget',
					'object_id'   => $base,
					'metadata'    => array( 'instances' => array_keys( $changed ) ),
				)
			) );
			return;
		}

		$theme = substr( $option, strlen( 'theme_mods_' ) );
		self::write( self::entry(
			'theme_mods_updated',
			'Change Management',
			'info',
			__( 'Theme customisations changed', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: theme directory name, 2: comma-separated setting names */
			sprintf( __( 'Customisations of theme "%1$s" were changed: %2$s.', 'tbsh-compliance-audit-logger' ), $theme, implode( ', ', array_keys( $changed ) ) ),
			array(
				'object_type' => 'theme',
				'object_id'   => $theme,
				'metadata'    => array( 'changed_keys' => $changed ),
			)
		) );
	}

	/**
	 * Customizer changeset published.
	 *
	 * @param \WP_Customize_Manager $manager Customizer manager.
	 */
	public static function track_customizer_publish( $manager ) {
		if ( ! self::enabled( self::SETTING ) || ! $manager instanceof \WP_Customize_Manager ) {
			return;
		}
		$settings = array_map( 'sanitize_text_field', array_keys( $manager->unsanitized_post_values() ) );

		self::write( self::entry(
			'customizer_published',
			'Change Management',
			'notice',
			__( 'Customizer changes published', 'tbsh-compliance-audit-logger' ),
			/* translators: %d: number of changed settings */
			sprintf( _n( 'Customizer changes were published (%d setting).', 'Customizer changes were published (%d settings).', count( $settings ), 'tbsh-compliance-audit-logger' ), count( $settings ) ),
			array(
				'object_type' => 'customizer',
				'object_id'   => get_stylesheet(),
				'metadata'    => array( 'settings' => array_slice( $settings, 0, 50 ) ),
			)
		) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Site Editor and other design entities (non-public post types)
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Post types tracked as design entities.
	 *
	 * @return string[]
	 */
	public static function design_post_types() {
		/**
		 * Filter the non-public post types logged as site design changes.
		 * Public post types are always logged as content and are ignored here.
		 *
		 * @param string[] $post_types Post type names.
		 */
		$types = (array) apply_filters(
			'tbsh_cal_site_editor_post_types',
			array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'custom_css' )
		);
		return array_values( array_diff( $types, get_post_types( array( 'public' => true ), 'names' ) ) );
	}

	/**
	 * Design entity created, updated or trashed.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before Post before the update.
	 */
	public static function track_design_saved( $post_id, $post, $update, $post_before = null ) {
		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || ! in_array( $post->post_type, self::design_post_types(), true ) ) {
			return;
		}
		if ( ! self::enabled( self::SETTING ) || ! self::first( 'post:' . (int) $post_id ) ) {
			return;
		}

		$label = self::type_label( $post->post_type );
		$name  = self::design_name( $post );
		$from  = $post_before instanceof \WP_Post ? $post_before->post_status : '';

		if ( ! $update || 'auto-draft' === $from ) {
			// Core creates the global styles record automatically when the Site Editor is first opened.
			if ( 'wp_global_styles' === $post->post_type ) {
				return;
			}
			$type     = 'design_created';
			$severity = 'info';
			$title    = __( 'Site design item created', 'tbsh-compliance-audit-logger' );
			/* translators: 1: item type (e.g. Template), 2: item name */
			$message = sprintf( __( '%1$s "%2$s" was created.', 'tbsh-compliance-audit-logger' ), $label, $name );
		} elseif ( 'trash' === $post->post_status && 'trash' !== $from ) {
			$type     = 'design_trashed';
			$severity = 'warning';
			$title    = __( 'Site design item trashed', 'tbsh-compliance-audit-logger' );
			/* translators: 1: item type (e.g. Template), 2: item name */
			$message = sprintf( __( '%1$s "%2$s" was moved to the trash.', 'tbsh-compliance-audit-logger' ), $label, $name );
		} else {
			$type     = 'design_updated';
			$severity = 'info';
			$title    = __( 'Site design item updated', 'tbsh-compliance-audit-logger' );
			/* translators: 1: item type (e.g. Template), 2: item name */
			$message = sprintf( __( '%1$s "%2$s" was updated.', 'tbsh-compliance-audit-logger' ), $label, $name );
		}

		self::write( self::entry(
			$type,
			'Change Management',
			$severity,
			$title,
			$message,
			array(
				'object_type' => $post->post_type,
				'object_id'   => (int) $post_id,
				'metadata'    => array(
					'name'   => $name,
					'status' => array(
						'from' => $from,
						'to'   => $post->post_status,
					),
				),
			)
		) );
	}

	/**
	 * Design entity deleted (for templates this is "reset to the theme's version").
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public static function track_design_deleted( $post_id, $post = null ) {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::design_post_types(), true ) || ! self::enabled( self::SETTING ) ) {
			return;
		}

		self::write( self::entry(
			'design_deleted',
			'Change Management',
			'warning',
			__( 'Site design item deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: item type (e.g. Template), 2: item name */
			sprintf( __( '%1$s "%2$s" was deleted.', 'tbsh-compliance-audit-logger' ), self::type_label( $post->post_type ), self::design_name( $post ) ),
			array(
				'object_type' => $post->post_type,
				'object_id'   => (int) $post_id,
			)
		) );
	}

	/**
	 * Singular post type label.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function type_label( $post_type ) {
		$object = get_post_type_object( $post_type );
		return $object ? $object->labels->singular_name : $post_type;
	}

	/**
	 * Display name of a design entity.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function design_name( $post ) {
		$name = '' !== $post->post_title ? $post->post_title : $post->post_name;
		return self::display( $name, 120 );
	}
}
