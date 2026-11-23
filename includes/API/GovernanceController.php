<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Resilience\DrillRegister;
use TBSHComplianceAuditLogger\Risk\RiskRegister;
use TBSHComplianceAuditLogger\Vendors\SupplyChain;
use TBSHComplianceAuditLogger\Vendors\VendorRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Risk register, vendor register, supply chain and resilience drills.
 */
class GovernanceController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$this->crud(
			'/risks',
			RiskRegister::class,
			'tbsh_cal_view_risks',
			'tbsh_cal_manage_risks',
			array(
				'title'               => array( 'type' => 'string' ),
				'description'         => array( 'type' => 'string' ),
				'category'            => array( 'type' => 'string' ),
				'likelihood'          => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
				'impact'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ),
				'mitigation_plan'     => array( 'type' => 'string' ),
				'owner_id'            => array( 'type' => 'integer', 'minimum' => 0 ),
				'review_date'         => array( 'type' => array( 'string', 'null' ) ),
				'status'              => array( 'type' => 'string', 'enum' => RiskRegister::STATUSES ),
				'compliance_mappings' => array( 'type' => 'string' ),
			),
			array(
				'status'     => array( 'type' => 'string' ),
				'category'   => array( 'type' => 'string' ),
				'likelihood' => array( 'type' => 'integer' ),
				'impact'     => array( 'type' => 'integer' ),
			)
		);

		register_rest_route( $this->namespace, '/vendors/supply-chain', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return $this->success( SupplyChain::inventory( (bool) $r->get_param( 'refresh' ) ) );
			},
			'permission_callback' => $this->cap( 'tbsh_cal_view_vendors' ),
			'args'                => array( 'refresh' => array( 'type' => 'boolean', 'default' => false ) ),
		) );

		$this->crud(
			'/vendors',
			VendorRegister::class,
			'tbsh_cal_view_vendors',
			'tbsh_cal_manage_vendors',
			array(
				'name'                  => array( 'type' => 'string' ),
				'service_type'          => array( 'type' => 'string' ),
				'data_access_level'     => array( 'type' => 'string', 'enum' => VendorRegister::ACCESS ),
				'criticality'           => array( 'type' => 'string', 'enum' => VendorRegister::LEVELS ),
				'risk_rating'           => array( 'type' => 'string', 'enum' => VendorRegister::LEVELS ),
				'contact_info'          => array( 'type' => 'string' ),
				'contract_renewal_date' => array( 'type' => array( 'string', 'null' ) ),
				'review_date'           => array( 'type' => array( 'string', 'null' ) ),
				'status'                => array( 'type' => 'string', 'enum' => VendorRegister::STATUSES ),
				'linked_slug'           => array( 'type' => 'string' ),
			),
			array(
				'criticality' => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
			)
		);

		register_rest_route( $this->namespace, '/resilience/metrics', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function () {
				return $this->success( DrillRegister::metrics() );
			},
			'permission_callback' => $this->cap( 'tbsh_cal_view_analytics' ),
		) );

		$this->crud(
			'/resilience/drills',
			DrillRegister::class,
			'tbsh_cal_view_analytics',
			'tbsh_cal_manage_risks',
			array(
				'title'          => array( 'type' => 'string' ),
				'scenario'       => array( 'type' => 'string', 'enum' => DrillRegister::SCENARIOS ),
				'status'         => array( 'type' => 'string', 'enum' => DrillRegister::STATUSES ),
				'scheduled_at'   => array( 'type' => array( 'string', 'null' ) ),
				'completed_at'   => array( 'type' => array( 'string', 'null' ) ),
				'rto_target_min' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
				'rto_actual_min' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
				'rpo_target_min' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
				'rpo_actual_min' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
				'outcome'        => array( 'type' => 'string', 'enum' => DrillRegister::OUTCOMES ),
				'notes'          => array( 'type' => 'string' ),
			),
			array(
				'status'   => array( 'type' => 'string' ),
				'scenario' => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Register list/create/get/update/delete routes for a register class.
	 *
	 * @param string $base     Route base.
	 * @param string $class    Register class with list/get/create/update/delete.
	 * @param string $view_cap View capability.
	 * @param string $edit_cap Manage capability.
	 * @param array  $fields   Writable field schema.
	 * @param array  $filters  List filter args.
	 */
	private function crud( $base, $class, $view_cap, $edit_cap, array $fields, array $filters ) {
		$view = $this->cap( $view_cap );
		$edit = $this->cap( $edit_cap );
		$pick = function ( $r ) use ( $fields ) {
			$out = array();
			foreach ( array_keys( $fields ) as $key ) {
				if ( $r->has_param( $key ) ) {
					$out[ $key ] = $r->get_param( $key );
				}
			}
			return $out;
		};

		register_rest_route( $this->namespace, $base, array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) use ( $class ) {
					return $this->success( $class::list( $r->get_params() ) );
				},
				'permission_callback' => $view,
				'args'                => $this->pagination_args() + $filters,
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) use ( $class, $pick ) {
					return $this->respond( $class::create( $pick( $r ) ), 201 );
				},
				'permission_callback' => $edit,
				'args'                => $fields,
			),
		) );

		register_rest_route( $this->namespace, $base . '/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) use ( $class ) {
					$item = $class::get( $r['id'] );
					return $item ? $this->success( $item ) : $this->error( 'tbsh_cal_not_found', __( 'Not found.', 'tbsh-compliance-audit-logger' ), 404 );
				},
				'permission_callback' => $view,
				'args'                => $this->id_arg(),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => function ( $r ) use ( $class, $pick ) {
					return $this->respond( $class::update( $r['id'], $pick( $r ) ) );
				},
				'permission_callback' => $edit,
				'args'                => $this->id_arg() + $fields,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => function ( $r ) use ( $class ) {
					return $this->respond( $class::delete( $r['id'] ) );
				},
				'permission_callback' => $edit,
				'args'                => $this->id_arg(),
			),
		) );
	}
}
