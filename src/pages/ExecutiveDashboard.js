import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { get } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';


/**
 * Progress bar colour for a 0–100 score.
 *
 * @param {number} score Score.
 * @return {string} Modifier class.
 */
const scoreClass = ( score ) => {
	if ( score >= 85 ) {
		return 'is-success';
	}
	if ( score >= 60 ) {
		return 'is-warning';
	}
	return 'is-danger';
};

const scoreColor = ( score ) => {
	if ( score >= 85 ) {
		return 'var(--tbsh-success)';
	}
	if ( score >= 60 ) {
		return 'var(--tbsh-warning)';
	}
	return 'var(--tbsh-danger)';
};

// Where each scorecard component is managed.
const COMPONENT_ROUTES = {
	monitoring: 'compliance',
	integrity: 'integrity',
	access: 'compliance',
	incidents: 'incidents',
	risk: 'risks',
	vendors: 'vendors',
	resilience: 'resilience',
};

const bandLabel = ( band ) => {
	const labels = {
		strong: __( 'Strong', 'tbsh-compliance-audit-logger' ),
		good: __( 'Good', 'tbsh-compliance-audit-logger' ),
		fair: __( 'Fair', 'tbsh-compliance-audit-logger' ),
		weak: __( 'Weak', 'tbsh-compliance-audit-logger' ),
	};
	return labels[ band ] || band;
};

const bandBadge = ( band ) => {
	const map = { strong: 'success', good: 'info', fair: 'warning', weak: 'danger' };
	return map[ band ] || 'info';
};

const integrityStatusLabel = ( status ) => {
	const labels = {
		verified: __( 'Verified', 'tbsh-compliance-audit-logger' ),
		ok: __( 'Verified', 'tbsh-compliance-audit-logger' ),
		compromised: __( 'Compromised', 'tbsh-compliance-audit-logger' ),
		fail: __( 'Mismatches found', 'tbsh-compliance-audit-logger' ),
		error: __( 'Error', 'tbsh-compliance-audit-logger' ),
		unverified: __( 'Not verified yet', 'tbsh-compliance-audit-logger' ),
	};
	return labels[ status ] || status || __( 'Unknown', 'tbsh-compliance-audit-logger' );
};

const integrityStatusColour = ( status ) => {
	if ( 'verified' === status || 'ok' === status ) {
		return 'success';
	}
	if ( 'unverified' === status || ! status ) {
		return 'warning';
	}
	return 'danger';
};

const stageLabel = ( stage ) => {
	const labels = {
		early_warning: __( 'Early warning (24h)', 'tbsh-compliance-audit-logger' ),
		notification: __( 'Incident notification (72h)', 'tbsh-compliance-audit-logger' ),
		final_report: __( 'Final report (1 month)', 'tbsh-compliance-audit-logger' ),
	};
	return labels[ stage ] || stage;
};

