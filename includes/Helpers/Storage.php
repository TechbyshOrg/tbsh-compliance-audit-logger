<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private on-disk storage for generated files (reports, archives, vault packages).
 *
 * Files live under uploads/tbsh-compliance-private/{area}/ and are protected by an
 * index.php and an .htaccess that denies access on Apache 2.2 and 2.4. Web servers
 * that ignore .htaccess (e.g. nginx) are covered by unguessable file names and by
 * the System Health probe (is_publicly_readable()).
 */
class Storage {

	/**
	 * Base folder name inside uploads.
	 */
	const BASE = 'tbsh-compliance-private';

	/**
	 * Allowed storage areas.
	 */
	const AREAS = array( 'reports', 'archives', 'vault-tmp', 'vault-inbox', 'probe' );

	/**
	 * Absolute path (with trailing slash) to a protected storage area.
	 *
	 * @param string $area Area name (one of AREAS).
	 * @return string|false False when the area is unknown or cannot be created.
	 */
	public static function dir( $area ) {
		if ( ! in_array( $area, self::AREAS, true ) ) {
			return false;
		}

		$base = self::base_dir();
		$dir  = $base . $area . '/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::protect( $base );
		self::protect( $dir );

		return $dir;
	}

	/**
	 * Generate an unguessable file name.
	 *
	 * @param string $prefix    Name prefix (letters, digits, dashes, underscores).
	 * @param string $extension Extension without dot.
	 * @return string
	 */
	public static function unique_name( $prefix, $extension ) {
		$prefix    = preg_replace( '/[^a-z0-9_-]/i', '', (string) $prefix );
		$extension = preg_replace( '/[^a-z0-9.]/i', '', (string) $extension );

		return $prefix . '_' . bin2hex( random_bytes( 16 ) ) . '.' . $extension;
	}

	/**
	 * Resolve a stored file name inside an area, rejecting traversal.
	 *
	 * @param string $area      Area name.
	 * @param string $file_name Bare file name.
	 * @return string|false Absolute path, or false when invalid/missing.
	 */
	public static function path( $area, $file_name ) {
		$dir = self::dir( $area );
		if ( ! $dir || ! is_string( $file_name ) || $file_name !== basename( $file_name ) || ! preg_match( '/^[A-Za-z0-9._-]+$/', $file_name ) ) {
			return false;
		}

		$path = $dir . $file_name;
		return is_file( $path ) ? $path : false;
	}

	/**
	 * Check whether files in the private area can be fetched over HTTP.
	 *
	 * Writes a probe file and requests it. Result cached for 12 hours.
	 *
	 * @param bool $force Bypass the cache.
	 * @return bool|null True if publicly readable (insecure), false if protected,
	 *                   null if the check could not be performed.
	 */
	public static function is_publicly_readable( $force = false ) {
		$cached = get_transient( 'tbsh_cal_storage_probe' );
		if ( ! $force && false !== $cached ) {
			return 'unknown' === $cached ? null : ( 'public' === $cached );
		}

		$dir = self::dir( 'probe' );
		if ( ! $dir ) {
			set_transient( 'tbsh_cal_storage_probe', 'unknown', HOUR_IN_SECONDS );
			return null;
		}

		$name   = 'probe.txt';
		$marker = 'tbsh-cal-probe-' . wp_generate_password( 12, false, false );
		file_put_contents( $dir . $name, $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$upload   = wp_upload_dir();
		$url      = trailingslashit( $upload['baseurl'] ) . self::BASE . '/probe/' . $name;
		$response = wp_remote_get( $url, array( 'timeout' => 5, 'sslverify' => false ) );

		wp_delete_file( $dir . $name );

		if ( is_wp_error( $response ) ) {
			set_transient( 'tbsh_cal_storage_probe', 'unknown', HOUR_IN_SECONDS );
			return null;
		}

		$public = 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), $marker );
		set_transient( 'tbsh_cal_storage_probe', $public ? 'public' : 'protected', 12 * HOUR_IN_SECONDS );

		return $public;
	}

	/**
	 * Recursively delete the whole private storage tree (used on uninstall).
	 *
	 * @return void
	 */
	public static function delete_all() {
		self::rrmdir( self::base_dir() );
	}

	/**
	 * Absolute base dir (trailing slash).
	 *
	 * @return string
	 */
	private static function base_dir() {
		$upload = wp_upload_dir( null, false );
		return trailingslashit( $upload['basedir'] ) . self::BASE . '/';
	}

	/**
	 * Ensure protection files exist in a directory.
	 *
	 * @param string $dir Directory with trailing slash.
	 * @return void
	 */
	private static function protect( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			$rules = "# Deny all web access.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $dir . '.htaccess', $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Recursive directory removal.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
