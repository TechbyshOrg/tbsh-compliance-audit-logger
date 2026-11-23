<?php
namespace TBSHComplianceAuditLogger\Compliance;

use TBSHComplianceAuditLogger\Alerts\Channels;
use TBSHComplianceAuditLogger\API\SettingsController;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Incidents\IncidentRepository;
use TBSHComplianceAuditLogger\Incidents\Nis2Tracker;
use TBSHComplianceAuditLogger\Integrity\ChainVerifier;
use TBSHComplianceAuditLogger\Integrity\CoreChecksum;
use TBSHComplianceAuditLogger\Resilience\DrillRegister;
use TBSHComplianceAuditLogger\Risk\RiskRegister;
use TBSHComplianceAuditLogger\Vendors\VendorRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for compliance readiness: pass/warn/fail checks, scored
 * components and framework coverage, all computed from stored data.
 */
class ReadinessEngine {

	/**
	 * Per-request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Full assessment.
	 *
	 * @return array{overall:int, band:string, summary:string, components:array, checks:array, frameworks:array, facts:array}
	 */
	public static function assess() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$facts      = self::facts();
		$checks     = self::checks( $facts );
		$components = self::components( $facts );
		$overall    = (int) round( array_sum( wp_list_pluck( $components, 'score' ) ) / max( 1, count( $components ) ) );
		$band       = $overall >= 85 ? 'strong' : ( $overall >= 70 ? 'good' : ( $overall >= 50 ? 'fair' : 'weak' ) );

		$summaries = array(
			'strong' => __( 'Controls are in place and evidenced. Keep reviewing open items.', 'tbsh-compliance-audit-logger' ),
			'good'   => __( 'Most controls are working. Address the warnings below before an audit.', 'tbsh-compliance-audit-logger' ),
			'fair'   => __( 'Several controls need attention before this site is audit-ready.', 'tbsh-compliance-audit-logger' ),
			'weak'   => __( 'Key controls are missing or failing. Prioritise the failed items below.', 'tbsh-compliance-audit-logger' ),
		);

