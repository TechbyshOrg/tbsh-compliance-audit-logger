<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Compliance\ReadinessEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance overview, readiness and executive scorecard endpoints.
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
				'permission_callback' => $this->cap( 'tbsh_cal_view_logs' ),
			),
		) );

		register_rest_route( $this->namespace, '/readiness', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_readiness' ),
				'permission_callback' => $this->cap( 'tbsh_cal_view_logs' ),
			),
		) );

		register_rest_route( $this->namespace, '/executive', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_executive' ),
				'permission_callback' => $this->cap( 'tbsh_cal_view_analytics' ),
			),
		) );
	}

	/**
	 * Compliance overview: event categories, readiness checks, evidence coverage, frameworks.
	 */
	public function get_compliance_data( $request ) {
		global $wpdb;
		$table_logs = $wpdb->prefix . 'tbsh_cal_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT event_category, COUNT(*) as count FROM %i GROUP BY event_category', $table_logs ) );

		$counts = array_fill_keys(
			array( 'Access Control', 'Identity Management', 'Authentication', 'Authorization', 'Change Management', 'Configuration Management', 'Security Monitoring', 'Incident Detection', 'Operational Security', 'Audit Trail' ),
			0
		);
		foreach ( (array) $results as $row ) {
			if ( isset( $counts[ $row->event_category ] ) ) {
				$counts[ $row->event_category ] = intval( $row->count );
			}
		}

		$assessment = ReadinessEngine::assess();
		$facts      = $assessment['facts'];

		$checks = $assessment['checks'];
		$score  = 0;
		foreach ( $checks as $item ) {
			$score += 'pass' === $item['status'] ? 1 : ( 'warn' === $item['status'] ? 0.5 : 0 );
		}

		$coverage = ( $facts['evidence_count'] > 0 ? 40 : 0 ) + ( $facts['evidence_7d'] > 0 ? 30 : 0 ) + ( ! empty( $facts['settings']['auto_evidence'] ) ? 30 : 0 );

		return $this->success( array(
			'categories' => array(
				'access_control' => $counts['Access Control'] + $counts['Identity Management'],
				'authentication' => $counts['Authentication'],
				'authorization'  => $counts['Authorization'],
				'changes'        => $counts['Change Management'] + $counts['Configuration Management'],
				'security'       => $counts['Security Monitoring'] + $counts['Incident Detection'],
				'operational'    => $counts['Operational Security'] + $counts['Audit Trail'],
			),
			'readiness'  => array(
				'score' => (int) round( 100 * $score / max( 1, count( $checks ) ) ),
				'items' => $checks,
			),
			'coverage'   => array(
				'score'              => $coverage,
				'snapshots_recorded' => $facts['evidence_count'],
			),
			'frameworks' => $assessment['frameworks'],
		) );
	}

	/**
	 * Full readiness assessment.
	 */
	public function get_readiness( $request ) {
		$assessment = ReadinessEngine::assess();
		unset( $assessment['facts'] );
		return $this->success( $assessment );
	}

	/**
	 * Executive scorecard.
	 */
	public function get_executive( $request ) {
		return $this->success( ReadinessEngine::scorecard() );
	}
}
