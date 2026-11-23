<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Alerts\AlertManager;
use TBSHComplianceAuditLogger\Analytics\AnomalyDetector;
use TBSHComplianceAuditLogger\Analytics\BehaviorBaseline;
use TBSHComplianceAuditLogger\Analytics\Trends;
use TBSHComplianceAuditLogger\Compliance\AuditorAccess;
use TBSHComplianceAuditLogger\Vaults\Receiver;
use TBSHComplianceAuditLogger\Vaults\VaultManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Off-site vaults, vault receiver, alert history, analytics and auditor links.
 */
class OperationsController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$vaults = $this->cap( 'tbsh_cal_manage_vaults' );
		$vault_fields = array(
			'vault_type' => array( 'type' => 'string', 'enum' => array_keys( VaultManager::FIELDS ) ),
			'label'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'is_active'  => array( 'type' => 'boolean' ),
			'schedule'   => array( 'type' => 'string', 'enum' => array_keys( VaultManager::SCHEDULES ) ),
			'config'     => array( 'type' => 'object' ),
			'clear'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
		);

		register_rest_route( $this->namespace, '/vaults', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function () {
					return $this->success( VaultManager::list() );
				},
				'permission_callback' => $vaults,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					return $this->respond( VaultManager::save( $r->get_params() ), 201 );
				},
				'permission_callback' => $vaults,
				'args'                => $vault_fields,
			),
		) );
		register_rest_route( $this->namespace, '/vaults/history', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return $this->success( VaultManager::history( $r->get_params() ) );
			},
			'permission_callback' => $vaults,
			'args'                => $this->pagination_args() + array( 'vault_id' => array( 'type' => 'integer' ) ),
		) );
		register_rest_route( $this->namespace, '/vaults/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => function ( $r ) {
					return $this->respond( VaultManager::save( $r->get_params(), (int) $r['id'] ) );
				},
				'permission_callback' => $vaults,
				'args'                => $this->id_arg() + $vault_fields,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => function ( $r ) {
					return $this->respond( VaultManager::delete( $r['id'] ) );
				},
				'permission_callback' => $vaults,
				'args'                => $this->id_arg(),
			),
		) );
		register_rest_route( $this->namespace, '/vaults/(?P<id>\d+)/test', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => function ( $r ) {
				return $this->respond( VaultManager::test( $r['id'] ) );
			},
			'permission_callback' => $vaults,
			'args'                => $this->id_arg(),
		) );
		register_rest_route( $this->namespace, '/vaults/(?P<id>\d+)/sync', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => function ( $r ) {
				return $this->respond( VaultManager::sync( $r['id'] ) );
			},
			'permission_callback' => $vaults,
			'args'                => $this->id_arg(),
		) );

		// Public endpoint: authenticated inside the handler by HMAC signature.
		register_rest_route( $this->namespace, '/vault-receiver', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( Receiver::class, 'handle' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $this->namespace, '/alerts/log', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return $this->success( AlertManager::history( $r->get_params() ) );
			},
			'permission_callback' => $this->cap( 'tbsh_cal_view_alerts' ),
			'args'                => $this->pagination_args() + array(
				'status'  => array( 'type' => 'string' ),
				'channel' => array( 'type' => 'string' ),
			),
		) );

		register_rest_route( $this->namespace, '/analytics/trends', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return $this->success( Trends::get( (int) $r->get_param( 'days' ) ) );
			},
			'permission_callback' => $this->cap( 'tbsh_cal_view_analytics' ),
			'args'                => array( 'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 7, 'maximum' => 90 ) ),
		) );

		register_rest_route( $this->namespace, '/analytics/anomalies', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					return $this->success( AnomalyDetector::list( $r->get_params() ) );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_view_analytics' ),
				'args'                => $this->pagination_args() + array( 'user_id' => array( 'type' => 'integer' ) ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function () {
					/* translators: %d: users */
					return $this->success( array( 'updated' => BehaviorBaseline::update_baselines(), 'message' => __( 'Behaviour baselines rebuilt.', 'tbsh-compliance-audit-logger' ) ) );
				},
				'permission_callback' => $this->cap( 'tbsh_cal_manage_settings' ),
			),
		) );

		$auditor = $this->cap( 'tbsh_cal_manage_auditor_access' );
		register_rest_route( $this->namespace, '/auditor/links', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function () {
					return $this->success( AuditorAccess::list() );
				},
				'permission_callback' => $auditor,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					return $this->respond( AuditorAccess::create( (string) $r->get_param( 'label' ), (int) $r->get_param( 'days' ) ), 201 );
				},
				'permission_callback' => $auditor,
				'args'                => array(
					'label' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'days'  => array( 'type' => 'integer', 'default' => 7, 'minimum' => 1, 'maximum' => AuditorAccess::MAX_DAYS ),
				),
			),
		) );
		register_rest_route( $this->namespace, '/auditor/links/(?P<id>\d+)/revoke', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => function ( $r ) {
				return $this->respond( AuditorAccess::revoke( $r['id'] ) );
			},
			'permission_callback' => $auditor,
			'args'                => $this->id_arg(),
		) );
	}
}
