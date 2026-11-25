<?php
namespace TBSHComplianceAuditLogger\Logging\Trackers;

use TBSHComplianceAuditLogger\Logging\Origin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress Abilities API runs (WordPress 6.9+; setting: log_ability_events).
 *
 * Abilities are how AI agents and MCP servers act on a site. Every ability that starts is
 * noted for attribution (see Origin), whatever this setting says. When the setting is on:
 *
 * - ability_executed: an ability that can change the site finished (read-only abilities
 *   are skipped unless the tbsh_cal_log_readonly_abilities filter returns true);
 * - ability_failed:   such an ability returned an error (WordPress 7.1+);
 * - ability_denied:   a permission check refused an ability (WordPress 7.1+).
 *
 * Only the names of the input fields are logged, never their values.
 */
class AbilityTracker extends Tracker {

	/**
	 * Setting that switches the ability events on.
	 */
	const SETTING = 'log_ability_events';

	/**
	 * Most denied entries per request.
	 */
	const MAX_DENIED = 5;

	/**
	 * Most input field names kept.
	 */
	const MAX_INPUT_KEYS = 25;

	/**
	 * Denials already logged in this request (ability name => true).
	 *
	 * @var array
	 */
	private static $denied = array();

	/**
	 * Abilities whose execution was requested in this request (WordPress 7.1+).
	 *
	 * @var array
	 */
	private static $invoked = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ability_invoked', array( __CLASS__, 'invoked' ), 1 );
		add_action( 'wp_before_execute_ability', array( __CLASS__, 'before_execute' ), 1 );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'after_execute' ), 10, 4 );
		add_filter( 'wp_ability_execute_result', array( __CLASS__, 'execute_result' ), 999, 4 );
		add_filter( 'wp_ability_permission_result', array( __CLASS__, 'permission_result' ), 999, 4 );
	}

	/**
	 * Forget per-request state (tests).
	 */
	public static function reset() {
		self::$denied  = array();
		self::$invoked = array();
	}

	/**
	 * Execution of an ability was requested (before validation and permission checks).
	 *
	 * @param string $name Ability name.
	 */
	public static function invoked( $name ) {
		self::$invoked[ (string) $name ] = true;
	}

	/**
	 * Whether a permission check is part of an attempt to run the ability, rather than a
	 * listing that only asks what the user may do.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private static function is_run_attempt( $name ) {
		if ( isset( self::$invoked[ $name ] ) ) {
			return true;
		}
		$env   = Origin::environment();
		$route = strtolower( (string) $env['route'] );
		return '' !== $route && (bool) preg_match( '#^/wp-abilities/v\d+/abilities/' . preg_quote( strtolower( $name ), '#' ) . '/run/?$#', $route );
	}

	/**
	 * An ability passed its checks and is about to run.
	 *
	 * @param string $name Ability name.
	 */
	public static function before_execute( $name ) {
		Origin::note_ability( $name );
	}

	/**
	 * An ability finished successfully.
	 *
	 * @param string $name    Ability name.
	 * @param mixed  $input   Input.
	 * @param mixed  $result  Result.
	 * @param object $ability Ability (WordPress 7.1+).
	 */
	public static function after_execute( $name, $input = null, $result = null, $ability = null ) {
		$ability = self::resolve( $name, $ability );
		if ( ! self::should_log( $ability ) ) {
			return;
		}
		$destructive = true === self::annotation( $ability, 'destructive' );
		$label       = self::label( $name, $ability );
		self::write(
			self::entry(
				'ability_executed',
				'Change Management',
				$destructive ? 'warning' : 'notice',
				/* translators: %s: ability label */
				sprintf( __( 'Ability run: %s', 'tbsh-compliance-audit-logger' ), $label ),
				/* translators: 1: ability label, 2: ability name */
				sprintf( __( 'The ability "%1$s" (%2$s) was run.', 'tbsh-compliance-audit-logger' ), $label, $name ),
				array(
					'object_type' => 'ability',
					'object_id'   => $name,
					'metadata'    => self::metadata( $name, $input, $ability ),
				)
			)
		);
	}

	/**
	 * Log abilities whose callback returned an error (WordPress 7.1+). Result is passed through.
	 *
	 * @param mixed  $result  Result.
	 * @param string $name    Ability name.
	 * @param mixed  $input   Input.
	 * @param object $ability Ability.
	 * @return mixed
	 */
	public static function execute_result( $result, $name = '', $input = null, $ability = null ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$ability = self::resolve( $name, $ability );
		if ( ! self::should_log( $ability ) ) {
			return $result;
		}
		$label = self::label( $name, $ability );
		$meta  = self::metadata( $name, $input, $ability );

		$meta['error_code'] = sanitize_key( (string) $result->get_error_code() );
		self::write(
			self::entry(
				'ability_failed',
				'Change Management',
				'notice',
				/* translators: %s: ability label */
				sprintf( __( 'Ability failed: %s', 'tbsh-compliance-audit-logger' ), $label ),
				/* translators: 1: ability label, 2: error code */
				sprintf( __( 'The ability "%1$s" returned an error (%2$s).', 'tbsh-compliance-audit-logger' ), $label, $meta['error_code'] ),
				array(
					'object_type' => 'ability',
					'object_id'   => (string) $name,
					'metadata'    => $meta,
				)
			)
		);
		return $result;
	}

	/**
	 * Log refused permission checks (WordPress 7.1+). Result is passed through.
	 *
	 * @param bool|\WP_Error $permission Permission result.
	 * @param string         $name       Ability name.
	 * @param mixed          $input      Input.
	 * @param object         $ability    Ability.
	 * @return bool|\WP_Error
	 */
	public static function permission_result( $permission, $name = '', $input = null, $ability = null ) {
		if ( true === $permission || ! self::enabled( self::SETTING ) ) {
			return $permission;
		}
		$name = (string) $name;
		if ( isset( self::$denied[ $name ] ) || count( self::$denied ) >= self::MAX_DENIED || ! self::is_run_attempt( $name ) ) {
			return $permission;
		}
		self::$denied[ $name ] = true;

		$ability = self::resolve( $name, $ability );
		$label   = self::label( $name, $ability );
		self::write(
			self::entry(
				'ability_denied',
				'Authorization',
				'warning',
				/* translators: %s: ability label */
				sprintf( __( 'Ability refused: %s', 'tbsh-compliance-audit-logger' ), $label ),
				/* translators: %s: ability name */
				sprintf( __( 'A request to run the ability %s was refused by its permission check.', 'tbsh-compliance-audit-logger' ), $name ),
				array(
					'object_type' => 'ability',
					'object_id'   => $name,
					'metadata'    => self::metadata( $name, $input, $ability ),
				)
			)
		);
		return $permission;
	}

	/**
	 * Ability object for a name (the hook passes it from WordPress 7.1).
	 *
	 * @param string $name    Name.
	 * @param mixed  $ability Ability passed by the hook.
	 * @return object|null
	 */
	private static function resolve( $name, $ability ) {
		if ( is_object( $ability ) ) {
			return $ability;
		}
		if ( function_exists( 'wp_get_ability' ) ) {
			$found = wp_get_ability( (string) $name );
			return is_object( $found ) ? $found : null;
		}
		return null;
	}

	/**
	 * Whether a run of this ability is logged.
	 *
	 * @param object|null $ability Ability.
	 * @return bool
	 */
	private static function should_log( $ability ) {
		if ( ! self::enabled( self::SETTING ) ) {
			return false;
		}
		if ( true === self::annotation( $ability, 'readonly' ) ) {
			/**
			 * Whether runs of read-only abilities are logged too (off by default: agents read often).
			 *
			 * @param bool   $log     Whether to log.
			 * @param object $ability Ability.
			 */
			return (bool) apply_filters( 'tbsh_cal_log_readonly_abilities', false, $ability );
		}
		return true;
	}

	/**
	 * Annotation value (readonly, destructive, idempotent).
	 *
	 * @param object|null $ability Ability.
	 * @param string      $key     Annotation.
	 * @return bool|null
	 */
	private static function annotation( $ability, $key ) {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
			return null;
		}
		$meta = (array) $ability->get_meta();
		return isset( $meta['annotations'][ $key ] ) ? (bool) $meta['annotations'][ $key ] : null;
	}

	/**
	 * Human label.
	 *
	 * @param string      $name    Name.
	 * @param object|null $ability Ability.
	 * @return string
	 */
	private static function label( $name, $ability ) {
		if ( is_object( $ability ) && method_exists( $ability, 'get_label' ) ) {
			$label = sanitize_text_field( (string) $ability->get_label() );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return sanitize_text_field( (string) $name );
	}

	/**
	 * Entry metadata: ability name, category, annotations and input field names (no values).
	 *
	 * @param string      $name    Name.
	 * @param mixed       $input   Input.
	 * @param object|null $ability Ability.
	 * @return array
	 */
	private static function metadata( $name, $input, $ability ) {
		$meta = array( 'ability' => sanitize_text_field( (string) $name ) );
		if ( is_object( $ability ) && method_exists( $ability, 'get_category' ) ) {
			$meta['ability_category'] = sanitize_key( (string) $ability->get_category() );
		}
		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
			$value = self::annotation( $ability, $key );
			if ( null !== $value ) {
				$meta[ $key ] = $value;
			}
		}
		if ( is_array( $input ) && $input ) {
			$meta['input_fields'] = array_slice( array_map( 'sanitize_key', array_map( 'strval', array_keys( $input ) ) ), 0, self::MAX_INPUT_KEYS );
		}
		return $meta;
	}
}
