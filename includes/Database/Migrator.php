<?php
namespace TBSHComplianceAuditLogger\Database;

use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Security\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned, idempotent, resumable installer/upgrader (per site).
 *
 * Each step runs once and is recorded in `tbsh_cal_migration_state`. A MySQL
 * advisory lock prevents concurrent requests from running steps twice. Steps are
 * ordered: legacy table renames must happen before dbDelta so data is upgraded in
 * place rather than duplicated into new empty tables.
 */
class Migrator {

	/**
	 * Option recording completed steps.
	 */
	const STATE_OPTION = 'tbsh_cal_migration_state';

	/**
	 * Ordered migration steps (method names). Append only; never reorder or rename.
	 */
	const STEPS = array(
		'rename_legacy_tables',
		'prepare_legacy_indexes',
		'create_tables',
		'convert_legacy_columns',
		'normalize_legacy_values',
		'migrate_capabilities',
	);

	/**
	 * Run pending steps if the schema or step list is behind. Cheap when up to date.
	 */
	public static function maybe_run() {
		$state = self::state();
		if ( Schema::DB_VERSION === get_option( 'tbsh_cal_db_version', '' ) && count( array_intersect( self::STEPS, array_keys( $state['done'] ) ) ) === count( self::STEPS ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Run all pending steps for the current site.
	 *
	 * @return bool True if all steps completed.
	 */
	public static function run() {
		global $wpdb;

		$lock = 'tbsh_cal_migrate_' . get_current_blog_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false; // Another request is migrating this site.
		}

		$state     = self::state();
		$completed = true;
		$ran       = array();

		try {
			foreach ( self::STEPS as $step ) {
				if ( isset( $state['done'][ $step ] ) ) {
					continue;
				}
				$result = call_user_func( array( __CLASS__, $step ) );
				if ( false === $result ) {
					$completed        = false;
					$state['error'] = $step;
					break;
				}
				if ( is_array( $result ) && ! empty( $result['legacy_detected'] ) ) {
					unset( $result['legacy_detected'] );
					$state['legacy_detected'] = true;
				}
				$state['done'][ $step ] = time();
				$ran[ $step ]           = $result;
				update_option( self::STATE_OPTION, $state, true );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		if ( $completed ) {
			unset( $state['error'] );
			update_option( self::STATE_OPTION, $state, true );
			update_option( 'tbsh_cal_db_version', Schema::DB_VERSION );

			if ( ! empty( $state['legacy_detected'] ) && ! empty( $ran ) ) {
				self::log_migration( $ran );
				update_option( 'tbsh_cal_notice_merged', 1, true );
			}
		} else {
			update_option( self::STATE_OPTION, $state, true );
		}

		return $completed;
	}

	/**
	 * Whether a legacy (separate-edition) installation was found on this site.
	 *
	 * @return bool
	 */
	public static function legacy_detected() {
		$state = self::state();
		return ! empty( $state['legacy_detected'] );
	}

	/**
	 * Step: rename legacy tables to their new names.
	 *
	 * If both the legacy and new table exist (an interrupted earlier run, or the new
	 * table was created independently), rows missing from the new table are copied
	 * over by primary key and the legacy table is moved aside as *_migrated_bak.
	 *
	 * @return array|false Summary, or false on failure.
	 */
	private static function rename_legacy_tables() {
		global $wpdb;
		$summary = array();

		foreach ( Schema::LEGACY_TABLES as $legacy_suffix => $key ) {
			$legacy = $wpdb->prefix . $legacy_suffix;
			$target = Schema::table( $key );

			if ( ! Schema::table_exists( $legacy ) ) {
				continue;
			}

			$summary['legacy_detected'] = true;

			if ( ! Schema::table_exists( $target ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy, $target ) ) ) {
					return false;
				}
				$summary[ $key ] = 'renamed';
				continue;
			}

			// Both exist: copy rows whose id is not present yet, using only shared columns.
			$shared = array_values( array_intersect( self::columns( $legacy ), self::columns( $target ) ) );
			if ( in_array( 'id', $shared, true ) ) {
				$cols = implode( ',', array_map( array( __CLASS__, 'quote_identifier' ), $shared ) );
				// Identifiers are validated by quote_identifier(); table names via %i.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$copied = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i ($cols) SELECT $cols FROM %i l WHERE NOT EXISTS (SELECT 1 FROM %i t WHERE t.id = l.id)", $target, $legacy, $target ) );
				if ( false === $copied ) {
					return false;
				}
				// Rows whose id already exists in the new table are not copied; they remain
				// available in the *_migrated_bak table and are reported in the migration log.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$total           = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $legacy ) );
				$summary[ $key ] = sprintf( 'merged:%d skipped:%d kept_in:%s', (int) $copied, max( 0, $total - (int) $copied ), $legacy . '_migrated_bak' );
			}

			$backup = $legacy . '_migrated_bak';
			if ( ! Schema::table_exists( $backup ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $legacy, $backup ) );
			}
		}

		return $summary;
	}

	/**
	 * Step: adjust legacy indexes that dbDelta cannot reconcile, and remove duplicate
	 * rows that would block new unique keys.
	 *
	 * @return array
	 */
	private static function prepare_legacy_indexes() {
		global $wpdb;
		$done = array();

		// Legacy vendors table indexed the full varchar(255) `name`; we use a 191 prefix.
		$vendors = Schema::table( 'vendors' );
		if ( Schema::table_exists( $vendors ) && self::index_exists( $vendors, 'name' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX `name`', $vendors ) );
			$done[] = 'vendors.name';
		}

		// Deduplicate incident/evidence links before the unique key is added.
		$links = Schema::table( 'incident_evidence' );
		if ( Schema::table_exists( $links ) && ! self::index_exists( $links, 'incident_evidence' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$removed = $wpdb->query( $wpdb->prepare(
				'DELETE a FROM %i a JOIN %i b ON a.incident_id = b.incident_id AND a.evidence_uuid = b.evidence_uuid AND a.id > b.id',
				$links,
				$links
			) );
			$done[] = 'incident_evidence.dedupe:' . (int) $removed;
		}

		return $done;
	}

	/**
	 * Step: create/upgrade all tables.
	 *
	 * @return bool
	 */
	private static function create_tables() {
		Schema::create_tables();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			if ( ! Schema::table_exists( Schema::table( $key ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Step: convert legacy columns into their new shape, then drop the legacy columns.
	 *
	 * @return array|false
	 */
	private static function convert_legacy_columns() {
		global $wpdb;
		$done = array();

		// 1. Auditor tokens: plaintext access_token -> sha256 token_hash (links keep working).
		$auditor = Schema::table( 'auditor_access' );
		if ( Schema::column_exists( $auditor, 'access_token' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, access_token FROM %i WHERE token_hash = ''", $auditor ) );
			foreach ( (array) $rows as $row ) {
				$token = (string) $row->access_token;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$auditor,
					array(
						'token_hash' => hash( 'sha256', $token ),
						'token_hint' => substr( $token, -4 ),
						'label'      => __( 'Migrated auditor link', 'tbsh-compliance-audit-logger' ),
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
			if ( false === self::drop_column( $auditor, 'access_token' ) ) {
				return false;
			}
			$done[] = 'auditor_access.token_hash:' . count( (array) $rows );
		}

		// 2. Reports: absolute file_path -> file_name inside private storage.
		$reports = Schema::table( 'reports' );
		if ( Schema::column_exists( $reports, 'file_path' ) ) {
			$moved = self::move_legacy_report_files( $reports );
			if ( false === self::drop_column( $reports, 'file_path' ) ) {
				return false;
			}
			$done[] = 'reports.file_name:' . $moved;
		}

		// 3. Alert log: raw recipient (webhook URLs, bot tokens) -> masked recipient.
		$alerts = Schema::table( 'alert_log' );
		if ( Schema::column_exists( $alerts, 'recipient' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, channel, recipient FROM %i', $alerts ) );
			foreach ( (array) $rows as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$alerts,
					array(
						'recipient_masked' => self::mask_recipient( (string) $row->channel, (string) $row->recipient ),
						'attempts'         => 1,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
			}
			if ( false === self::drop_column( $alerts, 'recipient' ) ) {
				return false;
			}
			// Legacy response bodies may echo secrets back; keep only the first 500 chars.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET response_message = LEFT(response_message, 500)', $alerts ) );
			$done[] = 'alert_log.recipient_masked:' . count( (array) $rows );
		}

		// 4. Vaults: legacy sync_type column is replaced by `schedule`.
		$vaults = Schema::table( 'vaults' );
		if ( Schema::column_exists( $vaults, 'sync_type' ) ) {
			if ( false === self::drop_column( $vaults, 'sync_type' ) ) {
				return false;
			}
			$done[] = 'vaults.sync_type';
		}

		// 5. Resilience: legacy event_type/details -> title/notes.
		$resilience = Schema::table( 'resilience' );
		if ( Schema::column_exists( $resilience, 'event_type' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET title = event_type WHERE title = ''", $resilience ) );
			if ( Schema::column_exists( $resilience, 'details' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET notes = details WHERE notes IS NULL', $resilience ) );
				self::drop_column( $resilience, 'details' );
			}
			if ( false === self::drop_column( $resilience, 'event_type' ) ) {
				return false;
			}
			$done[] = 'resilience.title';
		}

		return $done;
	}

	/**
	 * Step: normalise legacy enum values to the unified vocabulary.
	 *
	 * @return array
	 */
	private static function normalize_legacy_values() {
		global $wpdb;
		$done = array();

		$maps = array(
			'incidents' => array(
				'status'   => array(
					'Open'          => 'open',
					'Investigating' => 'investigating',
					'Escalated'     => 'investigating',
					'Contained'     => 'contained',
					'Resolved'      => 'resolved',
					'Closed'        => 'closed',
				),
				'severity' => array(
					'high'     => 'error',
					'High'     => 'error',
					'medium'   => 'warning',
					'Medium'   => 'warning',
					'low'      => 'notice',
					'Low'      => 'notice',
					'Critical' => 'critical',
				),
			),
			'risks'     => array(
				'status' => array(
					'Active'    => 'active',
					'Mitigated' => 'mitigated',
					'Accepted'  => 'accepted',
					'Closed'    => 'closed',
				),
			),
			'vendors'   => array(
				'status' => array(
					'Active'   => 'active',
					'Inactive' => 'inactive',
				),
			),
			'reports'   => array(
				'status' => array(
					'Generated' => 'generated',
				),
			),
		);

		foreach ( $maps as $key => $columns ) {
			$table = Schema::table( $key );
			foreach ( $columns as $column => $values ) {
				foreach ( $values as $from => $to ) {
					// Column names come from the fixed map above.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %s WHERE BINARY %i = %s', $table, $column, $to, $column, $from ) );
					if ( $n ) {
						$done[] = "$key.$column:$from>$to:$n";
					}
				}
			}
		}

		// Resolved/closed incidents need resolved_at for MTTR metrics.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET resolved_at = updated_at WHERE resolved_at IS NULL AND status IN ('resolved','closed')", Schema::table( 'incidents' ) ) );
		// detected_at defaults to creation time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET detected_at = created_at WHERE detected_at IS NULL', Schema::table( 'incidents' ) ) );

		// The legacy file monitor wrote success/failed rows into the chain-integrity table,
		// masking the real chain status. Re-tag them so chain status reads ignore them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET check_type = 'legacy_file' WHERE status IN ('success','failed') AND check_type = 'chain'", Schema::table( 'integrity' ) ) );
		if ( $n ) {
			$done[] = 'integrity.legacy_file:' . $n;
		}

		return $done;
	}

	/**
	 * Step: rename legacy capabilities and grant all current ones to administrators.
	 *
	 * @return array
	 */
	private static function migrate_capabilities() {
		$migrated = AccessControl::migrate_legacy_caps();
		AccessControl::init();
		return array( 'legacy_grants' => $migrated );
	}

	/**
	 * Move legacy report files out of the public uploads folder into private storage.
	 *
	 * @param string $reports Reports table.
	 * @return int Number of files moved.
	 */
	private static function move_legacy_report_files( $reports ) {
		global $wpdb;
		$moved = 0;
		$dir   = Storage::dir( 'reports' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, file_path FROM %i WHERE file_name = ''", $reports ) );

		$upload     = wp_upload_dir( null, false );
		$legacy_dir = realpath( trailingslashit( $upload['basedir'] ) . 'tbsh-compliance-reports' );

		foreach ( (array) $rows as $row ) {
			$source = realpath( (string) $row->file_path );
			$name   = '';

			// Only move files that really live in the legacy reports folder.
			if ( $dir && $legacy_dir && $source && 0 === strpos( $source, $legacy_dir . DIRECTORY_SEPARATOR ) && is_file( $source ) ) {
				$ext  = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
				$ext  = in_array( $ext, array( 'json', 'csv', 'html' ), true ) ? $ext : 'dat';
				$name = Storage::unique_name( 'report', $ext );
				if ( rename( $source, $dir . $name ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					++$moved;
				} else {
					$name = '';
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$reports,
				array(
					'file_name' => $name,
					'status'    => '' === $name ? 'missing' : 'generated',
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// Remove the legacy folder if it is now empty (it was publicly readable).
		if ( $legacy_dir && is_dir( $legacy_dir ) ) {
			$left = array_diff( (array) scandir( $legacy_dir ), array( '.', '..' ) );
			if ( empty( $left ) ) {
				rmdir( $legacy_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}

		return $moved;
	}

	/**
	 * Mask a legacy alert recipient so no secret survives in the delivery log.
	 *
	 * @param string $channel   Channel.
	 * @param string $recipient Raw recipient.
	 * @return string
	 */
	public static function mask_recipient( $channel, $recipient ) {
		if ( 'email' === $channel && is_email( $recipient ) ) {
			list( $local, $domain ) = explode( '@', $recipient, 2 );
			return substr( $local, 0, 1 ) . '•••@' . $domain;
		}
		$host = wp_parse_url( $recipient, PHP_URL_HOST );
		return $host ? $host . '/••••' : '••••';
	}

	/**
	 * Current migration state.
	 *
	 * @return array{done: array, legacy_detected?: bool, error?: string}
	 */
	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		if ( ! isset( $state['done'] ) || ! is_array( $state['done'] ) ) {
			$state['done'] = array();
		}
		return $state;
	}

	/**
	 * Column names of a table.
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	private static function columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
	}

	/**
	 * Whether an index exists.
	 *
	 * @param string $table Table.
	 * @param string $index Index name.
	 * @return bool
	 */
	private static function index_exists( $table, $index ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index ) );
	}

	/**
	 * Drop a column if present.
	 *
	 * @param string $table  Table.
	 * @param string $column Column.
	 * @return bool|int
	 */
	private static function drop_column( $table, $column ) {
		global $wpdb;
		if ( ! Schema::column_exists( $table, $column ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		return $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table, $column ) );
	}

	/**
	 * Backtick-quote a column identifier after validating it.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	private static function quote_identifier( $name ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid identifier.' );
		}
		return '`' . $name . '`';
	}

	/**
	 * Write an audit-log entry summarising the migration.
	 *
	 * @param array $ran Steps run with their summaries.
	 */
	private static function log_migration( array $ran ) {
		Logger::init();
		Logger::log(
			'plugin_migrated',
			'Operational Security',
			'notice',
			__( 'Data migrated to the unified plugin', 'tbsh-compliance-audit-logger' ),
			__( 'Incidents, risks, vendors, reports, vaults, alert history, auditor links and capabilities from the previous separate edition were migrated.', 'tbsh-compliance-audit-logger' ),
			array(
				'object_type' => 'plugin',
				'metadata'    => $ran,
			)
		);
	}
}
