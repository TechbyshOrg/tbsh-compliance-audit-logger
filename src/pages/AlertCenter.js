import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { get, post, can, isAuditor } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';

const CHANNELS = [ 'email', 'slack', 'discord', 'teams', 'telegram', 'webhook' ];
const STATUSES = [ 'queued', 'retry', 'sending', 'sent', 'failed' ];

const channelLabels = () => ( {
	email: __( 'Email', 'tbsh-compliance-audit-logger' ),
	slack: __( 'Slack', 'tbsh-compliance-audit-logger' ),
	discord: __( 'Discord', 'tbsh-compliance-audit-logger' ),
	teams: __( 'Microsoft Teams', 'tbsh-compliance-audit-logger' ),
	telegram: __( 'Telegram', 'tbsh-compliance-audit-logger' ),
	webhook: __( 'Webhook', 'tbsh-compliance-audit-logger' ),
} );

const statusLabels = () => ( {
	queued: __( 'Queued', 'tbsh-compliance-audit-logger' ),
	retry: __( 'Retry scheduled', 'tbsh-compliance-audit-logger' ),
	sending: __( 'Sending', 'tbsh-compliance-audit-logger' ),
	sent: __( 'Sent', 'tbsh-compliance-audit-logger' ),
	failed: __( 'Failed', 'tbsh-compliance-audit-logger' ),
} );

const severityLabels = () => ( {
	info: __( 'Info', 'tbsh-compliance-audit-logger' ),
	notice: __( 'Notice', 'tbsh-compliance-audit-logger' ),
	warning: __( 'Warning', 'tbsh-compliance-audit-logger' ),
	error: __( 'Error', 'tbsh-compliance-audit-logger' ),
	critical: __( 'Critical', 'tbsh-compliance-audit-logger' ),
} );

const alertTypeLabels = () => ( {
	test: __( 'Test', 'tbsh-compliance-audit-logger' ),
	incident: __( 'Incident', 'tbsh-compliance-audit-logger' ),
	nis2_deadline: __( 'NIS2 deadline', 'tbsh-compliance-audit-logger' ),
	vendor_reminder: __( 'Vendor reminder', 'tbsh-compliance-audit-logger' ),
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
		case 'sent':
			return 'success';
		case 'failed':
			return 'danger';
		case 'retry':
			return 'warning';
		default:
			return 'info';
	}
};

const truncate = ( text, max = 60 ) => {
	const value = text || '';
	return value.length > max ? `${ value.slice( 0, max - 1 ) }…` : value;
};

