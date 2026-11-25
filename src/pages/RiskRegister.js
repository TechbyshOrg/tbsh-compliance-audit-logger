import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { get, post, put, del, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';

const STATUSES = [ 'active', 'mitigated', 'accepted', 'closed' ];
const CATEGORIES = [ 'Operational', 'Technical', 'Compliance', 'Vendor', 'Security', 'Data Protection' ];

const statusLabel = ( s ) =>
	( {
		active: __( 'Active', 'tbsh-compliance-audit-logger' ),
		mitigated: __( 'Mitigated', 'tbsh-compliance-audit-logger' ),
		accepted: __( 'Accepted', 'tbsh-compliance-audit-logger' ),
		closed: __( 'Closed', 'tbsh-compliance-audit-logger' ),
	}[ s ] || s );

const categoryLabel = ( c ) =>
	( {
		Operational: __( 'Operational', 'tbsh-compliance-audit-logger' ),
		Technical: __( 'Technical', 'tbsh-compliance-audit-logger' ),
		Compliance: __( 'Compliance', 'tbsh-compliance-audit-logger' ),
		Vendor: __( 'Vendor', 'tbsh-compliance-audit-logger' ),
		Security: __( 'Security', 'tbsh-compliance-audit-logger' ),
		'Data Protection': __( 'Data Protection', 'tbsh-compliance-audit-logger' ),
	}[ c ] || c );

const likelihoodLabel = ( n ) =>
	( {
		1: __( 'Rare', 'tbsh-compliance-audit-logger' ),
		2: __( 'Unlikely', 'tbsh-compliance-audit-logger' ),
		3: __( 'Possible', 'tbsh-compliance-audit-logger' ),
		4: __( 'Likely', 'tbsh-compliance-audit-logger' ),
		5: __( 'Almost certain', 'tbsh-compliance-audit-logger' ),
	}[ n ] || String( n ) );

const impactLabel = ( n ) =>
	( {
		1: __( 'Negligible', 'tbsh-compliance-audit-logger' ),
		2: __( 'Minor', 'tbsh-compliance-audit-logger' ),
		3: __( 'Moderate', 'tbsh-compliance-audit-logger' ),
		4: __( 'Major', 'tbsh-compliance-audit-logger' ),
		5: __( 'Catastrophic', 'tbsh-compliance-audit-logger' ),
	}[ n ] || String( n ) );

// Mirrors RiskRegister::band() on the server.
const bandOf = ( score ) => {
	if ( score >= 16 ) {
		return 'critical';
	}
	if ( score >= 10 ) {
		return 'high';
	}
	if ( score >= 5 ) {
		return 'medium';
	}
	return 'low';
};

const bandLabel = ( b ) =>
	( {
		low: __( 'Low', 'tbsh-compliance-audit-logger' ),
		medium: __( 'Medium', 'tbsh-compliance-audit-logger' ),
		high: __( 'High', 'tbsh-compliance-audit-logger' ),
		critical: __( 'Critical', 'tbsh-compliance-audit-logger' ),
	}[ b ] || b );

const bandBadge = ( b ) =>
	( { low: 'success', medium: 'info', high: 'warning', critical: 'danger' }[ b ] || 'info' );

const statusBadge = ( s ) =>
	( { active: 'warning', mitigated: 'success', accepted: 'info', closed: 'info' }[ s ] || 'info' );

const EMPTY_FORM = {
	title: '',
	description: '',
	category: 'Security',
	likelihood: 3,
	impact: 3,
	mitigation_plan: '',
	review_date: '',
	status: 'active',
	compliance_mappings: '',
};

export default function RiskRegister() {
	const canManage = can( 'manage_risks' );

	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ reloadKey, setReloadKey ] = useState( 0 );

	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ cell, setCell ] = useState( null ); // { likelihood, impact } or null.

	const [ editing, setEditing ] = useState( null ); // null = closed, {} = create, risk = edit.
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	const [ formError, setFormError ] = useState( '' );

	const [ toDelete, setToDelete ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );

	const reload = useCallback( () => setReloadKey( ( k ) => k + 1 ), [] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		get( '/risks', {
			page,
			per_page: 20,
			status,
			category,
			search,
			likelihood: cell ? cell.likelihood : '',
			impact: cell ? cell.impact : '',
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setData( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( ( err && err.message ) || __( 'Failed to load the risk register.', 'tbsh-compliance-audit-logger' ) );
				setLoading( false );
			} );
		return () => {
			cancelled = true;
		};
	}, [ page, status, category, search, cell, reloadKey ] );

	const stats = ( data && data.stats ) || null;
	const items = ( data && data.items ) || [];
	const totalPages = ( data && data.total_pages ) || 1;
	const total = ( data && data.total ) || 0;

	const openCreate = () => {
		setForm( EMPTY_FORM );
		setFormError( '' );
		setEditing( {} );
	};

	const openEdit = ( risk ) => {
		setForm( {
			title: risk.title || '',
			description: risk.description || '',
			category: risk.category || 'Security',
			likelihood: risk.likelihood || 3,
			impact: risk.impact || 3,
			mitigation_plan: risk.mitigation_plan || '',
			review_date: risk.review_date || '',
			status: risk.status || 'active',
			compliance_mappings: risk.compliance_mappings || '',
		} );
		setFormError( '' );
		setEditing( risk );
	};

	const closeForm = useCallback( () => {
		if ( ! saving ) {
			setEditing( null );
		}
	}, [ saving ] );

	const closeDelete = useCallback( () => {
		if ( ! deleting ) {
			setToDelete( null );
		}
	}, [ deleting ] );

	const setField = ( key, value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );

	const submitForm = ( e ) => {
		e.preventDefault();
		if ( ! form.title.trim() ) {
			setFormError( __( 'A risk title is required.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		setSaving( true );
		setFormError( '' );
		const payload = {
			...form,
			likelihood: parseInt( form.likelihood, 10 ),
			impact: parseInt( form.impact, 10 ),
			review_date: form.review_date || null,
		};
		const isEdit = editing && editing.id;
		const req = isEdit ? put( `/risks/${ editing.id }`, payload ) : post( '/risks', payload );
		req.then( () => {
			setSaving( false );
			setEditing( null );
			setNotice( {
				type: 'success',
				message: isEdit ? __( 'Risk updated.', 'tbsh-compliance-audit-logger' ) : __( 'Risk added to the register.', 'tbsh-compliance-audit-logger' ),
			} );
			reload();
		} ).catch( ( err ) => {
			setSaving( false );
			setFormError( ( err && err.message ) || __( 'Could not save the risk.', 'tbsh-compliance-audit-logger' ) );
		} );
	};

	const confirmDelete = () => {
		if ( ! toDelete ) {
			return;
		}
		setDeleting( true );
		del( `/risks/${ toDelete.id }` )
			.then( () => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'success', message: __( 'Risk deleted.', 'tbsh-compliance-audit-logger' ) } );
				if ( items.length === 1 && page > 1 ) {
					setPage( page - 1 );
				} else {
					reload();
				}
			} )
			.catch( ( err ) => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Could not delete the risk.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const toggleCell = ( l, i ) => {
		setPage( 1 );
		if ( cell && cell.likelihood === l && cell.impact === i ) {
			setCell( null );
		} else {
			setCell( { likelihood: l, impact: i } );
		}
	};

	const previewScore = parseInt( form.likelihood, 10 ) * parseInt( form.impact, 10 );
	const previewBand = bandOf( previewScore );

	const renderHeatmap = () => {
		const matrix = ( stats && stats.matrix ) || {};
		const rows = [];
		for ( let l = 5; l >= 1; l-- ) {
			rows.push(
				<div key={ `y${ l }` } className="tbsh-heatmap-label-y" title={ likelihoodLabel( l ) }>
					{ l }
				</div>
			);
			for ( let i = 1; i <= 5; i++ ) {
				const count = ( matrix[ l ] && matrix[ l ][ i ] ) || 0;
				const selected = cell && cell.likelihood === l && cell.impact === i;
				const band = bandOf( l * i );
				rows.push(
					<button
						key={ `c${ l }-${ i }` }
						type="button"
						className={ `tbsh-heatmap-cell ${ band }${ selected ? ' is-selected' : '' }` }
						aria-pressed={ !! selected }
						aria-label={ sprintf(
							/* translators: 1: number of risks, 2: likelihood label, 3: impact label, 4: band label */
							__( '%1$d active risks — likelihood %2$s, impact %3$s (%4$s)', 'tbsh-compliance-audit-logger' ),
							count,
							likelihoodLabel( l ),
							impactLabel( i ),
							bandLabel( band )
						) }
						onClick={ () => toggleCell( l, i ) }
					>
						{ count }
					</button>
				);
			}
		}
		rows.push( <div key="corner" aria-hidden="true" /> );
		for ( let i = 1; i <= 5; i++ ) {
			rows.push(
				<div key={ `x${ i }` } className="tbsh-heatmap-label-x" title={ impactLabel( i ) }>
					{ i }
				</div>
			);
		}
		return rows;
	};

	const renderPagination = () =>
		totalPages > 1 && (
			<div className="tbsh-pagination">
				<span className="tbsh-muted">
					{ sprintf(
						/* translators: %d: number of risks */
						_n( '%d risk', '%d risks', total, 'tbsh-compliance-audit-logger' ),
						total
					) }
				</span>
				<div className="tbsh-row">
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
					>
						{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
					</button>
					<span className="tbsh-muted">
						{ sprintf(
							/* translators: 1: current page, 2: total pages */
							__( 'Page %1$d of %2$d', 'tbsh-compliance-audit-logger' ),
							page,
							totalPages
						) }
					</span>
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
						disabled={ page >= totalPages }
						onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
					>
						{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);

	const hasFilters = !! ( status || category || search || cell );

	const renderRisks = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 5 } cols={ 6 } />
			);
		}
		if ( error ) {
			return (
				<div style={ { padding: '0 0 20px', textAlign: 'center' } }>
					<EmptyState title={ __( 'Could not load risks', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ reload }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( items.length === 0 ) {
			return (
				<EmptyState
					title={ hasFilters ? __( 'No matching risks', 'tbsh-compliance-audit-logger' ) : __( 'No risks registered yet', 'tbsh-compliance-audit-logger' ) }
					description={
						hasFilters
							? __( 'No risks match the current filters.', 'tbsh-compliance-audit-logger' )
							: __( 'Add your first risk to start building an auditable risk register.', 'tbsh-compliance-audit-logger' )
					}
					icon="risk"
				/>
			);
		}
		return (
			<>
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Risk', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Category', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Score', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Owner', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Review date', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
							{ canManage && <th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th> }
						</tr>
					</thead>
					<tbody>
						{ items.map( ( r ) => (
							<tr key={ r.id }>
								<td>
									<strong>{ r.title }</strong>
									{ r.compliance_mappings && <div className="tbsh-muted">{ r.compliance_mappings }</div> }
								</td>
								<td>{ categoryLabel( r.category ) }</td>
								<td>
									<span className="tbsh-row">
										<strong>{ r.score }</strong>
										<span className={ `tbsh-badge tbsh-badge-${ bandBadge( r.band ) }` }>{ bandLabel( r.band ) }</span>
									</span>
									<div className="tbsh-muted">
										{ sprintf(
											/* translators: 1: likelihood, 2: impact */
											__( 'L%1$d × I%2$d', 'tbsh-compliance-audit-logger' ),
											r.likelihood,
											r.impact
										) }
									</div>
								</td>
								<td>{ r.owner_name || '—' }</td>
								<td>
									{ r.review_date || '—' }
									{ r.review_overdue && (
										<>
											{ ' ' }
											<span className="tbsh-badge tbsh-badge-danger">{ __( 'Overdue', 'tbsh-compliance-audit-logger' ) }</span>
										</>
									) }
								</td>
								<td>
									<span className={ `tbsh-badge tbsh-badge-${ statusBadge( r.status ) }` }>{ statusLabel( r.status ) }</span>
								</td>
								{ canManage && (
									<td style={ { textAlign: 'end', whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
											onClick={ () => openEdit( r ) }
											aria-label={ sprintf(
												/* translators: %s: risk title */
												__( 'Edit risk %s', 'tbsh-compliance-audit-logger' ),
												r.title
											) }
										>
											<Icon name="edit" />
										</button>{ ' ' }
										<button
											type="button"
											className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
											onClick={ () => setToDelete( r ) }
											aria-label={ sprintf(
												/* translators: %s: risk title */
												__( 'Delete risk %s', 'tbsh-compliance-audit-logger' ),
												r.title
											) }
										>
											<Icon name="trash" />
										</button>
									</td>
								) }
							</tr>
						) ) }
					</tbody>
				</table>
				{ renderPagination() }
			</>
		);
	};

	return (
		<div className="tbsh-risk-page">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-row-between" style={ { marginBottom: '20px' } }>
				<div>
					<h3 className="tbsh-section-title" style={ { marginBottom: '4px' } }>
						{ __( 'Risk Register', 'tbsh-compliance-audit-logger' ) }
					</h3>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'Assess risks on a 5×5 likelihood × impact matrix, assign mitigation and track review dates (ISO 27001 6.1.2, NIS2 Art. 21).', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
				{ canManage && (
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ openCreate }>
						<Icon name="plus" />
						{ __( 'Add risk', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			{ stats && (
				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Active risks', 'tbsh-compliance-audit-logger' ) }
						value={ ( stats.by_status && stats.by_status.active ) || 0 }
						icon="risk"
						desc={ sprintf(
							/* translators: %d: total number of risks in the register */
							__( '%d in the register overall', 'tbsh-compliance-audit-logger' ),
							stats.total || 0
						) }
					/>
					<MetricCard
						title={ __( 'Critical', 'tbsh-compliance-audit-logger' ) }
						value={ ( stats.bands && stats.bands.critical ) || 0 }
						icon="alert"
						status={ stats.bands && stats.bands.critical > 0 ? 'danger' : '' }
						desc={ __( 'Active risks scoring 16–25', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'High', 'tbsh-compliance-audit-logger' ) }
						value={ ( stats.bands && stats.bands.high ) || 0 }
						icon="shield"
						status={ stats.bands && stats.bands.high > 0 ? 'warning' : '' }
						desc={ __( 'Active risks scoring 10–15', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'Overdue reviews', 'tbsh-compliance-audit-logger' ) }
						value={ stats.overdue_reviews || 0 }
						icon="calendar"
						status={ stats.overdue_reviews > 0 ? 'warning' : 'success' }
						desc={ __( 'Active risks past their review date', 'tbsh-compliance-audit-logger' ) }
					/>
				</div>
			) }

			{ stats && (
				<div className="tbsh-card" style={ { marginBottom: '20px' } }>
					<div className="tbsh-row-between">
						<h4 className="tbsh-section-title">{ __( 'Heat map (active risks)', 'tbsh-compliance-audit-logger' ) }</h4>
						{ cell && (
							<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => toggleCell( cell.likelihood, cell.impact ) }>
								<Icon name="close" />
								{ __( 'Clear cell filter', 'tbsh-compliance-audit-logger' ) }
							</button>
						) }
					</div>
					<p className="tbsh-muted" style={ { marginTop: 0 } }>
						{ __( 'Rows: likelihood (5 = almost certain, top). Columns: impact (1 = negligible, left). Select a cell to filter the list below; select it again to clear.', 'tbsh-compliance-audit-logger' ) }
					</p>
					<div className="tbsh-heatmap-grid" role="group" aria-label={ __( 'Risk heat map', 'tbsh-compliance-audit-logger' ) }>
						{ renderHeatmap() }
					</div>
				</div>
			) }

			<div className="tbsh-filters-bar">
				<form
					onSubmit={ ( e ) => {
						e.preventDefault();
						setPage( 1 );
						setSearch( searchInput.trim() );
					} }
					className="tbsh-row"
					style={ { flexGrow: 1, minWidth: '240px' } }
				>
					<label htmlFor="tbsh-risk-search" className="screen-reader-text">
						{ __( 'Search risks', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input
						id="tbsh-risk-search"
						type="search"
						className="tbsh-filter-input"
						style={ { flexGrow: 1 } }
						placeholder={ __( 'Search title or description…', 'tbsh-compliance-audit-logger' ) }
						value={ searchInput }
						onChange={ ( e ) => setSearchInput( e.target.value ) }
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-secondary">
						<Icon name="search" />
						{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
					</button>
				</form>
				<label htmlFor="tbsh-risk-status" className="screen-reader-text">
					{ __( 'Filter by status', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-risk-status"
					className="tbsh-filter-input"
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All statuses', 'tbsh-compliance-audit-logger' ) }</option>
					{ STATUSES.map( ( s ) => (
						<option key={ s } value={ s }>
							{ statusLabel( s ) }
						</option>
					) ) }
				</select>
				<label htmlFor="tbsh-risk-category" className="screen-reader-text">
					{ __( 'Filter by category', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-risk-category"
					className="tbsh-filter-input"
					value={ category }
					onChange={ ( e ) => {
						setCategory( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All categories', 'tbsh-compliance-audit-logger' ) }</option>
					{ CATEGORIES.map( ( c ) => (
						<option key={ c } value={ c }>
							{ categoryLabel( c ) }
						</option>
					) ) }
				</select>
				{ hasFilters && (
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary"
						onClick={ () => {
							setStatus( '' );
							setCategory( '' );
							setSearch( '' );
							setSearchInput( '' );
							setCell( null );
							setPage( 1 );
						} }
					>
						{ __( 'Reset filters', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			{ cell && (
				<p className="tbsh-muted">
					{ sprintf(
						/* translators: 1: likelihood value, 2: likelihood label, 3: impact value, 4: impact label */
						__( 'Showing risks with likelihood %1$d (%2$s) and impact %3$d (%4$s).', 'tbsh-compliance-audit-logger' ),
						cell.likelihood,
						likelihoodLabel( cell.likelihood ),
						cell.impact,
						impactLabel( cell.impact )
					) }
				</p>
			) }

			<div className="tbsh-table-container">
				{ renderRisks() }
			</div>

			<Modal
				title={ editing && editing.id ? __( 'Edit risk', 'tbsh-compliance-audit-logger' ) : __( 'Add risk', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! editing }
				onClose={ closeForm }
			>
				<form onSubmit={ submitForm }>
					{ formError && <Notice type="error" message={ formError } /> }
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-risk-title">
							{ __( 'Title', 'tbsh-compliance-audit-logger' ) }
						</label>
						<input
							id="tbsh-risk-title"
							type="text"
							className="tbsh-form-control"
							value={ form.title }
							onChange={ ( e ) => setField( 'title', e.target.value ) }
							required
						/>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-risk-description">
							{ __( 'Description', 'tbsh-compliance-audit-logger' ) }
						</label>
						<textarea
							id="tbsh-risk-description"
							className="tbsh-form-control"
							rows={ 3 }
							value={ form.description }
							onChange={ ( e ) => setField( 'description', e.target.value ) }
						/>
					</div>
					<div className="tbsh-grid-2" style={ { marginBottom: 0 } }>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-category-field">
								{ __( 'Category', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-risk-category-field"
								className="tbsh-form-control"
								value={ form.category }
								onChange={ ( e ) => setField( 'category', e.target.value ) }
							>
								{ CATEGORIES.map( ( c ) => (
									<option key={ c } value={ c }>
										{ categoryLabel( c ) }
									</option>
								) ) }
								{ form.category && ! CATEGORIES.includes( form.category ) && (
									<option value={ form.category }>{ form.category }</option>
								) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-status-field">
								{ __( 'Status', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-risk-status-field"
								className="tbsh-form-control"
								value={ form.status }
								onChange={ ( e ) => setField( 'status', e.target.value ) }
							>
								{ STATUSES.map( ( s ) => (
									<option key={ s } value={ s }>
										{ statusLabel( s ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-likelihood">
								{ __( 'Likelihood', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-risk-likelihood"
								className="tbsh-form-control"
								value={ form.likelihood }
								onChange={ ( e ) => setField( 'likelihood', parseInt( e.target.value, 10 ) ) }
							>
								{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
									<option key={ n } value={ n }>
										{ `${ n } — ${ likelihoodLabel( n ) }` }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-impact">
								{ __( 'Impact', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-risk-impact"
								className="tbsh-form-control"
								value={ form.impact }
								onChange={ ( e ) => setField( 'impact', parseInt( e.target.value, 10 ) ) }
							>
								{ [ 1, 2, 3, 4, 5 ].map( ( n ) => (
									<option key={ n } value={ n }>
										{ `${ n } — ${ impactLabel( n ) }` }
									</option>
								) ) }
							</select>
						</div>
					</div>
					<div className="tbsh-form-group tbsh-row" aria-live="polite">
						<span className="tbsh-muted">{ __( 'Risk score:', 'tbsh-compliance-audit-logger' ) }</span>
						<strong>{ previewScore }</strong>
						<span className={ `tbsh-badge tbsh-badge-${ bandBadge( previewBand ) }` }>{ bandLabel( previewBand ) }</span>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-risk-mitigation">
							{ __( 'Mitigation plan', 'tbsh-compliance-audit-logger' ) }
						</label>
						<textarea
							id="tbsh-risk-mitigation"
							className="tbsh-form-control"
							rows={ 3 }
							value={ form.mitigation_plan }
							onChange={ ( e ) => setField( 'mitigation_plan', e.target.value ) }
						/>
					</div>
					<div className="tbsh-grid-2" style={ { marginBottom: 0 } }>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-review">
								{ __( 'Next review date', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-risk-review"
								type="date"
								className="tbsh-form-control"
								value={ form.review_date || '' }
								onChange={ ( e ) => setField( 'review_date', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-risk-mappings">
								{ __( 'Compliance mappings', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-risk-mappings"
								type="text"
								className="tbsh-form-control"
								placeholder={ __( 'e.g. ISO 27001 A.8.8, NIS2 Art. 21', 'tbsh-compliance-audit-logger' ) }
								value={ form.compliance_mappings }
								onChange={ ( e ) => setField( 'compliance_mappings', e.target.value ) }
							/>
						</div>
					</div>
					<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeForm } disabled={ saving }>
							{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
						</button>
						<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ saving }>
							{ saving ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save risk', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</form>
			</Modal>

			<Modal title={ __( 'Delete risk', 'tbsh-compliance-audit-logger' ) } isOpen={ !! toDelete } onClose={ closeDelete }>
				{ toDelete && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: risk title */
								__( 'Delete the risk "%s"? This removes it from the register; the deletion itself is recorded in the audit log.', 'tbsh-compliance-audit-logger' ),
								toDelete.title
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeDelete } disabled={ deleting }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ confirmDelete } disabled={ deleting }>
								{ deleting ? __( 'Deleting…', 'tbsh-compliance-audit-logger' ) : __( 'Delete', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}

