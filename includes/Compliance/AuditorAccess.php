<?php
namespace TBSHComplianceAuditLogger\Compliance;

use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\SVGHelper;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Privacy\PrivacyManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expiring, read-only auditor links.
 *
 * - Tokens are random (256-bit), shown once, and stored only as SHA-256 hashes.
 * - The link `/?tbsh_cal_audit=<token>` is exchanged for an HttpOnly cookie and the
 *   browser is redirected to a URL without the token.
 * - REST requests carrying the cookie or the X-TBSH-Auditor-Token header may only
 *   reach an allowlist of GET routes; everything else is refused.
 * - Invalid attempts are rate-limited per IP hash; the portal page contains no secrets.
 */
class AuditorAccess {

	const COOKIE      = 'tbsh_cal_audit';
	const HEADER      = 'x_tbsh_auditor_token';
	const MAX_DAYS    = 90;
	const MAX_FAILS   = 10;

	/**
	 * Capabilities an auditor link satisfies (read-only).
	 */
	const READ_CAPS = array(
		'tbsh_cal_view_logs',
		'tbsh_cal_view_evidence',
		'tbsh_cal_view_incidents',
		'tbsh_cal_view_risks',
		'tbsh_cal_view_vendors',
		'tbsh_cal_view_reports',
		'tbsh_cal_view_analytics',
	);

	/**
	 * GET routes (relative to the namespace) an auditor may call.
	 */
	const ALLOWED_ROUTES = array(
		'#^/dashboard$#',
		'#^/logs(/\d+)?$#',
		'#^/timeline$#',
		'#^/evidence(/\d+)?$#',
		'#^/integrity$#',
		'#^/file-integrity$#',
		'#^/compliance$#',
		'#^/readiness$#',
		'#^/executive$#',
		'#^/incidents(/\d+)?$#',
		'#^/incidents/\d+/(notes|evidence)$#',
		'#^/risks(/\d+)?$#',
		'#^/vendors(/\d+)?$#',
		'#^/vendors/supply-chain$#',
		'#^/reports$#',
		'#^/reports/\d+/(download|verify)$#',
		'#^/resilience/(drills|metrics)$#',
		'#^/analytics/trends$#',
	);

	/**
	 * Validated access row for this request.
	 *
	 * @var object|null
	 */
	private static $active = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_front_request' ), 0 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'gate' ), 5, 3 );
		add_action( 'tbsh_cal_maintenance_run', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Whether the current REST request is authenticated by an auditor link.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return null !== self::$active;
	}

