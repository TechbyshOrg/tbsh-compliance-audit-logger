<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the evidence/cleanup cron schedule in sync with settings.
 */
class CronScheduler {

	/**
	 * Reschedule tbsh_cal_cron_job from current settings.
	 *
	 * @param array|null $settings Optional settings; defaults to stored option.
	 */
	public static function sync( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'tbsh_cal_settings', array() );
		}

		wp_clear_scheduled_hook( 'tbsh_cal_cron_job' );

		$should_schedule = ! empty( $settings['auto_evidence'] )
			|| ( isset( $settings['retain_logs'] ) && intval( $settings['retain_logs'] ) > 0 )
			|| ! empty( $settings['auto_core_checksum'] );

		if ( ! $should_schedule ) {
			return;
		}

		$frequency  = isset( $settings['evidence_frequency'] ) ? $settings['evidence_frequency'] : 'daily';
		$recurrence = ( 'weekly' === $frequency ) ? 'weekly' : 'daily';

		wp_schedule_event( time(), $recurrence, 'tbsh_cal_cron_job' );
	}
}
