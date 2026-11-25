<?php
namespace TBSHComplianceAuditLogger\Reporting;

use TBSHComplianceAuditLogger\Alerts\Channels;
use TBSHComplianceAuditLogger\API\SettingsController;
use TBSHComplianceAuditLogger\Compliance\ReadinessEngine;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Incidents\Nis2Tracker;
use TBSHComplianceAuditLogger\Integrity\ChainVerifier;
use TBSHComplianceAuditLogger\Integrity\CoreChecksum;
use TBSHComplianceAuditLogger\Integrity\ExtensionIntegrity;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Risk\RiskRegister;
use TBSHComplianceAuditLogger\Vendors\VendorRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly or monthly compliance digest email: a periodic, evidenced log review.
 *
 * Checked during the daily maintenance run. The first period starts when the digest is
 * switched on; "Send now" and "Preview" cover the time since the last digest.
 */
class ComplianceDigest {

	const STATE_OPTION = 'tbsh_cal_digest_state';
	const FREQUENCIES  = array( 'off', 'weekly', 'monthly' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'maybe_send' ) );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	private static function settings() {
		return wp_parse_args( get_option( 'tbsh_cal_settings', array() ), SettingsController::defaults() );
	}

	/**
	 * Stored state (last_sent, last_score).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Start a new period when the digest is switched on (called on settings save).
	 *
	 * @param array $old Old settings.
	 * @param array $new New settings.
	 */
	public static function on_settings_saved( array $old, array $new ) {
		$was = $old['digest_frequency'] ?? 'off';
		$now = $new['digest_frequency'] ?? 'off';
		if ( 'off' === $was && 'off' !== $now ) {
			$state              = self::state();
			$state['last_sent'] = Time::now();
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Daily: send when a period has ended.
	 */
	public static function maybe_send() {
		$settings  = self::settings();
		$frequency = $settings['digest_frequency'] ?? 'off';
		if ( 'off' === $frequency ) {
			return;
		}

		$state = self::state();
		if ( empty( $state['last_sent'] ) ) {
			$state['last_sent'] = Time::now();
			update_option( self::STATE_OPTION, $state, false );
			return;
		}

		$last = strtotime( $state['last_sent'] );
		$due  = 'monthly' === $frequency
			? wp_date( 'Y-m' ) !== wp_date( 'Y-m', $last - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
			: ( current_time( 'timestamp' ) - $last ) >= ( 7 * DAY_IN_SECONDS - 2 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Compared with a site-local datetime.
		if ( $due ) {
			self::send( 'scheduled' );
		}
	}

	/**
	 * Build and email the digest.
	 *
	 * @param string $trigger scheduled|manual.
	 * @return array{ok: bool, message: string, skipped?: bool}
	 */
	public static function send( $trigger = 'manual' ) {
		$settings   = self::settings();
		$recipients = self::recipients( $settings );
		if ( ! $recipients ) {
			return array( 'ok' => false, 'message' => __( 'No valid recipient address is configured.', 'tbsh-compliance-audit-logger' ) );
		}

		$data = self::collect();
		if ( 'scheduled' === $trigger && ! empty( $settings['digest_skip_empty'] ) && ! $data['attention'] ) {
			self::advance( $data );
			return array( 'ok' => true, 'skipped' => true, 'message' => __( 'Nothing needed attention, so no digest was sent.', 'tbsh-compliance-audit-logger' ) );
		}

		$attachments = array();
		$report_id   = 0;
		if ( ! empty( $settings['digest_attach_report'] ) ) {
			$report = ReportGenerator::generate( array(
				'type'       => 'executive',
				'format'     => 'html',
				'start_date' => substr( $data['since'], 0, 10 ),
				'end_date'   => substr( $data['until'], 0, 10 ),
			) );
			if ( is_array( $report ) && ! empty( $report['id'] ) ) {
				$report_id = (int) $report['id'];
				$file      = self::report_file( $report_id );
				if ( $file ) {
					$attachments[] = $file;
				}
			}
		}
		$data['report_id'] = $report_id;

		$subject = sprintf(
			/* translators: 1: site name, 2: period start, 3: period end */
			__( '[%1$s] Compliance digest %2$s – %3$s', 'tbsh-compliance-audit-logger' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			substr( $data['since'], 0, 10 ),
			substr( $data['until'], 0, 10 )
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		foreach ( array_slice( $recipients, 1 ) as $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}

		$sent = wp_mail( $recipients[0], $subject, self::render( $data ), $headers, $attachments );
		if ( ! $sent ) {
			return array( 'ok' => false, 'message' => __( 'The mailer reported a failure. Check your site’s email delivery.', 'tbsh-compliance-audit-logger' ) );
		}

		self::advance( $data );
		Logger::log(
			'compliance_digest_sent',
			'Audit Trail',
			'info',
			__( 'Compliance digest sent', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: period start, 2: period end, 3: masked recipients */
			sprintf( __( 'Compliance digest for %1$s – %2$s sent to %3$s.', 'tbsh-compliance-audit-logger' ), substr( $data['since'], 0, 10 ), substr( $data['until'], 0, 10 ), implode( ', ', array_map( array( Channels::class, 'mask_email' ), $recipients ) ) ),
			array(
				'object_type'     => 'digest',
				'object_id'       => $report_id,
				'compliance_tags' => 'Audit Trail, ISO 27001 A.8.15, PCI DSS 10.4',
				'metadata'        => array(
					'trigger'   => $trigger,
					'since'     => $data['since'],
					'until'     => $data['until'],
					'report_id' => $report_id,
					'events'    => $data['events']['total'],
				),
			)
		);

		return array(
			'ok'      => true,
			/* translators: %d: number of recipients */
			'message' => sprintf( _n( 'Digest sent to %d recipient.', 'Digest sent to %d recipients.', count( $recipients ), 'tbsh-compliance-audit-logger' ), count( $recipients ) ),
		);
	}

	/**
	 * Preview HTML for the current period (nothing is sent or stored).
	 *
	 * @return string
	 */
	public static function preview() {
		$data              = self::collect();
		$data['report_id'] = 0;
		return self::render( $data );
	}

	/**
	 * Record the end of a period.
	 *
	 * @param array $data Collected data.
	 */
	private static function advance( array $data ) {
		update_option(
			self::STATE_OPTION,
			array(
				'last_sent'  => $data['until'],
				'last_score' => $data['readiness']['score'],
			),
			false
		);
	}

	/**
	 * Digest recipients: digest list, else alert list, else the admin email.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function recipients( array $settings ) {
		$raw = trim( (string) ( $settings['digest_recipients'] ?? '' ) );
		if ( '' === $raw ) {
			return Channels::email_recipients( $settings );
		}
		$list = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' ) );
		return array_slice( array_unique( $list ), 0, 20 );
	}

	/**
	 * Path of a stored report file.
	 *
	 * @param int $report_id Report ID.
	 * @return string|false
	 */
	private static function report_file( $report_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$file = $wpdb->get_var( $wpdb->prepare( 'SELECT file_name FROM %i WHERE id = %d', Schema::table( 'reports' ), $report_id ) );
		return $file ? Storage::path( 'reports', (string) $file ) : false;
	}

	/**
	 * Gather the digest data for the period since the last digest.
	 *
	 * @return array
	 */
	public static function collect() {
		global $wpdb;
		$settings = self::settings();
		$state    = self::state();
		$until    = Time::now();
		$days     = 'monthly' === ( $settings['digest_frequency'] ?? '' ) ? 30 : 7;
		$since    = ! empty( $state['last_sent'] ) ? (string) $state['last_sent'] : Time::ago( $days * DAY_IN_SECONDS );
		$logs     = Schema::table( 'logs' );

		$by_severity = array_fill_keys( array( 'info', 'notice', 'warning', 'error', 'critical' ), 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT severity, COUNT(*) AS c FROM %i WHERE created_at >= %s AND created_at <= %s GROUP BY severity', $logs, $since, $until ) ) as $row ) {
			if ( isset( $by_severity[ $row->severity ] ) ) {
				$by_severity[ $row->severity ] = (int) $row->c;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$important = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_title, event_message, severity, username, created_at FROM %i WHERE created_at >= %s AND created_at <= %s AND severity IN ('error','critical') ORDER BY id DESC LIMIT 10",
			$logs,
			$since,
			$until
		) );

		// Entries attributed to AI agents (see Logging\Origin).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_agents = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE actor = %s AND created_at >= %s AND created_at <= %s',
			$logs,
			'agent',
			$since,
			$until
		) );

		$incidents = Schema::table( 'incidents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$opened = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE COALESCE(detected_at, created_at) >= %s', $incidents, $since ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status NOT IN ('resolved','closed')", $incidents ) );
		$overdue = Nis2Tracker::overdue();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$vault_failures = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'failed' AND created_at >= %s", Schema::table( 'vault_logs' ), $since ) );

		$chain  = ChainVerifier::get_latest_status();
		$core   = CoreChecksum::get_latest();
		$files  = ExtensionIntegrity::status()['result'];
		$risks  = RiskRegister::stats();
		$vendor = VendorRegister::stats();

		$assessment = ReadinessEngine::assess();
		$score      = (int) $assessment['overall'];
		$previous   = isset( $state['last_score'] ) ? (int) $state['last_score'] : null;

		$integrity = array(
			'chain' => (string) ( $chain['status'] ?? 'unverified' ),
			'core'  => (string) ( $core['status'] ?? 'unverified' ),
			'files' => is_array( $files ) ? (string) $files['status'] : 'unverified',
		);

		$network = \TBSHComplianceAuditLogger\Network\SiteNetwork::digest();

		$attention = $by_severity['error'] + $by_severity['critical'] > 0
			|| $network['unreachable'] > 0
			|| $network['compromised'] > 0
			|| $opened > 0
			|| $overdue
			|| $vault_failures > 0
			|| 'compromised' === $integrity['chain']
			|| 'fail' === $integrity['core']
			|| 'fail' === $integrity['files']
			|| (int) ( $risks['overdue_reviews'] ?? 0 ) > 0
			|| (int) $vendor['overdue_reviews'] > 0;

		return array(
			'since'     => $since,
			'until'     => $until,
			'attention' => $attention,
			'events'    => array(
				'total'       => array_sum( $by_severity ),
				'by_severity' => $by_severity,
				'by_agents'   => $by_agents,
				'important'   => (array) $important,
			),
			'incidents' => array(
				'opened'  => $opened,
				'open'    => $open,
				'overdue' => array_slice( $overdue, 0, 10 ),
			),
			'integrity' => $integrity,
			'readiness' => array(
				'score'    => $score,
				'previous' => $previous,
				'band'     => $assessment['band'],
				'summary'  => $assessment['summary'],
			),
			'governance' => array(
				'risk_reviews_overdue'   => (int) ( $risks['overdue_reviews'] ?? 0 ),
				'high_risks'             => (int) ( $risks['bands']['high'] ?? 0 ) + (int) ( $risks['bands']['critical'] ?? 0 ),
				'vendor_reviews_overdue' => (int) $vendor['overdue_reviews'],
				'vendor_renewals_soon'   => (int) $vendor['renewals_soon'],
			),
			'vault_failures' => $vault_failures,
			'network'        => $network,
		);
	}

	/**
	 * Render the email body (inline styles for mail clients).
	 *
	 * @param array $d Data.
	 * @return string
	 */
	public static function render( array $d ) {
		$site  = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$link  = esc_url( admin_url( 'admin.php?page=tbsh-compliance-audit-logger#/executive' ) );
		$cell  = 'padding:6px 10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px;';
		$head  = 'padding:6px 10px;border-bottom:2px solid #cbd5e1;text-align:left;font-size:13px;color:#475569;';
		$h2    = 'font-size:16px;margin:24px 0 8px;color:#0f172a;';
		$label = array(
			'ok'          => __( 'Passed', 'tbsh-compliance-audit-logger' ),
			'verified'    => __( 'Passed', 'tbsh-compliance-audit-logger' ),
			'fail'        => __( 'FAILED', 'tbsh-compliance-audit-logger' ),
			'compromised' => __( 'FAILED', 'tbsh-compliance-audit-logger' ),
			'error'       => __( 'Could not run', 'tbsh-compliance-audit-logger' ),
			'unverified'  => __( 'Not run yet', 'tbsh-compliance-audit-logger' ),
		);
		$state = function ( $value ) use ( $label ) {
			return esc_html( $label[ $value ] ?? $value );
		};

		$change = '';
		if ( null !== $d['readiness']['previous'] ) {
			$delta  = $d['readiness']['score'] - $d['readiness']['previous'];
			$change = ' (' . ( $delta >= 0 ? '+' : '' ) . (int) $delta . ')';
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="utf-8"><title><?php echo esc_html__( 'Compliance digest', 'tbsh-compliance-audit-logger' ); ?></title></head>
<body style="margin:0;padding:24px;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1e293b;">
<div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;">
	<h1 style="font-size:20px;margin:0 0 4px;"><?php echo esc_html__( 'Compliance digest', 'tbsh-compliance-audit-logger' ); ?> — <?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></h1>
	<p style="margin:0 0 16px;color:#475569;font-size:14px;">
		<?php
		/* translators: 1: period start, 2: period end */
		echo esc_html( sprintf( __( 'Period: %1$s to %2$s', 'tbsh-compliance-audit-logger' ), $d['since'], $d['until'] ) );
		?>
	</p>

	<p style="font-size:15px;margin:0 0 4px;">
		<strong><?php echo esc_html__( 'Readiness score', 'tbsh-compliance-audit-logger' ); ?>:</strong>
		<?php echo esc_html( (int) $d['readiness']['score'] . '/100' . $change ); ?>
	</p>
	<p style="margin:0;color:#475569;font-size:14px;"><?php echo esc_html( $d['readiness']['summary'] ); ?></p>

	<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Activity', 'tbsh-compliance-audit-logger' ); ?></h2>
	<table role="presentation" style="border-collapse:collapse;width:100%;">
		<tr>
			<?php foreach ( $d['events']['by_severity'] as $severity => $count ) : ?>
				<th scope="col" style="<?php echo esc_attr( $head ); ?>"><?php echo esc_html( ucfirst( $severity ) ); ?></th>
			<?php endforeach; ?>
		</tr>
		<tr>
			<?php foreach ( $d['events']['by_severity'] as $count ) : ?>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
			<?php endforeach; ?>
		</tr>
	</table>
	<?php if ( ! empty( $d['events']['by_agents'] ) ) : ?>
		<p style="margin:8px 0 0;font-size:14px;">
			<?php
			/* translators: %s: number of entries */
			echo esc_html( sprintf( _n( '%s entry was made by AI agents.', '%s entries were made by AI agents.', (int) $d['events']['by_agents'], 'tbsh-compliance-audit-logger' ), number_format_i18n( (int) $d['events']['by_agents'] ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php if ( $d['events']['important'] ) : ?>
		<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Errors and critical events', 'tbsh-compliance-audit-logger' ); ?></h2>
		<table style="border-collapse:collapse;width:100%;">
			<tr>
				<th scope="col" style="<?php echo esc_attr( $head ); ?>"><?php echo esc_html__( 'Time', 'tbsh-compliance-audit-logger' ); ?></th>
				<th scope="col" style="<?php echo esc_attr( $head ); ?>"><?php echo esc_html__( 'Event', 'tbsh-compliance-audit-logger' ); ?></th>
				<th scope="col" style="<?php echo esc_attr( $head ); ?>"><?php echo esc_html__( 'User', 'tbsh-compliance-audit-logger' ); ?></th>
			</tr>
			<?php foreach ( $d['events']['important'] as $row ) : ?>
				<tr>
					<td style="<?php echo esc_attr( $cell ); ?>white-space:nowrap;"><?php echo esc_html( $row->created_at ); ?></td>
					<td style="<?php echo esc_attr( $cell ); ?>"><strong><?php echo esc_html( $row->event_title ); ?></strong><br><span style="color:#475569;"><?php echo esc_html( wp_html_excerpt( (string) $row->event_message, 200, '…' ) ); ?></span></td>
					<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $row->username ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	<?php else : ?>
		<p style="font-size:14px;"><?php echo esc_html__( 'No errors or critical events were logged in this period.', 'tbsh-compliance-audit-logger' ); ?></p>
	<?php endif; ?>

	<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Incidents', 'tbsh-compliance-audit-logger' ); ?></h2>
	<ul style="font-size:14px;padding-left:20px;margin:0;">
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Opened in this period: %d', 'tbsh-compliance-audit-logger' ), $d['incidents']['opened'] ) ); ?></li>
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Currently open: %d', 'tbsh-compliance-audit-logger' ), $d['incidents']['open'] ) ); ?></li>
		<?php foreach ( $d['incidents']['overdue'] as $item ) : ?>
			<li style="color:#b91c1c;">
				<?php
				/* translators: 1: NIS2 stage, 2: incident title, 3: due date */
				echo esc_html( sprintf( __( 'Overdue NIS2 %1$s: %2$s (due %3$s)', 'tbsh-compliance-audit-logger' ), $item['label'], $item['title'], $item['due_at'] ) );
				?>
			</li>
		<?php endforeach; ?>
	</ul>

	<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Integrity', 'tbsh-compliance-audit-logger' ); ?></h2>
	<ul style="font-size:14px;padding-left:20px;margin:0;">
		<li><?php echo esc_html__( 'Audit log hash chain', 'tbsh-compliance-audit-logger' ); ?>: <?php echo $state( $d['integrity']['chain'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in closure. ?></li>
		<li><?php echo esc_html__( 'WordPress core files', 'tbsh-compliance-audit-logger' ); ?>: <?php echo $state( $d['integrity']['core'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in closure. ?></li>
		<li><?php echo esc_html__( 'Plugin, theme and uploads files', 'tbsh-compliance-audit-logger' ); ?>: <?php echo $state( $d['integrity']['files'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in closure. ?></li>
		<?php if ( $d['vault_failures'] ) : ?>
			<li style="color:#b91c1c;"><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Failed off-site vault syncs: %d', 'tbsh-compliance-audit-logger' ), $d['vault_failures'] ) ); ?></li>
		<?php endif; ?>
	</ul>

	<?php if ( ! empty( $d['network']['sites'] ) ) : ?>
		<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Site Network', 'tbsh-compliance-audit-logger' ); ?></h2>
		<ul style="font-size:14px;padding-left:20px;margin:0;">
			<?php foreach ( $d['network']['sites'] as $site ) : ?>
				<li<?php echo ( ! $site['reachable'] || 'compromised' === $site['integrity'] ) ? ' style="color:#b91c1c;"' : ''; ?>>
					<strong><?php echo esc_html( $site['label'] ); ?></strong>:
					<?php
					if ( ! $site['reachable'] ) {
						echo esc_html__( 'cannot be read (check its auditor link)', 'tbsh-compliance-audit-logger' );
					} else {
						$parts = array();
						if ( null !== $site['readiness'] ) {
							/* translators: %d: score */
							$parts[] = sprintf( __( 'readiness %d', 'tbsh-compliance-audit-logger' ), (int) $site['readiness'] );
						}
						if ( null !== $site['open_incidents'] ) {
							/* translators: %d: count */
							$parts[] = sprintf( __( 'open incidents: %d', 'tbsh-compliance-audit-logger' ), $site['open_incidents'] );
						}
						$parts[] = 'compromised' === $site['integrity']
							? __( 'audit log integrity FAILED', 'tbsh-compliance-audit-logger' )
							/* translators: %s: integrity state */
							: sprintf( __( 'audit log: %s', 'tbsh-compliance-audit-logger' ), $site['integrity'] );
						echo esc_html( implode( ' · ', $parts ) );
					}
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h2 style="<?php echo esc_attr( $h2 ); ?>"><?php echo esc_html__( 'Governance', 'tbsh-compliance-audit-logger' ); ?></h2>
	<ul style="font-size:14px;padding-left:20px;margin:0;">
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'High or critical risks: %d', 'tbsh-compliance-audit-logger' ), $d['governance']['high_risks'] ) ); ?></li>
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Risk reviews overdue: %d', 'tbsh-compliance-audit-logger' ), $d['governance']['risk_reviews_overdue'] ) ); ?></li>
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Vendor reviews overdue: %d', 'tbsh-compliance-audit-logger' ), $d['governance']['vendor_reviews_overdue'] ) ); ?></li>
		<li><?php /* translators: %d: count */ echo esc_html( sprintf( __( 'Vendor contracts renewing within 30 days: %d', 'tbsh-compliance-audit-logger' ), $d['governance']['vendor_renewals_soon'] ) ); ?></li>
	</ul>

	<?php if ( ! empty( $d['report_id'] ) ) : ?>
		<p style="font-size:14px;margin-top:24px;">
			<?php
			/* translators: %d: report ID */
			echo esc_html( sprintf( __( 'The signed executive report #%d for this period is attached and stored under Compliance Reports, where its signature can be verified.', 'tbsh-compliance-audit-logger' ), $d['report_id'] ) );
			?>
		</p>
	<?php endif; ?>

	<p style="margin-top:24px;"><a href="<?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;"><?php echo esc_html__( 'Open the executive dashboard', 'tbsh-compliance-audit-logger' ); ?></a></p>
	<p style="color:#64748b;font-size:12px;margin-top:24px;"><?php echo esc_html__( 'Sent by Compliance Audit Log. Change the frequency or recipients under Settings → Compliance Digest.', 'tbsh-compliance-audit-logger' ); ?></p>
</div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
