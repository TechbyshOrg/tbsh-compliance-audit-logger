import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';

export default function SystemHealth() {
	const [health, setHealth] = useState(null);
	const [loading, setLoading] = useState(true);

	useEffect(() => {
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/health' })
			.then((res) => {
				setHealth(res);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	}, []);

	if (loading) {
		return <SkeletonLoader rows={4} cols={4} />;
	}

	return (
		<div className="tbsh-health-page">
			{/* Health Overview Row */}
			<div className="tbsh-dashboard-grid" style={{ marginBottom: '24px' }}>
				<div className="tbsh-card">
					<div className="tbsh-card-header">
						<span>{__('Custom Database Footprint', 'tbsh-compliance-audit-logger')}</span>
						<Icon name="database" />
					</div>
					<div className="tbsh-card-value">{health.db_size}</div>
					<div className="tbsh-card-desc">{__('Aggregated custom tables size', 'tbsh-compliance-audit-logger')}</div>
				</div>

				<div className="tbsh-card">
					<div className="tbsh-card-header">
						<span>{__('Total Audit Logs Count', 'tbsh-compliance-audit-logger')}</span>
						<Icon name="logs" />
					</div>
					<div className="tbsh-card-value">{health.log_count}</div>
					<div className="tbsh-card-desc">{__('Stored in tbsh_cal_logs table', 'tbsh-compliance-audit-logger')}</div>
				</div>

				<div className="tbsh-card">
					<div className="tbsh-card-header">
						<span>{__('Evidence Snapshots Count', 'tbsh-compliance-audit-logger')}</span>
						<Icon name="evidence" />
					</div>
					<div className="tbsh-card-value">{health.evidence_count}</div>
					<div className="tbsh-card-desc">{__('Stored in tbsh_cal_evidence table', 'tbsh-compliance-audit-logger')}</div>
				</div>

				<div className="tbsh-card">
					<div className="tbsh-card-header">
						<span>{__('Errors / Critical Events', 'tbsh-compliance-audit-logger')}</span>
						<Icon name="alert" />
					</div>
					<div className="tbsh-card-value" style={{ color: health.recent_errors > 0 ? 'var(--tbsh-danger)' : 'inherit' }}>
						{health.recent_errors}
					</div>
					<div className="tbsh-card-desc">{__('Alarms captured in database', 'tbsh-compliance-audit-logger')}</div>
				</div>
			</div>

			{/* Environment Settings Card */}
			<div className="tbsh-card" style={{ padding: '20px', marginBottom: '24px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Operational Server Environment', 'tbsh-compliance-audit-logger')}
				</h3>
				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '20px' }}>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('PHP Engine Version', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.environment.php_version}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('WordPress Core Version', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.environment.wp_version}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Database Engine Version', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.environment.db_version}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Multisite Network Enabled', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.environment.multisite}</strong>
					</div>
				</div>
			</div>

			{/* Database Table Health */}
			<div className="tbsh-card" style={{ padding: '20px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Custom Tables Integrity & Health Status', 'tbsh-compliance-audit-logger')}
				</h3>
				<div className="tbsh-table-container" style={{ margin: 0 }}>
					<table className="tbsh-table">
						<thead>
							<tr>
								<th>{__('Table Name', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Engine', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Collation', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Total Rows', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Total Size', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Table Status', 'tbsh-compliance-audit-logger')}</th>
							</tr>
						</thead>
						<tbody>
							{Object.keys(health.table_health).map((key) => {
								const tbl = health.table_health[key];
								return (
									<tr key={key}>
										<td><code>{key}</code></td>
										<td>{tbl.engine}</td>
										<td>{tbl.collation}</td>
										<td>{tbl.rows}</td>
										<td>{tbl.size}</td>
										<td>
											<span className={`tbsh-badge tbsh-badge-${tbl.status === 'Healthy' ? 'success' : 'danger'}`}>
												{tbl.status}
											</span>
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				</div>
			</div>
		</div>
	);
}
