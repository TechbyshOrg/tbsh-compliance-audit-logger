import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Notice from './Notice';
import Icon from './Icon';
import Modal from './Modal';
import EmptyState from './EmptyState';
import { get, post, can, isAuditor } from '../api';

const STATUS_BADGE = {
	ok: 'success',
	modified: 'danger',
	tampered: 'danger',
	baseline_created: 'info',
	error: 'warning',
};

const statusLabel = ( status ) => {
	switch ( status ) {
		case 'ok':
			return __( 'Unchanged', 'tbsh-compliance-audit-logger' );
		case 'modified':
			return __( 'Changed', 'tbsh-compliance-audit-logger' );
		case 'tampered':
			return __( 'Baseline tampered', 'tbsh-compliance-audit-logger' );
		case 'baseline_created':
			return __( 'Baseline recorded', 'tbsh-compliance-audit-logger' );
		default:
			return __( 'Could not check', 'tbsh-compliance-audit-logger' );
	}
};

const typeLabel = ( type ) => {
	switch ( type ) {
		case 'plugin':
			return __( 'Plugin', 'tbsh-compliance-audit-logger' );
		case 'theme':
			return __( 'Theme', 'tbsh-compliance-audit-logger' );
		case 'mu-plugins':
			return __( 'Must-use plugins', 'tbsh-compliance-audit-logger' );
		default:
			return __( 'Uploads', 'tbsh-compliance-audit-logger' );
	}
};

/**
 * File list (modified, missing, unexpected) for one component.
 *
 * @param {Object} props       Props.
 * @param {string} props.title Heading.
 * @param {Array}  props.files Files.
 * @param {number} props.count Total count.
 * @return {Element|null} List.
 */
function FileList( { title, files, count } ) {
	if ( ! count ) {
		return null;
	}
	return (
		<div style={ { marginTop: '8px' } }>
			<strong style={ { fontSize: '13px' } }>{ title } ({ count })</strong>
			<ul style={ { margin: '4px 0 0', paddingLeft: '18px', fontSize: '12px', fontFamily: 'monospace', wordBreak: 'break-all' } }>
				{ files.map( ( f ) => <li key={ f }>{ f }</li> ) }
				{ count > files.length && (
					<li>
						{ sprintf(
							/* translators: %d: number of further files */
							__( '…and %d more', 'tbsh-compliance-audit-logger' ),
							count - files.length
						) }
					</li>
				) }
			</ul>
		</div>
	);
}