export default function AlertCenter( { navigate } ) {
	const [ rows, setRows ] = useState( [] );
	const [ summary, setSummary ] = useState( null );
	const [ channels, setChannels ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ channel, setChannel ] = useState( '' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ testing, setTesting ] = useState( '' );

	const canManage = can( 'manage_alerts' ) && ! isAuditor();
	const canOpenSettings = can( 'manage_settings' ) && ! isAuditor() && typeof navigate === 'function';
	const cLabels = channelLabels();
	const sLabels = statusLabels();
	const sevLabels = severityLabels();
	const tLabels = alertTypeLabels();

	const load = useCallback( () => {
		setLoading( true );
		setError( null );
		get( '/alerts/log', { page, per_page: 20, status, channel } )
			.then( ( data ) => {
				setRows( ( data && data.items ) || [] );
				setTotalPages( ( data && data.total_pages ) || 1 );
				setSummary( ( data && data.summary ) || null );
				setChannels( ( data && data.channels ) || {} );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load the alert log.', 'tbsh-compliance-audit-logger' ) );
				setRows( [] );
				setLoading( false );
			} );
	}, [ page, status, channel ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const sendTest = ( ch ) => {
		if ( ! canManage ) {
			return;
		}
		setTesting( ch );
		setNotice( { type: '', message: '' } );
		post( '/alerts/test', { channel: ch } )
			.then( ( res ) => {
				setNotice( {
					type: 'success',
					message: ( res && res.message ) || __( 'Test alert delivered.', 'tbsh-compliance-audit-logger' ),
				} );
			} )
			.catch( ( err ) => {
				setNotice( {
					type: 'error',
					message: ( err && err.message ) || __( 'The test alert could not be sent.', 'tbsh-compliance-audit-logger' ),
				} );
			} )
			.finally( () => {
				setTesting( '' );
				load();
			} );
	};

	const configured = CHANNELS.filter( ( ch ) => !! channels[ ch ] );
	const typeLabel = ( type ) => tLabels[ type ] || ( type || '' ).replace( /_/g, ' ' );

	return (
		<div className="tbsh-alert-center">
			<Notice type={ notice.type } message={ notice.message } />

			{ summary && (
				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Delivered', 'tbsh-compliance-audit-logger' ) }
						value={ summary.sent || 0 }
						icon="check"
						desc={ __( 'Last 30 days', 'tbsh-compliance-audit-logger' ) }
						status="success"
					/>
					<MetricCard
						title={ __( 'Failed', 'tbsh-compliance-audit-logger' ) }
						value={ summary.failed || 0 }
						icon="alert"
						desc={ __( 'Last 30 days', 'tbsh-compliance-audit-logger' ) }
						status={ summary.failed > 0 ? 'danger' : '' }
					/>
					<MetricCard
						title={ __( 'Pending', 'tbsh-compliance-audit-logger' ) }
						value={ summary.pending || 0 }
						icon="timeline"
						desc={ __( 'Queued or waiting for a retry (last 30 days)', 'tbsh-compliance-audit-logger' ) }
						status={ summary.pending > 0 ? 'warning' : '' }
					/>
					<MetricCard
						title={ __( 'Active channels', 'tbsh-compliance-audit-logger' ) }
						value={ sprintf(
							/* translators: 1: configured channel count, 2: total channel count */
							__( '%1$d of %2$d', 'tbsh-compliance-audit-logger' ),
							configured.length,
							CHANNELS.length
						) }
						icon="alerts"
						status={ configured.length ? '' : 'warning' }
					/>
				</div>
			) }

			<div className="tbsh-card" style={ { marginBottom: '24px' } }>
				<h3 className="tbsh-section-title">{ __( 'Delivery channels', 'tbsh-compliance-audit-logger' ) }</h3>
				{ loading && ! summary ? (
					<SkeletonLoader rows={ 1 } cols={ 6 } />
				) : (
					<div className="tbsh-stack">
						<div className="tbsh-row">
							{ CHANNELS.map( ( ch ) => (
								<div key={ ch } className="tbsh-row" style={ { marginRight: '16px' } }>
									<strong>{ cLabels[ ch ] }</strong>
									<span className={ `tbsh-badge tbsh-badge-${ channels[ ch ] ? 'success' : 'info' }` }>
										{ channels[ ch ]
											? __( 'Configured', 'tbsh-compliance-audit-logger' )
											: __( 'Not configured', 'tbsh-compliance-audit-logger' ) }
									</span>
									{ canManage && channels[ ch ] && (
										<button
											type="button"
											className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
											disabled={ !! testing }
											onClick={ () => sendTest( ch ) }
										>
											{ testing === ch
												? __( 'Sending…', 'tbsh-compliance-audit-logger' )
												: __( 'Send test', 'tbsh-compliance-audit-logger' ) }
										</button>
									) }
								</div>
							) ) }
						</div>
						{ summary && configured.length === 0 && (
							<div className="tbsh-row-between">
								<span className="tbsh-muted">
									{ __( 'No alert channel is configured yet, so no alerts will be delivered.', 'tbsh-compliance-audit-logger' ) }
								</span>
								{ canOpenSettings && (
									<button
										type="button"
										className="tbsh-btn tbsh-btn-primary tbsh-btn-sm"
										onClick={ () => navigate( 'settings' ) }
									>
										<Icon name="settings" />
										{ __( 'Configure channels in Settings', 'tbsh-compliance-audit-logger' ) }
									</button>
								) }
							</div>
						) }
					</div>
				) }
			</div>

			<div className="tbsh-filters-bar">
				<label className="screen-reader-text" htmlFor="tbsh-alert-status-filter">
					{ __( 'Filter by status', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-alert-status-filter"
					className="tbsh-filter-input"
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All statuses', 'tbsh-compliance-audit-logger' ) }</option>
					{ STATUSES.map( ( s ) => (
						<option key={ s } value={ s }>{ sLabels[ s ] }</option>
					) ) }
				</select>
				<label className="screen-reader-text" htmlFor="tbsh-alert-channel-filter">
					{ __( 'Filter by channel', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-alert-channel-filter"
					className="tbsh-filter-input"
					value={ channel }
					onChange={ ( e ) => {
						setChannel( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All channels', 'tbsh-compliance-audit-logger' ) }</option>
					{ CHANNELS.map( ( ch ) => (
						<option key={ ch } value={ ch }>{ cLabels[ ch ] }</option>
					) ) }
				</select>
				<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load } disabled={ loading }>
					{ __( 'Refresh', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>

			<div className="tbsh-table-container">
				{ loading ? (
					<SkeletonLoader rows={ 5 } cols={ 6 } />
				) : error ? (
					<div style={ { textAlign: 'center', paddingBottom: '24px' } }>
						<EmptyState
							title={ __( 'Could not load the alert log', 'tbsh-compliance-audit-logger' ) }
							description={ error }
							icon="alert"
						/>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
							{ __( 'Try again', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				) : rows.length === 0 ? (
					<EmptyState
						title={ __( 'No alerts recorded', 'tbsh-compliance-audit-logger' ) }
						description={
							status || channel
								? __( 'No alert deliveries match these filters.', 'tbsh-compliance-audit-logger' )
								: __( 'Alert deliveries will appear here once an alert has been queued.', 'tbsh-compliance-audit-logger' )
						}
						icon="alerts"
					/>
				) : (
					<>
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'Created', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Type', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Channel', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Recipient', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Subject', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Attempts', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Response', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Next attempt', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => (
									<tr key={ row.id }>
										<td>{ row.created_at }</td>
										<td>{ typeLabel( row.alert_type ) }</td>
										<td>{ cLabels[ row.channel ] || row.channel }</td>
										<td>{ row.recipient_masked || '—' }</td>
										<td>
											<span className={ `tbsh-badge tbsh-badge-${ severityBadge( row.severity ) }` }>
												{ sevLabels[ row.severity ] || row.severity }
											</span>
										</td>
										<td>{ row.subject }</td>
										<td>
											<span className={ `tbsh-badge tbsh-badge-${ statusBadge( row.status ) }` }>
												{ sLabels[ row.status ] || row.status }
											</span>
										</td>
										<td>{ row.attempts }</td>
										<td title={ row.response_message || '' }>
											{ row.response_message ? truncate( row.response_message ) : '—' }
										</td>
										<td>{ row.next_attempt_at || '—' }</td>
									</tr>
								) ) }
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
				) }
			</div>
		</div>
	);
}
