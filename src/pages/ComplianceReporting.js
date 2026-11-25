import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { get, post, del, downloadUrl, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import Icon from '../components/Icon';


const pad = ( n ) => String( n ).padStart( 2, '0' );
const ymd = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
const daysAgo = ( n ) => {
	const d = new Date();
	d.setDate( d.getDate() - n );
	return ymd( d );
};

const formatLabels = () => ( {
	html: __( 'HTML (print-ready)', 'tbsh-compliance-audit-logger' ),
	pdf: __( 'PDF', 'tbsh-compliance-audit-logger' ),
	csv: __( 'CSV', 'tbsh-compliance-audit-logger' ),
	json: __( 'JSON', 'tbsh-compliance-audit-logger' ),
} );

const stageLabels = () => ( {
	early_warning: __( 'Early warning (24 hours)', 'tbsh-compliance-audit-logger' ),
	notification: __( 'Incident notification (72 hours)', 'tbsh-compliance-audit-logger' ),
	final_report: __( 'Final report (1 month)', 'tbsh-compliance-audit-logger' ),
} );

const incidentStatusLabels = () => ( {
	open: __( 'Open', 'tbsh-compliance-audit-logger' ),
	investigating: __( 'Investigating', 'tbsh-compliance-audit-logger' ),
	contained: __( 'Contained', 'tbsh-compliance-audit-logger' ),
	resolved: __( 'Resolved', 'tbsh-compliance-audit-logger' ),
	closed: __( 'Closed', 'tbsh-compliance-audit-logger' ),
} );

const formatSize = ( bytes ) => {
	if ( bytes === null || bytes === undefined ) {
		return '';
	}
	if ( bytes < 1024 ) {
		/* translators: %d: size in bytes */
		return sprintf( __( '%d B', 'tbsh-compliance-audit-logger' ), bytes );
	}
	if ( bytes < 1024 * 1024 ) {
		/* translators: %s: size in kilobytes */
		return sprintf( __( '%s KB', 'tbsh-compliance-audit-logger' ), ( bytes / 1024 ).toFixed( 1 ) );
	}
	/* translators: %s: size in megabytes */
	return sprintf( __( '%s MB', 'tbsh-compliance-audit-logger' ), ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) );
};

