<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\Reporting\ReportGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance reports.
 */
class ReportsController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$view   = $this->cap( 'tbsh_cal_view_reports' );
		$manage = $this->cap( 'tbsh_cal_manage_reports' );

		register_rest_route( $this->namespace, '/reports', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					$list          = ReportGenerator::list( $r->get_params() );
					$list['types'] = ReportGenerator::titles();
					return $this->success( $list );
				},
				'permission_callback' => $view,
				'args'                => $this->pagination_args() + array( 'type' => array( 'type' => 'string' ) ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					return $this->respond( ReportGenerator::generate( $r->get_params() ), 201 );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'type'         => array( 'type' => 'string', 'required' => true, 'enum' => ReportGenerator::TYPES ),
					'format'       => array( 'type' => 'string', 'enum' => ReportGenerator::FORMATS, 'default' => 'html' ),
					'start_date'   => array( 'type' => 'string' ),
					'end_date'     => array( 'type' => 'string' ),
					'company_name' => array( 'type' => 'string' ),
					'incident_id'  => array( 'type' => 'integer', 'minimum' => 0 ),
					'stage'        => array( 'type' => 'string', 'enum' => array( 'early_warning', 'notification', 'final_report' ) ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/reports/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => function ( $r ) {
				return $this->respond( ReportGenerator::delete( $r['id'] ) );
			},
			'permission_callback' => $manage,
			'args'                => $this->id_arg(),
		) );

		register_rest_route( $this->namespace, '/reports/(?P<id>\d+)/download', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return ReportGenerator::serve( $r['id'] );
			},
			'permission_callback' => $view,
			'args'                => $this->id_arg(),
		) );

		register_rest_route( $this->namespace, '/reports/(?P<id>\d+)/verify', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => function ( $r ) {
				return $this->respond( ReportGenerator::verify( $r['id'] ) );
			},
			'permission_callback' => $view,
			'args'                => $this->id_arg(),
		) );
	}
}
