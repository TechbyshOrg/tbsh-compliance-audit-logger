import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import { actorLabels } from '../components/origin';
import { SeverityOptions } from '../components/labels';
import { formatSiteTime } from '../components/time';

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
	const [actor, setActor] = useState('');

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
				user_id: userId,
				actor
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

			<div className="tbsh-grid-sidebar">
				{/* Configuration Panel */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
						<h3 className="tbsh-section-title">
							{ __( 'Create an export', 'tbsh-compliance-audit-logger' ) }
						</h3>
					<form onSubmit={triggerExport}>
						<fieldset className="tbsh-form-group" style={ { border: 0, padding: 0, margin: '0 0 16px' } }>
							<legend className="tbsh-form-label">{ __( 'Format', 'tbsh-compliance-audit-logger' ) }</legend>
							<div className="tbsh-row" style={ { gap: '16px', marginTop: '6px' } }>
								<label className="tbsh-row" style={ { cursor: 'pointer', fontSize: '14px', gap: '6px' } }>
									<input type="radio" name="format" value="csv" checked={ format === 'csv' } onChange={ () => setFormat( 'csv' ) } />
									{ __( 'CSV (spreadsheets)', 'tbsh-compliance-audit-logger' ) }
								</label>
								<label className="tbsh-row" style={ { cursor: 'pointer', fontSize: '14px', gap: '6px' } }>
									<input type="radio" name="format" value="json" checked={ format === 'json' } onChange={ () => setFormat( 'json' ) } />
									{ __( 'JSON (all fields)', 'tbsh-compliance-audit-logger' ) }
								</label>
							</div>
						</fieldset>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-export-severity">
								{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select id="tbsh-export-severity" className="tbsh-form-control" value={ severity } onChange={ ( e ) => setSeverity( e.target.value ) }>
								<SeverityOptions allLabel={ __( 'All severities', 'tbsh-compliance-audit-logger' ) } />
							</select>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-export-category">
								{ __( 'Category', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select id="tbsh-export-category" className="tbsh-form-control" value={ category } onChange={ ( e ) => setCategory( e.target.value ) }>
								<option value="">{ __( 'All categories', 'tbsh-compliance-audit-logger' ) }</option>
								{categories.map((cat, i) => (
									<option key={i} value={cat}>{cat}</option>
								))}
							</select>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-export-actor">
								{ __( 'Made by', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-export-actor"
								className="tbsh-form-control"
								value={ actor }
								onChange={ ( e ) => setActor( e.target.value ) }
							>
								<option value="">{ __( 'Anyone', 'tbsh-compliance-audit-logger' ) }</option>
								{ Object.entries( actorLabels() ).map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>
										{ label }
									</option>
								) ) }
							</select>
						</div>

						<div className="tbsh-form-group tbsh-grid-fields">
							<div>
								<label className="tbsh-form-label" htmlFor="tbsh-export-from">
									{ __( 'From', 'tbsh-compliance-audit-logger' ) }
								</label>
								<input id="tbsh-export-from" type="date" className="tbsh-form-control" value={ dateStart } max={ dateEnd || undefined } onChange={ ( e ) => setDateStart( e.target.value ) } />
							</div>
							<div>
								<label className="tbsh-form-label" htmlFor="tbsh-export-to">
									{ __( 'To', 'tbsh-compliance-audit-logger' ) }
								</label>
								<input id="tbsh-export-to" type="date" className="tbsh-form-control" value={ dateEnd } min={ dateStart || undefined } onChange={ ( e ) => setDateEnd( e.target.value ) } />
							</div>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-export-search">
								{ __( 'Contains text', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input id="tbsh-export-search" type="search" className="tbsh-form-control" placeholder={ __( 'Event, message or username', 'tbsh-compliance-audit-logger' ) } value={ search } onChange={ ( e ) => setSearch( e.target.value ) } />
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-export-user">
								{ __( 'User ID', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input id="tbsh-export-user" type="number" min="0" inputMode="numeric" className="tbsh-form-control" placeholder={ __( 'e.g. 1', 'tbsh-compliance-audit-logger' ) } value={ userId } onChange={ ( e ) => setUserId( e.target.value ) } />
						</div>

						<button type="submit" className="tbsh-btn tbsh-btn-primary" style={{ width: '100%', justifyContent: 'center', marginTop: '12px' }} disabled={btnLoading}>
							<Icon name="export" />
							{ btnLoading ? __( 'Creating…', 'tbsh-compliance-audit-logger' ) : __( 'Create export', 'tbsh-compliance-audit-logger' ) }
						</button>
					</form>
				</div>

				{/* Available Downloads list */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h3 className="tbsh-section-title">
						{ __( 'Your exports', 'tbsh-compliance-audit-logger' ) }
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
							<EmptyState title={ __( 'Could not load exports', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
						) : exportsList.length > 0 ? (
							<table className="tbsh-table">
								<thead>
									<tr>
<th>{ __( 'Created', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{__('Format', 'tbsh-compliance-audit-logger')}</th>
										<th>{__('File Size', 'tbsh-compliance-audit-logger')}</th>
<th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th>
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
<td>{ formatSiteTime( item.created_at ) }</td>
												<td>
													<span className={`tbsh-badge tbsh-badge-${item.format === 'csv' ? 'success' : 'info'}`}>
														{item.format}
													</span>
												</td>
												<td><code>{sizeStr}</code></td>
												<td style={ { textAlign: 'end' } }>
													<div className="tbsh-row" style={ { justifyContent: 'flex-end', flexWrap: 'nowrap' } }>
													<a
														href={ getDownloadUrl( item.filename ) }
														className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
														aria-label={ sprintf(
															/* translators: 1: file format, 2: creation date */
															__( 'Download %1$s export from %2$s', 'tbsh-compliance-audit-logger' ),
															String( item.format ).toUpperCase(),
															formatSiteTime( item.created_at )
														) }
													>
														<Icon name="download" />
														{__('Download', 'tbsh-compliance-audit-logger')}
													</a>
													<button
														type="button"
														className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
														onClick={ () => createShareLink( item.filename ) }
														aria-label={ sprintf(
															/* translators: 1: file format, 2: creation date */
															__( 'Create share link for %1$s export from %2$s', 'tbsh-compliance-audit-logger' ),
															String( item.format ).toUpperCase(),
															formatSiteTime( item.created_at )
														) }
													>
														{ __( 'Share link', 'tbsh-compliance-audit-logger' ) }
													</button>
													</div>
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						) : (
<EmptyState title={ __( 'No exports yet', 'tbsh-compliance-audit-logger' ) } description={ __( 'Choose filters and select "Create export". Files are kept for 2 hours.', 'tbsh-compliance-audit-logger' ) } icon="info" />
						)}
					</div>
				</div>
			</div>
		</div>
	);
}
