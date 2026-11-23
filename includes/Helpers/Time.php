<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single timezone convention for the plugin.
 *
 * Every `created_at` column is written with current_time( 'mysql' ), i.e. in the
 * site's local timezone. All cutoffs compared against those columns must therefore
 * also be expressed in site-local time — never gmdate()/date()/NOW().
 */
class Time {

	/**
	 * MySQL datetime format.
	 */
	const FORMAT = 'Y-m-d H:i:s';

	/**
	 * Current site-local datetime string.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Site-local datetime string N seconds in the past.
	 *
	 * @param int $seconds Seconds ago.
	 * @return string
	 */
	public static function ago( $seconds ) {
		return self::local()->modify( '-' . absint( $seconds ) . ' seconds' )->format( self::FORMAT );
	}

	/**
	 * Site-local datetime string N seconds in the future.
	 *
	 * @param int $seconds Seconds ahead.
	 * @return string
	 */
	public static function from_now( $seconds ) {
		return self::local()->modify( '+' . absint( $seconds ) . ' seconds' )->format( self::FORMAT );
	}

	/**
	 * Start of today (site-local) as a datetime string.
	 *
	 * @return string
	 */
	public static function today_start() {
		return self::local()->setTime( 0, 0, 0 )->format( self::FORMAT );
	}

	/**
	 * Site-local date (Y-m-d) N days ago.
	 *
	 * @param int $days Days ago.
	 * @return string
	 */
	public static function date_days_ago( $days ) {
		return self::local()->modify( '-' . absint( $days ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Convert a site-local datetime string into a Unix timestamp.
	 *
	 * @param string $datetime Site-local datetime.
	 * @return int|null Null when unparsable.
	 */
	public static function to_timestamp( $datetime ) {
		if ( empty( $datetime ) ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $datetime, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		return checkdate( $m, $d, $y );
	}

	/**
	 * Current site-local DateTimeImmutable.
	 *
	 * @return \DateTimeImmutable
	 */
	private static function local() {
		return new \DateTimeImmutable( 'now', wp_timezone() );
	}
}
