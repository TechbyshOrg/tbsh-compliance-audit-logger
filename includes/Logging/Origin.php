<?php
namespace TBSHComplianceAuditLogger\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records how a change reached the site: who or what acted, and through which channel.
 *
 * Every log entry gets these keys in its metadata (covered by the hash chain):
 *
 * - `_actor`: person, agent (AI agent), integration, automation or visitor.
 * - `_via`:   web, ajax, rest, mcp, xmlrpc, cron or cli.
 * - `_app_password`: name of the application password used, if any.
 * - `_ability`: the WordPress ability that ran in this request, if any (abilities that
 *   ran earlier in the same request are listed in `_abilities`).
 *
 * An AI agent is recognised when the request arrives through an MCP endpoint, or when an
 * ability runs under an application password (the way remote agents and MCP servers
 * authenticate). An ability run from the block editor in a logged-in browser counts as the
 * person. Other plugins can refine the result with the `tbsh_cal_request_origin` filter.
 */
class Origin {

	/**
	 * Actor values.
	 */
	const ACTORS = array( 'person', 'agent', 'integration', 'automation', 'visitor' );

	/**
	 * Most ability names kept per request.
	 */
	const MAX_ABILITIES = 10;

	/**
	 * Application password used to authenticate this request.
	 *
	 * @var array|null { name: string, uuid: string }
	 */
	private static $app_password = null;

