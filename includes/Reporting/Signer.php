<?php
namespace TBSHComplianceAuditLogger\Reporting;

use TBSHComplianceAuditLogger\Helpers\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report signing: SHA-256 content hash plus an HMAC-SHA256 over that hash using a
 * site-secret key. Verifying recomputes both from the stored file, so any change to
 * the file (or a signature produced without this site's key) fails verification.
 */
class Signer {

	/**
	 * Sign file contents.
	 *
	 * @param string $contents File contents.
	 * @return array{content_hash: string, signature: string}
	 */
	public static function sign( $contents ) {
		$hash = hash( 'sha256', (string) $contents );
		return array(
			'content_hash' => $hash,
			'signature'    => Crypto::hmac( $hash, 'report-signing' ),
		);
	}

	/**
	 * Verify file contents against a stored hash and signature.
	 *
	 * @param string $contents     File contents.
	 * @param string $content_hash Stored hash.
	 * @param string $signature    Stored signature.
	 * @return array{valid: bool, hash_matches: bool, signature_valid: bool}
	 */
	public static function verify( $contents, $content_hash, $signature ) {
		$hash         = hash( 'sha256', (string) $contents );
		$hash_matches = hash_equals( (string) $content_hash, $hash );
		$sig_valid    = Crypto::verify_hmac( $hash, (string) $signature, 'report-signing' );
		return array(
			'valid'           => $hash_matches && $sig_valid,
			'hash_matches'    => $hash_matches,
			'signature_valid' => $sig_valid,
		);
	}
}
