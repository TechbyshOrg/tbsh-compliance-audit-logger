import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';

export default function ExportCenter() {
	const [exportsList, setExportsList] = useState([]);
	const [loading, setLoading] = useState(true);
	const [btnLoading, setBtnLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });
	const [error, setError] = useState(null);
	const [shareUrl, setShareUrl] = useState('');

	// Filter Form States.
	const [format, setFormat] = useState('csv');
	const [severity, setSeverity] = useState('');
	const [category, setCategory] = useState('');
	const [dateStart, setDateStart] = useState('');
	const [dateEnd, setDateEnd] = useState('');
	const [search, setSearch] = useState('');
	const [userId, setUserId] = useState('');

	const fetchExports = () => {
		setLoading(true);
		setError(null);
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/exports' })
			.then((data) => {
				setExportsList(data || []);
				setLoading(false);
			})
			.catch((err) => {
				setError(err.message || __('Failed to load exports.', 'tbsh-compliance-audit-logger'));
				setExportsList([]);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchExports();
	}, []);

	const triggerExport = (e) => {
		e.preventDefault();
		setBtnLoading(true);
		setNotice({ type: '', message: '' });

		apiFetch({
			path: '/tbsh-compliance-audit-logger/v1/exports',
			method: 'POST',
			data: {
				format,
				severity,
				category,
				date_start: dateStart,
				date_end: dateEnd,
				search,
				user_id: userId
			}
		})
		.then((res) => {
			setNotice({ type: 'success', message: res.message });
			fetchExports();
			setBtnLoading(false);
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to generate export file.', 'tbsh-compliance-audit-logger') });
			setBtnLoading(false);
		});
	};

	const createShareLink = (filename) => {
		setShareUrl('');
		apiFetch({
			path: '/tbsh-compliance-audit-logger/v1/exports/share',
			method: 'POST',
			data: { file: filename },
		})
		.then((res) => {
			setShareUrl(res.url);
			setNotice({ type: 'success', message: res.message });
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to create share link.', 'tbsh-compliance-audit-logger') });
		});
	};

	const getDownloadUrl = (filename) => {
		const settings = window.tbshCalApiSettings;
		return `${settings.root}${settings.namespace}/exports/download?file=${filename}&_wpnonce=${settings.nonce}`;
	};

	const categories = [
		'Access Control',
		'Identity Management',
		'Authentication',
		'Authorization',
		'Change Management',
		'Configuration Management',
		'Security Monitoring',
		'Incident Detection',
		'Operational Security',
		'Audit Trail',
	];

	return (
		<div className="tbsh-exports-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

			<div style={{ display: 'grid', gridTemplateColumns: '320px 1fr', gap: '24px', alignItems: 'start' }}>
				{/* Configuration Panel */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
						{__('Configure Audit Export', 'tbsh-compliance-audit-logger')}
					</h3>
					<form onSubmit={triggerExport}>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Export Format', 'tbsh-compliance-audit-logger')}</label>
							<div style={{ display: 'flex', gap: '16px', marginTop: '6px' }}>
								<label style={{ cursor: 'pointer', fontSize: '14px' }}>
									<input type="radio" name="format" value="csv" checked={format === 'csv'} onChange={() => setFormat('csv')} />
									{__(' CSV Layout', 'tbsh-compliance-audit-logger')}
								</label>
								<label style={{ cursor: 'pointer', fontSize: '14px' }}>
									<input type="radio" name="format" value="json" checked={format === 'json'} onChange={() => setFormat('json')} />
									{__(' JSON Raw', 'tbsh-compliance-audit-logger')}
								</label>
							</div>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Severity Threshold', 'tbsh-compliance-audit-logger')}</label>
							<select className="tbsh-form-control" value={severity} onChange={(e) => setSeverity(e.target.value)}>
								<option value="">{__('All Severities', 'tbsh-compliance-audit-logger')}</option>
								<option value="info">Info</option>
								<option value="notice">Notice</option>
								<option value="warning">Warning</option>
								<option value="error">Error</option>
								<option value="critical">Critical</option>
							</select>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Compliance Category', 'tbsh-compliance-audit-logger')}</label>
							<select className="tbsh-form-control" value={category} onChange={(e) => setCategory(e.target.value)}>
								<option value="">{__('All Categories', 'tbsh-compliance-audit-logger')}</option>
								{categories.map((cat, i) => (
									<option key={i} value={cat}>{cat}</option>
								))}
							</select>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Date Range', 'tbsh-compliance-audit-logger')}</label>
							<div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
								<input type="date" className="tbsh-form-control" value={dateStart} onChange={(e) => setDateStart(e.target.value)} />
								<span style={{ fontSize: '11px', color: 'var(--tbsh-text-muted)', textAlign: 'center' }}>{__('to', 'tbsh-compliance-audit-logger')}</span>
								<input type="date" className="tbsh-form-control" value={dateEnd} onChange={(e) => setDateEnd(e.target.value)} />
							</div>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Keyword Search', 'tbsh-compliance-audit-logger')}</label>
							<input type="text" className="tbsh-form-control" placeholder={__('Logs content keyword...', 'tbsh-compliance-audit-logger')} value={search} onChange={(e) => setSearch(e.target.value)} />
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('User ID Filter', 'tbsh-compliance-audit-logger')}</label>
							<input type="number" className="tbsh-form-control" placeholder={__('e.g. 1', 'tbsh-compliance-audit-logger')} value={userId} onChange={(e) => setUserId(e.target.value)} />
						</div>

						<button type="submit" className="tbsh-btn tbsh-btn-primary" style={{ width: '100%', justifyContent: 'center', marginTop: '12px' }} disabled={btnLoading}>
							<Icon name="export" />
							{btnLoading ? __('Generating...', 'tbsh-compliance-audit-logger') : __('Compile Export File', 'tbsh-compliance-audit-logger')}
						</button>
					</form>
				</div>

				{/* Available Downloads list */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
						{__('Available Compliance Exports', 'tbsh-compliance-audit-logger')}
					</h3>
					<p style={{ fontSize: '13px', color: 'var(--tbsh-text-secondary)', marginBottom: '16px' }}>
						{__('Generated files are capability-protected and expire after 2 hours for security. You can also mint a temporary share link for auditors.', 'tbsh-compliance-audit-logger')}
					</p>

					{shareUrl && (
						<div style={{ marginBottom: '16px', padding: '12px', background: 'var(--tbsh-bg-secondary)', borderRadius: '6px', wordBreak: 'break-all' }}>
							<strong>{__('Share link:', 'tbsh-compliance-audit-logger')}</strong>{' '}
							<a href={shareUrl} target="_blank" rel="noopener noreferrer">{shareUrl}</a>
						</div>
					)}

					<div className="tbsh-table-container" style={{ margin: 0 }}>
						{loading ? (
							<SkeletonLoader rows={4} cols={3} />
						) : error ? (
							<EmptyState title={__('Error Loading Data', 'tbsh-compliance-audit-logger')} description={error} icon="alert" />
						) : exportsList.length > 0 ? (
							<table className="tbsh-table">
								<thead>
									<tr>
										<th>{__('Date Compiled', 'tbsh-compliance-audit-logger')}</th>
										<th>{__('Format', 'tbsh-compliance-audit-logger')}</th>
										<th>{__('File Size', 'tbsh-compliance-audit-logger')}</th>
										<th style={{ textAlign: 'right' }}>{__('Actions', 'tbsh-compliance-audit-logger')}</th>
									</tr>
								</thead>
								<tbody>
									{exportsList.map((item, idx) => {
										let sizeStr = '0 B';
										if (item.size > 1024 * 1024) {
											sizeStr = `${(item.size / (1024 * 1024)).toFixed(2)} MB`;
										} else if (item.size > 1024) {
											sizeStr = `${(item.size / 1024).toFixed(1)} KB`;
										} else {
											sizeStr = `${item.size} B`;
										}

										return (
											<tr key={idx}>
												<td>{item.created_at}</td>
												<td>
													<span className={`tbsh-badge tbsh-badge-${item.format === 'csv' ? 'success' : 'info'}`}>
														{item.format}
													</span>
												</td>
												<td><code>{sizeStr}</code></td>
												<td style={{ textAlign: 'right', display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
													<a 
														href={getDownloadUrl(item.filename)} 
														className="tbsh-btn tbsh-btn-secondary"
														style={{ padding: '4px 8px', fontSize: '12px' }}
													>
														<Icon name="download" />
														{__('Download', 'tbsh-compliance-audit-logger')}
													</a>
													<button
														type="button"
														className="tbsh-btn tbsh-btn-secondary"
														style={{ padding: '4px 8px', fontSize: '12px' }}
														onClick={() => createShareLink(item.filename)}
													>
														{__('Share Link', 'tbsh-compliance-audit-logger')}
													</button>
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						) : (
							<EmptyState title={__('No Available Exports', 'tbsh-compliance-audit-logger')} description={__('Use the parameters on the left to compile an audit report.', 'tbsh-compliance-audit-logger')} icon="info" />
						)}
					</div>
				</div>
			</div>
		</div>
	);
}
