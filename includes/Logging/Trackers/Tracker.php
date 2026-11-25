<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

use TBSHComplianceAuditLogger\API\SettingsController;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for the event trackers.
 *
 * Events that arrive in bulk (comment moderation, term imports) are batched per request:
 * up to BATCH_THRESHOLD entries of a group are logged individually, larger groups are
 * written as one summary entry. Batches and deferred checks are resolved at shutdown
 * priority 1, before the logger writes its queue at the default priority.
 */
abstract class Tracker {

	/**
	 * Largest group of same-kind events logged one by one within a request.
	 */
	const BATCH_THRESHOLD = 5;

	/**
	 * Most object IDs kept in a summary entry's metadata.
	 */
	const SUMMARY_ID_LIMIT = 100;

	/**
	 * Pending batches: key => array( 'entries' => array, 'summary' => callable ).
	 *
	 * @var array
	 */
	private static $batches = array();

	/**
	 * Whether a settings toggle is on (defaults applied).
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	protected static function enabled( $key ) {
		$settings = wp_parse_args( get_option( 'tbsh_cal_settings', array() ), SettingsController::defaults() );
		return ! empty( $settings[ $key ] );
	}

	/**
	 * Build a log entry for write() / batch().
	 *
	 * @param string $type     Event type.
	 * @param string $category Compliance category.
	 * @param string $severity Severity.
	 * @param string $title    Title.
	 * @param string $message  Message.
	 * @param array  $args     Logger args.
	 * @return array
	 */
	protected static function entry( $type, $category, $severity, $title, $message, array $args = array() ) {
		return compact( 'type', 'category', 'severity', 'title', 'message', 'args' );
	}

	/**
	 * Queue an entry with the logger.
	 *
	 * @param array $entry Entry from entry().
	 */
	protected static function write( array $entry ) {
		Logger::log( $entry['type'], $entry['category'], $entry['severity'], $entry['title'], $entry['message'], $entry['args'] );
	}

	/**
	 * Add an entry to a per-request batch.
	 *
	 * @param string   $key     Batch key (same kind of event).
	 * @param array    $entry   Entry from entry().
	 * @param callable $summary Receives the entries, returns one summary entry.
	 */
	protected static function batch( $key, array $entry, callable $summary ) {
		// Too late to defer: the shutdown pass that resolves batches may already have run.
		if ( doing_action( 'shutdown' ) ) {
			self::write( $entry );
			return;
		}
		if ( ! isset( self::$batches[ $key ] ) ) {
			self::$batches[ $key ] = array(
				'entries' => array(),
				'summary' => $summary,
			);
		}
		self::$batches[ $key ]['entries'][] = $entry;
		self::defer( array( __CLASS__, 'flush_batches' ) );
	}

	/**
	 * Write pending batches.
	 */
	public static function flush_batches() {
		$batches       = self::$batches;
		self::$batches = array();

		foreach ( $batches as $batch ) {
			if ( count( $batch['entries'] ) <= self::BATCH_THRESHOLD ) {
				foreach ( $batch['entries'] as $entry ) {
					self::write( $entry );
				}
				continue;
			}
			self::write( call_user_func( $batch['summary'], $batch['entries'] ) );
		}
	}

	/**
	 * Run a callback once at shutdown, before the logger flushes.
	 *
	 * @param callable $callback Callback.
	 */
	protected static function defer( $callback ) {
		if ( false === has_action( 'shutdown', $callback ) ) {
			add_action( 'shutdown', $callback, 1 );
		}
	}

	/**
	 * Object IDs of batched entries, capped for metadata.
	 *
	 * @param array $entries Entries.
	 * @return array
	 */
	protected static function batch_ids( array $entries ) {
		$ids = array();
		foreach ( array_slice( $entries, 0, self::SUMMARY_ID_LIMIT ) as $entry ) {
			$ids[] = $entry['args']['object_id'] ?? '';
		}
		return $ids;
	}

	/**
	 * Username for a user ID, or "#ID" when the account no longer exists.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected static function user_label( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user ? $user->user_login : '#' . (int) $user_id;
	}

	/**
	 * Short, single-line display value for messages and metadata.
	 *
	 * @param mixed $value  Value.
	 * @param int   $length Maximum characters.
	 * @return string
	 */
	protected static function display( $value, $length = 100 ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return '[complex]';
		}
		return wp_html_excerpt( sanitize_text_field( (string) $value ), $length, '…' );
	}

	/**
	 * Keys whose values differ between two arrays, with short from/to values.
	 *
	 * @param array $old   Old values.
	 * @param array $new   New values.
	 * @param int   $limit Maximum keys reported.
	 * @return array key => array( from, to )
	 */
	protected static function diff_keys( array $old, array $new, $limit = 50 ) {
		$changed = array();
		foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
			$from = $old[ $key ] ?? null;
			$to   = $new[ $key ] ?? null;
			if ( maybe_serialize( $from ) === maybe_serialize( $to ) ) {
				continue;
			}
			$changed[ (string) $key ] = array(
				'from' => self::display( $from ),
				'to'   => self::display( $to ),
			);
			if ( count( $changed ) >= $limit ) {
				break;
			}
		}
		return $changed;
	}
}
