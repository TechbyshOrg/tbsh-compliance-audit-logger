<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Security\SessionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Active login sessions: list, end one, end all for a user.
 */
class SessionsController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/sessions', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_sessions' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_sessions' ),
				'args'                => array(
					'search' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/sessions/(?P<user_id>\d+)/(?P<session_id>[a-f0-9]{24})', array(
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'end_session' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_sessions' ),
				'args'                => array(
					'user_id'    => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'session_id' => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{24}$' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/sessions/(?P<user_id>\d+)/logout-all', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'end_all' ),
				'permission_callback' => $this->cap( 'tbsh_cal_manage_sessions' ),
				'args'                => array(
					'user_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			),
		) );
	}

	/**
	 * List sessions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_sessions( $request ) {
		return $this->success( SessionManager::all( (string) $request->get_param( 'search' ) ) );
	}

	/**
	 * End one session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function end_session( $request ) {
		$result = SessionManager::end( (int) $request->get_param( 'user_id' ), (string) $request->get_param( 'session_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->success( array( 'message' => __( 'Session ended. The user is signed out on that device.', 'tbsh-compliance-audit-logger' ) ) );
	}

	/**
	 * End all sessions of a user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function end_all( $request ) {
		$result = SessionManager::end_all( (int) $request->get_param( 'user_id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->success( array(
			'ended'   => $result,
			/* translators: %d: number of sessions */
			'message' => sprintf( _n( '%d session ended.', '%d sessions ended.', $result, 'tbsh-compliance-audit-logger' ), $result ),
		) );
	}
}
