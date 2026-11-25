<?php
/**
 * Compatibility with the discontinued separate edition of this plugin.
 *
 * That edition loads before this plugin (its folder name sorts first), defines the
 * TBSH_CAL_* path constants with its own values and registers an autoloader that
 * serves this plugin's namespace from a stale bundled copy. Booting normally in that
 * state is fatal, so when it is detected this plugin stays dormant for the request,
 * deactivates the old edition and lets the next request load cleanly. All data is
 * then migrated by Database\Migrator.
 *
 * This file is loaded with require_once (never via the autoloader) and must not
 * depend on any other class of this plugin.
 *
 * @package TBSHComplianceAuditLogger
 */

namespace TBSHComplianceAuditLogger\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects and retires the separate edition.
 */
class ProCompat {

	/**
	 * Default plugin basename of the separate edition.
	 */
	const LEGACY_BASENAME = 'tbsh-compliance-audit-logger-pro/tbsh-compliance-audit-logger-pro.php';

	/**
	 * Option set while the "features now included" notice should be shown.
	 */
	const NOTICE_OPTION = 'tbsh_cal_notice_merged';

	/**
	 * Whether the separate edition is loaded in this request.
	 *
	 * @return bool
	 */
	public static function legacy_loaded() {
		return defined( 'TBSH_CAL_PRO_VERSION' );
	}

	/**
	 * Boot in dormant mode: do nothing except retire the separate edition.
	 *
	 * @param string $main_file Absolute path of this plugin's main file.
	 */
	public static function boot_conflict_mode( $main_file ) {
		// Activation while the old edition is loaded: defer setup to the next clean request.
		register_activation_hook( $main_file, array( __CLASS__, 'flag_needs_setup' ) );

		add_action( 'init', array( __CLASS__, 'deactivate_legacy' ), 0 );
	}

	/**
	 * Activation callback in dormant mode.
	 */
	public static function flag_needs_setup() {
		update_option( 'tbsh_cal_needs_setup', 1, true );
	}

	/**
	 * Deactivate the separate edition and reload admin screens cleanly.
	 */
	public static function deactivate_legacy() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$basename = defined( 'TBSH_CAL_PRO_BASENAME' ) ? TBSH_CAL_PRO_BASENAME : self::LEGACY_BASENAME;
		$network  = is_multisite() && is_plugin_active_for_network( $basename );

		// Silent: the old edition's own deactivation routine would run its stale code.
		deactivate_plugins( $basename, true, $network );

		update_option( self::NOTICE_OPTION, 1, true );
		update_option( 'tbsh_cal_needs_setup', 1, true );

		// Reload plain admin page views so the next request boots this plugin normally.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( is_admin() && 'GET' === $method && ! wp_doing_ajax() && ! wp_doing_cron() && isset( $_SERVER['REQUEST_URI'] ) ) {
			wp_safe_redirect( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			exit;
		}
	}

	/**
	 * Normal mode: register the admin notice.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_tbsh_cal_dismiss_merged', array( __CLASS__, 'dismiss_notice' ) );
	}

	/**
	 * Show the "features now included" notice to administrators.
	 */
	public static function render_notice() {
		if ( ! get_option( self::NOTICE_OPTION ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=tbsh_cal_dismiss_merged' ), 'tbsh_cal_dismiss_merged' );
		$open    = admin_url( 'admin.php?page=tbsh-compliance-audit-logger' );
		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Compliance Audit Log (formerly Compliance Audit Trail & Evidence Logger) now includes every feature.', 'tbsh-compliance-audit-logger' ); ?></strong>
				<?php esc_html_e( 'Your incidents, risks, vendors, reports, vaults, alert settings and auditor links were migrated. The previous separate edition was deactivated and can now be deleted from the Plugins screen — delete it while this plugin stays active.', 'tbsh-compliance-audit-logger' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $open ); ?>"><?php esc_html_e( 'Open Compliance Logs', 'tbsh-compliance-audit-logger' ); ?></a>
				<a class="button" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'Dismiss', 'tbsh-compliance-audit-logger' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss handler.
	 */
	public static function dismiss_notice() {
		check_admin_referer( 'tbsh_cal_dismiss_merged' );
		if ( current_user_can( 'activate_plugins' ) ) {
			delete_option( self::NOTICE_OPTION );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
