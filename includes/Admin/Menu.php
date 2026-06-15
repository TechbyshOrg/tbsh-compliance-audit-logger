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
	 * Enqueue styles and JS assets.
	 */
	public static function enqueue_assets( $hook ) {
		// Only enqueue on our specific admin page.
		if ( 'toplevel_page_tbsh-compliance-audit-logger' !== $hook ) {
			return;
		}

		$build_path = TBSH_CAL_PATH . 'build/index.js';
		$build_url  = TBSH_CAL_URL . 'build/index.js';

		$asset_file  = TBSH_CAL_PATH . 'build/index.asset.php';
		$deps        = array( 'wp-element', 'wp-i18n', 'wp-api-fetch' );
		$ver         = TBSH_CAL_VERSION;

		if ( file_exists( $asset_file ) ) {
			$assets = require $asset_file;
			$deps   = $assets['dependencies'];
			$ver    = $assets['version'];
		}

		// Enqueue standard CSS stylesheet.
		wp_enqueue_style(
			'tbsh-cal-admin-css',
			TBSH_CAL_URL . 'assets/css/admin.css',
			array(),
			$ver
		);

		// Enqueue built JS file.
		if ( file_exists( $build_path ) ) {
			wp_enqueue_script(
				'tbsh-cal-admin-js',
				$build_url,
				$deps,
				$ver,
				true
			);

			// Gather user capabilities list.
			$capabilities = array(
				'view_logs'       => AccessControl::check( 'tbsh_cal_view_logs' ),
				'view_evidence'   => AccessControl::check( 'tbsh_cal_view_evidence' ),
				'export_data'     => AccessControl::check( 'tbsh_cal_export_data' ),
				'manage_settings' => AccessControl::check( 'tbsh_cal_manage_settings' ),
				'verify_integrity'=> AccessControl::check( 'tbsh_cal_verify_integrity' ),
			);

			// Localize variables.
			wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalApiSettings', array(
				'root'         => esc_url_raw( get_rest_url() ),
				'namespace'    => 'tbsh-compliance-audit-logger/v1',
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'site_id'      => get_current_blog_id(),
				'capabilities' => $capabilities,
				'settings'     => get_option( 'tbsh_cal_settings', array() ),
			) );

			wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalIcons', SVGHelper::get_icons() );
		}
	}
}
