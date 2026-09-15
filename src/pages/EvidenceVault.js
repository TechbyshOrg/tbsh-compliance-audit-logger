import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import Icon from '../components/Icon';

export default function EvidenceVault() {
	const [snapshots, setSnapshots] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [selectedSnapshot, setSelectedSnapshot] = useState(null);
	const [selectedDetails, setSelectedDetails] = useState(null);
	const [detailsLoading, setDetailsLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });
	const [btnLoading, setBtnLoading] = useState(false);
	const [page, setPage] = useState(1);
	const [totalPages, setTotalPages] = useState(1);

	const caps = ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};
	const canCapture = !!caps.verify_integrity;

	const fetchSnapshots = () => {
		setLoading(true);
		setError(null);
		apiFetch({ path: `/tbsh-compliance-audit-logger/v1/evidence?page=${page}&per_page=20` })
			.then((data) => {
				const items = Array.isArray(data) ? data : ( data.items || [] );
				setSnapshots(items);
				setTotalPages(data.total_pages || 1);
				setLoading(false);
			})
			.catch((err) => {
				setError(err.message || __('Failed to load evidence snapshots.', 'tbsh-compliance-audit-logger'));
				setSnapshots([]);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchSnapshots();
	}, [page]); // eslint-disable-line react-hooks/exhaustive-deps

	const triggerSnapshot = () => {
		if ( ! canCapture ) return;
		setBtnLoading(true);
		setNotice({ type: '', message: '' });

		apiFetch({ 
			path: '/tbsh-compliance-audit-logger/v1/evidence',
			method: 'POST',
			data: { title: __('Manual Compliance Vault Entry', 'tbsh-compliance-audit-logger') }
		})
		.then((res) => {
			setNotice({ type: 'success', message: res.message });
			fetchSnapshots();
			setBtnLoading(false);
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to capture snapshot.', 'tbsh-compliance-audit-logger') });
			setBtnLoading(false);
		});
	};

	const inspectSnapshot = (id) => {
		setSelectedSnapshot(id);
		setDetailsLoading(true);
		setSelectedDetails(null);

		apiFetch({ path: `/tbsh-compliance-audit-logger/v1/evidence/${id}` })
			.then((data) => {
				setSelectedDetails(data);
				setDetailsLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setDetailsLoading(false);
			});
	};

	// Client-side export helper
	const exportSnapshot = (format) => {
		if ( ! selectedDetails ) return;

		const data = selectedDetails.snapshot_data;
		if ( 'json' === format ) {
			const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
			const url  = URL.createObjectURL(blob);
			const a    = document.createElement('a');
			a.href     = url;
			a.download = `evidence_snapshot_${selectedDetails.evidence_uuid}.json`;
			a.click();
			URL.revokeObjectURL(url);
		} else if ( 'csv' === format ) {
			// Create a summary CSV of plugins
			let csvContent = "Plugin Name,Version,Active Status,File Path\n";
			data.plugins.forEach(p => {
				csvContent += `"${p.name}","${p.version}","${p.is_active ? 'Active' : 'Inactive'}","${p.file}"\n`;
			});

			const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
			const url  = URL.createObjectURL(blob);
			const a    = document.createElement('a');
			a.href     = url;
			a.download = `evidence_plugins_${selectedDetails.evidence_uuid}.csv`;
			a.click();
			URL.revokeObjectURL(url);
		}
	};

	return (
		<div className="tbsh-evidence-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
				<h3 style={{ margin: 0, fontSize: '18px', fontWeight: '600' }}>
					{__('Evidence Snapshots', 'tbsh-compliance-audit-logger')}
				</h3>
				{canCapture && (
					<button 
						type="button"
						className="tbsh-btn tbsh-btn-primary" 
						disabled={btnLoading}
						onClick={triggerSnapshot}
					>
						<Icon name="shield" />
						{btnLoading ? __('Capturing...', 'tbsh-compliance-audit-logger') : __('Record State Snapshot', 'tbsh-compliance-audit-logger')}
					</button>
				)}
			</div>

			<div className="tbsh-table-container">
				{loading ? (
					<SkeletonLoader rows={5} cols={4} />
				) : error ? (
					<EmptyState title={__('Error Loading Data', 'tbsh-compliance-audit-logger')} description={error} icon="alert" />
				) : snapshots.length > 0 ? (
					<>
					<table className="tbsh-table">
						<thead>
							<tr>
								<th>{__('Snapshot ID', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Title', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Trigger Type', 'tbsh-compliance-audit-logger')}</th>
								<th>{__('Date Logged', 'tbsh-compliance-audit-logger')}</th>
								<th style={{ textAlign: 'right' }}>{__('Actions', 'tbsh-compliance-audit-logger')}</th>
							</tr>
						</thead>
						<tbody>
							{snapshots.map((snap) => (
								<tr key={snap.id}>
									<td><code>{snap.evidence_uuid}</code></td>
									<td><strong>{snap.evidence_title}</strong></td>
									<td>
										<span className={`tbsh-badge tbsh-badge-${snap.evidence_type === 'manual' ? 'warning' : 'info'}`}>
											{snap.evidence_type}
										</span>
									</td>
									<td>{snap.created_at}</td>
									<td style={{ textAlign: 'right' }}>
										<button 
											type="button"
											className="tbsh-btn tbsh-btn-secondary"
											onClick={() => inspectSnapshot(snap.id)}
											style={{ padding: '4px 8px', fontSize: '12px' }}
										>
											{__('Inspect', 'tbsh-compliance-audit-logger')}
										</button>
									</td>
								</tr>
							))}
						</tbody>
					</table>
					<div className="tbsh-pagination" style={{ marginTop: '16px' }}>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={page === 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
							{__('Previous', 'tbsh-compliance-audit-logger')}
						</button>
						<span style={{ fontSize: '13px', padding: '0 12px' }}>{page} / {totalPages}</span>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
							{__('Next', 'tbsh-compliance-audit-logger')}
						</button>
					</div>
					</>
				) : (
					<EmptyState title={__('Evidence Vault is Empty', 'tbsh-compliance-audit-logger')} description={__('No state snapshots have been saved yet.', 'tbsh-compliance-audit-logger')} icon="evidence" />
				)}
			</div>

			{/* Detail Inspector Modal */}
			<Modal 
				title={__('Evidence Snapshot Details', 'tbsh-compliance-audit-logger')}
				isOpen={!!selectedSnapshot}
				onClose={() => setSelectedSnapshot(null)}
			>
				{detailsLoading ? (
					<SkeletonLoader rows={6} cols={3} />
				) : selectedDetails ? (
					<div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
						<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
							<div>
								<strong>{__('Snapshot Name:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.evidence_title}
							</div>
							<div style={{ display: 'flex', gap: '8px' }}>
								<button className="tbsh-btn tbsh-btn-secondary" onClick={() => exportSnapshot('json')} style={{ padding: '6px 10px', fontSize: '12px' }}>
									<Icon name="download" />
									JSON
								</button>
								<button className="tbsh-btn tbsh-btn-secondary" onClick={() => exportSnapshot('csv')} style={{ padding: '6px 10px', fontSize: '12px' }}>
									<Icon name="download" />
									CSV (Plugins)
								</button>
							</div>
						</div>

						<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', padding: '12px', backgroundColor: 'var(--tbsh-bg-secondary)', borderRadius: '6px' }}>
							<div><strong>{__('WordPress Version:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.wp_version}</div>
							<div><strong>{__('PHP Version:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.php_version}</div>
							<div><strong>{__('Multisite Network:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.site_config.is_multisite ? __('Yes', 'tbsh-compliance-audit-logger') : __('No', 'tbsh-compliance-audit-logger')}</div>
							<div><strong>{__('DB Tables Checked:', 'tbsh-compliance-audit-logger')}</strong> {Object.keys(selectedDetails.snapshot_data.database.tables).length}</div>
						</div>

						{/* Plugins Snapshot Tabulation */}
						<div>
							<h5 style={{ margin: '0 0 8px 0', fontSize: '14px' }}>
								{__('Active Plugins Catalog', 'tbsh-compliance-audit-logger')} ({selectedDetails.snapshot_data.plugins.length})
							</h5>
							<div style={{ maxHeight: '200px', overflowY: 'auto', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
								<table className="tbsh-table" style={{ fontSize: '12px' }}>
									<thead>
										<tr>
											<th>{__('Plugin Name', 'tbsh-compliance-audit-logger')}</th>
											<th>{__('Version', 'tbsh-compliance-audit-logger')}</th>
											<th>{__('Status', 'tbsh-compliance-audit-logger')}</th>
										</tr>
									</thead>
									<tbody>
										{selectedDetails.snapshot_data.plugins.map((plug, idx) => (
											<tr key={idx}>
												<td>{plug.name}</td>
												<td><code>{plug.version}</code></td>
												<td>
													<span className={`tbsh-badge tbsh-badge-${plug.is_active ? 'success' : 'info'}`}>
														{plug.is_active ? 'Active' : 'Inactive'}
													</span>
												</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>
						</div>

						{/* Security Settings Snapshot */}
						<div>
							<h5 style={{ margin: '0 0 8px 0', fontSize: '14px' }}>
								{__('Security Config Snapshot', 'tbsh-compliance-audit-logger')}
							</h5>
							<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', fontSize: '13px' }}>
								<div>
									<span>{__('WP Debug Active:', 'tbsh-compliance-audit-logger')} </span>
									<strong>{selectedDetails.snapshot_data.security.wp_debug ? 'TRUE' : 'FALSE'}</strong>
								</div>
								<div>
									<span>{__('Forced SSL Login:', 'tbsh-compliance-audit-logger')} </span>
									<strong>{selectedDetails.snapshot_data.security.force_ssl_admin ? 'TRUE' : 'FALSE'}</strong>
								</div>
								<div>
									<span>{__('Database Version:', 'tbsh-compliance-audit-logger')} </span>
									<strong>{selectedDetails.snapshot_data.security.db_version}</strong>
								</div>
								<div>
									<span>{__('SSL Active Connection:', 'tbsh-compliance-audit-logger')} </span>
									<strong>{selectedDetails.snapshot_data.security.is_ssl ? 'TRUE' : 'FALSE'}</strong>
								</div>
							</div>
						</div>
					</div>
				) : null}
			</Modal>
		</div>
	);
}
