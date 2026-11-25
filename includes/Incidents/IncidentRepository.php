<?php
namespace TBSHComplianceAuditLogger\Incidents;

use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incident register: incidents, investigation notes and linked evidence.
 */
class IncidentRepository {

	const STATUSES   = array( 'open', 'investigating', 'contained', 'resolved', 'closed' );
	const SEVERITIES = array( 'info', 'notice', 'warning', 'error', 'critical' );
	const CLOSED     = array( 'resolved', 'closed' );

	/**
	 * Paginated list.
	 *
	 * @param array $args status, severity, type, search, page, per_page.
	 * @return array
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'incidents' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = array( '1=1' );
		$params = array( $table );

		if ( ! empty( $args['status'] ) ) {
			if ( 'active' === $args['status'] ) {
				$where[] = "status NOT IN ('resolved','closed')";
			} else {
				$where[]  = 'status = %s';
				$params[] = Repository::enum( $args['status'], self::STATUSES, 'open' );
			}
		}
		if ( ! empty( $args['severity'] ) ) {
			$where[]  = 'severity = %s';
			$params[] = Repository::enum( $args['severity'], self::SEVERITIES, 'warning' );
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'incident_type = %s';
			$params[] = sanitize_key( $args['type'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(event_title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The WHERE clause holds only placeholders built in this method; every value goes through $wpdb->prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where_sql", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The WHERE clause holds only placeholders built in this method; every value goes through $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where_sql ORDER BY FIELD(status,'open','investigating','contained','resolved','closed'), id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );

		$result           = Repository::paged( array_map( array( __CLASS__, 'format' ), (array) $rows ), $total, $per_page, $page );
		$result['counts'] = self::status_counts();
		return $result;
	}

	/**
	 * Single incident.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'incidents' ), absint( $id ) ) );
	}

	/**
	 * Create an incident.
	 *
	 * @param array $data   Fields.
	 * @param bool  $notify Queue alerts.
	 * @return int|\WP_Error New ID.
	 */
	public static function create( array $data, $notify = true ) {
		global $wpdb;

		$title = Repository::line( $data['event_title'] ?? ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'An incident title is required.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}

		$now      = Time::now();
		$severity = Repository::enum( $data['severity'] ?? 'warning', self::SEVERITIES, 'warning' );
		$status   = Repository::enum( $data['status'] ?? 'open', self::STATUSES, 'open' );
		$detected = Repository::datetime_or_null( $data['detected_at'] ?? '' ) ?? $now;
		$user_id  = get_current_user_id();

		$row = array(
			'incident_uuid'       => wp_generate_uuid4(),
			'severity'            => $severity,
			'status'              => $status,
			'incident_type'       => sanitize_key( $data['incident_type'] ?? 'manual' ) ?: 'manual',
			'event_title'         => $title,
			'description'         => Repository::text( $data['description'] ?? '' ),
			'assigned_user_id'    => absint( $data['assigned_user_id'] ?? 0 ),
			'owner_id'            => absint( $data['owner_id'] ?? $user_id ),
			'affected_systems'    => Repository::text( $data['affected_systems'] ?? '', 2000 ),
			'timeline_json'       => wp_json_encode( array( self::timeline_entry( 'created', $data['source'] ?? 'manual' ) ) ),
			'compliance_mappings' => Repository::line( $data['compliance_mappings'] ?? '' ),
			'detection_key'       => mb_substr( (string) ( $data['detection_key'] ?? '' ), 0, 191 ),
			'source_log_id'       => absint( $data['source_log_id'] ?? 0 ),
			'nis2_significant'    => empty( $data['nis2_significant'] ) ? 0 : 1,
			'cross_border'        => empty( $data['cross_border'] ) ? 0 : 1,
			'detected_at'         => $detected,
			'resolved_at'         => in_array( $status, self::CLOSED, true ) ? $now : null,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $wpdb->insert( Schema::table( 'incidents' ), $row ) ) {
			return new \WP_Error( 'tbsh_cal_db', __( 'Could not save the incident.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		Logger::log(
			'incident_created',
			'Incident Detection',
			in_array( $severity, array( 'error', 'critical' ), true ) ? 'error' : 'notice',
			__( 'Incident opened', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: incident id, 2: incident title */
			sprintf( __( 'Incident #%1$d opened: %2$s', 'tbsh-compliance-audit-logger' ), $id, $title ),
			array(
				'object_type'     => 'incident',
				'object_id'       => $id,
				'compliance_tags' => 'Incident Detection, NIS2 Art. 23, DORA Art. 17',
				'metadata'        => array( 'severity' => $severity, 'type' => $row['incident_type'] ),
			)
		);

		if ( $notify ) {
			AlertManager::trigger(
				'incident',
				$severity,
				/* translators: %s: incident title */
				sprintf( __( 'New incident: %s', 'tbsh-compliance-audit-logger' ), $title ),
				'' !== $row['description'] ? $row['description'] : $title,
				array( 'incident_id' => $id, 'incident_type' => $row['incident_type'] ),
				'incident_' . $id
			);
		}

		return $id;
	}

	/**
	 * Update an incident.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields.
	 * @return array|\WP_Error Updated incident.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$update   = array();
		$timeline = Repository::json( $row->timeline_json );

		$text_fields = array(
			'description'       => 5000,
			'affected_systems'  => 2000,
			'resolution_notes'  => 5000,
			'root_cause'        => 5000,
			'impact_assessment' => 5000,
		);
		foreach ( $text_fields as $field => $max ) {
			if ( array_key_exists( $field, $data ) ) {
				$update[ $field ] = Repository::text( $data[ $field ], $max );
			}
		}
		if ( array_key_exists( 'event_title', $data ) ) {
			$title = Repository::line( $data['event_title'] );
			if ( '' !== $title ) {
				$update['event_title'] = $title;
			}
		}
		if ( array_key_exists( 'compliance_mappings', $data ) ) {
			$update['compliance_mappings'] = Repository::line( $data['compliance_mappings'] );
		}
		if ( array_key_exists( 'assigned_user_id', $data ) ) {
			$update['assigned_user_id'] = absint( $data['assigned_user_id'] );
		}
		if ( array_key_exists( 'severity', $data ) ) {
			$severity = Repository::enum( $data['severity'], self::SEVERITIES, $row->severity );
			if ( $severity !== $row->severity ) {
				$update['severity'] = $severity;
				$timeline[]         = self::timeline_entry( 'severity', $row->severity . ' → ' . $severity );
			}
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = Repository::enum( $data['status'], self::STATUSES, $row->status );
			if ( $status !== $row->status ) {
				$update['status'] = $status;
				$timeline[]       = self::timeline_entry( 'status', $row->status . ' → ' . $status );
				if ( in_array( $status, self::CLOSED, true ) && empty( $row->resolved_at ) ) {
					$update['resolved_at'] = Time::now();
				} elseif ( ! in_array( $status, self::CLOSED, true ) ) {
					$update['resolved_at'] = null;
				}
			}
		}
		foreach ( array( 'nis2_significant', 'cross_border' ) as $flag ) {
			if ( array_key_exists( $flag, $data ) ) {
				$update[ $flag ] = empty( $data[ $flag ] ) ? 0 : 1;
			}
		}
		if ( array_key_exists( 'detected_at', $data ) ) {
			$detected = Repository::datetime_or_null( $data['detected_at'] );
			if ( $detected ) {
				$update['detected_at'] = $detected;
			}
		}
		if ( array_key_exists( 'nis2_json', $data ) && is_array( $data['nis2_json'] ) ) {
			$update['nis2_json'] = wp_json_encode( $data['nis2_json'] );
		}
		if ( array_key_exists( 'ai_analysis_json', $data ) && is_array( $data['ai_analysis_json'] ) ) {
			$update['ai_analysis_json'] = wp_json_encode( $data['ai_analysis_json'] );
		}

		if ( empty( $update ) ) {
			return self::format( $row );
		}

		$update['timeline_json'] = wp_json_encode( array_slice( $timeline, -200 ) );
		$update['updated_at']    = Time::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'incidents' ), $update, array( 'id' => (int) $row->id ) );

		$changed = array_diff( array_keys( $update ), array( 'timeline_json', 'updated_at', 'ai_analysis_json' ) );
		if ( ! empty( $changed ) ) {
			Logger::log(
				'incident_updated',
				'Incident Detection',
				'info',
				__( 'Incident updated', 'tbsh-compliance-audit-logger' ),
				/* translators: 1: incident id, 2: changed field list */
				sprintf( __( 'Incident #%1$d updated (%2$s).', 'tbsh-compliance-audit-logger' ), $row->id, implode( ', ', $changed ) ),
				array(
					'object_type' => 'incident',
					'object_id'   => $row->id,
					'metadata'    => array(
						'fields' => array_values( $changed ),
						'status' => $update['status'] ?? $row->status,
					),
				)
			);
		}

		return self::get( $row->id );
	}

	/**
	 * Delete an incident with its notes and evidence links.
	 *
	 * @param int $id ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		self::delete_ids( array( (int) $row->id ) );

		Logger::log(
			'incident_deleted',
			'Incident Detection',
			'warning',
			__( 'Incident deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: incident id, 2: title */
			sprintf( __( 'Incident #%1$d deleted: %2$s', 'tbsh-compliance-audit-logger' ), $row->id, $row->event_title ),
			array( 'object_type' => 'incident', 'object_id' => $row->id )
		);
		return true;
	}

	/**
	 * Delete incidents and their children (also used by retention).
	 *
	 * @param int[] $ids IDs.
	 */
	public static function delete_ids( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		foreach ( array( 'incident_notes', 'incident_evidence' ) as $child ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE incident_id IN ($in)", array_merge( array( Schema::table( $child ) ), $ids ) ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ($in)", array_merge( array( Schema::table( 'incidents' ) ), $ids ) ) );
	}

	/**
	 * Whether an incident with this detection key exists.
	 *
	 * @param string $key Detection key.
	 * @return bool
	 */
	public static function exists_by_key( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE detection_key = %s LIMIT 1', Schema::table( 'incidents' ), $key ) );
	}

	/**
	 * Notes for an incident.
	 *
	 * @param int $incident_id Incident.
	 * @return array
	 */
	public static function notes( $incident_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, note_content, created_at FROM %i WHERE incident_id = %d ORDER BY id ASC', Schema::table( 'incident_notes' ), absint( $incident_id ) ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['id']        = (int) $row['id'];
			$row['user_name'] = Repository::user_name( $row['user_id'] );
		}
		return $rows;
	}

	/**
	 * Add a note.
	 *
	 * @param int    $incident_id Incident.
	 * @param string $content     Note.
	 * @return array|\WP_Error Notes list.
	 */
	public static function add_note( $incident_id, $content ) {
		global $wpdb;
		$content = Repository::text( $content );
		if ( ! self::row( $incident_id ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'The note is empty.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::table( 'incident_notes' ),
			array(
				'incident_id'  => absint( $incident_id ),
				'user_id'      => get_current_user_id(),
				'note_content' => $content,
				'created_at'   => Time::now(),
			)
		);
		self::touch( $incident_id, self::timeline_entry( 'note', '' ) );
		return self::notes( $incident_id );
	}

	/**
	 * Delete a note.
	 *
	 * @param int $incident_id Incident.
	 * @param int $note_id     Note.
	 * @return array Notes list.
	 */
	public static function delete_note( $incident_id, $note_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND incident_id = %d', Schema::table( 'incident_notes' ), absint( $note_id ), absint( $incident_id ) ) );
		return self::notes( $incident_id );
	}

	/**
	 * Linked evidence (metadata only — never the snapshot payload).
	 *
	 * @param int $incident_id Incident.
	 * @return array
	 */
	public static function evidence( $incident_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT l.id AS link_id, e.id, e.evidence_uuid, e.evidence_type, e.evidence_title, e.created_at, l.created_at AS linked_at
			 FROM %i l JOIN %i e ON e.evidence_uuid = l.evidence_uuid
			 WHERE l.incident_id = %d ORDER BY l.id ASC',
			Schema::table( 'incident_evidence' ),
			Schema::table( 'evidence' ),
			absint( $incident_id )
		), ARRAY_A );
	}

	/**
	 * Link an evidence snapshot.
	 *
	 * @param int    $incident_id   Incident.
	 * @param string $evidence_uuid Evidence UUID.
	 * @return array|\WP_Error Evidence list.
	 */
	public static function attach_evidence( $incident_id, $evidence_uuid ) {
		global $wpdb;
		if ( ! self::row( $incident_id ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$evidence_uuid = sanitize_text_field( (string) $evidence_uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE evidence_uuid = %s', Schema::table( 'evidence' ), $evidence_uuid ) );
		if ( ! $exists ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Evidence snapshot not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO %i (incident_id, evidence_uuid, created_at) VALUES (%d, %s, %s)',
			Schema::table( 'incident_evidence' ),
			absint( $incident_id ),
			$evidence_uuid,
			Time::now()
		) );
		self::touch( $incident_id, self::timeline_entry( 'evidence', $evidence_uuid ) );
		return self::evidence( $incident_id );
	}

	/**
	 * Unlink an evidence snapshot.
	 *
	 * @param int $incident_id Incident.
	 * @param int $link_id     Link row ID.
	 * @return array Evidence list.
	 */
	public static function detach_evidence( $incident_id, $link_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND incident_id = %d', Schema::table( 'incident_evidence' ), absint( $link_id ), absint( $incident_id ) ) );
		return self::evidence( $incident_id );
	}

	/**
	 * Count by status.
	 *
	 * @return array
	 */
	public static function status_counts() {
		global $wpdb;
		$counts = array_fill_keys( self::STATUSES, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i GROUP BY status', Schema::table( 'incidents' ) ) ) as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->c;
			}
		}
		return $counts;
	}

	/**
	 * Open incident counts by severity.
	 *
	 * @return array
	 */
	public static function open_by_severity() {
		global $wpdb;
		$counts = array_fill_keys( self::SEVERITIES, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT severity, COUNT(*) AS c FROM %i WHERE status NOT IN ('resolved','closed') GROUP BY severity", Schema::table( 'incidents' ) ) ) as $row ) {
			if ( isset( $counts[ $row->severity ] ) ) {
				$counts[ $row->severity ] = (int) $row->c;
			}
		}
		return $counts;
	}

	/**
	 * Shape a row for the API.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	public static function format( $row ) {
		$data = (array) $row;
		foreach ( array( 'id', 'assigned_user_id', 'owner_id', 'source_log_id' ) as $int ) {
			$data[ $int ] = (int) $data[ $int ];
		}
		$data['nis2_significant'] = (bool) (int) $data['nis2_significant'];
		$data['cross_border']     = (bool) (int) $data['cross_border'];
		$data['timeline']         = Repository::json( $data['timeline_json'] );
		$data['ai_analysis']      = Repository::json( $data['ai_analysis_json'] );
		$data['nis2']             = Nis2Tracker::status( $row );
		$data['assigned_name']    = Repository::user_name( $data['assigned_user_id'] );
		$data['owner_name']       = Repository::user_name( $data['owner_id'] );
		unset( $data['timeline_json'], $data['ai_analysis_json'], $data['nis2_json'], $data['detection_key'] );
		return $data;
	}

	/**
	 * Timeline entry.
	 *
	 * @param string $action Action.
	 * @param string $detail Detail.
	 * @return array
	 */
	private static function timeline_entry( $action, $detail ) {
		$user = wp_get_current_user();
		return array(
			'time'   => Time::now(),
			'action' => $action,
			'detail' => (string) $detail,
			'user'   => ( $user && $user->ID ) ? $user->user_login : 'system',
		);
	}

	/**
	 * Append a timeline entry and bump updated_at.
	 *
	 * @param int   $incident_id Incident.
	 * @param array $entry       Entry.
	 */
	private static function touch( $incident_id, array $entry ) {
		global $wpdb;
		$row = self::row( $incident_id );
		if ( ! $row ) {
			return;
		}
		$timeline   = Repository::json( $row->timeline_json );
		$timeline[] = $entry;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::table( 'incidents' ),
			array(
				'timeline_json' => wp_json_encode( array_slice( $timeline, -200 ) ),
				'updated_at'    => Time::now(),
			),
			array( 'id' => (int) $row->id )
		);
	}
}
