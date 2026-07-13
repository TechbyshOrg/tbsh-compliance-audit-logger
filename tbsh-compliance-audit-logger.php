<?php
/**
 * Plugin Name: Compliance Audit Trail & Evidence Logger
 * Description: Enterprise-grade compliance logging, audit evidence, security monitoring, and integrity verification platform.
 * Version: 1.0.3
 * Author: Techbysh
 * Author URI: https://techbysh.com
 * Text Domain: tbsh-compliance-audit-logger
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TBSHComplianceAuditLogger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Constants.
define( 'TBSH_CAL_VERSION', '1.0.3' );
define( 'TBSH_CAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'TBSH_CAL_URL', plugin_dir_url( __FILE__ ) );
define( 'TBSH_CAL_BASENAME', plugin_basename( __FILE__ ) );

// PSR-4 Autoloader.
spl_autoload_register( function ( $class ) {
	$prefix = 'TBSHComplianceAuditLogger\\';
	$base_dir = TBSH_CAL_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

/**
 * Bootstrap the plugin.
 */
class TBSH_Compliance_Audit_Logger {

	/**
	 * Instance of the plugin.
	 *
	 * @var TBSH_Compliance_Audit_Logger
	 */
	private static $instance = null;

	/**
	 * Get the single instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init();
	}

	/**
	 * Initialize components.
	 */
	private function init() {
		// Register Activation/Deactivation hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Add custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Hook for newly created sites on multisite.
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( $this, 'new_site_created' ) );
		}

		// Initialize backend components.
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		// Add settings link on plugins listing page.
		add_filter( 'plugin_action_links_' . TBSH_CAL_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add settings action link to the plugin list page.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=tbsh-compliance-audit-logger#/settings' ) ) . '">' . __( 'Settings', 'tbsh-compliance-audit-logger' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Run on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is activated network-wide.
	 */
	public function activate( $network_wide = false ) {
		// Install/upgrade DB tables.
		\TBSHComplianceAuditLogger\Database\Schema::install( $network_wide );

		// Setup capabilities.
		\TBSHComplianceAuditLogger\Security\AccessControl::init();

		// Generate a secure salt for hashing IPs and User Agents if not already done.
		if ( ! get_option( 'tbsh_cal_privacy_salt' ) ) {
			update_option( 'tbsh_cal_privacy_salt', wp_generate_password( 64, true, true ) );
		}

		// Initial Settings defaults.
		if ( ! get_option( 'tbsh_cal_settings' ) ) {
			update_option( 'tbsh_cal_settings', array(
				'enable_logging'      => true,
				'min_severity'        => 'info',
				'anonymize_ips'       => true,
				'auto_evidence'       => true,
				'evidence_frequency'  => 'daily',
				'retain_logs'         => 0, // 0 = unlimited
				'cleanup_on_uninstall'=> 'keep', // keep or delete
			) );
		}

		// Set up cron job for daily evidence snapshot and cleanup if needed.
		if ( ! wp_next_scheduled( 'tbsh_cal_cron_job' ) ) {
			wp_schedule_event( time(), 'daily', 'tbsh_cal_cron_job' );
		}

		// Log plugin activation event.
		\TBSHComplianceAuditLogger\Logging\Logger::log(
			'plugin_activation',
			'Operational Security',
			'info',
			__( 'Compliance Audit Trail & Evidence Logger plugin was activated.', 'tbsh-compliance-audit-logger' ),
			__( 'Plugin was activated successfully.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Register weekly schedule for cron.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800,
				'display'  => __( 'Once Weekly', 'tbsh-compliance-audit-logger' ),
			);
		}
		return $schedules;
	}

	/**
	 * Run on new site creation in multisite network.
	 *
	 * @param \WP_Site $site New site object.
	 */
	public function new_site_created( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active_for_network( TBSH_CAL_BASENAME ) ) {
			switch_to_blog( $site->blog_id );
			\TBSHComplianceAuditLogger\Database\Schema::install( false );
			restore_current_blog();
		}
	}

	/**
	 * Run on plugin deactivation.
	 */
	public function deactivate() {
		// Unschedule cron job.
		wp_clear_scheduled_hook( 'tbsh_cal_cron_job' );

		// Log plugin deactivation.
		\TBSHComplianceAuditLogger\Logging\Logger::log(
			'plugin_deactivation',
			'Operational Security',
			'warning',
			__( 'Compliance Audit Trail & Evidence Logger plugin was deactivated.', 'tbsh-compliance-audit-logger' ),
			__( 'Plugin was deactivated successfully.', 'tbsh-compliance-audit-logger' )
		);
	}

	/**
	 * Run when all plugins are loaded.
	 */
	public function plugins_loaded() {
		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Initialize WordPress action trackers.
		\TBSHComplianceAuditLogger\Logging\EventTracker::init();

		// Initialize Admin Interface.
		if ( is_admin() ) {
			\TBSHComplianceAuditLogger\Admin\Menu::init();
			\TBSHComplianceAuditLogger\Admin\DashboardWidget::init();
		}

		// Handle daily cron tasks.
		add_action( 'tbsh_cal_cron_job', array( $this, 'run_cron_tasks' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$controllers = array(
			new \TBSHComplianceAuditLogger\API\DashboardController(),
			new \TBSHComplianceAuditLogger\API\LogsController(),
			new \TBSHComplianceAuditLogger\API\TimelineController(),
			new \TBSHComplianceAuditLogger\API\EvidenceController(),
			new \TBSHComplianceAuditLogger\API\IntegrityController(),
			new \TBSHComplianceAuditLogger\API\ExportController(),
			new \TBSHComplianceAuditLogger\API\SettingsController(),
			new \TBSHComplianceAuditLogger\API\HealthController(),
			new \TBSHComplianceAuditLogger\API\ComplianceController(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Run cron tasks (Evidence Snapshot & Cleanup).
	 */
	public function run_cron_tasks() {
		$settings = get_option( 'tbsh_cal_settings', array() );

		// Capture snapshot.
		if ( ! empty( $settings['auto_evidence'] ) ) {
			\TBSHComplianceAuditLogger\Evidence\Vault::capture_snapshot();
		}

		// Cleanup old logs if retention is set.
		$retention_days = isset( $settings['retain_logs'] ) ? intval( $settings['retain_logs'] ) : 0;
		if ( $retention_days > 0 ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'tbsh_cal_logs';
			$date_limit = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created_at < %s", $table_name, $date_limit ) );
		}
	}
}

// Start the plugin.
TBSH_Compliance_Audit_Logger::get_instance();
