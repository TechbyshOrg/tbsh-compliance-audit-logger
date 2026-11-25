import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { get, post, put, del, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import MetricCard from '../components/MetricCard';
import Icon from '../components/Icon';

const LEVELS = [ 'low', 'medium', 'high' ];
const ACCESS = [ 'none', 'read', 'write' ];
const STATUSES = [ 'active', 'inactive' ];

const levelLabel = ( l ) =>
	( {
		low: __( 'Low', 'tbsh-compliance-audit-logger' ),
		medium: __( 'Medium', 'tbsh-compliance-audit-logger' ),
		high: __( 'High', 'tbsh-compliance-audit-logger' ),
	}[ l ] || l );

const levelBadge = ( l ) => ( { low: 'success', medium: 'warning', high: 'danger' }[ l ] || 'info' );

const accessLabel = ( a ) =>
	( {
		none: __( 'No data access', 'tbsh-compliance-audit-logger' ),
		read: __( 'Read access', 'tbsh-compliance-audit-logger' ),
		write: __( 'Read/write access', 'tbsh-compliance-audit-logger' ),
	}[ a ] || a );

const statusLabel = ( s ) =>
	( {
		active: __( 'Active', 'tbsh-compliance-audit-logger' ),
		inactive: __( 'Inactive', 'tbsh-compliance-audit-logger' ),
	}[ s ] || s );

const EMPTY_FORM = {
	name: '',
	service_type: '',
	criticality: 'medium',
	risk_rating: 'medium',
	data_access_level: 'none',
	contact_info: '',
	contract_renewal_date: '',
	review_date: '',
	status: 'active',
	linked_slug: '',
};

function Pagination( { page, totalPages, total, setPage } ) {
	if ( totalPages <= 1 ) {
		return null;
	}
	return (
		<div className="tbsh-pagination">
			<span className="tbsh-muted">
				{ sprintf(
					/* translators: %d: number of vendors */
					_n( '%d vendor', '%d vendors', total, 'tbsh-compliance-audit-logger' ),
					total
				) }
			</span>
			<div className="tbsh-row">
				<button
					type="button"
					className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
					disabled={ page <= 1 }
					onClick={ () => setPage( Math.max( 1, page - 1 ) ) }
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
					onClick={ () => setPage( Math.min( totalPages, page + 1 ) ) }
				>
					{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>
		</div>
	);
}

export default function VendorGovernance() {
	const canManage = can( 'manage_vendors' );

	const [ tab, setTab ] = useState( 'register' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );

	// Vendor register.
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ reloadKey, setReloadKey ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ criticality, setCriticality ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	// Supply chain.
	const [ chain, setChain ] = useState( null );
	const [ chainLoading, setChainLoading ] = useState( true );
	const [ chainError, setChainError ] = useState( '' );

	// Modals.
	const [ editing, setEditing ] = useState( null );
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
		get( '/vendors', { page, per_page: 20, criticality, status, search } )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setData( res );
					setLoading( false );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( ( err && err.message ) || __( 'Failed to load the vendor register.', 'tbsh-compliance-audit-logger' ) );
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ page, criticality, status, search, reloadKey ] );

	const loadChain = useCallback( ( refresh = false ) => {
		setChainLoading( true );
		setChainError( '' );
		return get( '/vendors/supply-chain', { refresh: refresh ? 1 : '' } )
			.then( ( res ) => {
				setChain( res );
				setChainLoading( false );
				if ( refresh ) {
					setNotice( { type: 'success', message: __( 'Supply chain inventory refreshed.', 'tbsh-compliance-audit-logger' ) } );
				}
			} )
			.catch( ( err ) => {
				setChainError( ( err && err.message ) || __( 'Failed to load the supply chain inventory.', 'tbsh-compliance-audit-logger' ) );
				setChainLoading( false );
			} );
	}, [] );

	// The inventory also feeds the "linked component" select, so load it up front.
	useEffect( () => {
		loadChain( false );
	}, [ loadChain ] );

	const stats = ( data && data.stats ) || null;
	const items = ( data && data.items ) || [];
	const totalPages = ( data && data.total_pages ) || 1;
	const total = ( data && data.total ) || 0;

	const components = chain ? [ ...( chain.plugins || [] ), ...( chain.themes || [] ) ] : [];
	const componentName = ( slug ) => {
		const c = components.find( ( p ) => p.slug === slug );
		return c ? c.name : slug;
	};

	const setField = ( key, value ) => setForm( ( f ) => ( { ...f, [ key ]: value } ) );

	const openCreate = ( prefill = {} ) => {
		setForm( { ...EMPTY_FORM, ...prefill } );
		setFormError( '' );
		setEditing( {} );
	};

	const openEdit = ( v ) => {
		setForm( {
			name: v.name || '',
			service_type: v.service_type || '',
			criticality: v.criticality || 'medium',
			risk_rating: v.risk_rating || 'medium',
			data_access_level: v.data_access_level || 'none',
			contact_info: v.contact_info || '',
			contract_renewal_date: v.contract_renewal_date || '',
			review_date: v.review_date || '',
			status: v.status || 'active',
			linked_slug: v.linked_slug || '',
		} );
		setFormError( '' );
		setEditing( v );
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

	const afterVendorChange = () => {
		reload();
		// Vendor changes flush the server-side inventory cache; re-read the mapping quietly.
		loadChain( false );
	};

	const submitForm = ( e ) => {
		e.preventDefault();
		if ( ! form.name.trim() ) {
			setFormError( __( 'A vendor name is required.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		setSaving( true );
		setFormError( '' );
		const payload = {
			...form,
			contract_renewal_date: form.contract_renewal_date || null,
			review_date: form.review_date || null,
		};
		const isEdit = editing && editing.id;
		const req = isEdit ? put( `/vendors/${ editing.id }`, payload ) : post( '/vendors', payload );
		req.then( () => {
			setSaving( false );
			setEditing( null );
			setNotice( {
				type: 'success',
				message: isEdit ? __( 'Vendor updated.', 'tbsh-compliance-audit-logger' ) : __( 'Vendor added to the register.', 'tbsh-compliance-audit-logger' ),
			} );
			afterVendorChange();
		} ).catch( ( err ) => {
			setSaving( false );
			setFormError( ( err && err.message ) || __( 'Could not save the vendor.', 'tbsh-compliance-audit-logger' ) );
		} );
	};

	const confirmDelete = () => {
		if ( ! toDelete ) {
			return;
		}
		setDeleting( true );
		del( `/vendors/${ toDelete.id }` )
			.then( () => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'success', message: __( 'Vendor removed.', 'tbsh-compliance-audit-logger' ) } );
				if ( items.length === 1 && page > 1 ) {
					setPage( page - 1 );
					loadChain( false );
				} else {
					afterVendorChange();
				}
			} )
			.catch( ( err ) => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Could not remove the vendor.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const registerComponent = ( c ) => {
		openCreate( {
			name: c.name,
			service_type: 'theme' === c.type ? __( 'Theme Developer', 'tbsh-compliance-audit-logger' ) : __( 'Plugin Developer', 'tbsh-compliance-audit-logger' ),
			linked_slug: c.slug,
		} );
	};

	const hasFilters = !! ( criticality || status || search );

	const riskCell = ( c ) => (
		<>
			<span className={ `tbsh-badge tbsh-badge-${ levelBadge( c.risk ) }` }>{ levelLabel( c.risk ) }</span>
			{ c.risk_reasons && c.risk_reasons.length > 0 && (
				<ul className="tbsh-muted" style={ { margin: '6px 0 0', paddingInlineStart: '18px' } }>
					{ c.risk_reasons.map( ( reason, idx ) => (
						<li key={ idx }>{ reason }</li>
					) ) }
				</ul>
			) }
		</>
	);

	const renderComponentVendor = ( c ) => {
		if ( c.vendor ) {
			return (
				<strong>{ c.vendor.name }</strong>
			);
		}
		if ( c.active && canManage ) {
			return (
				<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => registerComponent( c ) }>
					<Icon name="plus" />
					{ __( 'Register as vendor', 'tbsh-compliance-audit-logger' ) }
				</button>
			);
		}
		return (
			<span className="tbsh-muted">{ __( 'Not mapped', 'tbsh-compliance-audit-logger' ) }</span>
		);
	};

	const renderComponentState = ( c, kind ) => {
		if ( c.network_active ) {
			return (
				<span className="tbsh-badge tbsh-badge-success">{ __( 'Network active', 'tbsh-compliance-audit-logger' ) }</span>
			);
		}
		if ( c.active ) {
			let activeLabel;
			if ( 'theme' === kind && 'parent' === c.role ) {
				activeLabel = __( 'Parent theme', 'tbsh-compliance-audit-logger' );
			} else if ( 'theme' === kind && 'child' === c.role ) {
				activeLabel = __( 'Active (child)', 'tbsh-compliance-audit-logger' );
			} else {
				activeLabel = __( 'Active', 'tbsh-compliance-audit-logger' );
			}
			return (
				<span className="tbsh-badge tbsh-badge-success">
					{ activeLabel }
				</span>
			);
		}
		return (
			<span className="tbsh-badge tbsh-badge-info">{ __( 'Inactive', 'tbsh-compliance-audit-logger' ) }</span>
		);
	};

	const componentTable = ( list, kind ) => (
		<div className="tbsh-table-container">
			{ list.length === 0 ? (
				<EmptyState
					title={ 'theme' === kind ? __( 'No themes found', 'tbsh-compliance-audit-logger' ) : __( 'No plugins found', 'tbsh-compliance-audit-logger' ) }
					icon="database"
				/>
			) : (
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Version', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'State', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Update', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Vendor', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Risk', 'tbsh-compliance-audit-logger' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ list.map( ( c ) => (
							<tr key={ `${ c.type }-${ c.file || c.slug }` }>
								<td>
									<strong>{ c.name }</strong>
									<div className="tbsh-muted">
										{ c.author
											? sprintf(
												/* translators: 1: component slug, 2: author */
												__( '%1$s · by %2$s', 'tbsh-compliance-audit-logger' ),
												c.slug,
												c.author
											)
											: c.slug }
									</div>
								</td>
								<td>
									<code>{ c.version || '—' }</code>
								</td>
								<td>
									{ renderComponentState( c, kind ) }
								</td>
								<td>
									{ c.update_available ? (
										<span className="tbsh-badge tbsh-badge-warning">
											{ sprintf(
												/* translators: %s: new version */
												__( 'Update to %s', 'tbsh-compliance-audit-logger' ),
												c.update_available
											) }
										</span>
									) : (
										<span className="tbsh-muted">{ __( 'Up to date', 'tbsh-compliance-audit-logger' ) }</span>
									) }
								</td>
								<td>
									{ renderComponentVendor( c ) }
								</td>
								<td>{ riskCell( c ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);

	const renderVendorList = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 5 } cols={ 6 } />
			);
		}
		if ( error ) {
			return (
				<div style={ { padding: '0 0 20px', textAlign: 'center' } }>
					<EmptyState title={ __( 'Could not load vendors', 'tbsh-compliance-audit-logger' ) } description={ error } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ reload }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( items.length === 0 ) {
			return (
				<EmptyState
					title={ hasFilters ? __( 'No matching vendors', 'tbsh-compliance-audit-logger' ) : __( 'No vendors registered yet', 'tbsh-compliance-audit-logger' ) }
					description={
						hasFilters
							? __( 'No vendors match the current filters.', 'tbsh-compliance-audit-logger' )
							: __( 'Record the ICT third parties this site depends on (DORA Art. 28). The Software supply chain tab lists active plugins and themes you can register in one click.', 'tbsh-compliance-audit-logger' )
					}
					icon="vendors"
				/>
			);
		}
		return (
			<>
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Vendor', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Criticality', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Risk rating', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Data access', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Contract renewal', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Review date', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
							{ canManage && <th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th> }
						</tr>
					</thead>
					<tbody>
						{ items.map( ( v ) => (
							<tr key={ v.id }>
								<td>
									<strong>{ v.name }</strong>
									<div className="tbsh-muted">
										{ v.service_type }
										{ v.linked_slug &&
											` · ${ sprintf(
												/* translators: %s: plugin or theme name */
												__( 'linked to %s', 'tbsh-compliance-audit-logger' ),
												componentName( v.linked_slug )
											) }` }
									</div>
								</td>
								<td>
									<span className={ `tbsh-badge tbsh-badge-${ levelBadge( v.criticality ) }` }>{ levelLabel( v.criticality ) }</span>
								</td>
								<td>
									<span className={ `tbsh-badge tbsh-badge-${ levelBadge( v.risk_rating ) }` }>{ levelLabel( v.risk_rating ) }</span>
								</td>
								<td>{ accessLabel( v.data_access_level ) }</td>
								<td>{ v.contract_renewal_date || '—' }</td>
								<td>
									{ v.review_date || '—' }
									{ v.review_overdue && (
										<>
											{ ' ' }
											<span className="tbsh-badge tbsh-badge-danger">{ __( 'Overdue', 'tbsh-compliance-audit-logger' ) }</span>
										</>
									) }
								</td>
								<td>
									<span className={ `tbsh-badge tbsh-badge-${ 'active' === v.status ? 'success' : 'info' }` }>{ statusLabel( v.status ) }</span>
								</td>
								{ canManage && (
									<td style={ { textAlign: 'end', whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
											onClick={ () => openEdit( v ) }
											aria-label={ sprintf(
												/* translators: %s: vendor name */
												__( 'Edit vendor %s', 'tbsh-compliance-audit-logger' ),
												v.name
											) }
										>
											<Icon name="edit" />
										</button>{ ' ' }
										<button
											type="button"
											className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
											onClick={ () => setToDelete( v ) }
											aria-label={ sprintf(
												/* translators: %s: vendor name */
												__( 'Delete vendor %s', 'tbsh-compliance-audit-logger' ),
												v.name
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
				<Pagination page={ page } totalPages={ totalPages } total={ total } setPage={ setPage } />
			</>
		);
	};

	const renderRegister = () => (
		<>
			{ stats && (
				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Active vendors', 'tbsh-compliance-audit-logger' ) }
						value={ stats.active || 0 }
						icon="vendors"
						desc={ sprintf(
							/* translators: %d: total vendors */
							__( '%d in the register overall', 'tbsh-compliance-audit-logger' ),
							stats.total || 0
						) }
					/>
					<MetricCard
						title={ __( 'Critical vendors', 'tbsh-compliance-audit-logger' ) }
						value={ stats.critical || 0 }
						icon="alert"
						status={ stats.critical_overdue > 0 ? 'danger' : '' }
						desc={
							stats.critical_overdue > 0
								? sprintf(
									/* translators: %d: number of critical vendors with an overdue review */
									_n( '%d with an overdue review', '%d with overdue reviews', stats.critical_overdue, 'tbsh-compliance-audit-logger' ),
									stats.critical_overdue
								)
								: __( 'Active vendors with high criticality', 'tbsh-compliance-audit-logger' )
						}
					/>
					<MetricCard
						title={ __( 'Overdue reviews', 'tbsh-compliance-audit-logger' ) }
						value={ stats.overdue_reviews || 0 }
						icon="calendar"
						status={ stats.overdue_reviews > 0 ? 'warning' : 'success' }
						desc={ __( 'Active vendors past their security review date', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'Renewals in 30 days', 'tbsh-compliance-audit-logger' ) }
						value={ stats.renewals_soon || 0 }
						icon="timeline"
						status={ stats.renewals_soon > 0 ? 'warning' : '' }
						desc={ __( 'Contract renewals due within 30 days', 'tbsh-compliance-audit-logger' ) }
					/>
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
					<label htmlFor="tbsh-vendor-search" className="screen-reader-text">
						{ __( 'Search vendors', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input
						id="tbsh-vendor-search"
						type="search"
						className="tbsh-filter-input"
						style={ { flexGrow: 1 } }
						placeholder={ __( 'Search name or service type…', 'tbsh-compliance-audit-logger' ) }
						value={ searchInput }
						onChange={ ( e ) => setSearchInput( e.target.value ) }
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-secondary">
						<Icon name="search" />
						{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
					</button>
				</form>
				<label htmlFor="tbsh-vendor-criticality" className="screen-reader-text">
					{ __( 'Filter by criticality', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-vendor-criticality"
					className="tbsh-filter-input"
					value={ criticality }
					onChange={ ( e ) => {
						setCriticality( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All criticality levels', 'tbsh-compliance-audit-logger' ) }</option>
					{ LEVELS.map( ( l ) => (
						<option key={ l } value={ l }>
							{ levelLabel( l ) }
						</option>
					) ) }
				</select>
				<label htmlFor="tbsh-vendor-status" className="screen-reader-text">
					{ __( 'Filter by status', 'tbsh-compliance-audit-logger' ) }
				</label>
				<select
					id="tbsh-vendor-status"
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
				{ hasFilters && (
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary"
						onClick={ () => {
							setCriticality( '' );
							setStatus( '' );
							setSearch( '' );
							setSearchInput( '' );
							setPage( 1 );
						} }
					>
						{ __( 'Reset filters', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			<div className="tbsh-table-container">
				{ renderVendorList() }
			</div>
		</>
	);

	const renderSupplyChain = () => {
		if ( chainLoading && ! chain ) {
			return (
				<div className="tbsh-card">
					<SkeletonLoader rows={ 6 } cols={ 5 } />
				</div>
			);
		}
		if ( chainError && ! chain ) {
			return (
				<div className="tbsh-card" style={ { textAlign: 'center' } }>
					<EmptyState title={ __( 'Could not load the supply chain inventory', 'tbsh-compliance-audit-logger' ) } description={ chainError } icon="alert" />
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => loadChain( false ) }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			);
		}
		if ( ! chain ) {
			return null;
		}
		const summary = chain.summary || {};
		const integrations = chain.integrations || [];
		return (
			<>
				{ chainError && <Notice type="error" message={ chainError } /> }
				<div className="tbsh-row-between" style={ { marginBottom: '16px' } }>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ chain.generated_at
							? sprintf(
								/* translators: %s: date and time the inventory was generated */
								__( 'Inventory generated %s from the installed plugins, themes and configured outbound connections. Cached for up to 12 hours.', 'tbsh-compliance-audit-logger' ),
								chain.generated_at
							)
							: __( 'Inventory of installed plugins, themes and configured outbound connections.', 'tbsh-compliance-audit-logger' ) }
					</p>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => loadChain( true ) } disabled={ chainLoading }>
						<Icon name="timeline" />
						{ chainLoading ? __( 'Refreshing…', 'tbsh-compliance-audit-logger' ) : __( 'Refresh', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>

				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Plugins installed', 'tbsh-compliance-audit-logger' ) }
						value={ summary.plugins || 0 }
						icon="database"
						desc={ sprintf(
							/* translators: 1: active plugins, 2: inactive plugins */
							__( '%1$d active, %2$d inactive', 'tbsh-compliance-audit-logger' ),
							summary.active_plugins || 0,
							summary.inactive_plugins || 0
						) }
					/>
					<MetricCard
						title={ __( 'Updates pending', 'tbsh-compliance-audit-logger' ) }
						value={ summary.updates_pending || 0 }
						icon="download"
						status={ summary.updates_pending > 0 ? 'warning' : 'success' }
						desc={ __( 'Plugins and themes with a newer version available', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'High-risk components', 'tbsh-compliance-audit-logger' ) }
						value={ summary.high_risk || 0 }
						icon="alert"
						status={ summary.high_risk > 0 ? 'danger' : 'success' }
						desc={ __( 'Scored from pending updates, inactivity and vendor criticality', 'tbsh-compliance-audit-logger' ) }
					/>
					<MetricCard
						title={ __( 'Unmapped active plugins', 'tbsh-compliance-audit-logger' ) }
						value={ summary.unmapped_active || 0 }
						icon="vendors"
						status={ summary.unmapped_active > 0 ? 'warning' : 'success' }
						desc={ __( 'Active plugins not linked to a registered vendor', 'tbsh-compliance-audit-logger' ) }
					/>
				</div>

				<h4 className="tbsh-section-title">{ __( 'Plugins', 'tbsh-compliance-audit-logger' ) }</h4>
				{ componentTable( chain.plugins || [], 'plugin' ) }

				<h4 className="tbsh-section-title">{ __( 'Themes', 'tbsh-compliance-audit-logger' ) }</h4>
				{ componentTable( chain.themes || [], 'theme' ) }

				<h4 className="tbsh-section-title">{ __( 'Outbound integrations', 'tbsh-compliance-audit-logger' ) }</h4>
				<div className="tbsh-table-container">
					{ integrations.length === 0 ? (
						<EmptyState title={ __( 'No outbound integrations detected', 'tbsh-compliance-audit-logger' ) } icon="database" />
					) : (
						<table className="tbsh-table">
							<thead>
								<tr>
									<th>{ __( 'Name', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Purpose', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Endpoint', 'tbsh-compliance-audit-logger' ) }</th>
									<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ integrations.map( ( it, idx ) => (
									<tr key={ `${ it.name }-${ idx }` }>
										<td>
											<strong>{ it.name }</strong>
										</td>
										<td>{ it.kind }</td>
										<td>
											<code>{ it.endpoint || '—' }</code>
										</td>
										<td>
											<span className={ `tbsh-badge tbsh-badge-${ it.enabled ? 'success' : 'info' }` }>
												{ it.enabled ? __( 'Enabled', 'tbsh-compliance-audit-logger' ) : __( 'Not configured', 'tbsh-compliance-audit-logger' ) }
											</span>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			</>
		);
	};

	// Options for the linked component select; keep a stale slug visible when editing.
	const slugOptions = components.map( ( c ) => ( {
		value: c.slug,
		label: sprintf(
			/* translators: 1: component name, 2: "Plugin" or "Theme" */
			__( '%1$s (%2$s)', 'tbsh-compliance-audit-logger' ),
			c.name,
			'theme' === c.type ? __( 'Theme', 'tbsh-compliance-audit-logger' ) : __( 'Plugin', 'tbsh-compliance-audit-logger' )
		),
	} ) );
	if ( form.linked_slug && ! slugOptions.some( ( o ) => o.value === form.linked_slug ) ) {
		slugOptions.push( { value: form.linked_slug, label: form.linked_slug } );
	}

	return (
		<div className="tbsh-vendors-page">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-row-between" style={ { marginBottom: '20px' } }>
				<div>
					<h3 className="tbsh-section-title" style={ { marginBottom: '4px' } }>
						{ __( 'Vendor Governance', 'tbsh-compliance-audit-logger' ) }
					</h3>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'Register of ICT third-party providers and the software supply chain of this site (DORA Art. 28, NIS2 Art. 21(2)(d)).', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
				{ canManage && 'register' === tab && (
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ () => openCreate() }>
						<Icon name="plus" />
						{ __( 'Add vendor', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			<div className="tbsh-tabs" role="tablist">
				<button
					type="button"
					role="tab"
					aria-selected={ 'register' === tab }
					className={ `tbsh-tab-btn${ 'register' === tab ? ' active' : '' }` }
					onClick={ () => setTab( 'register' ) }
				>
					{ __( 'Vendor register', 'tbsh-compliance-audit-logger' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ 'supply' === tab }
					className={ `tbsh-tab-btn${ 'supply' === tab ? ' active' : '' }` }
					onClick={ () => setTab( 'supply' ) }
				>
					{ __( 'Software supply chain', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>

			{ 'register' === tab ? renderRegister() : renderSupplyChain() }

			<Modal title={ editing && editing.id ? __( 'Edit vendor', 'tbsh-compliance-audit-logger' ) : __( 'Add vendor', 'tbsh-compliance-audit-logger' ) } isOpen={ !! editing } onClose={ closeForm }>
				<form onSubmit={ submitForm }>
					{ formError && <Notice type="error" message={ formError } /> }
					<div className="tbsh-grid-2" style={ { marginBottom: 0 } }>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-name">
								{ __( 'Vendor name', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-vendor-name"
								type="text"
								className="tbsh-form-control"
								value={ form.name }
								onChange={ ( e ) => setField( 'name', e.target.value ) }
								required
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-service">
								{ __( 'Service type', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-vendor-service"
								type="text"
								className="tbsh-form-control"
								placeholder={ __( 'e.g. SaaS Integration, Hosting, Plugin Developer', 'tbsh-compliance-audit-logger' ) }
								value={ form.service_type }
								onChange={ ( e ) => setField( 'service_type', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-criticality-field">
								{ __( 'Criticality', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-vendor-criticality-field"
								className="tbsh-form-control"
								value={ form.criticality }
								onChange={ ( e ) => setField( 'criticality', e.target.value ) }
							>
								{ LEVELS.map( ( l ) => (
									<option key={ l } value={ l }>
										{ levelLabel( l ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-risk">
								{ __( 'Risk rating', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-vendor-risk"
								className="tbsh-form-control"
								value={ form.risk_rating }
								onChange={ ( e ) => setField( 'risk_rating', e.target.value ) }
							>
								{ LEVELS.map( ( l ) => (
									<option key={ l } value={ l }>
										{ levelLabel( l ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-access">
								{ __( 'Data access level', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-vendor-access"
								className="tbsh-form-control"
								value={ form.data_access_level }
								onChange={ ( e ) => setField( 'data_access_level', e.target.value ) }
							>
								{ ACCESS.map( ( a ) => (
									<option key={ a } value={ a }>
										{ accessLabel( a ) }
									</option>
								) ) }
							</select>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-status-field">
								{ __( 'Status', 'tbsh-compliance-audit-logger' ) }
							</label>
							<select
								id="tbsh-vendor-status-field"
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
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-renewal">
								{ __( 'Contract renewal date', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-vendor-renewal"
								type="date"
								className="tbsh-form-control"
								value={ form.contract_renewal_date || '' }
								onChange={ ( e ) => setField( 'contract_renewal_date', e.target.value ) }
							/>
						</div>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-vendor-review">
								{ __( 'Next security review', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input
								id="tbsh-vendor-review"
								type="date"
								className="tbsh-form-control"
								value={ form.review_date || '' }
								onChange={ ( e ) => setField( 'review_date', e.target.value ) }
							/>
						</div>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-vendor-linked">
							{ __( 'Linked plugin or theme', 'tbsh-compliance-audit-logger' ) }
						</label>
						<select
							id="tbsh-vendor-linked"
							className="tbsh-form-control"
							value={ form.linked_slug }
							onChange={ ( e ) => setField( 'linked_slug', e.target.value ) }
						>
							<option value="">{ __( 'None', 'tbsh-compliance-audit-logger' ) }</option>
							{ slugOptions.map( ( o ) => (
								<option key={ o.value } value={ o.value }>
									{ o.label }
								</option>
							) ) }
						</select>
						<p className="tbsh-muted" style={ { margin: '6px 0 0' } }>
							{ __( 'Links this vendor to an installed component in the software supply chain view.', 'tbsh-compliance-audit-logger' ) }
						</p>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-vendor-contact">
							{ __( 'Contact information', 'tbsh-compliance-audit-logger' ) }
						</label>
						<textarea
							id="tbsh-vendor-contact"
							className="tbsh-form-control"
							rows={ 3 }
							value={ form.contact_info }
							onChange={ ( e ) => setField( 'contact_info', e.target.value ) }
						/>
					</div>
					<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeForm } disabled={ saving }>
							{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
						</button>
						<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ saving }>
							{ saving ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save vendor', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</form>
			</Modal>

			<Modal title={ __( 'Remove vendor', 'tbsh-compliance-audit-logger' ) } isOpen={ !! toDelete } onClose={ closeDelete }>
				{ toDelete && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: vendor name */
								__( 'Remove "%s" from the vendor register? Any plugin or theme mapping is removed too; the deletion is recorded in the audit log.', 'tbsh-compliance-audit-logger' ),
								toDelete.name
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ closeDelete } disabled={ deleting }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ confirmDelete } disabled={ deleting }>
								{ deleting ? __( 'Removing…', 'tbsh-compliance-audit-logger' ) : __( 'Remove', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
