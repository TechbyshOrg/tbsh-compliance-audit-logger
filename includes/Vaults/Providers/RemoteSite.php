<?php
namespace TBSHComplianceAuditLogger\Vaults\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Push packages to another WordPress site running this plugin (its vault receiver).
 *
 * Requests are authenticated with HMAC-SHA256 over "timestamp\nfilename\nsha256(body)"
 * using the receiver's token; the receiver rejects stale or replayed requests.
 */
class RemoteSite implements ProviderInterface {

	/**
	 * Config.
	 *
	 * @var array
	 */
	private $c;

	/**
	 * Constructor.
	 *
	 * @param array $config url, token.
	 */
	public function __construct( array $config ) {
		$this->c = array(
			'url'   => esc_url_raw( trim( (string) ( $config['url'] ?? '' ) ) ),
			'token' => (string) ( $config['token'] ?? '' ),
		);
	}

	/**
	 * Signature helper shared with the receiver.
	 *
	 * @param string $timestamp Unix timestamp.
	 * @param string $filename  File name.
	 * @param string $sha       Body sha256 (hex).
	 * @param string $token     Shared token.
	 * @return string
	 */
	public static function signature( $timestamp, $filename, $sha, $token ) {
		return hash_hmac( 'sha256', $timestamp . "\n" . $filename . "\n" . $sha, $token );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$res = $this->send( '', 'probe.tbshvault', true );
		return $res['ok'] ? array( 'ok' => true, 'message' => __( 'The receiving site accepted the signed test request.', 'tbsh-compliance-audit-logger' ) ) : $res;
	}

	/**
	 * {@inheritDoc}
	 */
	public function upload( $local_path, $remote_name ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$res = $this->send( (string) file_get_contents( $local_path ), $remote_name, false );
		if ( $res['ok'] ) {
			$res['remote_path'] = (string) wp_parse_url( $this->c['url'], PHP_URL_HOST ) . ':' . $remote_name;
		}
		return $res;
	}

	/**
	 * Signed POST.
	 *
	 * @param string $body     Raw body.
	 * @param string $filename File name.
	 * @param bool   $is_test  Probe only.
	 * @return array
	 */
	private function send( $body, $filename, $is_test ) {
		if ( ! wp_http_validate_url( $this->c['url'] ) || 0 !== strpos( $this->c['url'], 'https://' ) || '' === $this->c['token'] ) {
			return array( 'ok' => false, 'message' => __( 'A valid https receiver URL and token are required.', 'tbsh-compliance-audit-logger' ) );
		}

		$timestamp = (string) time();
		$sha       = hash( 'sha256', $body );
		$headers   = array(
			'Content-Type'          => 'application/octet-stream',
			'X-TBSH-Timestamp'      => $timestamp,
			'X-TBSH-Filename'       => $filename,
			'X-TBSH-Content-SHA256' => $sha,
			'X-TBSH-Signature'      => self::signature( $timestamp, $filename, $sha, $this->c['token'] ),
			'X-TBSH-Source'         => home_url( '/' ),
		);
		if ( $is_test ) {
			$headers['X-TBSH-Test'] = '1';
		}

		$response = wp_safe_remote_post( $this->c['url'], array(
			'timeout'     => 120,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['received'] );

		return array(
			'ok'      => $ok,
			'message' => $ok ? __( 'Delivered to the receiving site.', 'tbsh-compliance-audit-logger' ) : 'HTTP ' . $code . ( is_array( $data ) && isset( $data['message'] ) ? ': ' . wp_strip_all_tags( (string) $data['message'] ) : '' ),
		);
	}
}
