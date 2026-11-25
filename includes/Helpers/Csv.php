<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spreadsheet-safe CSV rows.
 *
 * Log fields can contain text chosen by an attacker (for example a username typed
 * on the login form). A cell starting with = + - @ or a control character runs as
 * a formula when the export is opened in Excel, LibreOffice or Google Sheets, so
 * such cells get a leading apostrophe.
 */
class Csv {

	/**
	 * Make one cell safe to open in a spreadsheet.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		return ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $value : $value;
	}

	/**
	 * Write a spreadsheet-safe row.
	 *
	 * @param resource $handle File handle.
	 * @param array    $row    Cells.
	 * @return int|false
	 */
	public static function put( $handle, array $row ) {
		return fputcsv( $handle, array_map( array( __CLASS__, 'cell' ), $row ), ',', '"', '\\' );
	}
}
