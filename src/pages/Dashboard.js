import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import MetricCard from '../components/MetricCard';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';
import LogDetailModal from '../components/LogDetailModal';
import { SeverityBadge } from '../components/labels';
import { SiteTime, parseSiteTime } from '../components/time';
import { setPendingState } from '../components/navState';
import { get, can } from '../api';

const WEEK_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Today's date in the site's timezone (Y-m-d), as provided by the server.
 *
 * @return {string} Date.
 */
const siteToday = () => {
	const settings = window.tbshCalApiSettings || {};
	if ( settings.today ) {
		return settings.today;
	}
	const now = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad( now.getDate() ) }`;
};

/**
 * Items that need attention, built from existing read-only endpoints. Failed lookups are skipped.
 *
 * @param {Object} stats Dashboard stats.
 * @return {Promise<Array>} Items { id, tone, text, action, onActionRoute, filters }.
 */
const loadAttention = ( stats ) => {
	const today = siteToday();
	const items = [];
	const integrity = stats.integrity_status || {};

	if ( 'compromised' === integrity.status ) {
		items.push( {
			id: 'chain',
			tone: 'danger',
			text: __( 'Audit log tampering detected: the hash chain is broken.', 'tbsh-compliance-audit-logger' ),
			action: __( 'Investigate', 'tbsh-compliance-audit-logger' ),
			route: 'integrity',
		} );
	} else if ( 'warning' === integrity.status ) {
		items.push( {
			id: 'chain',
			tone: 'warning',
			text: __( 'The last integrity check found issues in the audit log.', 'tbsh-compliance-audit-logger' ),
			action: __( 'Review', 'tbsh-compliance-audit-logger' ),
			route: 'integrity',
		} );
	} else {
		const verified = parseSiteTime( integrity.verification_date );
		if ( ! verified || Date.now() - verified.getTime() > WEEK_MS ) {
			items.push( {
				id: 'chain',
				tone: 'info',
				text: verified
					? __( 'The audit log has not been verified in the last 7 days.', 'tbsh-compliance-audit-logger' )
					: __( 'The audit log has never been verified.', 'tbsh-compliance-audit-logger' ),
				action: __( 'Verify now', 'tbsh-compliance-audit-logger' ),
				route: 'integrity',
			} );
		}
	}

	const lookups = [
		get( '/logs', { severity: 'critical', date_start: today, date_end: today, per_page: 1 } )
			.then( ( data ) => {
				const total = ( data && data.total ) || 0;
				if ( total > 0 ) {
					items.push( {
						id: 'critical',
						tone: 'danger',
						text: sprintf(
							/* translators: %s: number of events */
							_n( '%s critical event today', '%s critical events today', total, 'tbsh-compliance-audit-logger' ),
							total.toLocaleString()
						),
						action: __( 'Review', 'tbsh-compliance-audit-logger' ),
						route: 'logs',
						filters: { severity: 'critical', dateStart: today, dateEnd: today },
					} );
				}
			} )
			.catch( () => {} ),
		get( '/file-integrity' )
			.then( ( data ) => {
				const result = data && data.result;
				if ( result && 'fail' === result.status ) {
					const count = ( result.failing || [] ).length || 1;
					items.push( {
						id: 'files',
						tone: 'danger',
						text: sprintf(
							/* translators: %s: number of plugins, themes or folders */
							_n( 'Unexpected file changes in %s component', 'Unexpected file changes in %s components', count, 'tbsh-compliance-audit-logger' ),
							count.toLocaleString()
						),
						action: __( 'Review files', 'tbsh-compliance-audit-logger' ),
						route: 'integrity',
					} );
				}
			} )
			.catch( () => {} ),
	];

	if ( can( 'view_incidents' ) ) {
		lookups.push(
			get( '/incidents', { status: 'active', per_page: 1 } )
				.then( ( data ) => {
					const total = ( data && data.total ) || 0;
					if ( total > 0 ) {
						items.push( {
							id: 'incidents',
							tone: 'warning',
							text: sprintf(
								/* translators: %s: number of incidents */
								_n( '%s open incident', '%s open incidents', total, 'tbsh-compliance-audit-logger' ),
								total.toLocaleString()
							),
							action: __( 'Open incidents', 'tbsh-compliance-audit-logger' ),
							route: 'incidents',
						} );
					}
				} )
				.catch( () => {} )
		);
	}

	const order = { danger: 0, warning: 1, info: 2 };
	return Promise.all( lookups ).then( () => items.sort( ( a, b ) => order[ a.tone ] - order[ b.tone ] ) );
};

export default function Dashboard( { navigate } ) {
	const [ loading, setLoading ] = useState( true );
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ attention, setAttention ] = useState( null );
	const [ openLogId, setOpenLogId ] = useState( 0 );

	useEffect( () => {
		let alive = true;
		get( '/dashboard' )
			.then( ( data ) => {
				if ( ! alive ) {
					return;
				}
				setStats( data );
				loadAttention( data ).then( ( items ) => alive && setAttention( items ) );
			} )
			.catch( ( err ) => alive && setError( err.message || __( 'Failed to load dashboard statistics.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => alive && setLoading( false ) );
		return () => {
			alive = false;
		};
	}, [] );

	const openLogs = ( filters = {} ) => {
		setPendingState( 'logs', filters );
		navigate( 'logs' );
	};

	if ( loading ) {
		return <SkeletonLoader rows={ 4 } cols={ 3 } />;
	}

	if ( error ) {
		return <EmptyState title={ __( 'Could not load the dashboard', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />;
	}

	const today = siteToday();
	const number = ( value ) => Number( value || 0 ).toLocaleString();
	const integrity = stats.integrity_status || {};

	const renderAttention = () => {
		if ( null === attention ) {
			return (
				<div className="tbsh-card tbsh-attention" aria-busy="true">
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'Checking what needs attention…', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
			);
		}
		if ( ! attention.length ) {
			return (
				<div className="tbsh-card tbsh-attention tbsh-attention-clear" role="status">
					<div className="tbsh-row">
						<Icon name="check" style={ { color: 'var(--tbsh-success)', fontSize: '20px' } } />
						<strong>{ __( 'All clear', 'tbsh-compliance-audit-logger' ) }</strong>
						<span className="tbsh-muted">
							{ __( 'No critical events today, no open incidents, and the audit log and files are verified.', 'tbsh-compliance-audit-logger' ) }
						</span>
					</div>
				</div>
			);
		}
		return (
			<section className="tbsh-card tbsh-attention" aria-labelledby="tbsh-attention-title">
				<h3 id="tbsh-attention-title" className="tbsh-section-title">
					{ __( 'Needs attention', 'tbsh-compliance-audit-logger' ) }
				</h3>
				<ul className="tbsh-list-plain">
					{ attention.map( ( item ) => (
						<li key={ item.id } className="tbsh-attention-item">
							<span className="tbsh-row">
								<span className={ `tbsh-badge tbsh-badge-${ item.tone }` }>
									{ 'info' === item.tone ? __( 'To do', 'tbsh-compliance-audit-logger' ) : __( 'Action needed', 'tbsh-compliance-audit-logger' ) }
								</span>
								<span>{ item.text }</span>
							</span>
							<button
								type="button"
								className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
								onClick={ () => ( item.filters ? openLogs( item.filters ) : navigate( item.route ) ) }
							>
								{ item.action }
							</button>
						</li>
					) ) }
				</ul>
			</section>
		);
	};

	return (
		<div className="tbsh-dashboard-page">
			{ renderAttention() }

			<div className="tbsh-dashboard-grid">
				<MetricCard
					title={ __( 'Events today', 'tbsh-compliance-audit-logger' ) }
					value={ number( stats.events_today ) }
					icon="calendar"
					desc={ __( 'Recorded since midnight (site time)', 'tbsh-compliance-audit-logger' ) }
					cta={ __( 'View', 'tbsh-compliance-audit-logger' ) }
					onClick={ () => openLogs( { dateStart: today, dateEnd: today } ) }
				/>
				<MetricCard
					title={ __( 'Errors and critical', 'tbsh-compliance-audit-logger' ) }
					value={ number( stats.critical_events ) }
					icon="alert"
					desc={ __( 'All time · opens critical events', 'tbsh-compliance-audit-logger' ) }
					status={ stats.critical_events > 0 ? 'warning' : '' }
					cta={ __( 'Review', 'tbsh-compliance-audit-logger' ) }
					onClick={ () => openLogs( { severity: 'critical' } ) }
				/>
				<MetricCard
					title={ __( 'Failed sign-ins', 'tbsh-compliance-audit-logger' ) }
					value={ number( stats.failed_logins ) }
					icon="user"
					desc={ __( 'All time', 'tbsh-compliance-audit-logger' ) }
					cta={ __( 'View', 'tbsh-compliance-audit-logger' ) }
					onClick={ () => openLogs( { eventType: 'failed_login' } ) }
				/>
				<MetricCard
					title={ __( 'Total events', 'tbsh-compliance-audit-logger' ) }
					value={ number( stats.total_events ) }
					icon="logs"
					desc={
						integrity.status === 'verified'
							? __( 'Hash chain verified', 'tbsh-compliance-audit-logger' )
							: __( 'Logged since installation', 'tbsh-compliance-audit-logger' )
					}
					cta={ __( 'View all', 'tbsh-compliance-audit-logger' ) }
					onClick={ () => openLogs() }
				/>
			</div>

			<div className="tbsh-grid-2">
				<section className="tbsh-card" style={ { padding: '16px' } } aria-labelledby="tbsh-common-title">
					<h3 id="tbsh-common-title" className="tbsh-section-title">
						{ __( 'Most common actions', 'tbsh-compliance-audit-logger' ) }
					</h3>
					{ stats.most_common_events && stats.most_common_events.length > 0 ? (
						<ul className="tbsh-list-plain">
							{ stats.most_common_events.map( ( evt ) => (
								<li key={ evt.name }>
									<button
										type="button"
										className="tbsh-list-row"
										onClick={ () => openLogs( { search: evt.name } ) }
										aria-label={ sprintf(
											/* translators: 1: event title, 2: number of events */
											__( '%1$s: %2$s events. Show them in the activity log.', 'tbsh-compliance-audit-logger' ),
											evt.name,
											number( evt.count )
										) }
									>
										<span className="tbsh-list-row-label">{ evt.name }</span>
										<span className="tbsh-badge tbsh-badge-info">{ number( evt.count ) }</span>
									</button>
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState title={ __( 'No activity yet', 'tbsh-compliance-audit-logger' ) } description={ __( 'The most frequent actions appear here once events are recorded.', 'tbsh-compliance-audit-logger' ) } icon="info" />
					) }
				</section>

				<section className="tbsh-card" style={ { padding: '16px' } } aria-labelledby="tbsh-users-title">
					<h3 id="tbsh-users-title" className="tbsh-section-title">
						{ __( 'Most active users', 'tbsh-compliance-audit-logger' ) }
					</h3>
					{ stats.most_active_users && stats.most_active_users.length > 0 ? (
						<ul className="tbsh-list-plain">
							{ stats.most_active_users.map( ( usr ) => (
								<li key={ `${ usr.user_id }-${ usr.username }` }>
									<button
										type="button"
										className="tbsh-list-row"
										onClick={ () => ( Number( usr.user_id ) > 0 ? openLogs( { userId: Number( usr.user_id ), userLabel: usr.username } ) : openLogs( { search: usr.username } ) ) }
										aria-label={ sprintf(
											/* translators: 1: username, 2: number of events */
											__( '%1$s: %2$s events. Show them in the activity log.', 'tbsh-compliance-audit-logger' ),
											usr.username,
											number( usr.count )
										) }
									>
										<span className="tbsh-list-row-label tbsh-row">
											<Icon name="user" style={ { color: 'var(--tbsh-text-muted)' } } />
											{ usr.username }
										</span>
										<span className="tbsh-badge tbsh-badge-info">{ number( usr.count ) }</span>
									</button>
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState title={ __( 'No user activity yet', 'tbsh-compliance-audit-logger' ) } description={ __( 'Users appear here once they sign in or make changes.', 'tbsh-compliance-audit-logger' ) } icon="user" />
					) }
				</section>
			</div>

			<section className="tbsh-card" style={ { padding: '20px' } } aria-labelledby="tbsh-recent-title">
				<div className="tbsh-row-between" style={ { marginBottom: '16px' } }>
					<h3 id="tbsh-recent-title" className="tbsh-section-title" style={ { margin: 0 } }>
						{ __( 'Recent activity', 'tbsh-compliance-audit-logger' ) }
					</h3>
					<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => openLogs() }>
						{ __( 'View all activity', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
				{ stats.recent_activity && stats.recent_activity.length > 0 ? (
					<div className="tbsh-table-container" style={ { margin: 0 } } role="region" aria-labelledby="tbsh-recent-title" tabIndex="0">
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'Time', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'User', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Event', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Category', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ stats.recent_activity.map( ( log ) => (
									<tr key={ log.id }>
										<td>
											<SiteTime value={ log.created_at } />
										</td>
										<td>
											<strong>{ log.username }</strong>
										</td>
										<td>
											<button
												type="button"
												className="tbsh-link-button"
												onClick={ () => setOpenLogId( Number( log.id ) ) }
												aria-label={ sprintf(
													/* translators: %s: event title */
													__( 'Details: %s', 'tbsh-compliance-audit-logger' ),
													log.event_title
												) }
											>
												{ log.event_title }
											</button>
										</td>
										<td>{ log.event_category }</td>
										<td>
											<SeverityBadge severity={ log.severity } />
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : (
					<EmptyState
						title={ __( 'No events recorded yet', 'tbsh-compliance-audit-logger' ) }
						description={ __( 'Events appear here as people sign in, edit content and change settings.', 'tbsh-compliance-audit-logger' ) }
						icon="logs"
						actionLabel={ can( 'manage_settings' ) ? __( 'Choose what is logged', 'tbsh-compliance-audit-logger' ) : '' }
						onAction={ () => navigate( 'settings' ) }
					/>
				) }
			</section>

			<LogDetailModal logId={ openLogId } onClose={ () => setOpenLogId( 0 ) } />
		</div>
	);
}
