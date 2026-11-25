<?php
namespace TBSHComplianceAuditLogger\Security;

use TBSHComplianceAuditLogger\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and ends WordPress login sessions.
 *
 * Works with core's default session store (user meta "session_tokens"). Sessions are
 * exposed by an opaque ID derived from the stored verifier, so neither tokens nor
 * verifiers leave the server. When another plugin replaces the session manager,
 * sessions can still be listed and ended per user, but not one by one.
 */
class SessionManager {

	/**
	 * Most users scanned for sessions.
	 */
	const MAX_USERS = 500;

	/**
	 * Whether core's meta-based session store is in use.
	 *
	 * @return bool
	 */
	public static function supports_single_logout() {
		/** This filter is documented in wp-includes/class-wp-session-tokens.php */
		return 'WP_User_Meta_Session_Tokens' === apply_filters( 'session_token_manager', 'WP_User_Meta_Session_Tokens' );
	}

	/**
	 * Active sessions of users on this site.
	 *
	 * @param string $search Optional username/display name filter.
	 * @return array{sessions: array, users: int, single_logout: bool, truncated: bool}
	 */
	public static function all( $search = '' ) {
		$args = array(
			'meta_key'     => 'session_tokens', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
			'number'       => self::MAX_USERS + 1,
			'fields'       => 'all',
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'display_name', 'user_nicename' );
		}

		$users     = get_users( $args );
		$truncated = count( $users ) > self::MAX_USERS;
		$users     = array_slice( $users, 0, self::MAX_USERS );
		$current   = self::current_verifier();
		$single    = self::supports_single_logout();
		$mask      = self::mask_ips();
		$sessions  = array();

		foreach ( $users as $user ) {
			foreach ( self::user_sessions( $user->ID ) as $verifier => $session ) {
				$sessions[] = array(
					'id'           => self::public_id( $user->ID, $verifier ),
					'user_id'      => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'roles'        => array_values( (array) $user->roles ),
					'login'        => isset( $session['login'] ) ? gmdate( 'c', (int) $session['login'] ) : null,
					'expiration'   => isset( $session['expiration'] ) ? gmdate( 'c', (int) $session['expiration'] ) : null,
					'ip'           => self::ip_label( $session['ip'] ?? '', $mask ),
					'user_agent'   => wp_html_excerpt( sanitize_text_field( (string) ( $session['ua'] ?? '' ) ), 160, '…' ),
					'is_current'   => '' !== $current && hash_equals( $current, (string) $verifier ),
					'can_end'      => current_user_can( 'edit_user', $user->ID ),
				);
			}
		}

		usort( $sessions, function ( $a, $b ) {
			return strcmp( (string) $b['login'], (string) $a['login'] );
		} );

