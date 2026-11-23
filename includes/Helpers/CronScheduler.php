<?php
namespace TBSHComplianceAuditLogger\Helpers;

use TBSHComplianceAuditLogger\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps every plugin cron schedule in sync with settings.
 *
 * Hooks:
 * - tbsh_cal_cron_job        daily/weekly: evidence snapshot, core checksum.
 * - tbsh_cal_maintenance     daily, always: retention, baselines, token purge, reminders, alert retries.
 * - tbsh_cal_vault_sync      hourly, only while an off-site vault is active.
 * - tbsh_cal_dispatch_alerts single events scheduled by the alert queue.
 */
class CronScheduler {

	const EVIDENCE_HOOK    = 'tbsh_cal_cron_job';
	const MAINTENANCE_HOOK = 'tbsh_cal_maintenance';
	const VAULT_HOOK       = 'tbsh_cal_vault_sync';
	const ALERTS_HOOK      = 'tbsh_cal_dispatch_alerts';

	/**
	 * Every hook this plugin schedules.
	 */
	const HOOKS = array(
		self::EVIDENCE_HOOK,
		self::MAINTENANCE_HOOK,
		self::VAULT_HOOK,
		self::ALERTS_HOOK,
	);

	/**
	 * Reschedule recurring hooks from current settings.
	 *
	 * Only (re)schedules when a hook is missing or its recurrence changed, so saving
	 * settings does not trigger an immediate run.
	 *
	 * @param array|null $settings Optional settings; defaults to stored option.
	 */
	public static function sync( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( 'tbsh_cal_settings', array() );
		}

		// Evidence snapshot + core checksum.
		$evidence_needed = ! empty( $settings['auto_evidence'] ) || ! empty( $settings['auto_core_checksum'] );
		$frequency       = isset( $settings['evidence_frequency'] ) && 'weekly' === $settings['evidence_frequency'] ? 'weekly' : 'daily';
		self::ensure( self::EVIDENCE_HOOK, $evidence_needed ? $frequency : null );

		// Maintenance always runs daily.
		self::ensure( self::MAINTENANCE_HOOK, 'daily', HOUR_IN_SECONDS );

		// Vault sync only while at least one vault is active.
		self::ensure( self::VAULT_HOOK, self::has_active_vault() ? 'hourly' : null, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Clear every scheduled plugin hook (deactivation/uninstall).
	 */
	public static function clear_all() {
		foreach ( self::HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Ensure a hook is scheduled with the given recurrence, or unscheduled when null.
	 *
	 * @param string      $hook       Hook name.
	 * @param string|null $recurrence Recurrence slug or null to unschedule.
	 * @param int         $delay      Seconds from now for the first run when scheduling.
	 */
	private static function ensure( $hook, $recurrence, $delay = 0 ) {
		$event = wp_get_scheduled_event( $hook );

		if ( null === $recurrence ) {
			if ( $event ) {
				wp_clear_scheduled_hook( $hook );
			}
			return;
		}

		if ( $event && $event->schedule === $recurrence ) {
			return;
		}

		wp_clear_scheduled_hook( $hook );
		wp_schedule_event( time() + $delay, $recurrence, $hook );
	}

	/**
	 * Whether any off-site vault is active.
	 *
	 * @return bool
	 */
	private static function has_active_vault() {
		global $wpdb;
		$table = Schema::table( 'vaults' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_active = 1', $table ) );
		return null !== $count && (int) $count > 0;
	}
}
