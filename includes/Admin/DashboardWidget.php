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
		// Only register widget if user has appropriate capability.
		if ( current_user_can( 'tbsh_cal_view_logs' ) ) {
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		}
	}

	/**
	 * Add dashboard widget.
	 */
	public static function add_widget() {
		wp_add_dashboard_widget(
			'tbsh_cal_integrity_status_widget',
			__( 'Audit Trail Integrity Status', 'tbsh-compliance-audit-logger' ),
			array( __CLASS__, 'render_widget' )
		);
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
			$desc  = sprintf( __( 'Some sequence checks failed (%d issues).', 'tbsh-compliance-audit-logger' ), $issues );
		} elseif ( 'compromised' === $status ) {
			$class = 'tbsh-status-compromised';
			$label = __( 'Compromised', 'tbsh-compliance-audit-logger' );
			$desc  = sprintf( __( 'CRITICAL: Cryptographic chain validation failed! %d tampering issues found.', 'tbsh-compliance-audit-logger' ), $issues );
		}

		?>
		<style>
			.tbsh-widget-container { padding: 5px 0; }
			.tbsh-widget-badge { display: inline-block; padding: 4px 12px; font-weight: bold; border-radius: 4px; text-transform: uppercase; font-size: 11px; margin-bottom: 10px; }
			.tbsh-status-unverified { background-color: #f3f4f6; color: #374151; }
			.tbsh-status-verified { background-color: #d1fae5; color: #065f46; }
			.tbsh-status-warning { background-color: #fef3c7; color: #92400e; }
			.tbsh-status-compromised { background-color: #fee2e2; color: #991b1b; }
			.tbsh-widget-meta { font-size: 12px; color: #64748b; margin-top: 12px; }
		</style>
		<div class="tbsh-widget-container">
			<span class="tbsh-widget-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
			<p><strong><?php echo esc_html( $desc ); ?></strong></p>
			<?php if ( $date ) : ?>
				<p class="tbsh-widget-meta"><?php echo esc_html( sprintf( __( 'Last Scan: %s', 'tbsh-compliance-audit-logger' ), $date ) ); ?></p>
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
