<?php
namespace TBSHComplianceAuditLogger\Risk;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Risk register with a 5x5 likelihood × impact matrix.
 */
class RiskRegister {

	const STATUSES   = array( 'active', 'mitigated', 'accepted', 'closed' );
	const CATEGORIES = array( 'Operational', 'Technical', 'Compliance', 'Vendor', 'Security', 'Data Protection' );

	/**
	 * Score band for a likelihood × impact score.
	 *
	 * @param int $score Score 1–25.
	 * @return string low|medium|high|critical
	 */
	public static function band( $score ) {
		$score = (int) $score;
		if ( $score >= 16 ) {
			return 'critical';
		}
		if ( $score >= 10 ) {
			return 'high';
		}
		if ( $score >= 5 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Paginated list.
	 *
	 * @param array $args status, category, band, likelihood, impact, search, page, per_page.
	 * @return array
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'risks' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = array( '1=1' );
		$params = array( $table );
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = Repository::enum( $args['status'], self::STATUSES, 'active' );
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = Repository::line( $args['category'], 50 );
		}
		if ( ! empty( $args['likelihood'] ) && ! empty( $args['impact'] ) ) {
			$where[]  = 'likelihood = %d AND impact = %d';
			$params[] = Repository::clamp( $args['likelihood'], 1, 5 );
			$params[] = Repository::clamp( $args['impact'], 1, 5 );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where_sql", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where_sql ORDER BY score DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );

		$result          = Repository::paged( array_map( array( __CLASS__, 'format' ), (array) $rows ), $total, $per_page, $page );
		$result['stats'] = self::stats();
		return $result;
	}

	/**
	 * Single risk.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'risks' ), absint( $id ) ) );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Create a risk.
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
		$fields['created_at'] = Time::now();
		$fields['updated_at'] = $fields['created_at'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $wpdb->insert( Schema::table( 'risks' ), $fields ) ) {
			return new \WP_Error( 'tbsh_cal_db', __( 'Could not save the risk.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::log( 'risk_created', __( 'Risk registered', 'tbsh-compliance-audit-logger' ), $id, $fields['title'] );
		return self::get( $id );
	}

	/**
	 * Update a risk.
	 *
	 * @param int   $id   ID.
	 * @param array $data Fields.
	 * @return array|\WP_Error
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Risk not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$fields = self::sanitize( $data, $current );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['updated_at'] = Time::now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'risks' ), $fields, array( 'id' => (int) $id ) );
		self::log( 'risk_updated', __( 'Risk updated', 'tbsh-compliance-audit-logger' ), $id, $fields['title'] );
		return self::get( $id );
	}

	/**
	 * Delete a risk.
	 *
	 * @param int $id ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Risk not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'risks' ), array( 'id' => (int) $id ), array( '%d' ) );
		self::log( 'risk_deleted', __( 'Risk deleted', 'tbsh-compliance-audit-logger' ), $id, $current['title'] );
		return true;
	}

	/**
	 * Register statistics: status counts, bands (active only), 5x5 matrix, overdue reviews.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = Schema::table( 'risks' );

		$by_status = array_fill_keys( self::STATUSES, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i GROUP BY status', $table ) ) as $row ) {
			if ( isset( $by_status[ $row->status ] ) ) {
				$by_status[ $row->status ] = (int) $row->c;
			}
		}

		$matrix = array();
		for ( $l = 1; $l <= 5; $l++ ) {
			$matrix[ $l ] = array_fill( 1, 5, 0 );
		}
		$bands = array( 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT likelihood, impact, COUNT(*) AS c FROM %i WHERE status = 'active' GROUP BY likelihood, impact", $table ) ) as $row ) {
			$l = Repository::clamp( $row->likelihood, 1, 5 );
			$i = Repository::clamp( $row->impact, 1, 5 );
			$matrix[ $l ][ $i ] += (int) $row->c;
			$bands[ self::band( $l * $i ) ] += (int) $row->c;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active' AND review_date IS NOT NULL AND review_date < %s", $table, wp_date( 'Y-m-d' ) ) );

		return array(
			'by_status'       => $by_status,
			'bands'           => $bands,
			'matrix'          => $matrix,
			'overdue_reviews' => $overdue,
			'total'           => array_sum( $by_status ),
		);
	}

	/**
	 * Validate input.
	 *
	 * @param array      $data    Input.
	 * @param array|null $current Existing risk for partial updates.
	 * @return array|\WP_Error
	 */
	private static function sanitize( array $data, $current ) {
		$get = function ( $key, $default ) use ( $data, $current ) {
			if ( array_key_exists( $key, $data ) ) {
				return $data[ $key ];
			}
			return $current ? $current[ $key ] : $default;
		};

		$title = Repository::line( $get( 'title', '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'A risk title is required.', 'tbsh-compliance-audit-logger' ) );
		}
		$likelihood = Repository::clamp( $get( 'likelihood', 3 ), 1, 5 );
		$impact     = Repository::clamp( $get( 'impact', 3 ), 1, 5 );
		$category   = Repository::line( $get( 'category', 'Security' ), 50 );

		return array(
			'title'               => $title,
			'description'         => Repository::text( $get( 'description', '' ) ),
			'category'            => '' !== $category ? $category : 'Security',
			'likelihood'          => $likelihood,
			'impact'              => $impact,
			'score'               => $likelihood * $impact,
			'mitigation_plan'     => Repository::text( $get( 'mitigation_plan', '' ) ),
			'owner_id'            => absint( $get( 'owner_id', get_current_user_id() ) ),
			'review_date'         => Repository::date_or_null( $get( 'review_date', null ) ),
			'status'              => Repository::enum( $get( 'status', 'active' ), self::STATUSES, 'active' ),
			'compliance_mappings' => Repository::line( $get( 'compliance_mappings', '' ) ),
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
		foreach ( array( 'id', 'likelihood', 'impact', 'score', 'owner_id' ) as $int ) {
			$data[ $int ] = (int) $data[ $int ];
		}
		$data['band']           = self::band( $data['score'] );
		$data['owner_name']     = Repository::user_name( $data['owner_id'] );
		$data['review_overdue'] = 'active' === $data['status'] && ! empty( $data['review_date'] ) && $data['review_date'] < wp_date( 'Y-m-d' );
		return $data;
	}

	/**
	 * Audit-log a register change.
	 */
	private static function log( $type, $title, $id, $name ) {
		Logger::log(
			$type,
			'Change Management',
			'info',
			$title,
			/* translators: 1: risk id, 2: risk title */
			sprintf( __( 'Risk #%1$d: %2$s', 'tbsh-compliance-audit-logger' ), $id, $name ),
			array( 'object_type' => 'risk', 'object_id' => $id, 'compliance_tags' => 'Risk Management, ISO 27001 6.1.2' )
		);
	}
}
