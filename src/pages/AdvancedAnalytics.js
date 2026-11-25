import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { get } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import MetricCard from '../components/MetricCard';


const PERIODS = [ 7, 30, 90 ];

const seriesDefs = () => [
	{ key: 'events', label: __( 'Events', 'tbsh-compliance-audit-logger' ), icon: 'logs' },
	{ key: 'critical', label: __( 'Critical events', 'tbsh-compliance-audit-logger' ), icon: 'alert' },
	{ key: 'incidents', label: __( 'Incidents', 'tbsh-compliance-audit-logger' ), icon: 'incidents' },
	{ key: 'failed_logins', label: __( 'Failed logins', 'tbsh-compliance-audit-logger' ), icon: 'lock' },
	{ key: 'open_risks', label: __( 'Open risks', 'tbsh-compliance-audit-logger' ), icon: 'risk' },
];

export default function AdvancedAnalytics() {
	const [ days, setDays ] = useState( 30 );
	const [ series, setSeries ] = useState( 'events' );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		get( '/analytics/trends', { days } )
			.then( ( res ) => setData( res ) )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'Failed to load analytics.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setLoading( false ) );
	}, [ days ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const defs = seriesDefs();
	const current = defs.find( ( d ) => d.key === series ) || defs[ 0 ];

	const toolbar = (
		<div className="tbsh-filters-bar">
			<label htmlFor="tbsh-analytics-period" className="tbsh-form-label" style={ { margin: 0 } }>
				{ __( 'Period', 'tbsh-compliance-audit-logger' ) }
			</label>
			<select
				id="tbsh-analytics-period"
				className="tbsh-filter-input"
				value={ days }
				onChange={ ( e ) => setDays( parseInt( e.target.value, 10 ) ) }
			>
				{ PERIODS.map( ( p ) => (
					<option key={ p } value={ p }>
												{ sprintf( /* translators: %d: number of days */ __( 'Last %d days', 'tbsh-compliance-audit-logger' ), p ) }
					</option>
				) ) }
			</select>
			<div className="tbsh-tabs" role="tablist" aria-label={ __( 'Data series', 'tbsh-compliance-audit-logger' ) } style={ { marginBottom: 0 } }>
				{ defs.map( ( d ) => (
					<button
						key={ d.key }
						type="button"
						role="tab"
						aria-selected={ series === d.key }
						className={ `tbsh-tab-btn ${ series === d.key ? 'active' : '' }` }
						onClick={ () => setSeries( d.key ) }
					>
						{ d.label }
					</button>
				) ) }
			</div>
		</div>
	);

	if ( loading ) {
		return (
			<div>
				{ toolbar }
				<SkeletonLoader rows={ 6 } cols={ 4 } />
			</div>
		);
	}

	if ( error || ! data ) {
		return (
			<div>
				{ toolbar }
				<div className="tbsh-card">
					<EmptyState title={ __( 'Error Loading Data', 'tbsh-compliance-audit-logger' ) } description={ error || __( 'No analytics data available.', 'tbsh-compliance-audit-logger' ) } icon="alert" />
					<div className="tbsh-row" style={ { justifyContent: 'center' } }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
							{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</div>
			</div>
		);
	}

	const labels = Array.isArray( data.labels ) ? data.labels : [];
	const values = Array.isArray( data[ series ] ) ? data[ series ].map( ( v ) => Number( v ) || 0 ) : [];
	const max = values.length ? Math.max( ...values ) : 0;
	const totals = data.totals || {};
	const categories = Array.isArray( data.categories ) ? data.categories : [];
	const categoryTotal = categories.reduce( ( sum, c ) => sum + ( Number( c.count ) || 0 ), 0 );
	const mid = Math.floor( ( labels.length - 1 ) / 2 );

	return (
		<div className="tbsh-analytics-page">
			{ toolbar }

			<div className="tbsh-dashboard-grid">
				<MetricCard title={ __( 'Events', 'tbsh-compliance-audit-logger' ) } value={ totals.events ?? 0 } icon="logs" />
				<MetricCard
					title={ __( 'Critical events', 'tbsh-compliance-audit-logger' ) }
					value={ totals.critical ?? 0 }
					icon="alert"
					status={ ( Number( totals.critical ) || 0 ) > 0 ? 'danger' : 'success' }
				/>
				<MetricCard
					title={ __( 'Incidents opened', 'tbsh-compliance-audit-logger' ) }
					value={ totals.incidents ?? 0 }
					icon="incidents"
					status={ ( Number( totals.incidents ) || 0 ) > 0 ? 'warning' : 'success' }
				/>
				<MetricCard
					title={ __( 'Failed logins', 'tbsh-compliance-audit-logger' ) }
					value={ totals.failed_logins ?? 0 }
					icon="lock"
					status={ ( Number( totals.failed_logins ) || 0 ) > 0 ? 'warning' : 'success' }
				/>
			</div>

			<div className="tbsh-card" style={ { marginBottom: '24px' } }>
				<div className="tbsh-row-between" style={ { marginBottom: '8px' } }>
					<h3 className="tbsh-section-title" style={ { margin: 0 } }>
												{ sprintf( /* translators: 1: series name, 2: number of days */ __( '%1$s per day — last %2$d days', 'tbsh-compliance-audit-logger' ), current.label, data.days || days ) }
					</h3>
					<span className="tbsh-muted">
												{ sprintf( /* translators: %d: highest daily value */ __( 'Max: %d', 'tbsh-compliance-audit-logger' ), max ) }
					</span>
				</div>
				{ labels.length === 0 ? (
					<EmptyState title={ __( 'No data for this period', 'tbsh-compliance-audit-logger' ) } icon="analytics" />
				) : (
					<>
						{ 0 === max && (
							<p className="tbsh-muted" style={ { margin: '0 0 8px' } }>
								{ __( 'No activity recorded for this series in the selected period.', 'tbsh-compliance-audit-logger' ) }
							</p>
						) }
						<div className="tbsh-bar-chart" role="img" aria-label={ sprintf( /* translators: 1: series name, 2: highest daily value */ __( 'Bar chart of %1$s per day, maximum %2$d', 'tbsh-compliance-audit-logger' ), current.label, max ) }>
							{ labels.map( ( label, idx ) => {
								const v = values[ idx ] || 0;
								const h = max > 0 ? ( v / max ) * 100 : 0;
								return (
									<div
										key={ label }
										className="tbsh-bar-chart-bar"
										title={ `${ label }: ${ v }` }
										style={ { height: `${ v > 0 ? Math.max( h, 2 ) : 0 }%` } }
									/>
								);
							} ) }
						</div>
						<div className="tbsh-bar-chart-labels">
							<span>{ labels[ 0 ] }</span>
							{ labels.length > 2 && <span>{ labels[ mid ] }</span> }
							{ labels.length > 1 && <span>{ labels[ labels.length - 1 ] }</span> }
						</div>
					</>
				) }
			</div>

			<div className="tbsh-card">
				<h3 className="tbsh-section-title">{ __( 'Top compliance categories', 'tbsh-compliance-audit-logger' ) }</h3>
				{ categories.length > 0 ? (
					<div className="tbsh-table-container">
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'Category', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Events', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Share', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ categories.map( ( c, idx ) => {
									const count = Number( c.count ) || 0;
									const share = categoryTotal > 0 ? Math.round( ( count / categoryTotal ) * 100 ) : 0;
									return (
										<tr key={ `${ c.name }-${ idx }` }>
											<td>{ c.name || __( 'Uncategorised', 'tbsh-compliance-audit-logger' ) }</td>
											<td>{ count }</td>
											<td style={ { minWidth: '160px' } }>
												<div className="tbsh-row" style={ { flexWrap: 'nowrap' } }>
													<div className="tbsh-progress" style={ { flex: 1 } }>
														<div className="tbsh-progress-bar" style={ { width: `${ share }%` } } />
													</div>
													<span style={ { minWidth: '40px', textAlign: 'end' } }>{ share }%</span>
												</div>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) : (
					<EmptyState title={ __( 'No categorised events', 'tbsh-compliance-audit-logger' ) } description={ __( 'No events with a compliance category were logged in this period.', 'tbsh-compliance-audit-logger' ) } icon="analytics" />
				) }
			</div>
		</div>
	);
}
