<?php
namespace TBSHComplianceAuditLogger\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public logging API for other plugins and custom code.
 *
 * Usage:
 *   tbsh_cal_log( 'invoice_approved', array( 'title' => 'Invoice approved', 'message' => '...' ) );
 *   do_action( 'tbsh_cal_log', 'invoice_approved', array( ... ) );
 *
 * Input is validated and sanitised, event types used by the plugin's own detection
 * rules are reserved, and a request can add at most MAX_PER_REQUEST entries.
 */
class PublicApi {

	/**
	 * Most entries one request can add through the API.
	 */
	const MAX_PER_REQUEST = 200;

	/**
	 * Largest encoded metadata kept (bytes).
	 */
	const MAX_METADATA_BYTES = 8192;

	/**
	 * Entries added in this request.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Register the action.
	 */
	public static function init() {
		add_action( 'tbsh_cal_log', array( __CLASS__, 'from_action' ), 10, 2 );
	}

	/**
	 * Action adapter (actions cannot return a value).
	 *
	 * @param string $event_type Event type.
	 * @param array  $args       Arguments.
	 */
	public static function from_action( $event_type, $args = array() ) {
		self::log( $event_type, is_array( $args ) ? $args : array() );
	}

	/**
	 * Event types callers may not use (they drive incident detection and integrity reporting).
	 *
	 * @return string[]
	 */
	public static function reserved_types() {
		return array_merge(
			array_keys( \TBSHComplianceAuditLogger\Incidents\IncidentDetector::rules() ),
			array( 'incident_created', 'file_integrity_check', 'siem_forward_failed', 'compliance_digest_sent', 'ability_executed', 'ability_failed', 'ability_denied', 'network_site_added', 'network_site_removed', 'network_link_replaced' )
		);
	}

	/**
	 * Validate and queue an event.
	 *
	 * @param string $event_type Lowercase slug (letters, digits, underscores, dashes), at most 64 characters.
	 * @param array  $args {
	 *     @type string $title           Required. Short title (max 200 characters).
	 *     @type string $message         Description (max 2000 characters). Defaults to the title.
	 *     @type string $category        One of Logger::CATEGORIES. Default 'Operational Security'.
	 *     @type string $severity        info|notice|warning|error|critical. Default 'info'.
	 *     @type string $object_type     Optional object type.
	 *     @type string $object_id       Optional object ID.
	 *     @type array  $metadata        Optional JSON-serialisable data (max 8 KB encoded).
	 *     @type string $compliance_tags Optional comma-separated tags.
	 * }
	 * @return bool Whether the event was queued (it can still be dropped by settings or filters).
	 */
	public static function log( $event_type, array $args = array() ) {
		$event_type = is_string( $event_type ) ? sanitize_key( $event_type ) : '';
		if ( '' === $event_type || strlen( $event_type ) > 64 ) {
			self::doing_it_wrong( __( 'The event type must be a non-empty slug of at most 64 characters.', 'tbsh-compliance-audit-logger' ) );
			return false;
		}
		if ( in_array( $event_type, self::reserved_types(), true ) ) {
			/* translators: %s: event type */
			self::doing_it_wrong( sprintf( __( 'The event type "%s" is reserved by the plugin.', 'tbsh-compliance-audit-logger' ), $event_type ) );
			return false;
		}

		$title = isset( $args['title'] ) && is_scalar( $args['title'] ) ? wp_html_excerpt( sanitize_text_field( (string) $args['title'] ), 200 ) : '';
		if ( '' === $title ) {
			self::doing_it_wrong( __( 'A title is required.', 'tbsh-compliance-audit-logger' ) );
			return false;
		}

		if ( self::$count >= self::MAX_PER_REQUEST ) {
			return false;
		}

		$message  = isset( $args['message'] ) && is_scalar( $args['message'] ) ? wp_html_excerpt( sanitize_textarea_field( (string) $args['message'] ), 2000 ) : '';
		$category = isset( $args['category'] ) && in_array( $args['category'], Logger::CATEGORIES, true ) ? $args['category'] : 'Operational Security';
		$severity = isset( $args['severity'] ) && in_array( $args['severity'], array( 'info', 'notice', 'warning', 'error', 'critical' ), true ) ? $args['severity'] : 'info';

		$metadata = isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array();
		$encoded  = wp_json_encode( $metadata );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_METADATA_BYTES ) {
			$metadata = array( 'truncated' => true );
		}
		$metadata['source'] = 'api';

		$log_args = array( 'metadata' => $metadata );
		foreach ( array( 'object_type', 'object_id', 'compliance_tags' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$log_args[ $key ] = wp_html_excerpt( sanitize_text_field( (string) $args[ $key ] ), 190 );
			}
		}

		++self::$count;
		Logger::log( $event_type, $category, $severity, $title, '' !== $message ? $message : $title, $log_args );
		return true;
	}

	/**
	 * Report developer misuse (shown only with WP_DEBUG).
	 *
	 * @param string $message Message.
	 */
	private static function doing_it_wrong( $message ) {
		_doing_it_wrong( 'tbsh_cal_log', esc_html( $message ), '2.0.0' );
	}
}
