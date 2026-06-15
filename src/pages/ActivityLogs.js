import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Icon from '../components/Icon';

export default function ActivityLogs() {
	const [logs, setLogs] = useState([]);
	const [totalItems, setTotalItems] = useState(0);
	const [totalPages, setTotalPages] = useState(1);
	const [page, setPage] = useState(1);
	const [perPage, setPerPage] = useState(20);
	const [loading, setLoading] = useState(true);

	// Filters.
	const [search, setSearch] = useState('');
	const [severity, setSeverity] = useState('');
	const [category, setCategory] = useState('');
	const [dateStart, setDateStart] = useState('');
	const [dateEnd, setDateEnd] = useState('');

	// Sorting.
	const [orderby, setOrderby] = useState('id');
	const [order, setOrder] = useState('DESC');

	// Selected Log for detail modal.
	const [selectedLog, setSelectedLog] = useState(null);

	// Column Visibility.
	const [showColumnControls, setShowColumnControls] = useState(false);
	const [visibleColumns, setVisibleColumns] = useState({
		id: true,
		timestamp: true,
		actor: true,
		title: true,
		category: true,
		severity: true,
		site: false,
		method: false,
	});

	const fetchLogs = () => {
		setLoading(true);
		let queryPath = `/tbsh-compliance-audit-logger/v1/logs?page=${page}&per_page=${perPage}&orderby=${orderby}&order=${order}`;

		if ( search ) queryPath += `&search=${encodeURIComponent(search)}`;
		if ( severity ) queryPath += `&severity=${severity}`;
		if ( category ) queryPath += `&category=${encodeURIComponent(category)}`;
		if ( dateStart ) queryPath += `&date_start=${dateStart}`;
		if ( dateEnd ) queryPath += `&date_end=${dateEnd}`;

		apiFetch({ path: queryPath })
			.then((data) => {
				setLogs(data.logs || []);
				setTotalItems(data.total || 0);
				setTotalPages(data.pages || 1);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchLogs();
	}, [page, perPage, orderby, order, severity, category, dateStart, dateEnd]);

	const handleSearchSubmit = (e) => {
		e.preventDefault();
		setPage(1);
		fetchLogs();
	};

	const resetFilters = () => {
		setSearch('');
		setSeverity('');
		setCategory('');
		setDateStart('');
		setDateEnd('');
		setPage(1);
	};

	const toggleColumn = (col) => {
		setVisibleColumns((prev) => ({
			...prev,
			[col]: ! prev[col],
		}));
	};

	const handleSort = (column) => {
		if (orderby === column) {
			setOrder((prev) => (prev === 'ASC' ? 'DESC' : 'ASC'));
		} else {
			setOrderby(column);
			setOrder('DESC');
		}
		setPage(1);
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
		<div className="tbsh-logs-page">
			{/* Advanced Filters Panel */}
			<div className="tbsh-filters-bar">
				<form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: '8px', flexGrow: 1, minWidth: '240px' }}>
					<input 
						type="text" 
						className="tbsh-filter-input" 
						style={{ flexGrow: 1 }}
						placeholder={__('Search logs, messages, users...', 'tbsh-compliance-audit-logger')}
						value={search}
						onChange={(e) => setSearch(e.target.value)}
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-primary">
						<Icon name="search" />
						{__('Search', 'tbsh-compliance-audit-logger')}
					</button>
				</form>

				<select 
					className="tbsh-filter-input"
					value={severity}
					onChange={(e) => { setSeverity(e.target.value); setPage(1); }}
				>
					<option value="">{__('All Severities', 'tbsh-compliance-audit-logger')}</option>
					<option value="info">Info</option>
					<option value="notice">Notice</option>
					<option value="warning">Warning</option>
					<option value="error">Error</option>
					<option value="critical">Critical</option>
				</select>

				<select 
					className="tbsh-filter-input"
					value={category}
					onChange={(e) => { setCategory(e.target.value); setPage(1); }}
				>
					<option value="">{__('All Categories', 'tbsh-compliance-audit-logger')}</option>
					{categories.map((cat, i) => (
						<option key={i} value={cat}>{cat}</option>
					))}
				</select>

				<div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
					<input 
						type="date" 
						className="tbsh-filter-input" 
						value={dateStart}
						onChange={(e) => { setDateStart(e.target.value); setPage(1); }}
					/>
					<span style={{ color: 'var(--tbsh-text-muted)' }}>{__('to', 'tbsh-compliance-audit-logger')}</span>
					<input 
						type="date" 
						className="tbsh-filter-input" 
						value={dateEnd}
						onChange={(e) => { setDateEnd(e.target.value); setPage(1); }}
					/>
				</div>

				<button className="tbsh-btn tbsh-btn-secondary" onClick={resetFilters}>
					{__('Reset', 'tbsh-compliance-audit-logger')}
				</button>

				<div style={{ position: 'relative' }}>
					<button 
						className="tbsh-btn tbsh-btn-secondary" 
						onClick={() => setShowColumnControls(!showColumnControls)}
					>
						<Icon name="filter" />
						{__('Columns', 'tbsh-compliance-audit-logger')}
					</button>
					
					{showColumnControls && (
						<div 
							className="tbsh-card" 
							style={{ 
								position: 'absolute', 
								right: 0, 
								top: '100%', 
								marginTop: '8px', 
								zIndex: 100, 
								minWidth: '180px',
								padding: '12px' 
							}}
						>
							<h4 style={{ margin: '0 0 8px 0', fontSize: '13px' }}>{__('Toggle Columns', 'tbsh-compliance-audit-logger')}</h4>
							{Object.keys(visibleColumns).map((col) => (
								<label key={col} style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px', fontSize: '13px', cursor: 'pointer' }}>
									<input 
										type="checkbox" 
										checked={visibleColumns[col]} 
										onChange={() => toggleColumn(col)} 
									/>
									{col.toUpperCase()}
								</label>
							))}
						</div>
					)}
				</div>
			</div>

			{/* Logs Table Container */}
			<div className="tbsh-table-container">
				{loading ? (
					<SkeletonLoader rows={8} cols={5} />
				) : logs.length > 0 ? (
					<table className="tbsh-table">
						<thead>
							<tr>
								{visibleColumns.id && <th style={{ cursor: 'pointer' }} onClick={() => handleSort('id')}>{__('ID', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.timestamp && <th style={{ cursor: 'pointer' }} onClick={() => handleSort('created_at')}>{__('Timestamp', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.actor && <th style={{ cursor: 'pointer' }} onClick={() => handleSort('username')}>{__('Actor', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.title && <th>{__('Event Title', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.category && <th style={{ cursor: 'pointer' }} onClick={() => handleSort('event_category')}>{__('Category', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.severity && <th style={{ cursor: 'pointer' }} onClick={() => handleSort('severity')}>{__('Severity', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.site && <th>{__('Site ID', 'tbsh-compliance-audit-logger')}</th>}
								{visibleColumns.method && <th>{__('Method', 'tbsh-compliance-audit-logger')}</th>}
								<th style={{ textAlign: 'right' }}>{__('Actions', 'tbsh-compliance-audit-logger')}</th>
							</tr>
						</thead>
						<tbody>
							{logs.map((log) => {
								let badgeClass = 'info';
								if (log.severity === 'warning') badgeClass = 'warning';
								if (log.severity === 'critical' || log.severity === 'error') badgeClass = 'danger';

								return (
									<tr key={log.id}>
										{visibleColumns.id && <td>{log.id}</td>}
										{visibleColumns.timestamp && <td style={{ whiteSpace: 'nowrap' }}>{log.created_at}</td>}
										{visibleColumns.actor && (
											<td>
												<strong>{log.username}</strong>
												<span style={{ display: 'block', fontSize: '11px', color: 'var(--tbsh-text-muted)' }}>{log.role}</span>
											</td>
										)}
										{visibleColumns.title && <td>{log.event_title}</td>}
										{visibleColumns.category && <td>{log.event_category}</td>}
										{visibleColumns.severity && (
											<td>
												<span className={`tbsh-badge tbsh-badge-${badgeClass}`}>{log.severity}</span>
											</td>
										)}
										{visibleColumns.site && <td>{log.site_id}</td>}
										{visibleColumns.method && <td><code>{log.request_method || 'GET'}</code></td>}
										<td style={{ textAlign: 'right' }}>
											<button 
												className="tbsh-btn tbsh-btn-secondary" 
												onClick={() => setSelectedLog(log)}
												style={{ padding: '4px 8px', fontSize: '12px' }}
											>
												{__('Details', 'tbsh-compliance-audit-logger')}
											</button>
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				) : (
					<EmptyState title={__('No Audit Records Found', 'tbsh-compliance-audit-logger')} description={__('No logs match your search and filter criteria.', 'tbsh-compliance-audit-logger')} icon="logs" />
				)}

				{/* Pagination Controls */}
				<div className="tbsh-pagination">
					<div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
						<span style={{ fontSize: '13px', color: 'var(--tbsh-text-secondary)' }}>
							{__('Items per page:', 'tbsh-compliance-audit-logger')}
						</span>
						<select 
							className="tbsh-filter-input" 
							value={perPage} 
							onChange={(e) => { setPerPage(parseInt(e.target.value)); setPage(1); }}
							style={{ padding: '4px 24px 4px 8px', fontSize: '13px', minWidth: '65px' }}
						>
							<option value="10">10</option>
							<option value="20">20</option>
							<option value="50">50</option>
							<option value="100">100</option>
						</select>
						<span style={{ fontSize: '13px', color: 'var(--tbsh-text-muted)', marginLeft: '12px' }}>
							{sprintf(__('Showing %d total records', 'tbsh-compliance-audit-logger'), totalItems)}
						</span>
					</div>

					<div style={{ display: 'flex', gap: '8px' }}>
						<button 
							className="tbsh-btn tbsh-btn-secondary" 
							disabled={page === 1}
							onClick={() => setPage((p) => Math.max(1, p - 1))}
							style={{ padding: '6px 12px' }}
						>
							{__('Previous', 'tbsh-compliance-audit-logger')}
						</button>
						<span style={{ display: 'flex', alignItems: 'center', padding: '0 8px', fontSize: '13px', fontWeight: '500' }}>
							{sprintf(__('Page %1$d of %2$d', 'tbsh-compliance-audit-logger'), page, totalPages)}
						</span>
						<button 
							className="tbsh-btn tbsh-btn-secondary" 
							disabled={page === totalPages}
							onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
							style={{ padding: '6px 12px' }}
						>
							{__('Next', 'tbsh-compliance-audit-logger')}
						</button>
					</div>
				</div>
			</div>

			{/* Log Detail Modal */}
			<Modal 
				title={__('Audit Event Record Inspection', 'tbsh-compliance-audit-logger')} 
				isOpen={!!selectedLog} 
				onClose={() => setSelectedLog(null)}
			>
				{selectedLog && (
					<div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
						<div>
							<strong>{__('UUID:', 'tbsh-compliance-audit-logger')}</strong> 
							<code style={{ display: 'block', padding: '6px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', marginTop: '4px', fontSize: '12px' }}>
								{selectedLog.event_uuid}
							</code>
						</div>

						<div>
							<strong>{__('Event Action:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.event_title}
						</div>

						<div>
							<strong>{__('Description:', 'tbsh-compliance-audit-logger')}</strong>
							<p style={{ margin: '4px 0 0 0', padding: '8px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px' }}>
								{selectedLog.event_message}
							</p>
						</div>

						<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px', marginTop: '8px' }}>
							<div>
								<strong>{__('Actor ID:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.user_id}
							</div>
							<div>
								<strong>{__('Username:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.username}
							</div>
							<div>
								<strong>{__('Severity:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.severity}
							</div>
							<div>
								<strong>{__('Category:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.event_category}
							</div>
							<div>
								<strong>{__('Request Method:', 'tbsh-compliance-audit-logger')}</strong> <code>{selectedLog.request_method || 'GET'}</code>
							</div>
							<div>
								<strong>{__('Site ID:', 'tbsh-compliance-audit-logger')}</strong> {selectedLog.site_id}
							</div>
						</div>

						<div style={{ marginTop: '8px' }}>
							<strong>{__('Request URI:', 'tbsh-compliance-audit-logger')}</strong>
							<code style={{ display: 'block', padding: '6px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', marginTop: '4px', fontSize: '12px', wordBreak: 'break-all' }}>
								{selectedLog.request_uri || '-'}
							</code>
						</div>

						<div>
							<strong>{__('Referrer:', 'tbsh-compliance-audit-logger')}</strong>
							<code style={{ display: 'block', padding: '6px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', marginTop: '4px', fontSize: '12px', wordBreak: 'break-all' }}>
								{selectedLog.referrer || '-'}
							</code>
						</div>

						<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
							<div>
								<strong>{__('Client IP Hash:', 'tbsh-compliance-audit-logger')}</strong>
								<code style={{ display: 'block', padding: '4px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', marginTop: '4px', fontSize: '11px', textOverflow: 'ellipsis', overflow: 'hidden' }}>
									{selectedLog.ip_hash || '-'}
								</code>
							</div>
							<div>
								<strong>{__('User Agent Hash:', 'tbsh-compliance-audit-logger')}</strong>
								<code style={{ display: 'block', padding: '4px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', marginTop: '4px', fontSize: '11px', textOverflow: 'ellipsis', overflow: 'hidden' }}>
									{selectedLog.user_agent_hash || '-'}
								</code>
							</div>
						</div>

						<div style={{ borderTop: '1px solid var(--tbsh-border-color)', paddingTop: '12px', marginTop: '8px' }}>
							<strong>{__('Compliance Tags:', 'tbsh-compliance-audit-logger')}</strong>
							<div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap', marginTop: '6px' }}>
								{selectedLog.compliance_tags.split(',').map((tag, idx) => (
									<span key={idx} className="tbsh-badge tbsh-badge-info">{tag.trim()}</span>
								))}
							</div>
						</div>

						<div style={{ borderTop: '1px solid var(--tbsh-border-color)', paddingTop: '12px' }}>
							<h5 style={{ margin: '0 0 8px 0', fontSize: '13px', color: 'var(--tbsh-text-primary)' }}>
								{__('Cryptographic Signatures', 'tbsh-compliance-audit-logger')}
							</h5>
							<div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
								<div>
									<span style={{ fontSize: '11px', color: 'var(--tbsh-text-muted)' }}>{__('Current Row Hash (SHA-256):', 'tbsh-compliance-audit-logger')}</span>
									<code style={{ display: 'block', padding: '4px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', fontSize: '11px', wordBreak: 'break-all', color: 'var(--tbsh-success-text)' }}>
										{selectedLog.integrity_hash}
									</code>
								</div>
								<div>
									<span style={{ fontSize: '11px', color: 'var(--tbsh-text-muted)' }}>{__('Previous Row Hash (SHA-256):', 'tbsh-compliance-audit-logger')}</span>
									<code style={{ display: 'block', padding: '4px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '4px', fontSize: '11px', wordBreak: 'break-all' }}>
										{selectedLog.previous_hash}
									</code>
								</div>
							</div>
						</div>

						{selectedLog.metadata && Object.keys(selectedLog.metadata).length > 0 && (
							<div style={{ borderTop: '1px solid var(--tbsh-border-color)', paddingTop: '12px' }}>
								<h5 style={{ margin: '0 0 8px 0', fontSize: '13px', color: 'var(--tbsh-text-primary)' }}>
									{__('Extended Metadata Context', 'tbsh-compliance-audit-logger')}
								</h5>
								<pre style={{ margin: 0, padding: '10px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: '6px', fontSize: '12px', overflowX: 'auto' }}>
									{JSON.stringify(selectedLog.metadata, null, 2)}
								</pre>
							</div>
						)}
					</div>
				)}
			</Modal>
		</div>
	);
}
