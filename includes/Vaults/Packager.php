<?php
namespace TBSHComplianceAuditLogger\Vaults;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Helpers\Time;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds encrypted evidence packages for off-site vaults.
 *
 * Plain content: gzip-compressed JSON Lines, one record per line:
 *   {"type":"manifest"|"log"|"incident"|"incident_note"|"evidence", "data":{...}}
 *
 * Encrypted file format (version 1):
 *   "TBSHVLT1" (8 bytes) | PBKDF2 iterations (uint32 big-endian) | salt (16 bytes)
 *   | secretstream header (24 bytes)
 *   | repeated: ciphertext length (uint32 big-endian) | ciphertext chunk
 * Key = PBKDF2-HMAC-SHA256(passphrase, salt, iterations, 32 bytes).
 * Chunks use libsodium crypto_secretstream_xchacha20poly1305; the last carries TAG_FINAL.
 * The format is documented in tools/decrypt-vault.php so backups can be restored
 * without WordPress.
 */
class Packager {

	const MAGIC      = 'TBSHVLT1';

	/**
	 * libsodium secretstream values (fixed by the format). Defined here because the sodium_compat
	 * polyfill that WordPress uses when ext/sodium is missing does not define every SODIUM_* constant.
	 */
	const TAG_MESSAGE = 0;
	const TAG_FINAL   = 3;
	const HEADERBYTES = 24;
	const ITERATIONS = 210000;
	const CHUNK      = 65536;
	const LOG_PAGE   = 5000;

	/**
	 * Build an encrypted package.
	 *
	 * @param object $vault      Vault row.
	 * @param string $passphrase Package passphrase.
	 * @return array|\WP_Error { path, name, size, last_log_id, counts }
	 */
	public static function build( $vault, $passphrase ) {
		global $wpdb;

		if ( '' === (string) $passphrase ) {
			return new \WP_Error( 'tbsh_cal_vault_passphrase', __( 'Set an encryption passphrase for this vault before syncing.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return new \WP_Error( 'tbsh_cal_vault_zlib', __( 'The PHP zlib extension is required to build vault packages.', 'tbsh-compliance-audit-logger' ) );
		}

		$dir = Storage::dir( 'vault-tmp' );
		if ( ! $dir ) {
			return new \WP_Error( 'tbsh_cal_storage', __( 'The private temporary folder could not be created.', 'tbsh-compliance-audit-logger' ) );
		}

		// A build cut short by a fatal error or time limit leaves its unencrypted
		// temporary file behind; remove such leftovers before starting.
		self::sweep( $dir );

		$plain  = $dir . Storage::unique_name( 'plain', 'jsonl.gz' );
		$gz     = gzopen( $plain, 'wb6' );
		$since  = (int) $vault->last_synced_log_id;
		$counts = array( 'logs' => 0, 'incidents' => 0, 'incident_notes' => 0, 'evidence' => 0 );
		$last   = $since;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$head = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT integrity_hash FROM %i ORDER BY id DESC LIMIT 1', Schema::table( 'logs' ) ) );
		self::line( $gz, 'manifest', array(
			'format'        => 1,
			'site'          => home_url( '/' ),
			'generated_at'  => Time::now(),
			'logs_after_id' => $since,
			'chain_head'    => $head,
			'plugin'        => TBSH_CAL_VERSION,
		) );

		// Logs since the last successful sync, streamed in pages.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', Schema::table( 'logs' ), $last, self::LOG_PAGE ), ARRAY_A );
			foreach ( $rows as $row ) {
				self::line( $gz, 'log', $row );
				$last = (int) $row['id'];
				++$counts['logs'];
			}
		} while ( count( $rows ) === self::LOG_PAGE );

		// Incident register (full snapshot each time; small).
		foreach ( array( 'incidents' => 'incident', 'incident_notes' => 'incident_note' ) as $table => $type ) {
			$offset = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT 1000 OFFSET %d', Schema::table( $table ), $offset ), ARRAY_A );
				foreach ( $rows as $row ) {
					self::line( $gz, $type, $row );
					++$counts[ $table ];
				}
				$offset += 1000;
			} while ( count( $rows ) === 1000 );
		}

		// Evidence captured since the previous sync (or everything on the first sync).
		$evidence_since = $vault->last_sync_at ? $vault->last_sync_at : '1970-01-01 00:00:00';
		$offset         = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE created_at > %s ORDER BY id ASC LIMIT 100 OFFSET %d', Schema::table( 'evidence' ), $evidence_since, $offset ), ARRAY_A );
			foreach ( $rows as $row ) {
				self::line( $gz, 'evidence', $row );
				++$counts['evidence'];
			}
			$offset += 100;
		} while ( count( $rows ) === 100 );

		gzclose( $gz );

