import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import { get, post, can } from '../api';

/**
 * Frameworks with the minimum log/incident retention (days) commonly expected for them.
 * These are suggestions shown to the user, who confirms them with their auditor.
 *
 * @return {Array} Frameworks.
 */
const frameworks = () => [
	{ id: 'nis2', label: __( 'NIS2 Directive', 'tbsh-compliance-audit-logger' ), help: __( 'EU essential and important entities: risk management, logging and 24h/72h/1-month incident reporting.', 'tbsh-compliance-audit-logger' ), logs: 365, incidents: 1825 },
	{ id: 'dora', label: __( 'DORA', 'tbsh-compliance-audit-logger' ), help: __( 'EU financial entities: ICT incident records, resilience testing and third-party register.', 'tbsh-compliance-audit-logger' ), logs: 1825, incidents: 1825 },
	{ id: 'iso27001', label: __( 'ISO/IEC 27001:2022', 'tbsh-compliance-audit-logger' ), help: __( 'Information security management: logging (A.8.15), monitoring (A.8.16) and evidence (A.5.28).', 'tbsh-compliance-audit-logger' ), logs: 365, incidents: 1095 },
	{ id: 'soc2', label: __( 'SOC 2', 'tbsh-compliance-audit-logger' ), help: __( 'Trust Services Criteria: monitoring (CC7) and change management (CC8).', 'tbsh-compliance-audit-logger' ), logs: 365, incidents: 1095 },
	{ id: 'pci_dss', label: __( 'PCI DSS 4.0', 'tbsh-compliance-audit-logger' ), help: __( 'Card payments: audit logs kept 12 months with daily review (Requirement 10) and change detection (11.5).', 'tbsh-compliance-audit-logger' ), logs: 365, incidents: 365 },
	{ id: 'gdpr', label: __( 'GDPR', 'tbsh-compliance-audit-logger' ), help: __( 'Personal data: IP addresses are stored only as salted hashes and data requests are logged.', 'tbsh-compliance-audit-logger' ), logs: 0, incidents: 0 },
];

/**
 * New retention value: never shortens "forever" (0) or a longer existing period.
 *
 * @param {number} current   Current days (0 = forever).
 * @param {number} suggested Suggested minimum.
 * @return {number} Days.
 */
const raiseRetention = ( current, suggested ) => {
	const now = parseInt( current, 10 ) || 0;
	if ( ! suggested || now === 0 || now >= suggested ) {
		return now;
	}
	return suggested;
};

const retentionLabel = ( days ) => ( days === 0
	? __( 'kept forever', 'tbsh-compliance-audit-logger' )
	: sprintf(
		/* translators: %d: number of days */
		__( '%d days', 'tbsh-compliance-audit-logger' ),
		days
	) );

