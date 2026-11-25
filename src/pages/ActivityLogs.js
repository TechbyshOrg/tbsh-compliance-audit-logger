import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';
import LogDetailModal from '../components/LogDetailModal';
import { actorLabels, originText } from '../components/origin';
import { SeverityBadge, SeverityOptions, severityLabel } from '../components/labels';
import { SiteTime, formatSiteTime } from '../components/time';
import { takePendingState } from '../components/navState';

const COLUMNS_KEY = 'tbshCalLogColumns';

const DEFAULT_COLUMNS = {
	id: true,
	timestamp: true,
	actor: true,
	title: true,
	category: true,
	severity: true,
	site: false,
	method: false,
};

const CATEGORIES = [
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

/**
 * Translated column names.
 *
 * @return {Object} Column key => label.
 */
const columnLabels = () => ( {
	id: __( 'ID', 'tbsh-compliance-audit-logger' ),
	timestamp: __( 'Time', 'tbsh-compliance-audit-logger' ),
	actor: __( 'User', 'tbsh-compliance-audit-logger' ),
	title: __( 'Event', 'tbsh-compliance-audit-logger' ),
	category: __( 'Category', 'tbsh-compliance-audit-logger' ),
	severity: __( 'Severity', 'tbsh-compliance-audit-logger' ),
	site: __( 'Site ID', 'tbsh-compliance-audit-logger' ),
	method: __( 'Method', 'tbsh-compliance-audit-logger' ),
} );

/**
 * Column choice saved in this browser (a per-viewer convenience).
 *
 * @return {Object} Columns.
 */
const loadColumns = () => {
	try {
		const saved = JSON.parse( window.localStorage.getItem( COLUMNS_KEY ) || 'null' );
		return saved && typeof saved === 'object' ? { ...DEFAULT_COLUMNS, ...saved } : DEFAULT_COLUMNS;
	} catch ( e ) {
		return DEFAULT_COLUMNS;
	}
};

const EMPTY_FILTERS = {
	search: '',
	severity: '',
	category: '',
	actor: '',
	eventType: '',
	userId: 0,
	userLabel: '',
	dateStart: '',
	dateEnd: '',
};

export default function ActivityLogs() {
	// Filters handed over by another page (e.g. a dashboard card) apply on first render.
	const [ initial ] = useState( () => ( { ...EMPTY_FILTERS, ...( takePendingState( 'logs' ) || {} ) } ) );

	const [ logs, setLogs ] = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ totalCapped, setTotalCapped ] = useState( false );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ perPage, setPerPage ] = useState( 20 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const [ searchInput, setSearchInput ] = useState( initial.search );
	const [ filters, setFilters ] = useState( initial );

	const [ orderby, setOrderby ] = useState( 'id' );
	const [ order, setOrder ] = useState( 'DESC' );

	const [ selectedLog, setSelectedLog ] = useState( null );

	const [ showColumnControls, setShowColumnControls ] = useState( false );
	const [ visibleColumns, setVisibleColumns ] = useState( loadColumns );
	const columnsRef = useRef( null );

	useEffect( () => {
		let query = `/tbsh-compliance-audit-logger/v1/logs?page=${ page }&per_page=${ perPage }&orderby=${ orderby }&order=${ order }`;
		const add = ( key, value ) => {
			if ( value ) {
				query += `&${ key }=${ encodeURIComponent( value ) }`;
			}
		};
		add( 'search', filters.search );
		add( 'severity', filters.severity );
		add( 'category', filters.category );
		add( 'actor', filters.actor );
		add( 'event_type', filters.eventType );
		add( 'user_id', filters.userId );
		add( 'date_start', filters.dateStart );
		add( 'date_end', filters.dateEnd );

		setLoading( true );
		apiFetch( { path: query } )
			.then( ( data ) => {
				setLogs( data.logs || [] );
				setTotalItems( data.total || 0 );
				setTotalCapped( !! data.total_capped );
				setTotalPages( Math.max( 1, data.pages || 1 ) );
				setError( null );
			} )
			.catch( ( err ) => {
				setError( err.message || __( 'Failed to load activity logs.', 'tbsh-compliance-audit-logger' ) );
				setLogs( [] );
			} )
			.finally( () => setLoading( false ) );
	}, [ page, perPage, orderby, order, filters ] );

	useEffect( () => {
		try {
			window.localStorage.setItem( COLUMNS_KEY, JSON.stringify( visibleColumns ) );
		} catch ( e ) {
			// Storage unavailable; the choice lasts for this visit only.
		}
	}, [ visibleColumns ] );

	// Close the column menu on Escape or a click outside it.
	useEffect( () => {
		if ( ! showColumnControls ) {
			return undefined;
		}
		const onKey = ( e ) => {
			if ( 'Escape' === e.key ) {
				setShowColumnControls( false );
				const toggle = columnsRef.current && columnsRef.current.querySelector( 'button' );
				if ( toggle ) {
					toggle.focus();
				}
			}
		};
		const onClick = ( e ) => {
			if ( columnsRef.current && ! columnsRef.current.contains( e.target ) ) {
				setShowColumnControls( false );
			}
		};
		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'mousedown', onClick );
		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'mousedown', onClick );
		};
	}, [ showColumnControls ] );

	const updateFilter = ( key, value ) => {
		setFilters( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setPage( 1 );
	};

	const handleSearchSubmit = ( e ) => {
		e.preventDefault();
		updateFilter( 'search', searchInput.trim() );
	};

	const resetFilters = () => {
		setSearchInput( '' );
		setFilters( EMPTY_FILTERS );
		setPage( 1 );
	};

	const handleSort = ( column ) => {
		if ( orderby === column ) {
			setOrder( ( prev ) => ( prev === 'ASC' ? 'DESC' : 'ASC' ) );
		} else {
			setOrderby( column );
			setOrder( 'DESC' );
		}
		setPage( 1 );
	};

	// Active filters, each removable on its own.
	const active = [];
	if ( filters.search ) {
		/* translators: %s: search text */
		active.push( { key: 'search', label: sprintf( __( 'Search: %s', 'tbsh-compliance-audit-logger' ), filters.search ) } );
	}
	if ( filters.severity ) {
		/* translators: %s: severity */
		active.push( { key: 'severity', label: sprintf( __( 'Severity: %s', 'tbsh-compliance-audit-logger' ), severityLabel( filters.severity ) ) } );
	}
	if ( filters.category ) {
		/* translators: %s: category */
		active.push( { key: 'category', label: sprintf( __( 'Category: %s', 'tbsh-compliance-audit-logger' ), filters.category ) } );
	}
	if ( filters.actor ) {
		/* translators: %s: who made the change, e.g. AI agent */
		active.push( { key: 'actor', label: sprintf( __( 'Made by: %s', 'tbsh-compliance-audit-logger' ), actorLabels()[ filters.actor ] || filters.actor ) } );
	}
	if ( filters.eventType ) {
		/* translators: %s: event type slug */
		active.push( { key: 'eventType', label: sprintf( __( 'Event type: %s', 'tbsh-compliance-audit-logger' ), filters.eventType ) } );
	}
	if ( filters.userId ) {
		/* translators: %s: username or user ID */
		active.push( { key: 'userId', label: sprintf( __( 'User: %s', 'tbsh-compliance-audit-logger' ), filters.userLabel || `#${ filters.userId }` ) } );
	}
	if ( filters.dateStart || filters.dateEnd ) {
		active.push( {
			key: 'dates',
			/* translators: 1: start date, 2: end date */
			label: sprintf( __( 'Dates: %1$s – %2$s', 'tbsh-compliance-audit-logger' ), filters.dateStart || '…', filters.dateEnd || '…' ),
		} );
	}

	const removeFilter = ( key ) => {
		if ( 'dates' === key ) {
			setFilters( ( prev ) => ( { ...prev, dateStart: '', dateEnd: '' } ) );
		} else if ( 'userId' === key ) {
			setFilters( ( prev ) => ( { ...prev, userId: 0, userLabel: '' } ) );
		} else {
			if ( 'search' === key ) {
				setSearchInput( '' );
			}
			setFilters( ( prev ) => ( { ...prev, [ key ]: EMPTY_FILTERS[ key ] } ) );
		}
		setPage( 1 );
	};

	const labels = columnLabels();

	const sortHeader = ( column, label ) => {
		const isSorted = orderby === column;
		let ariaSort = 'none';
		if ( isSorted ) {
			ariaSort = 'ASC' === order ? 'ascending' : 'descending';
		}
		return (
			<th aria-sort={ ariaSort }>
				<button type="button" className="tbsh-link-button" style={ { textDecoration: 'none', fontWeight: 600, color: 'inherit' } } onClick={ () => handleSort( column ) }>
					{ label }
					{ isSorted && <span aria-hidden="true">{ 'ASC' === order ? ' ↑' : ' ↓' }</span> }
				</button>
			</th>
		);
	};

	const renderTable = () => {
		if ( loading ) {
			return <SkeletonLoader rows={ 8 } cols={ 5 } />;
		}
		if ( error ) {
			return <EmptyState title={ __( 'Could not load the activity log', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />;
		}
		if ( ! logs.length ) {
			return active.length ? (
				<EmptyState
					title={ __( 'No entries match these filters', 'tbsh-compliance-audit-logger' ) }
					description={ __( 'Try a wider date range or remove a filter.', 'tbsh-compliance-audit-logger' ) }
					icon="logs"
					actionLabel={ __( 'Clear all filters', 'tbsh-compliance-audit-logger' ) }
					onAction={ resetFilters }
				/>
			) : (
				<EmptyState
					title={ __( 'No activity recorded yet', 'tbsh-compliance-audit-logger' ) }
					description={ __( 'Entries appear here as people sign in, edit content and change settings.', 'tbsh-compliance-audit-logger' ) }
					icon="logs"
				/>
			);
		}
		return (
			<table className="tbsh-table tbsh-table-sticky-first">
				<caption className="tbsh-sr-only">{ __( 'Activity log entries', 'tbsh-compliance-audit-logger' ) }</caption>
				<thead>
					<tr>
						{ visibleColumns.id && sortHeader( 'id', labels.id ) }
						{ visibleColumns.timestamp && sortHeader( 'created_at', labels.timestamp ) }
						{ visibleColumns.actor && sortHeader( 'username', labels.actor ) }
						{ visibleColumns.title && <th>{ labels.title }</th> }
						{ visibleColumns.category && sortHeader( 'event_category', labels.category ) }
						{ visibleColumns.severity && sortHeader( 'severity', labels.severity ) }
						{ visibleColumns.site && <th>{ labels.site }</th> }
						{ visibleColumns.method && <th>{ labels.method }</th> }
						<th style={ { textAlign: 'end' } }>
							<span className="tbsh-sr-only">{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ logs.map( ( log ) => (
						<tr key={ log.id }>
							{ visibleColumns.id && <td>{ log.id }</td> }
							{ visibleColumns.timestamp && (
								<td>
									<SiteTime value={ log.created_at } />
								</td>
							) }
							{ visibleColumns.actor && (
								<td>
									<strong>{ log.username }</strong>
									<span style={ { display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)' } }>{ log.role }</span>
									{ log.metadata && 'agent' === log.metadata._actor && (
										<span className="tbsh-badge tbsh-badge-warning" style={ { marginTop: '4px', display: 'inline-block' } } title={ originText( log.metadata ) }>
											{ __( 'AI agent', 'tbsh-compliance-audit-logger' ) }
										</span>
									) }
								</td>
							) }
							{ visibleColumns.title && <td>{ log.event_title }</td> }
							{ visibleColumns.category && <td>{ log.event_category }</td> }
							{ visibleColumns.severity && (
								<td>
									<SeverityBadge severity={ log.severity } />
								</td>
							) }
							{ visibleColumns.site && <td>{ log.site_id }</td> }
							{ visibleColumns.method && (
								<td>
									<code>{ log.request_method || 'GET' }</code>
								</td>
							) }
							<td style={ { textAlign: 'end' } }>
								<button
									type="button"
									className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
									onClick={ () => setSelectedLog( log ) }
									aria-label={ sprintf(
										/* translators: 1: event title, 2: date and time */
										__( 'Details: %1$s, %2$s', 'tbsh-compliance-audit-logger' ),
										log.event_title,
										formatSiteTime( log.created_at )
									) }
								>
									{ __( 'Details', 'tbsh-compliance-audit-logger' ) }
								</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		);
	};

	return (
		<div className="tbsh-logs-page">
			<div className="tbsh-filters-bar">
				<form onSubmit={ handleSearchSubmit } className="tbsh-filter-field" style={ { flex: '1 1 260px' } } role="search">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-search">
						{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
					</label>
					<div style={ { display: 'flex', gap: '8px' } }>
						<input
							id="tbsh-logs-search"
							type="search"
							className="tbsh-filter-input"
							style={ { flexGrow: 1, minWidth: 0 } }
							placeholder={ __( 'Event, message or username', 'tbsh-compliance-audit-logger' ) }
							value={ searchInput }
							onChange={ ( e ) => setSearchInput( e.target.value ) }
						/>
						<button type="submit" className="tbsh-btn tbsh-btn-primary">
							<Icon name="search" />
							{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</form>

				<div className="tbsh-filter-field">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-severity">
						{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }
					</label>
					<select id="tbsh-logs-severity" className="tbsh-filter-input" value={ filters.severity } onChange={ ( e ) => updateFilter( 'severity', e.target.value ) }>
						<SeverityOptions allLabel={ __( 'All severities', 'tbsh-compliance-audit-logger' ) } combined />
					</select>
				</div>

				<div className="tbsh-filter-field">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-category">
						{ __( 'Category', 'tbsh-compliance-audit-logger' ) }
					</label>
					<select id="tbsh-logs-category" className="tbsh-filter-input" value={ filters.category } onChange={ ( e ) => updateFilter( 'category', e.target.value ) }>
						<option value="">{ __( 'All categories', 'tbsh-compliance-audit-logger' ) }</option>
						{ CATEGORIES.map( ( cat ) => (
							<option key={ cat } value={ cat }>
								{ cat }
							</option>
						) ) }
					</select>
				</div>

				<div className="tbsh-filter-field">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-actor">
						{ __( 'Made by', 'tbsh-compliance-audit-logger' ) }
					</label>
					<select id="tbsh-logs-actor" className="tbsh-filter-input" value={ filters.actor } onChange={ ( e ) => updateFilter( 'actor', e.target.value ) }>
						<option value="">{ __( 'Anyone', 'tbsh-compliance-audit-logger' ) }</option>
						{ Object.entries( actorLabels() ).map( ( [ value, label ] ) => (
							<option key={ value } value={ value }>
								{ label }
							</option>
						) ) }
					</select>
				</div>

				<div className="tbsh-filter-field">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-from">
						{ __( 'From', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input id="tbsh-logs-from" type="date" className="tbsh-filter-input" value={ filters.dateStart } max={ filters.dateEnd || undefined } onChange={ ( e ) => updateFilter( 'dateStart', e.target.value ) } />
				</div>

				<div className="tbsh-filter-field">
					<label className="tbsh-filter-label" htmlFor="tbsh-logs-to">
						{ __( 'To', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input id="tbsh-logs-to" type="date" className="tbsh-filter-input" value={ filters.dateEnd } min={ filters.dateStart || undefined } onChange={ ( e ) => updateFilter( 'dateEnd', e.target.value ) } />
				</div>

				<div style={ { position: 'relative' } } ref={ columnsRef }>
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary"
						aria-expanded={ showColumnControls }
						aria-controls="tbsh-logs-columns"
						onClick={ () => setShowColumnControls( ! showColumnControls ) }
					>
						<Icon name="filter" />
						{ __( 'Columns', 'tbsh-compliance-audit-logger' ) }
					</button>

					{ showColumnControls && (
						<fieldset id="tbsh-logs-columns" className="tbsh-card tbsh-popover" style={ { margin: '8px 0 0' } }>
							<legend className="tbsh-section-title" style={ { fontSize: '13px', padding: 0, marginBottom: '8px' } }>
								{ __( 'Show columns', 'tbsh-compliance-audit-logger' ) }
							</legend>
							{ Object.keys( DEFAULT_COLUMNS ).map( ( col ) => (
								<label key={ col } htmlFor={ `tbsh-logs-col-${ col }` } style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px', fontSize: '13px', cursor: 'pointer' } }>
									<input
										id={ `tbsh-logs-col-${ col }` }
										type="checkbox"
										checked={ !! visibleColumns[ col ] }
										onChange={ () => setVisibleColumns( ( prev ) => ( { ...prev, [ col ]: ! prev[ col ] } ) ) }
									/>
									{ labels[ col ] }
								</label>
							) ) }
						</fieldset>
					) }
				</div>
			</div>

			{ active.length > 0 && (
				<div className="tbsh-filter-summary">
					<span>
						{ sprintf(
							/* translators: %d: number of active filters */
							_n( '%d filter active:', '%d filters active:', active.length, 'tbsh-compliance-audit-logger' ),
							active.length
						) }
					</span>
					{ active.map( ( f ) => (
						<span key={ f.key } className="tbsh-badge tbsh-badge-info" style={ { textTransform: 'none', gap: '6px', alignItems: 'center' } }>
							{ f.label }
							<button
								type="button"
								className="tbsh-link-button"
								style={ { textDecoration: 'none', lineHeight: 1 } }
								onClick={ () => removeFilter( f.key ) }
								aria-label={ sprintf(
									/* translators: %s: filter description */
									__( 'Remove filter %s', 'tbsh-compliance-audit-logger' ),
									f.label
								) }
							>
								×
							</button>
						</span>
					) ) }
					<button type="button" className="tbsh-link-button" onClick={ resetFilters }>
						{ __( 'Clear all', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			) }

			<div className="tbsh-table-container" role="region" aria-label={ __( 'Activity log entries', 'tbsh-compliance-audit-logger' ) } tabIndex="0">
				{ renderTable() }

				<div className="tbsh-pagination" style={ { flexWrap: 'wrap', gap: '12px' } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' } }>
						<label htmlFor="tbsh-logs-per-page" style={ { fontSize: '13px', color: 'var(--tbsh-text-secondary)' } }>
							{ __( 'Rows per page', 'tbsh-compliance-audit-logger' ) }
						</label>
						<select
							id="tbsh-logs-per-page"
							className="tbsh-filter-input"
							value={ perPage }
							onChange={ ( e ) => {
								setPerPage( parseInt( e.target.value, 10 ) );
								setPage( 1 );
							} }
							style={ { padding: '4px 24px 4px 8px', fontSize: '13px', minWidth: '65px' } }
						>
							<option value="10">10</option>
							<option value="20">20</option>
							<option value="50">50</option>
							<option value="100">100</option>
						</select>
						<span style={ { fontSize: '13px', color: 'var(--tbsh-text-secondary)', marginInlineStart: '12px' } } aria-live="polite">
							{ totalCapped
								? sprintf(
									/* translators: %s: number of entries, e.g. 10,000 */
									__( 'More than %s entries. Narrow the filters, or use Export for all of them.', 'tbsh-compliance-audit-logger' ),
									totalItems.toLocaleString()
								)
								: sprintf(
									/* translators: %s: number of entries */
									_n( '%s entry', '%s entries', totalItems, 'tbsh-compliance-audit-logger' ),
									totalItems.toLocaleString()
								) }
						</span>
					</div>

					<nav style={ { display: 'flex', gap: '8px', alignItems: 'center' } } aria-label={ __( 'Pagination', 'tbsh-compliance-audit-logger' ) }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }>
							{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
						</button>
						<span style={ { padding: '0 8px', fontSize: '13px', fontWeight: '500' } }>
							{ sprintf(
								/* translators: 1: current page, 2: total pages */
								__( 'Page %1$d of %2$d', 'tbsh-compliance-audit-logger' ),
								page,
								totalPages
							) }
						</span>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ page >= totalPages } onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }>
							{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
						</button>
					</nav>
				</div>
			</div>

			<LogDetailModal log={ selectedLog } onClose={ () => setSelectedLog( null ) } />
		</div>
	);
}
