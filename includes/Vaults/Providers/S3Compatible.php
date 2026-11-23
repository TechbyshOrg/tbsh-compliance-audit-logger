<?php
namespace TBSHComplianceAuditLogger\Vaults\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S3-compatible object storage with AWS Signature Version 4.
 *
 * Works with Amazon S3, Google Cloud Storage (interoperability HMAC keys),
 * Cloudflare R2, Wasabi, Backblaze B2 (S3 API), DigitalOcean Spaces and MinIO.
 * Files larger than PART_SIZE are sent with multipart upload.
 */
class S3Compatible implements ProviderInterface {

	const PART_SIZE = 8388608; // 8 MiB.

	/**
	 * Config.
	 *
	 * @var array
	 */
	private $c;

	/**
	 * Constructor.
	 *
	 * @param array $config endpoint, region, bucket, prefix, access_key, secret_key, path_style.
	 */
	public function __construct( array $config ) {
		$region   = '' !== trim( (string) ( $config['region'] ?? '' ) ) ? trim( $config['region'] ) : 'us-east-1';
		$endpoint = rtrim( trim( (string) ( $config['endpoint'] ?? '' ) ), '/' );
		$custom   = '' !== $endpoint;
		if ( ! $custom ) {
			$endpoint = 'https://s3.' . $region . '.amazonaws.com';
		}
		if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$endpoint = 'https://' . $endpoint;
		}

