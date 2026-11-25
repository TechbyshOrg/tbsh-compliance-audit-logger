<?php
namespace TBSHComplianceAuditLogger\Integrity;

use TBSHComplianceAuditLogger\Helpers\Crypto;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin, theme and uploads file integrity.
 *
 * - Plugins hosted on WordPress.org are compared with the official per-version checksums
 *   (downloads.wordpress.org/plugin-checksums), including PHP files that are not part of
 *   the release.
 * - Other plugins, themes and must-use plugins are compared with a local baseline of
 *   SHA-256 hashes, created on the first scan and refreshed after updates. Baselines are
 *   HMAC-signed so tampering with them is detected.
 * - The uploads folder is searched for executable PHP files.
 *
 * Scans are fully resumable: every component is processed in phases (list files, hash
 * files, compare) whose progress is saved to a private job file, so each batch stays
 * within its time budget however large a plugin, theme or uploads folder is. Manual
 * scans are driven by the browser, scheduled scans by chained WP-Cron events.
 */
class ExtensionIntegrity {

	const STATE_OPTION  = 'tbsh_cal_file_integrity_state';
	const RESULT_OPTION = 'tbsh_cal_file_integrity_result';
	const CRON_HOOK     = 'tbsh_cal_file_integrity';
	const LOCK          = 'tbsh_cal_file_integrity_lock';
	const ACCEPT_LOCK   = 'tbsh_cal_file_accept_lock';

	/**
	 * Files listed and hashed per component at most.
	 */
	const MAX_FILES = 20000;

	/**
	 * Files visited in uploads at most.
	 */
	const MAX_UPLOADS = 200000;

	/**
	 * Items reported per list (modified, missing, added).
	 */
	const MAX_REPORTED = 50;