	/**
	 * Abilities that started executing in this request, in order.
	 *
	 * @var string[]
	 */
	private static $abilities = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_app_password' ), 10, 2 );
	}

	/**
	 * Remember the application password that authenticated this request.
	 *
	 * @param \WP_User $user User.
	 * @param array    $item Application password item.
	 */
	public static function note_app_password( $user, $item ) {
		self::$app_password = array(
			'name' => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
			'uuid' => sanitize_text_field( (string) ( $item['uuid'] ?? '' ) ),
		);
	}

	/**
	 * Remember an ability that started executing.
	 *
	 * @param string $name Ability name.
	 */
	public static function note_ability( $name ) {
		$name = substr( preg_replace( '/[^a-z0-9\-\/_]/', '', strtolower( (string) $name ) ), 0, 100 );
		if ( '' === $name ) {
			return;
		}
		self::$abilities[] = $name;
		if ( count( self::$abilities ) > self::MAX_ABILITIES ) {
			self::$abilities = array_slice( self::$abilities, -self::MAX_ABILITIES );
		}
	}

	/**
	 * Forget per-request state (long-running processes and tests).
	 */
	public static function reset() {
		self::$app_password = null;
		self::$abilities    = array();
	}

	/**
	 * Facts about the current request.
	 *
	 * @return array
	 */
	public static function environment() {
		$route = '';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
				$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
			} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$uri    = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
				$prefix = '/' . trim( rest_get_url_prefix(), '/' );
				$pos    = strpos( $uri, $prefix . '/' );
				$route  = false === $pos ? '' : substr( $uri, $pos + strlen( $prefix ) );
			}
		}

		return array(
			'cli'          => defined( 'WP_CLI' ) && WP_CLI,
			'cron'         => wp_doing_cron(),
			'xmlrpc'       => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'rest'         => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'route'        => $route,
			'ajax'         => wp_doing_ajax(),
			'user_id'      => get_current_user_id(),
			'app_password' => self::$app_password,
			'abilities'    => self::$abilities,
		);
	}

	/**
	 * Classify a request. Pure function of the environment.
	 *
	 * @param array $env Output of environment().
	 * @return array{actor: string, via: string, app_password?: string, ability?: string, abilities?: string[]}
	 */
	public static function detect( array $env ) {
		$abilities = array_values( (array) ( $env['abilities'] ?? array() ) );
		$app       = $env['app_password'] ?? null;
		$user      = (int) ( $env['user_id'] ?? 0 ) > 0;
		$route     = '/' . ltrim( strtolower( (string) ( $env['route'] ?? '' ) ), '/' );

		if ( ! empty( $env['cli'] ) ) {
			$via = 'cli';
		} elseif ( ! empty( $env['cron'] ) ) {
			$via = 'cron';
		} elseif ( ! empty( $env['xmlrpc'] ) ) {
			$via = 'xmlrpc';
		} elseif ( ! empty( $env['rest'] ) && self::is_mcp_route( $route ) ) {
			$via = 'mcp';
		} elseif ( ! empty( $env['rest'] ) ) {
			$via = 'rest';
		} elseif ( ! empty( $env['ajax'] ) ) {
			$via = 'ajax';
		} else {
			$via = 'web';
		}

		if ( 'mcp' === $via || ( $abilities && $app && in_array( $via, array( 'rest', 'xmlrpc' ), true ) ) ) {
			$actor = 'agent';
		} elseif ( in_array( $via, array( 'cli', 'cron' ), true ) ) {
			$actor = 'automation';
		} elseif ( $app || 'xmlrpc' === $via ) {
			$actor = 'integration';
		} elseif ( $user ) {
			$actor = 'person';
		} else {
			$actor = 'visitor';
		}

		$origin = array(
			'actor' => $actor,
			'via'   => $via,
		);
		if ( $app && '' !== ( $app['name'] ?? '' ) ) {
			$origin['app_password'] = $app['name'];
		}
		if ( $abilities ) {
			$origin['ability'] = end( $abilities );
			if ( count( $abilities ) > 1 ) {
				$origin['abilities'] = array_values( array_unique( $abilities ) );
			}
		}
		return $origin;
	}

	/**
	 * Whether a REST route belongs to an MCP server.
	 *
	 * @param string $route Route with leading slash, lowercase.
	 * @return bool
	 */
	private static function is_mcp_route( $route ) {
		/**
		 * REST namespaces served by MCP servers (requests through them come from AI agents).
		 *
		 * @param string[] $namespaces Namespace prefixes, without slashes.
		 */
		$namespaces = (array) apply_filters( 'tbsh_cal_mcp_namespaces', array( 'mcp', 'mcp-adapter', 'wp-mcp' ) );
		foreach ( $namespaces as $ns ) {
			$ns = trim( strtolower( (string) $ns ), '/' );
			if ( '' !== $ns && ( $route === '/' . $ns || 0 === strpos( $route, '/' . $ns . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * LIKE pattern matching entries made by an actor (attribution lives in metadata_json).
	 *
	 * @param string $actor Actor value.
	 * @return string Pattern for `metadata_json LIKE %s`, or '' for an unknown actor.
	 */
	public static function actor_like( $actor ) {
		global $wpdb;
		$actor = (string) $actor;
		if ( ! in_array( $actor, self::ACTORS, true ) ) {
			return '';
		}
		return '%' . $wpdb->esc_like( '"_actor":"' . $actor . '"' ) . '%';
	}

	/**
	 * Origin of the current request, ready to merge into an entry's metadata.
	 *
	 * @return array Keys prefixed with an underscore.
	 */
	public static function current() {
		$env    = self::environment();
		$origin = self::detect( $env );

		/**
		 * Filter the recorded origin of the current request.
		 *
		 * For example, a plugin that runs an AI assistant can set `actor` to `agent`
		 * for the changes it makes on a user's behalf.
		 *
		 * @param array $origin Keys: actor, via, and optionally app_password, ability, abilities.
		 * @param array $env    Request facts used to classify it.
		 */
		$filtered = apply_filters( 'tbsh_cal_request_origin', $origin, $env );
		if ( is_array( $filtered ) ) {
			$origin = $filtered;
		}
		if ( ! in_array( $origin['actor'] ?? '', self::ACTORS, true ) ) {
			$origin['actor'] = 'person';
		}

		$meta = array();
		foreach ( array( 'actor', 'via', 'app_password', 'ability', 'abilities' ) as $key ) {
			if ( isset( $origin[ $key ] ) && '' !== $origin[ $key ] && array() !== $origin[ $key ] ) {
				$meta[ '_' . $key ] = is_array( $origin[ $key ] )
					? array_map( 'sanitize_text_field', array_map( 'strval', array_slice( $origin[ $key ], 0, self::MAX_ABILITIES ) ) )
					: sanitize_text_field( (string) $origin[ $key ] );
			}
		}
		return $meta;
	}
}
