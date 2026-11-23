<?php
namespace TBSHComplianceAuditLogger\API;

use TBSHComplianceAuditLogger\AI\AIAdapter;
use TBSHComplianceAuditLogger\Incidents\IncidentRepository;
use TBSHComplianceAuditLogger\Incidents\Nis2Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incidents, notes, evidence links, NIS2 tracking and AI analysis.
 */
class IncidentsController extends BaseController {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$view   = $this->cap( 'tbsh_cal_view_incidents' );
		$manage = $this->cap( 'tbsh_cal_manage_incidents' );
		$fields = array(
			'event_title'         => array( 'type' => 'string' ),
			'description'         => array( 'type' => 'string' ),
			'severity'            => array( 'type' => 'string', 'enum' => IncidentRepository::SEVERITIES ),
			'status'              => array( 'type' => 'string', 'enum' => IncidentRepository::STATUSES ),
			'incident_type'       => array( 'type' => 'string' ),
			'affected_systems'    => array( 'type' => 'string' ),
			'resolution_notes'    => array( 'type' => 'string' ),
			'root_cause'          => array( 'type' => 'string' ),
			'impact_assessment'   => array( 'type' => 'string' ),
			'compliance_mappings' => array( 'type' => 'string' ),
			'assigned_user_id'    => array( 'type' => 'integer', 'minimum' => 0 ),
			'detected_at'         => array( 'type' => 'string' ),
			'nis2_significant'    => array( 'type' => 'boolean' ),
			'cross_border'        => array( 'type' => 'boolean' ),
		);

		register_rest_route( $this->namespace, '/incidents', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					return $this->success( IncidentRepository::list( $r->get_params() ) );
				},
				'permission_callback' => $view,
				'args'                => $this->pagination_args() + array(
					'status'   => array( 'type' => 'string', 'enum' => array_merge( array( '', 'active' ), IncidentRepository::STATUSES ) ),
					'severity' => array( 'type' => 'string', 'enum' => array_merge( array( '' ), IncidentRepository::SEVERITIES ) ),
					'type'     => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					$id = IncidentRepository::create( $this->only( $r, array_keys( $this->fields() ) ) + array( 'source' => 'manual' ) );
					return is_wp_error( $id ) ? $id : $this->success( IncidentRepository::get( $id ), 201 );
				},
				'permission_callback' => $manage,
				'args'                => $fields,
			),
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					$item = IncidentRepository::get( $r['id'] );
					return $item ? $this->success( $item ) : $this->error( 'tbsh_cal_not_found', __( 'Incident not found.', 'tbsh-compliance-audit-logger' ), 404 );
				},
				'permission_callback' => $view,
				'args'                => $this->id_arg(),
			),
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => function ( $r ) {
					return $this->respond( IncidentRepository::update( $r['id'], $this->only( $r, array_keys( $this->fields() ) ) ) );
				},
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + $fields,
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => function ( $r ) {
					return $this->respond( IncidentRepository::delete( $r['id'] ) );
				},
				'permission_callback' => $manage,
				'args'                => $this->id_arg(),
			),
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/notes', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					return $this->success( IncidentRepository::notes( $r['id'] ) );
				},
				'permission_callback' => $view,
				'args'                => $this->id_arg(),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					return $this->respond( IncidentRepository::add_note( $r['id'], (string) $r->get_param( 'note_content' ) ), 201 );
				},
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + array( 'note_content' => array( 'type' => 'string', 'required' => true ) ),
			),
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/notes/(?P<note_id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'args'                => $this->id_arg() + array( 'note_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ) ),
			'callback'            => function ( $r ) {
				return $this->success( IncidentRepository::delete_note( $r['id'], $r['note_id'] ) );
			},
			'permission_callback' => $manage,
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/evidence', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function ( $r ) {
					return $this->success( IncidentRepository::evidence( $r['id'] ) );
				},
				'permission_callback' => $view,
				'args'                => $this->id_arg(),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => function ( $r ) {
					return $this->respond( IncidentRepository::attach_evidence( $r['id'], (string) $r->get_param( 'evidence_uuid' ) ), 201 );
				},
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + array( 'evidence_uuid' => array( 'type' => 'string', 'required' => true ) ),
			),
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/evidence/(?P<link_id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'args'                => $this->id_arg() + array( 'link_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ) ),
			'callback'            => function ( $r ) {
				return $this->success( IncidentRepository::detach_evidence( $r['id'], $r['link_id'] ) );
			},
			'permission_callback' => $manage,
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/nis2', array(
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => function ( $r ) {
					return $this->respond( Nis2Tracker::update( $r['id'], $this->only( $r, array( 'significant', 'cross_border', 'stages', 'remediation', 'authority', 'detected_at' ) ) ) );
				},
				'permission_callback' => $manage,
				'args'                => $this->id_arg() + array(
					'significant'  => array( 'type' => 'boolean' ),
					'cross_border' => array( 'type' => 'boolean' ),
					'stages'       => array( 'type' => 'object' ),
					'remediation'  => array( 'type' => 'string' ),
					'authority'    => array( 'type' => 'string' ),
					'detected_at'  => array( 'type' => 'string' ),
				),
			),
		) );

		register_rest_route( $this->namespace, '/incidents/(?P<id>\d+)/analyze', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => function ( $r ) {
				return $this->respond( AIAdapter::analyze( $r['id'] ) );
			},
			'permission_callback' => $manage,
			'args'                => $this->id_arg(),
		) );
	}

	/**
	 * Writable field names.
	 *
	 * @return array
	 */
	private function fields() {
		return array_flip( array( 'event_title', 'description', 'severity', 'status', 'incident_type', 'affected_systems', 'resolution_notes', 'root_cause', 'impact_assessment', 'compliance_mappings', 'assigned_user_id', 'detected_at', 'nis2_significant', 'cross_border' ) );
	}

	/**
	 * Only the listed params that were actually sent.
	 *
	 * @param \WP_REST_Request $r    Request.
	 * @param array            $keys Keys.
	 * @return array
	 */
	private function only( $r, array $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			if ( $r->has_param( $key ) ) {
				$out[ $key ] = $r->get_param( $key );
			}
		}
		return $out;
	}
}
