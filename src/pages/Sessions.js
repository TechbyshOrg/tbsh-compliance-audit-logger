import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import Modal from '../components/Modal';
import { get, post, del, can } from '../api';

const formatDate = ( iso ) => {
	if ( ! iso ) {
		return '—';
	}
	try {
		return new Date( iso ).toLocaleString();
	} catch {
		return iso;
	}
};

export default function Sessions() {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ confirm, setConfirm ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const allowed = can( 'manage_sessions' );

	const load = ( q = query ) => {
		setLoading( true );
		get( '/sessions', { search: q } )
			.then( ( res ) => setData( res ) )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to load sessions.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( () => {
		if ( allowed ) {
			load( '' );
		} else {
			setLoading( false );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! allowed ) {
		return (
			<EmptyState
				title={ __( 'Access Denied', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'You do not have permission to manage login sessions.', 'tbsh-compliance-audit-logger' ) }
				icon="lock"
			/>
		);
	}

	const onSearch = ( e ) => {
		e.preventDefault();
		setQuery( search.trim() );
		load( search.trim() );
	};

	const runConfirmed = () => {
		const action = confirm;
		setBusy( true );
		const request = action.kind === 'one'
			? del( `/sessions/${ action.session.user_id }/${ action.session.id }` )
			: post( `/sessions/${ action.session.user_id }/logout-all` );
		request
			.then( ( res ) => {
				setNotice( { type: 'success', message: res.message } );
				load();
			} )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'The session could not be ended.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => {
				setBusy( false );
				setConfirm( null );
			} );
	};

	const sessions = ( data && data.sessions ) || [];

	return (
		<div className="tbsh-sessions-page">
			{ notice.message && <Notice type={ notice.type } message={ notice.message } /> }

			<div className="tbsh-card" style={ { padding: '20px', marginBottom: '24px' } }>
				<h3 style={ { margin: '0 0 6px 0', fontSize: '18px', fontWeight: '600' } }>
					{ __( 'Active Login Sessions', 'tbsh-compliance-audit-logger' ) }
				</h3>
				<p className="tbsh-muted" style={ { margin: 0 } }>
					{ __( 'Everyone currently signed in to this site, on every device. During an incident, end a suspicious session or sign a user out everywhere; each action is recorded in the audit log. Ending a session does not change the password, so reset it too if credentials may be compromised.', 'tbsh-compliance-audit-logger' ) }
				</p>
			</div>

			<div className="tbsh-filters-bar">
				<form onSubmit={ onSearch } className="tbsh-row" style={ { flexGrow: 1, minWidth: '240px', flexWrap: 'nowrap' } } role="search">
					<label htmlFor="tbsh-session-search" className="screen-reader-text">{ __( 'Search users', 'tbsh-compliance-audit-logger' ) }</label>
					<input
						id="tbsh-session-search"
						type="search"
						className="tbsh-form-control"
						placeholder={ __( 'Search by username or name…', 'tbsh-compliance-audit-logger' ) }
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
						style={ { flex: 1, minWidth: 0 } }
					/>
					<button type="submit" className="tbsh-btn tbsh-btn-secondary">
						<Icon name="search" />
						{ __( 'Search', 'tbsh-compliance-audit-logger' ) }
					</button>
				</form>
				<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => load() } disabled={ loading }>
					{ __( 'Refresh', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>

			{ data && ! data.single_logout && (
				<Notice type="info" message={ __( 'Another plugin manages login sessions, so individual sessions cannot be ended here. You can still sign a user out everywhere.', 'tbsh-compliance-audit-logger' ) } />
			) }
			{ data && data.truncated && (
				<Notice type="info" message={ __( 'Only the first 500 users with sessions are shown. Use the search to find a specific user.', 'tbsh-compliance-audit-logger' ) } />
			) }

			{ loading && <SkeletonLoader rows={ 4 } cols={ 5 } /> }

			{ ! loading && sessions.length === 0 && (
				<EmptyState
					title={ __( 'No active sessions', 'tbsh-compliance-audit-logger' ) }
					description={ query ? __( 'No signed-in user matches your search.', 'tbsh-compliance-audit-logger' ) : __( 'Nobody is signed in right now.', 'tbsh-compliance-audit-logger' ) }
					icon="user"
				/>
			) }

			{ ! loading && sessions.length > 0 && (
				<div className="tbsh-table-container">
					<table className="tbsh-table">
						<caption className="screen-reader-text">{ __( 'Active login sessions', 'tbsh-compliance-audit-logger' ) }</caption>
						<thead>
							<tr>
								<th scope="col">{ __( 'User', 'tbsh-compliance-audit-logger' ) }</th>
								<th scope="col">{ __( 'Signed in', 'tbsh-compliance-audit-logger' ) }</th>
								<th scope="col">{ __( 'Expires', 'tbsh-compliance-audit-logger' ) }</th>
								<th scope="col">{ __( 'IP address', 'tbsh-compliance-audit-logger' ) }</th>
								<th scope="col">{ __( 'Browser', 'tbsh-compliance-audit-logger' ) }</th>
								<th scope="col">{ __( 'Actions', 'tbsh-compliance-audit-logger' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ sessions.map( ( s ) => (
								<tr key={ `${ s.user_id }-${ s.id }` }>
									<td>
										<strong>{ s.display_name || s.username }</strong>
										<div className="tbsh-muted" style={ { fontSize: '12px' } }>
											{ s.username }{ s.roles.length ? ` · ${ s.roles.join( ', ' ) }` : '' }
										</div>
										{ s.is_current && <span className="tbsh-badge tbsh-badge-info">{ __( 'This session', 'tbsh-compliance-audit-logger' ) }</span> }
									</td>
									<td>{ formatDate( s.login ) }</td>
									<td>{ formatDate( s.expiration ) }</td>
									<td><code>{ s.ip || '—' }</code></td>
									<td style={ { fontSize: '12px', maxWidth: '260px', wordBreak: 'break-word' } }>{ s.user_agent || '—' }</td>
									<td>
										{ s.can_end ? (
											<div className="tbsh-row" style={ { gap: '6px', flexWrap: 'wrap' } }>
												{ data.single_logout && ! s.is_current && (
													<button type="button" className="tbsh-btn tbsh-btn-danger tbsh-btn-sm" onClick={ () => setConfirm( { kind: 'one', session: s } ) }>
														{ __( 'End session', 'tbsh-compliance-audit-logger' ) }
													</button>
												) }
												<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => setConfirm( { kind: 'all', session: s } ) }>
													{ s.is_current ? __( 'Sign out my other devices', 'tbsh-compliance-audit-logger' ) : __( 'Sign out everywhere', 'tbsh-compliance-audit-logger' ) }
												</button>
											</div>
										) : (
											<span className="tbsh-muted" style={ { fontSize: '12px' } }>{ __( 'Not permitted', 'tbsh-compliance-audit-logger' ) }</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<Modal
				title={ confirm && confirm.kind === 'one' ? __( 'End this session?', 'tbsh-compliance-audit-logger' ) : __( 'Sign out everywhere?', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! confirm }
				onClose={ () => ! busy && setConfirm( null ) }
			>
				{ confirm && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ confirm.kind === 'one'
								? sprintf(
									/* translators: %s: username */
									__( '"%s" will be signed out on that device immediately.', 'tbsh-compliance-audit-logger' ),
									confirm.session.username
								)
								: sprintf(
									/* translators: %s: username */
									__( 'Every session of "%s" will end (except your own current session if this is you).', 'tbsh-compliance-audit-logger' ),
									confirm.session.username
								) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setConfirm( null ) } disabled={ busy }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ runConfirmed } disabled={ busy }>
								{ busy ? __( 'Ending…', 'tbsh-compliance-audit-logger' ) : __( 'Confirm', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
