<?php
namespace TBSHComplianceAuditLogger\Integrity;

use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies WordPress core file checksums via the WordPress.org API.
 */
class CoreChecksum {

	const OPTION_KEY = 'tbsh_cal_core_checksum_result';

	/**
	 * Run core checksum verification.
	 *
	 * @return array Result payload.
	 */
	public static function verify() {
		global $wp_version;

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$locale    = get_locale();
		$checksums = get_core_checksums( $wp_version, $locale );

		if ( ! is_array( $checksums ) || empty( $checksums ) ) {
			$result = array(
				'status'    => 'error',
				'message'   => __( 'Could not retrieve core checksums from WordPress.org.', 'tbsh-compliance-audit-logger' ),
				'checked'   => 0,
				'mismatches'=> array(),
				'date'      => current_time( 'mysql' ),
			);
			update_option( self::OPTION_KEY, $result, false );
			return $result;
		}

		$mismatches = array();
		$checked    = 0;

		foreach ( $checksums as $file => $expected ) {
			// Skip wp-content — not part of core distribution authenticity check for themes/plugins.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}

			$path = ABSPATH . $file;
			$checked++;

			if ( ! file_exists( $path ) ) {
				$mismatches[] = array(
					'file'   => $file,
					'reason' => 'missing',
				);
				continue;
			}

			$actual = md5_file( $path );
			if ( $actual !== $expected ) {
				$mismatches[] = array(
					'file'   => $file,
					'reason' => 'mismatch',
				);
			}

			// Cap reported mismatches to keep option size small.
			if ( count( $mismatches ) >= 50 ) {
				break;
			}
		}

		$status = empty( $mismatches ) ? 'ok' : 'fail';

		$result = array(
			'status'     => $status,
			'message'    => 'ok' === $status
				? __( 'All checked WordPress core files match official checksums.', 'tbsh-compliance-audit-logger' )
				: sprintf(
					/* translators: %d: number of mismatched files */
					__( '%d core file(s) do not match official checksums.', 'tbsh-compliance-audit-logger' ),
					count( $mismatches )
				),
			'checked'    => $checked,
			'mismatches' => $mismatches,
			'wp_version' => $wp_version,
			'date'       => current_time( 'mysql' ),
		);

		update_option( self::OPTION_KEY, $result, false );

		Logger::log(
			'core_checksum_check',
			'Security Monitoring',
			'ok' === $status ? 'info' : 'critical',
			__( 'Core file checksum verification', 'tbsh-compliance-audit-logger' ),
			$result['message'],
			array(
				'compliance_tags' => 'Security Monitoring, File Integrity',
				'metadata'        => array(
					'status'  => $status,
					'checked' => $checked,
					'issues'  => count( $mismatches ),
				),
			)
		);

		return $result;
	}

	/**
	 * Get last stored result.
	 *
	 * @return array
	 */
	public static function get_latest() {
		$result = get_option( self::OPTION_KEY, array() );
		if ( empty( $result ) ) {
			return array(
				'status'  => 'unverified',
				'message' => __( 'Core checksum verification has not been run yet.', 'tbsh-compliance-audit-logger' ),
				'date'    => null,
			);
		}
		return $result;
	}
}
