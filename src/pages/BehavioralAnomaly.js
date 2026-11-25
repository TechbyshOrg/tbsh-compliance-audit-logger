import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { get, post, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import MetricCard from '../components/MetricCard';
import Notice from '../components/Notice';


const pad = ( h ) => String( h ).padStart( 2, '0' );

/**
 * Render a list of hours (0–23) as compact ranges, e.g. [8,9,10,14] → "08–11, 14–15".
 * A range that wraps past midnight (e.g. 22, 23, 0, 1) is shown as "22–02".
 *
 * @param {number[]} hours Hours.
 * @return {string} Ranges.
 */
const formatHours = ( hours ) => {
	const sorted = Array.from( new Set( ( hours || [] ).map( ( h ) => parseInt( h, 10 ) ).filter( ( h ) => h >= 0 && h <= 23 ) ) ).sort( ( a, b ) => a - b );
	if ( ! sorted.length ) {
		return '';
	}
	if ( 24 === sorted.length ) {
		return '00–24';
	}
	const ranges = [];
	let start = sorted[ 0 ];
	let prev = sorted[ 0 ];
	for ( let i = 1; i < sorted.length; i++ ) {
		if ( sorted[ i ] === prev + 1 ) {
			prev = sorted[ i ];
			continue;
		}
		ranges.push( [ start, prev ] );
		start = sorted[ i ];
		prev = sorted[ i ];
	}
	ranges.push( [ start, prev ] );

	// Merge a range ending at 23 with one starting at 0.
	if ( ranges.length > 1 && 0 === ranges[ 0 ][ 0 ] && 23 === ranges[ ranges.length - 1 ][ 1 ] ) {
		const first = ranges.shift();
		ranges[ ranges.length - 1 ][ 1 ] = first[ 1 ];
	}

	return ranges.map( ( [ a, b ] ) => `${ pad( a ) }–${ pad( b + 1 ) }` ).join( ', ' );
};

export default function BehavioralAnomaly() {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ rebuilding, setRebuilding ] = useState( false );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );

	const canManage = can( 'manage_settings' );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		get( '/analytics/anomalies', { page } )
			.then( ( res ) => setData( res ) )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'Failed to load anomaly data.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setLoading( false ) );
	}, [ page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const rebuild = () => {
		setRebuilding( true );
		setNotice( { type: '', message: '' } );
		post( '/analytics/anomalies' )
			.then( ( res ) => {
				const updated = Number( res && res.updated ) || 0;
				setNotice( {
					type: 'success',
					message: sprintf(
						/* translators: %d: number of users */
						_n( 'Baselines rebuilt for %d user.', 'Baselines rebuilt for %d users.', updated, 'tbsh-compliance-audit-logger' ),
						updated
					),
				} );
				load();
			} )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'Could not rebuild baselines.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => setRebuilding( false ) );
	};

	if ( loading && ! data ) {
		return <SkeletonLoader rows={ 6 } cols={ 4 } />;
	}

	if ( error && ! data ) {
		return (
			<div className="tbsh-card">
				<EmptyState title={ __( 'Error Loading Data', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
				<div className="tbsh-row" style={ { justifyContent: 'center' } }>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);
	}

	const items = Array.isArray( data.items ) ? data.items : [];
	const baselines = Array.isArray( data.baselines ) ? data.baselines : [];
	const totalPages = Number( data.total_pages ) || 1;

	return (
		<div className="tbsh-anomaly-page">
			<Notice type={ notice.type } message={ notice.message } />
			{ error && <Notice type="error" message={ error } /> }

			{ ! data.enabled && (
				<Notice
					type="info"
					message={ __( 'Anomaly detection is currently off, so new actions are not being scored. Baselines and past anomalies are still shown below. Turn it on in Settings → Anomaly detection.', 'tbsh-compliance-audit-logger' ) }
				/>
			) }

			<div className="tbsh-dashboard-grid">
				<MetricCard
					title={ __( 'Anomalies (7 days)', 'tbsh-compliance-audit-logger' ) }
					value={ data.last_7_days ?? 0 }
					icon="anomaly"
					status={ ( Number( data.last_7_days ) || 0 ) > 0 ? 'warning' : 'success' }
				/>
				<MetricCard title={ __( 'Anomalies (all time)', 'tbsh-compliance-audit-logger' ) } value={ data.total ?? 0 } icon="alert" />
				<MetricCard title={ __( 'Users with baselines', 'tbsh-compliance-audit-logger' ) } value={ baselines.length } icon="user" />
				<MetricCard
					title={ __( 'Baselines updated', 'tbsh-compliance-audit-logger' ) }
					value={ data.baselines_updated || __( 'Never', 'tbsh-compliance-audit-logger' ) }
					icon="calendar"
				/>
			</div>

			<div className="tbsh-card" style={ { marginBottom: '24px' } }>
				<h3 className="tbsh-section-title">{ __( 'How scoring works', 'tbsh-compliance-audit-logger' ) }</h3>
				<p className="tbsh-muted" style={ { marginTop: 0 } }>
					{ __( 'Every action by a logged-in user is compared with that user’s own baseline (built from their recent history). Users need at least 5 active days of history before they are scored. Points are added for:', 'tbsh-compliance-audit-logger' ) }
				</p>
				<ul style={ { margin: '0 0 12px 18px', listStyle: 'disc', fontSize: '13px' } }>
					<li>{ __( '+25 — action outside the user’s usual working hours', 'tbsh-compliance-audit-logger' ) }</li>
					<li>{ __( '+40 — an action the user has never performed before, or +20 if they perform it rarely (under 2% of their activity)', 'tbsh-compliance-audit-logger' ) }</li>
					<li>{ __( '+25 — a burst of activity well above the user’s normal hourly rate', 'tbsh-compliance-audit-logger' ) }</li>
				</ul>
				<p className="tbsh-muted" style={ { margin: 0 } }>
					{ __( 'A score of 60 or more is logged as an anomaly (warning). A score of 85 or more is logged at error level and automatically opens an incident in the Incident Center.', 'tbsh-compliance-audit-logger' ) }
				</p>
			</div>

			<div className="tbsh-card" style={ { marginBottom: '24px' } }>
				<h3 className="tbsh-section-title">{ __( 'Detected anomalies', 'tbsh-compliance-audit-logger' ) }</h3>
				{ items.length > 0 ? (
					<>
						<div className="tbsh-table-container">
							<table className="tbsh-table">
								<thead>
									<tr>
										<th>{ __( 'Date', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'User', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Action', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Score', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Reasons', 'tbsh-compliance-audit-logger' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ items.map( ( item ) => {
										const score = Number( item.score ) || 0;
										const reasons = Array.isArray( item.reasons ) ? item.reasons : [];
										return (
											<tr key={ item.id }>
												<td>{ item.created_at }</td>
												<td>{ item.username || '—' }</td>
												<td><code>{ item.action || '—' }</code></td>
												<td>
													<span className={ `tbsh-badge tbsh-badge-${ score >= 85 ? 'danger' : 'warning' }` }>
																												{ sprintf( /* translators: %d: anomaly score */ __( '%d/100', 'tbsh-compliance-audit-logger' ), score ) }
													</span>
												</td>
												<td>
													{ reasons.length > 0 ? (
														<ul style={ { margin: 0, paddingInlineStart: '16px', listStyle: 'disc' } }>
															{ reasons.map( ( r, idx ) => (
																<li key={ idx }>{ r }</li>
															) ) }
														</ul>
													) : (
														'—'
													) }
												</td>
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
						{ totalPages > 1 && (
							<div className="tbsh-pagination" style={ { marginTop: '16px' } }>
								<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page === 1 || loading } onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }>
									{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
								</button>
								<span style={ { fontSize: '13px', padding: '0 12px' } }>{ page } / { totalPages }</span>
								<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page >= totalPages || loading } onClick={ () => setPage( ( p ) => p + 1 ) }>
									{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
								</button>
							</div>
						) }
					</>
				) : (
					<EmptyState
						title={ __( 'No anomalies detected', 'tbsh-compliance-audit-logger' ) }
						description={ __( 'No user action has reached the anomaly threshold yet.', 'tbsh-compliance-audit-logger' ) }
						icon="anomaly"
					/>
				) }
			</div>

			<div className="tbsh-card">
				<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
					<h3 className="tbsh-section-title" style={ { margin: 0 } }>{ __( 'User behaviour baselines', 'tbsh-compliance-audit-logger' ) }</h3>
					{ canManage && (
						<button type="button" className="tbsh-btn tbsh-btn-primary tbsh-btn-sm" onClick={ rebuild } disabled={ rebuilding }>
							{ rebuilding ? __( 'Rebuilding…', 'tbsh-compliance-audit-logger' ) : __( 'Rebuild baselines now', 'tbsh-compliance-audit-logger' ) }
						</button>
					) }
				</div>
				<p className="tbsh-muted" style={ { marginTop: 0 } }>
					{ __( 'Baselines are rebuilt automatically every day from recent activity. Usual hours are shown in site time.', 'tbsh-compliance-audit-logger' ) }
				</p>
				{ baselines.length > 0 ? (
					<div className="tbsh-table-container">
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'User', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Usual hours', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Avg. actions / day', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Distinct actions', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Active days', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Updated', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ baselines.map( ( b ) => (
									<tr key={ b.user_id }>
										<td>
											{ b.display_name || b.user_login }
											{ b.display_name && b.user_login && b.display_name !== b.user_login && (
												<span className="tbsh-muted"> ({ b.user_login })</span>
											) }
										</td>
										<td>{ formatHours( b.usual_hours ) || '—' }</td>
										<td>{ b.avg_daily_actions }</td>
										<td>{ b.distinct_actions }</td>
										<td>
											{ b.active_days }
											{ ( Number( b.active_days ) || 0 ) < 5 && (
												<span className="tbsh-badge tbsh-badge-info" style={ { marginInlineStart: '6px' } }>
													{ __( 'Learning', 'tbsh-compliance-audit-logger' ) }
												</span>
											) }
										</td>
										<td>{ b.updated_at || '—' }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : (
					<EmptyState
						title={ __( 'No baselines yet', 'tbsh-compliance-audit-logger' ) }
						description={ __( 'Baselines are built from logged-in user activity. They appear after users have been active on the site.', 'tbsh-compliance-audit-logger' ) }
						icon="user"
					/>
				) }
			</div>
		</div>
	);
}
