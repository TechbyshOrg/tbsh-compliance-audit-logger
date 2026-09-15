<?php
namespace TBSHComplianceAuditLogger\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends rate-limited email alerts for critical security events.
 */
class EmailAlerts {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_excessive_failed_logins', array( __CLASS__, 'on_brute_force' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'flush_critical_from_queue' ), 5 );
	}

	/**
	 * Alert on excessive failed logins.
	 *
	 * @param string $username Attempted username.
	 * @param int    $failures Failure count.
	 */
	public static function on_brute_force( $username, $failures ) {
		self::maybe_send(
			'brute_force',
			sprintf(
				/* translators: 1: failure count, 2: username */
				__( 'Excessive failed logins (%1$d) detected for "%2$s".', 'tbsh-compliance-audit-logger' ),
				$failures,
				$username
			)
		);
	}

	/**
	 * After logs are about to flush, scan queue for privilege_escalation and alert.
	 */
	public static function flush_critical_from_queue() {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( empty( $settings['email_alerts'] ) ) {
			return;
		}

		// Privilege escalation is logged via Logger; pick up from a dedicated action if fired.
	}

	/**
	 * Notify on privilege escalation (called from EventTracker).
	 *
	 * @param string $username Username granted admin.
	 */
	public static function on_privilege_escalation( $username ) {
		self::maybe_send(
			'privilege_escalation',
			sprintf(
				/* translators: %s: username */
				__( 'Administrator privileges were granted to "%s".', 'tbsh-compliance-audit-logger' ),
				$username
			)
		);
	}

	/**
	 * Send email if enabled and not rate-limited.
	 *
	 * @param string $type    Alert type key.
	 * @param string $message Body message.
	 */
	public static function maybe_send( $type, $message ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		if ( empty( $settings['email_alerts'] ) ) {
			return;
		}

		$transient_key = 'tbsh_cal_email_' . sanitize_key( $type );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 15 * MINUTE_IN_SECONDS );

		$to = get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Compliance Audit Alert', 'tbsh-compliance-audit-logger' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = $message . "\n\n" . home_url( '/wp-admin/admin.php?page=tbsh-compliance-audit-logger#/logs' );

		wp_mail( $to, $subject, $body );
	}
}
