<?php
namespace TBSHComplianceAuditLogger\Vaults\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FTP / FTPS (explicit TLS) uploads via PHP's ftp extension.
 */
class Ftp implements ProviderInterface {

	/**
	 * Config.
	 *
	 * @var array
	 */
	private $c;

	/**
	 * Constructor.
	 *
	 * @param array $config host, port, username, password, path, mode (ftps|ftp), passive.
	 */
	public function __construct( array $config ) {
		$this->c = array(
			'host'     => trim( (string) ( $config['host'] ?? '' ) ),
			'port'     => (int) ( $config['port'] ?? 0 ) > 0 ? (int) $config['port'] : 21,
			'username' => (string) ( $config['username'] ?? '' ),
			'password' => (string) ( $config['password'] ?? '' ),
			'path'     => '/' . trim( (string) ( $config['path'] ?? '' ), '/' ),
			'mode'     => 'ftp' === ( $config['mode'] ?? 'ftps' ) ? 'ftp' : 'ftps',
			'passive'  => ! isset( $config['passive'] ) || '' === $config['passive'] || (bool) $config['passive'],
		);
	}

	/**
	 * Whether plain (unencrypted) FTP may be used.
	 *
	 * Off by default: it sends the FTP password in clear text. FTPS also protects
	 * the login, although PHP's FTP extension cannot verify the server certificate.
	 *
	 * @return bool
	 */
	public static function plain_allowed() {
		/**
		 * Allow vaults over plain, unencrypted FTP (for example on a closed network).
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'tbsh_cal_allow_plain_ftp', false );
	}

	/**
	 * Message shown when a vault still uses plain FTP.
	 *
	 * @return string
	 */
	public static function plain_refused_message() {
		return __( 'Plain FTP sends your FTP password unencrypted and is no longer supported. Edit this vault and choose FTPS.', 'tbsh-compliance-audit-logger' );
	}

	/**
	 * Whether the server can use this provider.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'ftp_connect' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$name  = '.tbsh-cal-probe-' . wp_generate_password( 10, false, false ) . '.txt';
		$local = wp_tempnam( 'tbsh-cal-probe' );
		file_put_contents( $local, 'probe' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$res = $this->with_connection( function ( $conn ) use ( $local, $name ) {
			if ( ! @ftp_put( $conn, $name, $local, FTP_BINARY ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array( 'ok' => false, 'message' => __( 'Connected, but writing a test file was refused.', 'tbsh-compliance-audit-logger' ) );
			}
			@ftp_delete( $conn, $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return array( 'ok' => true, 'message' => __( 'Connected: a probe file was written and removed.', 'tbsh-compliance-audit-logger' ) );
		} );
		wp_delete_file( $local );
		return $res;
	}

	/**
	 * {@inheritDoc}
	 */
	public function upload( $local_path, $remote_name ) {
		return $this->with_connection( function ( $conn ) use ( $local_path, $remote_name ) {
			if ( ! @ftp_put( $conn, $remote_name, $local_path, FTP_BINARY ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array( 'ok' => false, 'message' => __( 'The server refused the upload.', 'tbsh-compliance-audit-logger' ) );
			}
			$remote_size = @ftp_size( $conn, $remote_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $remote_size >= 0 && (int) $remote_size !== (int) filesize( $local_path ) ) {
				return array( 'ok' => false, 'message' => __( 'The uploaded file size does not match.', 'tbsh-compliance-audit-logger' ) );
			}
			return array( 'ok' => true, 'message' => __( 'Uploaded.', 'tbsh-compliance-audit-logger' ), 'remote_path' => $this->c['mode'] . '://' . $this->c['host'] . rtrim( $this->c['path'], '/' ) . '/' . $remote_name );
		} );
	}

	/**
	 * Connect, log in, change into the target directory, run a callback, close.
	 *
	 * @param callable $callback Receives the connection.
	 * @return array
	 */
	private function with_connection( callable $callback ) {
		if ( 'ftp' === $this->c['mode'] && ! self::plain_allowed() ) {
			return array( 'ok' => false, 'message' => self::plain_refused_message() );
		}
		if ( ! self::available() ) {
			return array( 'ok' => false, 'message' => __( 'The PHP FTP extension is not installed on this server, so FTP vaults are unavailable.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( '' === $this->c['host'] || '' === $this->c['username'] ) {
			return array( 'ok' => false, 'message' => __( 'Host and username are required.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( 'ftps' === $this->c['mode'] && ! function_exists( 'ftp_ssl_connect' ) ) {
			return array( 'ok' => false, 'message' => __( 'FTPS is not supported by this PHP build.', 'tbsh-compliance-audit-logger' ) );
		}

		// On multisite, sub-site administrators must not reach the internal network.
		$target = $this->c['host'];
		if ( is_multisite() && ! apply_filters( 'tbsh_cal_allow_private_vault_endpoints', false ) ) {
			$ips = \TBSHComplianceAuditLogger\Helpers\Network::public_ips( $this->c['host'] );
			if ( is_wp_error( $ips ) ) {
				return array( 'ok' => false, 'message' => $ips->get_error_message() );
			}
			$target = $ips[0]; // Connect to the checked address, not a name that could change.
		}

		$conn = 'ftps' === $this->c['mode']
			? @ftp_ssl_connect( $target, $this->c['port'], 20 ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			: @ftp_connect( $target, $this->c['port'], 20 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $conn ) {
			return array( 'ok' => false, 'message' => __( 'Could not connect to the FTP server.', 'tbsh-compliance-audit-logger' ) );
		}

		try {
			if ( ! @ftp_login( $conn, $this->c['username'], $this->c['password'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return array( 'ok' => false, 'message' => __( 'FTP login failed.', 'tbsh-compliance-audit-logger' ) );
			}
			ftp_pasv( $conn, $this->c['passive'] );

			if ( '/' !== $this->c['path'] && ! @ftp_chdir( $conn, $this->c['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$current = '';
				foreach ( array_filter( explode( '/', $this->c['path'] ) ) as $segment ) {
					$current .= '/' . $segment;
					if ( ! @ftp_chdir( $conn, $current ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@ftp_mkdir( $conn, $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( ! @ftp_chdir( $conn, $current ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							/* translators: %s: path */
							return array( 'ok' => false, 'message' => sprintf( __( 'Cannot create or open the remote folder %s.', 'tbsh-compliance-audit-logger' ), $current ) );
						}
					}
				}
			}

			return $callback( $conn );
		} finally {
			@ftp_close( $conn ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