		$name      = sprintf( 'evidence-%s-%s.tbshvault', sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ), gmdate( 'Ymd-His' ) );
		$encrypted = $dir . Storage::unique_name( 'vault', 'tbshvault' );
		$result    = self::encrypt_file( $plain, $encrypted, $passphrase );
		wp_delete_file( $plain );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $encrypted );
			return $result;
		}

		return array(
			'path'        => $encrypted,
			'name'        => $name,
			'size'        => (int) filesize( $encrypted ),
			'last_log_id' => $last,
			'counts'      => $counts,
		);
	}

	/**
	 * Encrypt a file.
	 *
	 * @param string $in         Plain input path.
	 * @param string $out        Encrypted output path.
	 * @param string $passphrase Passphrase.
	 * @return true|\WP_Error
	 */
	public static function encrypt_file( $in, $out, $passphrase ) {
		$salt = random_bytes( 16 );
		$key  = hash_pbkdf2( 'sha256', $passphrase, $salt, self::ITERATIONS, 32, true );

		$src = fopen( $in, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$dst = fopen( $out, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $src || ! $dst ) {
			return new \WP_Error( 'tbsh_cal_vault_io', __( 'Could not open the package files.', 'tbsh-compliance-audit-logger' ) );
		}

		try {
			list( $state, $header ) = sodium_crypto_secretstream_xchacha20poly1305_init_push( $key );
			fwrite( $dst, self::MAGIC . pack( 'N', self::ITERATIONS ) . $salt . $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			$size = (int) filesize( $in );
			$read = 0;
			do {
				$chunk = (string) fread( $src, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$read += strlen( $chunk );
				$final = $read >= $size || feof( $src );
				$tag   = $final ? self::TAG_FINAL : self::TAG_MESSAGE;
				$ct    = sodium_crypto_secretstream_xchacha20poly1305_push( $state, $chunk, '', $tag );
				fwrite( $dst, pack( 'N', strlen( $ct ) ) . $ct ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			} while ( ! $final );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'tbsh_cal_vault_crypto', $e->getMessage() );
		} finally {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( function_exists( 'sodium_memzero' ) ) {
				try {
					sodium_memzero( $key );
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Polyfill may not support memzero.
				}
			}
		}
		return true;
	}

	/**
	 * Decrypt a package to its gzip JSON Lines content.
	 *
	 * @param string $in         Encrypted path.
	 * @param string $out        Output path (.jsonl.gz).
	 * @param string $passphrase Passphrase.
	 * @return true|\WP_Error
	 */
	public static function decrypt_file( $in, $out, $passphrase ) {
		$src = fopen( $in, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $src ) {
			return new \WP_Error( 'tbsh_cal_vault_io', 'Cannot open input file.' );
		}
		$head = (string) fread( $src, 8 + 4 + 16 + self::HEADERBYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( 0 !== strpos( $head, self::MAGIC ) ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'tbsh_cal_vault_format', 'Not a vault package (bad header).' );
		}
		$iterations = unpack( 'N', substr( $head, 8, 4 ) )[1];
		// Packages are written with ITERATIONS; refuse values that would fail or hang.
		if ( $iterations < 10000 || $iterations > 10000000 ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'tbsh_cal_vault_format', 'Not a vault package (invalid key settings).' );
		}
		$salt       = substr( $head, 12, 16 );
		$header     = substr( $head, 28 );
		$key        = hash_pbkdf2( 'sha256', $passphrase, $salt, $iterations, 32, true );

		$dst = fopen( $out, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		try {
			$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull( $header, $key );
			$final = false;
			while ( ! $final ) {
				$len = fread( $src, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $len || 4 !== strlen( $len ) ) {
					throw new \RuntimeException( 'Package is truncated.' );
				}
				$ct  = (string) fread( $src, unpack( 'N', $len )[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$res = sodium_crypto_secretstream_xchacha20poly1305_pull( $state, $ct );
				if ( false === $res ) {
					throw new \RuntimeException( 'Wrong passphrase or the package was modified.' );
				}
				list( $plain, $tag ) = $res;
				fwrite( $dst, $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$final = self::TAG_FINAL === (int) $tag;
			}
		} catch ( \Throwable $e ) {
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( $out );
			return new \WP_Error( 'tbsh_cal_vault_crypto', $e->getMessage() );
		}
		fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * Delete temporary package files older than an hour (leftovers of interrupted builds).
	 *
	 * @param string $dir Temporary folder, with trailing slash.
	 */
	private static function sweep( $dir ) {
		foreach ( array_merge( (array) glob( $dir . 'plain*' ), (array) glob( $dir . 'vault*' ) ) as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Write one JSON Lines record.
	 *
	 * @param resource $gz   Gzip handle.
	 * @param string   $type Record type.
	 * @param array    $data Data.
	 */
	private static function line( $gz, $type, array $data ) {
		gzwrite( $gz, wp_json_encode( array( 'type' => $type, 'data' => $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
}
