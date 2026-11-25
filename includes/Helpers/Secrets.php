<?php
namespace TBSHComplianceAuditLogger\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypted store for credentials (API keys, webhook URLs, tokens).
 *
 * Stored in the non-autoloaded `tbsh_cal_secrets` option; every value is encrypted
 * with Crypto. Secrets are never sent to the browser in clear — use masked().
 */
class Secrets {

	/**
	 * Option name.
	 */
	const OPTION = 'tbsh_cal_secrets';

	/**
	 * Allowed secret keys.
	 */
	const KEYS = array(
		'ai_api_key',
		'ai_endpoint',
		'slack_webhook',
		'discord_webhook',
		'teams_webhook',
		'webhook_url',
		'webhook_signing_secret',
		'telegram_bot_token',
		'vault_receiver_token',
		'siem_url',
		'siem_token',
	);

	/**
	 * Request-level cache of decrypted values.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Get a decrypted secret.
	 *
	 * @param string $key Secret key.
	 * @return string Empty string when unset or undecryptable.
	 */
	public static function get( $key ) {
		if ( ! in_array( $key, self::KEYS, true ) ) {
			return '';
		}

		if ( null === self::$cache ) {
			self::$cache = array();
		}

		if ( ! array_key_exists( $key, self::$cache ) ) {
			$stored = self::stored();
			$plain  = isset( $stored[ $key ] ) ? Crypto::decrypt( $stored[ $key ] ) : null;

			self::$cache[ $key ] = null === $plain ? '' : $plain;
		}

		return self::$cache[ $key ];
	}

	/**
	 * Whether a secret is set (and decryptable).
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function has( $key ) {
		return '' !== self::get( $key );
	}

	/**
	 * Whether a secret is stored but can no longer be decrypted
	 * (e.g. the WordPress salts or TBSH_CAL_ENCRYPTION_KEY changed).
	 *
	 * @param string $key Secret key.
	 * @return bool
	 */
	public static function is_unreadable( $key ) {
		$stored = self::stored();
		return isset( $stored[ $key ] ) && null === Crypto::decrypt( $stored[ $key ] );
	}

	/**
	 * Set (or clear with an empty value) one or more secrets.
	 *
	 * @param array $values Key => plaintext. Empty string removes the secret.
	 * @return void
	 */
	public static function set_many( array $values ) {
		$stored = self::stored();

		foreach ( $values as $key => $value ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				continue;
			}
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value ) {
				unset( $stored[ $key ] );
			} else {
				$stored[ $key ] = Crypto::encrypt( $value );
			}
		}

		update_option( self::OPTION, $stored, false );
		self::$cache = null;
	}

	/**
	 * Set a single secret.
	 *
	 * @param string $key   Secret key.
	 * @param string $value Plaintext (empty removes).
	 * @return void
	 */
	public static function set( $key, $value ) {
		self::set_many( array( $key => $value ) );
	}

	/**
	 * Masked representation of every secret, safe to send to the browser.
	 *
	 * @return array Key => array{ set: bool, hint: string, unreadable: bool }.
	 */
	public static function masked() {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$value       = self::get( $key );
			$out[ $key ] = array(
				'set'        => '' !== $value,
				'hint'       => self::mask( $value ),
				'unreadable' => self::is_unreadable( $key ),
			);
		}
		return $out;
	}

	/**
	 * Mask a value, keeping only the last 4 characters of long values.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	public static function mask( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) < 12 ) {
			return '••••';
		}
		return '••••' . substr( $value, -4 );
	}

	/**
	 * Delete all secrets.
	 *
	 * @return void
	 */
	public static function delete_all() {
		delete_option( self::OPTION );
		self::$cache = null;
	}

	/**
	 * Raw stored (encrypted) values.
	 *
	 * @return array
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
