<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Network\SiteNetwork;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site network endpoints. Viewing needs tbsh_cal_view_analytics; connecting, replacing links,
 * disconnecting and refreshing need tbsh_cal_manage_settings. Auditor links cannot reach these routes.
 */
class NetworkController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$id   = array( 'type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9]{12}$' );
		$link = array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' );

		register_rest_route( $this->namespace, '/network', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function () {
					return $this->success( array( 'sites' => SiteNetwork::all() ) );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_view_analytics' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					$site = SiteNetwork::add( (string) $request->get_param( 'label' ), (string) $request->get_param( 'link' ) );
					return is_wp_error( $site ) ? $site : $this->success( $site, 201 );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
				'args'                => array(
					'label' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
					'link'  => $link,
				),
			),
		) );

		register_rest_route( $this->namespace, '/network/refresh', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => function () {
				return $this->success( array( 'sites' => SiteNetwork::refresh_all() ) );
			},
			'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
		) );

		register_rest_route( $this->namespace, '/network/(?P<id>[A-Za-z0-9]{12})', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $request ) {
					$id   = (string) $request['id'];
					$link = (string) $request->get_param( 'link' );
					$site = '' !== $link ? SiteNetwork::replace_link( $id, $link ) : SiteNetwork::refresh( $id );
					return is_wp_error( $site ) ? $site : $this->success( $site );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
				'args'                => array(
					'id'   => $id,
					'link' => array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => function ( $request ) {
					$result = SiteNetwork::remove( (string) $request['id'] );
					return is_wp_error( $result ) ? $result : $this->success( array( 'deleted' => true ) );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
				'args'                => array( 'id' => $id ),
			),
		) );
	}
}
