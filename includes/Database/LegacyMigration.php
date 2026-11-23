<?php
namespace TBSHComplianceAuditLogger\Database;

use TBSHComplianceAuditLogger\Helpers\Crypto;
use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Helpers\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration of settings and vault credentials written by the discontinued separate
 * edition (option `tbsh_cal_pro_settings`, AES-256-CTR encrypted vault credentials).
 */
class LegacyMigration {

	const LEGACY_OPTION = 'tbsh_cal_pro_settings';

	/**
	 * Re-encrypt legacy vault credentials and map provider types.
	 *
	 * @return array Summary.
	 */
	public static function vaults() {
		global $wpdb;
		$table   = Schema::table( 'vaults' );
		$legacy  = get_option( self::LEGACY_OPTION, array() );
		$fallback_pass = is_array( $legacy ) ? (string) ( $legacy['encryption_password'] ?? '' ) : '';
		$summary = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE vault_type IN ('s3','gcs','azure','sftp','ftp','remote_wp')", $table ) );

		foreach ( (array) $rows as $row ) {
			$old   = self::decrypt( (string) $row->credentials_encrypted );
			$old   = is_array( $old ) ? $old : array();
			$pass  = (string) ( $old['encryption_password'] ?? '' );
			$pass  = '' !== $pass ? $pass : $fallback_pass;
			$type  = $row->vault_type;
			$new   = array();
			$error = null;
			$keep_active = (bool) $row->is_active;

			switch ( $type ) {
				case 's3':
					$type = 's3_compatible';
					$new  = array(
						'region'     => (string) ( $old['region'] ?? '' ),
						'bucket'     => (string) ( $old['bucket'] ?? '' ),
						'access_key' => (string) ( $old['key'] ?? '' ),
						'secret_key' => (string) ( $old['secret'] ?? '' ),
					);
					break;
				case 'gcs':
					$type        = 's3_compatible';
					$new         = array( 'endpoint' => 'https://storage.googleapis.com', 'region' => 'auto', 'bucket' => (string) ( $old['bucket'] ?? '' ), 'path_style' => '1' );
					$error       = __( 'Google Cloud Storage now uses the S3-compatible API. Create an HMAC key (Cloud Storage → Settings → Interoperability) and enter its access key and secret.', 'tbsh-compliance-audit-logger' );
					$keep_active = false;
					break;
				case 'ftp':
					$new = array(
						'host'     => (string) ( $old['host'] ?? '' ),
						'port'     => (string) ( $old['port'] ?? '21' ),
						'username' => (string) ( $old['username'] ?? '' ),
						'password' => (string) ( $old['password'] ?? '' ),
						'mode'     => 'ftp',
					);
					break;
				case 'remote_wp':
					$type        = 'remote_site';
					$new         = array( 'url' => (string) ( $old['url'] ?? '' ), 'token' => (string) ( $old['token'] ?? '' ) );
					$error       = __( 'Enter the receiver URL shown in the other site’s Settings (Vault receiver) and its token, then test the connection.', 'tbsh-compliance-audit-logger' );
					$keep_active = false;
					break;
				default: // azure, sftp.
					$error       = __( 'This storage provider is no longer supported. Configure an S3-compatible, FTP/FTPS or remote-site vault instead.', 'tbsh-compliance-audit-logger' );
					$keep_active = false;
			}

			if ( '' !== $pass ) {
				$new['passphrase'] = $pass;
			} elseif ( $keep_active ) {
				$error       = __( 'Set an encryption passphrase for this vault, then re-activate it.', 'tbsh-compliance-audit-logger' );
				$keep_active = false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'vault_type'            => $type,
					'label'                 => $row->label ? $row->label : ucfirst( str_replace( '_', ' ', $row->vault_type ) ) . ' (migrated)',
					'credentials_encrypted' => Crypto::encrypt( wp_json_encode( array_filter( $new, 'strlen' ) ), 'vault' ),
					'is_active'             => $keep_active ? 1 : 0,
					'status'                => 'unknown',
					'last_error'            => $error,
					'updated_at'            => Time::now(),
				),
				array( 'id' => (int) $row->id )
			);
			$summary[] = $row->vault_type . '>' . $type . ( $keep_active ? '' : ' (inactive)' );
		}

