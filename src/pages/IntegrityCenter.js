import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';

export default function IntegrityCenter() {
	const [integrityData, setIntegrityData] = useState(null);
	const [loading, setLoading] = useState(true);
	const [scanLoading, setScanLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });

	const fetchIntegrity = () => {
		setLoading(true);
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/integrity' })
			.then((data) => {
				setIntegrityData(data);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchIntegrity();
	}, []);

	const runScan = () => {
		setScanLoading(true);
		setNotice({ type: '', message: '' });

		apiFetch({ 
			path: '/tbsh-compliance-audit-logger/v1/integrity',
			method: 'POST'
		})
		.then((res) => {
			setNotice({ 
				type: res.status === 'verified' ? 'success' : 'error', 
				message: res.status === 'verified' 
					? __('Audit trail integrity check passed! All records verified.', 'tbsh-compliance-audit-logger')
					: sprintf(__('Audit trail compromised! Found %d anomalies.', 'tbsh-compliance-audit-logger'), res.issues_found)
			});
			fetchIntegrity();
			setScanLoading(false);
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to run integrity scan.', 'tbsh-compliance-audit-logger') });
			setScanLoading(false);
		});
	};

	if (loading) {
		return <SkeletonLoader rows={5} cols={4} />;
	}

	const latest = integrityData.latest;
	const history = integrityData.history;

	// Visual indicators mapping.
	let statusClass = 'tbsh-integrity-banner-success';
	let statusLabel = __('Verified', 'tbsh-compliance-audit-logger');
	let statusDesc = __('The cryptographic chain matches the logs. No tampering detected.', 'tbsh-compliance-audit-logger');
	let statusIcon = 'check';

	if (latest.status === 'warning') {
		statusClass = 'tbsh-integrity-banner-warning';
		statusLabel = __('Warning', 'tbsh-compliance-audit-logger');
		statusDesc = sprintf(__('Some sequence checks failed (%d issues detected).', 'tbsh-compliance-audit-logger'), latest.issues_found);
		statusIcon = 'alert';
	} else if (latest.status === 'compromised') {
		statusClass = 'tbsh-integrity-banner-danger';
		statusLabel = __('Compromised', 'tbsh-compliance-audit-logger');
		statusDesc = sprintf(__('CRITICAL: Audit trail validation failed! Found %d altered records.', 'tbsh-compliance-audit-logger'), latest.issues_found);
		statusIcon = 'alert';
	} else if (latest.status === 'unverified') {
		statusClass = 'tbsh-integrity-banner-info';
		statusLabel = __('Unverified', 'tbsh-compliance-audit-logger');
		statusDesc = __('Database integrity has not been scanned.', 'tbsh-compliance-audit-logger');
		statusIcon = 'info';
	}

	return (
		<div className="tbsh-integrity-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

			{/* Top Actions card */}
			<div className="tbsh-card" style={{ marginBottom: '24px' }}>
				<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
					<div>
						<h3 style={{ margin: '0 0 6px 0', fontSize: '18px', fontWeight: '600' }}>
							{__('Cryptographic Integrity Verification', 'tbsh-compliance-audit-logger')}
						</h3>
						<p style={{ margin: 0, color: 'var(--tbsh-text-secondary)', fontSize: '14px' }}>
							{__('Verify log authenticity using blockchain-style SHA-256 hash chains.', 'tbsh-compliance-audit-logger')}
						</p>
					</div>
					<button 
						className="tbsh-btn tbsh-btn-primary" 
						disabled={scanLoading}
						onClick={runScan}
					>
						<Icon name="shield" />
						{scanLoading ? __('Running Verification Scan...', 'tbsh-compliance-audit-logger') : __('Run Integrity Verification', 'tbsh-compliance-audit-logger')}
					</button>
				</div>
			</div>

			{/* Status Banner */}
			<div className={`tbsh-integrity-banner ${statusClass}`}>
				<div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
					<Icon name={statusIcon} style={{ fontSize: '24px' }} />
					<div>
						<h4 style={{ margin: '0 0 4px 0', fontSize: '16px', fontWeight: '700' }}>{statusLabel}</h4>
						<p style={{ margin: 0, fontSize: '14px' }}>{statusDesc}</p>
					</div>
				</div>
				{latest.verification_date && (
					<span style={{ fontSize: '12px', opacity: 0.8 }}>
						{sprintf(__('Checked: %s', 'tbsh-compliance-audit-logger'), latest.verification_date)}
					</span>
				)}
			</div>

			{/* Broken Logs Alerts list */}
			{latest.status === 'compromised' && latest.details && latest.details.broken_logs && latest.details.broken_logs.length > 0 && (
				<div className="tbsh-card" style={{ borderColor: 'var(--tbsh-danger)', backgroundColor: 'var(--tbsh-bg-secondary)', marginBottom: '24px' }}>
					<h4 style={{ color: 'var(--tbsh-danger-text)', margin: '0 0 12px 0', fontSize: '15px', display: 'flex', alignItems: 'center', gap: '8px' }}>
						<Icon name="alert" />
						{__('Tampered / Corrupted Audit Trails Details', 'tbsh-compliance-audit-logger')}
					</h4>
					<div style={{ maxHeight: '250px', overflowY: 'auto' }}>
						<table className="tbsh-table" style={{ fontSize: '13px' }}>
							<thead>
								<tr>
									<th>{__('Log ID', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Title', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Discrepancy Details', 'tbsh-compliance-audit-logger')}</th>
								</tr>
							</thead>
							<tbody>
								{latest.details.broken_logs.map((item, idx) => (
									<tr key={idx}>
										<td><code>#{item.id}</code></td>
										<td>{item.title}</td>
										<td style={{ color: 'var(--tbsh-danger-text)' }}>{item.reason}</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				</div>
			)}

			{/* Scan History */}
			<div className="tbsh-card" style={{ padding: '20px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Verification Scans Logs History', 'tbsh-compliance-audit-logger')}
				</h3>
				{history && history.length > 0 ? (
					<div className="tbsh-table-container" style={{ margin: 0 }}>
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{__('Scan Date', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Scan Status', 'tbsh-compliance-audit-logger')}</th>
									<th>{__('Issues Discovered', 'tbsh-compliance-audit-logger')}</th>
								</tr>
							</thead>
							<tbody>
								{history.map((h) => (
									<tr key={h.id}>
										<td>{h.verification_date}</td>
										<td>
											<span className={`tbsh-badge tbsh-badge-${h.status === 'verified' ? 'success' : 'danger'}`}>
												{h.status}
											</span>
										</td>
										<td>
											<strong style={{ color: h.issues_found > 0 ? 'var(--tbsh-danger-text)' : 'inherit' }}>
												{h.issues_found}
											</strong>
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				) : (
					<EmptyState title={__('No Scan History Available', 'tbsh-compliance-audit-logger')} description={__('Trigger your first verification check using the button above.', 'tbsh-compliance-audit-logger')} icon="info" />
				)}
			</div>
		</div>
	);
}
