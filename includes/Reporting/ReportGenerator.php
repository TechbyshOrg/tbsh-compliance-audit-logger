<?php
namespace TBSHComplianceAuditLogger\Reporting;

use TBSHComplianceAuditLogger\Compliance\ReadinessEngine;
use TBSHComplianceAuditLogger\Database\Schema;
use TBSHComplianceAuditLogger\Helpers\Repository;
use TBSHComplianceAuditLogger\Helpers\Storage;
use TBSHComplianceAuditLogger\Helpers\Time;
use TBSHComplianceAuditLogger\Incidents\Nis2Tracker;
use TBSHComplianceAuditLogger\Logging\Logger;
use TBSHComplianceAuditLogger\Resilience\DrillRegister;
use TBSHComplianceAuditLogger\Risk\RiskRegister;
use TBSHComplianceAuditLogger\Vendors\SupplyChain;
use TBSHComplianceAuditLogger\Vendors\VendorRegister;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds, stores, signs, lists, verifies and serves compliance reports.
 */
class ReportGenerator {

	const TYPES   = array( 'executive', 'nis2', 'dora', 'iso27001', 'soc2', 'incidents', 'risk', 'vendors' );
	const FORMATS = array( 'html', 'csv', 'json' );
	const MIME    = array(
		'html' => 'text/html; charset=utf-8',
		'csv'  => 'text/csv; charset=utf-8',
		'json' => 'application/json; charset=utf-8',
	);

	/**
	 * Human titles.
	 *
	 * @return array
	 */
	public static function titles() {
		return array(
			'executive' => __( 'Executive Compliance Summary', 'tbsh-compliance-audit-logger' ),
			'nis2'      => __( 'NIS2 Incident Report', 'tbsh-compliance-audit-logger' ),
			'dora'      => __( 'DORA ICT Risk & Resilience Report', 'tbsh-compliance-audit-logger' ),
			'iso27001'  => __( 'ISO/IEC 27001:2022 Control Evidence Report', 'tbsh-compliance-audit-logger' ),
			'soc2'      => __( 'SOC 2 Control Evidence Report', 'tbsh-compliance-audit-logger' ),
			'incidents' => __( 'Incident Register Report', 'tbsh-compliance-audit-logger' ),
			'risk'      => __( 'Risk Register Report', 'tbsh-compliance-audit-logger' ),
			'vendors'   => __( 'Third-Party & Supply Chain Report', 'tbsh-compliance-audit-logger' ),
		);
	}