	/**
	 * Executable extensions searched in uploads and reported as "added" in WordPress.org plugins.
	 */
	const EXECUTABLE = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'pht', 'phps' );

	/**
	 * Folders never walked.
	 */
	const SKIP_DIRS = array( '.git', '.svn', 'node_modules' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget_upgraded' ), 20, 2 );
		add_action( 'deleted_plugin', array( __CLASS__, 'forget_plugin' ), 10, 2 );
		add_action( 'deleted_theme', array( __CLASS__, 'forget_theme' ), 10, 2 );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scan lifecycle
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Start a scan (no-op while one is running and not stale).
	 *
	 * @param string $trigger manual|scheduled.
	 * @return array Status.
	 */
	public static function start( $trigger = 'manual' ) {
		$state = self::state();
		if ( ! empty( $state['running'] ) && strtotime( (string) $state['started_at'] ) > strtotime( Time::ago( HOUR_IN_SECONDS ) ) ) {
			return self::status();
		}

		$queue = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			$queue[] = array( 'type' => 'plugin', 'id' => $file );
		}
		foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
			$queue[] = array( 'type' => 'theme', 'id' => $stylesheet );
		}
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$queue[] = array( 'type' => 'mu-plugins', 'id' => 'mu-plugins' );
		}
		$queue[] = array( 'type' => 'uploads', 'id' => 'uploads' );

		self::clear_job();
		update_option(
			self::STATE_OPTION,
			array(
				'running'    => true,
				'trigger'    => 'scheduled' === $trigger ? 'scheduled' : 'manual',
				'started_at' => Time::now(),
				'total'      => count( $queue ),
				'queue'      => $queue,
				'results'    => array(),
			),
			false
		);

		if ( 'scheduled' === $trigger ) {
			self::schedule_next( 0 );
		}
		return self::status();
	}

	/**
	 * Cron: process a batch and chain the next one.
	 */
	public static function run_batch() {
		self::process( 20 );
		if ( ! empty( self::state()['running'] ) ) {
			self::schedule_next( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Process queued work for up to $budget seconds.
	 *
	 * @param int $budget Seconds.
	 * @return array Status.
	 */
	public static function process( $budget = 15 ) {
		if ( get_transient( self::LOCK ) ) {
			// Another batch (cron or another tab) is running; callers should wait before retrying.
			return array_merge( self::status(), array( 'busy' => true ) );
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$state    = self::state();
		$deadline = microtime( true ) + max( 1, (int) $budget );

		while ( ! empty( $state['running'] ) && ! empty( $state['queue'] ) ) {
			$result = self::scan_item( $state['queue'][0], $deadline );
			if ( null === $result ) {
				break; // Progress saved in the job file; continue next batch.
			}
			array_shift( $state['queue'] );
			$state['results'][] = $result;
			update_option( self::STATE_OPTION, $state, false );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( ! empty( $state['running'] ) && empty( $state['queue'] ) ) {
			self::finish( $state );
		}

		delete_transient( self::LOCK );
		return self::status();
	}

	/**
	 * Store the completed result and log it.
	 *
	 * @param array $state Finished state.
	 */
	private static function finish( array $state ) {
		$components = $state['results'];
		$failing    = array();
		$totals     = array(
			'components' => 0,
			'checked'    => 0,
			'modified'   => 0,
			'missing'    => 0,
			'added'      => 0,
			'baselined'  => 0,
		);

		foreach ( $components as $c ) {
			++$totals['components'];
			$totals['checked']  += (int) $c['checked'];
			$totals['modified'] += (int) $c['counts']['modified'];
			$totals['missing']  += (int) $c['counts']['missing'];
			$totals['added']    += (int) $c['counts']['added'];
			if ( 'baseline_created' === $c['status'] ) {
				++$totals['baselined'];
			}
			if ( in_array( $c['status'], array( 'modified', 'tampered' ), true ) ) {
				$failing[] = $c['name'];
			}
		}

		$status = $failing ? 'fail' : 'ok';
		$result = array(
			'status'      => $status,
			'trigger'     => $state['trigger'],
			'started_at'  => $state['started_at'],
			'finished_at' => Time::now(),
			'totals'      => $totals,
			'failing'     => $failing,
			'components'  => $components,
		);
		update_option( self::RESULT_OPTION, $result, false );
		update_option( self::STATE_OPTION, array( 'running' => false ), false );
		self::clear_job();
		wp_clear_scheduled_hook( self::CRON_HOOK );

		$message = 'ok' === $status
			/* translators: 1: components, 2: files */
			? sprintf( __( 'Plugin, theme and uploads files verified: %1$d components, %2$d files, no unexpected changes.', 'tbsh-compliance-audit-logger' ), $totals['components'], $totals['checked'] )
			/* translators: 1: comma-separated component names, 2: modified, 3: missing, 4: added */
			: sprintf( __( 'Unexpected file changes in: %1$s (%2$d modified, %3$d missing, %4$d unexpected files).', 'tbsh-compliance-audit-logger' ), implode( ', ', array_slice( $failing, 0, 10 ) ), $totals['modified'], $totals['missing'], $totals['added'] );

		Logger::log(
			'file_integrity_check',
			'Security Monitoring',
			'ok' === $status ? 'info' : 'critical',
			__( 'Plugin and theme file verification', 'tbsh-compliance-audit-logger' ),
			$message,
			array(
				'compliance_tags' => 'Security Monitoring, File Integrity',
				'metadata'        => array(
					'status'  => $status,
					'totals'  => $totals,
					'failing' => array_slice( $failing, 0, 50 ),
				),
			)
		);
	}

	/**
	 * Current progress and last result.
	 *
	 * @return array
	 */
	public static function status() {
		$state = self::state();
		return array(
			'running'   => ! empty( $state['running'] ),
			'total'     => (int) ( $state['total'] ?? 0 ),
			'remaining' => count( $state['queue'] ?? array() ),
			'result'    => get_option( self::RESULT_OPTION, null ),
		);
	}

	/**
	 * Stored scan state.
	 *
	 * @return array
	 */
	private static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Schedule the next cron batch.
	 *
	 * @param int $delay Seconds.
	 */
	private static function schedule_next( $delay ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Resumable component jobs
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Describe a queue item: where its files are and how it is verified.
	 *
	 * @param array $item Queue item.
	 * @return array|null type, id, name, version, path, single, mode (wporg|baseline|uploads), checksums.
	 */
	private static function describe( array $item ) {
		switch ( $item['type'] ) {
			case 'plugin':
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = get_plugins();
				if ( ! isset( $plugins[ $item['id'] ] ) ) {
					return null;
				}
				$data    = $plugins[ $item['id'] ];
				$single  = false === strpos( $item['id'], '/' );
				$version = (string) ( $data['Version'] ?? '' );
				$desc    = array(
					'type'    => 'plugin',
					'id'      => $item['id'],
					'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $item['id'],
					'version' => $version,
					'path'    => $single ? WP_PLUGIN_DIR . '/' . $item['id'] : WP_PLUGIN_DIR . '/' . dirname( $item['id'] ),
					'single'  => $single,
					'mode'    => 'baseline',
				);
				if ( ! $single && '' !== $version ) {
					$checksums = self::wporg_checksums( dirname( $item['id'] ), $version );
					if ( is_array( $checksums ) ) {
						$desc['mode']      = 'wporg';
						$desc['checksums'] = $checksums;
					}
				}
				return $desc;

			case 'theme':
				$theme = wp_get_theme( $item['id'] );
				if ( ! $theme->exists() ) {
					return null;
				}
				return array(
					'type'    => 'theme',
					'id'      => $item['id'],
					'name'    => $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
					'path'    => $theme->get_stylesheet_directory(),
					'single'  => false,
					'mode'    => 'baseline',
				);

			case 'mu-plugins':
				return array(
					'type'    => 'mu-plugins',
					'id'      => 'mu-plugins',
					'name'    => __( 'Must-use plugins', 'tbsh-compliance-audit-logger' ),
					'version' => '',
					'path'    => WPMU_PLUGIN_DIR,
					'single'  => false,
					'mode'    => 'baseline',
				);

			default:
				$uploads = wp_upload_dir( null, false );
				return array(
					'type'    => 'uploads',
					'id'      => 'uploads',
					'name'    => __( 'Uploads folder', 'tbsh-compliance-audit-logger' ),
					'version' => '',
					'path'    => (string) ( $uploads['basedir'] ?? '' ),
					'single'  => false,
					'mode'    => 'uploads',
				);
		}
	}

	/**
	 * Advance one component until it finishes or the deadline passes.
	 *
	 * @param array $item     Queue item.
	 * @param float $deadline microtime() deadline.
	 * @return array|null Component result, or null when it needs another batch.
	 */
	private static function scan_item( array $item, $deadline ) {
		$key = $item['type'] . ':' . $item['id'];
		$job = self::load_job();
		if ( ! $job || ( $job['key'] ?? '' ) !== $key ) {
			$desc = self::describe( $item );
			if ( null === $desc ) {
				return self::component( $item['type'], $item['id'], $item['id'], '', 'baseline', 'error', 0, array(), array(), array(), __( 'This component was removed during the scan.', 'tbsh-compliance-audit-logger' ) );
			}
			$path = rtrim( wp_normalize_path( $desc['path'] ), '/' );
			if ( '' === $path || ( $desc['single'] ? ! is_readable( $path ) : ! is_dir( $path ) ) ) {
				return self::component( $desc['type'], $desc['id'], $desc['name'], $desc['version'], 'uploads' === $desc['mode'] ? 'scan' : 'baseline', 'uploads' === $desc['mode'] ? 'ok' : 'error', 0, array(), array(), array(), 'uploads' === $desc['mode'] ? '' : __( 'The files could not be read.', 'tbsh-compliance-audit-logger' ) );
			}
			$desc['path'] = $path;
			$job          = array(
				'key'     => $key,
				'desc'    => $desc,
				'phase'   => 'list',
				'stack'   => $desc['single'] ? array() : array( '' ),
				'files'   => $desc['single'] ? array( basename( $path ) ) : array(),
				'visited' => 0,
				'capped'  => false,
				'index'   => 0,
				'hashes'  => array(),
			);
		}

		$desc = $job['desc'];

		if ( 'list' === $job['phase'] ) {
			if ( ! self::list_step( $job, $deadline ) ) {
				self::save_job( $job );
				return null;
			}
			$job['phase'] = 'uploads' === $desc['mode'] ? 'done' : 'hash';
			if ( 'wporg' === $desc['mode'] ) {
				$job['targets'] = array_keys( $desc['checksums'] );
			} else {
				$job['targets'] = $job['files'];
			}
		}

		if ( 'hash' === $job['phase'] ) {
			if ( ! self::hash_step( $job, $deadline ) ) {
				self::save_job( $job );
				return null;
			}
			$job['phase'] = 'done';
		}

		self::clear_job();
		switch ( $desc['mode'] ) {
			case 'wporg':
				return self::compare_wporg( $job );
			case 'uploads':
				return self::uploads_result( $job );
			default:
				return self::compare_baseline( $job );
		}
	}

	/**
	 * List files, one directory at a time (always at least one).
	 *
	 * @param array $job      Job (by reference).
	 * @param float $deadline Deadline.
	 * @return bool Whether listing is complete.
	 */
	private static function list_step( array &$job, $deadline ) {
		$uploads = 'uploads' === $job['desc']['mode'];
		$limit   = $uploads ? self::MAX_UPLOADS : self::MAX_FILES;
		$base    = $job['desc']['path'];
		$first   = true;

		while ( $job['stack'] ) {
			if ( ! $first && microtime( true ) >= $deadline ) {
				return false;
			}
			$first   = false;
			$rel_dir = array_pop( $job['stack'] );
			$dir     = '' === $rel_dir ? $base : $base . '/' . $rel_dir;
			$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable folders are skipped.
			if ( false === $entries ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$rel  = '' === $rel_dir ? $entry : $rel_dir . '/' . $entry;
				$full = $base . '/' . $rel;
				if ( is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					if ( ! in_array( $entry, self::SKIP_DIRS, true ) ) {
						$job['stack'][] = $rel;
					}
					continue;
				}
				++$job['visited'];
				if ( $uploads ) {
					if ( in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), self::EXECUTABLE, true ) && ! self::is_placeholder( $full ) ) {
						$job['files'][] = $rel;
					}
				} else {
					$job['files'][] = $rel;
				}
				if ( $job['visited'] >= $limit ) {
					$job['capped'] = true;
					$job['stack']  = array();
					break;
				}
			}
		}
		sort( $job['files'] );
		return true;
	}

	/**
	 * Hash target files with a cursor (always at least one per call).
	 *
	 * @param array $job      Job (by reference).
	 * @param float $deadline Deadline.
	 * @return bool Whether hashing is complete.
	 */
	private static function hash_step( array &$job, $deadline ) {
		$base  = $job['desc']['single'] ? dirname( $job['desc']['path'] ) : $job['desc']['path'];
		$total = count( $job['targets'] );
		$first = true;

		while ( $job['index'] < $total ) {
			if ( ! $first && microtime( true ) >= $deadline ) {
				return false;
			}
			$first = false;
			$rel   = $job['targets'][ $job['index'] ];
			$file  = $base . '/' . $rel;
			if ( ! is_file( $file ) ) {
				$job['hashes'][ $rel ] = 'missing';
			} else {
				$hash                  = @hash_file( 'sha256', $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable files are reported as modified.
				$job['hashes'][ $rel ] = false === $hash ? 'unreadable' : $hash;
			}
			++$job['index'];
		}
		return true;
	}

	/**
	 * Compare hashed files with WordPress.org checksums.
	 *
	 * @param array $job Finished job.
	 * @return array
	 */
	private static function compare_wporg( array $job ) {
		$desc     = $job['desc'];
		$modified = array();
		$missing  = array();

		foreach ( $desc['checksums'] as $rel => $accepted ) {
			$hash = $job['hashes'][ $rel ] ?? 'missing';
			if ( 'missing' === $hash ) {
				$missing[] = $rel;
				continue;
			}
			if ( in_array( 'sha256:' . $hash, $accepted, true ) ) {
				continue;
			}
			// Releases without SHA-256 sums: fall back to MD5.
			if ( ! preg_grep( '/^sha256:/', $accepted ) ) {
				$md5 = @md5_file( $desc['path'] . '/' . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $md5 && in_array( 'md5:' . $md5, $accepted, true ) ) {
					continue;
				}
			}
			$modified[] = $rel;
		}

		$added = array();
		foreach ( $job['files'] as $rel ) {
			if ( ! isset( $desc['checksums'][ $rel ] ) && in_array( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ), self::EXECUTABLE, true ) ) {
				$added[] = $rel;
			}
		}

		return self::component( $desc['type'], $desc['id'], $desc['name'], $desc['version'], 'wordpress.org', ( $modified || $missing || $added ) ? 'modified' : 'ok', count( $job['hashes'] ), $modified, $missing, $added, self::cap_note( $job ) );
	}

	/**
	 * Compare hashed files with the signed baseline, creating it when absent.
	 *
	 * @param array $job Finished job.
	 * @return array
	 */
	private static function compare_baseline( array $job ) {
		$desc    = $job['desc'];
		$current = array_filter( $job['hashes'], function ( $hash ) {
			return 'missing' !== $hash;
		} );
		$note    = self::cap_note( $job );

		$baseline = self::read_baseline( $desc['type'], $desc['id'] );
		if ( is_wp_error( $baseline ) ) {
			self::write_baseline( $desc['type'], $desc['id'], $desc['version'], $current );
			return self::component( $desc['type'], $desc['id'], $desc['name'], $desc['version'], 'baseline', 'tampered', count( $current ), array(), array(), array(), __( 'The stored baseline failed its signature check and was replaced. Review this component.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( null === $baseline || (string) $baseline['version'] !== (string) $desc['version'] ) {
			self::write_baseline( $desc['type'], $desc['id'], $desc['version'], $current );
			return self::component( $desc['type'], $desc['id'], $desc['name'], $desc['version'], 'baseline', 'baseline_created', count( $current ), array(), array(), array(), trim( __( 'Baseline recorded. Later scans report changes against it.', 'tbsh-compliance-audit-logger' ) . ' ' . $note ) );
		}

		$modified = array();
		$missing  = array();
		foreach ( $baseline['files'] as $rel => $hash ) {
			if ( ! isset( $current[ $rel ] ) ) {
				$missing[] = $rel;
			} elseif ( ! hash_equals( (string) $hash, (string) $current[ $rel ] ) ) {
				$modified[] = $rel;
			}
		}
		$added = array_values( array_diff( array_keys( $current ), array_keys( $baseline['files'] ) ) );

		return self::component( $desc['type'], $desc['id'], $desc['name'], $desc['version'], 'baseline', ( $modified || $missing || $added ) ? 'modified' : 'ok', count( $current ), $modified, $missing, $added, $note );
	}

	/**
	 * Result of the uploads search.
	 *
	 * @param array $job Finished job.
	 * @return array
	 */
	private static function uploads_result( array $job ) {
		$desc = $job['desc'];
		return self::component( 'uploads', 'uploads', $desc['name'], '', 'scan', $job['files'] ? 'modified' : 'ok', (int) $job['visited'], array(), array(), $job['files'], self::cap_note( $job ) );
	}

	/**
	 * Note shown when a component had more files than are checked.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	private static function cap_note( array $job ) {
		if ( empty( $job['capped'] ) ) {
			return '';
		}
		/* translators: %d: number of files */
		return sprintf( __( 'Only the first %d files were checked.', 'tbsh-compliance-audit-logger' ), (int) $job['visited'] );
	}

	/**
	 * Job file path.
	 *
	 * @param string $name scan-job (scans) or accept-job (baseline acceptance).
	 * @return string|false
	 */
	private static function job_path( $name = 'scan-job' ) {
		$dir = Storage::dir( 'integrity' );
		return $dir ? $dir . $name . '.json' : false;
	}

	/**
	 * Load the in-progress job.
	 *
	 * @param string $name Job name.
	 * @return array|null
	 */
	private static function load_job( $name = 'scan-job' ) {
		$file = self::job_path( $name );
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$job = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $job ) && isset( $job['key'], $job['desc'], $job['phase'] ) ? $job : null;
	}

	/**
	 * Save the in-progress job.
	 *
	 * @param array  $job  Job.
	 * @param string $name Job name.
	 */
	private static function save_job( array $job, $name = 'scan-job' ) {
		$file = self::job_path( $name );
		if ( $file ) {
			file_put_contents( $file, wp_json_encode( $job ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Delete the in-progress job.
	 *
	 * @param string $name Job name.
	 */
	private static function clear_job( $name = 'scan-job' ) {
		$file = self::job_path( $name );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * WordPress.org checksums
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Official checksums of a WordPress.org plugin version (cached for a day).
	 *
	 * @param string $slug    Plugin folder.
	 * @param string $version Version.
	 * @return array|null File => list of accepted "sha256:…"/"md5:…" hashes, or null when unavailable.
	 */
	private static function wporg_checksums( $slug, $version ) {
		$slug    = sanitize_key( $slug );
		$version = preg_replace( '/[^0-9A-Za-z.\-_]/', '', $version );
		if ( '' === $slug || '' === $version ) {
			return null;
		}

		$key    = 'tbsh_cal_pcs_' . md5( $slug . '|' . $version );
		$cached = get_transient( $key );
		if ( 'none' === $cached ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			'https://downloads.wordpress.org/plugin-checksums/' . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json',
			array( 'timeout' => 15, 'redirection' => 2 )
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			set_transient( $key, 'none', DAY_IN_SECONDS );
			return null;
		}
		if ( 200 !== $code ) {
			return null;
		}

		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$files = array();
		foreach ( (array) ( $body['files'] ?? array() ) as $rel => $hashes ) {
			$accepted = array();
			foreach ( array( 'sha256', 'md5' ) as $algo ) {
				foreach ( (array) ( $hashes[ $algo ] ?? array() ) as $hash ) {
					$accepted[] = $algo . ':' . strtolower( (string) $hash );
				}
			}
			$files[ (string) $rel ] = $accepted;
		}
		if ( ! $files ) {
			set_transient( $key, 'none', DAY_IN_SECONDS );
			return null;
		}
		set_transient( $key, $files, DAY_IN_SECONDS );
		return $files;
	}

	/**
	 * Whether a PHP file is an empty "silence is golden" placeholder.
	 *
	 * @param string $file File.
	 * @return bool
	 */
	private static function is_placeholder( $file ) {
		if ( 'index.php' !== basename( $file ) || @filesize( $file ) > 200 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		$code = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		return (bool) preg_match( '#^\s*<\?php\s*(?://[^\n]*\s*|/\*.*?\*/\s*)*(?:\?>\s*)?$#s', $code );
	}

	/**
	 * Build a component result.
	 *
	 * @param string $type     Type.
	 * @param string $id       ID.
	 * @param string $name     Name.
	 * @param string $version  Version.
	 * @param string $source   wordpress.org|baseline|scan.
	 * @param string $status   ok|modified|baseline_created|tampered|error.
	 * @param int    $checked  Files checked.
	 * @param array  $modified Modified files.
	 * @param array  $missing  Missing files.
	 * @param array  $added    Unexpected files.
	 * @param string $message  Optional note.
	 * @return array
	 */
	private static function component( $type, $id, $name, $version, $source, $status, $checked, array $modified, array $missing, array $added, $message = '' ) {
		return array(
			'type'     => $type,
			'id'       => $id,
			'name'     => wp_strip_all_tags( (string) $name ),
			'version'  => (string) $version,
			'source'   => $source,
			'status'   => $status,
			'checked'  => (int) $checked,
			'counts'   => array(
				'modified' => count( $modified ),
				'missing'  => count( $missing ),
				'added'    => count( $added ),
			),
			'modified' => array_slice( $modified, 0, self::MAX_REPORTED ),
			'missing'  => array_slice( $missing, 0, self::MAX_REPORTED ),
			'added'    => array_slice( $added, 0, self::MAX_REPORTED ),
			'message'  => $message,
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Baselines
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Baseline file path.
	 *
	 * @param string $type Type.
	 * @param string $id   ID.
	 * @return string|false
	 */
	private static function baseline_path( $type, $id ) {
		$dir = Storage::dir( 'integrity' );
		return $dir ? $dir . 'baseline-' . sanitize_key( $type ) . '-' . md5( $id ) . '.json' : false;
	}

	/**
	 * Load and verify a baseline.
	 *
	 * @param string $type Type.
	 * @param string $id   ID.
	 * @return array|null|\WP_Error Baseline, null when absent, WP_Error when its signature is invalid.
	 */
	private static function read_baseline( $type, $id ) {
		$file = self::baseline_path( $type, $id );
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || ! isset( $data['files'], $data['mac'], $data['version'] ) || ! is_array( $data['files'] ) ) {
			return new \WP_Error( 'tbsh_cal_baseline', 'invalid' );
		}
		if ( ! Crypto::verify_hmac( self::canonical( $type, $id, $data['version'], $data['files'] ), (string) $data['mac'], 'file-baseline' ) ) {
			return new \WP_Error( 'tbsh_cal_baseline', 'signature' );
		}
		return $data;
	}

	/**
	 * Write a signed baseline.
	 *
	 * @param string $type    Type.
	 * @param string $id      ID.
	 * @param string $version Version.
	 * @param array  $files   rel => hash.
	 * @return bool
	 */
	private static function write_baseline( $type, $id, $version, array $files ) {
		$file = self::baseline_path( $type, $id );
		if ( ! $file ) {
			return false;
		}
		ksort( $files );
		$data = array(
			'type'       => $type,
			'id'         => $id,
			'version'    => (string) $version,
			'created_at' => Time::now(),
			'files'      => $files,
			'mac'        => Crypto::hmac( self::canonical( $type, $id, $version, $files ), 'file-baseline' ),
		);
		return false !== file_put_contents( $file, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Canonical string signed for a baseline.
	 *
	 * @param string $type    Type.
	 * @param string $id      ID.
	 * @param string $version Version.
	 * @param array  $files   Files.
	 * @return string
	 */
	private static function canonical( $type, $id, $version, array $files ) {
		ksort( $files );
		return $type . '|' . $id . '|' . $version . '|' . wp_json_encode( $files );
	}

	/**
	 * Accept the current files of a component as its new baseline (after an intentional change).
	 *
	 * Works in steps like a scan: each call lists and hashes files for up to $budget seconds and
	 * saves its progress, so very large plugins never hit the host's time limit. Call again with
	 * the same component until `done` is true. Starting another component discards unfinished work.
	 *
	 * @param string $type   plugin|theme|mu-plugins.
	 * @param string $id     Component ID.
	 * @param float  $budget Seconds of work for this call.
	 * @return array|\WP_Error { done: bool, busy?: bool, phase: string, files: int, hashed: int }.
	 */
	public static function accept( $type, $id, $budget = 15 ) {
		if ( ! in_array( $type, array( 'plugin', 'theme', 'mu-plugins' ), true ) ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'Only plugins, themes and must-use plugins have a baseline.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		$desc = self::describe( array( 'type' => $type, 'id' => 'mu-plugins' === $type ? 'mu-plugins' : $id ) );
		if ( null === $desc ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Component not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$desc['path'] = rtrim( wp_normalize_path( $desc['path'] ), '/' );
		if ( '' === $desc['path'] || ( $desc['single'] ? ! is_readable( $desc['path'] ) : ! is_dir( $desc['path'] ) ) ) {
			return new \WP_Error( 'tbsh_cal_unreadable', __( 'The files could not be read.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}

		if ( get_transient( self::ACCEPT_LOCK ) ) {
			// Another request is working on it; the caller waits and asks again.
			return array( 'done' => false, 'busy' => true, 'phase' => 'wait', 'files' => 0, 'hashed' => 0 );
		}
		set_transient( self::ACCEPT_LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$key = $desc['type'] . ':' . $desc['id'];
		$job = self::load_job( 'accept-job' );
		if ( ! $job || ( $job['key'] ?? '' ) !== $key || (string) ( $job['desc']['version'] ?? '' ) !== (string) $desc['version'] ) {
			unset( $desc['checksums'] );
			$job = array(
				'key'     => $key,
				'desc'    => $desc,
				'phase'   => 'list',
				'stack'   => $desc['single'] ? array() : array( '' ),
				'files'   => $desc['single'] ? array( basename( $desc['path'] ) ) : array(),
				'visited' => 0,
				'capped'  => false,
				'index'   => 0,
				'hashes'  => array(),
			);
		}
		$desc     = $job['desc'];
		$deadline = microtime( true ) + max( 0.05, (float) $budget );

		if ( 'list' === $job['phase'] ) {
			if ( ! self::list_step( $job, $deadline ) ) {
				return self::accept_pause( $job );
			}
			$job['phase']   = 'hash';
			$job['targets'] = $job['files'];
		}
		if ( ! self::hash_step( $job, $deadline ) ) {
			return self::accept_pause( $job );
		}

		$files = array_filter( $job['hashes'], function ( $hash ) {
			return 'missing' !== $hash;
		} );
		if ( ! self::write_baseline( $desc['type'], $desc['id'], $desc['version'], $files ) ) {
			delete_transient( self::ACCEPT_LOCK );
			return new \WP_Error( 'tbsh_cal_storage', __( 'The baseline could not be written.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		self::clear_job( 'accept-job' );
		delete_transient( self::ACCEPT_LOCK );

		Logger::log(
			'file_baseline_accepted',
			'Change Management',
			'warning',
			__( 'File baseline accepted', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: component name */
			sprintf( __( 'The current files of "%s" were accepted as its new integrity baseline.', 'tbsh-compliance-audit-logger' ), wp_strip_all_tags( (string) $desc['name'] ) ),
			array(
				'object_type'     => $desc['type'],
				'object_id'       => $desc['id'],
				'compliance_tags' => 'Change Management, File Integrity',
				'metadata'        => array( 'files' => count( $files ), 'version' => $desc['version'] ),
			)
		);

		self::mark_accepted( $desc['type'], $desc['id'], count( $files ) );
		return array( 'done' => true, 'phase' => 'done', 'files' => count( $files ), 'hashed' => count( $files ) );
	}

	/**
	 * Save an unfinished acceptance and report its progress.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function accept_pause( array $job ) {
		self::save_job( $job, 'accept-job' );
		delete_transient( self::ACCEPT_LOCK );
		return array(
			'done'   => false,
			'phase'  => $job['phase'],
			'files'  => count( $job['files'] ),
			'hashed' => (int) $job['index'],
		);
	}

	/**
	 * Update the stored result after a baseline is accepted.
	 *
	 * @param string $type  Type.
	 * @param string $id    ID.
	 * @param int    $files Files.
	 */
	private static function mark_accepted( $type, $id, $files ) {
		$result = get_option( self::RESULT_OPTION, null );
		if ( ! is_array( $result ) || empty( $result['components'] ) ) {
			return;
		}
		$failing = array();
		foreach ( $result['components'] as &$c ) {
			if ( $c['type'] === $type && $c['id'] === $id ) {
				$c['status']   = 'ok';
				$c['source']   = 'baseline';
				$c['checked']  = $files;
				$c['counts']   = array( 'modified' => 0, 'missing' => 0, 'added' => 0 );
				$c['modified'] = array();
				$c['missing']  = array();
				$c['added']    = array();
				$c['message']  = __( 'Current files accepted as the baseline.', 'tbsh-compliance-audit-logger' );
			}
			if ( in_array( $c['status'], array( 'modified', 'tampered' ), true ) ) {
				$failing[] = $c['name'];
			}
		}
		unset( $c );
		$result['failing'] = $failing;
		$result['status']  = $failing ? 'fail' : 'ok';
		update_option( self::RESULT_OPTION, $result, false );
	}

	/**
	 * Drop baselines of updated or installed plugins/themes (re-created on the next scan).
	 *
	 * @param \WP_Upgrader $upgrader   Upgrader.
	 * @param array        $hook_extra Details.
	 */
	public static function forget_upgraded( $upgrader, $hook_extra ) {
		$type = $hook_extra['type'] ?? '';
		if ( 'plugin' === $type ) {
			$items = $hook_extra['plugins'] ?? ( isset( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );
			foreach ( (array) $items as $file ) {
				self::delete_baseline( 'plugin', (string) $file );
			}
		} elseif ( 'theme' === $type ) {
			$items = $hook_extra['themes'] ?? ( isset( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );
			foreach ( (array) $items as $stylesheet ) {
				self::delete_baseline( 'theme', (string) $stylesheet );
			}
		}
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $file    Plugin file.
	 * @param bool   $deleted Whether it was deleted.
	 */
	public static function forget_plugin( $file, $deleted = true ) {
		if ( $deleted ) {
			self::delete_baseline( 'plugin', (string) $file );
		}
	}

	/**
	 * Theme deleted.
	 *
	 * @param string $stylesheet Theme.
	 * @param bool   $deleted    Whether it was deleted.
	 */
	public static function forget_theme( $stylesheet, $deleted = true ) {
		if ( $deleted ) {
			self::delete_baseline( 'theme', (string) $stylesheet );
		}
	}

	/**
	 * Delete a baseline file.
	 *
	 * @param string $type Type.
	 * @param string $id   ID.
	 */
	private static function delete_baseline( $type, $id ) {
		$file = self::baseline_path( $type, $id );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
