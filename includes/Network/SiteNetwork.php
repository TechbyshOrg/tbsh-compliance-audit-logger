<?php
namespace TBSHComplianceAuditLogger\Network;

use TBSHComplianceAuditLogger\Helpers\Crypto;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site network: one overview of several sites that run this plugin.
 *
 * Each connected site is added with one of its own auditor links (Auditor Access on that site).
 * The link is read-only, expires after at most 90 days and can be revoked there at any time,
 * so connecting a site never grants this site more than an auditor would have.
 *
 * This site calls the other site's REST API server-to-server with the link token in the
 * X-TBSH-Auditor-Token header and caches a summary: readiness score, activity today, errors and
 * critical events, failed sign-ins, open incidents, log integrity and the latest errors.
 * Tokens are stored encrypted and never sent to the browser. Requests go only to public https
 * addresses and never follow redirects, so the token cannot leak to another host.
 */
class SiteNetwork {

	const OPTION     = 'tbsh_cal_network_sites';
	const HOOK       = 'tbsh_cal_network_refresh';
	const MAX_SITES  = 50;
	const TIMEOUT    = 12;
	const RUN_BUDGET = 25;
	const NS         = '/tbsh-compliance-audit-logger/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'refresh_all' ) );
	}

	/**
	 * Stored sites keyed by ID.
	 *
	 * @return array
	 */
	private static function stored() {
		$sites = get_option( self::OPTION, array() );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Save sites and keep the hourly refresh scheduled only while sites exist.
	 *
	 * @param array $sites Sites.
	 */
	private static function save( array $sites ) {
		update_option( self::OPTION, $sites, false );
		if ( $sites && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		} elseif ( ! $sites ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Sites for display (never includes tokens).
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();
		foreach ( self::stored() as $site ) {
			$out[] = self::public_view( $site );
		}
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );
		return $out;
	}

	/**
	 * Site without secrets.
	 *
	 * @param array $site Site.
	 * @return array
	 */
	private static function public_view( array $site ) {
		unset( $site['token'] );
		return $site;
	}

	/**
	 * Parse an auditor link ("https://example.com/?tbsh_cal_audit=<64 hex>").
	 *
	 * @param string $link Link.
	 * @return array|\WP_Error array( base URL, token ).
	 */
	public static function parse_link( $link ) {
		$link  = trim( (string) $link );
		$parts = wp_parse_url( $link );
		if ( ! $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'tbsh_cal_network_link', __( 'Paste the full auditor link from the other site, for example https://example.com/?tbsh_cal_audit=….', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) && ! self::allow_private() ) {
			return new \WP_Error( 'tbsh_cal_network_link', __( 'The other site must use https.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		parse_str( $parts['query'] ?? '', $query );
		$token = isset( $query['tbsh_cal_audit'] ) ? strtolower( (string) $query['tbsh_cal_audit'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return new \WP_Error( 'tbsh_cal_network_link', __( 'This is not an auditor link. On the other site open Auditor Access, create a link and copy it.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		$base = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . rtrim( $parts['path'] ?? '', '/' );
		return array( $base, $token );
	}

	/**
	 * Connect a site.
	 *
	 * @param string $label Display name.
	 * @param string $link  Auditor link of that site.
	 * @return array|\WP_Error Site (public view).
	 */
	public static function add( $label, $link ) {
		$parsed = self::parse_link( $link );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		list( $base, $token ) = $parsed;
		$sites = self::stored();
		if ( count( $sites ) >= self::MAX_SITES ) {
			/* translators: %d: maximum number of sites */
			return new \WP_Error( 'tbsh_cal_network_full', sprintf( __( 'A network can hold up to %d sites.', 'tbsh-compliance-audit-logger' ), self::MAX_SITES ), array( 'status' => 400 ) );
		}
		foreach ( $sites as $site ) {
			if ( $site['url'] === $base ) {
				return new \WP_Error( 'tbsh_cal_network_exists', __( 'This site is already connected. Use “Replace link” to give it a new auditor link.', 'tbsh-compliance-audit-logger' ), array( 'status' => 409 ) );
			}
		}
		if ( untrailingslashit( strtolower( home_url() ) ) === $base ) {
			return new \WP_Error( 'tbsh_cal_network_self', __( 'This is the current site. Connect other sites here.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}

		$label = sanitize_text_field( (string) $label );
		$site  = array(
			'id'           => wp_generate_password( 12, false, false ),
			'label'        => '' !== $label ? mb_substr( $label, 0, 80 ) : (string) wp_parse_url( $base, PHP_URL_HOST ),
			'url'          => $base,
			'token'        => Crypto::encrypt( $token, 'network' ),
			'added_at'     => Time::now(),
			'status'       => 'pending',
			'summary'      => null,
			'last_checked' => null,
			'last_error'   => '',
		);

		// Confirm the link works before saving it.
		$probe = self::request( $site, '/readiness' );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}

		$sites[ $site['id'] ] = $site;
		self::save( $sites );
		self::log( 'network_site_added', __( 'Site connected to the network', 'tbsh-compliance-audit-logger' ), $site );
		return self::refresh( $site['id'] );
	}

	/**
	 * Replace a site's auditor link (after it expired or was revoked).
	 *
	 * @param string $id   Site ID.
	 * @param string $link New auditor link.
	 * @return array|\WP_Error Site.
	 */
	public static function replace_link( $id, $link ) {
		$sites = self::stored();
		if ( ! isset( $sites[ $id ] ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Site not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$parsed = self::parse_link( $link );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		list( $base, $token ) = $parsed;
		if ( $base !== $sites[ $id ]['url'] ) {
			return new \WP_Error( 'tbsh_cal_network_link', __( 'This link belongs to a different site. Connect that site separately.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}
		$site          = $sites[ $id ];
		$site['token'] = Crypto::encrypt( $token, 'network' );
		$probe         = self::request( $site, '/readiness' );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		$sites[ $id ] = $site;
		self::save( $sites );
		self::log( 'network_link_replaced', __( 'Network site link replaced', 'tbsh-compliance-audit-logger' ), $site );
		return self::refresh( $id );
	}

	/**
	 * Disconnect a site (the auditor link on that site stays valid until revoked there).
	 *
	 * @param string $id Site ID.
	 * @return true|\WP_Error
	 */
	public static function remove( $id ) {
		$sites = self::stored();
		if ( ! isset( $sites[ $id ] ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Site not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$site = $sites[ $id ];
		unset( $sites[ $id ] );
		self::save( $sites );
		self::log( 'network_site_removed', __( 'Site disconnected from the network', 'tbsh-compliance-audit-logger' ), $site );
		return true;
	}

	/**
	 * Refresh one site's summary.
	 *
	 * @param string $id Site ID.
	 * @return array|\WP_Error Site (public view).
	 */
	public static function refresh( $id ) {
		$sites = self::stored();
		if ( ! isset( $sites[ $id ] ) ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Site not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$site    = $sites[ $id ];
		$summary = self::collect( $site );
		if ( is_wp_error( $summary ) ) {
			$site['status']        = 'error';
			$site['last_error']    = $summary->get_error_message();
			$site['last_error_at'] = Time::now();
		} else {
			$site['status']       = 'ok';
			$site['summary']      = $summary;
			$site['last_checked'] = Time::now();
			$site['last_error']   = '';
		}
		// Re-read before saving: another request may have changed the list meanwhile.
		$latest = self::stored();
		if ( isset( $latest[ $id ] ) ) {
			$latest[ $id ] = $site;
			self::save( $latest );
		}
		return self::public_view( $site );
	}

	/**
	 * Cron and "Refresh all": refresh the stalest sites within a time budget.
	 *
	 * @return array Sites (public view).
	 */
	public static function refresh_all() {
		$sites = self::stored();
		uasort( $sites, function ( $a, $b ) {
			return strcmp( (string) $a['last_checked'], (string) $b['last_checked'] );
		} );
		$deadline = microtime( true ) + self::RUN_BUDGET;
		foreach ( array_keys( $sites ) as $id ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			self::refresh( $id );
		}
		return self::all();
	}

	/**
	 * Gather a site's summary from its read-only endpoints.
	 *
	 * @param array $site Site.
	 * @return array|\WP_Error
	 */
	private static function collect( array $site ) {
		$dashboard = self::request( $site, '/dashboard' );
		if ( is_wp_error( $dashboard ) ) {
			return $dashboard;
		}
		$readiness = self::request( $site, '/readiness' );
		$incidents = self::request( $site, '/incidents', array( 'status' => 'active', 'per_page' => 1 ) );
		$recent    = self::request( $site, '/logs', array( 'severity' => 'error,critical', 'per_page' => 5 ) );
		if ( is_wp_error( $recent ) ) {
			// Older plugin versions accept a single severity only.
			$recent = self::request( $site, '/logs', array( 'severity' => 'critical', 'per_page' => 5 ) );
		}

		$integrity = isset( $dashboard['integrity_status'] ) && is_array( $dashboard['integrity_status'] ) ? $dashboard['integrity_status'] : array();
		$latest    = array();
		if ( ! is_wp_error( $recent ) && ! empty( $recent['logs'] ) ) {
			foreach ( array_slice( (array) $recent['logs'], 0, 5 ) as $row ) {
				$row      = (array) $row;
				$latest[] = array(
					'created_at'  => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
					'event_title' => sanitize_text_field( (string) ( $row['event_title'] ?? '' ) ),
					'severity'    => sanitize_key( (string) ( $row['severity'] ?? '' ) ),
					'username'    => sanitize_text_field( (string) ( $row['username'] ?? '' ) ),
				);
			}
		}

		return array(
			'readiness'       => is_wp_error( $readiness ) || ! isset( $readiness['overall'] ) ? null : (int) $readiness['overall'],
			'events_today'    => (int) ( $dashboard['events_today'] ?? 0 ),
			'total_events'    => (int) ( $dashboard['total_events'] ?? 0 ),
			'critical_events' => (int) ( $dashboard['critical_events'] ?? 0 ),
			'failed_logins'   => (int) ( $dashboard['failed_logins'] ?? 0 ),
			'integrity'       => sanitize_key( (string) ( $integrity['status'] ?? 'unverified' ) ),
			'verified_at'     => sanitize_text_field( (string) ( $integrity['verification_date'] ?? '' ) ),
			'open_incidents'  => is_wp_error( $incidents ) ? null : (int) ( $incidents['total'] ?? 0 ),
			'latest'          => $latest,
		);
	}

	/**
	 * GET a read-only endpoint of a connected site.
	 *
	 * @param array  $site  Site (with encrypted token).
	 * @param string $path  Route relative to the plugin namespace.
	 * @param array  $query Query args.
	 * @return array|\WP_Error Decoded JSON.
	 */
	private static function request( array $site, $path, array $query = array() ) {
		$token = Crypto::decrypt( $site['token'], 'network' );
		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error( 'tbsh_cal_network_key', __( 'The stored link could not be decrypted (the encryption key changed). Replace the link.', 'tbsh-compliance-audit-logger' ) );
		}
		// ?rest_route= works whatever the other site's permalink settings are.
		$url  = add_query_arg( array_merge( array( 'rest_route' => self::NS . $path ), $query ), $site['url'] . '/' );
		$args = array(
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'X-TBSH-Auditor-Token' => $token,
				'Accept'               => 'application/json',
			),
		);
		$response = self::allow_private() ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'tbsh_cal_network_unreachable', sprintf( __( 'The site could not be reached: %s', 'tbsh-compliance-audit-logger' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $body ) ) {
			return $body;
		}
		switch ( $code ) {
			case 301:
			case 302:
			case 307:
			case 308:
				$message = __( 'The site redirected the request. Create the auditor link on the address the site redirects to, for example with or without “www”.', 'tbsh-compliance-audit-logger' );
				break;
			case 401:
				$message = __( 'The auditor link was revoked or has expired. Create a new link on that site and use “Replace link”.', 'tbsh-compliance-audit-logger' );
				break;
			case 403:
				$message = __( 'The auditor link is not allowed to read this information.', 'tbsh-compliance-audit-logger' );
				break;
			case 404:
				$message = __( 'Compliance Audit Trail was not found on that site, or it runs an older version.', 'tbsh-compliance-audit-logger' );
				break;
			case 429:
				$message = __( 'The site is limiting requests. Try again later.', 'tbsh-compliance-audit-logger' );
				break;
			default:
				/* translators: %d: HTTP status code */
				$message = sprintf( __( 'The site answered with HTTP %d.', 'tbsh-compliance-audit-logger' ), $code );
		}
		return new \WP_Error( 'tbsh_cal_network_http', $message, array( 'status' => 400 ) );
	}

	/**
	 * Allow private, loopback or plain-http addresses (sites on an internal network).
	 *
	 * @return bool
	 */
	private static function allow_private() {
		/**
		 * Allow connecting sites on private networks or plain http (off by default).
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'tbsh_cal_allow_private_network_sites', false );
	}

	/**
	 * Audit-log a network change.
	 *
	 * @param string $type  Event type.
	 * @param string $title Title.
	 * @param array  $site  Site.
	 */
	private static function log( $type, $title, array $site ) {
		Logger::log(
			$type,
			'Configuration Management',
			'notice',
			$title,
			/* translators: 1: site name, 2: site address */
			sprintf( __( '%1$s (%2$s)', 'tbsh-compliance-audit-logger' ), $site['label'], $site['url'] ),
			array(
				'object_type' => 'network_site',
				'object_id'   => $site['id'],
				'metadata'    => array( 'url' => $site['url'], 'label' => $site['label'] ),
			)
		);
	}
}
