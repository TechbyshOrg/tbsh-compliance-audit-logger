<?php
namespace TBSHComplianceAuditLogger\Vaults;

use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Vaults\Providers\RemoteSite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Receives encrypted vault packages pushed by other sites (disabled unless enabled
 * in Settings with a receiver token). Packages stay encrypted at rest; this site
 * never learns their passphrase.
 */
class Receiver {

	const MAX_AGE = 300;

	/**
	 * Handle a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		$settings = get_option( 'tbsh_cal_settings', array() );
		$token    = Secrets::get( 'vault_receiver_token' );
		if ( empty( $settings['vault_receiver_enabled'] ) || '' === $token ) {
			return new \WP_Error( 'tbsh_cal_receiver_disabled', __( 'This site does not accept vault packages.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$timestamp = (string) $request->get_header( 'x_tbsh_timestamp' );
		$filename  = (string) $request->get_header( 'x_tbsh_filename' );
		$sha       = strtolower( (string) $request->get_header( 'x_tbsh_content_sha256' ) );
		$signature = strtolower( (string) $request->get_header( 'x_tbsh_signature' ) );
		$body      = (string) $request->get_body();

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_AGE ) {
			return self::deny( __( 'Request expired or clock skew too large.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,150}\.tbshvault$/', $filename ) ) {
			return self::deny( __( 'Invalid file name.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( ! hash_equals( hash( 'sha256', $body ), $sha ) ) {
			return self::deny( __( 'Body checksum mismatch.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( ! hash_equals( RemoteSite::signature( $timestamp, $filename, $sha, $token ), $signature ) ) {
			return self::deny( __( 'Invalid signature.', 'tbsh-compliance-audit-logger' ) );
		}

		$replay = 'tbsh_cal_rcv_' . substr( $signature, 0, 40 );
		if ( get_transient( $replay ) ) {
			return self::deny( __( 'Duplicate request.', 'tbsh-compliance-audit-logger' ) );
		}
		set_transient( $replay, 1, 2 * self::MAX_AGE );

		if ( '1' === (string) $request->get_header( 'x_tbsh_test' ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'test' => true ), 200 );
		}

		/**
		 * Maximum accepted package size in bytes.
		 *
		 * @param int $bytes Default 100 MB.
		 */
		$max = (int) apply_filters( 'tbsh_cal_vault_receiver_max_bytes', 100 * MB_IN_BYTES );
		if ( strlen( $body ) > $max || 0 !== strpos( $body, Packager::MAGIC ) ) {
			return self::deny( __( 'Package rejected (size or format).', 'tbsh-compliance-audit-logger' ) );
		}

		$dir = Storage::dir( 'vault-inbox' );
		if ( ! $dir ) {
			return new \WP_Error( 'tbsh_cal_storage', __( 'Storage unavailable.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$stored = Storage::unique_name( 'received-' . substr( preg_replace( '/[^A-Za-z0-9-]/', '', pathinfo( $filename, PATHINFO_FILENAME ) ), 0, 60 ), 'tbshvault' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $dir . $stored, $body ) ) {
			return new \WP_Error( 'tbsh_cal_storage', __( 'Could not store the package.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}

		$source = esc_url_raw( (string) $request->get_header( 'x_tbsh_source' ) );
		Logger::log(
			'vault_package_received',
			'Operational Security',
			'info',
			__( 'Off-site evidence package received', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: source site, 2: bytes */
			sprintf( __( 'Encrypted package from %1$s stored (%2$s bytes).', 'tbsh-compliance-audit-logger' ), $source ? $source : __( 'unknown site', 'tbsh-compliance-audit-logger' ), number_format_i18n( strlen( $body ) ) ),
			array( 'object_type' => 'vault_package', 'object_id' => $stored, 'username' => 'remote-site' )
		);

		return new \WP_REST_Response( array( 'received' => true, 'stored_as' => $stored ), 201 );
	}

	/**
	 * Uniform 401 with a short delay to slow brute force.
	 *
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function deny( $message ) {
		usleep( 250000 );
		return new \WP_Error( 'tbsh_cal_receiver_denied', $message, array( 'status' => 401 ) );
	}
}