export default function ComplianceReporting() {
	const canManage = can( 'manage_reports' );

	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ reports, setReports ] = useState( [] );
	const [ types, setTypes ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );

	const [ form, setForm ] = useState( {
		type: 'executive',
		format: 'html',
		start_date: daysAgo( 30 ),
		end_date: ymd( new Date() ),
		company_name: '',
		incident_id: '',
		stage: '',
	} );
	const [ generating, setGenerating ] = useState( false );

	const [ incidents, setIncidents ] = useState( [] );
	const [ incidentsLoading, setIncidentsLoading ] = useState( false );
	const [ incidentsError, setIncidentsError ] = useState( null );
	const [ incidentsLoaded, setIncidentsLoaded ] = useState( false );

	const [ verifying, setVerifying ] = useState( null );
	const [ toDelete, setToDelete ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	const fetchReports = () => {
		setLoading( true );
		setError( null );
		get( '/reports', { page, per_page: 20 } )
			.then( ( data ) => {
				setReports( data.items || [] );
				setTypes( data.types || {} );
				setTotalPages( data.total_pages || 1 );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load reports.', 'tbsh-compliance-audit-logger' ) );
				setReports( [] );
				setLoading( false );
			} );
	};

	useEffect( () => {
		fetchReports();
	}, [ page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const fetchIncidents = () => {
		setIncidentsLoading( true );
		setIncidentsError( null );
		get( '/incidents', { per_page: 100 } )
			.then( ( data ) => {
				setIncidents( ( data && data.items ) || [] );
				setIncidentsLoading( false );
				setIncidentsLoaded( true );
			} )
			.catch( ( err ) => {
				setIncidentsError( ( err && err.message ) || __( 'Failed to load incidents.', 'tbsh-compliance-audit-logger' ) );
				setIncidentsLoading( false );
			} );
	};

	useEffect( () => {
		if ( canManage && 'nis2' === form.type && ! incidentsLoaded && ! incidentsLoading ) {
			fetchIncidents();
		}
	}, [ form.type ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const setField = ( key, value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );

	const typeKeys = Object.keys( types );

	const generate = ( e ) => {
		e.preventDefault();
		if ( ! canManage ) {
			return;
		}
		setNotice( { type: '', message: '' } );

		if ( form.start_date && form.end_date && form.start_date > form.end_date ) {
			setNotice( { type: 'error', message: __( 'The start date must be before the end date.', 'tbsh-compliance-audit-logger' ) } );
			return;
		}
		if ( 'nis2' === form.type && ( ! form.incident_id || ! form.stage ) ) {
			setNotice( { type: 'error', message: __( 'Choose the incident and the NIS2 reporting stage.', 'tbsh-compliance-audit-logger' ) } );
			return;
		}

		const body = {
			type: form.type,
			format: form.format,
			start_date: form.start_date,
			end_date: form.end_date,
			company_name: form.company_name,
		};
		if ( 'nis2' === form.type ) {
			body.incident_id = parseInt( form.incident_id, 10 );
			body.stage = form.stage;
		}

		setGenerating( true );
		post( '/reports', body )
			.then( ( res ) => {
				setGenerating( false );
				setNotice( {
					type: 'success',
					/* translators: %s: report title */
					message: sprintf( __( 'Report generated: %s', 'tbsh-compliance-audit-logger' ), ( res && res.title ) || '' ),
				} );
				if ( 1 === page ) {
					fetchReports();
				} else {
					setPage( 1 );
				}
			} )
			.catch( ( err ) => {
				setGenerating( false );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to generate the report.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const verify = ( report ) => {
		setVerifying( report.id );
		setNotice( { type: '', message: '' } );
		get( `/reports/${ report.id }/verify` )
			.then( ( res ) => {
				setVerifying( null );
				if ( res && res.valid ) {
					setNotice( {
						type: 'success',
						/* translators: %s: report title */
						message: sprintf( __( '"%s" is unchanged and signed by this site.', 'tbsh-compliance-audit-logger' ), report.title ),
					} );
				} else {
					setNotice( {
						type: 'error',
						message: ( res && res.message ) || __( 'Verification failed.', 'tbsh-compliance-audit-logger' ),
					} );
				}
			} )
			.catch( ( err ) => {
				setVerifying( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Verification failed.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const confirmDelete = () => {
		if ( ! toDelete ) {
			return;
		}
		setDeleting( true );
		del( `/reports/${ toDelete.id }` )
			.then( () => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'success', message: __( 'Report deleted.', 'tbsh-compliance-audit-logger' ) } );
				if ( 1 === reports.length && page > 1 ) {
					setPage( page - 1 );
				} else {
					fetchReports();
				}
			} )
			.catch( ( err ) => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to delete the report.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const formats = formatLabels();
	const stages = stageLabels();
	const incidentStatuses = incidentStatusLabels();

	const renderPeriod = ( period ) => {
		if ( ! Array.isArray( period ) || period.length < 2 ) {
			return '—';
		}
		/* translators: 1: start date, 2: end date */
		return sprintf( __( '%1$s to %2$s', 'tbsh-compliance-audit-logger' ), period[ 0 ], period[ 1 ] );
	};

	const renderReports = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 5 } cols={ 6 } />
			);
		}
		if ( error ) {
			return (
				<EmptyState
					title={ __( 'Error loading reports', 'tbsh-compliance-audit-logger' ) }
					description={ (
						<>
							{ error }
							<br />
							<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '12px' } } onClick={ fetchReports }>
								{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
							</button>
						</>
					) }
					icon="alert"
				/>
			);
		}
		if ( reports.length ) {
			return (
				<>
					<table className="tbsh-table">
						<thead>
							<tr>
								<th>{ __( 'Report', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Format', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Period', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Created by', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Created', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Seal', 'tbsh-compliance-audit-logger' ) }</th>
								<th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ reports.map( ( r ) => (
								<tr key={ r.id }>
									<td>
										<strong>{ r.title }</strong>
										{ r.size ? <div className="tbsh-muted" style={ { fontSize: '12px' } }>{ formatSize( r.size ) }</div> : null }
									</td>
									<td>{ formats[ r.format ] || r.format }</td>
									<td>{ renderPeriod( r.period ) }</td>
									<td>{ r.created_by || '—' }</td>
									<td>{ r.created_at }</td>
									<td>
										<div className="tbsh-row">
											{ r.signed ? (
												<span className="tbsh-badge tbsh-badge-success">{ __( 'Signed', 'tbsh-compliance-audit-logger' ) }</span>
											) : (
												<span className="tbsh-badge tbsh-badge-warning">{ __( 'Unsigned', 'tbsh-compliance-audit-logger' ) }</span>
											) }
											{ ! r.available && (
												<span className="tbsh-badge tbsh-badge-danger">{ __( 'File missing', 'tbsh-compliance-audit-logger' ) }</span>
											) }
										</div>
									</td>
									<td style={ { textAlign: 'end' } }>
										<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
											{ r.available ? (
												<a
													className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
													href={ downloadUrl( `/reports/${ r.id }/download` ) }
													download
												>
													<Icon name="download" />
													{ __( 'Download', 'tbsh-compliance-audit-logger' ) }
												</a>
											) : (
												<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" disabled>
													<Icon name="download" />
													{ __( 'Download', 'tbsh-compliance-audit-logger' ) }
												</button>
											) }
											<button
												type="button"
												className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
												disabled={ verifying === r.id }
												onClick={ () => verify( r ) }
											>
												<Icon name="integrity" />
												{ verifying === r.id ? __( 'Verifying…', 'tbsh-compliance-audit-logger' ) : __( 'Verify', 'tbsh-compliance-audit-logger' ) }
											</button>
											{ canManage && (
												<button
													type="button"
													className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
													onClick={ () => setToDelete( r ) }
												>
													<Icon name="trash" />
													{ __( 'Delete', 'tbsh-compliance-audit-logger' ) }
												</button>
											) }
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ totalPages > 1 && (
						<div className="tbsh-pagination" style={ { marginTop: '16px' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page === 1 } onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }>
								{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
							</button>
							<span style={ { fontSize: '13px', padding: '0 12px' } }>{ page } / { totalPages }</span>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => p + 1 ) }>
								{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					) }
				</>
			);
		}
		return (
			<EmptyState
				title={ __( 'No reports yet', 'tbsh-compliance-audit-logger' ) }
				description={ canManage
					? __( 'Generate your first compliance report using the form above.', 'tbsh-compliance-audit-logger' )
					: __( 'No compliance reports have been generated yet.', 'tbsh-compliance-audit-logger' ) }
				icon="reports"
			/>
		);
	};

	return (
		<div className="tbsh-reports-page">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-card" style={ { padding: '20px', marginBottom: '20px' } }>
				<h3 className="tbsh-section-title">{ __( 'How reports are signed', 'tbsh-compliance-audit-logger' ) }</h3>
				<p className="tbsh-muted" style={ { margin: 0 } }>
					{ __( 'Each report file is fingerprinted with a SHA-256 content hash, and that hash is signed with HMAC-SHA256 using a key held privately by this site. "Verify" recomputes both, so any change to the stored file — even a single character — is detected. This is a tamper-evidence seal for audit purposes, not a qualified or legally binding electronic signature.', 'tbsh-compliance-audit-logger' ) }
				</p>
			</div>

			{ canManage && (
				<div className="tbsh-card" style={ { padding: '20px', marginBottom: '20px' } }>
					<h3 className="tbsh-section-title">{ __( 'Generate a report', 'tbsh-compliance-audit-logger' ) }</h3>
					<form onSubmit={ generate }>
						<div className="tbsh-grid-2">
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-report-type">{ __( 'Report type', 'tbsh-compliance-audit-logger' ) }</label>
								<select
									id="tbsh-report-type"
									className="tbsh-form-control"
									value={ form.type }
									onChange={ ( e ) => setField( 'type', e.target.value ) }
									disabled={ loading && ! typeKeys.length }
								>
									{ typeKeys.length ? typeKeys.map( ( key ) => (
										<option key={ key } value={ key }>{ types[ key ] }</option>
									) ) : (
										<option value={ form.type }>{ __( 'Loading…', 'tbsh-compliance-audit-logger' ) }</option>
									) }
								</select>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-report-format">{ __( 'Format', 'tbsh-compliance-audit-logger' ) }</label>
								<select
									id="tbsh-report-format"
									className="tbsh-form-control"
									value={ form.format }
									onChange={ ( e ) => setField( 'format', e.target.value ) }
								>
									{ Object.keys( formats ).map( ( key ) => (
										<option key={ key } value={ key }>{ formats[ key ] }</option>
									) ) }
								</select>
								<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
									{ __( 'PDF files open anywhere and are signed like every other format. PDF supports Latin, Greek, Cyrillic, Armenian, Georgian and Vietnamese text; for Arabic, Hebrew, Indic, Thai or Chinese, Japanese and Korean choose HTML and print it to PDF from your browser.', 'tbsh-compliance-audit-logger' ) }
								</span>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-report-start">{ __( 'Start date', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-report-start"
									type="date"
									className="tbsh-form-control"
									value={ form.start_date }
									onChange={ ( e ) => setField( 'start_date', e.target.value ) }
								/>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-report-end">{ __( 'End date', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-report-end"
									type="date"
									className="tbsh-form-control"
									value={ form.end_date }
									onChange={ ( e ) => setField( 'end_date', e.target.value ) }
								/>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-report-org">{ __( 'Organisation name', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-report-org"
									type="text"
									className="tbsh-form-control"
									value={ form.company_name }
									placeholder={ __( 'Defaults to the site name', 'tbsh-compliance-audit-logger' ) }
									onChange={ ( e ) => setField( 'company_name', e.target.value ) }
								/>
							</div>
						</div>

						{ 'nis2' === form.type && (
							<div className="tbsh-grid-2" style={ { marginTop: '4px' } }>
								<div className="tbsh-form-group">
									<label className="tbsh-form-label" htmlFor="tbsh-report-incident">{ __( 'Incident (required)', 'tbsh-compliance-audit-logger' ) }</label>
									{ incidentsError ? (
										<div className="tbsh-row">
											<span className="tbsh-muted">{ incidentsError }</span>
											<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ fetchIncidents }>
												{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
											</button>
										</div>
									) : (
										<select
											id="tbsh-report-incident"
											className="tbsh-form-control"
											value={ form.incident_id }
											required
											disabled={ incidentsLoading }
											onChange={ ( e ) => setField( 'incident_id', e.target.value ) }
										>
											<option value="">
												{ incidentsLoading ? __( 'Loading incidents…', 'tbsh-compliance-audit-logger' ) : __( '— Select an incident —', 'tbsh-compliance-audit-logger' ) }
											</option>
											{ incidents.map( ( inc ) => (
												<option key={ inc.id } value={ inc.id }>
													{ sprintf(
														/* translators: 1: incident id, 2: incident title, 3: incident status */
														__( '#%1$d %2$s (%3$s)', 'tbsh-compliance-audit-logger' ),
														inc.id,
														inc.event_title,
														incidentStatuses[ inc.status ] || inc.status
													) }
												</option>
											) ) }
										</select>
									) }
									{ incidentsLoaded && ! incidents.length && ! incidentsError && (
										<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
											{ __( 'No incidents are recorded yet. Create an incident first.', 'tbsh-compliance-audit-logger' ) }
										</span>
									) }
								</div>
								<div className="tbsh-form-group">
									<label className="tbsh-form-label" htmlFor="tbsh-report-stage">{ __( 'NIS2 reporting stage (required)', 'tbsh-compliance-audit-logger' ) }</label>
									<select
										id="tbsh-report-stage"
										className="tbsh-form-control"
										value={ form.stage }
										required
										onChange={ ( e ) => setField( 'stage', e.target.value ) }
									>
										<option value="">{ __( '— Select a stage —', 'tbsh-compliance-audit-logger' ) }</option>
										{ Object.keys( stages ).map( ( key ) => (
											<option key={ key } value={ key }>{ stages[ key ] }</option>
										) ) }
									</select>
								</div>
							</div>
						) }

						<div className="tbsh-row" style={ { marginTop: '8px' } }>
							<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ generating || ! typeKeys.length }>
								<Icon name="reports" />
								{ generating ? __( 'Generating…', 'tbsh-compliance-audit-logger' ) : __( 'Generate report', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</form>
				</div>
			) }

			<div className="tbsh-card" style={ { padding: '20px' } }>
				<h3 className="tbsh-section-title">{ __( 'Report history', 'tbsh-compliance-audit-logger' ) }</h3>
				<div className="tbsh-table-container">
					{ renderReports() }
				</div>
			</div>

			<Modal
				title={ __( 'Delete report', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! toDelete }
				onClose={ () => ! deleting && setToDelete( null ) }
			>
				{ toDelete && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: 1: report title, 2: creation date */
								__( 'Delete "%1$s" created on %2$s? The stored file is removed permanently. The deletion is recorded in the audit log.', 'tbsh-compliance-audit-logger' ),
								toDelete.title,
								toDelete.created_at
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ deleting } onClick={ () => setToDelete( null ) }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" disabled={ deleting } onClick={ confirmDelete }>
								{ deleting ? __( 'Deleting…', 'tbsh-compliance-audit-logger' ) : __( 'Delete report', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