		return array(
			'sessions'      => $sessions,
			'users'         => count( array_unique( wp_list_pluck( $sessions, 'user_id' ) ) ),
			'single_logout' => $single,
			'truncated'     => $truncated,
		);
	}

	/**
	 * End one session.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $session_id Public session ID.
	 * @return true|\WP_Error
	 */
	public static function end( $user_id, $session_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'User not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new \WP_Error( 'tbsh_cal_forbidden', __( 'You are not allowed to end sessions of this user.', 'tbsh-compliance-audit-logger' ), array( 'status' => 403 ) );
		}
		if ( ! self::supports_single_logout() ) {
			return new \WP_Error( 'tbsh_cal_unsupported', __( 'Another plugin manages login sessions, so sessions can only be ended per user.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}

		$stored = get_user_meta( $user->ID, 'session_tokens', true );
		$stored = is_array( $stored ) ? $stored : array();
		$match  = null;
		foreach ( array_keys( $stored ) as $verifier ) {
			if ( hash_equals( self::public_id( $user->ID, $verifier ), (string) $session_id ) ) {
				$match = $verifier;
				break;
			}
		}
		if ( null === $match ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'This session has already ended.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		if ( hash_equals( self::current_verifier(), (string) $match ) ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'This is your current session. Use "Log out" in WordPress instead.', 'tbsh-compliance-audit-logger' ), array( 'status' => 400 ) );
		}

		unset( $stored[ $match ] );
		if ( $stored ) {
			update_user_meta( $user->ID, 'session_tokens', $stored );
		} else {
			delete_user_meta( $user->ID, 'session_tokens' );
		}

		Logger::log(
			'session_terminated',
			'Access Control',
			'warning',
			__( 'Login session ended', 'tbsh-compliance-audit-logger' ),
			/* translators: %s: username */
			sprintf( __( 'A login session of user "%s" was ended by an administrator.', 'tbsh-compliance-audit-logger' ), $user->user_login ),
			array(
				'object_type'     => 'user',
				'object_id'       => $user->ID,
				'compliance_tags' => 'Access Control, Incident Response',
			)
		);
		return true;
	}

	/**
	 * End every session of a user (all but the caller's own when ending their own sessions).
	 *
	 * @param int $user_id User ID.
	 * @return int|\WP_Error Number of sessions ended.
	 */
	public static function end_all( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'User not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return new \WP_Error( 'tbsh_cal_forbidden', __( 'You are not allowed to end sessions of this user.', 'tbsh-compliance-audit-logger' ), array( 'status' => 403 ) );
		}

		$manager = \WP_Session_Tokens::get_instance( $user->ID );
		$before  = count( $manager->get_all() );
		if ( get_current_user_id() === $user->ID ) {
			$manager->destroy_others( wp_get_session_token() );
		} else {
			$manager->destroy_all();
		}
		$ended = max( 0, $before - count( $manager->get_all() ) );

		Logger::log(
			'sessions_terminated_all',
			'Access Control',
			'warning',
			__( 'All login sessions ended', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: number of sessions, 2: username */
			sprintf( _n( '%1$d login session of user "%2$s" was ended by an administrator.', '%1$d login sessions of user "%2$s" were ended by an administrator.', $ended, 'tbsh-compliance-audit-logger' ), $ended, $user->user_login ),
			array(
				'object_type'     => 'user',
				'object_id'       => $user->ID,
				'compliance_tags' => 'Access Control, Incident Response',
				'metadata'        => array( 'ended' => $ended ),
			)
		);
		return $ended;
	}

	/**
	 * Unexpired sessions of a user, keyed by verifier.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	private static function user_sessions( $user_id ) {
		if ( self::supports_single_logout() ) {
			$stored = get_user_meta( $user_id, 'session_tokens', true );
			$stored = is_array( $stored ) ? $stored : array();
			return array_filter( $stored, function ( $session ) {
				return is_array( $session ) && isset( $session['expiration'] ) && (int) $session['expiration'] >= time();
			} );
		}

		$out = array();
		foreach ( \WP_Session_Tokens::get_instance( $user_id )->get_all() as $index => $session ) {
			$out[ 'idx' . $index ] = $session;
		}
		return $out;
	}

	/**
	 * Opaque, stable ID for a session.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Stored verifier.
	 * @return string
	 */
	private static function public_id( $user_id, $verifier ) {
		return substr( hash_hmac( 'sha256', (int) $user_id . '|' . $verifier, wp_salt( 'auth' ) ), 0, 24 );
	}

	/**
	 * Verifier of the caller's own session.
	 *
	 * @return string
	 */
	private static function current_verifier() {
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return '';
		}
		return function_exists( 'hash' ) ? hash( 'sha256', $token ) : sha1( $token );
	}

	/**
	 * Whether IP addresses are shown truncated (follows the anonymisation setting).
	 *
	 * @return bool
	 */
	private static function mask_ips() {
		$settings = get_option( 'tbsh_cal_settings', array() );
		return ! isset( $settings['anonymize_ips'] ) || ! empty( $settings['anonymize_ips'] );
	}

	/**
	 * IP for display: network part only when masking.
	 *
	 * @param string $ip   IP.
	 * @param bool   $mask Whether to truncate.
	 * @return string
	 */
	private static function ip_label( $ip, $mask ) {
		$ip = (string) $ip;
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		return $mask ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}
}
