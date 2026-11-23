<?php
namespace TBSHComplianceAuditLogger\Incidents;

use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NIS2 Article 23 reporting timeline for significant incidents:
 * early warning within 24h, incident notification within 72h, final report within 1 month.
 */
class Nis2Tracker {

	/**
	 * Stage => deadline offset in seconds from detection.
	 */
	const STAGES = array(
		'early_warning' => DAY_IN_SECONDS,
		'notification'  => 3 * DAY_IN_SECONDS,
		'final_report'  => 30 * DAY_IN_SECONDS,
	);

	const STAGE_STATUSES = array( 'pending', 'draft', 'submitted' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'remind_overdue' ) );
	}

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'early_warning' => __( 'Early warning (24 hours)', 'tbsh-compliance-audit-logger' ),
			'notification'  => __( 'Incident notification (72 hours)', 'tbsh-compliance-audit-logger' ),
			'final_report'  => __( 'Final report (1 month)', 'tbsh-compliance-audit-logger' ),
		);
	}

	/**
	 * Computed NIS2 status for an incident row.
	 *
	 * @param object $row Incident row.
	 * @return array
	 */
	public static function status( $row ) {
		$data        = Repository::json( $row->nis2_json ?? '' );
		$significant = ! empty( $row->nis2_significant );
		$detected    = Time::to_timestamp( $row->detected_at ?? $row->created_at );
		$now         = Time::to_timestamp( Time::now() );
		$stages      = array();
		$overdue     = 0;
		$labels      = self::labels();

		foreach ( self::STAGES as $stage => $offset ) {
			$entry  = isset( $data['stages'][ $stage ] ) && is_array( $data['stages'][ $stage ] ) ? $data['stages'][ $stage ] : array();
			$status = Repository::enum( $entry['status'] ?? 'pending', self::STAGE_STATUSES, 'pending' );
			$due_ts = $detected ? $detected + $offset : null;
			$is_due = $significant && 'submitted' !== $status && $due_ts && $now > $due_ts;
			if ( $is_due ) {
				++$overdue;
			}
			$stages[ $stage ] = array(
				'label'        => $labels[ $stage ],
				'status'       => $status,
				'due_at'       => $due_ts ? wp_date( 'Y-m-d H:i:s', $due_ts ) : null,
				'submitted_at' => $entry['submitted_at'] ?? null,
				'reference'    => $entry['reference'] ?? '',
				'overdue'      => $is_due,
			);
		}

		return array(
			'significant' => $significant,
			'stages'      => $stages,
			'overdue'     => $overdue,
			'remediation' => isset( $data['remediation'] ) ? (string) $data['remediation'] : '',
			'authority'   => isset( $data['authority'] ) ? (string) $data['authority'] : '',
		);
	}

	/**
	 * Update the NIS2 tracking for an incident.
	 *
	 * @param int   $incident_id Incident ID.
	 * @param array $input       significant, cross_border, stages{stage:{status,reference}}, remediation, authority.
	 * @return array|\WP_Error Updated incident.
	 */
	public static function update( $incident_id, array $input ) {
		$row = IncidentRepository::row( $incident_id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$data = Repository::json( $row->nis2_json );
		if ( ! isset( $data['stages'] ) || ! is_array( $data['stages'] ) ) {
			$data['stages'] = array();
		}

		if ( isset( $input['stages'] ) && is_array( $input['stages'] ) ) {
			foreach ( self::STAGES as $stage => $unused ) {
				if ( ! isset( $input['stages'][ $stage ] ) || ! is_array( $input['stages'][ $stage ] ) ) {
					continue;
				}
				$in      = $input['stages'][ $stage ];
				$current = $data['stages'][ $stage ] ?? array();
				$status  = Repository::enum( $in['status'] ?? ( $current['status'] ?? 'pending' ), self::STAGE_STATUSES, 'pending' );

				$current['status']    = $status;
				$current['reference'] = Repository::line( $in['reference'] ?? ( $current['reference'] ?? '' ), 191 );
				if ( 'submitted' === $status && empty( $current['submitted_at'] ) ) {
					$current['submitted_at'] = Time::now();
				} elseif ( 'submitted' !== $status ) {
					$current['submitted_at'] = null;
				}
				$data['stages'][ $stage ] = $current;
			}
		}
		if ( array_key_exists( 'remediation', $input ) ) {
			$data['remediation'] = Repository::text( $input['remediation'] );
		}
		if ( array_key_exists( 'authority', $input ) ) {
			$data['authority'] = Repository::line( $input['authority'] );
		}

		$update = array( 'nis2_json' => $data );
		if ( array_key_exists( 'significant', $input ) ) {
			$update['nis2_significant'] = ! empty( $input['significant'] );
		}
		if ( array_key_exists( 'cross_border', $input ) ) {
			$update['cross_border'] = ! empty( $input['cross_border'] );
		}
		if ( array_key_exists( 'detected_at', $input ) ) {
			$update['detected_at'] = $input['detected_at'];
		}

		return IncidentRepository::update( $incident_id, $update );
	}

	/**
	 * Significant, still-active incidents with an overdue stage.
	 *
	 * @return array List of [incident_id, title, stage, due_at].
	 */
	public static function overdue() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM %i WHERE nis2_significant = 1 AND status <> 'closed' ORDER BY id DESC LIMIT 200",
			Schema::table( 'incidents' )
		) );

		$out = array();
		foreach ( (array) $rows as $row ) {
			foreach ( self::status( $row )['stages'] as $stage => $info ) {
				if ( $info['overdue'] ) {
					$out[] = array(
						'incident_id' => (int) $row->id,
						'title'       => $row->event_title,
						'stage'       => $stage,
						'label'       => $info['label'],
						'due_at'      => $info['due_at'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Daily: alert once per overdue stage.
	 */
	public static function remind_overdue() {
		foreach ( self::overdue() as $item ) {
			$key = 'tbsh_cal_nis2_' . $item['incident_id'] . '_' . $item['stage'];
			if ( get_transient( $key ) ) {
				continue;
			}
			set_transient( $key, 1, 7 * DAY_IN_SECONDS );
			AlertManager::trigger(
				'nis2_deadline',
				'error',
				/* translators: 1: stage label, 2: incident title */
				sprintf( __( 'NIS2 deadline missed: %1$s — %2$s', 'tbsh-compliance-audit-logger' ), $item['label'], $item['title'] ),
				/* translators: %s: due date */
				sprintf( __( 'This reporting stage was due on %s and has not been marked as submitted.', 'tbsh-compliance-audit-logger' ), $item['due_at'] ),
				array( 'incident_id' => $item['incident_id'], 'stage' => $item['stage'] )
			);
		}
	}

	/**
	 * Report package content for one stage.
	 *
	 * @param int    $incident_id Incident.
	 * @param string $stage       Stage.
	 * @return array|\WP_Error
	 */
	public static function package( $incident_id, $stage ) {
		$incident = IncidentRepository::get( $incident_id );
		if ( ! $incident ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		if ( ! isset( self::STAGES[ $stage ] ) ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'Unknown NIS2 reporting stage.', 'tbsh-compliance-audit-logger' ) );
		}

		$nis2 = $incident['nis2'];
		$base = array(
			'stage'              => $stage,
			'stage_label'        => self::labels()[ $stage ],
			'incident_reference' => $incident['incident_uuid'],
			'title'              => $incident['event_title'],
			'detected_at'        => $incident['detected_at'],
			'severity'           => $incident['severity'],
			'status'             => $incident['status'],
			'significant'        => $nis2['significant'],
			'cross_border'       => $incident['cross_border'],
			'competent_authority' => $nis2['authority'],
			'due_at'             => $nis2['stages'][ $stage ]['due_at'],
		);

		if ( 'early_warning' === $stage ) {
			return $base + array(
				'suspected_cause' => $incident['description'],
				'affected_systems' => $incident['affected_systems'],
			);
		}

		$detailed = $base + array(
			'description'       => $incident['description'],
			'affected_systems'  => $incident['affected_systems'],
			'impact_assessment' => $incident['impact_assessment'],
			'root_cause'        => $incident['root_cause'],
			'remediation'       => $nis2['remediation'],
		);

		if ( 'final_report' === $stage ) {
			$detailed['resolution_notes'] = $incident['resolution_notes'];
			$detailed['resolved_at']      = $incident['resolved_at'];
			$detailed['timeline']         = $incident['timeline'];
			$detailed['evidence']         = IncidentRepository::evidence( $incident_id );
		}

		return $detailed;
	}
}
