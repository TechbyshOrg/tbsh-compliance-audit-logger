<?php
namespace TBSHComplianceAuditLogger\Vaults;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\CronScheduler;
use TBSHComplianceAuditLogger\Helpers\Crypto;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Secrets;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Vaults\Providers\Ftp;
use TBSHComplianceAuditLogger\Vaults\Providers\RemoteSite;
use TBSHComplianceAuditLogger\Vaults\Providers\S3Compatible;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off-site evidence vaults: configuration, connection tests, sync and schedule.
 */
class VaultManager {

	const SCHEDULES = array( 'hourly' => HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );

	/**
	 * Provider field definitions: field => [ secret, required ].
	 */
	const FIELDS = array(
		's3_compatible' => array(
			'endpoint'   => array( false, false ),
			'region'     => array( false, false ),
			'bucket'     => array( false, true ),
			'prefix'     => array( false, false ),
			'path_style' => array( false, false ),
			'access_key' => array( true, true ),
			'secret_key' => array( true, true ),
			'passphrase' => array( true, true ),
		),
		'ftp'           => array(
			'host'       => array( false, true ),
			'port'       => array( false, false ),
			'mode'       => array( false, false ),
			'passive'    => array( false, false ),
			'path'       => array( false, false ),
			'username'   => array( false, true ),
			'password'   => array( true, true ),
			'passphrase' => array( true, true ),
		),
		'remote_site'   => array(
			'url'        => array( false, true ),
			'token'      => array( true, true ),
			'passphrase' => array( true, true ),
		),
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( CronScheduler::VAULT_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Provider labels.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			's3_compatible' => array( 'label' => __( 'S3-compatible storage (AWS S3, Google Cloud Storage, Cloudflare R2, Wasabi, Backblaze B2, MinIO)', 'tbsh-compliance-audit-logger' ), 'available' => true ),
			'ftp'           => array( 'label' => __( 'FTP / FTPS server', 'tbsh-compliance-audit-logger' ), 'available' => Ftp::available() ),
			'remote_site'   => array( 'label' => __( 'Another WordPress site running this plugin', 'tbsh-compliance-audit-logger' ), 'available' => true ),
		);
	}