		$this->c = array(
			'endpoint'   => $endpoint,
			'region'     => $region,
			'bucket'     => trim( (string) ( $config['bucket'] ?? '' ) ),
			'prefix'     => trim( (string) ( $config['prefix'] ?? '' ), '/' ),
			'access_key' => (string) ( $config['access_key'] ?? '' ),
			'secret_key' => (string) ( $config['secret_key'] ?? '' ),
			'path_style' => isset( $config['path_style'] ) && '' !== $config['path_style'] ? (bool) $config['path_style'] : $custom,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function test() {
		$key = $this->key( '.tbsh-cal-probe-' . wp_generate_password( 10, false, false ) . '.txt' );
		$put = $this->request( 'PUT', $key, array(), 'probe', array( 'content-type' => 'text/plain' ) );
		if ( ! $put['ok'] ) {
			return $put;
		}
		$this->request( 'DELETE', $key );
		return array( 'ok' => true, 'message' => __( 'Connected: a probe object was written and removed.', 'tbsh-compliance-audit-logger' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function upload( $local_path, $remote_name ) {
		$key  = $this->key( $remote_name );
		$size = (int) filesize( $local_path );

		if ( $size <= self::PART_SIZE ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$res = $this->request( 'PUT', $key, array(), (string) file_get_contents( $local_path ), array( 'content-type' => 'application/octet-stream' ) );
			return $res['ok'] ? array( 'ok' => true, 'message' => $res['message'], 'remote_path' => $this->display_path( $key ) ) : $res;
		}

		// Multipart upload.
		$init = $this->request( 'POST', $key, array( 'uploads' => '' ), '', array( 'content-type' => 'application/octet-stream' ) );
		if ( ! $init['ok'] || ! preg_match( '#<UploadId>([^<]+)</UploadId>#', $init['body'], $m ) ) {
			return array( 'ok' => false, 'message' => $init['ok'] ? __( 'The storage service did not return an upload ID.', 'tbsh-compliance-audit-logger' ) : $init['message'] );
		}
		$upload_id = html_entity_decode( $m[1], ENT_XML1 );
		$etags     = array();
		$fh        = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$part      = 1;

		while ( ! feof( $fh ) ) {
			$chunk = (string) fread( $fh, self::PART_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( '' === $chunk ) {
				break;
			}
			$res = $this->request( 'PUT', $key, array( 'partNumber' => (string) $part, 'uploadId' => $upload_id ), $chunk );
			if ( ! $res['ok'] || empty( $res['etag'] ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
				/* translators: 1: part number, 2: error */
				return array( 'ok' => false, 'message' => sprintf( __( 'Upload of part %1$d failed: %2$s', 'tbsh-compliance-audit-logger' ), $part, $res['message'] ) );
			}
			$etags[ $part ] = $res['etag'];
			++$part;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$xml = '<CompleteMultipartUpload>';
		foreach ( $etags as $n => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $n . '</PartNumber><ETag>' . esc_xml( $etag ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$done = $this->request( 'POST', $key, array( 'uploadId' => $upload_id ), $xml, array( 'content-type' => 'application/xml' ) );
		// S3 can return 200 with an <Error> body for CompleteMultipartUpload.
		if ( ! $done['ok'] || false !== strpos( $done['body'], '<Error>' ) ) {
			$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
			return array( 'ok' => false, 'message' => $done['ok'] ? self::error_message( $done['body'] ) : $done['message'] );
		}

		return array( 'ok' => true, 'message' => __( 'Uploaded (multipart).', 'tbsh-compliance-audit-logger' ), 'remote_path' => $this->display_path( $key ) );
	}

	/**
	 * Object key with prefix.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function key( $name ) {
		return ( '' !== $this->c['prefix'] ? $this->c['prefix'] . '/' : '' ) . ltrim( $name, '/' );
	}

	/**
	 * Human-readable location.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function display_path( $key ) {
		return 's3://' . $this->c['bucket'] . '/' . $key;
	}

	/**
	 * Signed request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $key     Object key.
	 * @param array  $query   Query args.
	 * @param string $body    Body.
	 * @param array  $headers Extra headers (lowercase names).
	 * @return array{ok: bool, code: int, message: string, body: string, etag: string}
	 */
	private function request( $method, $key, array $query = array(), $body = '', array $headers = array() ) {
		if ( '' === $this->c['bucket'] || '' === $this->c['access_key'] || '' === $this->c['secret_key'] ) {
			return array( 'ok' => false, 'code' => 0, 'message' => __( 'Bucket, access key and secret key are required.', 'tbsh-compliance-audit-logger' ), 'body' => '', 'etag' => '' );
		}

		$parts  = wp_parse_url( $this->c['endpoint'] );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		if ( ! empty( $parts['port'] ) ) {
			$host .= ':' . $parts['port'];
		}
		$base_path = rtrim( $parts['path'] ?? '', '/' );

		$encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		if ( $this->c['path_style'] ) {
			$uri = $base_path . '/' . rawurlencode( $this->c['bucket'] ) . '/' . $encoded_key;
		} else {
			$host = $this->c['bucket'] . '.' . $host;
			$uri  = $base_path . '/' . $encoded_key;
		}

		ksort( $query );
		$canonical_query = array();
		foreach ( $query as $k => $v ) {
			$canonical_query[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
		}
		$canonical_query = implode( '&', $canonical_query );

		/**
		 * Signing time (filterable for deterministic tests).
		 *
		 * @param int $timestamp Unix timestamp.
		 */
		$amz_date     = gmdate( 'Ymd\THis\Z', (int) apply_filters( 'tbsh_cal_s3_signing_time', time() ) );
		$date         = substr( $amz_date, 0, 8 );
		$payload_hash = hash( 'sha256', $body );

		$headers = array_change_key_case( $headers, CASE_LOWER ) + array(
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
		);
		ksort( $headers );
		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( (string) $value ) . "\n";
		}
		$signed_headers = implode( ';', array_keys( $headers ) );

		$canonical_request = implode( "\n", array( $method, $uri, $canonical_query, $canonical_headers, $signed_headers, $payload_hash ) );
		$scope             = $date . '/' . $this->c['region'] . '/s3/aws4_request';
		$string_to_sign    = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $this->c['secret_key'], true );
		$k_region  = hash_hmac( 'sha256', $this->c['region'], $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->c['access_key'] . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
		unset( $headers['host'] );

		$url  = $scheme . '://' . $host . $uri . ( '' !== $canonical_query ? '?' . $canonical_query : '' );
		$args = array(
			'method'      => $method,
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
		);

		/**
		 * Allow vault endpoints on private networks (e.g. a self-hosted MinIO).
		 *
		 * @param bool $allow Default false.
		 */
		$response = apply_filters( 'tbsh_cal_allow_private_vault_endpoints', false ) ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'message' => $response->get_error_message(), 'body' => '', 'etag' => '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$resp = (string) wp_remote_retrieve_body( $response );
		$ok   = $code >= 200 && $code < 300;

		return array(
			'ok'      => $ok,
			'code'    => $code,
			'message' => $ok ? 'HTTP ' . $code : 'HTTP ' . $code . ': ' . self::error_message( $resp ),
			'body'    => $resp,
			'etag'    => trim( (string) wp_remote_retrieve_header( $response, 'etag' ) ),
		);
	}

	/**
	 * Extract an S3 XML error message.
	 *
	 * @param string $xml Body.
	 * @return string
	 */
	private static function error_message( $xml ) {
		if ( preg_match( '#<Code>([^<]+)</Code>.*?<Message>([^<]*)</Message>#s', (string) $xml, $m ) ) {
			return $m[1] . ' — ' . $m[2];
		}
		return mb_substr( wp_strip_all_tags( (string) $xml ), 0, 200 );
	}
}
