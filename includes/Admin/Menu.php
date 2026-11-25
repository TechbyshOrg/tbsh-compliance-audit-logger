<?php
namespace TBSHComplianceAuditLogger\Admin;

use TBSHComplianceAuditLogger\Helpers\SVGHelper;
use TBSHComplianceAuditLogger\Security\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the administration dashboard page.
 */
class Menu {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_pages' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add admin menu item.
	 */
	public static function add_admin_pages() {
		add_menu_page(
			__( 'Compliance Logs', 'tbsh-compliance-audit-logger' ),
			__( 'Compliance Logs', 'tbsh-compliance-audit-logger' ),
			'tbsh_cal_view_logs',
			'tbsh-compliance-audit-logger',
			array( __CLASS__, 'render_app_wrapper' ),
			'dashicons-shield-alt',
			26
		);
	}

	/**
	 * Render React mounting node.
	 */
	public static function render_app_wrapper() {
		echo '<div id="tbsh-cal-admin-root"></div>';
	}

	/**
	 * Make translations of lazily loaded chunks (build/page-*.js) available.
	 *
	 * WordPress loads JSON translations only for enqueued script files. Chunks are
	 * fetched later by the app, so their translation files are read here and merged
	 * into the text domain before the main script runs.
	 *
	 * @param string $handle Main script handle.
	 */
	public static function chunk_translations( $handle ) {
		$chunks = glob( TBSH_CAL_PATH . 'build/page-*.js' );
		if ( ! $chunks ) {
			return;
		}

		$messages = array();
		foreach ( $chunks as $chunk ) {
			$name        = basename( $chunk, '.js' );
			$chunk_handle = 'tbsh-cal-chunk-' . sanitize_key( $name );
			wp_register_script( $chunk_handle, TBSH_CAL_URL . 'build/' . $name . '.js', array(), TBSH_CAL_VERSION, true );
			$json = load_script_textdomain( $chunk_handle, 'tbsh-compliance-audit-logger', TBSH_CAL_PATH . 'languages' );
			wp_deregister_script( $chunk_handle );
			if ( ! $json ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( isset( $data['locale_data']['messages'] ) && is_array( $data['locale_data']['messages'] ) ) {
				$messages = array_merge( $messages, $data['locale_data']['messages'] );
			}
		}
		if ( ! $messages ) {
			return;
		}

		wp_add_inline_script(
			$handle,
			sprintf( 'wp.i18n.setLocaleData( %s, "tbsh-compliance-audit-logger" );', wp_json_encode( $messages ) ),
			'before'
		);
	}

	/**
	 * Enqueue styles and JS assets.
	 */
	public static function enqueue_assets( $hook ) {
		// Enqueue on our specific admin page or the main dashboard page (for the widget styles).
		if ( 'toplevel_page_tbsh-compliance-audit-logger' !== $hook && 'index.php' !== $hook ) {
			return;
		}

		$ver         = TBSH_CAL_VERSION;
		$asset_file  = TBSH_CAL_PATH . 'build/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$assets = require $asset_file;
			$ver    = $assets['version'];
		}

		// Enqueue standard CSS stylesheet.
		wp_enqueue_style(
			'tbsh-cal-admin-css',
			TBSH_CAL_URL . 'assets/css/admin.css',
			array(),
			$ver
		);

		// Only enqueue React script and settings on our specific page.
		if ( 'toplevel_page_tbsh-compliance-audit-logger' === $hook ) {
			$build_path = TBSH_CAL_PATH . 'build/index.js';
			$build_url  = TBSH_CAL_URL . 'build/index.js';
			$deps        = array( 'wp-element', 'wp-i18n', 'wp-api-fetch' );

			if ( file_exists( $asset_file ) ) {
				$assets = require $asset_file;
				$deps   = $assets['dependencies'];
			}

			if ( file_exists( $build_path ) ) {
				wp_enqueue_script(
					'tbsh-cal-admin-js',
					$build_url,
					$deps,
					$ver,
					true
				);

				wp_set_script_translations( 'tbsh-cal-admin-js', 'tbsh-compliance-audit-logger', TBSH_CAL_PATH . 'languages' );

				// Localize variables. Never include secrets here (see Helpers\Secrets::masked()).
				wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalApiSettings', array(
					'mode'         => 'admin',
					'root'         => esc_url_raw( get_rest_url() ),
					'namespace'    => 'tbsh-compliance-audit-logger/v1',
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'site_id'      => get_current_blog_id(),
					'capabilities' => AccessControl::current_user_caps(),
					// Recipients, hosts and exclusions are only for users who may manage settings.
					'settings'     => current_user_can( 'tbsh_cal_manage_settings' ) ? get_option( 'tbsh_cal_settings', array() ) : array(),
					'today'        => wp_date( 'Y-m-d' ),
					'setupState'   => current_user_can( 'tbsh_cal_manage_settings' ) ? \TBSHComplianceAuditLogger\API\AutomationController::setup_state() : 'complete',
				) );

				// Translations for the page chunks loaded on demand.
				self::chunk_translations( 'tbsh-cal-admin-js' );

				wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalIcons', SVGHelper::get_icons() );
			}
		}
	}
}
