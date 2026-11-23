<?php
namespace TBSHComplianceAuditLogger\Vendors;

use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICT third-party register (DORA Art. 28) with renewal/review reminders.
 */
class VendorRegister {

	const LEVELS   = array( 'low', 'medium', 'high' );
	const ACCESS   = array( 'none', 'read', 'write' );
	const STATUSES = array( 'active', 'inactive' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'send_reminders' ) );
	}

	/**
	 * Paginated list.
	 *
	 * @param array $args criticality, status, search, page, per_page.
	 * @return array
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'vendors' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = array( '1=1' );
		$params = array( $table );
		if ( ! empty( $args['criticality'] ) ) {
			$where[]  = 'criticality = %s';
			$params[] = Repository::enum( $args['criticality'], self::LEVELS, 'medium' );
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = Repository::enum( $args['status'], self::STATUSES, 'active' );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR service_type LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where_sql", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where_sql ORDER BY FIELD(criticality,'high','medium','low'), name ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );

		$result          = Repository::paged( array_map( array( __CLASS__, 'format' ), (array) $rows ), $total, $per_page, $page );
		$result['stats'] = self::stats();
		return $result;
	}

	/**
	 * Single vendor.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'vendors' ), absint( $id ) ) );
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
		$fields['created_at'] = Time::now();
		$fields['updated_at'] = $fields['created_at'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $wpdb->insert( Schema::table( 'vendors' ), $fields ) ) {
			return new \WP_Error( 'tbsh_cal_db', __( 'Could not save the vendor.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::log( 'vendor_created', __( 'Vendor registered', 'tbsh-compliance-audit-logger' ), $id, $fields['name'] );
		SupplyChain::flush();
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
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vendor not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$fields = self::sanitize( $data, $current );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['updated_at'] = Time::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'vendors' ), $fields, array( 'id' => (int) $id ) );
		self::log( 'vendor_updated', __( 'Vendor updated', 'tbsh-compliance-audit-logger' ), $id, $fields['name'] );
		SupplyChain::flush();
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
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vendor not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'vendors' ), array( 'id' => (int) $id ), array( '%d' ) );
		self::log( 'vendor_deleted', __( 'Vendor removed', 'tbsh-compliance-audit-logger' ), $id, $current['name'] );
		SupplyChain::flush();
		return true;
	}

	/**
	 * Statistics.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = Schema::table( 'vendors' );
		$today = wp_date( 'Y-m-d' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total,
				SUM(status = 'active') AS active,
				SUM(status = 'active' AND criticality = 'high') AS critical,
				SUM(status = 'active' AND review_date IS NOT NULL AND review_date < %s) AS overdue_reviews,
				SUM(status = 'active' AND criticality = 'high' AND review_date IS NOT NULL AND review_date < %s) AS critical_overdue,
				SUM(status = 'active' AND contract_renewal_date IS NOT NULL AND contract_renewal_date BETWEEN %s AND %s) AS renewals_soon
			 FROM %i",
			$today,
			$today,
			$today,
			wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
			$table
		) );
		return array(
			'total'            => (int) ( $row->total ?? 0 ),
			'active'           => (int) ( $row->active ?? 0 ),
			'critical'         => (int) ( $row->critical ?? 0 ),
			'overdue_reviews'  => (int) ( $row->overdue_reviews ?? 0 ),
			'critical_overdue' => (int) ( $row->critical_overdue ?? 0 ),
			'renewals_soon'    => (int) ( $row->renewals_soon ?? 0 ),
		);
	}

	/**
	 * Map of linked plugin/theme slug => vendor.
	 *
	 * @return array
	 */
	public static function linked_map() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, criticality, status, linked_slug FROM %i WHERE linked_slug <> '' ORDER BY status = 'active' ASC", Schema::table( 'vendors' ) ) );
		$map  = array();
		foreach ( (array) $rows as $row ) {
			// Active vendors are read last, so they win when a slug is linked twice.
			$map[ $row->linked_slug ] = array( 'id' => (int) $row->id, 'name' => $row->name, 'criticality' => 'active' === $row->status ? $row->criticality : 'low', 'status' => $row->status );
		}
		return $map;
	}

	/**
	 * Daily reminders 30 and 7 days before a renewal or review date.
	 */
	public static function send_reminders() {
		global $wpdb;
		foreach ( array( 30, 7 ) as $days ) {
			$date = wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, contract_renewal_date, review_date FROM %i WHERE status = 'active' AND (contract_renewal_date = %s OR review_date = %s)",
				Schema::table( 'vendors' ),
				$date,
				$date
			) );
			foreach ( (array) $rows as $row ) {
				$what = $row->contract_renewal_date === $date ? __( 'contract renewal', 'tbsh-compliance-audit-logger' ) : __( 'security review', 'tbsh-compliance-audit-logger' );
				AlertManager::trigger(
					'vendor_reminder',
					'warning',
					/* translators: 1: vendor name, 2: reminder type, 3: days */
					sprintf( __( '%1$s: %2$s due in %3$d days', 'tbsh-compliance-audit-logger' ), $row->name, $what, $days ),
					/* translators: %s: date */
					sprintf( __( 'Scheduled for %s. Review the vendor record in Vendor Governance.', 'tbsh-compliance-audit-logger' ), $date ),
					array( 'vendor_id' => (int) $row->id ),
					'vendor_' . $row->id . '_' . $date
				);
			}
		}
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

		$name = Repository::line( $get( 'name', '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'A vendor name is required.', 'tbsh-compliance-audit-logger' ) );
		}
		$service = Repository::line( $get( 'service_type', 'SaaS Integration' ), 100 );

		return array(
			'name'                  => $name,
			'service_type'          => '' !== $service ? $service : 'SaaS Integration',
			'data_access_level'     => Repository::enum( $get( 'data_access_level', 'none' ), self::ACCESS, 'none' ),
			'criticality'           => Repository::enum( $get( 'criticality', 'medium' ), self::LEVELS, 'medium' ),
			'risk_rating'           => Repository::enum( $get( 'risk_rating', 'medium' ), self::LEVELS, 'medium' ),
			'contact_info'          => Repository::text( $get( 'contact_info', '' ), 2000 ),
			'contract_renewal_date' => Repository::date_or_null( $get( 'contract_renewal_date', null ) ),
			'review_date'           => Repository::date_or_null( $get( 'review_date', null ) ),
			'status'                => Repository::enum( $get( 'status', 'active' ), self::STATUSES, 'active' ),
			'linked_slug'           => mb_substr( sanitize_text_field( (string) $get( 'linked_slug', '' ) ), 0, 191 ),
		);
	}

	/**
	 * Shape a row.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	private static function format( $row ) {
		$data       = (array) $row;
		$data['id'] = (int) $data['id'];
		unset( $data['tracking_data_json'] );
		$today                  = wp_date( 'Y-m-d' );
		$data['review_overdue'] = 'active' === $data['status'] && ! empty( $data['review_date'] ) && $data['review_date'] < $today;
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
			/* translators: 1: vendor id, 2: vendor name */
			sprintf( __( 'Vendor #%1$d: %2$s', 'tbsh-compliance-audit-logger' ), $id, $name ),
			array( 'object_type' => 'vendor', 'object_id' => $id, 'compliance_tags' => 'Third-Party Risk, DORA Art. 28' )
		);
	}
}
