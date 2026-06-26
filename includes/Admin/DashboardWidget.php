<?php
namespace TBSHComplianceAuditLogger\Admin;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an Integrity Status widget to the WordPress Dashboard.
 */
class DashboardWidget {

	/**
	 * Register widget init hook.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
	}

	/**
	 * Add dashboard widget.
	 */
	public static function add_widget() {
		if ( current_user_can( 'tbsh_cal_view_logs' ) ) {
			wp_add_dashboard_widget(
				'tbsh_cal_integrity_status_widget',
				__( 'Audit Trail Integrity Status', 'tbsh-compliance-audit-logger' ),
				array( __CLASS__, 'render_widget' )
			);
		}
	}

	/**
	 * Render the widget content.
	 */
	public static function render_widget() {
		$status_info = ChainVerifier::get_latest_status();
		$status      = $status_info['status'];
		$date        = $status_info['verification_date'];
		$issues      = $status_info['issues_found'];

		$class = 'tbsh-status-unverified';
		$label = __( 'Unverified', 'tbsh-compliance-audit-logger' );
		$desc  = __( 'No integrity scan has been run yet.', 'tbsh-compliance-audit-logger' );

		if ( 'verified' === $status ) {
			$class = 'tbsh-status-verified';
			$label = __( 'Verified', 'tbsh-compliance-audit-logger' );
			$desc  = __( 'Cryptographic chain is solid. No tampering detected.', 'tbsh-compliance-audit-logger' );
		} elseif ( 'warning' === $status ) {
			$class = 'tbsh-status-warning';
			$label = __( 'Warning', 'tbsh-compliance-audit-logger' );
			/* translators: %d: number of issues */
			$desc  = sprintf( __( 'Some sequence checks failed (%d issues).', 'tbsh-compliance-audit-logger' ), $issues );
		} elseif ( 'compromised' === $status ) {
			$class = 'tbsh-status-compromised';
			$label = __( 'Compromised', 'tbsh-compliance-audit-logger' );
			/* translators: %d: number of tampering issues */
			$desc  = sprintf( __( 'CRITICAL: Cryptographic chain validation failed! %d tampering issues found.', 'tbsh-compliance-audit-logger' ), $issues );
		}

		?>
		<div class="tbsh-widget-container">
			<span class="tbsh-widget-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
			<p><strong><?php echo esc_html( $desc ); ?></strong></p>
			<?php if ( $date ) : ?>
				<p class="tbsh-widget-meta"><?php /* translators: %s: verification date */ echo esc_html( sprintf( __( 'Last Scan: %s', 'tbsh-compliance-audit-logger' ), $date ) ); ?></p>
			<?php endif; ?>
			<p style="margin-top: 15px; margin-bottom: 0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tbsh-compliance-audit-logger#/integrity' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Go to Integrity Center', 'tbsh-compliance-audit-logger' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