	/**
	 * Create a link.
	 *
	 * @param string $label Label.
	 * @param int    $days  Lifetime in days (1–90).
	 * @return array|\WP_Error { link, token, url } — the token is returned only here.
	 */
	public static function create( $label, $days ) {
		global $wpdb;
		$days  = Repository::clamp( $days, 1, self::MAX_DAYS );
		$token = bin2hex( random_bytes( 32 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( Schema::table( 'auditor_access' ), array(
			'token_hash' => hash( 'sha256', $token ),
			'token_hint' => substr( $token, -4 ),
			'label'      => Repository::line( $label, 191 ),
			'created_by' => get_current_user_id(),
			'expires_at' => Time::from_now( $days * DAY_IN_SECONDS ),
			'status'     => 'active',
			'created_at' => Time::now(),
		) );
		$id = (int) $wpdb->insert_id;

		Logger::log(
			'auditor_link_created',
			'Access Control',
			'notice',
			__( 'Auditor access link created', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: link id, 2: label, 3: days */
			sprintf( __( 'Read-only auditor link #%1$d "%2$s" valid for %3$d days.', 'tbsh-compliance-audit-logger' ), $id, $label, $days ),
			array( 'object_type' => 'auditor_link', 'object_id' => $id, 'compliance_tags' => 'Access Control, Audit Trail' )
		);

		return array(
			'link'  => self::get( $id ),
			'token' => $token,
			'url'   => add_query_arg( 'tbsh_cal_audit', $token, home_url( '/' ) ),
		);
	}

	/**
	 * Links (never tokens).
	 *
	 * @return array
	 */
	public static function list() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 200', Schema::table( 'auditor_access' ) ) );
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * Single link.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'auditor_access' ), absint( $id ) ) );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Revoke a link.
	 *
	 * @param int $id ID.
	 * @return array|\WP_Error
	 */
	public static function revoke( $id ) {
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Link not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'auditor_access' ), array( 'status' => 'revoked', 'revoked_at' => Time::now() ), array( 'id' => absint( $id ) ) );
		Logger::log( 'auditor_link_revoked', 'Access Control', 'notice', __( 'Auditor access link revoked', 'tbsh-compliance-audit-logger' ), sprintf( 'Link #%d', $id ), array( 'object_type' => 'auditor_link', 'object_id' => $id ) );
		return self::get( $id );
	}

	/**
	 * Daily: delete links expired or revoked more than 30 days ago.
	 */
	public static function purge() {
		global $wpdb;
		$cutoff = Time::ago( 30 * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE expires_at < %s OR (status = 'revoked' AND revoked_at < %s)", Schema::table( 'auditor_access' ), $cutoff, $cutoff ) );
	}

	/**
	 * Resolve a token to an active link row.
	 *
	 * @param string $token Raw token.
	 * @return object|null
	 */
	public static function validate( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM %i WHERE token_hash = %s AND status = 'active' AND expires_at > %s LIMIT 1",
			Schema::table( 'auditor_access' ),
			hash( 'sha256', $token ),
			Time::now()
		) );
		return $row ? $row : null;
	}

	/**
	 * REST gate: authenticate auditor requests and restrict them to the allowlist.
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function gate( $result, $server, $request ) {
		// Auditor state never carries over between dispatched requests.
		self::$active = null;

		$prefix = '/tbsh-compliance-audit-logger/v1';
		$route  = $request->get_route();
		if ( 0 !== strpos( $route, $prefix . '/' ) ) {
			return $result;
		}

		$token = (string) $request->get_header( self::HEADER );
		if ( '' === $token && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		if ( '' === $token || is_user_logged_in() ) {
			return $result; // Not an auditor request; normal capability checks apply.
		}

		if ( self::rate_limited() ) {
			return new \WP_Error( 'tbsh_cal_rate_limited', __( 'Too many invalid attempts. Try again later.', 'tbsh-compliance-audit-logger' ), array( 'status' => 429 ) );
		}

		$row = self::validate( $token );
		if ( ! $row ) {
			self::record_failure();
			return new \WP_Error( 'tbsh_cal_auditor_invalid', __( 'This auditor link is invalid, revoked or expired.', 'tbsh-compliance-audit-logger' ), array( 'status' => 401 ) );
		}

		$relative = substr( $route, strlen( $prefix ) );
		$allowed  = 'GET' === $request->get_method() && (bool) array_filter( self::ALLOWED_ROUTES, function ( $re ) use ( $relative ) {
			return (bool) preg_match( $re, $relative );
		} );
		if ( ! $allowed ) {
			return new \WP_Error( 'tbsh_cal_auditor_forbidden', __( 'Auditor links are read-only and cannot access this resource.', 'tbsh-compliance-audit-logger' ), array( 'status' => 403 ) );
		}

		self::$active = $row;
		self::touch( $row );
		return $result;
	}

	/**
	 * Front-end: token handoff and portal rendering.
	 */
	public static function handle_front_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token-authenticated public entry point.
		if ( isset( $_GET['tbsh_cal_audit'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['tbsh_cal_audit'] ) );
			if ( self::rate_limited() ) {
				self::deny_page( 429 );
			}
			$row = self::validate( $token );
			if ( ! $row ) {
				self::record_failure();
				self::deny_page( 403 );
			}
			setcookie( self::COOKIE, $token, array(
				'expires'  => (int) Time::to_timestamp( $row->expires_at ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
			self::touch( $row );
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			wp_safe_redirect( add_query_arg( 'tbsh_cal_portal', '1', home_url( '/' ) ) );
			exit;
		}

		if ( isset( $_GET['tbsh_cal_portal'] ) ) {
			$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
			$row   = self::validate( $token );
			if ( ! $row ) {
				self::deny_page( 403 );
			}
			self::render_portal( $row );
		}
		// phpcs:enable
	}

	/**
	 * Render the standalone read-only portal.
	 *
	 * @param object $row Link row.
	 */
	private static function render_portal( $row ) {
		$asset = TBSH_CAL_PATH . 'build/index.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array( 'dependencies' => array( 'wp-element', 'wp-i18n', 'wp-api-fetch', 'wp-url' ), 'version' => TBSH_CAL_VERSION );

		wp_register_style( 'tbsh-cal-admin-css', TBSH_CAL_URL . 'assets/css/admin.css', array(), $meta['version'] );
		wp_register_script( 'tbsh-cal-admin-js', TBSH_CAL_URL . 'build/index.js', $meta['dependencies'], $meta['version'], true );
		wp_set_script_translations( 'tbsh-cal-admin-js', 'tbsh-compliance-audit-logger', TBSH_CAL_PATH . 'languages' );

		$caps = array();
		foreach ( \TBSHComplianceAuditLogger\Security\AccessControl::CAPS as $short => $cap ) {
			$caps[ $short ] = in_array( $cap, self::READ_CAPS, true );
		}

		wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalApiSettings', array(
			'mode'         => 'auditor',
			'root'         => esc_url_raw( get_rest_url() ),
			'namespace'    => 'tbsh-compliance-audit-logger/v1',
			'nonce'        => '',
			'site_id'      => get_current_blog_id(),
			'capabilities' => $caps,
			'settings'     => array(),
			'siteName'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'expiresAt'    => $row->expires_at,
			'label'        => $row->label,
			'today'        => wp_date( 'Y-m-d' ),
		) );
		wp_localize_script( 'tbsh-cal-admin-js', 'tbshCalIcons', SVGHelper::get_icons() );
		\TBSHComplianceAuditLogger\Admin\Menu::chunk_translations( 'tbsh-cal-admin-js' );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Compliance Audit Portal (read-only)', 'tbsh-compliance-audit-logger' ); ?></title>
		<?php wp_print_styles( array( 'tbsh-cal-admin-css' ) ); ?>
<style>body{margin:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}#tbsh-cal-admin-root{max-width:1400px;margin:0 auto;padding:24px}</style>
</head>
<body class="tbsh-cal-auditor-portal">
<div id="tbsh-cal-admin-root"></div>
		<?php wp_print_scripts( array( 'tbsh-cal-admin-js' ) ); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Generic denial page.
	 *
	 * @param int $status HTTP status.
	 */
	private static function deny_page( $status ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_die(
			esc_html( 429 === $status ? __( 'Too many invalid attempts. Try again later.', 'tbsh-compliance-audit-logger' ) : __( 'This auditor link is invalid, revoked or expired.', 'tbsh-compliance-audit-logger' ) ),
			esc_html__( 'Access denied', 'tbsh-compliance-audit-logger' ),
			array( 'response' => (int) $status )
		);
	}

	/**
	 * Update last use and write an audit entry at most hourly per link.
	 *
	 * @param object $row Link row.
	 */
	private static function touch( $row ) {
		$key = 'tbsh_cal_audit_seen_' . $row->id;
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::table( 'auditor_access' ), array( 'last_used_at' => Time::now() ), array( 'id' => (int) $row->id ) );

		Logger::log(
			'auditor_access',
			'Access Control',
			'info',
			__( 'Auditor link used', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: link id, 2: label */
			sprintf( __( 'Read-only auditor link #%1$d "%2$s" accessed the portal.', 'tbsh-compliance-audit-logger' ), $row->id, $row->label ),
			array( 'object_type' => 'auditor_link', 'object_id' => $row->id, 'username' => 'auditor#' . $row->id, 'role' => 'auditor' )
		);
	}

	/**
	 * Whether this client exceeded invalid attempts.
	 *
	 * @return bool
	 */
	private static function rate_limited() {
		return (int) get_transient( 'tbsh_cal_audit_fail_' . substr( PrivacyManager::hash_ip(), 0, 32 ) ) >= self::MAX_FAILS;
	}

	/**
	 * Record an invalid attempt.
	 */
	private static function record_failure() {
		$key = 'tbsh_cal_audit_fail_' . substr( PrivacyManager::hash_ip(), 0, 32 );
		set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * API shape.
	 *
	 * @param object $row Row.
	 * @return array
	 */
	private static function format( $row ) {
		$status = $row->status;
		if ( 'active' === $status && $row->expires_at <= Time::now() ) {
			$status = 'expired';
		}
		return array(
			'id'           => (int) $row->id,
			'label'        => $row->label,
			'token_hint'   => '…' . $row->token_hint,
			'status'       => $status,
			'expires_at'   => $row->expires_at,
			'last_used_at' => $row->last_used_at,
			'created_by'   => Repository::user_name( $row->created_by ),
			'created_at'   => $row->created_at,
			'revoked_at'   => $row->revoked_at,
		);
	}
}