	/**
	 * Generate a report.
	 *
	 * @param array $args type, format, start_date, end_date, company_name, incident_id, stage.
	 * @return array|\WP_Error Report record.
	 */
	public static function generate( array $args ) {
		global $wpdb;

		$type   = Repository::enum( $args['type'] ?? '', self::TYPES, '' );
		$format = Repository::enum( $args['format'] ?? 'html', self::FORMATS, 'html' );
		if ( '' === $type ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'Unknown report type.', 'tbsh-compliance-audit-logger' ) );
		}

		$end   = Repository::date_or_null( $args['end_date'] ?? '' ) ?? wp_date( 'Y-m-d' );
		$start = Repository::date_or_null( $args['start_date'] ?? '' ) ?? Time::date_days_ago( 30 );
		if ( $start > $end ) {
			return new \WP_Error( 'tbsh_cal_invalid', __( 'The start date must be before the end date.', 'tbsh-compliance-audit-logger' ) );
		}

		$data = self::build( $type, array(
			'start'        => $start . ' 00:00:00',
			'end'          => $end . ' 23:59:59',
			'start_date'   => $start,
			'end_date'     => $end,
			'company'      => Repository::line( $args['company_name'] ?? '' ),
			'incident_id'  => absint( $args['incident_id'] ?? 0 ),
			'stage'        => sanitize_key( $args['stage'] ?? 'notification' ),
		) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$contents = ReportRenderer::render( $format, $data );
		$dir      = Storage::dir( 'reports' );
		if ( ! $dir ) {
			return new \WP_Error( 'tbsh_cal_storage', __( 'The private report folder could not be created.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}
		$file = Storage::unique_name( 'report', $format );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $dir . $file, $contents ) ) {
			return new \WP_Error( 'tbsh_cal_storage', __( 'The report file could not be written.', 'tbsh-compliance-audit-logger' ), array( 'status' => 500 ) );
		}

		$sig = Signer::sign( $contents );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::table( 'reports' ),
			array(
				'report_type'       => $type,
				'report_title'      => $data['meta']['title'],
				'format'            => $format,
				'file_name'         => $file,
				'status'            => 'generated',
				'content_hash'      => $sig['content_hash'],
				'digital_signature' => $sig['signature'],
				'metadata_json'     => wp_json_encode( array(
					'period'      => array( $start, $end ),
					'chain_head'  => $data['meta']['chain_head'],
					'incident_id' => $data['meta']['incident_id'] ?? null,
					'stage'       => $data['meta']['stage'] ?? null,
					'size'        => strlen( $contents ),
				) ),
				'created_by'        => get_current_user_id(),
				'created_at'        => Time::now(),
			)
		);
		$id = (int) $wpdb->insert_id;

		Logger::log(
			'report_generated',
			'Audit Trail',
			'info',
			__( 'Compliance report generated', 'tbsh-compliance-audit-logger' ),
			/* translators: 1: title, 2: format */
			sprintf( __( '%1$s generated (%2$s).', 'tbsh-compliance-audit-logger' ), $data['meta']['title'], strtoupper( $format ) ),
			array( 'object_type' => 'report', 'object_id' => $id, 'metadata' => array( 'content_hash' => $sig['content_hash'] ) )
		);

		return self::get( $id );
	}

	/**
	 * Report list.
	 *
	 * @param array $args page, per_page, type.
	 * @return array
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'reports' );
		list( $page, $per_page, $offset ) = Repository::page_args( $args );

		$where  = '1=1';
		$params = array( $table );
		if ( ! empty( $args['type'] ) ) {
			$where   .= ' AND report_type = %s';
			$params[] = Repository::enum( $args['type'], self::TYPES, 'executive' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );

		return Repository::paged( array_map( array( __CLASS__, 'format' ), (array) $rows ), $total, $per_page, $page );
	}

	/**
	 * Single report record.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Verify a stored report.
	 *
	 * @param int $id ID.
	 * @return array|\WP_Error
	 */
	public static function verify( $id ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Report not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$path = Storage::path( 'reports', $row->file_name );
		if ( ! $path ) {
			return array( 'valid' => false, 'hash_matches' => false, 'signature_valid' => false, 'message' => __( 'The report file is missing.', 'tbsh-compliance-audit-logger' ) );
		}
		if ( '' === (string) $row->content_hash ) {
			return array( 'valid' => false, 'hash_matches' => false, 'signature_valid' => false, 'message' => __( 'This report was created before signing was available and cannot be verified.', 'tbsh-compliance-audit-logger' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$result            = Signer::verify( file_get_contents( $path ), $row->content_hash, $row->digital_signature );
		$result['message'] = $result['valid']
			? __( 'The report is unchanged and was signed by this site.', 'tbsh-compliance-audit-logger' )
			: __( 'Verification failed: the file was modified or the signature does not belong to this site.', 'tbsh-compliance-audit-logger' );
		return $result;
	}

	/**
	 * Stream a report file to the browser and exit.
	 *
	 * @param int $id ID.
	 * @return \WP_Error|void
	 */
	public static function serve( $id ) {
		$row  = self::row( $id );
		$path = $row ? Storage::path( 'reports', $row->file_name ) : false;
		if ( ! $path ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Report file not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}

		$ext      = Repository::enum( $row->format, self::FORMATS, 'html' );
		$filename = sprintf( 'compliance-report-%s-%s.%s', $row->report_type, substr( (string) $row->created_at, 0, 10 ), $ext );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . self::MIME[ $ext ] );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Delete a report and its file.
	 *
	 * @param int $id ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'tbsh_cal_not_found', __( 'Report not found.', 'tbsh-compliance-audit-logger' ), array( 'status' => 404 ) );
		}
		$path = Storage::path( 'reports', $row->file_name );
		if ( $path ) {
			wp_delete_file( $path );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'reports' ), array( 'id' => (int) $row->id ), array( '%d' ) );
		Logger::log( 'report_deleted', 'Audit Trail', 'notice', __( 'Compliance report deleted', 'tbsh-compliance-audit-logger' ), (string) $row->report_title, array( 'object_type' => 'report', 'object_id' => $row->id ) );
		return true;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	private static function row( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'reports' ), absint( $id ) ) );
	}

	/**
	 * API shape (never exposes paths).
	 *
	 * @param object $row Row.
	 * @return array
	 */
	private static function format( $row ) {
		$meta = Repository::json( $row->metadata_json );
		return array(
			'id'           => (int) $row->id,
			'report_type'  => $row->report_type,
			'title'        => $row->report_title ? $row->report_title : ( self::titles()[ $row->report_type ] ?? $row->report_type ),
			'format'       => $row->format,
			'status'       => $row->status,
			'available'    => (bool) Storage::path( 'reports', (string) $row->file_name ),
			'signed'       => '' !== (string) $row->content_hash,
			'content_hash' => $row->content_hash,
			'period'       => $meta['period'] ?? null,
			'size'         => $meta['size'] ?? null,
			'created_by'   => Repository::user_name( $row->created_by ),
			'created_at'   => $row->created_at,
		);
	}

	/**
	 * Collect the report data structure.
	 *
	 * @param string $type Type.
	 * @param array  $p    Parameters.
	 * @return array|\WP_Error { meta, sections[] } where a section is { title, kind: kv|table|text, ... }.
	 */
	private static function build( $type, array $p ) {
		global $wpdb;
		$title = self::titles()[ $type ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$chain_head = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT integrity_hash FROM %i ORDER BY id DESC LIMIT 1', Schema::table( 'logs' ) ) );

		$meta = array(
			'title'        => $title,
			'type'         => $type,
			'organisation' => '' !== $p['company'] ? $p['company'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site'         => home_url( '/' ),
			'period'       => array( $p['start_date'], $p['end_date'] ),
			'generated_at' => Time::now(),
			'generated_by' => wp_get_current_user()->display_name,
			'chain_head'   => $chain_head,
			'plugin'       => 'Compliance Audit Trail & Evidence Logger ' . TBSH_CAL_VERSION,
		);

		$assessment = ReadinessEngine::assess();
		$sections   = array();

		$readiness = array(
			'title' => __( 'Readiness score', 'tbsh-compliance-audit-logger' ),
			'kind'  => 'table',
			'columns' => array( __( 'Area', 'tbsh-compliance-audit-logger' ), __( 'Score', 'tbsh-compliance-audit-logger' ) ),
			'rows'  => array_merge(
				array( array( __( 'Overall', 'tbsh-compliance-audit-logger' ), $assessment['overall'] . '/100 (' . $assessment['band'] . ')' ) ),
				array_map( function ( $c ) {
					return array( $c['label'], $c['score'] . '/100' );
				}, array_values( $assessment['components'] ) )
			),
		);
		$checks = array(
			'title'   => __( 'Control checks', 'tbsh-compliance-audit-logger' ),
			'kind'    => 'table',
			'columns' => array( __( 'Check', 'tbsh-compliance-audit-logger' ), __( 'Status', 'tbsh-compliance-audit-logger' ), __( 'Detail', 'tbsh-compliance-audit-logger' ) ),
			'rows'    => array_map( function ( $c ) {
				return array( $c['title'], strtoupper( $c['status'] ), $c['desc'] );
			}, array_values( $assessment['checks'] ) ),
		);

		switch ( $type ) {
			case 'nis2':
				if ( ! $p['incident_id'] ) {
					return new \WP_Error( 'tbsh_cal_invalid', __( 'Choose the incident to report.', 'tbsh-compliance-audit-logger' ) );
				}
				$package = Nis2Tracker::package( $p['incident_id'], $p['stage'] );
				if ( is_wp_error( $package ) ) {
					return $package;
				}
				$meta['incident_id'] = $p['incident_id'];
				$meta['stage']       = $p['stage'];
				$meta['title']       = $title . ' — ' . $package['stage_label'];
				$evidence            = $package['evidence'] ?? array();
				$timeline            = $package['timeline'] ?? array();
				unset( $package['evidence'], $package['timeline'] );
				$sections[] = array( 'title' => __( 'Report content', 'tbsh-compliance-audit-logger' ), 'kind' => 'kv', 'data' => $package );
				if ( $timeline ) {
					$sections[] = self::table( __( 'Incident timeline', 'tbsh-compliance-audit-logger' ), $timeline, array( 'time', 'action', 'detail', 'user' ) );
				}
				if ( $evidence ) {
					$sections[] = self::table( __( 'Linked evidence', 'tbsh-compliance-audit-logger' ), $evidence, array( 'evidence_uuid', 'evidence_type', 'evidence_title', 'created_at' ) );
				}
				break;

			case 'incidents':
				$sections[] = self::table( __( 'Incidents in period', 'tbsh-compliance-audit-logger' ), self::incidents( $p ), array( 'id', 'event_title', 'severity', 'status', 'incident_type', 'detected_at', 'resolved_at', 'compliance_mappings' ) );
				break;

			case 'risk':
				$risks      = RiskRegister::list( array( 'per_page' => 100 ) );
				$sections[] = array( 'title' => __( 'Summary', 'tbsh-compliance-audit-logger' ), 'kind' => 'kv', 'data' => $risks['stats']['bands'] + array( 'overdue_reviews' => $risks['stats']['overdue_reviews'] ) );
				$sections[] = self::table( __( 'Risk register', 'tbsh-compliance-audit-logger' ), $risks['items'], array( 'id', 'title', 'category', 'likelihood', 'impact', 'score', 'band', 'status', 'review_date', 'mitigation_plan' ) );
				break;

			case 'vendors':
				$vendors    = VendorRegister::list( array( 'per_page' => 100 ) );
				$supply     = SupplyChain::inventory();
				$sections[] = array( 'title' => __( 'Summary', 'tbsh-compliance-audit-logger' ), 'kind' => 'kv', 'data' => $vendors['stats'] + $supply['summary'] );
				$sections[] = self::table( __( 'ICT third-party register', 'tbsh-compliance-audit-logger' ), $vendors['items'], array( 'name', 'service_type', 'criticality', 'risk_rating', 'data_access_level', 'contract_renewal_date', 'review_date', 'status' ) );
				$sections[] = self::table( __( 'Software components', 'tbsh-compliance-audit-logger' ), array_merge( $supply['plugins'], $supply['themes'] ), array( 'type', 'name', 'version', 'update_available', 'risk' ) );
				$sections[] = self::table( __( 'Outbound integrations', 'tbsh-compliance-audit-logger' ), $supply['integrations'], array( 'name', 'kind', 'endpoint' ) );
				break;

			case 'dora':
				$sections[] = $readiness;
				$sections[] = self::table( __( 'ICT-related incidents (Art. 17–19)', 'tbsh-compliance-audit-logger' ), self::incidents( $p ), array( 'id', 'event_title', 'severity', 'status', 'detected_at', 'resolved_at' ) );
				$sections[] = self::table( __( 'Resilience testing (Art. 24–25)', 'tbsh-compliance-audit-logger' ), DrillRegister::list( array( 'per_page' => 100 ) )['items'], array( 'title', 'scenario', 'status', 'completed_at', 'rto_target_min', 'rto_actual_min', 'rpo_target_min', 'rpo_actual_min', 'outcome' ) );
				$sections[] = self::table( __( 'ICT third-party providers (Art. 28)', 'tbsh-compliance-audit-logger' ), VendorRegister::list( array( 'per_page' => 100 ) )['items'], array( 'name', 'service_type', 'criticality', 'review_date', 'contract_renewal_date' ) );
				$sections[] = self::framework( $assessment, 'dora' );
				break;

			case 'iso27001':
			case 'soc2':
				$sections[] = self::framework( $assessment, $type );
				$sections[] = $checks;
				$sections[] = self::table( __( 'Evidence snapshots in period', 'tbsh-compliance-audit-logger' ), self::evidence( $p ), array( 'evidence_uuid', 'evidence_type', 'evidence_title', 'created_at' ) );
				$sections[] = self::table( __( 'Integrity verifications', 'tbsh-compliance-audit-logger' ), self::integrity(), array( 'verification_date', 'status', 'issues_found' ) );
				break;

			default: // executive.
				$sections[] = array( 'title' => __( 'Summary', 'tbsh-compliance-audit-logger' ), 'kind' => 'text', 'text' => $assessment['summary'] );
				$sections[] = $readiness;
				$sections[] = $checks;
				$sections[] = self::table( __( 'Incidents in period', 'tbsh-compliance-audit-logger' ), self::incidents( $p ), array( 'id', 'event_title', 'severity', 'status', 'detected_at' ) );
				$sections[] = self::table( __( 'Top risks', 'tbsh-compliance-audit-logger' ), array_slice( RiskRegister::list( array( 'status' => 'active', 'per_page' => 10 ) )['items'], 0, 10 ), array( 'title', 'category', 'score', 'band', 'review_date' ) );
				$sections[] = array(
					'title' => __( 'Framework coverage', 'tbsh-compliance-audit-logger' ),
					'kind'  => 'table',
					'columns' => array( __( 'Framework', 'tbsh-compliance-audit-logger' ), __( 'Controls evidenced', 'tbsh-compliance-audit-logger' ) ),
					'rows'  => array_map( function ( $fw ) {
						return array( $fw['name'], $fw['coverage'] . '%' );
					}, array_values( $assessment['frameworks'] ) ),
				);
		}

		return array( 'meta' => $meta, 'sections' => $sections );
	}

	/**
	 * Framework controls section.
	 */
	private static function framework( array $assessment, $key ) {
		$fw = $assessment['frameworks'][ $key ];
		return array(
			/* translators: 1: framework name, 2: coverage percent */
			'title'   => sprintf( __( '%1$s — %2$d%% of mapped controls evidenced', 'tbsh-compliance-audit-logger' ), $fw['name'], $fw['coverage'] ),
			'kind'    => 'table',
			'columns' => array( __( 'Reference', 'tbsh-compliance-audit-logger' ), __( 'Control', 'tbsh-compliance-audit-logger' ), __( 'Status', 'tbsh-compliance-audit-logger' ) ),
			'rows'    => array_map( function ( $c ) {
				return array( $c['ref'], $c['title'], strtoupper( $c['status'] ) );
			}, $fw['controls'] ),
		);
	}

	/**
	 * Table section from associative rows.
	 */
	private static function table( $title, array $rows, array $columns ) {
		return array(
			'title'   => $title,
			'kind'    => 'table',
			'columns' => $columns,
			'rows'    => array_map( function ( $row ) use ( $columns ) {
				$row = (array) $row;
				return array_map( function ( $col ) use ( $row ) {
					$v = $row[ $col ] ?? '';
					return is_array( $v ) ? wp_json_encode( $v ) : ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v );
				}, $columns );
			}, $rows ),
		);
	}

	/**
	 * Incidents detected in the period.
	 */
	private static function incidents( array $p ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT id, event_title, severity, status, incident_type, detected_at, resolved_at, compliance_mappings FROM %i WHERE COALESCE(detected_at, created_at) BETWEEN %s AND %s ORDER BY id DESC LIMIT 500',
			Schema::table( 'incidents' ),
			$p['start'],
			$p['end']
		), ARRAY_A );
	}

	/**
	 * Evidence snapshots in the period (metadata only).
	 */
	private static function evidence( array $p ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT evidence_uuid, evidence_type, evidence_title, created_at FROM %i WHERE created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 500',
			Schema::table( 'evidence' ),
			$p['start'],
			$p['end']
		), ARRAY_A );
	}

	/**
	 * Recent chain verifications.
	 */
	private static function integrity() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT verification_date, status, issues_found FROM %i WHERE check_type = 'chain' ORDER BY id DESC LIMIT 20", Schema::table( 'integrity' ) ), ARRAY_A );
	}
}
