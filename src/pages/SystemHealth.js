import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';

export default function SystemHealth() {
	const [health, setHealth] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [checksumLoading, setChecksumLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });

	const caps = ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};

	const fetchHealth = () => {
		setLoading(true);
		setError(null);
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/health' })
			.then((res) => {
				setHealth(res);
				setLoading(false);
			})
			.catch((err) => {
				setError(err.message || __('Failed to load system health.', 'tbsh-compliance-audit-logger'));
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchHealth();
	}, []);

	const runChecksum = () => {
		setChecksumLoading(true);
		setNotice({ type: '', message: '' });
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/checksums', method: 'POST' })
			.then((res) => {
				setNotice({
					type: res.status === 'ok' ? 'success' : 'error',
					message: res.message,
				});
				fetchHealth();
				setChecksumLoading(false);
			})
			.catch((err) => {
				setNotice({ type: 'error', message: err.message || __('Checksum scan failed.', 'tbsh-compliance-audit-logger') });
				setChecksumLoading(false);
			});
	};

	if (loading) {
		return <SkeletonLoader rows={4} cols={4} />;
	}

	if (error || !health) {
		return <EmptyState title={__('Error Loading Data', 'tbsh-compliance-audit-logger')} description={error || __('No health data available.', 'tbsh-compliance-audit-logger')} icon="alert" />;
	}

	const checksum = health.core_checksum || {};

	return (
		<div className="tbsh-health-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

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
						<span>{__('Integrity Status', 'tbsh-compliance-audit-logger')}</span>
						<Icon name="integrity" />
					</div>
					<div className="tbsh-card-value" style={{ fontSize: '20px', textTransform: 'capitalize' }}>{health.integrity_status || '—'}</div>
					<div className="tbsh-card-desc">
						{health.last_verification
							? sprintf(__('Last check: %s', 'tbsh-compliance-audit-logger'), health.last_verification)
							: __('No verification run yet', 'tbsh-compliance-audit-logger')}
					</div>
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

			<div className="tbsh-card" style={{ padding: '20px', marginBottom: '24px' }}>
				<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '12px' }}>
					<h3 style={{ margin: 0, fontSize: '16px', fontWeight: '600' }}>
						{__('Core File Checksums & Cron', 'tbsh-compliance-audit-logger')}
					</h3>
					{caps.verify_integrity && (
						<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={runChecksum} disabled={checksumLoading}>
							{checksumLoading ? __('Scanning…', 'tbsh-compliance-audit-logger') : __('Run Core Checksum Scan', 'tbsh-compliance-audit-logger')}
						</button>
					)}
				</div>
				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: '20px' }}>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Core checksum status', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)', textTransform: 'capitalize' }}>{checksum.status || 'unverified'}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Last checksum run', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{checksum.date || '—'}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Next scheduled cron', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.cron_next_run || __('Not scheduled', 'tbsh-compliance-audit-logger')}</strong>
					</div>
					<div style={{ padding: '12px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<span style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', display: 'block' }}>{__('Evidence snapshots', 'tbsh-compliance-audit-logger')}</span>
						<strong style={{ fontSize: '16px', color: 'var(--tbsh-text-primary)' }}>{health.evidence_count}</strong>
					</div>
				</div>
			</div>

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
							{Object.keys(health.table_health || {}).map((key) => {
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
