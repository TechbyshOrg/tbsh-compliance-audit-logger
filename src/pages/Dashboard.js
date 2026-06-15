import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import MetricCard from '../components/MetricCard';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';

export default function Dashboard({ navigate }) {
	const [loading, setLoading] = useState(true);
	const [stats, setStats] = useState(null);
	const [error, setError] = useState(null);

	const fetchStats = () => {
		setLoading(true);
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/dashboard' })
			.then((data) => {
				setStats(data);
				setLoading(false);
			})
			.catch((err) => {
				setError(err.message || __('Failed to load dashboard statistics.', 'tbsh-compliance-audit-logger'));
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchStats();
	}, []);

	if (loading) {
		return <SkeletonLoader rows={4} cols={3} />;
	}

	if (error) {
		return <EmptyState title={__('Error Loading Data', 'tbsh-compliance-audit-logger')} description={error} icon="alert" />;
	}

	// Determine integrity status details.
	const integrity = stats.integrity_status;
	let integrityStatusClass = 'tbsh-integrity-banner-success';
	let integrityLabel = __('Verified', 'tbsh-compliance-audit-logger');
	let integrityDesc = __('Cryptographic integrity verified. The database has not been altered.', 'tbsh-compliance-audit-logger');
	let integrityIcon = 'check';

	if (integrity.status === 'warning') {
		integrityStatusClass = 'tbsh-integrity-banner-warning';
		integrityLabel = __('Warning', 'tbsh-compliance-audit-logger');
		integrityDesc = __('Database integrity issues detected. Some record sequence check failed.', 'tbsh-compliance-audit-logger');
		integrityIcon = 'alert';
	} else if (integrity.status === 'compromised') {
		integrityStatusClass = 'tbsh-integrity-banner-danger';
		integrityLabel = __('Compromised', 'tbsh-compliance-audit-logger');
		integrityDesc = __('CRITICAL: Hashing chain broken! Unapproved database modification detected.', 'tbsh-compliance-audit-logger');
		integrityIcon = 'alert';
	} else if (integrity.status === 'unverified') {
		integrityStatusClass = 'tbsh-integrity-banner-info';
		integrityLabel = __('Unverified', 'tbsh-compliance-audit-logger');
		integrityDesc = __('No database integrity verification has been performed yet.', 'tbsh-compliance-audit-logger');
		integrityIcon = 'info';
	}

	return (
		<div className="tbsh-dashboard-page">
			{/* Integrity Status Bar */}
			<div className={`tbsh-integrity-banner ${integrityStatusClass}`}>
				<div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
					<Icon name={integrityIcon} style={{ fontSize: '20px' }} />
					<div>
						<strong>{integrityLabel}</strong>: {integrityDesc}
					</div>
				</div>
				<button 
					className="tbsh-btn tbsh-btn-secondary" 
					onClick={() => navigate('integrity')}
					style={{ padding: '6px 12px', fontSize: '12px' }}
				>
					{__('Integrity Center', 'tbsh-compliance-audit-logger')}
				</button>
			</div>

			{/* KPI Grid */}
			<div className="tbsh-dashboard-grid">
				<MetricCard 
					title={__('Total Events', 'tbsh-compliance-audit-logger')} 
					value={stats.total_events} 
					icon="logs" 
					desc={__('Logged since installation', 'tbsh-compliance-audit-logger')} 
				/>
				<MetricCard 
					title={__('Events Today', 'tbsh-compliance-audit-logger')} 
					value={stats.events_today} 
					icon="calendar" 
					desc={__('Events captured today', 'tbsh-compliance-audit-logger')} 
				/>
				<MetricCard 
					title={__('Critical Events', 'tbsh-compliance-audit-logger')} 
					value={stats.critical_events} 
					icon="alert" 
					desc={__('Events requiring review', 'tbsh-compliance-audit-logger')}
					status={stats.critical_events > 0 ? 'warning' : ''} 
				/>
				<MetricCard 
					title={__('Failed Logins', 'tbsh-compliance-audit-logger')} 
					value={stats.failed_logins} 
					icon="user" 
					desc={__('Unsuccessful login attempts', 'tbsh-compliance-audit-logger')} 
				/>
			</div>

			{/* Subsections Grid */}
			<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px', margin: '24px 0' }}>
				{/* Most Common Events */}
				<div className="tbsh-card" style={{ padding: '16px' }}>
					<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
						{__('Most Common Actions', 'tbsh-compliance-audit-logger')}
					</h3>
					{stats.most_common_events && stats.most_common_events.length > 0 ? (
						<ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
							{stats.most_common_events.map((evt, idx) => (
								<li 
									key={idx} 
									style={{ 
										display: 'flex', 
										justifyContent: 'space-between', 
										padding: '10px 0', 
										borderBottom: idx === stats.most_common_events.length - 1 ? 'none' : '1px solid var(--tbsh-border-color)' 
									}}
								>
									<span>{evt.name}</span>
									<span className="tbsh-badge tbsh-badge-info">{evt.count}</span>
								</li>
							))}
						</ul>
					) : (
						<EmptyState title={__('No Data Available', 'tbsh-compliance-audit-logger')} description={__('No logged events recorded.', 'tbsh-compliance-audit-logger')} icon="info" />
					)}
				</div>

				{/* Active Actors */}
				<div className="tbsh-card" style={{ padding: '16px' }}>
					<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
						{__('Most Active Users', 'tbsh-compliance-audit-logger')}
					</h3>
					{stats.most_active_users && stats.most_active_users.length > 0 ? (
						<ul style={{ listStyle: 'none', padding: 0, margin: 0 }}>
							{stats.most_active_users.map((usr, idx) => (
								<li 
									key={idx} 
									style={{ 
										display: 'flex', 
										justifyContent: 'space-between', 
										padding: '10px 0', 
										borderBottom: idx === stats.most_active_users.length - 1 ? 'none' : '1px solid var(--tbsh-border-color)' 
									}}
								>
									<span style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
										<Icon name="user" style={{ color: 'var(--tbsh-text-muted)' }} />
										{usr.username}
									</span>
									<span className="tbsh-badge tbsh-badge-info">{usr.count}</span>
								</li>
							))}
						</ul>
					) : (
						<EmptyState title={__('No Data Available', 'tbsh-compliance-audit-logger')} description={__('No active actors logged yet.', 'tbsh-compliance-audit-logger')} icon="info" />
					)}
				</div>
			</div>

			{/* Recent Activity Logs */}
			<div className="tbsh-card" style={{ padding: '20px' }}>
				<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
					<h3 style={{ margin: 0, fontSize: '16px', fontWeight: '600' }}>
						{__('Recent Activity Feed', 'tbsh-compliance-audit-logger')}
					</h3>
					<button className="tbsh-btn tbsh-btn-secondary" onClick={() => navigate('logs')} style={{ padding: '6px 12px', fontSize: '13px' }}>
						{__('View All Logs', 'tbsh-compliance-audit-logger')}
					</button>
				</div>
				{stats.recent_activity && stats.recent_activity.length > 0 ? (
					<div className="tbsh-table-container" style={{ margin: 0 }}>
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{__('Timestamp', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Actor', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Event Title', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Category', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Severity', 'tbsh-compliance-audit-logger')}</th>
								</tr>
							</thead>
							<tbody>
								{stats.recent_activity.map((log) => {
									let badgeClass = 'info';
									if (log.severity === 'warning') badgeClass = 'warning';
									if (log.severity === 'critical' || log.severity === 'error') badgeClass = 'danger';

									return (
										<tr key={log.id}>
											<td style={{ whiteSpace: 'nowrap' }}>{log.created_at}</td>
											<td><strong>{log.username}</strong></td>
											<td>{log.event_title}</td>
											<td>{log.event_category}</td>
											<td>
												<span className={`tbsh-badge tbsh-badge-${badgeClass}`}>{log.severity}</span>
											</td>
										</tr>
									);
								})}
							</tbody>
						</table>
					</div>
				) : (
					<EmptyState title={__('No Events Logged', 'tbsh-compliance-audit-logger')} description={__('Start interacting with your site to populate the logs.', 'tbsh-compliance-audit-logger')} icon="logs" />
				)}
			</div>
		</div>
	);
}
