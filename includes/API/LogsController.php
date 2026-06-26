<?php
namespace TBSHComplianceAuditLogger\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for Logs REST endpoints.
 */
class LogsController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/logs', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
			),
		) );

		register_rest_route( $this->namespace, '/logs/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'check_view_permission' ),
			),
		) );
	}

	/**
	 * Validate permissions.
	 */
	public function check_view_permission() {
		return $this->check_permission( 'tbsh_cal_view_logs' );
	}

	/**
	 * Get logs list.
	 */
	public function get_items( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';

		// Parameters.
		$page      = max( 1, intval( $request->get_param( 'page' ) ) );
		$per_page  = max( 1, min( 100, intval( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$search    = sanitize_text_field( $request->get_param( 'search' ) );
		$severity  = sanitize_text_field( $request->get_param( 'severity' ) );
		$category  = sanitize_text_field( $request->get_param( 'category' ) );
		$event_type= sanitize_text_field( $request->get_param( 'event_type' ) );
		$user_id   = intval( $request->get_param( 'user_id' ) );
		$site_id   = intval( $request->get_param( 'site_id' ) );
		$date_start= sanitize_text_field( $request->get_param( 'date_start' ) );
		$date_end  = sanitize_text_field( $request->get_param( 'date_end' ) );

		$orderby   = sanitize_key( $request->get_param( 'orderby' ) ?: 'id' );
		$order     = strtoupper( sanitize_key( $request->get_param( 'order' ) ?: 'DESC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Allowed orderby columns.
		$allowed_orderby = array( 'id', 'created_at', 'severity', 'event_category', 'event_type', 'user_id', 'username' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		// Clauses.
		$where = array( '1=1' );
		$args  = array();

		if ( ! empty( $search ) ) {
			$where[] = '(event_title LIKE %s OR event_message LIKE %s OR username LIKE %s OR object_type LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}

		if ( ! empty( $severity ) ) {
			$where[] = 'severity = %s';
			$args[]  = $severity;
		}

		if ( ! empty( $category ) ) {
			$where[] = 'event_category = %s';
			$args[]  = $category;
		}

		if ( ! empty( $event_type ) ) {
			$where[] = 'event_type = %s';
			$args[]  = $event_type;
		}

		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}

		if ( $site_id > 0 ) {
			$where[] = 'site_id = %d';
			$args[]  = $site_id;
		} elseif ( ! is_multisite() ) {
			$where[] = 'site_id = %d';
			$args[]  = 1;
		}

		if ( ! empty( $date_start ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $date_start . ' 00:00:00';
		}

		if ( ! empty( $date_end ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $date_end . ' 23:59:59';
		}

		$where_clause = implode( ' AND ', $where );

		// Count Query.
		$count_sql = "SELECT COUNT(*) FROM %i WHERE $where_clause";
		$count_args = array_merge( array( $table_name ), $args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = $wpdb->prepare( $count_sql, $count_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$total_items = intval( $wpdb->get_var( $count_sql ) );

		// Items Query.
		$items_sql = "SELECT * FROM %i WHERE $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
		$query_args = array_merge( array( $table_name ), $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items_sql  = $wpdb->prepare( $items_sql, $query_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
		$logs       = $wpdb->get_results( $items_sql );

		// Decode metadata JSON safely.
		foreach ( $logs as &$log ) {
			$log->metadata = json_decode( $log->metadata_json, true );
			unset( $log->metadata_json );
		}

		return $this->success( array(
			'logs'       => $logs,
			'total'      => $total_items,
			'pages'      => ceil( $total_items / $per_page ),
			'page'       => $page,
			'per_page'   => $per_page,
		) );
	}

	/**
	 * Get single log.
	 */
	public function get_item( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'tbsh_cal_logs';
		$id         = intval( $request['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $table_name, $id ) );

		if ( ! $log ) {
			return $this->error( 'tbsh_cal_not_found', __( 'Log not found.', 'tbsh-compliance-audit-logger' ), 404 );
		}

		$log->metadata = json_decode( $log->metadata_json, true );
		unset( $log->metadata_json );

		return $this->success( $log );
	}
}
