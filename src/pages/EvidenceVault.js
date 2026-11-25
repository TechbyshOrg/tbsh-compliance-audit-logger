import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Modal from '../components/Modal';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import { get, post, put, del, can } from '../api';

function SnapshotsPanel() {
	const [snapshots, setSnapshots] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);
	const [selectedSnapshot, setSelectedSnapshot] = useState(null);
	const [selectedDetails, setSelectedDetails] = useState(null);
	const [detailsLoading, setDetailsLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });
	const [btnLoading, setBtnLoading] = useState(false);
	const [page, setPage] = useState(1);
	const [totalPages, setTotalPages] = useState(1);

	const caps = ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};
	const canCapture = !!caps.verify_integrity;

	const fetchSnapshots = () => {
		setLoading(true);
		setError(null);
		apiFetch({ path: `/tbsh-compliance-audit-logger/v1/evidence?page=${page}&per_page=20` })
			.then((data) => {
				const items = Array.isArray(data) ? data : ( data.items || [] );
				setSnapshots(items);
				setTotalPages(data.total_pages || 1);
				setLoading(false);
			})
			.catch((err) => {
				setError(err.message || __('Failed to load evidence snapshots.', 'tbsh-compliance-audit-logger'));
				setSnapshots([]);
				setLoading(false);
			});
	};

	useEffect(() => {
		fetchSnapshots();
	}, [page]); // eslint-disable-line react-hooks/exhaustive-deps

	const triggerSnapshot = () => {
		if ( ! canCapture ) {return;}
		setBtnLoading(true);
		setNotice({ type: '', message: '' });

		apiFetch({ 
			path: '/tbsh-compliance-audit-logger/v1/evidence',
			method: 'POST',
			data: { title: __('Manual Compliance Vault Entry', 'tbsh-compliance-audit-logger') }
		})
		.then((res) => {
			setNotice({ type: 'success', message: res.message });
			fetchSnapshots();
			setBtnLoading(false);
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to capture snapshot.', 'tbsh-compliance-audit-logger') });
			setBtnLoading(false);
		});
	};

	const inspectSnapshot = (id) => {
		setSelectedSnapshot(id);
		setDetailsLoading(true);
		setSelectedDetails(null);

		apiFetch({ path: `/tbsh-compliance-audit-logger/v1/evidence/${id}` })
			.then((data) => {
				setSelectedDetails(data);
				setDetailsLoading(false);
			})
			.catch(() => {
				setDetailsLoading(false);
			});
	};

	// Client-side export helper
	const exportSnapshot = (format) => {
		if ( ! selectedDetails ) {return;}

		const data = selectedDetails.snapshot_data;
		if ( 'json' === format ) {
			const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
			const url  = URL.createObjectURL(blob);
			const a    = document.createElement('a');
			a.href     = url;
			a.download = `evidence_snapshot_${selectedDetails.evidence_uuid}.json`;
			a.click();
			URL.revokeObjectURL(url);
		} else if ( 'csv' === format ) {
			// Create a summary CSV of plugins
			let csvContent = "Plugin Name,Version,Active Status,File Path\n";
			data.plugins.forEach(p => {
				csvContent += `"${p.name}","${p.version}","${p.is_active ? 'Active' : 'Inactive'}","${p.file}"\n`;
			});

			const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
			const url  = URL.createObjectURL(blob);
			const a    = document.createElement('a');
			a.href     = url;
			a.download = `evidence_plugins_${selectedDetails.evidence_uuid}.csv`;
			a.click();
			URL.revokeObjectURL(url);
		}
	};

	const renderDetails = () => {
		if (detailsLoading) {
			return (
				<SkeletonLoader rows={6} cols={3} />
			);
		}
		if (selectedDetails) {
			return (
				<div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
					<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
						<div>
							<strong>{__('Snapshot Name:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.evidence_title}
						</div>
						<div style={{ display: 'flex', gap: '8px' }}>
							<button className="tbsh-btn tbsh-btn-secondary" onClick={() => exportSnapshot('json')} style={{ padding: '6px 10px', fontSize: '12px' }}>
								<Icon name="download" />
								JSON
							</button>
							<button className="tbsh-btn tbsh-btn-secondary" onClick={() => exportSnapshot('csv')} style={{ padding: '6px 10px', fontSize: '12px' }}>
								<Icon name="download" />
								CSV (Plugins)
							</button>
						</div>
					</div>

					<div className="tbsh-grid-fields" style={{ padding: '12px', backgroundColor: 'var(--tbsh-bg-secondary)', borderRadius: '6px' }}>
						<div><strong>{__('WordPress Version:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.wp_version}</div>
						<div><strong>{__('PHP Version:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.php_version}</div>
						<div><strong>{__('Multisite Network:', 'tbsh-compliance-audit-logger')}</strong> {selectedDetails.snapshot_data.site_config.is_multisite ? __('Yes', 'tbsh-compliance-audit-logger') : __('No', 'tbsh-compliance-audit-logger')}</div>
						<div><strong>{__('DB Tables Checked:', 'tbsh-compliance-audit-logger')}</strong> {Object.keys(selectedDetails.snapshot_data.database.tables).length}</div>
					</div>

					{/* Plugins Snapshot Tabulation */}
					<div>
						<h5 style={{ margin: '0 0 8px 0', fontSize: '14px' }}>
							{__('Active Plugins Catalog', 'tbsh-compliance-audit-logger')} ({selectedDetails.snapshot_data.plugins.length})
						</h5>
						<div style={{ maxHeight: '200px', overflowY: 'auto', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
							<table className="tbsh-table" style={{ fontSize: '12px' }}>
								<thead>
									<tr>
										<th>{__('Plugin Name', 'tbsh-compliance-audit-logger')}</th>
										<th>{__('Version', 'tbsh-compliance-audit-logger')}</th>
										<th>{__('Status', 'tbsh-compliance-audit-logger')}</th>
									</tr>
								</thead>
								<tbody>
									{selectedDetails.snapshot_data.plugins.map((plug, idx) => (
										<tr key={idx}>
											<td>{plug.name}</td>
											<td><code>{plug.version}</code></td>
											<td>
												<span className={`tbsh-badge tbsh-badge-${plug.is_active ? 'success' : 'info'}`}>
													{plug.is_active ? 'Active' : 'Inactive'}
												</span>
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</div>
					</div>

					{/* Security Settings Snapshot */}
					<div>
						<h5 style={{ margin: '0 0 8px 0', fontSize: '14px' }}>
							{__('Security Config Snapshot', 'tbsh-compliance-audit-logger')}
						</h5>
						<div className="tbsh-grid-fields" style={{ gap: '8px', fontSize: '13px' }}>
							<div>
								<span>{__('WP Debug Active:', 'tbsh-compliance-audit-logger')} </span>
								<strong>{selectedDetails.snapshot_data.security.wp_debug ? 'TRUE' : 'FALSE'}</strong>
							</div>
							<div>
								<span>{__('Forced SSL Login:', 'tbsh-compliance-audit-logger')} </span>
								<strong>{selectedDetails.snapshot_data.security.force_ssl_admin ? 'TRUE' : 'FALSE'}</strong>
							</div>
							<div>
								<span>{__('Database Version:', 'tbsh-compliance-audit-logger')} </span>
								<strong>{selectedDetails.snapshot_data.security.db_version}</strong>
							</div>
							<div>
								<span>{__('SSL Active Connection:', 'tbsh-compliance-audit-logger')} </span>
								<strong>{selectedDetails.snapshot_data.security.is_ssl ? 'TRUE' : 'FALSE'}</strong>
							</div>
						</div>
					</div>
				</div>
			);
		}
		return null;
	};

	const renderSnapshots = () => {
		if (loading) {
			return (
				<SkeletonLoader rows={5} cols={4} />
			);
		}
		if (error) {
			return (
				<EmptyState title={__('Error Loading Data', 'tbsh-compliance-audit-logger')} description={error} icon="alert" />
			);
		}
		if (snapshots.length > 0) {
			return (
				<>
				<table className="tbsh-table">
					<thead>
						<tr>
							<th>{__('Snapshot ID', 'tbsh-compliance-audit-logger')}</th>
							<th>{__('Title', 'tbsh-compliance-audit-logger')}</th>
							<th>{__('Trigger Type', 'tbsh-compliance-audit-logger')}</th>
							<th>{__('Date Logged', 'tbsh-compliance-audit-logger')}</th>
							<th style={{ textAlign: 'end' }}>{__('Actions', 'tbsh-compliance-audit-logger')}</th>
						</tr>
					</thead>
					<tbody>
						{snapshots.map((snap) => (
							<tr key={snap.id}>
								<td><code>{snap.evidence_uuid}</code></td>
								<td><strong>{snap.evidence_title}</strong></td>
								<td>
									<span className={`tbsh-badge tbsh-badge-${snap.evidence_type === 'manual' ? 'warning' : 'info'}`}>
										{snap.evidence_type}
									</span>
								</td>
								<td>{snap.created_at}</td>
								<td style={{ textAlign: 'end' }}>
									<button 
										type="button"
										className="tbsh-btn tbsh-btn-secondary"
										onClick={() => inspectSnapshot(snap.id)}
										style={{ padding: '4px 8px', fontSize: '12px' }}
									>
										{__('Inspect', 'tbsh-compliance-audit-logger')}
									</button>
								</td>
							</tr>
						))}
					</tbody>
				</table>
				<div className="tbsh-pagination" style={{ marginTop: '16px' }}>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={page === 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
						{__('Previous', 'tbsh-compliance-audit-logger')}
					</button>
					<span style={{ fontSize: '13px', padding: '0 12px' }}>{page} / {totalPages}</span>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
						{__('Next', 'tbsh-compliance-audit-logger')}
					</button>
				</div>
				</>
			);
		}
		return (
			<EmptyState title={__('Evidence Vault is Empty', 'tbsh-compliance-audit-logger')} description={__('No state snapshots have been saved yet.', 'tbsh-compliance-audit-logger')} icon="evidence" />
		);
	};

	return (
		<div className="tbsh-evidence-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' }}>
				<h3 style={{ margin: 0, fontSize: '18px', fontWeight: '600' }}>
					{__('Evidence Snapshots', 'tbsh-compliance-audit-logger')}
				</h3>
				{canCapture && (
					<button 
						type="button"
						className="tbsh-btn tbsh-btn-primary" 
						disabled={btnLoading}
						onClick={triggerSnapshot}
					>
						<Icon name="shield" />
						{btnLoading ? __('Capturing…', 'tbsh-compliance-audit-logger') : __('Record State Snapshot', 'tbsh-compliance-audit-logger')}
					</button>
				)}
			</div>

			<div className="tbsh-table-container">
				{renderSnapshots()}
			</div>

			{/* Detail Inspector Modal */}
			<Modal 
				title={__('Evidence Snapshot Details', 'tbsh-compliance-audit-logger')}
				isOpen={!!selectedSnapshot}
				onClose={() => setSelectedSnapshot(null)}
			>
				{renderDetails()}
			</Modal>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Off-site vaults                                                      */
/* ------------------------------------------------------------------ */


const scheduleLabels = () => ( {
	hourly: __( 'Hourly', 'tbsh-compliance-audit-logger' ),
	daily: __( 'Daily', 'tbsh-compliance-audit-logger' ),
	weekly: __( 'Weekly', 'tbsh-compliance-audit-logger' ),
} );

const vaultStatusMeta = () => ( {
	healthy: { label: __( 'Healthy', 'tbsh-compliance-audit-logger' ), badge: 'success' },
	failing: { label: __( 'Failing', 'tbsh-compliance-audit-logger' ), badge: 'danger' },
	unknown: { label: __( 'Not synced yet', 'tbsh-compliance-audit-logger' ), badge: 'info' },
} );

const historyStatusMeta = () => ( {
	success: { label: __( 'Success', 'tbsh-compliance-audit-logger' ), badge: 'success' },
	failed: { label: __( 'Failed', 'tbsh-compliance-audit-logger' ), badge: 'danger' },
} );

/**
 * Provider field definitions. kind: text | number | checkbox | select | secret.
 */
const providerFields = () => ( {
	s3_compatible: [
		{ key: 'endpoint', kind: 'text', label: __( 'Endpoint URL', 'tbsh-compliance-audit-logger' ), placeholder: __( 'Leave empty for AWS S3, e.g. https://storage.googleapis.com, https://<account>.r2.cloudflarestorage.com', 'tbsh-compliance-audit-logger' ) },
		{ key: 'region', kind: 'text', label: __( 'Region', 'tbsh-compliance-audit-logger' ), placeholder: __( 'us-east-1 (use "auto" for Cloudflare R2)', 'tbsh-compliance-audit-logger' ) },
		{ key: 'bucket', kind: 'text', label: __( 'Bucket', 'tbsh-compliance-audit-logger' ), required: true },
		{ key: 'prefix', kind: 'text', label: __( 'Path prefix', 'tbsh-compliance-audit-logger' ), placeholder: __( 'Optional folder inside the bucket', 'tbsh-compliance-audit-logger' ) },
		{ key: 'path_style', kind: 'checkbox', label: __( 'Use path-style addressing (needed by MinIO and some S3-compatible services)', 'tbsh-compliance-audit-logger' ) },
		{ key: 'access_key', kind: 'secret', label: __( 'Access key ID', 'tbsh-compliance-audit-logger' ), required: true },
		{ key: 'secret_key', kind: 'secret', label: __( 'Secret access key', 'tbsh-compliance-audit-logger' ), required: true },
	],
	ftp: [
		{ key: 'host', kind: 'text', label: __( 'Host', 'tbsh-compliance-audit-logger' ), required: true },
		{ key: 'port', kind: 'number', label: __( 'Port', 'tbsh-compliance-audit-logger' ), placeholder: '21' },
		{ key: 'mode', kind: 'select', label: __( 'Connection mode', 'tbsh-compliance-audit-logger' ), options: [
			{ value: 'ftps', label: __( 'FTPS — FTP over TLS (recommended)', 'tbsh-compliance-audit-logger' ) },
			{ value: 'ftp', legacy: true, label: __( 'FTP — unencrypted (no longer supported)', 'tbsh-compliance-audit-logger' ) },
		] },
		{ key: 'passive', kind: 'checkbox', label: __( 'Use passive mode', 'tbsh-compliance-audit-logger' ) },
		{ key: 'path', kind: 'text', label: __( 'Remote folder', 'tbsh-compliance-audit-logger' ), placeholder: '/' },
		{ key: 'username', kind: 'text', label: __( 'Username', 'tbsh-compliance-audit-logger' ), required: true },
		{ key: 'password', kind: 'secret', label: __( 'Password', 'tbsh-compliance-audit-logger' ), required: true },
	],
	remote_site: [
		{ key: 'url', kind: 'text', label: __( 'Receiver URL of the other site', 'tbsh-compliance-audit-logger' ), placeholder: 'https://example.com/wp-json/tbsh-compliance-audit-logger/v1/vault-receiver', required: true },
		{ key: 'token', kind: 'secret', label: __( 'Receiver token', 'tbsh-compliance-audit-logger' ), required: true },
	],
} );

const defaultConfig = ( type ) => {
	switch ( type ) {
		case 'ftp':
			return { host: '', port: '21', mode: 'ftps', passive: true, path: '', username: '' };
		case 'remote_site':
			return { url: '' };
		default:
			return { endpoint: '', region: '', bucket: '', prefix: '', path_style: false };
	}
};

const humanSize = ( bytes ) => {
	const n = parseInt( bytes, 10 );
	if ( ! n ) {
		return '—';
	}
	if ( n < 1024 ) {
		/* translators: %d: size in bytes */
		return sprintf( __( '%d B', 'tbsh-compliance-audit-logger' ), n );
	}
	if ( n < 1024 * 1024 ) {
		/* translators: %s: size in kilobytes */
		return sprintf( __( '%s KB', 'tbsh-compliance-audit-logger' ), ( n / 1024 ).toFixed( 1 ) );
	}
	if ( n < 1024 * 1024 * 1024 ) {
		/* translators: %s: size in megabytes */
		return sprintf( __( '%s MB', 'tbsh-compliance-audit-logger' ), ( n / ( 1024 * 1024 ) ).toFixed( 1 ) );
	}
	/* translators: %s: size in gigabytes */
	return sprintf( __( '%s GB', 'tbsh-compliance-audit-logger' ), ( n / ( 1024 * 1024 * 1024 ) ).toFixed( 2 ) );
};

function VaultForm( { vault, types, onSaved, onCancel } ) {
	const isEdit = !! vault;
	const typeKeys = Object.keys( types );
	const firstAvailable = typeKeys.find( ( k ) => types[ k ].available ) || typeKeys[ 0 ] || 's3_compatible';

	const initialType = isEdit ? vault.vault_type : firstAvailable;
	const initialConfig = () => {
		const base = defaultConfig( initialType );
		if ( ! isEdit ) {
			return base;
		}
		const out = { ...base };
		Object.keys( vault.config || {} ).forEach( ( k ) => {
			const v = vault.config[ k ];
			if ( 'path_style' === k || 'passive' === k ) {
				out[ k ] = '1' === v || true === v;
			} else {
				out[ k ] = v === null ? '' : String( v );
			}
		} );
		return out;
	};

	const [ vaultType, setVaultType ] = useState( initialType );
	const [ label, setLabel ] = useState( isEdit ? ( vault.label || '' ) : '' );
	const [ schedule, setSchedule ] = useState( isEdit ? ( vault.schedule || 'daily' ) : 'daily' );
	const [ isActive, setIsActive ] = useState( isEdit ? !! vault.is_active : true );
	const [ config, setConfig ] = useState( initialConfig );
	const [ secrets, setSecrets ] = useState( {} );
	const [ clear, setClear ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ formError, setFormError ] = useState( '' );

	const storedSecrets = ( isEdit && vault.secrets ) || {};
	const fields = providerFields()[ vaultType ] || [];
	const schedules = scheduleLabels();

	const changeType = ( t ) => {
		setVaultType( t );
		setConfig( defaultConfig( t ) );
		setSecrets( {} );
		setClear( [] );
	};

	const setCfg = ( k, v ) => setConfig( ( c ) => ( { ...c, [ k ]: v } ) );
	const setSecret = ( k, v ) => setSecrets( ( s ) => ( { ...s, [ k ]: v } ) );
	const toggleClear = ( k ) => {
		setClear( ( c ) => ( c.includes( k ) ? c.filter( ( x ) => x !== k ) : [ ...c, k ] ) );
		setSecret( k, '' );
	};

	const secretIsSet = ( k ) => !! ( storedSecrets[ k ] && storedSecrets[ k ].set ) && ! clear.includes( k );

	const submit = ( e ) => {
		e.preventDefault();
		setFormError( '' );

		if ( ! label.trim() ) {
			setFormError( __( 'Enter a label for this vault.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		if ( ! isEdit && types[ vaultType ] && ! types[ vaultType ].available ) {
			setFormError( __( 'This storage provider is not available on this server.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		if ( ! secretIsSet( 'passphrase' ) && ! ( secrets.passphrase || '' ).trim() ) {
			setFormError( __( 'An encryption passphrase is required.', 'tbsh-compliance-audit-logger' ) );
			return;
		}
		if ( isActive ) {
			const missing = fields.filter( ( f ) => f.required && ( 'secret' === f.kind
				? ! secretIsSet( f.key ) && ! ( secrets[ f.key ] || '' ).trim()
				: ! String( config[ f.key ] || '' ).trim() ) );
			if ( missing.length ) {
				setFormError( sprintf(
					/* translators: %s: comma-separated list of field names */
					__( 'These fields are required to activate the vault: %s', 'tbsh-compliance-audit-logger' ),
					missing.map( ( f ) => f.label ).join( ', ' )
				) );
				return;
			}
		}

		const cfg = {};
		fields.forEach( ( f ) => {
			if ( 'secret' === f.kind ) {
				return;
			}
			cfg[ f.key ] = 'checkbox' === f.kind ? !! config[ f.key ] : String( config[ f.key ] === undefined ? '' : config[ f.key ] );
		} );
		[ ...fields.filter( ( f ) => 'secret' === f.kind ).map( ( f ) => f.key ), 'passphrase' ].forEach( ( k ) => {
			const v = ( secrets[ k ] || '' ).trim();
			if ( v && ! clear.includes( k ) ) {
				cfg[ k ] = v;
			}
		} );

		const body = { label: label.trim(), is_active: isActive, schedule, config: cfg };
		if ( clear.length ) {
			body.clear = clear;
		}

		setSaving( true );
		const req = isEdit ? put( `/vaults/${ vault.id }`, body ) : post( '/vaults', { ...body, vault_type: vaultType } );
		req.then( () => {
			setSaving( false );
			onSaved( isEdit ? __( 'Vault updated.', 'tbsh-compliance-audit-logger' ) : __( 'Vault added.', 'tbsh-compliance-audit-logger' ) );
		} ).catch( ( err ) => {
			setSaving( false );
			setFormError( ( err && err.message ) || __( 'Failed to save the vault.', 'tbsh-compliance-audit-logger' ) );
		} );
	};

	const renderSecret = ( key, labelText, required ) => {
		const stored = storedSecrets[ key ];
		const markedClear = clear.includes( key );
		const id = `tbsh-vault-${ key }`;
		return (
			<div className="tbsh-form-group" key={ key }>
				<label className="tbsh-form-label" htmlFor={ id }>
					{ labelText }
					{ required ? ` ${ __( '(required)', 'tbsh-compliance-audit-logger' ) }` : '' }
				</label>
				<input
					id={ id }
					type="password"
					autoComplete="new-password"
					className="tbsh-form-control"
					value={ secrets[ key ] || '' }
					disabled={ markedClear }
					placeholder={ stored && stored.set && ! markedClear
						? sprintf(
							/* translators: %s: masked hint of the stored secret */
							__( 'Stored (%s) — leave empty to keep', 'tbsh-compliance-audit-logger' ),
							stored.hint || '••••'
						)
						: '' }
					onChange={ ( e ) => setSecret( key, e.target.value ) }
				/>
				{ stored && stored.unreadable && (
					<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
						{ __( 'The stored value could not be decrypted. Please re-enter it.', 'tbsh-compliance-audit-logger' ) }
					</span>
				) }
				{ stored && stored.set && 'passphrase' !== key && (
					<div className="tbsh-row" style={ { marginTop: '4px' } }>
						{ markedClear && <span className="tbsh-muted" style={ { fontSize: '12px' } }>{ __( 'Will be removed when you save.', 'tbsh-compliance-audit-logger' ) }</span> }
						<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ () => toggleClear( key ) }>
							{ markedClear ? __( 'Undo remove', 'tbsh-compliance-audit-logger' ) : __( 'Remove stored value', 'tbsh-compliance-audit-logger' ) }
						</button>
					</div>
				) }
			</div>
		);
	};

	const renderField = ( f ) => {
		const id = `tbsh-vault-${ f.key }`;
		if ( 'secret' === f.kind ) {
			return renderSecret( f.key, f.label, f.required );
		}
		if ( 'checkbox' === f.kind ) {
			return (
				<div className="tbsh-form-group" key={ f.key }>
					<label htmlFor={ id } style={ { display: 'flex', alignItems: 'center', gap: '10px', cursor: 'pointer' } }>
						<input id={ id } type="checkbox" checked={ !! config[ f.key ] } onChange={ ( e ) => setCfg( f.key, e.target.checked ) } />
						{ f.label }
					</label>
				</div>
			);
		}
		if ( 'select' === f.kind ) {
			return (
				<div className="tbsh-form-group" key={ f.key }>
					<label className="tbsh-form-label" htmlFor={ id }>{ f.label }</label>
					<select id={ id } className="tbsh-form-control" value={ config[ f.key ] || '' } onChange={ ( e ) => setCfg( f.key, e.target.value ) }>
						{ f.options
							.filter( ( o ) => ! o.legacy || config[ f.key ] === o.value || ( window.tbshCalApiSettings && window.tbshCalApiSettings.allowPlainFtp ) )
							.map( ( o ) => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
					</select>
					{ 'mode' === f.key && 'ftp' === config.mode && (
						<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
							{ __( 'Plain FTP sends your FTP username and password unencrypted, so it is no longer supported. Choose FTPS and save. The backup packages themselves were always encrypted with your passphrase.', 'tbsh-compliance-audit-logger' ) }
						</span>
					) }
				</div>
			);
		}
		let inputType = 'text';
		if ( 'number' === f.kind ) {
			inputType = 'number';
		} else if ( 'url' === f.key || 'endpoint' === f.key ) {
			inputType = 'url';
		}
		return (
			<div className="tbsh-form-group" key={ f.key }>
				<label className="tbsh-form-label" htmlFor={ id }>
					{ f.label }
					{ f.required ? ` ${ __( '(required)', 'tbsh-compliance-audit-logger' ) }` : '' }
				</label>
				<input
					id={ id }
					type={ inputType }
					className="tbsh-form-control"
					value={ config[ f.key ] === undefined ? '' : config[ f.key ] }
					placeholder={ f.placeholder || '' }
					min={ 'number' === f.kind ? 1 : undefined }
					max={ 'number' === f.kind ? 65535 : undefined }
					onChange={ ( e ) => setCfg( f.key, e.target.value ) }
				/>
			</div>
		);
	};

	return (
		<form onSubmit={ submit } className="tbsh-stack">
			{ formError && <Notice type="error" message={ formError } /> }

			<div className="tbsh-form-group">
				<label className="tbsh-form-label" htmlFor="tbsh-vault-type">{ __( 'Storage provider', 'tbsh-compliance-audit-logger' ) }</label>
				<select
					id="tbsh-vault-type"
					className="tbsh-form-control"
					value={ vaultType }
					disabled={ isEdit }
					onChange={ ( e ) => changeType( e.target.value ) }
				>
					{ typeKeys.map( ( k ) => (
						<option key={ k } value={ k } disabled={ ! types[ k ].available && ! ( isEdit && k === vaultType ) }>
							{ types[ k ].available
								? types[ k ].label
								: sprintf(
									/* translators: %s: provider name */
									__( '%s (not available on this server)', 'tbsh-compliance-audit-logger' ),
									types[ k ].label
								) }
						</option>
					) ) }
				</select>
				{ isEdit && (
					<span className="tbsh-muted" style={ { display: 'block', fontSize: '12px', marginTop: '4px' } }>
						{ __( 'The provider cannot be changed after creation. Add a new vault to use a different provider.', 'tbsh-compliance-audit-logger' ) }
					</span>
				) }
			</div>

			<div className="tbsh-grid-2">
				<div className="tbsh-form-group">
					<label className="tbsh-form-label" htmlFor="tbsh-vault-label">{ __( 'Label (required)', 'tbsh-compliance-audit-logger' ) }</label>
					<input id="tbsh-vault-label" type="text" className="tbsh-form-control" value={ label } required maxLength={ 191 } onChange={ ( e ) => setLabel( e.target.value ) } />
				</div>
				<div className="tbsh-form-group">
					<label className="tbsh-form-label" htmlFor="tbsh-vault-schedule">{ __( 'Schedule', 'tbsh-compliance-audit-logger' ) }</label>
					<select id="tbsh-vault-schedule" className="tbsh-form-control" value={ schedule } onChange={ ( e ) => setSchedule( e.target.value ) }>
						{ Object.keys( schedules ).map( ( k ) => <option key={ k } value={ k }>{ schedules[ k ] }</option> ) }
					</select>
				</div>
			</div>

			<div className="tbsh-form-group">
				<label htmlFor="tbsh-vault-active" style={ { display: 'flex', alignItems: 'center', gap: '10px', cursor: 'pointer', fontWeight: 600 } }>
					<input id="tbsh-vault-active" type="checkbox" checked={ isActive } onChange={ ( e ) => setIsActive( e.target.checked ) } />
					{ __( 'Active (run scheduled backups)', 'tbsh-compliance-audit-logger' ) }
				</label>
			</div>

			<h4 className="tbsh-section-title">{ __( 'Connection', 'tbsh-compliance-audit-logger' ) }</h4>
			{ fields.map( renderField ) }

			<h4 className="tbsh-section-title">{ __( 'Encryption', 'tbsh-compliance-audit-logger' ) }</h4>
			<Notice
				type="warning"
				message={ __( 'Every package is encrypted with this passphrase before it leaves the site. You need it to decrypt and restore your backups. It is never sent to the storage provider and cannot be recovered — if you lose it, your off-site backups are unreadable. Store it in a password manager.', 'tbsh-compliance-audit-logger' ) }
			/>
			{ renderSecret( 'passphrase', __( 'Encryption passphrase', 'tbsh-compliance-audit-logger' ), true ) }

			<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
				<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ saving } onClick={ onCancel }>
					{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
				</button>
				<button type="submit" className="tbsh-btn tbsh-btn-primary" disabled={ saving }>
					{ saving ? __( 'Saving…', 'tbsh-compliance-audit-logger' ) : __( 'Save vault', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>
		</form>
	);
}

function VaultsPanel() {
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ vaults, setVaults ] = useState( [] );
	const [ types, setTypes ] = useState( {} );
	const [ receiver, setReceiver ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const [ formOpen, setFormOpen ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const [ toDelete, setToDelete ] = useState( null );
	const [ deleting, setDeleting ] = useState( false );
	const [ busy, setBusy ] = useState( {} );

	const [ history, setHistory ] = useState( [] );
	const [ historyLoading, setHistoryLoading ] = useState( true );
	const [ historyError, setHistoryError ] = useState( null );
	const [ historyPage, setHistoryPage ] = useState( 1 );
	const [ historyPages, setHistoryPages ] = useState( 1 );

	const fetchVaults = () => {
		setLoading( true );
		setError( null );
		get( '/vaults' )
			.then( ( data ) => {
				setVaults( ( data && data.items ) || [] );
				setTypes( ( data && data.types ) || {} );
				setReceiver( ( data && data.receiver ) || null );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load vaults.', 'tbsh-compliance-audit-logger' ) );
				setVaults( [] );
				setLoading( false );
			} );
	};

	const fetchHistory = () => {
		setHistoryLoading( true );
		setHistoryError( null );
		get( '/vaults/history', { page: historyPage, per_page: 20 } )
			.then( ( data ) => {
				setHistory( ( data && data.items ) || [] );
				setHistoryPages( ( data && data.total_pages ) || 1 );
				setHistoryLoading( false );
			} )
			.catch( ( err ) => {
				setHistoryError( ( err && err.message ) || __( 'Failed to load sync history.', 'tbsh-compliance-audit-logger' ) );
				setHistory( [] );
				setHistoryLoading( false );
			} );
	};

	useEffect( () => {
		fetchVaults();
	}, [] );

	useEffect( () => {
		fetchHistory();
	}, [ historyPage ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const setBusyFor = ( id, action ) => setBusy( ( b ) => ( { ...b, [ id ]: action } ) );

	const testVault = ( v ) => {
		setBusyFor( v.id, 'test' );
		setNotice( { type: '', message: '' } );
		post( `/vaults/${ v.id }/test` )
			.then( ( res ) => {
				setBusyFor( v.id, '' );
				setNotice( {
					type: res && res.ok ? 'success' : 'error',
					message: sprintf(
						/* translators: 1: vault label, 2: result message */
						__( '%1$s: %2$s', 'tbsh-compliance-audit-logger' ),
						v.label,
						( res && res.message ) || ( res && res.ok ? __( 'Connection succeeded.', 'tbsh-compliance-audit-logger' ) : __( 'Connection failed.', 'tbsh-compliance-audit-logger' ) )
					),
				} );
			} )
			.catch( ( err ) => {
				setBusyFor( v.id, '' );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Connection test failed.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const syncVault = ( v ) => {
		setBusyFor( v.id, 'sync' );
		setNotice( { type: '', message: '' } );
		post( `/vaults/${ v.id }/sync` )
			.then( ( res ) => {
				setBusyFor( v.id, '' );
				let message = ( res && res.message ) || '';
				if ( res && res.ok && res.size ) {
					message += ' ' + sprintf(
						/* translators: %s: package size */
						__( 'Package size: %s.', 'tbsh-compliance-audit-logger' ),
						humanSize( res.size )
					);
				}
				if ( res && res.counts && 'object' === typeof res.counts ) {
					const parts = Object.keys( res.counts ).map( ( k ) => `${ k }: ${ res.counts[ k ] }` );
					if ( parts.length ) {
						message += ' (' + parts.join( ', ' ) + ')';
					}
				}
				setNotice( {
					type: res && res.ok ? 'success' : 'error',
					message: message.trim() || ( res && res.ok ? __( 'Sync completed.', 'tbsh-compliance-audit-logger' ) : __( 'Sync failed.', 'tbsh-compliance-audit-logger' ) ),
				} );
				fetchVaults();
				if ( 1 === historyPage ) {
					fetchHistory();
				} else {
					setHistoryPage( 1 );
				}
			} )
			.catch( ( err ) => {
				setBusyFor( v.id, '' );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Sync failed.', 'tbsh-compliance-audit-logger' ) } );
				fetchVaults();
			} );
	};

	const confirmDelete = () => {
		if ( ! toDelete ) {
			return;
		}
		setDeleting( true );
		del( `/vaults/${ toDelete.id }` )
			.then( () => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'success', message: __( 'Vault removed. Files already uploaded to the remote storage were not deleted.', 'tbsh-compliance-audit-logger' ) } );
				fetchVaults();
				fetchHistory();
			} )
			.catch( ( err ) => {
				setDeleting( false );
				setToDelete( null );
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to remove the vault.', 'tbsh-compliance-audit-logger' ) } );
			} );
	};

	const openCreate = () => {
		setEditing( null );
		setFormOpen( true );
	};
	const openEdit = ( v ) => {
		setEditing( v );
		setFormOpen( true );
	};
	const onSaved = ( msg ) => {
		setFormOpen( false );
		setEditing( null );
		setNotice( { type: 'success', message: msg } );
		fetchVaults();
	};

	const statuses = vaultStatusMeta();
	const histStatuses = historyStatusMeta();
	const schedules = scheduleLabels();
	const vaultLabel = ( id ) => {
		const v = vaults.find( ( x ) => String( x.id ) === String( id ) );
		/* translators: %d: vault id */
		return v ? v.label : sprintf( __( 'Vault #%d', 'tbsh-compliance-audit-logger' ), parseInt( id, 10 ) );
	};
	const typeLabel = ( t ) => ( types[ t ] && types[ t ].label ) || t;

	const renderHistory = () => {
		if ( historyLoading ) {
			return (
				<SkeletonLoader rows={ 4 } cols={ 6 } />
			);
		}
		if ( historyError ) {
			return (
				<EmptyState
					title={ __( 'Error loading sync history', 'tbsh-compliance-audit-logger' ) }
					description={ (
						<>
							{ historyError }
							<br />
							<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '12px' } } onClick={ fetchHistory }>
								{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
							</button>
						</>
					) }
					icon="alert"
				/>
			);
		}
		if ( history.length ) {
			return (
				<>
					<table className="tbsh-table">
						<thead>
							<tr>
								<th>{ __( 'Date', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Vault', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Status', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Size', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Remote file', 'tbsh-compliance-audit-logger' ) }</th>
								<th>{ __( 'Details', 'tbsh-compliance-audit-logger' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ history.map( ( h ) => {
								const hs = histStatuses[ h.status ] || { label: h.status, badge: 'info' };
								return (
									<tr key={ h.id }>
										<td>{ h.created_at }</td>
										<td>{ vaultLabel( h.vault_id ) }</td>
										<td><span className={ `tbsh-badge tbsh-badge-${ hs.badge }` }>{ hs.label }</span></td>
										<td>{ humanSize( h.file_size ) }</td>
										<td>{ h.remote_path ? <code style={ { wordBreak: 'break-all' } }>{ h.remote_path }</code> : '—' }</td>
										<td style={ { fontSize: '12px', wordBreak: 'break-word' } }>{ h.details || '—' }</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
					{ historyPages > 1 && (
						<div className="tbsh-pagination" style={ { marginTop: '16px' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ historyPage === 1 } onClick={ () => setHistoryPage( ( p ) => Math.max( 1, p - 1 ) ) }>
								{ __( 'Previous', 'tbsh-compliance-audit-logger' ) }
							</button>
							<span style={ { fontSize: '13px', padding: '0 12px' } }>{ historyPage } / { historyPages }</span>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ historyPage >= historyPages } onClick={ () => setHistoryPage( ( p ) => p + 1 ) }>
								{ __( 'Next', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					) }
				</>
			);
		}
		return (
			<EmptyState
				title={ __( 'No syncs yet', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'Sync runs appear here after a scheduled or manual backup.', 'tbsh-compliance-audit-logger' ) }
				icon="timeline"
			/>
		);
	};

	const renderVaults = () => {
		if ( loading ) {
			return (
				<SkeletonLoader rows={ 3 } cols={ 3 } />
			);
		}
		if ( error ) {
			return (
				<EmptyState
					title={ __( 'Error loading vaults', 'tbsh-compliance-audit-logger' ) }
					description={ (
						<>
							{ error }
							<br />
							<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" style={ { marginTop: '12px' } } onClick={ fetchVaults }>
								{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
							</button>
						</>
					) }
					icon="alert"
				/>
			);
		}
		if ( vaults.length ) {
			return (
				<div className="tbsh-grid-2">
					{ vaults.map( ( v ) => {
						const s = statuses[ v.status ] || { label: v.status, badge: 'info' };
						const action = busy[ v.id ] || '';
						return (
							<div key={ v.id } className="tbsh-card" style={ { padding: '16px' } }>
								<div className="tbsh-row-between" style={ { marginBottom: '8px' } }>
									<strong>{ v.label || sprintf( /* translators: %d: vault id */ __( 'Vault #%d', 'tbsh-compliance-audit-logger' ), v.id ) }</strong>
									<div className="tbsh-row">
										<span className={ `tbsh-badge tbsh-badge-${ s.badge }` }>{ s.label }</span>
										{ ! v.is_active && <span className="tbsh-badge tbsh-badge-warning">{ __( 'Inactive', 'tbsh-compliance-audit-logger' ) }</span> }
									</div>
								</div>
								<dl className="tbsh-kv">
									<dt>{ __( 'Provider', 'tbsh-compliance-audit-logger' ) }</dt>
									<dd>{ typeLabel( v.vault_type ) }</dd>
									<dt>{ __( 'Destination', 'tbsh-compliance-audit-logger' ) }</dt>
									<dd><code>{ v.endpoint || '—' }</code></dd>
									<dt>{ __( 'Schedule', 'tbsh-compliance-audit-logger' ) }</dt>
									<dd>{ schedules[ v.schedule ] || v.schedule }</dd>
									<dt>{ __( 'Last successful sync', 'tbsh-compliance-audit-logger' ) }</dt>
									<dd>{ v.last_sync_at || __( 'Never', 'tbsh-compliance-audit-logger' ) }</dd>
								</dl>
								{ v.last_error && (
									<div style={ { marginTop: '12px' } }>
										<Notice type="error" message={ v.last_error } />
									</div>
								) }
								{ ! v.credentials_ok && (
									<Notice type="warning" message={ __( 'The stored credentials could not be decrypted (for example after the site\'s security keys changed). Edit this vault and re-enter the credentials and passphrase.', 'tbsh-compliance-audit-logger' ) } />
								) }
								{ ! v.supported && (
									<Notice type="warning" message={ __( 'This vault uses a storage provider that is not supported by this version of the plugin.', 'tbsh-compliance-audit-logger' ) } />
								) }
								<div className="tbsh-row" style={ { marginTop: '12px' } }>
									<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" disabled={ !! action || ! v.supported } onClick={ () => testVault( v ) }>
										<Icon name="health" />
										{ 'test' === action ? __( 'Testing…', 'tbsh-compliance-audit-logger' ) : __( 'Test connection', 'tbsh-compliance-audit-logger' ) }
									</button>
									<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" disabled={ !! action || ! v.supported } onClick={ () => syncVault( v ) }>
										<Icon name="database" />
										{ 'sync' === action ? __( 'Syncing…', 'tbsh-compliance-audit-logger' ) : __( 'Sync now', 'tbsh-compliance-audit-logger' ) }
									</button>
									<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" disabled={ !! action || ! v.supported } onClick={ () => openEdit( v ) }>
										<Icon name="edit" />
										{ __( 'Edit', 'tbsh-compliance-audit-logger' ) }
									</button>
									<button type="button" className="tbsh-btn tbsh-btn-danger tbsh-btn-sm" disabled={ !! action } onClick={ () => setToDelete( v ) }>
										<Icon name="trash" />
										{ __( 'Delete', 'tbsh-compliance-audit-logger' ) }
									</button>
								</div>
							</div>
						);
					} ) }
				</div>
			);
		}
		return (
			<EmptyState
				title={ __( 'No off-site vaults', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'Add an S3-compatible bucket, an FTP/FTPS server or another WordPress site to keep encrypted copies of your audit trail off this server.', 'tbsh-compliance-audit-logger' ) }
				icon="database"
			/>
		);
	};

	return (
		<div className="tbsh-vaults-panel">
			<Notice type={ notice.type } message={ notice.message } />

			<div className="tbsh-card" style={ { padding: '20px', marginBottom: '20px' } }>
				<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
					<h3 className="tbsh-section-title" style={ { margin: 0 } }>{ __( 'Off-site vaults', 'tbsh-compliance-audit-logger' ) }</h3>
					<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ openCreate } disabled={ loading || !! error }>
						<Icon name="plus" />
						{ __( 'Add vault', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
				<p className="tbsh-muted" style={ { marginTop: 0 } }>
					{ __( 'Vaults copy encrypted packages of your audit logs and evidence to storage outside this site, so a compromised or lost server does not take your audit trail with it.', 'tbsh-compliance-audit-logger' ) }
				</p>

				{ renderVaults() }
			</div>

			<div className="tbsh-card" style={ { padding: '20px', marginBottom: '20px' } }>
				<h3 className="tbsh-section-title">{ __( 'Sync history', 'tbsh-compliance-audit-logger' ) }</h3>
				<div className="tbsh-table-container">
					{ renderHistory() }
				</div>
			</div>

			<div className="tbsh-grid-2">
				<div className="tbsh-card" style={ { padding: '20px' } }>
					<h3 className="tbsh-section-title">{ __( 'Receive backups from another site', 'tbsh-compliance-audit-logger' ) }</h3>
					{ receiver ? (
						<div className="tbsh-stack">
							<div className="tbsh-row">
								<span>{ __( 'Receiving:', 'tbsh-compliance-audit-logger' ) }</span>
								{ receiver.enabled ? (
									<span className="tbsh-badge tbsh-badge-success">{ __( 'Enabled', 'tbsh-compliance-audit-logger' ) }</span>
								) : (
									<span className="tbsh-badge tbsh-badge-warning">{ __( 'Disabled', 'tbsh-compliance-audit-logger' ) }</span>
								) }
							</div>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-vault-receiver-url">{ __( 'This site\'s receiver URL', 'tbsh-compliance-audit-logger' ) }</label>
								<input id="tbsh-vault-receiver-url" type="text" className="tbsh-form-control" readOnly value={ receiver.url || '' } onFocus={ ( e ) => e.target.select() } />
							</div>
							<p className="tbsh-muted" style={ { margin: 0 } }>
								{ __( 'Another site running this plugin can use this URL in an "Another WordPress site" vault to store its encrypted packages here. Enable receiving and set the receiver token in Settings; enter the same token on the sending site.', 'tbsh-compliance-audit-logger' ) }
							</p>
						</div>
					) : (
						<p className="tbsh-muted" style={ { margin: 0 } }>{ loading ? __( 'Loading…', 'tbsh-compliance-audit-logger' ) : '—' }</p>
					) }
				</div>
				<div className="tbsh-card" style={ { padding: '20px' } }>
					<h3 className="tbsh-section-title">{ __( 'Restoring from a backup', 'tbsh-compliance-audit-logger' ) }</h3>
					<div className="tbsh-stack">
						<p className="tbsh-muted" style={ { margin: 0 } }>
							{ __( 'Download the package from your storage, then decrypt it with the vault passphrase using either command:', 'tbsh-compliance-audit-logger' ) }
						</p>
						<pre style={ { margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' } }><code>wp tbsh-cal vault-decrypt &lt;file&gt;</code></pre>
						<pre style={ { margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' } }><code>php decrypt-vault.php &lt;file&gt;</code></pre>
						<p className="tbsh-muted" style={ { margin: 0 } }>
							{ __( 'The second command uses the standalone decryptor, which does not need WordPress, so it works even if this site is gone. It needs PHP with the sodium extension.', 'tbsh-compliance-audit-logger' ) }
							{ ' ' }
							<a href="https://github.com/TechbyshOrg/tbsh-compliance-audit-logger/tree/main/tools" target="_blank" rel="noopener noreferrer">
								{ __( 'Download decrypt-vault.php from the plugin’s GitHub repository', 'tbsh-compliance-audit-logger' ) }
								<span className="tbsh-sr-only">{ __( '(opens in a new tab)', 'tbsh-compliance-audit-logger' ) }</span>
							</a>
						</p>
					</div>
				</div>
			</div>

			<Modal
				title={ editing ? __( 'Edit vault', 'tbsh-compliance-audit-logger' ) : __( 'Add vault', 'tbsh-compliance-audit-logger' ) }
				isOpen={ formOpen }
				onClose={ () => setFormOpen( false ) }
			>
				{ formOpen && (
					<VaultForm
						key={ editing ? `edit-${ editing.id }` : 'new' }
						vault={ editing }
						types={ types }
						onSaved={ onSaved }
						onCancel={ () => setFormOpen( false ) }
					/>
				) }
			</Modal>

			<Modal
				title={ __( 'Delete vault', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! toDelete }
				onClose={ () => ! deleting && setToDelete( null ) }
			>
				{ toDelete && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: vault label */
								__( 'Delete the vault "%s"? Its stored credentials and sync history on this site are removed. Packages already uploaded to the remote storage are not deleted.', 'tbsh-compliance-audit-logger' ),
								toDelete.label
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" disabled={ deleting } onClick={ () => setToDelete( null ) }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" disabled={ deleting } onClick={ confirmDelete }>
								{ deleting ? __( 'Deleting…', 'tbsh-compliance-audit-logger' ) : __( 'Delete vault', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}

export default function EvidenceVault() {
	const showVaults = can( 'manage_vaults' );
	const [ tab, setTab ] = useState( 'snapshots' );

	if ( ! showVaults ) {
		return <SnapshotsPanel />;
	}

	return (
		<div className="tbsh-evidence-vault">
			<div className="tbsh-tabs" role="tablist">
				<button
					type="button"
					role="tab"
					aria-selected={ 'snapshots' === tab }
					className={ `tbsh-tab-btn${ 'snapshots' === tab ? ' active' : '' }` }
					onClick={ () => setTab( 'snapshots' ) }
				>
					{ __( 'Snapshots', 'tbsh-compliance-audit-logger' ) }
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ 'vaults' === tab }
					className={ `tbsh-tab-btn${ 'vaults' === tab ? ' active' : '' }` }
					onClick={ () => setTab( 'vaults' ) }
				>
					{ __( 'Off-site vaults', 'tbsh-compliance-audit-logger' ) }
				</button>
			</div>
			{ 'snapshots' === tab ? <SnapshotsPanel /> : <VaultsPanel /> }
		</div>
	);
}