export default function ExecutiveDashboard( { navigate } ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	const go = ( route ) => {
		if ( 'function' === typeof navigate ) {
			navigate( route );
		}
	};

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		get( '/executive' )
			.then( ( res ) => setData( res ) )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'Failed to load the executive scorecard.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	if ( loading ) {
		return <SkeletonLoader rows={ 6 } cols={ 4 } />;
	}

	if ( error || ! data ) {
		return (
			<div className="tbsh-card">
				<EmptyState title={ __( 'Error Loading Data', 'tbsh-compliance-audit-logger' ) } description={ error || __( 'No scorecard data available.', 'tbsh-compliance-audit-logger' ) } icon="alert" />
				<div className="tbsh-row" style={ { justifyContent: 'center' } }>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);
	}

	const kpis = data.kpis || {};
	const components = data.components || {};
	const frameworks = data.frameworks || {};
	const overdue = Array.isArray( data.nis2_overdue_items ) ? data.nis2_overdue_items : [];
	const overall = Number( data.overall ) || 0;

	const countStatus = ( value, dangerAt = 1 ) => {
		const n = Number( value ) || 0;
		if ( n >= dangerAt ) {
			return 'danger';
		}
		return 'success';
	};

	return (
		<div className="tbsh-executive-page">
			{ /* Overall score */ }
			<div className="tbsh-card" style={ { marginBottom: '24px' } }>
				<div className="tbsh-row-between">
					<div>
						<h3 className="tbsh-section-title">{ __( 'Overall compliance posture', 'tbsh-compliance-audit-logger' ) }</h3>
						<div className="tbsh-row" style={ { alignItems: 'baseline', gap: '12px' } }>
							<span style={ { fontSize: '56px', fontWeight: 800, lineHeight: 1, color: scoreColor( overall ) } }>
								{ overall }
							</span>
							<span className="tbsh-muted">{ __( 'out of 100', 'tbsh-compliance-audit-logger' ) }</span>
							<span className={ `tbsh-badge tbsh-badge-${ bandBadge( data.band ) }` }>{ bandLabel( data.band ) }</span>
						</div>
						{ data.summary && <p style={ { margin: '12px 0 0' } }>{ data.summary }</p> }
					</div>
					{ data.generated_at && (
						<span className="tbsh-muted">
							{ /* translators: %s: date and time */ }
							{ sprintf( __( 'Calculated %s', 'tbsh-compliance-audit-logger' ), data.generated_at ) }
						</span>
					) }
				</div>
				<div className="tbsh-progress" style={ { marginTop: '16px' } }>
					<div className={ `tbsh-progress-bar ${ scoreClass( overall ) }` } style={ { width: `${ overall }%` } } />
				</div>
			</div>

			{ /* KPIs */ }
			<div className="tbsh-dashboard-grid">
				<MetricCard
					title={ __( 'Open incidents', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.open_incidents ?? 0 }
					icon="incidents"
					status={ ( Number( kpis.open_incidents ) || 0 ) > 0 ? 'warning' : 'success' }
					desc={ __( 'Not yet resolved or closed', 'tbsh-compliance-audit-logger' ) }
				/>
				<MetricCard
					title={ __( 'Critical open incidents', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.critical_open ?? 0 }
					icon="alert"
					status={ countStatus( kpis.critical_open ) }
				/>
				<MetricCard
					title={ __( 'Overdue NIS2 stages', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.nis2_overdue ?? 0 }
					icon="calendar"
					status={ countStatus( kpis.nis2_overdue ) }
				/>
				<MetricCard
					title={ __( 'Active risks', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.active_risks ?? 0 }
					icon="risk"
					status={ ( Number( kpis.active_risks ) || 0 ) > 0 ? 'warning' : 'success' }
				/>
				<MetricCard
					title={ __( 'Critical risks', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.critical_risks ?? 0 }
					icon="risk"
					status={ countStatus( kpis.critical_risks ) }
				/>
				<MetricCard
					title={ __( 'Critical vendors', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.critical_vendors ?? 0 }
					icon="vendors"
					desc={ __( 'Active vendors rated high criticality', 'tbsh-compliance-audit-logger' ) }
				/>
				<MetricCard
					title={ __( 'Log chain', 'tbsh-compliance-audit-logger' ) }
					value={ integrityStatusLabel( kpis.chain_status ) }
					icon="integrity"
					status={ integrityStatusColour( kpis.chain_status ) }
				/>
				<MetricCard
					title={ __( 'Core files', 'tbsh-compliance-audit-logger' ) }
					value={ integrityStatusLabel( kpis.core_status ) }
					icon="shield"
					status={ integrityStatusColour( kpis.core_status ) }
				/>
				<MetricCard
					title={ __( 'Last off-site backup', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.last_backup || __( 'None yet', 'tbsh-compliance-audit-logger' ) }
					icon="database"
					status={ kpis.last_backup ? 'success' : 'warning' }
				/>
				<MetricCard
					title={ __( 'Mean time to resolve', 'tbsh-compliance-audit-logger' ) }
					value={
						null === kpis.mttr_hours || undefined === kpis.mttr_hours
							? '—'
							: /* translators: %s: number of hours */
							  sprintf( __( '%s h', 'tbsh-compliance-audit-logger' ), kpis.mttr_hours )
					}
					icon="timeline"
					desc={ null === kpis.mttr_hours || undefined === kpis.mttr_hours ? __( 'No resolved incidents yet', 'tbsh-compliance-audit-logger' ) : __( 'Average across resolved incidents', 'tbsh-compliance-audit-logger' ) }
				/>
				<MetricCard
					title={ __( 'Failed logins (7 days)', 'tbsh-compliance-audit-logger' ) }
					value={ kpis.failed_logins_7d ?? 0 }
					icon="lock"
					status={ ( Number( kpis.failed_logins_7d ) || 0 ) > 0 ? 'warning' : 'success' }
				/>
			</div>

			{ /* Score components */ }
			<h3 className="tbsh-section-title">{ __( 'What drives the score', 'tbsh-compliance-audit-logger' ) }</h3>
			<div className="tbsh-grid-3">
				{ Object.keys( components ).map( ( key ) => {
					const comp = components[ key ] || {};
					const score = Number( comp.score ) || 0;
					const route = COMPONENT_ROUTES[ key ];
					const inputs = Array.isArray( comp.inputs ) ? comp.inputs : [];
					return (
						<div key={ key } className="tbsh-card">
							<div className="tbsh-row-between">
								<strong>{ comp.label || key }</strong>
								<span style={ { fontWeight: 700, color: scoreColor( score ) } }>{ score }</span>
							</div>
							<div className="tbsh-progress" style={ { margin: '10px 0 12px' } }>
								<div className={ `tbsh-progress-bar ${ scoreClass( score ) }` } style={ { width: `${ score }%` } } />
							</div>
							{ inputs.length > 0 ? (
								<ul style={ { listStyle: 'none', margin: 0, padding: 0 } }>
									{ inputs.map( ( input, idx ) => (
										<li key={ idx } className="tbsh-row" style={ { alignItems: 'flex-start', flexWrap: 'nowrap', marginBottom: '6px', fontSize: '13px' } }>
											<Icon
												name={ input.ok ? 'check' : 'alert' }
												style={ { width: '16px', height: '16px', flexShrink: 0, marginTop: '1px', color: input.ok ? 'var(--tbsh-success)' : 'var(--tbsh-danger)' } }
											/>
											<span>
												<span className="screen-reader-text">{ input.ok ? __( 'Passing:', 'tbsh-compliance-audit-logger' ) : __( 'Needs attention:', 'tbsh-compliance-audit-logger' ) } </span>
												{ input.label }
											</span>
										</li>
									) ) }
								</ul>
							) : (
								<p className="tbsh-muted" style={ { margin: 0 } }>{ __( 'No inputs reported.', 'tbsh-compliance-audit-logger' ) }</p>
							) }
							{ route && (
								<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '12px' } } onClick={ () => go( route ) }>
									{ __( 'Open', 'tbsh-compliance-audit-logger' ) }
								</button>
							) }
						</div>
					);
				} ) }
			</div>

			<div className="tbsh-grid-2">
				{ /* Framework coverage */ }
				<div className="tbsh-card">
					<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
						<h3 className="tbsh-section-title" style={ { margin: 0 } }>{ __( 'Framework coverage', 'tbsh-compliance-audit-logger' ) }</h3>
						<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => go( 'compliance' ) }>
							{ __( 'View controls', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
					{ Object.keys( frameworks ).length > 0 ? (
						<div className="tbsh-stack">
							{ Object.keys( frameworks ).map( ( key ) => {
								const fw = frameworks[ key ] || {};
								const cov = Number( fw.coverage ) || 0;
								return (
									<div key={ key }>
										<div className="tbsh-row-between" style={ { fontSize: '13px', marginBottom: '4px' } }>
											<span>{ fw.name || key }</span>
											<strong>{ cov }%</strong>
										</div>
										<div className="tbsh-progress">
											<div className={ `tbsh-progress-bar ${ scoreClass( cov ) }` } style={ { width: `${ cov }%` } } />
										</div>
									</div>
								);
							} ) }
							<p className="tbsh-muted" style={ { margin: '12px 0 0' } }>
								{ __( 'Share of mapped controls whose readiness checks all pass.', 'tbsh-compliance-audit-logger' ) }
							</p>
						</div>
					) : (
						<EmptyState title={ __( 'No framework data', 'tbsh-compliance-audit-logger' ) } icon="compliance" />
					) }
				</div>

				{ /* NIS2 overdue */ }
				<div className="tbsh-card">
					<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
						<h3 className="tbsh-section-title" style={ { margin: 0 } }>{ __( 'Overdue NIS2 reporting stages', 'tbsh-compliance-audit-logger' ) }</h3>
						<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => go( 'incidents' ) }>
							{ __( 'Incident Center', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
					{ overdue.length > 0 ? (
						<div className="tbsh-table-container">
							<table className="tbsh-table">
								<thead>
									<tr>
										<th>{ __( 'Incident', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Stage', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Due', 'tbsh-compliance-audit-logger' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ overdue.map( ( item ) => (
										<tr key={ `${ item.incident_id }-${ item.stage }` }>
											<td>
												<button
													type="button"
													className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
													onClick={ () => go( 'incidents' ) }
													/* translators: 1: incident ID, 2: incident title */
													aria-label={ sprintf( __( 'Open incident #%1$d: %2$s', 'tbsh-compliance-audit-logger' ), item.incident_id, item.title ) }
												>
													#{ item.incident_id }
												</button>{ ' ' }
												{ item.title }
											</td>
											<td>
												<span className="tbsh-badge tbsh-badge-danger">{ item.label || stageLabel( item.stage ) }</span>
											</td>
											<td>{ item.due_at }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) : (
						<EmptyState title={ __( 'Nothing overdue', 'tbsh-compliance-audit-logger' ) } description={ __( 'All NIS2 reporting stages for significant incidents are on time.', 'tbsh-compliance-audit-logger' ) } icon="check" />
					) }
				</div>
			</div>
		</div>
	);
}
