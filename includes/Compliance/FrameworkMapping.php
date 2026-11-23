<?php
namespace TBSHComplianceAuditLogger\Compliance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapping of plugin capabilities to framework controls (ISO/IEC 27001:2022 Annex A
 * numbering). Each control lists the readiness check keys that evidence it.
 */
class FrameworkMapping {

	/**
	 * Frameworks and controls.
	 *
	 * @return array
	 */
	public static function frameworks() {
		return array(
			'nis2'     => array(
				'name'     => 'NIS2 Directive (EU) 2022/2555',
				'controls' => array(
					array( 'ref' => 'Art. 21(2)(b)', 'title' => __( 'Incident handling', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'incident_process', 'alerting' ) ),
					array( 'ref' => 'Art. 21(2)(c)', 'title' => __( 'Business continuity and backup management', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'offsite_backup', 'resilience_testing' ) ),
					array( 'ref' => 'Art. 21(2)(d)', 'title' => __( 'Supply chain security', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'vendor_register' ) ),
					array( 'ref' => 'Art. 21(2)(e)', 'title' => __( 'Security in acquisition, development and maintenance', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'core_integrity' ) ),
					array( 'ref' => 'Art. 21(2)(i)', 'title' => __( 'Access control policies', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'logging_active' ) ),
					array( 'ref' => 'Art. 23', 'title' => __( 'Reporting obligations (24h / 72h / 1 month)', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'nis2_deadlines' ) ),
				),
			),
			'dora'     => array(
				'name'     => 'DORA Regulation (EU) 2022/2554',
				'controls' => array(
					array( 'ref' => 'Art. 9', 'title' => __( 'Protection and prevention', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'logging_active', 'integrity_chain' ) ),
					array( 'ref' => 'Art. 10', 'title' => __( 'Detection', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'alerting', 'incident_process' ) ),
					array( 'ref' => 'Art. 12', 'title' => __( 'Backup policies and restoration', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'offsite_backup' ) ),
					array( 'ref' => 'Art. 17', 'title' => __( 'ICT-related incident management process', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'incident_process' ) ),
					array( 'ref' => 'Art. 24–25', 'title' => __( 'Digital operational resilience testing', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'resilience_testing' ) ),
					array( 'ref' => 'Art. 28', 'title' => __( 'ICT third-party risk', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'vendor_register' ) ),
				),
			),
			'iso27001' => array(
				'name'     => 'ISO/IEC 27001:2022',
				'controls' => array(
					array( 'ref' => '6.1.2 / 8.2', 'title' => __( 'Information security risk assessment', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'risk_register' ) ),
					array( 'ref' => 'A.5.19–5.22', 'title' => __( 'Supplier relationships', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'vendor_register' ) ),
					array( 'ref' => 'A.5.24–5.27', 'title' => __( 'Information security incident management', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'incident_process' ) ),
					array( 'ref' => 'A.5.28', 'title' => __( 'Collection of evidence', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'evidence_snapshots', 'integrity_chain' ) ),
					array( 'ref' => 'A.5.30', 'title' => __( 'ICT readiness for business continuity', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'resilience_testing', 'offsite_backup' ) ),
					array( 'ref' => 'A.5.34', 'title' => __( 'Privacy and protection of PII', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'gdpr_compliance' ) ),
					array( 'ref' => 'A.8.13', 'title' => __( 'Information backup', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'offsite_backup' ) ),
					array( 'ref' => 'A.8.15', 'title' => __( 'Logging', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'logging_active', 'integrity_chain' ) ),
					array( 'ref' => 'A.8.16', 'title' => __( 'Monitoring activities', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'alerting', 'core_integrity' ) ),
				),
			),
			'soc2'     => array(
				'name'     => 'SOC 2 (Trust Services Criteria)',
				'controls' => array(
					array( 'ref' => 'CC3.2', 'title' => __( 'Risk identification and analysis', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'risk_register' ) ),
					array( 'ref' => 'CC6.1', 'title' => __( 'Logical access security', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'logging_active' ) ),
					array( 'ref' => 'CC7.2', 'title' => __( 'Monitoring of system components', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'alerting', 'integrity_chain', 'core_integrity' ) ),
					array( 'ref' => 'CC7.3–7.4', 'title' => __( 'Incident evaluation and response', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'incident_process' ) ),
					array( 'ref' => 'CC9.2', 'title' => __( 'Vendor and business partner risk', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'vendor_register' ) ),
					array( 'ref' => 'A1.2', 'title' => __( 'Backup and recovery', 'tbsh-compliance-audit-logger' ), 'checks' => array( 'offsite_backup', 'resilience_testing' ) ),
				),
			),
		);
	}

	/**
	 * Frameworks with each control's status resolved from readiness checks.
	 *
	 * @param array $checks Check key => array{status}.
	 * @return array
	 */
	public static function evaluate( array $checks ) {
		$out = array();
		foreach ( self::frameworks() as $key => $framework ) {
			$met = 0;
			foreach ( $framework['controls'] as &$control ) {
				$statuses = array();
				foreach ( $control['checks'] as $check ) {
					$statuses[] = $checks[ $check ]['status'] ?? 'warn';
				}
				$control['status'] = in_array( 'fail', $statuses, true ) ? 'fail' : ( in_array( 'warn', $statuses, true ) ? 'warn' : 'pass' );
				if ( 'pass' === $control['status'] ) {
					++$met;
				}
			}
			unset( $control );
			$framework['coverage'] = count( $framework['controls'] ) ? (int) round( 100 * $met / count( $framework['controls'] ) ) : 0;
			$out[ $key ]           = $framework;
		}
		return $out;
	}
}
