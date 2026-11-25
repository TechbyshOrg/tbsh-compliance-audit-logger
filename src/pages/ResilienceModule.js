import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { get, post, put, del, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';

const SCENARIOS = [ 'backup_restore', 'failover', 'incident_response', 'tabletop', 'other' ];
const STATUSES = [ 'planned', 'completed', 'cancelled' ];
const OUTCOMES = [ 'passed', 'partial', 'failed' ];

const scenarioLabel = ( s ) =>
	( {
		backup_restore: __( 'Backup restore', 'tbsh-compliance-audit-logger' ),
		failover: __( 'Failover', 'tbsh-compliance-audit-logger' ),
		incident_response: __( 'Incident response', 'tbsh-compliance-audit-logger' ),
		tabletop: __( 'Tabletop exercise', 'tbsh-compliance-audit-logger' ),
		other: __( 'Other', 'tbsh-compliance-audit-logger' ),
	}[ s ] || s );

const statusLabel = ( s ) =>
	( {
		planned: __( 'Planned', 'tbsh-compliance-audit-logger' ),
		completed: __( 'Completed', 'tbsh-compliance-audit-logger' ),
		cancelled: __( 'Cancelled', 'tbsh-compliance-audit-logger' ),
	}[ s ] || s );

const statusBadge = ( s ) => ( { planned: 'info', completed: 'success', cancelled: 'warning' }[ s ] || 'info' );

const outcomeLabel = ( o ) =>
	( {
		passed: __( 'Passed', 'tbsh-compliance-audit-logger' ),
		partial: __( 'Partial', 'tbsh-compliance-audit-logger' ),
		failed: __( 'Failed', 'tbsh-compliance-audit-logger' ),
	}[ o ] || o );

const outcomeBadge = ( o ) => ( { passed: 'success', partial: 'warning', failed: 'danger' }[ o ] || 'info' );

// 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM' for <input type="datetime-local">.
const toLocalInput = ( v ) => ( v ? String( v ).replace( ' ', 'T' ).slice( 0, 16 ) : '' );

const toMinutes = ( v ) => {
	if ( '' === v || null === v || undefined === v ) {
		return null;
	}
	const n = parseInt( v, 10 );
	return Number.isNaN( n ) ? null : Math.max( 0, n );
};

const minutesText = ( v ) =>
	null === v || undefined === v
		? '—'
		: sprintf(
			/* translators: %d: number of minutes */
			_n( '%d min', '%d min', v, 'tbsh-compliance-audit-logger' ),
			v
		);

const monthLabel = ( key ) => {
	const d = new Date( `${ key }-01T00:00:00` );
	return Number.isNaN( d.getTime() ) ? key : d.toLocaleDateString( undefined, { month: 'short', year: 'numeric' } );
};

const EMPTY_FORM = {
	title: '',
	scenario: 'backup_restore',
	status: 'planned',
	scheduled_at: '',
	completed_at: '',
	rto_target_min: '',
	rto_actual_min: '',
	rpo_target_min: '',
	rpo_actual_min: '',
	outcome: '',
	notes: '',
};

function MetBadge( { met } ) {
	if ( true === met ) {
		return <span className="tbsh-badge tbsh-badge-success">{ __( 'Met', 'tbsh-compliance-audit-logger' ) }</span>;
	}
	if ( false === met ) {
		return <span className="tbsh-badge tbsh-badge-danger">{ __( 'Missed', 'tbsh-compliance-audit-logger' ) }</span>;
	}
	return null;
}

function TargetActual( { target, actual, met } ) {
	if ( ( null === target || undefined === target ) && ( null === actual || undefined === actual ) ) {
		return <span className="tbsh-muted">—</span>;
	}
	return (
		<div>
			<div>
				{ sprintf(
					/* translators: 1: target duration, 2: actual duration */
					__( '%1$s target / %2$s actual', 'tbsh-compliance-audit-logger' ),
					minutesText( target ),
					minutesText( actual )
				) }
			</div>
			<MetBadge met={ met } />
		</div>
	);
}

export default function ResilienceModule() {
	const canManage = can( 'manage_risks' );

	const [ notice, setNotice ] = useState( { type: '', message: '' } );

	const [ metrics, setMetrics ] = useState( null );
	const [ metricsLoading, setMetricsLoading ] = useState( true );
	const [ metricsError, setMetricsError ] = useState( '' );
	const [ metricsKey, setMetricsKey ] = useState( 0 );

	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );

	const [ editing, setEditing ] = useState( null );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	const [ formError, setFormError ] = useState( '' );
	const [ toDelete, setToDelete ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	const reloadAll = useCallback( () => {
		setReloadKey( ( k ) => k + 1 );
		setMetricsKey( ( k ) => k + 1 );
	}, [] );

	useEffect( () => {
		let cancelled = false;
		setMetricsLoading( true );
		setMetricsError( '' );
		get( '/resilience/metrics' )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setMetrics( res );
					setMetricsLoading( false );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setMetricsError( ( err && err.message ) || __( 'Failed to load resilience metrics.', 'tbsh-compliance-audit-logger' ) );
					setMetricsLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ metricsKey ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		get( '/resilience/drills', { page, per_page: 20 } )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setData( res );
					setLoading( false );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( ( err && err.message ) || __( 'Failed to load resilience drills.', 'tbsh-compliance-audit-logger' ) );
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ page, reloadKey ] );

	const items = ( data && data.items ) || [];
	const totalPages = ( data && data.total_pages ) || 1;
	const total = ( data && data.total ) || 0;

	const setField = ( key, value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );

	const openCreate = () => {
		setForm( EMPTY_FORM );
		setFormError( '' );
		setEditing( {} );
	};

	const openEdit = ( d ) => {
		const str = ( v ) => ( null === v || undefined === v ? '' : String( v ) );
		setForm( {
			title: d.title || '',
			scenario: d.scenario || 'other',
			status: d.status || 'planned',
			scheduled_at: toLocalInput( d.scheduled_at ),
			completed_at: toLocalInput( d.completed_at ),
			rto_target_min: str( d.rto_target_min ),
			rto_actual_min: str( d.rto_actual_min ),
			rpo_target_min: str( d.rpo_target_min ),
			rpo_actual_min: str( d.rpo_actual_min ),
			outcome: d.outcome || '',
			notes: d.notes || '',
		} );
		setFormError( '' );
		setEditing( d );
	};

	const closeForm = useCallback( () => {
		if ( ! saving ) {
			setEditing( null );
		}
	}, [ saving ] );

	const closeDelete = useCallback( () => {
		if ( ! deleting ) {
			setToDelete( null );
		}
	}, [ deleting ] );

	const submitForm = ( e ) => {
		e.preventDefault();
		if ( ! form.title.trim() ) {
			setFormError( __( 'A drill title is required.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		const completed = 'completed' === form.status;
		const payload = {
			title: form.title,
			scenario: form.scenario,
			status: form.status,
			scheduled_at: form.scheduled_at || null,
			completed_at: completed ? form.completed_at || null : null,
			rto_target_min: toMinutes( form.rto_target_min ),
			rto_actual_min: toMinutes( form.rto_actual_min ),
			rpo_target_min: toMinutes( form.rpo_target_min ),
			rpo_actual_min: toMinutes( form.rpo_actual_min ),
			outcome: completed ? form.outcome : '',
			notes: form.notes,
		};
		setSaving( true );
		setFormError( '' );
		const isEdit = editing && editing.id;
		const req = isEdit ? put( `/resilience/drills/${ editing.id }`, payload ) : post( '/resilience/drills', payload );
		req.then( () => {
			setSaving( false );
			setEditing( null );
			setNotice( {
				type: 'success',
				message: isEdit ? __( 'Drill updated.', 'tbsh-compliance-audit-logger' ) : __( 'Drill recorded.', 'tbsh-compliance-audit-logger' ),
			} );
			reloadAll();
		} ).catch( ( err ) => {
			setSaving( false );
			setFormError( ( err && err.message ) || __( 'Could not save the drill.', 'tbsh-compliance-audit-logger' ) );
		} );
	};

	const confirmDelete = () => {
		if ( ! toDelete ) {
			return;
		}
		setDeleting( true );
		del( `/resilience/drills/${ toDelete.id }` )
			.then( () => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'success', message: __( 'Drill deleted.', 'tbsh-compliance-audit-logger' ) } );
				if ( items.length === 1 && page > 1 ) {
					setPage( page - 1 );
					setMetricsKey( ( k ) => k + 1 );
				} else {
					reloadAll();
				}
			} )
			.catch( ( err ) => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Could not delete the drill.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const renderMetrics = () => {
		if ( metricsLoading && ! metrics ) {
			return (
				<div className="tbsh-card" style={ { marginBottom: '20px' } }>
					<SkeletonLoader rows={ 2 } cols={ 5 } />
				</div>
			);
		}
		if ( metricsError && ! metrics ) {
			return (
				<div className="tbsh-card" style={ { marginBottom: '20px', textAlign: 'center' } }>
					<EmptyState title={ __( 'Could not load resilience metrics', 'tbsh-compliance-audit-logger' ) } description={ metricsError } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setMetricsKey( ( k ) => k + 1 ) }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( ! metrics ) {
			return null;
		}

		const rate = metrics.drill_pass_rate;
		let rateStatus = 'danger';
		if ( null === rate || undefined === rate ) {
			rateStatus = '';
		} else if ( rate >= 80 ) {
			rateStatus = 'success';
		} else if ( rate >= 50 ) {
			rateStatus = 'warning';
		}
		const months = Object.entries( metrics.incidents_by_month || {} );
		const max = months.reduce( ( m, [ , c ] ) => Math.max( m, c ), 0 );
		const totalIncidents = months.reduce( ( s, [ , c ] ) => s + c, 0 );
		const next = metrics.next_drill;

		return (
			<>
				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Drills completed (12 months)', 'tbsh-compliance-audit-logger' ) }
						value={ metrics.drills_completed_12m || 0 }
						icon="resilience"
						status={ metrics.drills_completed_12m > 0 ? 'success' : 'warning' }
						desc={
							metrics.last_drill
								? sprintf(
									/* translators: 1: drill title, 2: completion date */
									__( 'Last: %1$s (%2$s)', 'tbsh-compliance-audit-logger' ),
									metrics.last_drill.title,
									metrics.last_drill.completed_at
								)
								: __( 'No drill completed yet', 'tbsh-compliance-audit-logger' )
						}
					/>
					<MetricCard
						title={ __( 'Drill pass rate', 'tbsh-compliance-audit-logger' ) }
						value={
							null === rate || undefined === rate
								? '—'
								: sprintf(
									/* translators: %d: percentage */
									__( '%d%%', 'tbsh-compliance-audit-logger' ),
									rate
								)
						}
						icon="check"
						status={ rateStatus }
						desc={ sprintf(
							/* translators: 1: passed drills, 2: completed drills */
							__( '%1$d of %2$d completed drills passed', 'tbsh-compliance-audit-logger' ),
							metrics.drills_passed_12m || 0,
							metrics.drills_completed_12m || 0
						) }
					/>
					<MetricCard
						title={ __( 'Mean time to resolve', 'tbsh-compliance-audit-logger' ) }
						value={
							null === metrics.mttr_hours || undefined === metrics.mttr_hours
								? '—'
								: sprintf(
									/* translators: %s: number of hours */
									__( '%s h', 'tbsh-compliance-audit-logger' ),
									metrics.mttr_hours
								)
						}
						icon="incidents"
						desc={ __( 'Average over incidents resolved in the last 12 months', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'Last off-site backup', 'tbsh-compliance-audit-logger' ) }
						value={ metrics.last_offsite_backup || __( 'No off-site backup yet', 'tbsh-compliance-audit-logger' ) }
						icon="database"
						status={ metrics.last_offsite_backup ? 'success' : 'warning' }
						desc={ __( 'Latest successful transfer to a remote vault', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'Next drill', 'tbsh-compliance-audit-logger' ) }
						value={ next ? next.scheduled_at : __( 'None scheduled', 'tbsh-compliance-audit-logger' ) }
						icon="calendar"
						status={ next ? '' : 'warning' }
						desc={ next ? next.title : __( 'Plan a drill to keep testing evidence current', 'tbsh-compliance-audit-logger' ) }
					/>
				</div>

				<div className="tbsh-card" style={ { marginBottom: '20px' } }>
					<h4 className="tbsh-section-title">{ __( 'Incidents per month (last 6 months)', 'tbsh-compliance-audit-logger' ) }</h4>
					{ months.length === 0 ? (
						<p className="tbsh-muted">{ __( 'No incident data available.', 'tbsh-compliance-audit-logger' ) }</p>
					) : (
						<>
							<div
								className="tbsh-bar-chart"
								role="img"
								aria-label={ months
									.map( ( [ k, c ] ) =>
										sprintf(
											/* translators: 1: month, 2: number of incidents */
											__( '%1$s: %2$d', 'tbsh-compliance-audit-logger' ),
											monthLabel( k ),
											c
										)
									)
									.join( ', ' ) }
							>
								{ months.map( ( [ k, c ] ) => (
									<div
										key={ k }
										className="tbsh-bar-chart-bar"
										style={ { height: max > 0 ? `${ ( c / max ) * 100 }%` : '0%' } }
										title={ sprintf(
											/* translators: 1: month, 2: number of incidents */
											_n( '%1$s: %2$d incident', '%1$s: %2$d incidents', c, 'tbsh-compliance-audit-logger' ),
											monthLabel( k ),
											c
										) }
									/>
								) ) }
							</div>
							<div className="tbsh-bar-chart-labels">
								{ months.map( ( [ k, c ] ) => (
									<span key={ k }>{ `${ monthLabel( k ) } (${ c })` }</span>
								) ) }
							</div>
							{ 0 === totalIncidents && (
								<p className="tbsh-muted" style={ { marginBottom: 0 } }>
									{ __( 'No incidents were recorded in this period.', 'tbsh-compliance-audit-logger' ) }
								</p>
							) }
						</>
					) }
				</div>
			</>
		);
	};

	const completed = 'completed' === form.status;

	const renderDrills = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 5 } cols={ 6 } />
			);
		}
		if ( error ) {
			return (
				<div style={ { padding: '0 0 20px', textAlign: 'center' } }>
					<EmptyState title={ __( 'Could not load drills', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setReloadKey( ( k ) => k + 1 ) }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( items.length === 0 ) {
			return (
				<EmptyState
					title={ __( 'No drills recorded yet', 'tbsh-compliance-audit-logger' ) }
					description={ __( 'Record backup restores, failovers, incident-response and tabletop exercises with their RTO/RPO targets and results to build your testing evidence.', 'tbsh-compliance-audit-logger' ) }
					icon="resilience"
				/>
			);
		}
		return (
			<>
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Drill', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Scheduled / completed', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'RTO', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'RPO', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Outcome', 'tbsh-compliance-audit-logger' ) }</th>
							{ canManage && <th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th> }
						</tr>
					</thead>
					<tbody>
						{ items.map( ( d ) => (
							<tr key={ d.id }>
								<td>
									<strong>{ d.title }</strong>
									<div className="tbsh-muted">{ scenarioLabel( d.scenario ) }</div>
								</td>
								<td>
									<span className={ `tbsh-badge tbsh-badge-${ statusBadge( d.status ) }` }>{ statusLabel( d.status ) }</span>
								</td>
								<td>
									<div>
										{ sprintf(
											/* translators: %s: date and time */
											__( 'Scheduled: %s', 'tbsh-compliance-audit-logger' ),
											d.scheduled_at || '—'
										) }
									</div>
									{ d.completed_at && (
										<div className="tbsh-muted">
											{ sprintf(
												/* translators: %s: date and time */
												__( 'Completed: %s', 'tbsh-compliance-audit-logger' ),
												d.completed_at
											) }
										</div>
									) }
								</td>
								<td>
									<TargetActual target={ d.rto_target_min } actual={ d.rto_actual_min } met={ d.rto_met } />
								</td>
								<td>
									<TargetActual target={ d.rpo_target_min } actual={ d.rpo_actual_min } met={ d.rpo_met } />
								</td>
								<td>
									{ d.outcome ? (
										<span className={ `tbsh-badge tbsh-badge-${ outcomeBadge( d.outcome ) }` }>{ outcomeLabel( d.outcome ) }</span>
									) : (
										<span className="tbsh-muted">—</span>
									) }
								</td>
								{ canManage && (
									<td style={ { textAlign: 'end', whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
											onClick={ () => openEdit( d ) }
											aria-label={ sprintf(
												/* translators: %s: drill title */
												__( 'Edit drill %s', 'tbsh-compliance-audit-logger' ),
												d.title
											) }
										>
											<Icon name="edit" />
										</button>{ ' ' }
										<button
											type="button"
											className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
											onClick={ () => setToDelete( d ) }
											aria-label={ sprintf(
												/* translators: %s: drill title */
												__( 'Delete drill %s', 'tbsh-compliance-audit-logger' ),
												d.title
											) }
										>
											<Icon name="trash" />
										</button>
									</td>
								) }
							</tr>
						) ) }
					</tbody>
				</table>
				{ totalPages > 1 && (
					<div className="tbsh-pagination">
						<span className="tbsh-muted">
							{ sprintf(
								/* translators: %d: number of drills */
								_n( '%d drill', '%d drills', total, 'tbsh-compliance-audit-logger' ),
								total
							) }
						</span>
						<div className="tbsh-row">
							<button
								type="button"
								className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
								disabled={ page <= 1 }
								onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
							>
								{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
							</button>
							<span className="tbsh-muted">
								{ sprintf(
									/* translators: 1: current page, 2: total pages */
									__( 'Page %1$d of %2$d', 'tbsh-compliance-audit-logger' ),
									page,
									totalPages
								) }
							</span>
							<button
								type="button"
								className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
								disabled={ page >= totalPages }
								onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
							>
								{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</>
		);
	};

	return (
		<div className="tbsh-resilience-page">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-row-between" style={ { marginBottom: '20px' } }>
				<div>
					<h3 className="tbsh-section-title" style={ { marginBottom: '4px' } }>
						{ __( 'Operational Resilience', 'tbsh-compliance-audit-logger' ) }
					</h3>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'Digital operational resilience testing evidence (DORA Art. 24–25). Every metric on this page is computed from the drills recorded below, the incidents in the incident register and successful off-site vault backups — nothing is estimated.', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
				{ canManage && (
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ openCreate }>
						<Icon name="plus" />
						{ __( 'Record drill', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			{ renderMetrics() }

			<h4 className="tbsh-section-title">{ __( 'Resilience drills', 'tbsh-compliance-audit-logger' ) }</h4>
			<div className="tbsh-table-container">
				{ renderDrills() }
			</div>

			<Modal title={ editing && editing.id ? __( 'Edit drill', 'tbsh-compliance-audit-logger' ) : __( 'Record drill', 'tbsh-compliance-audit-logger' ) } isOpen={ !! editing } onClose={ closeForm }>
				<form onSubmit={ submitForm }>
					{ formError && <Notice type="error" message={ formError } /> }
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-drill-title">
							{ __( 'Title', 'tbsh-compliance-audit-logger' ) }
						</label>
						<input
							id="tbsh-drill-title"
							type="text"
							className="tbsh-form-control"
							value={ form.title }
							onChange={ ( e ) => setField( 'title', e.target.value ) }
							required
						/>
					</div>
					<div className="tbsh-grid-2" style={ { marginBottom: 0 } }>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-scenario">
								{ __( 'Scenario', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-drill-scenario"
								className="tbsh-form-control"
								value={ form.scenario }
								onChange={ ( e ) => setField( 'scenario', e.target.value ) }
							>
								{ SCENARIOS.map( ( s ) => (
									<option key={ s } value={ s }>
										{ scenarioLabel( s ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-status">
								{ __( 'Status', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-drill-status"
								className="tbsh-form-control"
								value={ form.status }
								onChange={ ( e ) => setField( 'status', e.target.value ) }
							>
								{ STATUSES.map( ( s ) => (
									<option key={ s } value={ s }>
										{ statusLabel( s ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-scheduled">
								{ __( 'Scheduled for', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-drill-scheduled"
								type="datetime-local"
								className="tbsh-form-control"
								value={ form.scheduled_at }
								onChange={ ( e ) => setField( 'scheduled_at', e.target.value ) }
							/>
						</div>
						{ completed && (
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-drill-completed">
									{ __( 'Completed at', 'tbsh-compliance-audit-logger' ) }
								</label>
								<input
									id="tbsh-drill-completed"
									type="datetime-local"
									className="tbsh-form-control"
									value={ form.completed_at }
									onChange={ ( e ) => setField( 'completed_at', e.target.value ) }
								/>
								<p className="tbsh-muted" style={ { margin: '6px 0 0' } }>
									{ __( 'Leave empty to use the current time.', 'tbsh-compliance-audit-logger' ) }
								</p>
							</div>
						) }
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-rto-target">
								{ __( 'RTO target (minutes)', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-drill-rto-target"
								type="number"
								min="0"
								className="tbsh-form-control"
								value={ form.rto_target_min }
								onChange={ ( e ) => setField( 'rto_target_min', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-rto-actual">
								{ __( 'RTO actual (minutes)', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-drill-rto-actual"
								type="number"
								min="0"
								className="tbsh-form-control"
								value={ form.rto_actual_min }
								onChange={ ( e ) => setField( 'rto_actual_min', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-rpo-target">
								{ __( 'RPO target (minutes)', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-drill-rpo-target"
								type="number"
								min="0"
								className="tbsh-form-control"
								value={ form.rpo_target_min }
								onChange={ ( e ) => setField( 'rpo_target_min', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-drill-rpo-actual">
								{ __( 'RPO actual (minutes)', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-drill-rpo-actual"
								type="number"
								min="0"
								className="tbsh-form-control"
								value={ form.rpo_actual_min }
								onChange={ ( e ) => setField( 'rpo_actual_min', e.target.value ) }
							/>
						</div>
						{ completed && (
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-drill-outcome">
									{ __( 'Outcome', 'tbsh-compliance-audit-logger' ) }
								</label>
								<select
									id="tbsh-drill-outcome"
									className="tbsh-form-control"
									value={ form.outcome }
									onChange={ ( e ) => setField( 'outcome', e.target.value ) }
								>
									<option value="">{ __( 'Not assessed', 'tbsh-compliance-audit-logger' ) }</option>
									{ OUTCOMES.map( ( o ) => (
										<option key={ o } value={ o }>
											{ outcomeLabel( o ) }
										</option>
									) ) }
								</select>
							</div>
						) }
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-drill-notes">
							{ __( 'Notes and lessons learned', 'tbsh-compliance-audit-logger' ) }
						</label>
						<textarea
							id="tbsh-drill-notes"
							className="tbsh-form-control"
							rows={ 4 }
							value={ form.notes }
							onChange={ ( e ) => setField( 'notes', e.target.value ) }
						/>
					</div>
					<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeForm } disabled={ saving }>
							{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
						</button>
						<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ saving }>
							{ saving ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save drill', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</form>
			</Modal>

			<Modal title={ __( 'Delete drill', 'tbsh-compliance-audit-logger' ) } isOpen={ !! toDelete } onClose={ closeDelete }>
				{ toDelete && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: drill title */
								__( 'Delete the drill "%s"? It will no longer count towards your resilience metrics or testing evidence.', 'tbsh-compliance-audit-logger' ),
								toDelete.title
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeDelete } disabled={ deleting }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ confirmDelete } disabled={ deleting }>
								{ deleting ? __( 'Deleting…', 'tbsh-compliance-audit-logger' ) : __( 'Delete', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
