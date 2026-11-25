<?php
namespace TBSHComplianceAuditLogger\Resilience;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operational resilience testing register (DORA Art. 24–25): drills with RTO/RPO
 * targets and results, plus metrics computed from real incident and backup data.
 */
class DrillRegister {

	const SCENARIOS = array( 'backup_restore', 'failover', 'incident_response', 'tabletop', 'other' );
	const STATUSES  = array( 'planned', 'completed', 'cancelled' );
	const OUTCOMES  = array( '', 'passed', 'partial', 'failed' );

	/**
	 * Paginated list.
	 *
	 * @param array $args status, scenario, page, per_page.
	 * @return array
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'resilience' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = array( '1=1' );
		$params = array( $table );
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = Repository::enum( $args['status'], self::STATUSES, 'planned' );
		}
		if ( ! empty( $args['scenario'] ) ) {
			$where[]  = 'scenario = %s';
			$params[] = Repository::enum( $args['scenario'], self::SCENARIOS, 'other' );
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The WHERE clause holds only placeholders built in this method; every value goes through $wpdb->prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where_sql", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The WHERE clause holds only placeholders built in this method; every value goes through $wpdb->prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where_sql ORDER BY COALESCE(completed_at, scheduled_at, created_at) DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );

		return Repository::paged( array_map( array( __CLASS__, 'format' ), (array) $rows ), $total, $per_page, $page );
	}

	/**
	 * Single drill.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'resilience' ), absint( $id ) ) );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Create.
	 *
	 * @param array $data Fields.
	 * @return array|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$fields = self::sanitize( $data, null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['created_by'] = get_current_user_id();
		$fields['created_at'] = Time::now();
		$fields['updated_at'] = $fields['created_at'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $wpdb->insert( Schema::table( 'resilience' ), $fields ) ) {
			return new \WP_Error( 'tbsh_cal_db', __( 'Could not save the drill.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::log( 'resilience_drill_created', $id, $fields );
		return self::get( $id );
	}

	/**
	 * Update.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields.
	 * @return array|\WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Drill not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$fields = self::sanitize( $data, $current );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['updated_at'] = Time::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'resilience' ), $fields, array( 'id' => (int) $id ) );
		self::log( 'resilience_drill_updated', $id, $fields );
		return self::get( $id );
	}

	/**
	 * Delete.
	 *
	 * @param int $id ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Drill not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'resilience' ), array( 'id' => (int) $id ), array( '%d' ) );
		Logger::log(
			'resilience_drill_deleted',
			'Operational Security',
			'notice',
			__( 'Resilience drill deleted', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: drill id, 2: title */
			sprintf( __( 'Drill #%1$d "%2$s" was deleted.', 'tbsh-compliance-audit-logger' ), $id, $current['title'] ),
			array( 'object_type' => 'resilience_drill', 'object_id' => $id )
		);
		return true;
	}

	/**
	 * Resilience metrics from real data.
	 *
	 * @return array
	 */
	public static function metrics() {
		global $wpdb;
		$drills    = Schema::table( 'resilience' );
		$incidents = Schema::table( 'incidents' );
		$vault_log = Schema::table( 'vault_logs' );
		$year_ago  = Time::ago( 365 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$drill_stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS completed, SUM(outcome = 'passed') AS passed, SUM(outcome = 'partial') AS partial, SUM(outcome = 'failed') AS failed, MAX(completed_at) AS last_completed
			 FROM %i WHERE status = 'completed' AND completed_at >= %s",
			$drills,
			$year_ago
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, outcome, completed_at FROM %i WHERE status = 'completed' ORDER BY completed_at DESC LIMIT 1", $drills ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$next = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, scheduled_at FROM %i WHERE status = 'planned' AND scheduled_at >= %s ORDER BY scheduled_at ASC LIMIT 1", $drills, Time::now() ), ARRAY_A );

		// Mean time to resolve (hours) over the last 12 months.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$mttr = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(TIMESTAMPDIFF(MINUTE, COALESCE(detected_at, created_at), resolved_at)) FROM %i WHERE resolved_at IS NOT NULL AND resolved_at >= %s",
			$incidents,
			$year_ago
		) );

		// Incidents per month, last 6 months.
		$months = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$months[ wp_date( 'Y-m', strtotime( "first day of -$i month", current_time( 'timestamp' ) ) ) ] = 0; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS m, COUNT(*) AS c FROM %i WHERE created_at >= %s GROUP BY m", $incidents, array_key_first( $months ) . '-01 00:00:00' ) ) as $row ) {
			if ( isset( $months[ $row->m ] ) ) {
				$months[ $row->m ] = (int) $row->c;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$last_backup = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM %i WHERE status = 'success'", $vault_log ) );

		foreach ( array( &$last, &$next ) as &$drill ) {
			if ( is_array( $drill ) ) {
				$drill['id'] = (int) $drill['id'];
			}
		}
		unset( $drill );

		$completed = (int) ( $drill_stats->completed ?? 0 );
		return array(
			'drills_completed_12m'  => $completed,
			'drills_passed_12m'     => (int) ( $drill_stats->passed ?? 0 ),
			'drill_pass_rate'       => $completed ? round( 100 * (int) $drill_stats->passed / $completed ) : null,
			'last_drill'            => $last,
			'next_drill'            => $next,
			'mttr_hours'            => null === $mttr ? null : round( (float) $mttr / 60, 1 ),
			'incidents_by_month'    => $months,
			'last_offsite_backup'   => $last_backup,
		);
	}

	/**
	 * Validate input.
	 *
	 * @param array      $data    Input.
	 * @param array|null $current Existing.
	 * @return array|\WP_Error
	 */
	private static function sanitize( array $data, $current ) {
		$get = function ( $key, $default ) use ( $data, $current ) {
			if ( array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
			return $current ? $current[ $key ] : $default;
		};
		$minutes = function ( $value ) {
			return ( null === $value || '' === $value ) ? null : max( 0, min( 525600, (int) $value ) );
		};

		$title = Repository::line( $get( 'title', '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'A drill title is required.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		$status       = Repository::enum( $get( 'status', 'planned' ), self::STATUSES, 'planned' );
		$completed_at = Repository::datetime_or_null( $get( 'completed_at', null ) );
		if ( 'completed' === $status && ! $completed_at ) {
			$completed_at = Time::now();
		}

		return array(
			'title'          => $title,
			'scenario'       => Repository::enum( $get( 'scenario', 'tabletop' ), self::SCENARIOS, 'other' ),
			'status'         => $status,
			'scheduled_at'   => Repository::datetime_or_null( $get( 'scheduled_at', null ) ),
			'completed_at'   => 'completed' === $status ? $completed_at : null,
			'rto_target_min' => $minutes( $get( 'rto_target_min', null ) ),
			'rto_actual_min' => $minutes( $get( 'rto_actual_min', null ) ),
			'rpo_target_min' => $minutes( $get( 'rpo_target_min', null ) ),
			'rpo_actual_min' => $minutes( $get( 'rpo_actual_min', null ) ),
			'outcome'        => 'completed' === $status ? Repository::enum( $get( 'outcome', '' ), self::OUTCOMES, '' ) : '',
			'notes'          => Repository::text( $get( 'notes', '' ) ),
		);
	}

	/**
	 * Shape a row.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	private static function format( $row ) {
		$data = (array) $row;
		foreach ( array( 'id', 'created_by' ) as $int ) {
			$data[ $int ] = (int) $data[ $int ];
		}
		foreach ( array( 'rto_target_min', 'rto_actual_min', 'rpo_target_min', 'rpo_actual_min' ) as $min ) {
			$data[ $min ] = null === $data[ $min ] ? null : (int) $data[ $min ];
		}
		$data['rto_met'] = ( null !== $data['rto_target_min'] && null !== $data['rto_actual_min'] ) ? $data['rto_actual_min'] <= $data['rto_target_min'] : null;
		$data['rpo_met'] = ( null !== $data['rpo_target_min'] && null !== $data['rpo_actual_min'] ) ? $data['rpo_actual_min'] <= $data['rpo_target_min'] : null;
		return $data;
	}

	/**
	 * Audit-log.
	 */
	private static function log( $type, $id, array $fields ) {
		Logger::log(
			$type,
			'Operational Security',
			'info',
			__( 'Resilience drill recorded', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: drill id, 2: title, 3: status */
			sprintf( __( 'Drill #%1$d "%2$s" (%3$s).', 'tbsh-compliance-audit-logger' ), $id, $fields['title'], $fields['status'] ),
			array( 'object_type' => 'resilience_drill', 'object_id' => $id, 'compliance_tags' => 'DORA Art. 24, ISO 27001 A.5.30' )
		);
	}
}