		return $summary;
	}

	/**
	 * Map legacy settings into the unified settings and encrypted secrets, then delete
	 * the legacy option (it holds plaintext secrets).
	 *
	 * @return array Summary.
	 */
	public static function settings() {
		$legacy = get_option( self::LEGACY_OPTION, null );
		if ( ! is_array( $legacy ) ) {
			if ( null !== $legacy ) {
				delete_option( self::LEGACY_OPTION );
			}
			return array();
		}

		$settings = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), \TBSHComplianceAuditLogger\API\SettingsController::defaults() );
		$master   = ! empty( $legacy['enable_alerts'] );

		// Email: either edition's switch enables it.
		if ( $master && ! empty( $legacy['alert_email_enabled'] ) ) {
			$settings['email_alerts'] = true;
		}
		if ( ! empty( $legacy['alert_emails'] ) && '' === $settings['alert_emails'] ) {
			$emails                   = array_filter( array_map( 'trim', explode( ',', (string) $legacy['alert_emails'] ) ), 'is_email' );
			$settings['alert_emails'] = implode( ', ', $emails );
		}
		foreach ( array( 'slack', 'discord', 'teams', 'telegram', 'webhook' ) as $channel ) {
			$settings[ $channel . '_enabled' ] = $master && ! empty( $legacy[ $channel . '_enabled' ] );
		}
		if ( ! empty( $legacy['telegram_chat_id'] ) ) {
			$settings['telegram_chat_id'] = preg_replace( '/[^0-9@A-Za-z_\-]/', '', (string) $legacy['telegram_chat_id'] );
		}

		// AI.
		$provider_map           = array( 'openai' => 'openai', 'claude' => 'anthropic', 'gemini' => 'gemini', 'local' => 'ollama' );
		$settings['ai_provider'] = $provider_map[ $legacy['ai_provider'] ?? '' ] ?? 'none';
		// The old edition's built-in defaults are retired models; let the provider default apply instead.
		$retired_defaults = array( 'gpt-4o-mini', 'claude-3-5-sonnet-20241022', 'gemini-1.5-flash', 'llama3' );
		if ( ! empty( $legacy['ai_model'] ) && ! in_array( $legacy['ai_model'], $retired_defaults, true ) ) {
			$settings['ai_model'] = preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) $legacy['ai_model'] );
		}

		// Retention: keep the stricter non-zero value.
		$pick = function ( $a, $b ) {
			$a = (int) $a;
			$b = (int) $b;
			if ( $a > 0 && $b > 0 ) {
				return min( $a, $b );
			}
			return max( $a, $b );
		};
		$settings['retain_logs']      = $pick( $settings['retain_logs'], $legacy['retention_logs'] ?? 0 );
		$settings['retain_incidents'] = $pick( $settings['retain_incidents'], $legacy['retention_incidents'] ?? 0 );
		$settings['retain_evidence']  = $pick( $settings['retain_evidence'], $legacy['retention_evidence'] ?? 0 );

		update_option( 'tbsh_cal_settings', $settings );

		// Secrets.
		$secrets = array();
		foreach ( array( 'slack_webhook', 'discord_webhook', 'teams_webhook', 'webhook_url', 'telegram_bot_token' ) as $key ) {
			if ( ! empty( $legacy[ $key ] ) && is_string( $legacy[ $key ] ) ) {
				$secrets[ $key ] = $legacy[ $key ];
			}
		}
		if ( ! empty( $legacy['ai_api_key'] ) && is_string( $legacy['ai_api_key'] ) ) {
			// The legacy "local" provider stored its endpoint URL in the API-key field.
			$secrets[ 'local' === ( $legacy['ai_provider'] ?? '' ) ? 'ai_endpoint' : 'ai_api_key' ] = $legacy['ai_api_key'];
		}
		if ( $secrets ) {
			Secrets::set_many( $secrets );
		}

		delete_option( self::LEGACY_OPTION );

		return array(
			'settings_mapped' => true,
			'secrets'         => array_keys( $secrets ),
			'ai_provider'     => $settings['ai_provider'],
		);
	}

	/**
	 * Decrypt a legacy credential blob (AES-256-CTR, key chain of the old edition).
	 *
	 * @param string $blob Base64 IV + ciphertext.
	 * @return array|null
	 */
	private static function decrypt( $blob ) {
		if ( '' === $blob || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$data = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $data || strlen( $data ) <= 16 ) {
			return null;
		}

		$candidates = array();
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$candidates[] = hash( 'sha256', SECURE_AUTH_KEY );
		}
		$salt         = get_option( 'tbsh_cal_privacy_salt' );
		$candidates[] = hash( 'sha256', $salt ? $salt : 'fallback_secure_salt_tbsh_cal_pro_key' );

		foreach ( $candidates as $key ) {
			$plain = openssl_decrypt( substr( $data, 16 ), 'aes-256-ctr', $key, 0, substr( $data, 0, 16 ) );
			if ( false === $plain ) {
				continue;
			}
			$decoded = json_decode( $plain, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}
}