export default function Setup( { navigate } ) {
	const [ settings, setSettings ] = useState( null );
	const [ meta, setMeta ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ stepIndex, setStepIndex ] = useState( 0 );
	const [ choice, setChoice ] = useState( {
		frameworks: [],
		alertEmails: '',
		emailAlerts: true,
		digest: 'weekly',
		coreChecksum: true,
		fileIntegrity: true,
		detection: true,
		anomaly: false,
	} );
	const [ saving, setSaving ] = useState( false );
	const [ done, setDone ] = useState( false );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const headingRef = useRef( null );

	const allowed = can( 'manage_settings' );

	useEffect( () => {
		if ( ! allowed ) {
			setLoading( false );
			return;
		}
		get( '/settings' )
			.then( ( data ) => {
				setSettings( data );
				setMeta( data.meta || {} );
				setChoice( ( prev ) => ( {
					...prev,
					frameworks: Array.isArray( data.frameworks ) ? data.frameworks : [],
					alertEmails: data.alert_emails || ( data.meta && data.meta.admin_email ) || '',
					emailAlerts: true,
					digest: data.digest_frequency && data.digest_frequency !== 'off' ? data.digest_frequency : 'weekly',
					anomaly: !! data.anomaly_detection,
				} ) );
			} )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to load settings.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => setLoading( false ) );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Move focus to the step heading so keyboard and screen-reader users follow the wizard.
	useEffect( () => {
		if ( headingRef.current ) {
			headingRef.current.focus();
		}
	}, [ stepIndex, done ] );

	if ( ! allowed ) {
		return <EmptyState title={ __( 'Access Denied', 'tbsh-compliance-audit-logger' ) } description={ __( 'Only users who can manage settings can run the setup assistant.', 'tbsh-compliance-audit-logger' ) } icon="lock" />;
	}
	if ( loading ) {
		return <SkeletonLoader rows={ 3 } cols={ 2 } />;
	}
	if ( ! settings ) {
		return <Notice type="error" message={ notice.message || __( 'Failed to load settings.', 'tbsh-compliance-audit-logger' ) } />;
	}

	const set = ( key, value ) => setChoice( ( prev ) => ( { ...prev, [ key ]: value } ) );
	const toggleFramework = ( id ) => set( 'frameworks', choice.frameworks.includes( id ) ? choice.frameworks.filter( ( f ) => f !== id ) : [ ...choice.frameworks, id ] );

	const selected = frameworks().filter( ( f ) => choice.frameworks.includes( f.id ) );
	const suggestedLogs = Math.max( 0, ...selected.map( ( f ) => f.logs ) );
	const suggestedIncidents = Math.max( 0, ...selected.map( ( f ) => f.incidents ) );
	const newLogs = raiseRetention( settings.retain_logs, suggestedLogs );
	const newIncidents = raiseRetention( settings.retain_incidents, suggestedIncidents );

	const payload = () => {
		const data = {
			frameworks: choice.frameworks,
			email_alerts: choice.emailAlerts && choice.alertEmails.trim() !== '',
			alert_emails: choice.alertEmails.trim(),
			digest_frequency: choice.digest,
			auto_evidence: true,
			auto_core_checksum: choice.coreChecksum,
			auto_file_integrity: choice.fileIntegrity,
			anomaly_detection: choice.anomaly,
			retain_logs: newLogs,
			retain_incidents: newIncidents,
		};
		if ( choice.detection ) {
			Object.assign( data, { detect_brute_force: true, detect_privilege_escalation: true, detect_config_change: true, detect_integrity: true, detect_vault_failure: true } );
		}
		if ( choice.frameworks.includes( 'gdpr' ) ) {
			data.anonymize_ips = true;
		}
		return data;
	};

	const apply = () => {
		setSaving( true );
		setNotice( { type: '', message: '' } );
		post( '/settings', payload() )
			.then( () => post( '/setup', { state: 'complete' } ) )
			.then( () => {
				if ( window.tbshCalApiSettings ) {
					window.tbshCalApiSettings.setupState = 'complete';
				}
				setDone( true );
			} )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'The settings could not be saved.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => setSaving( false ) );
	};

	const skip = () => {
		post( '/setup', { state: 'dismissed' } )
			.catch( () => {} )
			.finally( () => {
				if ( window.tbshCalApiSettings ) {
					window.tbshCalApiSettings.setupState = 'dismissed';
				}
				navigate( 'dashboard' );
			} );
	};

	const steps = [
		__( 'Frameworks', 'tbsh-compliance-audit-logger' ),
		__( 'Notifications', 'tbsh-compliance-audit-logger' ),
		__( 'Protection', 'tbsh-compliance-audit-logger' ),
		__( 'Review', 'tbsh-compliance-audit-logger' ),
	];

	const check = ( id, label, checked, onChange, help ) => (
		<div key={ id } className="tbsh-form-group" style={ { marginBottom: '14px' } }>
			<label htmlFor={ id } style={ { display: 'flex', gap: '10px', alignItems: 'flex-start', cursor: 'pointer' } }>
				<input id={ id } type="checkbox" checked={ checked } onChange={ ( e ) => onChange( e.target.checked ) } style={ { marginTop: '3px' } } />
				<span>
					<strong>{ label }</strong>
					{ help && <span className="tbsh-muted" style={ { display: 'block', fontSize: '13px' } }>{ help }</span> }
				</span>
			</label>
		</div>
	);

	if ( done ) {
		return (
			<div className="tbsh-card" style={ { padding: '24px', maxWidth: '760px' } }>
				<h3 ref={ headingRef } tabIndex={ -1 } className="tbsh-page-title" style={ { marginTop: 0, fontSize: '18px' } }>{ __( 'Setup complete', 'tbsh-compliance-audit-logger' ) }</h3>
				<p>{ __( 'Your settings are saved and recorded in the audit log. Next, verify the audit log chain and scan your files so you have a first, clean baseline.', 'tbsh-compliance-audit-logger' ) }</p>
				<div className="tbsh-row" style={ { gap: '8px', flexWrap: 'wrap' } }>
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ () => navigate( 'integrity' ) }>
						<Icon name="integrity" />
						{ __( 'Open the Integrity Center', 'tbsh-compliance-audit-logger' ) }
					</button>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => navigate( 'dashboard' ) }>
						{ __( 'Go to the dashboard', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);
	}

	return (
		<div className="tbsh-card" style={ { padding: '24px', maxWidth: '760px' } }>
			<ol className="tbsh-row" aria-label={ __( 'Setup steps', 'tbsh-compliance-audit-logger' ) } style={ { listStyle: 'none', padding: 0, margin: '0 0 20px', gap: '8px', flexWrap: 'wrap' } }>
				{ steps.map( ( label, i ) => (
					<li key={ label } aria-current={ i === stepIndex ? 'step' : undefined }>
						<span className={ `tbsh-badge tbsh-badge-${ i === stepIndex ? 'success' : 'info' }` }>
							{ sprintf(
								/* translators: 1: step number, 2: step name */
								__( '%1$d. %2$s', 'tbsh-compliance-audit-logger' ),
								i + 1,
								label
							) }
						</span>
					</li>
				) ) }
			</ol>

			{ notice.message && <Notice type={ notice.type } message={ notice.message } /> }

			{ stepIndex === 0 && (
				<fieldset style={ { border: 0, padding: 0, margin: 0 } }>
					<legend>
						<h3 ref={ headingRef } tabIndex={ -1 } className="tbsh-page-title" style={ { marginTop: 0, fontSize: '18px' } }>{ __( 'Which frameworks apply to this site?', 'tbsh-compliance-audit-logger' ) }</h3>
					</legend>
					<p className="tbsh-muted">{ __( 'Pick any that apply. This tunes the recommendations in the next steps; you can change everything later in Settings.', 'tbsh-compliance-audit-logger' ) }</p>
					{ frameworks().map( ( f ) => check( `tbsh-fw-${ f.id }`, f.label, choice.frameworks.includes( f.id ), () => toggleFramework( f.id ), f.help ) ) }
				</fieldset>
			) }

			{ stepIndex === 1 && (
				<div>
					<h3 ref={ headingRef } tabIndex={ -1 } className="tbsh-page-title" style={ { marginTop: 0, fontSize: '18px' } }>{ __( 'Who should be notified?', 'tbsh-compliance-audit-logger' ) }</h3>
					{ check( 'tbsh-setup-email-alerts', __( 'Email alerts for errors and critical events', 'tbsh-compliance-audit-logger' ), choice.emailAlerts, ( v ) => set( 'emailAlerts', v ), __( 'Incidents such as brute-force attacks, administrator grants and tampering. Slack, Teams and other channels can be added in Settings.', 'tbsh-compliance-audit-logger' ) ) }
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-setup-emails">{ __( 'Recipient email addresses', 'tbsh-compliance-audit-logger' ) }</label>
						<input id="tbsh-setup-emails" type="text" className="tbsh-form-control" value={ choice.alertEmails } placeholder={ meta.admin_email || 'security@example.com' } onChange={ ( e ) => set( 'alertEmails', e.target.value ) } />
						<span className="tbsh-muted" style={ { fontSize: '12px' } }>{ __( 'Separate several addresses with commas.', 'tbsh-compliance-audit-logger' ) }</span>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-setup-digest">{ __( 'Compliance digest email', 'tbsh-compliance-audit-logger' ) }</label>
						<select id="tbsh-setup-digest" className="tbsh-form-control" value={ choice.digest } onChange={ ( e ) => set( 'digest', e.target.value ) }>
							<option value="weekly">{ __( 'Weekly (recommended)', 'tbsh-compliance-audit-logger' ) }</option>
							<option value="monthly">{ __( 'Monthly', 'tbsh-compliance-audit-logger' ) }</option>
							<option value="off">{ __( 'Off', 'tbsh-compliance-audit-logger' ) }</option>
						</select>
						<span className="tbsh-muted" style={ { fontSize: '12px' } }>{ __( 'A summary of activity, incidents, integrity and governance with a signed report attached: evidence that logs are reviewed regularly.', 'tbsh-compliance-audit-logger' ) }</span>
					</div>
				</div>
			) }

			{ stepIndex === 2 && (
				<div>
					<h3 ref={ headingRef } tabIndex={ -1 } className="tbsh-page-title" style={ { marginTop: 0, fontSize: '18px' } }>{ __( 'Automatic protection', 'tbsh-compliance-audit-logger' ) }</h3>
					{ check( 'tbsh-setup-core', __( 'Verify WordPress core files on a schedule', 'tbsh-compliance-audit-logger' ), choice.coreChecksum, ( v ) => set( 'coreChecksum', v ), __( 'Compares core files with the official checksums from WordPress.org.', 'tbsh-compliance-audit-logger' ) ) }
					{ check( 'tbsh-setup-files', __( 'Verify plugin, theme and uploads files on a schedule', 'tbsh-compliance-audit-logger' ), choice.fileIntegrity, ( v ) => set( 'fileIntegrity', v ), __( 'Detects modified plugins and themes and PHP files hidden in uploads.', 'tbsh-compliance-audit-logger' ) ) }
					{ check( 'tbsh-setup-detection', __( 'Open incidents automatically', 'tbsh-compliance-audit-logger' ), choice.detection, ( v ) => set( 'detection', v ), __( 'Brute-force logins, privilege escalation, critical setting and code changes, integrity failures and failing backups.', 'tbsh-compliance-audit-logger' ) ) }
					{ check( 'tbsh-setup-anomaly', __( 'Behavioural anomaly detection (optional)', 'tbsh-compliance-audit-logger' ), choice.anomaly, ( v ) => set( 'anomaly', v ), __( 'Learns each user’s normal activity over 30 days and flags unusual behaviour.', 'tbsh-compliance-audit-logger' ) ) }
				</div>
			) }

			{ stepIndex === 3 && (
				<div>
					<h3 ref={ headingRef } tabIndex={ -1 } className="tbsh-page-title" style={ { marginTop: 0, fontSize: '18px' } }>{ __( 'Review and apply', 'tbsh-compliance-audit-logger' ) }</h3>
					<ul style={ { paddingInlineStart: '20px' } }>
						<li>
							{ selected.length
								? sprintf(
									/* translators: %s: comma-separated framework names */
									__( 'Frameworks: %s', 'tbsh-compliance-audit-logger' ),
									selected.map( ( f ) => f.label ).join( ', ' )
								)
								: __( 'Frameworks: none selected', 'tbsh-compliance-audit-logger' ) }
						</li>
						<li>
							{ choice.emailAlerts && choice.alertEmails.trim()
								? sprintf(
									/* translators: %s: email addresses */
									__( 'Email alerts to: %s', 'tbsh-compliance-audit-logger' ),
									choice.alertEmails.trim()
								)
								: __( 'Email alerts: off', 'tbsh-compliance-audit-logger' ) }
						</li>
						<li>
							{ choice.digest === 'off'
								? __( 'Compliance digest: off', 'tbsh-compliance-audit-logger' )
								: sprintf(
									/* translators: %s: weekly or monthly */
									__( 'Compliance digest: %s', 'tbsh-compliance-audit-logger' ),
									choice.digest === 'weekly' ? __( 'weekly', 'tbsh-compliance-audit-logger' ) : __( 'monthly', 'tbsh-compliance-audit-logger' )
								) }
						</li>
						<li>
							{ sprintf(
								/* translators: 1: log retention, 2: incident retention */
								__( 'Retention: audit logs %1$s, closed incidents %2$s', 'tbsh-compliance-audit-logger' ),
								retentionLabel( newLogs ),
								retentionLabel( newIncidents )
							) }
						</li>
					</ul>
					{ ( suggestedLogs > 0 || suggestedIncidents > 0 ) && (
						<Notice
							type="info"
							message={ __( 'Retention is only ever lengthened to meet the periods commonly expected for your frameworks; data you keep forever stays forever. Confirm the exact periods with your auditor or legal advisor.', 'tbsh-compliance-audit-logger' ) }
						/>
					) }
				</div>
			) }

			<div className="tbsh-row-between" style={ { marginTop: '24px', flexWrap: 'wrap', gap: '8px' } }>
				<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ skip } disabled={ saving }>
					{ __( 'Skip setup', 'tbsh-compliance-audit-logger' ) }
				</button>
				<div className="tbsh-row" style={ { gap: '8px' } }>
					{ stepIndex > 0 && (
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setStepIndex( stepIndex - 1 ) } disabled={ saving }>
							{ __( 'Back', 'tbsh-compliance-audit-logger' ) }
						</button>
					) }
					{ stepIndex < steps.length - 1 ? (
						<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ () => setStepIndex( stepIndex + 1 ) }>
							{ __( 'Continue', 'tbsh-compliance-audit-logger' ) }
						</button>
					) : (
						<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ apply } disabled={ saving }>
							<Icon name="check" />
							{ saving ? __( 'Applying…', 'tbsh-compliance-audit-logger' ) : __( 'Apply settings', 'tbsh-compliance-audit-logger' ) }
						</button>
					) }
				</div>
			</div>
		</div>
	);
}
