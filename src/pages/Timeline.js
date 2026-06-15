import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';

export default function Timeline() {
	const [timeline, setTimeline] = useState([]);
	const [loading, setLoading] = useState(true);

	// Filters.
	const [search, setSearch] = useState('');
	const [category, setCategory] = useState('');
	const [dateStart, setDateStart] = useState('');
	const [dateEnd, setDateEnd] = useState('');
	const [userId, setUserId] = useState('');

	const fetchTimeline = () => {
		setLoading(true);
		let queryPath = '/tbsh-compliance-audit-logger/v1/timeline?';

		if ( search ) queryPath += `&search=${encodeURIComponent(search)}`;
		if ( category ) queryPath += `&category=${encodeURIComponent(category)}`;
		if ( dateStart ) queryPath += `&date_start=${dateStart}`;
		if ( dateEnd ) queryPath += `&date_end=${dateEnd}`;
		if ( userId ) queryPath += `&user_id=${userId}`;

		apiFetch({ path: queryPath })
			.then((data) => {
				setTimeline(data || []);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchTimeline();
	}, [category, dateStart, dateEnd, userId]);

	const handleSearchSubmit = (e) => {
		e.preventDefault();
		fetchTimeline();
	};

	const resetFilters = () => {
		setSearch('');
		setCategory('');
		setDateStart('');
		setDateEnd('');
		setUserId('');
		fetchTimeline();
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
		<div className="tbsh-timeline-page">
			{/* Filters Bar */}
			<div className="tbsh-filters-bar">
				<form onSubmit={handleSearchSubmit} style={{ display: 'flex', gap: '8px', flexGrow: 1, minWidth: '220px' }}>
					<input 
						type="text" 
						className="tbsh-filter-input" 
						style={{ flexGrow: 1 }}
						placeholder={__('Search timeline events...', 'tbsh-compliance-audit-logger')}
						value={search}
						onChange={(e) => setSearch(e.target.value)}
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-primary">
						<Icon name="search" />
						{__('Filter', 'tbsh-compliance-audit-logger')}
					</button>
				</form>

				<select 
					className="tbsh-filter-input"
					value={category}
					onChange={(e) => setCategory(e.target.value)}
				>
					<option value="">{__('All Categories', 'tbsh-compliance-audit-logger')}</option>
					{categories.map((cat, i) => (
						<option key={i} value={cat}>{cat}</option>
					))}
				</select>

				<input 
					type="number" 
					className="tbsh-filter-input"
					style={{ width: '100px' }}
					placeholder={__('User ID', 'tbsh-compliance-audit-logger')}
					value={userId}
					onChange={(e) => setUserId(e.target.value)}
				/>

				<div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
					<input 
						type="date" 
						className="tbsh-filter-input" 
						value={dateStart}
						onChange={(e) => setDateStart(e.target.value)}
					/>
					<span style={{ color: 'var(--tbsh-text-muted)' }}>{__('to', 'tbsh-compliance-audit-logger')}</span>
					<input 
						type="date" 
						className="tbsh-filter-input" 
						value={dateEnd}
						onChange={(e) => setDateEnd(e.target.value)}
					/>
				</div>

				<button className="tbsh-btn tbsh-btn-secondary" onClick={resetFilters}>
					{__('Clear', 'tbsh-compliance-audit-logger')}
				</button>
			</div>

			{/* Timeline Visual Container */}
			<div className="tbsh-card" style={{ padding: '24px' }}>
				{loading ? (
					<SkeletonLoader rows={6} cols={3} />
				) : timeline.length > 0 ? (
					<div style={{ display: 'flex', flexDirection: 'column', position: 'relative', paddingLeft: '24px' }}>
						{/* Vertical Line */}
						<div 
							style={{ 
								position: 'absolute', 
								left: '6px', 
								top: '10px', 
								bottom: '10px', 
								width: '2px', 
								backgroundColor: 'var(--tbsh-border-color)' 
							}} 
						/>

						{timeline.map((event, idx) => {
							let bulletColor = 'var(--tbsh-primary)';
							let cardBorder = '1px solid var(--tbsh-border-color)';
							if (event.severity === 'warning') {
								bulletColor = 'var(--tbsh-warning)';
								cardBorder = '1px solid var(--tbsh-warning)';
							} else if (event.severity === 'critical' || event.severity === 'error') {
								bulletColor = 'var(--tbsh-danger)';
								cardBorder = '1px solid var(--tbsh-danger)';
							}

							return (
								<div 
									key={event.id} 
									style={{ 
										position: 'relative', 
										marginBottom: idx === timeline.length - 1 ? 0 : '24px' 
									}}
								>
									{/* Bullet Indicator */}
									<div 
										style={{ 
											position: 'absolute', 
											left: '-23px', 
											top: '6px', 
											width: '10px', 
											height: '10px', 
											borderRadius: '50%', 
											backgroundColor: bulletColor,
											border: '2px solid var(--tbsh-bg-primary)',
											zIndex: 2
										}} 
									/>

									{/* Event Box */}
									<div 
										style={{ 
											padding: '16px', 
											border: cardBorder, 
											backgroundColor: 'var(--tbsh-bg-secondary)', 
											borderRadius: '8px',
											boxShadow: 'var(--tbsh-shadow-sm)'
										}}
									>
										<div 
											style={{ 
												display: 'flex', 
												justifyContent: 'space-between', 
												alignItems: 'center', 
												marginBottom: '8px',
												flexWrap: 'wrap',
												gap: '8px'
											}}
										>
											<span style={{ fontSize: '13px', color: 'var(--tbsh-text-muted)', display: 'flex', alignItems: 'center', gap: '6px' }}>
												<Icon name="calendar" />
												{event.created_at}
											</span>
											<span style={{ display: 'flex', gap: '6px' }}>
												<span className="tbsh-badge tbsh-badge-info">{event.event_category}</span>
												<span className={`tbsh-badge tbsh-badge-${event.severity === 'critical' || event.severity === 'error' ? 'danger' : (event.severity === 'warning' ? 'warning' : 'info')}`}>
													{event.severity}
												</span>
											</span>
										</div>

										<h4 style={{ margin: '0 0 6px 0', fontSize: '15px', color: 'var(--tbsh-text-primary)' }}>
											{event.event_title}
										</h4>
										
										<p style={{ margin: '0 0 12px 0', fontSize: '14px', color: 'var(--tbsh-text-secondary)' }}>
											{event.event_message}
										</p>

										<div 
											style={{ 
												display: 'flex', 
												gap: '24px', 
												fontSize: '12px', 
												color: 'var(--tbsh-text-muted)',
												borderTop: '1px solid var(--tbsh-border-color)',
												paddingTop: '8px',
												flexWrap: 'wrap'
											}}
										>
											<span>
												<strong>{__('Actor:', 'tbsh-compliance-audit-logger')}</strong> {event.username} ({event.role})
											</span>
											<span>
												<strong>{__('IP Hash:', 'tbsh-compliance-audit-logger')}</strong> <code style={{ fontSize: '11px' }}>{event.ip_hash ? event.ip_hash.substring(0, 10) + '...' : '-'}</code>
											</span>
											{event.request_uri && (
												<span style={{ textOverflow: 'ellipsis', overflow: 'hidden', whiteSpace: 'nowrap', maxWidth: '300px' }}>
													<strong>{__('URI:', 'tbsh-compliance-audit-logger')}</strong> <code>{event.request_uri}</code>
												</span>
											)}
										</div>
									</div>
								</div>
							);
						})}
					</div>
				) : (
					<EmptyState title={__('No Forensic Events', 'tbsh-compliance-audit-logger')} description={__('No events matched your timeline query.', 'tbsh-compliance-audit-logger')} icon="timeline" />
				)}
			</div>
		</div>
	);
}
