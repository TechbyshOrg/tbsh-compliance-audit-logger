import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { get, post, put, del, can, isAuditor } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';

const STATUSES = [ 'open', 'investigating', 'contained', 'resolved', 'closed' ];
const SEVERITIES = [ 'info', 'notice', 'warning', 'error', 'critical' ];
const NIS2_STAGES = [ 'early_warning', 'notification', 'final_report' ];
const STAGE_STATUSES = [ 'pending', 'draft', 'submitted' ];

const statusLabels = () => ( {
	open: __( 'Open', 'tbsh-compliance-audit-logger' ),
	investigating: __( 'Investigating', 'tbsh-compliance-audit-logger' ),
	contained: __( 'Contained', 'tbsh-compliance-audit-logger' ),
	resolved: __( 'Resolved', 'tbsh-compliance-audit-logger' ),
	closed: __( 'Closed', 'tbsh-compliance-audit-logger' ),
} );

const severityLabels = () => ( {
	info: __( 'Info', 'tbsh-compliance-audit-logger' ),
	notice: __( 'Notice', 'tbsh-compliance-audit-logger' ),
	warning: __( 'Warning', 'tbsh-compliance-audit-logger' ),
	error: __( 'Error', 'tbsh-compliance-audit-logger' ),
	critical: __( 'Critical', 'tbsh-compliance-audit-logger' ),
} );

const stageStatusLabels = () => ( {
	pending: __( 'Pending', 'tbsh-compliance-audit-logger' ),
	draft: __( 'Draft', 'tbsh-compliance-audit-logger' ),
	submitted: __( 'Submitted', 'tbsh-compliance-audit-logger' ),
} );

const timelineLabels = () => ( {
	created: __( 'Incident created', 'tbsh-compliance-audit-logger' ),
	status: __( 'Status changed', 'tbsh-compliance-audit-logger' ),
	severity: __( 'Severity changed', 'tbsh-compliance-audit-logger' ),
	note: __( 'Note added', 'tbsh-compliance-audit-logger' ),
	evidence: __( 'Evidence linked', 'tbsh-compliance-audit-logger' ),
} );

const riskLabels = () => ( {
	low: __( 'Low', 'tbsh-compliance-audit-logger' ),
	medium: __( 'Medium', 'tbsh-compliance-audit-logger' ),
	high: __( 'High', 'tbsh-compliance-audit-logger' ),
	critical: __( 'Critical', 'tbsh-compliance-audit-logger' ),
} );

const severityBadge = ( severity ) => {
	if ( 'critical' === severity || 'error' === severity ) {
		return 'danger';
	}
	if ( 'warning' === severity ) {
		return 'warning';
	}
	return 'info';
};

const statusBadge = ( status ) => {
	switch ( status ) {
		case 'open':
			return 'danger';
		case 'investigating':
			return 'warning';
		case 'contained':
			return 'info';
		default:
			return 'success';
	}
};

const riskBadge = ( level ) => {
	if ( 'critical' === level || 'high' === level ) {
		return 'danger';
	}
	if ( 'medium' === level ) {
		return 'warning';
	}
	return 'info';
};

const humanize = ( value ) => ( value || '' ).replace( /_/g, ' ' );

const errMessage = ( err, fallback ) => ( err && err.message ) || fallback;

/**
 * Read-only text block for users without write access.
 *
 * @param {Object} props       Props.
 * @param {string} props.label Label.
 * @param {string} props.value Value.
 */
function ReadOnlyField( { label, value } ) {
	return (
		<div className="tbsh-form-group">
			<div className="tbsh-form-label">{ label }</div>
			<div style={ { whiteSpace: 'pre-wrap' } }>
				{ value ? value : <span className="tbsh-muted">{ __( 'Not recorded', 'tbsh-compliance-audit-logger' ) }</span> }
			</div>
		</div>
	);
}

/**
 * Incident detail view (lives inside a Modal).
 *
 * @param {Object}   props                 Props.
 * @param {number}   props.id              Incident ID.
 * @param {boolean}  props.canManage       Write access.
 * @param {Function} props.onChanged       Called after any successful change.
 * @param {Function} props.onRequestDelete Called with the incident to ask for deletion.
 */