		self::$cache = array(
			'overall'    => $overall,
			'band'       => $band,
			'summary'    => $summaries[ $band ],
			'components' => $components,
			'checks'     => $checks,
			'frameworks' => FrameworkMapping::evaluate( $checks ),
			'facts'      => $facts,
		);
		return self::$cache;
	}

	/**
	 * Raw facts gathered once.
	 *
	 * @return array
	 */
	private static function facts() {
		global $wpdb;
		$settings  = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), SettingsController::defaults() );
		$logs      = Schema::table( 'logs' );
		$week_ago  = Time::ago( 7 * DAY_IN_SECONDS );
		$channels  = array_values( array_filter( Channels::ALL, function ( $c ) use ( $settings ) {
			return Channels::is_ready( $c, $settings );
		} ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$week = $wpdb->get_row( $wpdb->prepare(
			"SELECT SUM(event_type = 'failed_login') AS failed, SUM(event_type = 'user_login') AS logins, SUM(event_type = 'privilege_escalation') AS escalations FROM %i WHERE created_at >= %s",
			$logs,
			$week_ago
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$evidence_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'evidence' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$evidence_recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', Schema::table( 'evidence' ), $week_ago ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$vaults = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(is_active = 1 AND status = 'failing') AS failing FROM %i", Schema::table( 'vaults' ) ) );

		$resilience = DrillRegister::metrics();
		$risk_stats = RiskRegister::stats();

		return array(
			'settings'         => $settings,
			'alert_channels'   => $channels,
			'chain'            => ChainVerifier::get_latest_status(),
			'core'             => CoreChecksum::get_latest(),
			'failed_logins_7d' => (int) ( $week->failed ?? 0 ),
			'logins_7d'        => (int) ( $week->logins ?? 0 ),
			'escalations_7d'   => (int) ( $week->escalations ?? 0 ),
			'evidence_count'   => $evidence_count,
			'evidence_7d'      => $evidence_recent,
			'open_incidents'   => IncidentRepository::open_by_severity(),
			'nis2_overdue'     => count( Nis2Tracker::overdue() ),
			'risks'            => $risk_stats,
			'vendors'          => VendorRegister::stats(),
			'vaults'           => array(
				'total'   => (int) ( $vaults->total ?? 0 ),
				'active'  => (int) ( $vaults->active ?? 0 ),
				'failing' => (int) ( $vaults->failing ?? 0 ),
			),
			'last_backup'      => $resilience['last_offsite_backup'],
			'resilience'       => $resilience,
		);
	}

	/**
	 * Pass/warn/fail checks (also used by framework mapping and Compliance Overview).
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private static function checks( array $f ) {
		$s     = $f['settings'];
		$check = function ( $title, $status, $desc ) {
			return array( 'title' => $title, 'status' => $status, 'desc' => $desc );
		};

		$chain       = $f['chain']['status'];
		$core        = $f['core']['status'] ?? 'unverified';
		$open_total  = array_sum( $f['open_incidents'] );
		$backup_age  = $f['last_backup'] ? ( time() - (int) Time::to_timestamp( $f['last_backup'] ) ) : null;
		$last_drill  = $f['resilience']['last_drill'];

		return array(
			'logging_active'     => ! empty( $s['enable_logging'] )
				? $check( __( 'Continuous Event Logging', 'tbsh-compliance-audit-logger' ), 'pass', __( 'System is actively compiling compliance logs.', 'tbsh-compliance-audit-logger' ) )
				: $check( __( 'Continuous Event Logging', 'tbsh-compliance-audit-logger' ), 'fail', __( 'Continuous logging is disabled.', 'tbsh-compliance-audit-logger' ) ),
			'integrity_chain'    => $check(
				__( 'Cryptographic Integrity Chain', 'tbsh-compliance-audit-logger' ),
				'verified' === $chain ? 'pass' : ( 'unverified' === $chain ? 'warn' : 'fail' ),
				'verified' === $chain ? __( 'Cryptographic hash validation matches stored state.', 'tbsh-compliance-audit-logger' ) : ( 'unverified' === $chain ? __( 'Integrity scan pending.', 'tbsh-compliance-audit-logger' ) : __( 'Chain verification detected discrepancies!', 'tbsh-compliance-audit-logger' ) )
			),
			'evidence_snapshots' => ! empty( $s['auto_evidence'] )
				? $check( __( 'Evidence Snapshot Collection', 'tbsh-compliance-audit-logger' ), 'pass', __( 'Automated state snapshots are active.', 'tbsh-compliance-audit-logger' ) )
				: $check( __( 'Evidence Snapshot Collection', 'tbsh-compliance-audit-logger' ), 'fail', __( 'Automated state archiving is disabled.', 'tbsh-compliance-audit-logger' ) ),
			'gdpr_compliance'    => ! empty( $s['anonymize_ips'] )
				? $check( __( 'Data Subject Privacy Shield', 'tbsh-compliance-audit-logger' ), 'pass', __( 'Actor IP hash masking is active.', 'tbsh-compliance-audit-logger' ) )
				: $check( __( 'Data Subject Privacy Shield', 'tbsh-compliance-audit-logger' ), 'warn', __( 'Actor IPs are being saved without hash masking.', 'tbsh-compliance-audit-logger' ) ),
			'core_integrity'     => $check(
				__( 'Core File Integrity', 'tbsh-compliance-audit-logger' ),
				'ok' === $core ? 'pass' : ( 'fail' === $core ? 'fail' : 'warn' ),
				'ok' === $core ? __( 'WordPress core files match official checksums.', 'tbsh-compliance-audit-logger' ) : ( 'fail' === $core ? __( 'Modified WordPress core files were found.', 'tbsh-compliance-audit-logger' ) : __( 'Run a core checksum scan in System Health.', 'tbsh-compliance-audit-logger' ) )
			),
			'alerting'           => ! empty( $f['alert_channels'] )
				/* translators: %s: channel list */
				? $check( __( 'Security Alerting', 'tbsh-compliance-audit-logger' ), 'pass', sprintf( __( 'Alerts are delivered via: %s.', 'tbsh-compliance-audit-logger' ), implode( ', ', $f['alert_channels'] ) ) )
				: $check( __( 'Security Alerting', 'tbsh-compliance-audit-logger' ), 'warn', __( 'No alert channel is configured.', 'tbsh-compliance-audit-logger' ) ),
			'incident_process'   => $check(
				__( 'Incident Response', 'tbsh-compliance-audit-logger' ),
				$f['open_incidents']['critical'] > 0 ? 'fail' : ( $open_total > 0 ? 'warn' : 'pass' ),
				/* translators: %d: open incidents */
				$open_total > 0 ? sprintf( _n( '%d incident is still open.', '%d incidents are still open.', $open_total, 'tbsh-compliance-audit-logger' ), $open_total ) : __( 'No open incidents.', 'tbsh-compliance-audit-logger' )
			),
			'nis2_deadlines'     => $f['nis2_overdue'] > 0
				/* translators: %d: overdue stages */
				? $check( __( 'NIS2 Reporting Deadlines', 'tbsh-compliance-audit-logger' ), 'fail', sprintf( _n( '%d NIS2 reporting stage is overdue.', '%d NIS2 reporting stages are overdue.', $f['nis2_overdue'], 'tbsh-compliance-audit-logger' ), $f['nis2_overdue'] ) )
				: $check( __( 'NIS2 Reporting Deadlines', 'tbsh-compliance-audit-logger' ), 'pass', __( 'No reporting deadlines are overdue.', 'tbsh-compliance-audit-logger' ) ),
			'risk_register'      => $check(
				__( 'Risk Register', 'tbsh-compliance-audit-logger' ),
				0 === $f['risks']['total'] ? 'warn' : ( $f['risks']['overdue_reviews'] > 0 ? 'warn' : 'pass' ),
				0 === $f['risks']['total'] ? __( 'No risks have been registered yet.', 'tbsh-compliance-audit-logger' ) : ( $f['risks']['overdue_reviews'] > 0 ? __( 'Some risk reviews are overdue.', 'tbsh-compliance-audit-logger' ) : __( 'Risks are registered and reviews are current.', 'tbsh-compliance-audit-logger' ) )
			),
			'vendor_register'    => $check(
				__( 'Third-Party Register', 'tbsh-compliance-audit-logger' ),
				0 === $f['vendors']['total'] ? 'warn' : ( $f['vendors']['critical_overdue'] > 0 ? 'fail' : ( $f['vendors']['overdue_reviews'] > 0 ? 'warn' : 'pass' ) ),
				0 === $f['vendors']['total'] ? __( 'No ICT third parties have been registered yet.', 'tbsh-compliance-audit-logger' ) : ( $f['vendors']['overdue_reviews'] > 0 ? __( 'Some vendor reviews are overdue.', 'tbsh-compliance-audit-logger' ) : __( 'Vendors are registered and reviews are current.', 'tbsh-compliance-audit-logger' ) )
			),
			'offsite_backup'     => $check(
				__( 'Off-site Evidence Backup', 'tbsh-compliance-audit-logger' ),
				0 === $f['vaults']['active'] ? 'warn' : ( $f['vaults']['failing'] > 0 || null === $backup_age || $backup_age > 7 * DAY_IN_SECONDS ? 'fail' : 'pass' ),
				0 === $f['vaults']['active'] ? __( 'No off-site vault is active.', 'tbsh-compliance-audit-logger' ) : ( $f['vaults']['failing'] > 0 ? __( 'An off-site vault is failing.', 'tbsh-compliance-audit-logger' ) : ( null === $backup_age || $backup_age > 7 * DAY_IN_SECONDS ? __( 'No successful off-site backup in the last 7 days.', 'tbsh-compliance-audit-logger' ) : __( 'Evidence is copied off-site.', 'tbsh-compliance-audit-logger' ) ) )
			),
			'resilience_testing' => $check(
				__( 'Resilience Testing', 'tbsh-compliance-audit-logger' ),
				$f['resilience']['drills_completed_12m'] > 0 ? ( ( $last_drill['outcome'] ?? '' ) === 'failed' ? 'warn' : 'pass' ) : 'warn',
				$f['resilience']['drills_completed_12m'] > 0 ? __( 'At least one resilience drill was completed in the last 12 months.', 'tbsh-compliance-audit-logger' ) : __( 'No resilience drill in the last 12 months.', 'tbsh-compliance-audit-logger' )
			),
		);
	}

	/**
	 * Scored components (0–100), each with the inputs that produced the score.
	 *
	 * @param array $f Facts.
	 * @return array
	 */
	private static function components( array $f ) {
		$s   = $f['settings'];
		$out = array();

		// Logging & monitoring.
		$score  = 0;
		$inputs = array();
		$parts  = array(
			array( ! empty( $s['enable_logging'] ), 30, __( 'Logging enabled', 'tbsh-compliance-audit-logger' ) ),
			array( in_array( $s['min_severity'], array( 'info', 'notice' ), true ), 10, __( 'Low-severity events captured', 'tbsh-compliance-audit-logger' ) ),
			array( ! empty( $s['auto_evidence'] ), 20, __( 'Automatic evidence snapshots', 'tbsh-compliance-audit-logger' ) ),
			array( ! empty( $s['auto_core_checksum'] ), 15, __( 'Scheduled core checksum scans', 'tbsh-compliance-audit-logger' ) ),
			array( ! empty( $f['alert_channels'] ), 15, __( 'Alert channel configured', 'tbsh-compliance-audit-logger' ) ),
			array( ! empty( $s['anonymize_ips'] ), 10, __( 'IP addresses masked', 'tbsh-compliance-audit-logger' ) ),
		);
		foreach ( $parts as $p ) {
			$score   += $p[0] ? $p[1] : 0;
			$inputs[] = array( 'label' => $p[2], 'ok' => (bool) $p[0] );
		}
		$out['monitoring'] = self::component( __( 'Logging & Monitoring', 'tbsh-compliance-audit-logger' ), $score, $inputs );

		// Integrity.
		$chain = $f['chain']['status'];
		$core  = $f['core']['status'] ?? 'unverified';
		$score = ( 'verified' === $chain ? 60 : ( 'unverified' === $chain ? 30 : 0 ) ) + ( 'ok' === $core ? 40 : ( 'fail' === $core ? 0 : 20 ) );
		$out['integrity'] = self::component( __( 'Integrity', 'tbsh-compliance-audit-logger' ), $score, array(
			/* translators: %s: status */
			array( 'label' => sprintf( __( 'Log chain: %s', 'tbsh-compliance-audit-logger' ), $chain ), 'ok' => 'verified' === $chain ),
			/* translators: %s: status */
			array( 'label' => sprintf( __( 'Core checksums: %s', 'tbsh-compliance-audit-logger' ), $core ), 'ok' => 'ok' === $core ),
		) );

		// Access.
		$failed  = $f['failed_logins_7d'];
		$deduct  = min( 30, (int) floor( $failed / 10 ) * 5 ) + min( 30, 10 * $f['escalations_7d'] );
		$out['access'] = self::component( __( 'Access Control', 'tbsh-compliance-audit-logger' ), 100 - $deduct, array(
			/* translators: %d: count */
			array( 'label' => sprintf( _n( '%d failed login in 7 days', '%d failed logins in 7 days', $failed, 'tbsh-compliance-audit-logger' ), $failed ), 'ok' => $failed < 10 ),
			/* translators: %d: count */
			array( 'label' => sprintf( _n( '%d administrator grant in 7 days', '%d administrator grants in 7 days', $f['escalations_7d'], 'tbsh-compliance-audit-logger' ), $f['escalations_7d'] ), 'ok' => 0 === $f['escalations_7d'] ),
		) );

		// Incidents.
		$o     = $f['open_incidents'];
		$score = 100 - 25 * $o['critical'] - 15 * $o['error'] - 5 * $o['warning'] - 15 * $f['nis2_overdue'];
		$out['incidents'] = self::component( __( 'Incident Management', 'tbsh-compliance-audit-logger' ), $score, array(
			/* translators: %d: count */
			array( 'label' => sprintf( _n( '%d open critical incident', '%d open critical incidents', $o['critical'], 'tbsh-compliance-audit-logger' ), $o['critical'] ), 'ok' => 0 === $o['critical'] ),
			/* translators: %d: count */
			array( 'label' => sprintf( _n( '%d open high-severity incident', '%d open high-severity incidents', $o['error'], 'tbsh-compliance-audit-logger' ), $o['error'] ), 'ok' => 0 === $o['error'] ),
			/* translators: %d: count */
			array( 'label' => sprintf( _n( '%d overdue NIS2 reporting stage', '%d overdue NIS2 reporting stages', $f['nis2_overdue'], 'tbsh-compliance-audit-logger' ), $f['nis2_overdue'] ), 'ok' => 0 === $f['nis2_overdue'] ),
		) );

		// Risk.
		$r = $f['risks'];
		if ( 0 === $r['total'] ) {
			$out['risk'] = self::component( __( 'Risk Management', 'tbsh-compliance-audit-logger' ), 50, array( array( 'label' => __( 'No risks registered', 'tbsh-compliance-audit-logger' ), 'ok' => false ) ) );
		} else {
			$score       = 100 - 15 * $r['bands']['critical'] - 8 * $r['bands']['high'] - 5 * $r['overdue_reviews'];
			$out['risk'] = self::component( __( 'Risk Management', 'tbsh-compliance-audit-logger' ), $score, array(
				/* translators: %d: count */
				array( 'label' => sprintf( _n( '%d active critical risk', '%d active critical risks', $r['bands']['critical'], 'tbsh-compliance-audit-logger' ), $r['bands']['critical'] ), 'ok' => 0 === $r['bands']['critical'] ),
				/* translators: %d: count */
				array( 'label' => sprintf( _n( '%d active high risk', '%d active high risks', $r['bands']['high'], 'tbsh-compliance-audit-logger' ), $r['bands']['high'] ), 'ok' => 0 === $r['bands']['high'] ),
				/* translators: %d: count */
				array( 'label' => sprintf( _n( '%d overdue risk review', '%d overdue risk reviews', $r['overdue_reviews'], 'tbsh-compliance-audit-logger' ), $r['overdue_reviews'] ), 'ok' => 0 === $r['overdue_reviews'] ),
			) );
		}

		// Vendors.
		$v = $f['vendors'];
		if ( 0 === $v['total'] ) {
			$out['vendors'] = self::component( __( 'Third-Party Risk', 'tbsh-compliance-audit-logger' ), 60, array( array( 'label' => __( 'No vendors registered', 'tbsh-compliance-audit-logger' ), 'ok' => false ) ) );
		} else {
			$updates        = (int) ( get_transient( \TBSHComplianceAuditLogger\Vendors\SupplyChain::CACHE_KEY )['summary']['updates_pending'] ?? 0 );
			$score          = 100 - 15 * $v['critical_overdue'] - 5 * $v['overdue_reviews'] - min( 30, 5 * $updates );
			$out['vendors'] = self::component( __( 'Third-Party Risk', 'tbsh-compliance-audit-logger' ), $score, array(
				/* translators: %d: count */
				array( 'label' => sprintf( _n( '%d critical vendor overdue for review', '%d critical vendors overdue for review', $v['critical_overdue'], 'tbsh-compliance-audit-logger' ), $v['critical_overdue'] ), 'ok' => 0 === $v['critical_overdue'] ),
				/* translators: %d: count */
				array( 'label' => sprintf( _n( '%d component with an update pending', '%d components with updates pending', $updates, 'tbsh-compliance-audit-logger' ), $updates ), 'ok' => 0 === $updates ),
			) );
		}

		// Resilience.
		$last_drill = $f['resilience']['last_drill'];
		$drill_age  = $last_drill ? time() - (int) Time::to_timestamp( $last_drill['completed_at'] ) : null;
		$drill_pts  = ( $drill_age && $drill_age <= YEAR_IN_SECONDS ) ? ( 'passed' === $last_drill['outcome'] ? 50 : ( 'partial' === $last_drill['outcome'] ? 30 : 15 ) ) : 0;
		$backup_age = $f['last_backup'] ? time() - (int) Time::to_timestamp( $f['last_backup'] ) : null;
		$backup_pts = 0;
		if ( $f['vaults']['failing'] > 0 ) {
			$backup_pts = 10;
		} elseif ( null !== $backup_age ) {
			$backup_pts = $backup_age <= 2 * DAY_IN_SECONDS ? 50 : ( $backup_age <= 7 * DAY_IN_SECONDS ? 30 : 10 );
		}
		$out['resilience'] = self::component( __( 'Operational Resilience', 'tbsh-compliance-audit-logger' ), $drill_pts + $backup_pts, array(
			array( 'label' => $last_drill ? sprintf( /* translators: 1: date, 2: outcome */ __( 'Last drill %1$s (%2$s)', 'tbsh-compliance-audit-logger' ), substr( (string) $last_drill['completed_at'], 0, 10 ), $last_drill['outcome'] ? $last_drill['outcome'] : '—' ) : __( 'No drill recorded', 'tbsh-compliance-audit-logger' ), 'ok' => $drill_pts >= 50 ),
			array( 'label' => $f['last_backup'] ? sprintf( /* translators: %s: date */ __( 'Last off-site backup %s', 'tbsh-compliance-audit-logger' ), $f['last_backup'] ) : __( 'No off-site backup yet', 'tbsh-compliance-audit-logger' ), 'ok' => $backup_pts >= 50 ),
		) );

		return $out;
	}

	/**
	 * Component structure.
	 *
	 * @param string $label  Label.
	 * @param int    $score  Raw score.
	 * @param array  $inputs Inputs.
	 * @return array
	 */
	private static function component( $label, $score, array $inputs ) {
		return array(
			'label'  => $label,
			'score'  => max( 0, min( 100, (int) $score ) ),
			'inputs' => $inputs,
		);
	}

	/**
	 * Executive scorecard.
	 *
	 * @return array
	 */
	public static function scorecard() {
		$a = self::assess();
		$f = $a['facts'];
		return array(
			'overall'    => $a['overall'],
			'band'       => $a['band'],
			'summary'    => $a['summary'],
			'components' => $a['components'],
			'frameworks' => array_map( function ( $fw ) {
				return array( 'name' => $fw['name'], 'coverage' => $fw['coverage'] );
			}, $a['frameworks'] ),
			'kpis'       => array(
				'open_incidents'   => array_sum( $f['open_incidents'] ),
				'critical_open'    => $f['open_incidents']['critical'],
				'nis2_overdue'     => $f['nis2_overdue'],
				'active_risks'     => $f['risks']['by_status']['active'],
				'critical_risks'   => $f['risks']['bands']['critical'],
				'critical_vendors' => $f['vendors']['critical'],
				'chain_status'     => $f['chain']['status'],
				'core_status'      => $f['core']['status'] ?? 'unverified',
				'last_backup'      => $f['last_backup'],
				'mttr_hours'       => $f['resilience']['mttr_hours'],
				'failed_logins_7d' => $f['failed_logins_7d'],
			),
			'nis2_overdue_items' => Nis2Tracker::overdue(),
			'generated_at'       => Time::now(),
		);
	}

	/**
	 * Clear the per-request cache (after data changes in the same request).
	 */
	public static function flush() {
		self::$cache = null;
	}
}