export default function FileIntegrityPanel() {
	const [ status, setStatus ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ scanning, setScanning ] = useState( false );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ filter, setFilter ] = useState( 'attention' );
	const [ acceptTarget, setAcceptTarget ] = useState( null );
	const [ accepting, setAccepting ] = useState( false );
	const alive = useRef( true );

	const canScan = can( 'verify_integrity' ) && ! isAuditor();

	useEffect( () => {
		alive.current = true;
		get( '/file-integrity' )
			.then( ( data ) => alive.current && setStatus( data ) )
			.catch( ( err ) => alive.current && setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to load file verification results.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => alive.current && setLoading( false ) );
		return () => {
			alive.current = false;
		};
	}, [] );

	const step = ( first ) => post( '/file-integrity/scan', { step: first ? 'start' : 'continue' } ).then( ( data ) => {
		if ( ! alive.current ) {
			return null;
		}
		setStatus( data );
		if ( ! data.running ) {
			return data;
		}
		// Another batch holds the lock: wait before asking again.
		const delay = data.busy ? 3000 : 0;
		return new Promise( ( resolve ) => setTimeout( resolve, delay ) ).then( () => step( false ) );
	} );

	const runScan = () => {
		setScanning( true );
		setNotice( { type: '', message: '' } );
		step( true )
			.then( ( data ) => {
				if ( data && data.result ) {
					setNotice( data.result.status === 'ok'
						? { type: 'success', message: __( 'Scan complete: no unexpected file changes.', 'tbsh-compliance-audit-logger' ) }
						: { type: 'error', message: __( 'Scan complete: unexpected file changes were found. Review the components below.', 'tbsh-compliance-audit-logger' ) } );
				}
			} )
			.catch( ( err ) => alive.current && setNotice( { type: 'error', message: ( err && err.message ) || __( 'The scan failed.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => alive.current && setScanning( false ) );
	};

	const confirmAccept = () => {
		setAccepting( true );
		post( '/file-integrity/baseline', { type: acceptTarget.type, id: acceptTarget.id } )
			.then( ( data ) => {
				setStatus( data );
				setNotice( { type: 'success', message: data.message } );
			} )
			.catch( ( err ) => setNotice( { type: 'error', message: ( err && err.message ) || __( 'The baseline could not be updated.', 'tbsh-compliance-audit-logger' ) } ) )
			.finally( () => {
				setAccepting( false );
				setAcceptTarget( null );
			} );
	};

	const result = status && status.result;
	const components = ( result && result.components ) || [];
	const shown = filter === 'all' ? components : components.filter( ( c ) => c.status !== 'ok' );
	const progress = status && status.running && status.total
		? Math.round( ( ( status.total - status.remaining ) / status.total ) * 100 )
		: 0;

	return (
		<div className="tbsh-card" style={ { padding: '20px', marginTop: '24px' } }>
			<div className="tbsh-row-between" style={ { marginBottom: '12px', flexWrap: 'wrap', gap: '12px' } }>
				<div style={ { minWidth: 0 } }>
					<h3 style={ { margin: '0 0 6px 0', fontSize: '16px', fontWeight: '600' } }>
						{ __( 'Plugin, Theme & Uploads Files', 'tbsh-compliance-audit-logger' ) }
					</h3>
					<p className="tbsh-muted" style={ { margin: 0, fontSize: '13px' } }>
						{ __( 'Plugins from WordPress.org are compared with their official checksums. Other plugins, themes and must-use plugins are compared with a signed baseline recorded on the first scan and refreshed after updates. The uploads folder is searched for PHP files.', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
				{ canScan && (
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ runScan } disabled={ scanning || loading }>
						<Icon name="integrity" />
						{ scanning ? __( 'Scanning…', 'tbsh-compliance-audit-logger' ) : __( 'Scan files now', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>

			{ notice.message && <Notice type={ notice.type } message={ notice.message } /> }

			{ status && status.running && (
				<div role="status" aria-live="polite" style={ { margin: '12px 0' } }>
					<div style={ { fontSize: '13px', marginBottom: '4px' } }>
						{ sprintf(
							/* translators: 1: checked components, 2: total components */
							__( 'Checked %1$d of %2$d components…', 'tbsh-compliance-audit-logger' ),
							status.total - status.remaining,
							status.total
						) }
					</div>
					<div style={ { height: '8px', background: 'var(--tbsh-border-color)', borderRadius: '4px', overflow: 'hidden' } }>
						<div style={ { width: `${ progress }%`, height: '100%', background: 'var(--tbsh-primary)' } } />
					</div>
				</div>
			) }

			{ loading && <p className="tbsh-muted">{ __( 'Loading…', 'tbsh-compliance-audit-logger' ) }</p> }

			{ ! loading && ! result && ! ( status && status.running ) && (
				<EmptyState
					title={ __( 'No file scan yet', 'tbsh-compliance-audit-logger' ) }
					description={ __( 'Run the first scan to record baselines and check plugins against WordPress.org.', 'tbsh-compliance-audit-logger' ) }
					icon="integrity"
				/>
			) }

			{ result && (
				<>
					<div className="tbsh-row" style={ { gap: '16px', flexWrap: 'wrap', margin: '12px 0', fontSize: '13px' } }>
						<span className={ `tbsh-badge tbsh-badge-${ result.status === 'ok' ? 'success' : 'danger' }` }>
							{ result.status === 'ok' ? __( 'No unexpected changes', 'tbsh-compliance-audit-logger' ) : __( 'Changes found', 'tbsh-compliance-audit-logger' ) }
						</span>
						<span>
							{ sprintf(
								/* translators: 1: components, 2: files */
								__( '%1$d components, %2$d files checked', 'tbsh-compliance-audit-logger' ),
								result.totals.components,
								result.totals.checked
							) }
						</span>
						<span className="tbsh-muted">
							{ sprintf(
								/* translators: %s: date and time */
								__( 'Last scan: %s', 'tbsh-compliance-audit-logger' ),
								result.finished_at
							) }
						</span>
					</div>

					<div role="group" aria-label={ __( 'Show components', 'tbsh-compliance-audit-logger' ) } className="tbsh-row" style={ { gap: '8px', marginBottom: '8px' } }>
						<button type="button" className={ `tbsh-btn tbsh-btn-sm ${ filter === 'attention' ? 'tbsh-btn-primary' : 'tbsh-btn-secondary' }` } aria-pressed={ filter === 'attention' } onClick={ () => setFilter( 'attention' ) }>
							{ __( 'Needs attention', 'tbsh-compliance-audit-logger' ) }
						</button>
						<button type="button" className={ `tbsh-btn tbsh-btn-sm ${ filter === 'all' ? 'tbsh-btn-primary' : 'tbsh-btn-secondary' }` } aria-pressed={ filter === 'all' } onClick={ () => setFilter( 'all' ) }>
							{ __( 'All components', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>

					{ shown.length === 0 ? (
						<p className="tbsh-muted" style={ { fontSize: '13px' } }>{ __( 'Every component matches its reference.', 'tbsh-compliance-audit-logger' ) }</p>
					) : (
						<div className="tbsh-table-container" style={ { margin: 0 } }>
							<table className="tbsh-table">
								<thead>
									<tr>
										<th scope="col">{ __( 'Component', 'tbsh-compliance-audit-logger' ) }</th>
										<th scope="col">{ __( 'Reference', 'tbsh-compliance-audit-logger' ) }</th>
										<th scope="col">{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
										<th scope="col">{ __( 'Details', 'tbsh-compliance-audit-logger' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ shown.map( ( c ) => (
										<tr key={ `${ c.type }:${ c.id }` }>
											<td>
												<strong>{ c.name }</strong>
												<div className="tbsh-muted" style={ { fontSize: '12px' } }>
													{ typeLabel( c.type ) }{ c.version ? ` · ${ c.version }` : '' }
												</div>
											</td>
											<td style={ { fontSize: '13px' } }>
												{ c.source === 'wordpress.org' && __( 'WordPress.org checksums', 'tbsh-compliance-audit-logger' ) }
												{ c.source === 'baseline' && __( 'Local baseline', 'tbsh-compliance-audit-logger' ) }
												{ c.source === 'scan' && __( 'PHP file search', 'tbsh-compliance-audit-logger' ) }
											</td>
											<td>
												<span className={ `tbsh-badge tbsh-badge-${ STATUS_BADGE[ c.status ] || 'info' }` }>{ statusLabel( c.status ) }</span>
											</td>
											<td style={ { minWidth: '220px' } }>
												{ c.message && <div style={ { fontSize: '12px' } }>{ c.message }</div> }
												<FileList title={ __( 'Modified', 'tbsh-compliance-audit-logger' ) } files={ c.modified } count={ c.counts.modified } />
												<FileList title={ __( 'Missing', 'tbsh-compliance-audit-logger' ) } files={ c.missing } count={ c.counts.missing } />
												<FileList title={ c.type === 'uploads' ? __( 'PHP files in uploads', 'tbsh-compliance-audit-logger' ) : __( 'Unexpected files', 'tbsh-compliance-audit-logger' ) } files={ c.added } count={ c.counts.added } />
												{ canScan && c.source === 'baseline' && ( c.status === 'modified' || c.status === 'tampered' ) && (
													<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '8px' } } onClick={ () => setAcceptTarget( c ) }>
														{ __( 'Accept current files', 'tbsh-compliance-audit-logger' ) }
													</button>
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			) }

			<Modal
				title={ __( 'Accept current files as the baseline', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! acceptTarget }
				onClose={ () => ! accepting && setAcceptTarget( null ) }
			>
				{ acceptTarget && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: component name */
								__( 'Only do this if you made or approved the changes to "%s". Future scans will treat the current files as correct, and this decision is recorded in the audit log.', 'tbsh-compliance-audit-logger' ),
								acceptTarget.name
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setAcceptTarget( null ) } disabled={ accepting }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ confirmAccept } disabled={ accepting }>
								{ accepting ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Accept files', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