function IncidentDetail( { id, canManage, onChanged, onRequestDelete } ) {
	const [ incident, setIncident ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( 'details' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ busy, setBusy ] = useState( '' );

	const [ form, setForm ] = useState( {} );
	const [ nis2, setNis2 ] = useState( null );

	const [ notes, setNotes ] = useState( [] );
	const [ noteText, setNoteText ] = useState( '' );
	const [ confirmNote, setConfirmNote ] = useState( null );

	const [ evidence, setEvidence ] = useState( [] );
	const [ pickerOpen, setPickerOpen ] = useState( false );
	const [ pickerItems, setPickerItems ] = useState( [] );
	const [ pickerLoading, setPickerLoading ] = useState( false );
	const [ pickerValue, setPickerValue ] = useState( '' );
	const [ confirmDetach, setConfirmDetach ] = useState( null );

	const sLabels = statusLabels();
	const sevLabels = severityLabels();

	const applyIncident = ( data ) => {
		setIncident( data );
		setForm( {
			event_title: data.event_title || '',
			severity: data.severity || 'warning',
			description: data.description || '',
			affected_systems: data.affected_systems || '',
			impact_assessment: data.impact_assessment || '',
			root_cause: data.root_cause || '',
			resolution_notes: data.resolution_notes || '',
			compliance_mappings: data.compliance_mappings || '',
		} );
		const n = data.nis2 || {};
		const stages = {};
		NIS2_STAGES.forEach( ( stage ) => {
			const s = ( n.stages && n.stages[ stage ] ) || {};
			stages[ stage ] = { status: s.status || 'pending', reference: s.reference || '' };
		} );
		setNis2( {
			significant: !! n.significant,
			cross_border: !! data.cross_border,
			authority: n.authority || '',
			remediation: n.remediation || '',
			stages,
		} );
	};

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		Promise.all( [
			get( `/incidents/${ id }` ),
			get( `/incidents/${ id }/notes` ),
			get( `/incidents/${ id }/evidence` ),
		] )
			.then( ( [ data, noteList, evidenceList ] ) => {
				applyIncident( data );
				setNotes( Array.isArray( noteList ) ? noteList : [] );
				setEvidence( Array.isArray( evidenceList ) ? evidenceList : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( errMessage( err, __( 'Failed to load the incident.', 'tbsh-compliance-audit-logger' ) ) );
				setLoading( false );
			} );
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		load();
	}, [ load ] );

	const run = ( key, request, onSuccess, successMessage ) => {
		setBusy( key );
		setNotice( { type: '', message: '' } );
		return request()
			.then( ( res ) => {
				onSuccess( res );
				if ( successMessage ) {
					setNotice( { type: 'success', message: successMessage } );
				}
				onChanged();
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', message: errMessage( err, __( 'The request failed.', 'tbsh-compliance-audit-logger' ) ) } );
			} )
			.finally( () => setBusy( '' ) );
	};

	const refreshTimeline = () => get( `/incidents/${ id }` ).then( ( data ) => setIncident( data ) ).catch( () => {} );

	const changeStatus = ( status ) =>
		run(
			`status-${ status }`,
			() => put( `/incidents/${ id }`, { status } ),
			applyIncident,
			/* translators: %s: new incident status */
			sprintf( __( 'Status changed to %s.', 'tbsh-compliance-audit-logger' ), sLabels[ status ] )
		);

	const saveDetails = ( e ) => {
		e.preventDefault();
		run( 'details', () => put( `/incidents/${ id }`, form ), applyIncident, __( 'Incident saved.', 'tbsh-compliance-audit-logger' ) );
	};

	const addNote = ( e ) => {
		e.preventDefault();
		if ( ! noteText.trim() ) {
			return;
		}
		run(
			'note',
			() => post( `/incidents/${ id }/notes`, { note_content: noteText } ),
			( list ) => {
				setNotes( Array.isArray( list ) ? list : [] );
				setNoteText( '' );
				refreshTimeline();
			},
			__( 'Note added.', 'tbsh-compliance-audit-logger' )
		);
	};

	const deleteNote = ( noteId ) =>
		run(
			`note-del-${ noteId }`,
			() => del( `/incidents/${ id }/notes/${ noteId }` ),
			( list ) => {
				setNotes( Array.isArray( list ) ? list : [] );
				setConfirmNote( null );
			},
			__( 'Note deleted.', 'tbsh-compliance-audit-logger' )
		);

	const openPicker = () => {
		setPickerOpen( true );
		setPickerLoading( true );
		setPickerValue( '' );
		get( '/evidence', { per_page: 50 } )
			.then( ( data ) => {
				setPickerItems( ( data && data.items ) || [] );
				setPickerLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', message: errMessage( err, __( 'Failed to load evidence snapshots.', 'tbsh-compliance-audit-logger' ) ) } );
				setPickerItems( [] );
				setPickerLoading( false );
			} );
	};

	const attachEvidence = () => {
		if ( ! pickerValue ) {
			return;
		}
		run(
			'attach',
			() => post( `/incidents/${ id }/evidence`, { evidence_uuid: pickerValue } ),
			( list ) => {
				setEvidence( Array.isArray( list ) ? list : [] );
				setPickerValue( '' );
				setPickerOpen( false );
				refreshTimeline();
			},
			__( 'Evidence linked to the incident.', 'tbsh-compliance-audit-logger' )
		);
	};

	const detachEvidence = ( linkId ) =>
		run(
			`detach-${ linkId }`,
			() => del( `/incidents/${ id }/evidence/${ linkId }` ),
			( list ) => {
				setEvidence( Array.isArray( list ) ? list : [] );
				setConfirmDetach( null );
			},
			__( 'Evidence unlinked.', 'tbsh-compliance-audit-logger' )
		);

	const saveNis2 = ( e ) => {
		e.preventDefault();
		run( 'nis2', () => put( `/incidents/${ id }/nis2`, nis2 ), applyIncident, __( 'NIS2 reporting saved.', 'tbsh-compliance-audit-logger' ) );
	};

	const analyze = () => {
		setBusy( 'ai' );
		setNotice( { type: '', message: '' } );
		post( `/incidents/${ id }/analyze` )
			.then( ( data ) => {
				setIncident( data );
				setNotice( { type: 'success', message: __( 'AI analysis generated.', 'tbsh-compliance-audit-logger' ) } );
				onChanged();
			} )
			.catch( ( err ) => {
				const disabled = err && 'tbsh_cal_ai_disabled' === err.code;
				setNotice( {
					type: disabled ? 'warning' : 'error',
					message: disabled
						? sprintf(
							/* translators: %s: server message explaining that AI analysis is not configured */
							__( '%s AI analysis is optional and stays off until a provider is set up under Settings.', 'tbsh-compliance-audit-logger' ),
							err.message
						)
						: errMessage( err, __( 'The AI analysis failed.', 'tbsh-compliance-audit-logger' ) ),
				} );
			} )
			.finally( () => setBusy( '' ) );
	};

	if ( loading ) {
		return <SkeletonLoader rows={ 6 } cols={ 2 } />;
	}

	if ( error || ! incident ) {
		return (
			<div style={ { textAlign: 'center' } }>
				<EmptyState title={ __( 'Could not load the incident', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
				<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
					{ __( 'Try again', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>
		);
	}

	const nis2Status = incident.nis2 || { stages: {}, overdue: 0 };
	const analysis = incident.ai_analysis && incident.ai_analysis.result ? incident.ai_analysis : null;
	const timeline = Array.isArray( incident.timeline ) ? incident.timeline.slice().reverse() : [];
	const linkedUuids = evidence.map( ( ev ) => ev.evidence_uuid );
	const pickable = pickerItems.filter( ( ev ) => ! linkedUuids.includes( ev.evidence_uuid ) );

	const tabs = [
		[ 'details', __( 'Details', 'tbsh-compliance-audit-logger' ) ],
		[ 'timeline', __( 'Timeline', 'tbsh-compliance-audit-logger' ) ],
		/* translators: %d: number of notes */
		[ 'notes', sprintf( __( 'Notes (%d)', 'tbsh-compliance-audit-logger' ), notes.length ) ],
		/* translators: %d: number of linked evidence snapshots */
		[ 'evidence', sprintf( __( 'Evidence (%d)', 'tbsh-compliance-audit-logger' ), evidence.length ) ],
		[ 'nis2', __( 'NIS2', 'tbsh-compliance-audit-logger' ) ],
		[ 'ai', __( 'AI analysis', 'tbsh-compliance-audit-logger' ) ],
	];

	const timelineDetail = ( entry ) => {
		const detail = entry.detail || '';
		if ( ( 'status' === entry.action || 'severity' === entry.action ) && detail.includes( '→' ) ) {
			const map = 'status' === entry.action ? sLabels : sevLabels;
			const [ from, to ] = detail.split( '→' ).map( ( s ) => s.trim() );
			return sprintf(
				/* translators: 1: previous value, 2: new value */
				__( '%1$s to %2$s', 'tbsh-compliance-audit-logger' ),
				map[ from ] || from,
				map[ to ] || to
			);
		}
		if ( 'created' === entry.action ) {
			return 'manual' === detail ? __( 'Opened manually', 'tbsh-compliance-audit-logger' ) : humanize( detail );
		}
		return detail;
	};

	const textFields = [
		[ 'description', __( 'Description', 'tbsh-compliance-audit-logger' ) ],
		[ 'affected_systems', __( 'Affected systems', 'tbsh-compliance-audit-logger' ) ],
		[ 'impact_assessment', __( 'Impact assessment', 'tbsh-compliance-audit-logger' ) ],
		[ 'root_cause', __( 'Root cause', 'tbsh-compliance-audit-logger' ) ],
		[ 'resolution_notes', __( 'Resolution notes', 'tbsh-compliance-audit-logger' ) ],
	];

	const tLabels = timelineLabels();
	const stLabels = stageStatusLabels();

	const renderPicker = () => {
		if ( pickerLoading ) {
			return (
				<SkeletonLoader rows={ 1 } cols={ 2 } />
			);
		}
		if ( pickable.length === 0 ) {
			return (
				<div className="tbsh-row-between">
					<span className="tbsh-muted">
						{ __( 'There are no other evidence snapshots to link. Capture one in the Evidence Vault first.', 'tbsh-compliance-audit-logger' ) }
					</span>
					<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => setPickerOpen( false ) }>
						{ __( 'Close', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		return (
			<div>
				<div className="tbsh-form-group">
					<label className="tbsh-form-label" htmlFor="tbsh-inc-evidence-pick">
						{ __( 'Evidence snapshot (latest 50)', 'tbsh-compliance-audit-logger' ) }
					</label>
					<select
						id="tbsh-inc-evidence-pick"
						className="tbsh-form-control"
						value={ pickerValue }
						onChange={ ( e ) => setPickerValue( e.target.value ) }
					>
						<option value="">{ __( 'Select a snapshot…', 'tbsh-compliance-audit-logger' ) }</option>
						{ pickable.map( ( ev ) => (
							<option key={ ev.evidence_uuid } value={ ev.evidence_uuid }>
								{ `${ ev.evidence_title } — ${ ev.created_at } (${ humanize( ev.evidence_type ) })` }
							</option>
						) ) }
					</select>
				</div>
				<div className="tbsh-row">
					<button
						type="button"
						className="tbsh-btn tbsh-btn-primary tbsh-btn-sm"
						disabled={ ! pickerValue || !! busy }
						onClick={ attachEvidence }
					>
						{ 'attach' === busy ? __( 'Linking…', 'tbsh-compliance-audit-logger' ) : __( 'Link snapshot', 'tbsh-compliance-audit-logger' ) }
					</button>
					<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => setPickerOpen( false ) }>
						{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);
	};

	return (
		<div className="tbsh-stack">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-row">
				<span className={ `tbsh-badge tbsh-badge-${ severityBadge( incident.severity ) }` }>
					{ sevLabels[ incident.severity ] || incident.severity }
				</span>
				<span className={ `tbsh-badge tbsh-badge-${ statusBadge( incident.status ) }` }>
					{ sLabels[ incident.status ] || incident.status }
				</span>
				{ nis2Status.significant && (
					<span className="tbsh-badge tbsh-badge-warning">{ __( 'NIS2 significant', 'tbsh-compliance-audit-logger' ) }</span>
				) }
				{ nis2Status.overdue > 0 && (
					<span className="tbsh-badge tbsh-badge-danger">
						{ sprintf(
							/* translators: %d: number of overdue NIS2 reporting stages */
							_n( '%d NIS2 stage overdue', '%d NIS2 stages overdue', nis2Status.overdue, 'tbsh-compliance-audit-logger' ),
							nis2Status.overdue
						) }
					</span>
				) }
			</div>

			<div className="tbsh-tabs" role="tablist">
				{ tabs.map( ( [ key, label ] ) => (
					<button
						key={ key }
						type="button"
						role="tab"
						aria-selected={ tab === key }
						className={ `tbsh-tab-btn${ tab === key ? ' active' : '' }` }
						onClick={ () => setTab( key ) }
					>
						{ label }
					</button>
				) ) }
			</div>

			{ 'details' === tab && (
				<div className="tbsh-stack">
					<dl className="tbsh-kv">
						<dt>{ __( 'Reference', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd><code>{ incident.incident_uuid }</code></dd>
						<dt>{ __( 'Type', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ humanize( incident.incident_type ) }</dd>
						<dt>{ __( 'Detected', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ incident.detected_at }</dd>
						<dt>{ __( 'Created', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ incident.created_at }</dd>
						<dt>{ __( 'Last updated', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ incident.updated_at }</dd>
						{ incident.resolved_at && (
							<>
								<dt>{ __( 'Resolved', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>{ incident.resolved_at }</dd>
							</>
						) }
						{ incident.owner_name && (
							<>
								<dt>{ __( 'Owner', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>{ incident.owner_name }</dd>
							</>
						) }
						{ incident.assigned_name && (
							<>
								<dt>{ __( 'Assigned to', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>{ incident.assigned_name }</dd>
							</>
						) }
					</dl>

					{ canManage && (
						<div>
							<h5 className="tbsh-section-title">{ __( 'Move to status', 'tbsh-compliance-audit-logger' ) }</h5>
							<div className="tbsh-row">
								{ STATUSES.filter( ( s ) => s !== incident.status ).map( ( s ) => (
									<button
										key={ s }
										type="button"
										className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
										disabled={ !! busy }
										onClick={ () => changeStatus( s ) }
									>
										{ sLabels[ s ] }
									</button>
								) ) }
							</div>
						</div>
					) }

					{ canManage ? (
						<form onSubmit={ saveDetails }>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-inc-title">{ __( 'Title', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-inc-title"
									type="text"
									className="tbsh-form-control"
									value={ form.event_title }
									onChange={ ( e ) => setForm( { ...form, event_title: e.target.value } ) }
									required
								/>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-inc-severity">{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</label>
								<select
									id="tbsh-inc-severity"
									className="tbsh-form-control"
									value={ form.severity }
									onChange={ ( e ) => setForm( { ...form, severity: e.target.value } ) }
								>
									{ SEVERITIES.map( ( s ) => (
										<option key={ s } value={ s }>{ sevLabels[ s ] }</option>
									) ) }
								</select>
							</div>
							{ textFields.map( ( [ key, label ] ) => (
								<div className="tbsh-form-group" key={ key }>
									<label className="tbsh-form-label" htmlFor={ `tbsh-inc-${ key }` }>{ label }</label>
									<textarea
										id={ `tbsh-inc-${ key }` }
										className="tbsh-form-control"
										rows={ 3 }
										value={ form[ key ] }
										onChange={ ( e ) => setForm( { ...form, [ key ]: e.target.value } ) }
									/>
								</div>
							) ) }
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-inc-mappings">{ __( 'Compliance mappings', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-inc-mappings"
									type="text"
									className="tbsh-form-control"
									placeholder={ __( 'e.g. NIS2 Art. 23, ISO 27001 A.5.24', 'tbsh-compliance-audit-logger' ) }
									value={ form.compliance_mappings }
									onChange={ ( e ) => setForm( { ...form, compliance_mappings: e.target.value } ) }
								/>
							</div>
							<div className="tbsh-row-between">
								<button
									type="button"
									className="tbsh-btn tbsh-btn-danger"
									onClick={ () => onRequestDelete( incident ) }
								>
									<Icon name="trash" />
									{ __( 'Delete incident', 'tbsh-compliance-audit-logger' ) }
								</button>
								<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ !! busy }>
									{ 'details' === busy ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save changes', 'tbsh-compliance-audit-logger' ) }
								</button>
							</div>
						</form>
					) : (
						<div>
							<ReadOnlyField label={ __( 'Title', 'tbsh-compliance-audit-logger' ) } value={ incident.event_title } />
							{ textFields.map( ( [ key, label ] ) => (
								<ReadOnlyField key={ key } label={ label } value={ incident[ key ] } />
							) ) }
							<ReadOnlyField label={ __( 'Compliance mappings', 'tbsh-compliance-audit-logger' ) } value={ incident.compliance_mappings } />
						</div>
					) }
				</div>
			) }

			{ 'timeline' === tab && (
				timeline.length === 0 ? (
					<EmptyState title={ __( 'No timeline entries', 'tbsh-compliance-audit-logger' ) } icon="timeline" />
				) : (
					<ul className="tbsh-timeline-list">
						{ timeline.map( ( entry, idx ) => (
							<li key={ idx }>
								<strong>{ tLabels[ entry.action ] || humanize( entry.action ) }</strong>
								{ timelineDetail( entry ) && <span> — { timelineDetail( entry ) }</span> }
								<div className="tbsh-muted">
									{ sprintf(
										/* translators: 1: date and time, 2: user login */
										__( '%1$s by %2$s', 'tbsh-compliance-audit-logger' ),
										entry.time,
										entry.user || __( 'system', 'tbsh-compliance-audit-logger' )
									) }
								</div>
							</li>
						) ) }
					</ul>
				)
			) }

			{ 'notes' === tab && (
				<div className="tbsh-stack">
					{ notes.length === 0 ? (
						<EmptyState
							title={ __( 'No investigation notes', 'tbsh-compliance-audit-logger' ) }
							description={ canManage ? __( 'Record findings and decisions as the investigation progresses.', 'tbsh-compliance-audit-logger' ) : '' }
							icon="edit"
						/>
					) : (
						<ul className="tbsh-timeline-list">
							{ notes.map( ( note ) => (
								<li key={ note.id }>
									<div className="tbsh-row-between">
										<span className="tbsh-muted">
											{ sprintf(
												/* translators: 1: author name, 2: date and time */
												__( '%1$s on %2$s', 'tbsh-compliance-audit-logger' ),
												note.user_name || __( 'Unknown user', 'tbsh-compliance-audit-logger' ),
												note.created_at
											) }
										</span>
										{ canManage && ( confirmNote === note.id ? (
											<span className="tbsh-row">
												<span>{ __( 'Delete this note?', 'tbsh-compliance-audit-logger' ) }</span>
												<button
													type="button"
													className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
													disabled={ !! busy }
													onClick={ () => deleteNote( note.id ) }
												>
													{ __( 'Delete', 'tbsh-compliance-audit-logger' ) }
												</button>
												<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => setConfirmNote( null ) }>
													{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
												</button>
											</span>
										) : (
											<button
												type="button"
												className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
												aria-label={ __( 'Delete note', 'tbsh-compliance-audit-logger' ) }
												onClick={ () => setConfirmNote( note.id ) }
											>
												<Icon name="trash" />
											</button>
										) ) }
									</div>
									<div style={ { whiteSpace: 'pre-wrap' } }>{ note.note_content }</div>
								</li>
							) ) }
						</ul>
					) }
					{ canManage && (
						<form onSubmit={ addNote }>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-inc-note">{ __( 'Add a note', 'tbsh-compliance-audit-logger' ) }</label>
								<textarea
									id="tbsh-inc-note"
									className="tbsh-form-control"
									rows={ 3 }
									value={ noteText }
									onChange={ ( e ) => setNoteText( e.target.value ) }
								/>
							</div>
							<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ !! busy || ! noteText.trim() }>
								<Icon name="plus" />
								{ 'note' === busy ? __( 'Adding…', 'tbsh-compliance-audit-logger' ) : __( 'Add note', 'tbsh-compliance-audit-logger' ) }
							</button>
						</form>
					) }
				</div>
			) }

			{ 'evidence' === tab && (
				<div className="tbsh-stack">
					{ evidence.length === 0 ? (
						<EmptyState
							title={ __( 'No evidence linked', 'tbsh-compliance-audit-logger' ) }
							description={ canManage ? __( 'Link evidence snapshots from the Evidence Vault to support this incident record.', 'tbsh-compliance-audit-logger' ) : '' }
							icon="evidence"
						/>
					) : (
						<div className="tbsh-table-container">
							<table className="tbsh-table">
								<thead>
									<tr>
										<th>{ __( 'Snapshot', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Captured', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Linked', 'tbsh-compliance-audit-logger' ) }</th>
										{ canManage && <th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th> }
									</tr>
								</thead>
								<tbody>
									{ evidence.map( ( ev ) => (
										<tr key={ ev.link_id }>
											<td>
												<strong>{ ev.evidence_title }</strong>
												<div className="tbsh-muted"><code>{ ev.evidence_uuid }</code> · { humanize( ev.evidence_type ) }</div>
											</td>
											<td>{ ev.created_at }</td>
											<td>{ ev.linked_at }</td>
											{ canManage && (
												<td style={ { textAlign: 'end' } }>
													{ confirmDetach === ev.link_id ? (
														<span className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
															<button
																type="button"
																className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
																disabled={ !! busy }
																onClick={ () => detachEvidence( ev.link_id ) }
															>
																{ __( 'Unlink', 'tbsh-compliance-audit-logger' ) }
															</button>
															<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => setConfirmDetach( null ) }>
																{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
															</button>
														</span>
													) : (
														<button
															type="button"
															className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
															onClick={ () => setConfirmDetach( ev.link_id ) }
														>
															{ __( 'Unlink', 'tbsh-compliance-audit-logger' ) }
														</button>
													) }
												</td>
											) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ canManage && ! pickerOpen && (
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ openPicker }>
							<Icon name="plus" />
							{ __( 'Link evidence', 'tbsh-compliance-audit-logger' ) }
						</button>
					) }
					{ canManage && pickerOpen && (
						<div className="tbsh-card">
							{ renderPicker() }
						</div>
					) }
				</div>
			) }

			{ 'nis2' === tab && nis2 && (
				<form className="tbsh-stack" onSubmit={ saveNis2 }>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'NIS2 Article 23 deadlines are counted from the detection time of a significant incident: early warning within 24 hours, incident notification within 72 hours, final report within one month.', 'tbsh-compliance-audit-logger' ) }
					</p>

					{ canManage ? (
						<div className="tbsh-row">
							<label htmlFor="tbsh-nis2-significant" className="tbsh-row" style={ { marginInlineEnd: '16px' } }>
								<input
									id="tbsh-nis2-significant"
									type="checkbox"
									checked={ nis2.significant }
									onChange={ ( e ) => setNis2( { ...nis2, significant: e.target.checked } ) }
								/>
								{ __( 'Significant incident (reportable under NIS2)', 'tbsh-compliance-audit-logger' ) }
							</label>
							<label htmlFor="tbsh-nis2-cross-border" className="tbsh-row">
								<input
									id="tbsh-nis2-cross-border"
									type="checkbox"
									checked={ nis2.cross_border }
									onChange={ ( e ) => setNis2( { ...nis2, cross_border: e.target.checked } ) }
								/>
								{ __( 'Cross-border impact', 'tbsh-compliance-audit-logger' ) }
							</label>
						</div>
					) : (
						<dl className="tbsh-kv">
							<dt>{ __( 'Significant incident', 'tbsh-compliance-audit-logger' ) }</dt>
							<dd>{ nis2.significant ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' ) }</dd>
							<dt>{ __( 'Cross-border impact', 'tbsh-compliance-audit-logger' ) }</dt>
							<dd>{ nis2.cross_border ? __( 'Yes', 'tbsh-compliance-audit-logger' ) : __( 'No', 'tbsh-compliance-audit-logger' ) }</dd>
						</dl>
					) }

					{ canManage ? (
						<div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-nis2-authority">{ __( 'Competent authority / CSIRT', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-nis2-authority"
									type="text"
									className="tbsh-form-control"
									value={ nis2.authority }
									onChange={ ( e ) => setNis2( { ...nis2, authority: e.target.value } ) }
								/>
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-nis2-remediation">{ __( 'Mitigation and remediation measures', 'tbsh-compliance-audit-logger' ) }</label>
								<textarea
									id="tbsh-nis2-remediation"
									className="tbsh-form-control"
									rows={ 3 }
									value={ nis2.remediation }
									onChange={ ( e ) => setNis2( { ...nis2, remediation: e.target.value } ) }
								/>
							</div>
						</div>
					) : (
						<div>
							<ReadOnlyField label={ __( 'Competent authority / CSIRT', 'tbsh-compliance-audit-logger' ) } value={ nis2.authority } />
							<ReadOnlyField label={ __( 'Mitigation and remediation measures', 'tbsh-compliance-audit-logger' ) } value={ nis2.remediation } />
						</div>
					) }

					<div className="tbsh-table-container">
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'Stage', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Due', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Reference', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ NIS2_STAGES.map( ( stage ) => {
									const info = ( nis2Status.stages && nis2Status.stages[ stage ] ) || {};
									const value = nis2.stages[ stage ];
									return (
										<tr key={ stage }>
											<td>
												<strong>{ info.label || humanize( stage ) }</strong>
												{ info.submitted_at && (
													<div className="tbsh-muted">
														{ sprintf(
															/* translators: %s: date and time */
															__( 'Submitted %s', 'tbsh-compliance-audit-logger' ),
															info.submitted_at
														) }
													</div>
												) }
											</td>
											<td>
												<span style={ info.overdue ? { color: 'var(--tbsh-danger-text)', fontWeight: 600 } : undefined }>
													{ info.due_at || '—' }
												</span>
												{ info.overdue && (
													<div><span className="tbsh-badge tbsh-badge-danger">{ __( 'Overdue', 'tbsh-compliance-audit-logger' ) }</span></div>
												) }
											</td>
											<td>
												{ canManage ? (
													<>
														<label className="screen-reader-text" htmlFor={ `tbsh-nis2-${ stage }-status` }>
															{ __( 'Stage status', 'tbsh-compliance-audit-logger' ) }
														</label>
														<select
															id={ `tbsh-nis2-${ stage }-status` }
															className="tbsh-form-control"
															value={ value.status }
															onChange={ ( e ) =>
																setNis2( {
																	...nis2,
																	stages: { ...nis2.stages, [ stage ]: { ...value, status: e.target.value } },
																} )
															}
														>
															{ STAGE_STATUSES.map( ( s ) => (
																<option key={ s } value={ s }>{ stLabels[ s ] }</option>
															) ) }
														</select>
													</>
												) : (
													<span className={ `tbsh-badge tbsh-badge-${ 'submitted' === value.status ? 'success' : 'info' }` }>
														{ stLabels[ value.status ] || value.status }
													</span>
												) }
											</td>
											<td>
												{ canManage ? (
													<>
														<label className="screen-reader-text" htmlFor={ `tbsh-nis2-${ stage }-ref` }>
															{ __( 'Submission reference', 'tbsh-compliance-audit-logger' ) }
														</label>
														<input
															id={ `tbsh-nis2-${ stage }-ref` }
															type="text"
															className="tbsh-form-control"
															value={ value.reference }
															onChange={ ( e ) =>
																setNis2( {
																	...nis2,
																	stages: { ...nis2.stages, [ stage ]: { ...value, reference: e.target.value } },
																} )
															}
														/>
													</>
												) : (
													value.reference || '—'
												) }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
					{ ! nis2Status.significant && (
						<p className="tbsh-muted" style={ { margin: 0 } }>
							{ __( 'Deadlines are only tracked as overdue once the incident is marked as significant.', 'tbsh-compliance-audit-logger' ) }
						</p>
					) }

					{ canManage && (
						<div>
							<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ !! busy }>
								{ 'nis2' === busy ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save NIS2 reporting', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					) }
				</form>
			) }

			{ 'ai' === tab && (
				<div className="tbsh-stack">
					{ canManage && (
						<div className="tbsh-row-between">
							<span className="tbsh-muted">
								{ __( 'Sends this incident’s fields (no log data or secrets) to the AI provider configured in Settings.', 'tbsh-compliance-audit-logger' ) }
							</span>
							<button type="button" className="tbsh-btn tbsh-btn-primary" disabled={ !! busy } onClick={ analyze }>
								<Icon name="analytics" />
								{ 'ai' === busy ? __( 'Analysing…', 'tbsh-compliance-audit-logger' ) : __( 'Analyse with AI', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					) }

					{ ! analysis ? (
						<EmptyState
							title={ __( 'No AI analysis yet', 'tbsh-compliance-audit-logger' ) }
							description={ canManage ? __( 'Run an analysis to get a summary, likely causes and recommended actions.', 'tbsh-compliance-audit-logger' ) : '' }
							icon="analytics"
						/>
					) : (
						<div className="tbsh-stack">
							<p className="tbsh-muted" style={ { margin: 0 } }>
								{ sprintf(
									/* translators: 1: AI provider, 2: model name, 3: date and time */
									__( 'Generated by %1$s (%2$s) on %3$s', 'tbsh-compliance-audit-logger' ),
									analysis.provider,
									analysis.model,
									analysis.generated_at
								) }
							</p>
							<dl className="tbsh-kv">
								<dt>{ __( 'Risk level', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>
									{ analysis.result.risk_level ? (
										<span className={ `tbsh-badge tbsh-badge-${ riskBadge( analysis.result.risk_level ) }` }>
											{ riskLabels()[ analysis.result.risk_level ] || analysis.result.risk_level }
										</span>
									) : (
										__( 'Not stated', 'tbsh-compliance-audit-logger' )
									) }
								</dd>
								<dt>{ __( 'NIS2 reportable', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>
									{ true === analysis.result.nis2_reportable && __( 'Likely yes', 'tbsh-compliance-audit-logger' ) }
									{ false === analysis.result.nis2_reportable && __( 'Likely no', 'tbsh-compliance-audit-logger' ) }
									{ ( null === analysis.result.nis2_reportable || undefined === analysis.result.nis2_reportable ) &&
										__( 'Not stated', 'tbsh-compliance-audit-logger' ) }
								</dd>
							</dl>
							{ analysis.result.summary && (
								<div>
									<h5 className="tbsh-section-title">{ __( 'Summary', 'tbsh-compliance-audit-logger' ) }</h5>
									<p style={ { margin: 0, whiteSpace: 'pre-wrap' } }>{ analysis.result.summary }</p>
								</div>
							) }
							{ Array.isArray( analysis.result.likely_causes ) && analysis.result.likely_causes.length > 0 && (
								<div>
									<h5 className="tbsh-section-title">{ __( 'Likely causes', 'tbsh-compliance-audit-logger' ) }</h5>
									<ul style={ { margin: 0, paddingInlineStart: '20px', listStyle: 'disc' } }>
										{ analysis.result.likely_causes.map( ( c, i ) => <li key={ i }>{ c }</li> ) }
									</ul>
								</div>
							) }
							{ Array.isArray( analysis.result.recommended_actions ) && analysis.result.recommended_actions.length > 0 && (
								<div>
									<h5 className="tbsh-section-title">{ __( 'Recommended actions', 'tbsh-compliance-audit-logger' ) }</h5>
									<ol style={ { margin: 0, paddingInlineStart: '20px' } }>
										{ analysis.result.recommended_actions.map( ( a, i ) => <li key={ i }>{ a }</li> ) }
									</ol>
								</div>
							) }
							{ analysis.result.notes && (
								<div>
									<h5 className="tbsh-section-title">{ __( 'Notes', 'tbsh-compliance-audit-logger' ) }</h5>
									<p style={ { margin: 0, whiteSpace: 'pre-wrap' } }>{ analysis.result.notes }</p>
								</div>
							) }
							<p className="tbsh-muted" style={ { margin: 0 } }>
								{ __( 'AI output is advisory. Verify it before acting on it or reporting it.', 'tbsh-compliance-audit-logger' ) }
							</p>
						</div>
					) }
				</div>
			) }
		</div>
	);
}

const emptyCreateForm = () => ( {
	event_title: '',
	severity: 'warning',
	description: '',
	affected_systems: '',
	detected_at: '',
} );

export default function IncidentCenter() {
	const [ incidents, setIncidents ] = useState( [] );
	const [ counts, setCounts ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ severity, setSeverity ] = useState( '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );

	const [ createOpen, setCreateOpen ] = useState( false );
	const [ createForm, setCreateForm ] = useState( emptyCreateForm() );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState( '' );

	const [ selectedId, setSelectedId ] = useState( null );
	const [ deleteTarget, setDeleteTarget ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	const canManage = can( 'manage_incidents' ) && ! isAuditor();
	const sLabels = statusLabels();
	const sevLabels = severityLabels();

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		get( '/incidents', { page, per_page: 20, status, severity, search } )
			.then( ( data ) => {
				setIncidents( ( data && data.items ) || [] );
				setTotalPages( ( data && data.total_pages ) || 1 );
				setCounts( ( data && data.counts ) || null );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( errMessage( err, __( 'Failed to load incidents.', 'tbsh-compliance-audit-logger' ) ) );
				setIncidents( [] );
				setLoading( false );
			} );
	}, [ page, status, severity, search ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const closeDetail = useCallback( () => setSelectedId( null ), [] );
	const closeCreate = useCallback( () => setCreateOpen( false ), [] );
	const closeDelete = useCallback( () => setDeleteTarget( null ), [] );

	const submitSearch = ( e ) => {
		e.preventDefault();
		setPage( 1 );
		setSearch( searchInput.trim() );
	};

	const openCreate = () => {
		setCreateForm( emptyCreateForm() );
		setCreateError( '' );
		setCreateOpen( true );
	};

	const submitCreate = ( e ) => {
		e.preventDefault();
		if ( ! createForm.event_title.trim() ) {
			setCreateError( __( 'An incident title is required.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		setCreating( true );
		setCreateError( '' );
		const data = { ...createForm };
		if ( ! data.detected_at ) {
			delete data.detected_at;
		}
		post( '/incidents', data )
			.then( ( res ) => {
				setCreating( false );
				setCreateOpen( false );
				setNotice( { type: 'success', message: __( 'Incident created.', 'tbsh-compliance-audit-logger' ) } );
				load();
				if ( res && res.id ) {
					setSelectedId( res.id );
				}
			} )
			.catch( ( err ) => {
				setCreating( false );
				setCreateError( errMessage( err, __( 'The incident could not be created.', 'tbsh-compliance-audit-logger' ) ) );
			} );
	};

	const requestDelete = ( incident ) => {
		setSelectedId( null );
		setDeleteTarget( incident );
	};

	const confirmDelete = () => {
		if ( ! deleteTarget ) {
			return;
		}
		setDeleting( true );
		del( `/incidents/${ deleteTarget.id }` )
			.then( () => {
				setNotice( { type: 'success', message: __( 'Incident deleted.', 'tbsh-compliance-audit-logger' ) } );
				setDeleteTarget( null );
				setDeleting( false );
				load();
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', message: errMessage( err, __( 'The incident could not be deleted.', 'tbsh-compliance-audit-logger' ) ) } );
				setDeleteTarget( null );
				setDeleting( false );
			} );
	};

	const metricIcons = { open: 'alert', investigating: 'search', contained: 'shield', resolved: 'check', closed: 'lock' };
	const metricStatus = { open: 'danger', investigating: 'warning', contained: '', resolved: 'success', closed: '' };
	const filtered = !! ( status || severity || search );

	const renderIncidents = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 5 } cols={ 5 } />
			);
		}
		if ( error ) {
			return (
				<div style={ { textAlign: 'center', paddingBottom: '24px' } }>
					<EmptyState title={ __( 'Could not load incidents', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
						{ __( 'Try again', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( incidents.length === 0 ) {
			return (
				<EmptyState
					title={ filtered ? __( 'No matching incidents', 'tbsh-compliance-audit-logger' ) : __( 'No incidents recorded', 'tbsh-compliance-audit-logger' ) }
					description={
						filtered
							? __( 'Try different filters or search terms.', 'tbsh-compliance-audit-logger' )
							: __( 'Incidents opened manually or by automatic detection will appear here.', 'tbsh-compliance-audit-logger' )
					}
					icon="incidents"
				/>
			);
		}
		return (
			<>
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Type', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Detected', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'NIS2', 'tbsh-compliance-audit-logger' ) }</th>
							<th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ incidents.map( ( inc ) => {
							const n = inc.nis2 || {};
							return (
								<tr key={ inc.id }>
									<td><strong>{ inc.event_title }</strong></td>
									<td>
										<span className={ `tbsh-badge tbsh-badge-${ severityBadge( inc.severity ) }` }>
											{ sevLabels[ inc.severity ] || inc.severity }
										</span>
									</td>
									<td>
										<span className={ `tbsh-badge tbsh-badge-${ statusBadge( inc.status ) }` }>
											{ sLabels[ inc.status ] || inc.status }
										</span>
									</td>
									<td>{ humanize( inc.incident_type ) }</td>
									<td>{ inc.detected_at }</td>
									<td>
										<span className="tbsh-row">
											{ n.significant ? (
												<span className="tbsh-badge tbsh-badge-warning">{ __( 'Significant', 'tbsh-compliance-audit-logger' ) }</span>
											) : (
												<span className="tbsh-muted">—</span>
											) }
											{ n.overdue > 0 && (
												<span className="tbsh-badge tbsh-badge-danger">
													{ sprintf(
														/* translators: %d: number of overdue NIS2 reporting stages */
														_n( '%d overdue', '%d overdue', n.overdue, 'tbsh-compliance-audit-logger' ),
														n.overdue
													) }
												</span>
											) }
										</span>
									</td>
									<td style={ { textAlign: 'end' } }>
										<button
											type="button"
											className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
											onClick={ () => setSelectedId( inc.id ) }
										>
											{ canManage ? __( 'Manage', 'tbsh-compliance-audit-logger' ) : __( 'View', 'tbsh-compliance-audit-logger' ) }
										</button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
				{ totalPages > 1 && (
					<div className="tbsh-pagination" style={ { marginTop: '16px' } }>
						<button
							type="button"
							className="tbsh-btn tbsh-btn-secondary"
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
						>
							{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
						</button>
						<span style={ { fontSize: '13px', padding: '0 12px' } }>
							{ sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$d of %2$d', 'tbsh-compliance-audit-logger' ),
								page,
								totalPages
							) }
						</span>
						<button
							type="button"
							className="tbsh-btn tbsh-btn-secondary"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				) }
			</>
		);
	};

	return (
		<div className="tbsh-incident-center">
			<Notice type={ notice.type } message={ notice.message } />

			{ counts && (
				<div className="tbsh-dashboard-grid">
					{ STATUSES.map( ( s ) => (
						<MetricCard
							key={ s }
							title={ sLabels[ s ] }
							value={ counts[ s ] || 0 }
							icon={ metricIcons[ s ] }
							status={ counts[ s ] > 0 ? metricStatus[ s ] : '' }
						/>
					) ) }
				</div>
			) }

			<div className="tbsh-row-between" style={ { marginBottom: '16px' } }>
				<h3 className="tbsh-section-title" style={ { margin: 0 } }>
					{ __( 'Incident register', 'tbsh-compliance-audit-logger' ) }
				</h3>
				{ canManage && (
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ openCreate }>
						<Icon name="plus" />
						{ __( 'New incident', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			<div className="tbsh-filters-bar">
				<form onSubmit={ submitSearch } className="tbsh-row" style={ { flexGrow: 1, minWidth: '240px' } }>
					<label className="screen-reader-text" htmlFor="tbsh-incident-search">
						{ __( 'Search incidents', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input
						id="tbsh-incident-search"
						type="search"
						className="tbsh-filter-input"
						style={ { flexGrow: 1 } }
						placeholder={ __( 'Search titles and descriptions…', 'tbsh-compliance-audit-logger' ) }
						value={ searchInput }
						onChange={ ( e ) => setSearchInput( e.target.value ) }
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-primary">
						<Icon name="search" />
						{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
					</button>
				</form>
				<label className="screen-reader-text" htmlFor="tbsh-incident-status">
					{ __( 'Filter by status', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-incident-status"
					className="tbsh-filter-input"
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All statuses', 'tbsh-compliance-audit-logger' ) }</option>
					<option value="active">{ __( 'Active (not resolved or closed)', 'tbsh-compliance-audit-logger' ) }</option>
					{ STATUSES.map( ( s ) => (
						<option key={ s } value={ s }>{ sLabels[ s ] }</option>
					) ) }
				</select>
				<label className="screen-reader-text" htmlFor="tbsh-incident-severity">
					{ __( 'Filter by severity', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-incident-severity"
					className="tbsh-filter-input"
					value={ severity }
					onChange={ ( e ) => {
						setSeverity( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All severities', 'tbsh-compliance-audit-logger' ) }</option>
					{ SEVERITIES.map( ( s ) => (
						<option key={ s } value={ s }>{ sevLabels[ s ] }</option>
					) ) }
				</select>
			</div>

			<div className="tbsh-table-container">
				{ renderIncidents() }
			</div>

			<Modal
				size="lg"
				title={ __( 'Incident', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! selectedId }
				onClose={ closeDetail }
			>
				{ selectedId && (
					<IncidentDetail
						key={ selectedId }
						id={ selectedId }
						canManage={ canManage }
						onChanged={ load }
						onRequestDelete={ requestDelete }
					/>
				) }
			</Modal>

			{ canManage && (
				<Modal title={ __( 'New incident', 'tbsh-compliance-audit-logger' ) } isOpen={ createOpen } onClose={ closeCreate }>
					<form onSubmit={ submitCreate }>
						<Notice type="error" message={ createError } />
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-new-inc-title">{ __( 'Title', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-new-inc-title"
								type="text"
								className="tbsh-form-control"
								value={ createForm.event_title }
								onChange={ ( e ) => setCreateForm( { ...createForm, event_title: e.target.value } ) }
								required
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-new-inc-severity">{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-new-inc-severity"
								className="tbsh-form-control"
								value={ createForm.severity }
								onChange={ ( e ) => setCreateForm( { ...createForm, severity: e.target.value } ) }
							>
								{ SEVERITIES.map( ( s ) => (
									<option key={ s } value={ s }>{ sevLabels[ s ] }</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-new-inc-description">{ __( 'Description', 'tbsh-compliance-audit-logger' ) }</label>
							<textarea
								id="tbsh-new-inc-description"
								className="tbsh-form-control"
								rows={ 4 }
								value={ createForm.description }
								onChange={ ( e ) => setCreateForm( { ...createForm, description: e.target.value } ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-new-inc-systems">{ __( 'Affected systems', 'tbsh-compliance-audit-logger' ) }</label>
							<textarea
								id="tbsh-new-inc-systems"
								className="tbsh-form-control"
								rows={ 2 }
								value={ createForm.affected_systems }
								onChange={ ( e ) => setCreateForm( { ...createForm, affected_systems: e.target.value } ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-new-inc-detected">{ __( 'Detected at', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-new-inc-detected"
								type="datetime-local"
								className="tbsh-form-control"
								value={ createForm.detected_at }
								onChange={ ( e ) => setCreateForm( { ...createForm, detected_at: e.target.value } ) }
							/>
							<div className="tbsh-muted">
								{ __( 'Leave empty to use the current time. NIS2 reporting deadlines are counted from this moment.', 'tbsh-compliance-audit-logger' ) }
							</div>
						</div>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeCreate }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ creating }>
								{ creating ? __( 'Creating…', 'tbsh-compliance-audit-logger' ) : __( 'Create incident', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</form>
				</Modal>
			) }

			{ canManage && (
				<Modal title={ __( 'Delete incident', 'tbsh-compliance-audit-logger' ) } isOpen={ !! deleteTarget } onClose={ closeDelete }>
					{ deleteTarget && (
						<div className="tbsh-stack">
							<p style={ { margin: 0 } }>
								{ sprintf(
									/* translators: %s: incident title */
									__( 'Delete the incident “%s” together with its notes and evidence links? This cannot be undone. The deletion itself is recorded in the audit log.', 'tbsh-compliance-audit-logger' ),
									deleteTarget.event_title
								) }
							</p>
							<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
								<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeDelete }>
									{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
								</button>
								<button type="button" className="tbsh-btn tbsh-btn-danger" disabled={ deleting } onClick={ confirmDelete }>
									{ deleting ? __( 'Deleting…', 'tbsh-compliance-audit-logger' ) : __( 'Delete incident', 'tbsh-compliance-audit-logger' ) }
								</button>
							</div>
						</div>
					) }
				</Modal>
			) }
		</div>
	);
}
