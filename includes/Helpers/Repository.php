<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared helpers for the register-style repositories (incidents, risks,
 * vendors, drills, reports, vaults): input normalisation and pagination.
 */
class Repository {

	/**
	 * Clamp pagination args.
	 *
	 * @param array $args Request args.
	 * @return array{0:int,1:int,2:int} page, per_page, offset.
	 */
	public static function page_args( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		return array( $page, $per_page, ( $page - 1 ) * $per_page );
	}

	/**
	 * Standard paginated response shape.
	 *
	 * @param array $items    Items.
	 * @param int   $total    Total rows.
	 * @param int   $per_page Per page.
	 * @param int   $page     Page.
	 * @return array
	 */
	public static function paged( array $items, $total, $per_page, $page ) {
		return array(
			'items'       => $items,
			'total'       => (int) $total,
			'total_pages' => (int) max( 1, ceil( $total / max( 1, $per_page ) ) ),
			'page'        => (int) $page,
		);
	}

	/**
	 * Return $value if it is in $allowed, otherwise $default.
	 *
	 * @param mixed $value   Value.
	 * @param array $allowed Allowed values.
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	public static function enum( $value, array $allowed, $default ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : $value;
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Clamp an integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 * @return int
	 */
	public static function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Valid Y-m-d date or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function date_or_null( $value ) {
		return ( is_string( $value ) && Time::is_valid_date( $value ) ) ? $value : null;
	}

	/**
	 * Valid site-local datetime (Y-m-d H:i[:s] or ISO-ish) or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function datetime_or_null( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = str_replace( 'T', ' ', substr( trim( $value ), 0, 19 ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
			$value .= ':00';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) || ! Time::is_valid_date( substr( $value, 0, 10 ) ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Multi-line text field.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function text( $value, $max = 5000 ) {
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, $max );
	}

	/**
	 * Single-line text field.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max length.
	 * @return string
	 */
	public static function line( $value, $max = 255 ) {
		return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	/**
	 * Decode a JSON column into an array.
	 *
	 * @param mixed $value Raw column.
	 * @return array
	 */
	public static function json( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Display name for a user id.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_name( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( ! isset( $cache[ $user_id ] ) ) {
			$user              = get_userdata( $user_id );
			$cache[ $user_id ] = $user ? $user->display_name : '';
		}
		return $cache[ $user_id ];
	}
}
