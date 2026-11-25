<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Address checks for connections the plugin opens itself (syslog, FTP).
 *
 * WordPress's wp_safe_remote_*() covers HTTP; raw sockets and the FTP extension
 * need the same protection against being pointed at internal services.
 */
class Network {

	/**
	 * Resolve a host name to its IPv4 addresses (an IP literal is returned as is).
	 *
	 * @param string $host Host name or IP.
	 * @return string[] Addresses, empty when the name does not resolve.
	 */
	public static function resolve( $host ) {
		$host = trim( (string) $host, " \t\n\r\0\x0B[]" );
		if ( '' === $host ) {
			return array();
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		return array_values( (array) gethostbynamel( $host ) );
	}

	/**
	 * Whether an IP address is on the public internet.
	 *
	 * Rejects private, loopback, link-local (including cloud metadata), reserved
	 * and carrier-grade NAT (100.64.0.0/10) ranges.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			if ( ( $long & 0xFFC00000 ) === ( ip2long( '100.64.0.0' ) & 0xFFC00000 ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve a host and require every address to be public.
	 *
	 * Connecting to the returned address (instead of the name) closes the window
	 * in which DNS could change between this check and the connection.
	 *
	 * @param string $host Host name or IP.
	 * @return string[]|\WP_Error Public addresses, or an error naming the problem.
	 */
	public static function public_ips( $host ) {
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return new \WP_Error( 'tbsh_cal_unresolved', __( 'The host name could not be resolved.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'tbsh_cal_private_host', __( 'The host resolves to a private or reserved address, which is not allowed here.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
			}
		}
		return $ips;
	}
}