	/**
	 * All vaults (no secrets).
	 *
	 * @return array
	 */
	public static function list() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Schema::table( 'vaults' ) ) );
		return array(
			'items'    => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'types'    => self::types(),
			'receiver' => array(
				'enabled' => ! empty( get_option( 'tbsh_cal_settings', array() )['vault_receiver_enabled'] ) && Secrets::has( 'vault_receiver_token' ),
				'url'     => rest_url( 'tbsh-compliance-audit-logger/v1/vault-receiver' ),
			),
		);
	}

	/**
	 * Single vault (no secrets).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Create or update a vault. Secret fields are write-only: empty input keeps the
	 * stored value; list a field in `clear` to remove it.
	 *
	 * @param array    $data Input: vault_type, label, is_active, schedule, config{}, clear[].
	 * @param int|null $id   Existing vault ID.
	 * @return array|\WP_Error
	 */
	public static function save( array $data, $id = null ) {
		global $wpdb;
		$row = $id ? self::row( $id ) : null;
		if ( $id && ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vault not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$type = $row ? $row->vault_type : (string) ( $data['vault_type'] ?? '' );
		if ( ! isset( self::FIELDS[ $type ] ) ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'Choose a supported storage provider.', 'tbsh-compliance-audit-logger' ) );
		}

		$creds  = $row ? ( self::credentials( $row ) ?? array() ) : array();
		$input  = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();
		$clear  = isset( $data['clear'] ) && is_array( $data['clear'] ) ? $data['clear'] : array();

		foreach ( self::FIELDS[ $type ] as $field => list( $secret, $required ) ) {
			if ( in_array( $field, $clear, true ) ) {
				unset( $creds[ $field ] );
				continue;
			}
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			if ( in_array( $field, array( 'passive', 'path_style' ), true ) ) {
				// Explicit flags: '1' or '0' (empty would mean "provider default").
				$creds[ $field ] = rest_sanitize_boolean( $input[ $field ] ) ? '1' : '0';
				continue;
			}
			$value = trim( (string) $input[ $field ] );
			if ( $secret && '' === $value ) {
				continue; // Keep existing secret.
			}
			$creds[ $field ] = 'url' === $field || 'endpoint' === $field ? esc_url_raw( $value ) : sanitize_text_field( $value );
		}

		$is_active = ! empty( $data['is_active'] );
		if ( $is_active ) {
			foreach ( self::FIELDS[ $type ] as $field => list( $secret, $required ) ) {
				if ( $required && '' === (string) ( $creds[ $field ] ?? '' ) ) {
					/* translators: %s: field name */
					return new \WP_Error( 'tbsh_cal_invalid', sprintf( __( 'The field "%s" is required to activate this vault.', 'tbsh-compliance-audit-logger' ), ucwords( str_replace( '_', ' ', $field ) ) ) );
				}
			}
			if ( 'remote_site' === $type && 0 !== strpos( (string) ( $creds['url'] ?? '' ), 'https://' ) ) {
				return new \WP_Error( 'tbsh_cal_invalid', __( 'The receiver URL must use https.', 'tbsh-compliance-audit-logger' ) );
			}
		}

		$fields = array(
			'vault_type'            => $type,
			'label'                 => Repository::line( $data['label'] ?? ( $row->label ?? '' ), 191 ),
			'is_active'             => $is_active ? 1 : 0,
			'schedule'              => Repository::enum( $data['schedule'] ?? ( $row->schedule ?? 'daily' ), array_keys( self::SCHEDULES ), 'daily' ),
			'credentials_encrypted' => Crypto::encrypt( wp_json_encode( $creds ), 'vault' ),
			'updated_at'            => Time::now(),
		);

		if ( $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( Schema::table( 'vaults' ), $fields, array( 'id' => (int) $row->id ) );
			$id = (int) $row->id;
		} else {
			$fields['status']     = 'unknown';
			$fields['created_at'] = Time::now();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( Schema::table( 'vaults' ), $fields );
			$id = (int) $wpdb->insert_id;
		}

		Logger::log(
			'vault_configured',
			'Operational Security',
			'notice',
			__( 'Off-site vault configured', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: vault id, 2: type, 3: active state */
			sprintf( __( 'Vault #%1$d (%2$s) saved; active: %3$s.', 'tbsh-compliance-audit-logger' ), $id, $type, $is_active ? 'yes' : 'no' ),
			array( 'object_type' => 'vault', 'object_id' => $id )
		);

		CronScheduler::sync();
		return self::get( $id );
	}

	/**
	 * Delete a vault and its history.
	 *
	 * @param int $id ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vault not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'vaults' ), array( 'id' => (int) $row->id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'vault_logs' ), array( 'vault_id' => (int) $row->id ), array( '%d' ) );
		Logger::log( 'vault_deleted', 'Operational Security', 'warning', __( 'Off-site vault removed', 'tbsh-compliance-audit-logger' ), sprintf( 'Vault #%d (%s)', $row->id, $row->vault_type ), array( 'object_type' => 'vault', 'object_id' => $row->id ) );
		CronScheduler::sync();
		return true;
	}

	/**
	 * Test a vault's connection.
	 *
	 * @param int $id ID.
	 * @return array|\WP_Error
	 */
	public static function test( $id ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vault not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$provider = self::provider( $row );
		if ( is_wp_error( $provider ) ) {
			return array( 'ok' => false, 'message' => $provider->get_error_message() );
		}
		return $provider->test();
	}

	/**
	 * Build a package and upload it.
	 *
	 * @param int $id ID.
	 * @return array|\WP_Error Result { ok, message, size, counts }.
	 */
	public static function sync( $id ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Vault not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$lock = 'tbsh_cal_vault_lock_' . $row->id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'tbsh_cal_busy', __( 'A sync for this vault is already running.', 'tbsh-compliance-audit-logger' ), array( 'status' => 409 ) );
		}
		set_transient( $lock, 1, 30 * MINUTE_IN_SECONDS );

		try {
			$provider = self::provider( $row );
			$creds    = self::credentials( $row ) ?? array();
			$package  = is_wp_error( $provider ) ? $provider : Packager::build( $row, (string) ( $creds['passphrase'] ?? '' ) );

			if ( is_wp_error( $package ) ) {
				$result = array( 'ok' => false, 'message' => $package->get_error_message() );
			} else {
				$result = $provider->upload( $package['path'], $package['name'] );
				wp_delete_file( $package['path'] );
			}

			$now = Time::now();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( Schema::table( 'vault_logs' ), array(
				'vault_id'    => (int) $row->id,
				'status'      => $result['ok'] ? 'success' : 'failed',
				'details'     => mb_substr( (string) $result['message'] . ( ! is_wp_error( $package ) ? ' ' . wp_json_encode( $package['counts'] ) : '' ), 0, 2000 ),
				'remote_path' => mb_substr( (string) ( $result['remote_path'] ?? '' ), 0, 255 ),
				'file_size'   => is_wp_error( $package ) ? 0 : (int) $package['size'],
				'created_at'  => $now,
			) );

			$update = array(
				'status'     => $result['ok'] ? 'healthy' : 'failing',
				'last_error' => $result['ok'] ? null : mb_substr( (string) $result['message'], 0, 1000 ),
				'updated_at' => $now,
			);
			if ( $result['ok'] ) {
				$update['last_sync_at']       = $now;
				$update['last_synced_log_id'] = (int) $package['last_log_id'];
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( Schema::table( 'vaults' ), $update, array( 'id' => (int) $row->id ) );

			if ( $result['ok'] ) {
				Logger::log( 'vault_sync_completed', 'Operational Security', 'info', __( 'Off-site evidence backup completed', 'tbsh-compliance-audit-logger' ), sprintf( 'Vault #%d: %s (%s bytes)', $row->id, $result['remote_path'] ?? '', number_format_i18n( $package['size'] ) ), array( 'object_type' => 'vault', 'object_id' => $row->id, 'metadata' => $package['counts'] ) );
			} else {
				Logger::log( 'vault_sync_failed', 'Operational Security', 'error', __( 'Off-site evidence backup failed', 'tbsh-compliance-audit-logger' ), sprintf( 'Vault #%d: %s', $row->id, $result['message'] ), array( 'object_type' => 'vault', 'object_id' => $row->id ) );
			}

			if ( ! is_wp_error( $package ) ) {
				$result['size']   = $package['size'];
				$result['counts'] = $package['counts'];
			}
			return $result;
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Cron: sync each active vault whose schedule is due.
	 */
	public static function run_scheduled() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, schedule, last_sync_at, updated_at, status FROM %i WHERE is_active = 1', Schema::table( 'vaults' ) ) );
		foreach ( (array) $rows as $row ) {
			$interval = self::SCHEDULES[ $row->schedule ] ?? DAY_IN_SECONDS;
			// Failing vaults retry at most hourly.
			$last = $row->last_sync_at;
			if ( 'failing' === $row->status ) {
				$last     = $row->updated_at;
				$interval = min( $interval, HOUR_IN_SECONDS );
			}
			$age = $last ? time() - (int) Time::to_timestamp( $last ) : PHP_INT_MAX;
			if ( $age >= $interval - 300 ) {
				self::sync( (int) $row->id );
			}
		}
	}

	/**
	 * Sync history.
	 *
	 * @param array $args page, per_page, vault_id.
	 * @return array
	 */
	public static function history( array $args ) {
		global $wpdb;
		list( $page, $per_page, $offset ) = Repository::page_args( $args );
		$where  = '1=1';
		$params = array( Schema::table( 'vault_logs' ) );
		if ( ! empty( $args['vault_id'] ) ) {
			$where   .= ' AND vault_id = %d';
			$params[] = absint( $args['vault_id'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		return Repository::paged( (array) $rows, $total, $per_page, $page );
	}

	/**
	 * Endpoint host for a vault (inventory disclosure).
	 *
	 * @param int $id ID.
	 * @return string
	 */
	public static function endpoint_host( $id ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return '';
		}
		$c = self::credentials( $row ) ?? array();
		switch ( $row->vault_type ) {
			case 's3_compatible':
				return ! empty( $c['endpoint'] ) ? (string) wp_parse_url( $c['endpoint'], PHP_URL_HOST ) : 's3.' . ( ! empty( $c['region'] ) ? $c['region'] : 'us-east-1' ) . '.amazonaws.com';
			case 'ftp':
				return (string) ( $c['host'] ?? '' );
			case 'remote_site':
				return (string) wp_parse_url( $c['url'] ?? '', PHP_URL_HOST );
		}
		return '';
	}

	/**
	 * Decrypted credentials, or null when unreadable.
	 *
	 * @param object $row Vault row.
	 * @return array|null
	 */
	public static function credentials( $row ) {
		$plain = Crypto::decrypt( (string) $row->credentials_encrypted, 'vault' );
		if ( null === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Provider instance.
	 *
	 * @param object $row Vault row.
	 * @return \TBSHComplianceAuditLogger\Vaults\Providers\ProviderInterface|\WP_Error
	 */
	private static function provider( $row ) {
		$creds = self::credentials( $row );
		if ( null === $creds ) {
			return new \WP_Error( 'tbsh_cal_vault_creds', __( 'Stored credentials could not be decrypted (the site encryption key may have changed). Re-enter them.', 'tbsh-compliance-audit-logger' ) );
		}
		switch ( $row->vault_type ) {
			case 's3_compatible':
				return new S3Compatible( $creds );
			case 'ftp':
				return new Ftp( $creds );
			case 'remote_site':
				return new RemoteSite( $creds );
		}
		return new \WP_Error( 'tbsh_cal_vault_type', __( 'This storage provider is no longer supported. Configure an S3-compatible, FTP/FTPS or remote-site vault instead.', 'tbsh-compliance-audit-logger' ) );
	}

	/**
	 * Raw row.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	private static function row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'vaults' ), absint( $id ) ) );
	}

	/**
	 * API shape: non-secret config in clear, secrets as set/hint only.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	private static function format( $row ) {
		$creds      = self::credentials( $row );
		$config     = array();
		$secrets    = array();
		$fields     = self::FIELDS[ $row->vault_type ] ?? array();
		foreach ( $fields as $field => list( $secret ) ) {
			$value = (string) ( $creds[ $field ] ?? '' );
			if ( $secret ) {
				$secrets[ $field ] = array( 'set' => '' !== $value, 'hint' => Secrets::mask( $value ) );
			} else {
				$config[ $field ] = $value;
			}
		}
		return array(
			'id'               => (int) $row->id,
			'vault_type'       => $row->vault_type,
			'supported'        => isset( self::FIELDS[ $row->vault_type ] ),
			'label'            => $row->label,
			'is_active'        => (bool) (int) $row->is_active,
			'schedule'         => $row->schedule,
			'status'           => $row->status,
			'last_error'       => $row->last_error,
			'last_sync_at'     => $row->last_sync_at,
			'config'           => $config,
			'secrets'          => $secrets,
			'credentials_ok'   => null !== $creds,
			'endpoint'         => self::endpoint_host( (int) $row->id ),
		);
	}
}
