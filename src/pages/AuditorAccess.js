import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { get, post, can } from '../api';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import MetricCard from '../components/MetricCard';


const statusMeta = () => ( {
	active: { label: __( 'Active', 'tbsh-compliance-audit-logger' ), badge: 'success' },
	expired: { label: __( 'Expired', 'tbsh-compliance-audit-logger' ), badge: 'warning' },
	revoked: { label: __( 'Revoked', 'tbsh-compliance-audit-logger' ), badge: 'danger' },
} );

export default function AuditorAccess() {
	const canManage = can( 'manage_auditor_access' );

	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ links, setLinks ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const [ createOpen, setCreateOpen ] = useState( false );
	const [ label, setLabel ] = useState( '' );
	const [ days, setDays ] = useState( 7 );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState( '' );

	const [ created, setCreated ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const urlRef = useRef( null );

	const [ toRevoke, setToRevoke ] = useState( null );
	const [ revoking, setRevoking ] = useState( false );

	const fetchLinks = () => {
		setLoading( true );
		setError( null );
		get( '/auditor/links' )
			.then( ( data ) => {
				setLinks( Array.isArray( data ) ? data : ( ( data && data.items ) || [] ) );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load auditor links.', 'tbsh-compliance-audit-logger' ) );
				setLinks( [] );
				setLoading( false );
			} );
	};

	useEffect( () => {
		if ( canManage ) {
			fetchLinks();
		} else {
			setLoading( false );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const openCreate = () => {
		setLabel( '' );
		setDays( 7 );
		setCreateError( '' );
		setCreateOpen( true );
	};

	const submitCreate = ( e ) => {
		e.preventDefault();
		const trimmed = label.trim();
		if ( ! trimmed ) {
			setCreateError( __( 'Enter a label so you can recognise this link later.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		const d = parseInt( days, 10 );
		if ( ! d || d < 1 || d > 90 ) {
			setCreateError( __( 'Validity must be between 1 and 90 days.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		setCreating( true );
		setCreateError( '' );
		post( '/auditor/links', { label: trimmed, days: d } )
			.then( ( res ) => {
				setCreating( false );
				setCreateOpen( false );
				setCopied( false );
				setCreated( res );
				fetchLinks();
			} )
			.catch( ( err ) => {
				setCreating( false );
				setCreateError( ( err && err.message ) || __( 'Failed to create the link.', 'tbsh-compliance-audit-logger' ) );
			} );
	};

	const copyUrl = () => {
		if ( ! created || ! created.url ) {
			return;
		}
		const fallback = () => {
			if ( urlRef.current ) {
				urlRef.current.focus();
				urlRef.current.select();
				try {
					setCopied( document.execCommand( 'copy' ) );
				} catch ( e ) {
					setCopied( false );
				}
			}
		};
		if ( window.navigator && window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard.writeText( created.url ).then( () => setCopied( true ), fallback );
		} else {
			fallback();
		}
	};

	const closeCreated = () => {
		setCreated( null );
		setCopied( false );
	};

	const confirmRevoke = () => {
		if ( ! toRevoke ) {
			return;
		}
		setRevoking( true );
		post( `/auditor/links/${ toRevoke.id }/revoke` )
			.then( () => {
				setRevoking( false );
				setToRevoke( null );
				setNotice( { type: 'success', message: __( 'The auditor link was revoked and can no longer be used.', 'tbsh-compliance-audit-logger' ) } );
				fetchLinks();
			} )
			.catch( ( err ) => {
				setRevoking( false );
				setToRevoke( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to revoke the link.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	if ( ! canManage ) {
		return (
			<EmptyState
				title={ __( 'Access restricted', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'You do not have permission to manage auditor access links.', 'tbsh-compliance-audit-logger' ) }
				icon="lock"
			/>
		);
	}

	const statuses = statusMeta();
	const activeCount = links.filter( ( l ) => 'active' === l.status ).length;

	const renderLinks = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 4 } cols={ 6 } />
			);
		}
		if ( error ) {
			return (
				<EmptyState
					title={ __( 'Error loading auditor links', 'tbsh-compliance-audit-logger' ) }
					description={ (
						<>
							{ error }
							<br />
							<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '12px' } } onClick={ fetchLinks }>
								{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
							</button>
						</>
					) }
					icon="alert"
				/>
			);
		}
		if ( links.length ) {
			return (
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{ __( 'Label', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Token', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Expires', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Last used', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Created by', 'tbsh-compliance-audit-logger' ) }</th>
							<th>{ __( 'Created', 'tbsh-compliance-audit-logger' ) }</th>
							<th style={ { textAlign: 'end' } }>{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ links.map( ( l ) => {
							const s = statuses[ l.status ] || { label: l.status, badge: 'info' };
							return (
								<tr key={ l.id }>
									<td><strong>{ l.label }</strong></td>
									<td><code>{ l.token_hint }</code></td>
									<td><span className={ `tbsh-badge tbsh-badge-${ s.badge }` }>{ s.label }</span></td>
									<td>{ l.expires_at }</td>
									<td>{ l.last_used_at || __( 'Never', 'tbsh-compliance-audit-logger' ) }</td>
									<td>{ l.created_by || '—' }</td>
									<td>{ l.created_at }</td>
									<td style={ { textAlign: 'end' } }>
										{ 'active' === l.status ? (
											<button type="button" className="tbsh-btn tbsh-btn-danger tbsh-btn-sm" onClick={ () => setToRevoke( l ) }>
												{ __( 'Revoke', 'tbsh-compliance-audit-logger' ) }
											</button>
										) : (
											<span className="tbsh-muted">—</span>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			);
		}
		return (
			<EmptyState
				title={ __( 'No auditor links', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'Create a time-limited, read-only link when an external auditor needs to review your compliance evidence.', 'tbsh-compliance-audit-logger' ) }
				icon="auditor"
			/>
		);
	};

	return (
		<div className="tbsh-auditor-page">
			<Notice type={ notice.type } message={ notice.message } />

			{ ! loading && ! error && (
				<div className="tbsh-dashboard-grid">
					<MetricCard
						title={ __( 'Active links', 'tbsh-compliance-audit-logger' ) }
						value={ activeCount }
						icon="auditor"
						desc={ __( 'Links that can open the read-only portal right now', 'tbsh-compliance-audit-logger' ) }
						status={ activeCount ? 'warning' : '' }
					/>
					<MetricCard
						title={ __( 'All links', 'tbsh-compliance-audit-logger' ) }
						value={ links.length }
						icon="lock"
						desc={ __( 'Including expired and revoked links', 'tbsh-compliance-audit-logger' ) }
					/>
				</div>
			) }

			<div className="tbsh-card" style={ { padding: '20px', marginBottom: '20px' } }>
				<h3 className="tbsh-section-title">{ __( 'Read-only access for external auditors', 'tbsh-compliance-audit-logger' ) }</h3>
				<div className="tbsh-stack">
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'An auditor link opens a read-only compliance portal without a WordPress account. The auditor can see: the dashboard, executive summary, compliance overview, activity logs, timeline, incidents, risks, vendors, resilience drills and metrics, analytics trends, evidence snapshots, integrity status, and compliance reports (download and verify).', 'tbsh-compliance-audit-logger' ) }
					</p>
					<p className="tbsh-muted" style={ { margin: 0 } }>
						{ __( 'Auditors cannot create, edit or delete anything, and cannot see plugin settings, alert channels, vault configuration or any credentials. Every portal visit is recorded in the audit log. Links expire automatically and can be revoked at any time.', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
			</div>

			<div className="tbsh-card" style={ { padding: '20px' } }>
				<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
					<h3 className="tbsh-section-title" style={ { margin: 0 } }>{ __( 'Auditor links', 'tbsh-compliance-audit-logger' ) }</h3>
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ openCreate }>
						<Icon name="plus" />
						{ __( 'Create auditor link', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
				<div className="tbsh-table-container">
					{ renderLinks() }
				</div>
			</div>

			<Modal
				title={ __( 'Create auditor link', 'tbsh-compliance-audit-logger' ) }
				isOpen={ createOpen }
				onClose={ () => ! creating && setCreateOpen( false ) }
			>
				<form onSubmit={ submitCreate } className="tbsh-stack">
					{ createError && <Notice type="error" message={ createError } /> }
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-auditor-label">{ __( 'Label (required)', 'tbsh-compliance-audit-logger' ) }</label>
						<input
							id="tbsh-auditor-label"
							type="text"
							className="tbsh-form-control"
							value={ label }
							required
							maxLength={ 191 }
							placeholder={ __( 'e.g. ISO 27001 surveillance audit — Example Auditors Ltd', 'tbsh-compliance-audit-logger' ) }
							onChange={ ( e ) => setLabel( e.target.value ) }
						/>
					</div>
					<div className="tbsh-form-group">
						<label className="tbsh-form-label" htmlFor="tbsh-auditor-days">{ __( 'Valid for (days)', 'tbsh-compliance-audit-logger' ) }</label>
						<input
							id="tbsh-auditor-days"
							type="number"
							className="tbsh-form-control"
							min={ 1 }
							max={ 90 }
							value={ days }
							onChange={ ( e ) => setDays( e.target.value ) }
						/>
						<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
							{ __( 'Between 1 and 90 days. Choose the shortest period the audit needs.', 'tbsh-compliance-audit-logger' ) }
						</span>
					</div>
					<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
						<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ creating } onClick={ () => setCreateOpen( false ) }>
							{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
						</button>
						<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ creating }>
							{ creating ? __( 'Creating…', 'tbsh-compliance-audit-logger' ) : __( 'Create link', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				</form>
			</Modal>

			<Modal
				title={ __( 'Auditor link created', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! created }
				onClose={ closeCreated }
			>
				{ created && (
					<div className="tbsh-stack">
						<Notice
							type="warning"
							message={ __( 'Copy this link now. It will not be shown again. Anyone who has this link gets read-only access to your compliance data until it expires or you revoke it — share it only through a secure channel.', 'tbsh-compliance-audit-logger' ) }
						/>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-auditor-url">{ __( 'Auditor link', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-auditor-url"
								ref={ urlRef }
								type="text"
								className="tbsh-form-control"
								readOnly
								value={ created.url || '' }
								onFocus={ ( e ) => e.target.select() }
							/>
						</div>
						{ created.link && created.link.expires_at && (
							<p className="tbsh-muted" style={ { margin: 0 } }>
								{ sprintf(
									/* translators: %s: expiry date and time */
									__( 'Expires: %s', 'tbsh-compliance-audit-logger' ),
									created.link.expires_at
								) }
							</p>
						) }
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ copyUrl }>
								<Icon name={ copied ? 'check' : 'export' } />
								{ copied ? __( 'Copied', 'tbsh-compliance-audit-logger' ) : __( 'Copy link', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ closeCreated }>
								{ __( 'Done', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>

			<Modal
				title={ __( 'Revoke auditor link', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! toRevoke }
				onClose={ () => ! revoking && setToRevoke( null ) }
			>
				{ toRevoke && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: link label */
								__( 'Revoke "%s"? The auditor will lose access immediately. This cannot be undone; create a new link if access is needed again.', 'tbsh-compliance-audit-logger' ),
								toRevoke.label
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ revoking } onClick={ () => setToRevoke( null ) }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" disabled={ revoking } onClick={ confirmRevoke }>
								{ revoking ? __( 'Revoking…', 'tbsh-compliance-audit-logger' ) : __( 'Revoke link', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
