<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Integrity\ChainVerifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Compliance Overview REST endpoints.
 */
class ComplianceController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/compliance', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_compliance_data' ),
				'permission_callback' => array( $this, 'check_compliance_permission' ),
			),
		) );
	}

	/**
	 * Permissions.
	 */
	public function check_compliance_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	/**
	 * Get compliance overview data.
	 */
	public function get_compliance_data( $request ) {
		global $wpdb;
		$table_logs = $wpdb->prefix . 'tbsh_cal_logs';

		// 1. Gather Category counts in a single query.
		$results = $wpdb->get_results( "SELECT event_category, COUNT(*) as count FROM $table_logs GROUP BY event_category" );

		$counts = array(
			'Access Control'           => 0,
			'Identity Management'      => 0,
			'Authentication'           => 0,
			'Authorization'            => 0,
			'Change Management'        => 0,
			'Configuration Management' => 0,
			'Security Monitoring'      => 0,
			'Incident Detection'       => 0,
			'Operational Security'     => 0,
			'Audit Trail'              => 0,
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				if ( isset( $counts[ $row->event_category ] ) ) {
					$counts[ $row->event_category ] = intval( $row->count );
				}
			}
		}

		$access_control_events  = $counts['Access Control'];
		$identity_events        = $counts['Identity Management'];
		$authentication_events  = $counts['Authentication'];
		$authorization_events   = $counts['Authorization'];
		$change_events          = $counts['Change Management'];
		$configuration_events   = $counts['Configuration Management'];
		$security_events        = $counts['Security Monitoring'];
		$incident_events        = $counts['Incident Detection'];
		$operational_events     = $counts['Operational Security'];
		$audit_trail_events     = $counts['Audit Trail'];

		// 2. Compute Audit Readiness Indicators.
		$settings = get_option( 'tbsh_cal_settings', array() );
		$integrity = ChainVerifier::get_latest_status();

		$readiness_score = 0;
		$readiness_items = array(
			'logging_active'     => array(
				'title'  => __( 'Continuous Event Logging', 'tbsh-compliance-audit-logger' ),
				'status' => ! empty( $settings['enable_logging'] ) ? 'pass' : 'fail',
				'desc'   => ! empty( $settings['enable_logging'] ) ? __( 'System is actively compiling compliance logs.', 'tbsh-compliance-audit-logger' ) : __( 'Continuous logging is disabled.', 'tbsh-compliance-audit-logger' ),
			),
			'integrity_chain'    => array(
				'title'  => __( 'Cryptographic Integrity Chain', 'tbsh-compliance-audit-logger' ),
				'status' => 'verified' === $integrity['status'] ? 'pass' : ( 'unverified' === $integrity['status'] ? 'warn' : 'fail' ),
				'desc'   => 'verified' === $integrity['status'] ? __( 'Cryptographic hash validation matches stored state.', 'tbsh-compliance-audit-logger' ) : ( 'unverified' === $integrity['status'] ? __( 'Integrity scan pending.', 'tbsh-compliance-audit-logger' ) : __( 'Chain verification detected discrepancies!', 'tbsh-compliance-audit-logger' ) ),
			),
			'evidence_snapshots' => array(
				'title'  => __( 'Evidence Snapshot Collection', 'tbsh-compliance-audit-logger' ),
				'status' => ! empty( $settings['auto_evidence'] ) ? 'pass' : 'fail',
				'desc'   => ! empty( $settings['auto_evidence'] ) ? __( 'Automated state snapshots are active.', 'tbsh-compliance-audit-logger' ) : __( 'Automated state archiving is disabled.', 'tbsh-compliance-audit-logger' ),
			),
			'gdpr_compliance'    => array(
				'title'  => __( 'Data Subject Privacy Shield', 'tbsh-compliance-audit-logger' ),
				'status' => ! empty( $settings['anonymize_ips'] ) ? 'pass' : 'warn',
				'desc'   => ! empty( $settings['anonymize_ips'] ) ? __( 'Actor IP hash masking is active.', 'tbsh-compliance-audit-logger' ) : __( 'Actor IPs are being saved without hash masking.', 'tbsh-compliance-audit-logger' ),
			),
		);

		// Calculate Score (Pass = 25, Warn = 12.5, Fail = 0)
		foreach ( $readiness_items as $item ) {
			if ( 'pass' === $item['status'] ) {
				$readiness_score += 25;
			} elseif ( 'warn' === $item['status'] ) {
				$readiness_score += 12.5;
			}
		}

		// 3. Evidence Coverage Indicators.
		// Assess whether major configuration snapshots are registered.
		$table_evidence   = $wpdb->prefix . 'tbsh_cal_evidence';
		$snapshots_recent = intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table_evidence" ) );

		$coverage_score = 0;
		if ( $snapshots_recent > 0 ) {
			$coverage_score += 50;
		}
		if ( ! empty( $settings['auto_evidence'] ) ) {
			$coverage_score += 50;
		}

		return $this->success( array(
			'categories' => array(
				'access_control'  => $access_control_events + $identity_events,
				'authentication'  => $authentication_events,
				'authorization'   => $authorization_events,
				'changes'         => $change_events + $configuration_events,
				'security'        => $security_events + $incident_events,
				'operational'     => $operational_events + $audit_trail_events,
			),
			'readiness' => array(
				'score' => $readiness_score,
				'items' => $readiness_items,
			),
			'coverage' => array(
				'score' => $coverage_score,
				'snapshots_recorded' => $snapshots_recent,
			),
		) );
	}
}
