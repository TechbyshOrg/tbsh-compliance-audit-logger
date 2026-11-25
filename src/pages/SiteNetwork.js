import { useState, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Modal from '../components/Modal';
import Icon from '../components/Icon';
import MetricCard from '../components/MetricCard';
import { SeverityBadge } from '../components/labels';
import { SiteTime } from '../components/time';
import { get, post, del, can } from '../api';

/**
 * Translated log-integrity status.
 *
 * @param {string} status Status.
 * @return {Array} [ label, badge tone ].
 */
const integrityLabel = ( status ) => {
	switch ( status ) {
		case 'verified':
			return [ __( 'Verified', 'tbsh-compliance-audit-logger' ), 'success' ];
		case 'warning':
			return [ __( 'Issues found', 'tbsh-compliance-audit-logger' ), 'warning' ];
		case 'compromised':
			return [ __( 'Tampering detected', 'tbsh-compliance-audit-logger' ), 'danger' ];
		default:
			return [ __( 'Not verified', 'tbsh-compliance-audit-logger' ), 'info' ];
	}
};

/**
 * Whether a site needs attention.
 *
 * @param {Object} site Site.
 * @return {boolean} Needs attention.
 */
const needsAttention = ( site ) => {
	const s = site.summary || {};
	return 'error' === site.status || 'compromised' === s.integrity || 'warning' === s.integrity || ( s.open_incidents || 0 ) > 0;
};

export default function SiteNetwork() {
	const canManage = can( 'manage_settings' );
	const [ sites, setSites ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ form, setForm ] = useState( { label: '', link: '' } );
	const [ busy, setBusy ] = useState( '' );
	const [ replaceTarget, setReplaceTarget ] = useState( null );
	const [ replaceLink, setReplaceLink ] = useState( '' );
	const [ removeTarget, setRemoveTarget ] = useState( null );

	useEffect( () => {
		get( '/network' )
			.then( ( data ) => setSites( data.sites || [] ) )
			.catch( ( err ) => setLoadError( ( err && err.message ) || __( 'The site network could not be loaded.', 'tbsh-compliance-audit-logger' ) ) );
	}, [] );

	const upsert = ( site ) => setSites( ( prev ) => [ ...( prev || [] ).filter( ( s ) => s.id !== site.id ), site ].sort( ( a, b ) => a.label.localeCompare( b.label ) ) );
	const fail = ( err, fallback ) => setNotice( { type: 'error', message: ( err && err.message ) || fallback } );

	const connect = ( e ) => {
		e.preventDefault();
		setBusy( 'add' );
		setNotice( { type: '', message: '' } );
		post( '/network', form )
			.then( ( site ) => {
				upsert( site );
				setForm( { label: '', link: '' } );
				setNotice( {
					type: 'success',
					/* translators: %s: site name */
					message: sprintf( __( '%s is connected.', 'tbsh-compliance-audit-logger' ), site.label ),
				} );
			} )
			.catch( ( err ) => fail( err, __( 'The site could not be connected.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const refreshAll = () => {
		setBusy( 'all' );
		setNotice( { type: '', message: '' } );
		post( '/network/refresh' )
			.then( ( data ) => {
				setSites( data.sites || [] );
				setNotice( { type: 'success', message: __( 'Sites refreshed.', 'tbsh-compliance-audit-logger' ) } );
			} )
			.catch( ( err ) => fail( err, __( 'The sites could not be refreshed.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const refreshOne = ( site ) => {
		setBusy( site.id );
		post( `/network/${ site.id }` )
			.then( upsert )
			.catch( ( err ) => fail( err, __( 'The site could not be refreshed.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const confirmReplace = ( e ) => {
		e.preventDefault();
		setBusy( 'replace' );
		post( `/network/${ replaceTarget.id }`, { link: replaceLink } )
			.then( ( site ) => {
				upsert( site );
				setReplaceTarget( null );
				setReplaceLink( '' );
				setNotice( { type: 'success', message: __( 'The link was replaced.', 'tbsh-compliance-audit-logger' ) } );
			} )
			.catch( ( err ) => fail( err, __( 'The link could not be replaced.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const confirmRemove = () => {
		setBusy( 'remove' );
		del( `/network/${ removeTarget.id }` )
			.then( () => {
				setSites( ( prev ) => prev.filter( ( s ) => s.id !== removeTarget.id ) );
				setNotice( {
					type: 'success',
					/* translators: %s: site name */
					message: sprintf( __( '%s was disconnected. Revoke its auditor link on that site to end access completely.', 'tbsh-compliance-audit-logger' ), removeTarget.label ),
				} );
				setRemoveTarget( null );
			} )
			.catch( ( err ) => fail( err, __( 'The site could not be disconnected.', 'tbsh-compliance-audit-logger' ) ) )
			.finally( () => setBusy( '' ) );
	};

	if ( loadError ) {
		return <EmptyState title={ __( 'Could not load the site network', 'tbsh-compliance-audit-logger' ) } description={ loadError } icon="alert" />;
	}
	if ( null === sites ) {
		return <SkeletonLoader rows={ 4 } cols={ 4 } />;
	}

	const attention = sites.filter( needsAttention );
	const scored = sites.filter( ( s ) => s.summary && null !== s.summary.readiness && undefined !== s.summary.readiness );
	const lowest = scored.length ? scored.reduce( ( min, s ) => ( s.summary.readiness < min.summary.readiness ? s : min ) ) : null;
	const latest = sites
		.flatMap( ( s ) => ( ( s.summary && s.summary.latest ) || [] ).map( ( row ) => ( { ...row, site: s.label } ) ) )
		.sort( ( a, b ) => String( b.created_at ).localeCompare( String( a.created_at ) ) )
		.slice( 0, 15 );

	const connectForm = canManage && (
		<section className="tbsh-card" aria-labelledby="tbsh-network-add-title" style={ { marginBottom: '24px' } }>
			<h3 id="tbsh-network-add-title" className="tbsh-section-title">
				{ __( 'Connect a site', 'tbsh-compliance-audit-logger' ) }
			</h3>
			<ol className="tbsh-muted" style={ { margin: '0 0 16px', paddingInlineStart: '20px', fontSize: '13px' } }>
				<li>{ __( 'On the other site (it needs this plugin), open Auditor Access and create a link. Links last up to 90 days.', 'tbsh-compliance-audit-logger' ) }</li>
				<li>{ __( 'Copy the link and paste it below. It gives read-only access; revoke it on that site at any time to disconnect.', 'tbsh-compliance-audit-logger' ) }</li>
			</ol>
			<form onSubmit={ connect } className="tbsh-grid-fields" style={ { alignItems: 'end' } }>
				<div>
					<label className="tbsh-form-label" htmlFor="tbsh-network-label">
						{ __( 'Name', 'tbsh-compliance-audit-logger' ) } <span className="tbsh-muted">{ __( '(optional)', 'tbsh-compliance-audit-logger' ) }</span>
					</label>
					<input id="tbsh-network-label" className="tbsh-form-control" maxLength={ 80 } value={ form.label } onChange={ ( e ) => setForm( { ...form, label: e.target.value } ) } placeholder={ __( 'e.g. Shop (production)', 'tbsh-compliance-audit-logger' ) } />
				</div>
				<div style={ { gridColumn: 'span 2' } }>
					<label className="tbsh-form-label" htmlFor="tbsh-network-link">
						{ __( 'Auditor link', 'tbsh-compliance-audit-logger' ) }
					</label>
					<input id="tbsh-network-link" type="url" required className="tbsh-form-control" value={ form.link } onChange={ ( e ) => setForm( { ...form, link: e.target.value } ) } placeholder="https://example.com/?tbsh_cal_audit=…" autoComplete="off" spellCheck="false" />
				</div>
				<div>
					<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ 'add' === busy }>
						<Icon name="plus" />
						{ 'add' === busy ? __( 'Connecting…', 'tbsh-compliance-audit-logger' ) : __( 'Connect site', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</form>
		</section>
	);

	return (
		<div className="tbsh-network-page">
			{ notice.message && <Notice type={ notice.type } message={ notice.message } /> }

			{ ! sites.length ? (
				<>
					{ connectForm }
					<EmptyState
						title={ __( 'No sites connected yet', 'tbsh-compliance-audit-logger' ) }
						description={
							canManage
								? __( 'Connect other sites that run this plugin to see their readiness, errors and incidents here.', 'tbsh-compliance-audit-logger' )
								: __( 'An administrator can connect other sites that run this plugin.', 'tbsh-compliance-audit-logger' )
						}
						icon="dashboard"
					/>
				</>
			) : (
				<>
					<div className="tbsh-dashboard-grid">
						<MetricCard title={ __( 'Connected sites', 'tbsh-compliance-audit-logger' ) } value={ sites.length.toLocaleString() } icon="dashboard" />
						<MetricCard
							title={ __( 'Need attention', 'tbsh-compliance-audit-logger' ) }
							value={ attention.length.toLocaleString() }
							icon="alert"
							status={ attention.length ? 'warning' : '' }
							desc={ __( 'Unreachable, open incidents or log issues', 'tbsh-compliance-audit-logger' ) }
						/>
						<MetricCard
							title={ __( 'Lowest readiness', 'tbsh-compliance-audit-logger' ) }
							value={ lowest ? `${ lowest.summary.readiness }/100` : '—' }
							icon="compliance"
							desc={ lowest ? lowest.label : '' }
						/>
					</div>

					<section className="tbsh-card" aria-labelledby="tbsh-network-sites-title" style={ { marginBottom: '24px' } }>
						<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
							<h3 id="tbsh-network-sites-title" className="tbsh-section-title" style={ { margin: 0 } }>
								{ __( 'Sites', 'tbsh-compliance-audit-logger' ) }
							</h3>
							{ canManage && (
								<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ refreshAll } disabled={ !! busy }>
									{ 'all' === busy ? __( 'Refreshing…', 'tbsh-compliance-audit-logger' ) : __( 'Refresh all', 'tbsh-compliance-audit-logger' ) }
								</button>
							) }
						</div>
						<div className="tbsh-table-container" style={ { margin: 0 } } role="region" aria-labelledby="tbsh-network-sites-title" tabIndex="0">
							<table className="tbsh-table tbsh-table-sticky-first">
								<thead>
									<tr>
										<th>{ __( 'Site', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Readiness', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Events today', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Errors and critical', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Failed sign-ins', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Open incidents', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Audit log', 'tbsh-compliance-audit-logger' ) }</th>
										<th>{ __( 'Last checked', 'tbsh-compliance-audit-logger' ) }</th>
										{ canManage && (
											<th>
												<span className="tbsh-sr-only">{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</span>
											</th>
										) }
									</tr>
								</thead>
								<tbody>
									{ sites.map( ( site ) => {
										const s = site.summary || {};
										const [ integrity, tone ] = integrityLabel( s.integrity );
										const value = ( v ) => ( null === v || undefined === v ? '—' : Number( v ).toLocaleString() );
										return (
											<tr key={ site.id }>
												<td>
													<strong>{ site.label }</strong>
													<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px' } }>
														{ site.url }
													</span>
													{ 'error' === site.status && (
														<span className="tbsh-badge tbsh-badge-danger" style={ { marginTop: '4px', textTransform: 'none', whiteSpace: 'normal' } } role="note">
															{ site.last_error }
														</span>
													) }
												</td>
												<td>{ null === s.readiness || undefined === s.readiness ? '—' : `${ s.readiness }/100` }</td>
												<td>{ value( s.events_today ) }</td>
												<td>{ value( s.critical_events ) }</td>
												<td>{ value( s.failed_logins ) }</td>
												<td>{ value( s.open_incidents ) }</td>
												<td>
													{ site.summary ? <span className={ `tbsh-badge tbsh-badge-${ tone }` }>{ integrity }</span> : '—' }
												</td>
												<td>{ site.last_checked ? <SiteTime value={ site.last_checked } /> : __( 'Never', 'tbsh-compliance-audit-logger' ) }</td>
												{ canManage && (
													<td>
														<div className="tbsh-row" style={ { flexWrap: 'nowrap', justifyContent: 'flex-end' } }>
															<button
																type="button"
																className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
																onClick={ () => refreshOne( site ) }
																disabled={ !! busy }
																aria-label={ sprintf(
																	/* translators: %s: site name */
																	__( 'Refresh %s', 'tbsh-compliance-audit-logger' ),
																	site.label
																) }
															>
																{ site.id === busy ? __( 'Refreshing…', 'tbsh-compliance-audit-logger' ) : __( 'Refresh', 'tbsh-compliance-audit-logger' ) }
															</button>
															<button
																type="button"
																className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
																onClick={ () => setReplaceTarget( site ) }
																aria-label={ sprintf(
																	/* translators: %s: site name */
																	__( 'Replace the auditor link of %s', 'tbsh-compliance-audit-logger' ),
																	site.label
																) }
															>
																{ __( 'Replace link', 'tbsh-compliance-audit-logger' ) }
															</button>
															<button
																type="button"
																className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
																onClick={ () => setRemoveTarget( site ) }
																aria-label={ sprintf(
																	/* translators: %s: site name */
																	__( 'Disconnect %s', 'tbsh-compliance-audit-logger' ),
																	site.label
																) }
															>
																{ __( 'Disconnect', 'tbsh-compliance-audit-logger' ) }
															</button>
														</div>
													</td>
												) }
											</tr>
										);
									} ) }
								</tbody>
							</table>
						</div>
					</section>

					<section className="tbsh-card" aria-labelledby="tbsh-network-latest-title" style={ { marginBottom: '24px' } }>
						<h3 id="tbsh-network-latest-title" className="tbsh-section-title">
							{ __( 'Latest errors and critical events', 'tbsh-compliance-audit-logger' ) }
						</h3>
						{ latest.length ? (
							<>
								<p className="tbsh-muted" style={ { marginTop: 0 } }>
									{ __( 'Times are shown in each site’s own timezone.', 'tbsh-compliance-audit-logger' ) }
								</p>
								<ul className="tbsh-list-plain">
									{ latest.map( ( row, i ) => (
										<li key={ `${ row.site }-${ i }` } className="tbsh-attention-item">
											<span className="tbsh-row">
												<SeverityBadge severity={ row.severity } />
												<strong>{ row.site }</strong>
												<span>{ row.event_title }</span>
												{ row.username && <span className="tbsh-muted">· { row.username }</span> }
											</span>
											<span className="tbsh-muted tbsh-time">{ row.created_at }</span>
										</li>
									) ) }
								</ul>
							</>
						) : (
							<p className="tbsh-muted" style={ { margin: 0 } }>
								{ sprintf(
									/* translators: %d: number of sites */
									_n( 'No errors or critical events on %d connected site.', 'No errors or critical events on %d connected sites.', sites.length, 'tbsh-compliance-audit-logger' ),
									sites.length
								) }
							</p>
						) }
					</section>

					{ connectForm }
				</>
			) }

			<Modal title={ __( 'Replace auditor link', 'tbsh-compliance-audit-logger' ) } isOpen={ !! replaceTarget } onClose={ () => 'replace' !== busy && setReplaceTarget( null ) }>
				{ replaceTarget && (
					<form onSubmit={ confirmReplace } className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: site name */
								__( 'Create a new auditor link on %s and paste it here. The old link keeps working on that site until it expires or is revoked.', 'tbsh-compliance-audit-logger' ),
								replaceTarget.label
							) }
						</p>
						<div>
							<label className="tbsh-form-label" htmlFor="tbsh-network-replace">
								{ __( 'New auditor link', 'tbsh-compliance-audit-logger' ) }
							</label>
							<input id="tbsh-network-replace" type="url" required className="tbsh-form-control" value={ replaceLink } onChange={ ( e ) => setReplaceLink( e.target.value ) } autoComplete="off" spellCheck="false" />
						</div>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setReplaceTarget( null ) } disabled={ 'replace' === busy }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ 'replace' === busy }>
								{ 'replace' === busy ? __( 'Checking…', 'tbsh-compliance-audit-logger' ) : __( 'Replace link', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</form>
				) }
			</Modal>

			<Modal title={ __( 'Disconnect site', 'tbsh-compliance-audit-logger' ) } isOpen={ !! removeTarget } onClose={ () => 'remove' !== busy && setRemoveTarget( null ) }>
				{ removeTarget && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: site name */
								__( 'Stop showing %s here? Its stored link is deleted from this site. To end access completely, also revoke the auditor link on that site.', 'tbsh-compliance-audit-logger' ),
								removeTarget.label
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setRemoveTarget( null ) } disabled={ 'remove' === busy }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ confirmRemove } disabled={ 'remove' === busy }>
								{ 'remove' === busy ? __( 'Disconnecting…', 'tbsh-compliance-audit-logger' ) : __( 'Disconnect', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
