<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated symmetric encryption for secrets stored in the database.
 *
 * Uses libsodium secretbox (XSalsa20-Poly1305). WordPress core loads the
 * sodium_compat polyfill when the extension is unavailable (wp-includes/compat.php).
 *
 * Key material: the TBSH_CAL_ENCRYPTION_KEY constant when defined (recommended, so
 * rotating WordPress salts does not invalidate stored secrets), otherwise the
 * SECURE_AUTH salt. Per-purpose keys are derived with HKDF-SHA256.
 */
class Crypto {

	/**
	 * Ciphertext prefix (format version marker).
	 */
	const PREFIX = 'tbshc1:';

	/**
	 * Encrypt a string.
	 *
	 * @param string $plaintext Plain value.
	 * @param string $context   Key-derivation context.
	 * @return string Prefixed, base64-encoded nonce + ciphertext.
	 */
	public static function encrypt( $plaintext, $context = 'secrets' ) {
		$key   = self::key( $context );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );

		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $value   Encrypted value.
	 * @param string $context Key-derivation context.
	 * @return string|null Plaintext, or null when the value is not decryptable
	 *                     (wrong key, tampered, or not our format).
	 */
	public static function decrypt( $value, $context = 'secrets' ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, self::PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key( $context ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Whether a value looks like our ciphertext format.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Keyed HMAC-SHA256 (hex) — e.g. for signing generated reports.
	 *
	 * @param string $data    Data to sign.
	 * @param string $context Key-derivation context.
	 * @return string
	 */
	public static function hmac( $data, $context = 'signing' ) {
		return hash_hmac( 'sha256', (string) $data, self::key( $context ) );
	}

	/**
	 * Constant-time HMAC verification.
	 *
	 * @param string $data      Data.
	 * @param string $signature Expected hex signature.
	 * @param string $context   Key-derivation context.
	 * @return bool
	 */
	public static function verify_hmac( $data, $signature, $context = 'signing' ) {
		return is_string( $signature ) && hash_equals( self::hmac( $data, $context ), $signature );
	}

	/**
	 * Whether a dedicated encryption key constant is configured.
	 *
	 * @return bool
	 */
	public static function has_dedicated_key() {
		return defined( 'TBSH_CAL_ENCRYPTION_KEY' ) && is_string( TBSH_CAL_ENCRYPTION_KEY ) && strlen( TBSH_CAL_ENCRYPTION_KEY ) >= 32;
	}

	/**
	 * Derive a 32-byte key for a purpose.
	 *
	 * @param string $context Purpose label.
	 * @return string Raw binary key.
	 */
	private static function key( $context ) {
		$ikm = self::has_dedicated_key() ? TBSH_CAL_ENCRYPTION_KEY : wp_salt( 'secure_auth' );

		return hash_hkdf( 'sha256', $ikm, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'tbsh-cal:' . $context );
	}
}
