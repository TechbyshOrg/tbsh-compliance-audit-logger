<?php
namespace TBSHComplianceAuditLogger\Timeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles investigations timeline data queries.
 */
class ForensicTimeline {

	/**
	 * Get chronological event list for forensics (paginated).
	 *
	 * @param array $filters Query filters including page/per_page.
	 * @return array{items:array,total:int,page:int,per_page:int,total_pages:int}
	 */
	public static function get_timeline( $filters = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $filters['date_start'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['date_start'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_end'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['date_end'] . ' 23:59:59';
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[] = 'event_category = %s';
			$args[]  = $filters['category'];
		}
		if ( ! empty( $filters['severity'] ) ) {
			$where[] = 'severity = %s';
			$args[]  = $filters['severity'];
		}
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = intval( $filters['user_id'] );
		}
		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		$exact  = false;
		do {
			$try_where = $where;
			$try_args  = $args;
			$mode      = '';
			if ( '' !== $search ) {
				// The faster of index and substring search; substring search when the
				// index finds nothing (see LogSearch::choose()).
				list( $search_sql, $search_args, $mode ) = $exact
					? \TBSHComplianceAuditLogger\Logging\LogSearch::clause( $search, true )
					: \TBSHComplianceAuditLogger\Logging\LogSearch::choose( $search, $where, $args );
				$try_where[] = $search_sql;
				$try_args    = array_merge( $try_args, $search_args );
			}
			$where_clause = implode( ' AND ', $try_where );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE $where_clause LIMIT 1 ) AS any_row", array_merge( array( $table_name ), $try_args ) ) );
			$retry = 'fulltext' === $mode && 0 === $found;
			$exact = true;
		} while ( $retry );
		$where = $try_where;
		$args  = $try_args;

		$page     = max( 1, isset( $filters['page'] ) ? intval( $filters['page'] ) : 1 );
		$per_page = max( 1, min( 100, isset( $filters['per_page'] ) ? intval( $filters['per_page'] ) : 50 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM %i WHERE $where_clause";
		$count_args = array_merge( array( $table_name ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = $wpdb->prepare( $count_sql, $count_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = intval( $wpdb->get_var( $count_sql ) );

		$sql = "SELECT id, created_at, event_uuid, event_type, event_category, severity, event_title, event_message, user_id, username, role, ip_hash, request_method, request_uri
		           FROM %i
		           WHERE $where_clause
		           ORDER BY id DESC
		           LIMIT %d OFFSET %d";

		$query_args = array_merge( array( $table_name ), $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $wpdb->prepare( $sql, $query_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $sql );

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		);
	}
}
