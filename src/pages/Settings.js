import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';
import Modal from '../components/Modal';
import { get, post, can } from '../api';

/** Keys of the payload that are not plain settings and must never be posted back. */
const NON_SETTING_KEYS = [ 'secrets', 'meta', 'message' ];

/** Secrets whose value must be a public https URL (validated by the server too). */
const HTTPS_SECRETS = [ 'slack_webhook', 'discord_webhook', 'teams_webhook', 'webhook_url' ];

const helpStyle = { display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginTop: '4px' };
const toggleHelpStyle = { ...helpStyle, marginLeft: '24px' };
const toggleLabelStyle = { display: 'flex', alignItems: 'center', gap: '10px', fontSize: '14px', cursor: 'pointer', fontWeight: 600 };
const cardTitleStyle = { margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' };
const subBlockStyle = { borderTop: '1px solid var(--tbsh-border-color)', paddingTop: '16px', marginTop: '16px' };

const severityOptions = () => [
	[ 'info', __( 'Info', 'tbsh-compliance-audit-logger' ) ],
	[ 'notice', __( 'Notice', 'tbsh-compliance-audit-logger' ) ],
	[ 'warning', __( 'Warning', 'tbsh-compliance-audit-logger' ) ],
	[ 'error', __( 'Error', 'tbsh-compliance-audit-logger' ) ],
	[ 'critical', __( 'Critical', 'tbsh-compliance-audit-logger' ) ],
];

/**
 * Strip secrets/meta/message from an API payload, leaving plain settings.
 *
 * @param {Object} payload API payload.
 * @return {Object} Settings only.
 */
const plainSettings = ( payload ) => {
	const out = {};
	Object.keys( payload || {} ).forEach( ( key ) => {
		if ( ! NON_SETTING_KEYS.includes( key ) ) {
			out[ key ] = payload[ key ];
		}
	} );
	return out;
};

/**
 * Random 32-byte hex string from the browser CSPRNG.
 *
 * @return {string} 64 hex characters.
 */
const randomHex = () => {
	const bytes = new Uint8Array( 32 );
	window.crypto.getRandomValues( bytes );
	return Array.from( bytes, ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
};

const isHttpsUrl = ( value ) => {
	try {
		const url = new URL( value );
		return url.protocol === 'https:' && !! url.hostname;
	} catch ( e ) {
		return false;
	}
};

const isHttpUrl = ( value ) => {
	try {
		const url = new URL( value );
		return ( url.protocol === 'https:' || url.protocol === 'http:' ) && !! url.hostname;
	} catch ( e ) {
		return false;
	}
};

const looksLikeEmail = ( value ) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );

function Toggle( { id, label, checked, onChange, disabled, description } ) {
	return (
		<div className="tbsh-form-group" style={ { marginBottom: '20px' } }>
			<label htmlFor={ id } style={ toggleLabelStyle }>
				<input
					id={ id }
					type="checkbox"
					disabled={ disabled }
					checked={ !! checked }
					onChange={ ( e ) => onChange( e.target.checked ) }
				/>
				{ label }
			</label>
			{ description && <span style={ toggleHelpStyle }>{ description }</span> }
		</div>
	);
}

/**
 * Write-only secret input. The stored value is never shown; its masked hint is the placeholder.
 */
function SecretField( { secretKey, label, secrets, value, onChange, onRemove, disabled, help, reveal, emptyPlaceholder, optional, onGenerate } ) {
	const id = `tbsh-secret-${ secretKey }`;
	const info = ( secrets && secrets[ secretKey ] ) || { set: false, hint: '', unreadable: false };
	const isStored = info.set || info.unreadable;
	let placeholder = emptyPlaceholder || '';
	if ( info.set ) {
		placeholder = sprintf(
			/* translators: %s: masked hint of the stored value, e.g. ••••abcd */
			__( 'Saved (%s) — type to replace', 'tbsh-compliance-audit-logger' ),
			info.hint || '••••'
		);
	}

	return (
		<div className="tbsh-form-group">
			<label className="tbsh-form-label" htmlFor={ id }>
				{ label }
				{ optional && <span className="tbsh-muted" style={ { fontWeight: 400 } }> { __( '(optional)', 'tbsh-compliance-audit-logger' ) }</span> }
			</label>
			{ info.unreadable && (
				<Notice type="warning" message={ __( 'The stored value could not be decrypted — re-enter it.', 'tbsh-compliance-audit-logger' ) } />
			) }
			<div className="tbsh-row" style={ { flexWrap: 'nowrap' } }>
				<input
					id={ id }
					type={ reveal ? 'text' : 'password' }
					className="tbsh-form-control"
					autoComplete="new-password"
					spellCheck={ false }
					disabled={ disabled }
					placeholder={ placeholder }
					value={ value || '' }
					onChange={ ( e ) => onChange( e.target.value ) }
					style={ { flex: 1, minWidth: 0 } }
				/>
				{ onGenerate && ! disabled && (
					<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ onGenerate }>
						{ __( 'Generate', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
				{ isStored && ! disabled && (
					<button
						type="button"
						className="tbsh-btn tbsh-btn-danger tbsh-btn-sm"
						onClick={ onRemove }
						aria-label={ sprintf(
							/* translators: %s: field label */
							__( 'Remove %s', 'tbsh-compliance-audit-logger' ),
							label
						) }
					>
						<Icon name="trash" />
						{ __( 'Remove', 'tbsh-compliance-audit-logger' ) }
					</button>
				) }
			</div>
			{ help && <span style={ helpStyle }>{ help }</span> }
		</div>
	);
}

export default function Settings() {
	const [ settings, setSettings ] = useState( null );
	const [ secrets, setSecrets ] = useState( {} );
	const [ meta, setMeta ] = useState( {} );
	const [ secretInputs, setSecretInputs ] = useState( {} );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ btnLoading, setBtnLoading ] = useState( false );
	const [ notice, setNotice ] = useState( { type: '', message: '' } );
	const [ testing, setTesting ] = useState( '' );
	const [ testResults, setTestResults ] = useState( {} );
	const [ removeTarget, setRemoveTarget ] = useState( null );
	const [ removing, setRemoving ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	const hasManageCapability = can( 'manage_settings' );
	const canTestAlerts = can( 'manage_alerts' );

	const applyPayload = ( data ) => {
		setSettings( plainSettings( data ) );
		setSecrets( data.secrets || {} );
		setMeta( data.meta || {} );
	};

	const load = () => {
		if ( ! hasManageCapability ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setLoadError( '' );
		get( '/settings' )
			.then( ( data ) => {
				applyPayload( data );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setLoadError( ( err && err.message ) || __( 'Failed to load settings.', 'tbsh-compliance-audit-logger' ) );
				setLoading( false );
			} );
	};

	useEffect( load, [ hasManageCapability ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleChange = ( field, val ) => {
		setSettings( ( prev ) => ( {
			...prev,
			[ field ]: val,
		} ) );
	};

	const handleDays = ( field, raw ) => {
		const n = parseInt( raw, 10 );
		handleChange( field, Number.isNaN( n ) ? 0 : Math.min( 36500, Math.max( 0, n ) ) );
	};

	const setSecretInput = ( key, val ) => {
		setSecretInputs( ( prev ) => ( { ...prev, [ key ]: val } ) );
	};

	const validate = ( typed ) => {
		const emails = String( settings.alert_emails || '' ).split( /[\s,;]+/ ).map( ( e ) => e.trim() ).filter( Boolean );
		const badEmails = emails.filter( ( e ) => ! looksLikeEmail( e ) );
		if ( badEmails.length ) {
			return sprintf(
				/* translators: %s: comma-separated invalid addresses */
				__( 'Invalid email address: %s', 'tbsh-compliance-audit-logger' ),
				badEmails.join( ', ' )
			);
		}
		for ( const key of HTTPS_SECRETS ) {
			if ( typed[ key ] && ! isHttpsUrl( typed[ key ] ) ) {
				return __( 'Webhook URLs must be full https:// addresses.', 'tbsh-compliance-audit-logger' );
			}
		}
		if ( typed.ai_endpoint && ! isHttpUrl( typed.ai_endpoint ) ) {
			return __( 'The AI endpoint must be an http(s) URL.', 'tbsh-compliance-audit-logger' );
		}
		return '';
	};

	const saveSettings = ( e ) => {
		e.preventDefault();
		if ( ! hasManageCapability || ! settings ) {
			return;
		}

		const typed = {};
		Object.keys( secretInputs ).forEach( ( key ) => {
			const v = String( secretInputs[ key ] || '' ).trim();
			if ( v ) {
				typed[ key ] = v;
			}
		} );

		const error = validate( typed );
		if ( error ) {
			setNotice( { type: 'error', message: error } );
			return;
		}

		setBtnLoading( true );
		setNotice( { type: '', message: '' } );

		const data = plainSettings( settings );
		if ( Object.keys( typed ).length ) {
			data.secrets = typed;
		}

		post( '/settings', data )
			.then( ( res ) => {
				applyPayload( res );
				setSecretInputs( {} );
				setNotice( { type: 'success', message: res.message || __( 'Settings saved successfully.', 'tbsh-compliance-audit-logger' ) } );
				// Update local cached copy of global settings.
				if ( window.tbshCalApiSettings ) {
					window.tbshCalApiSettings.settings = plainSettings( res );
				}
				setBtnLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to save settings.', 'tbsh-compliance-audit-logger' ) } );
				setBtnLoading( false );
			} );
	};

	const confirmRemove = () => {
		if ( ! removeTarget ) {
			return;
		}
		setRemoving( true );
		post( '/settings', { clear_secrets: [ removeTarget.key ] } )
			.then( ( res ) => {
				// Only refresh credentials so unsaved edits to other fields are kept.
				setSecrets( res.secrets || {} );
				setSecretInput( removeTarget.key, '' );
				setNotice( {
					type: 'success',
					message: sprintf(
						/* translators: %s: credential label */
						__( '%s removed.', 'tbsh-compliance-audit-logger' ),
						removeTarget.label
					),
				} );
			} )
			.catch( ( err ) => {
				setNotice( { type: 'error', message: ( err && err.message ) || __( 'Failed to remove the credential.', 'tbsh-compliance-audit-logger' ) } );
			} )
			.finally( () => {
				setRemoving( false );
				setRemoveTarget( null );
			} );
	};

	const sendTest = ( channel ) => {
		setTesting( channel );
		setTestResults( ( prev ) => ( { ...prev, [ channel ]: null } ) );
		post( '/alerts/test', { channel } )
			.then( ( res ) => {
				setTestResults( ( prev ) => ( { ...prev, [ channel ]: { type: 'success', message: res.message || __( 'Test alert delivered.', 'tbsh-compliance-audit-logger' ) } } ) );
			} )
			.catch( ( err ) => {
				setTestResults( ( prev ) => ( { ...prev, [ channel ]: { type: 'error', message: ( err && err.message ) || __( 'Test alert failed.', 'tbsh-compliance-audit-logger' ) } } ) );
			} )
			.finally( () => setTesting( '' ) );
	};

	const copyReceiverUrl = () => {
		try {
			if ( window.navigator.clipboard && meta.receiver_url ) {
				window.navigator.clipboard.writeText( meta.receiver_url ).then( () => {
					setCopied( true );
					setTimeout( () => setCopied( false ), 2000 );
				} ).catch( () => {} );
			}
		} catch ( e ) {
			// Clipboard unavailable: the field stays selectable.
		}
	};

	if ( loading ) {
		return <SkeletonLoader rows={ 4 } cols={ 3 } />;
	}

	if ( ! hasManageCapability ) {
		return (
			<EmptyState
				title={ __( 'Access Denied', 'tbsh-compliance-audit-logger' ) }
				description={ __( 'You do not have the required permissions to view or manage settings.', 'tbsh-compliance-audit-logger' ) }
				icon="lock"
			/>
		);
	}

	if ( ! settings ) {
		return (
			<div>
				<EmptyState
					title={ __( 'Error Loading Data', 'tbsh-compliance-audit-logger' ) }
					description={ loadError || __( 'Could not load settings.', 'tbsh-compliance-audit-logger' ) }
					icon="alert"
				/>
				<div className="tbsh-row" style={ { justifyContent: 'center' } }>
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ load }>
						{ __( 'Retry', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</div>
		);
	}

	const disabled = ! hasManageCapability;
	const providers = meta.ai_providers || {};
	const provider = settings.ai_provider || 'none';
	const providerInfo = providers[ provider ] || null;
	const isEndpointProvider = provider === 'ollama';

	const secretProps = ( key, label, extra = {} ) => ( {
		secretKey: key,
		label,
		secrets,
		value: secretInputs[ key ],
		onChange: ( v ) => setSecretInput( key, v ),
		onRemove: () => setRemoveTarget( { key, label } ),
		disabled,
		...extra,
	} );

	const configuredBadge = ( ready ) => (
		<span className={ `tbsh-badge tbsh-badge-${ ready ? 'success' : 'info' }` }>
			{ ready ? __( 'Configured', 'tbsh-compliance-audit-logger' ) : __( 'Not configured', 'tbsh-compliance-audit-logger' ) }
		</span>
	);

	const testButton = ( channel ) => canTestAlerts && (
		<button
			type="button"
			className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm"
			onClick={ () => sendTest( channel ) }
			disabled={ !! testing }
		>
			<Icon name="alerts" />
			{ testing === channel ? __( 'Sending…', 'tbsh-compliance-audit-logger' ) : __( 'Send test', 'tbsh-compliance-audit-logger' ) }
		</button>
	);

	const testResult = ( channel ) => testResults[ channel ] && (
		<Notice type={ testResults[ channel ].type } message={ testResults[ channel ].message } />
	);

	const channelHeader = ( channel, title, ready ) => (
		<div className="tbsh-row-between" style={ { marginBottom: '12px' } }>
			<strong style={ { fontSize: '14px' } }>{ title }</strong>
			<div className="tbsh-row">
				{ configuredBadge( ready ) }
				{ testButton( channel ) }
			</div>
		</div>
	);

	const webhookChannels = [
		{
			channel: 'slack',
			title: __( 'Slack', 'tbsh-compliance-audit-logger' ),
			secret: 'slack_webhook',
			label: __( 'Slack incoming webhook URL', 'tbsh-compliance-audit-logger' ),
			placeholder: 'https://hooks.slack.com/services/…',
		},
		{
			channel: 'discord',
			title: __( 'Discord', 'tbsh-compliance-audit-logger' ),
			secret: 'discord_webhook',
			label: __( 'Discord webhook URL', 'tbsh-compliance-audit-logger' ),
			placeholder: 'https://discord.com/api/webhooks/…',
		},
		{
			channel: 'teams',
			title: __( 'Microsoft Teams', 'tbsh-compliance-audit-logger' ),
			secret: 'teams_webhook',
			label: __( 'Teams workflow URL', 'tbsh-compliance-audit-logger' ),
			placeholder: 'https://…',
			help: __( 'Use the URL of a Teams Workflows flow based on the "Post to a channel when a webhook request is received" template.', 'tbsh-compliance-audit-logger' ),
		},
	];

	return (
		<div className="tbsh-settings-page">
			{ notice.message && <Notice type={ notice.type } message={ notice.message } /> }

			<form onSubmit={ saveSettings } className="tbsh-grid-2" style={ { alignItems: 'start' } }>
				{ /* Left column */ }
				<div style={ { display: 'flex', flexDirection: 'column', gap: '24px', minWidth: 0 } }>
					{ /* Logging & Security Settings */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Audit Logging Policies', 'tbsh-compliance-audit-logger' ) }
						</h3>

						<Toggle
							id="tbsh-enable-logging"
							label={ __( 'Enable Compliance Logging', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.enable_logging }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'enable_logging', v ) }
							description={ __( 'Enable or disable recording of all compliance audit trails.', 'tbsh-compliance-audit-logger' ) }
						/>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-min-severity">{ __( 'Minimum Logging Severity', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-min-severity"
								className="tbsh-form-control"
								disabled={ disabled }
								value={ settings.min_severity }
								onChange={ ( e ) => handleChange( 'min_severity', e.target.value ) }
							>
								{ severityOptions().map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>{ label }</option>
								) ) }
							</select>
							<span style={ helpStyle }>
								{ __( 'Only events at or above this severity level will be written to the database.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						<Toggle
							id="tbsh-anonymize-ips"
							label={ __( 'Mask Client IP Addresses (GDPR Compliance)', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.anonymize_ips }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'anonymize_ips', v ) }
							description={ __( 'Mask and irreversibly hash user IP addresses using SHA-256 with a local site salt. Recommended.', 'tbsh-compliance-audit-logger' ) }
						/>

						<Toggle
							id="tbsh-log-content"
							label={ __( 'Log Content Changes (Posts)', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.log_content_events !== false }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'log_content_events', v ) }
						/>

						<Toggle
							id="tbsh-log-media"
							label={ __( 'Log Media Upload/Delete Events', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.log_media_events !== false }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'log_media_events', v ) }
						/>
					</div>

					{ /* Alert channels */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Alert Channels', 'tbsh-compliance-audit-logger' ) }
						</h3>

						{ meta.dedicated_key === false && (
							<Notice
								type="info"
								message={ __( 'Credentials are encrypted with a key derived from your WordPress salts. Define TBSH_CAL_ENCRYPTION_KEY (a long random string) in wp-config.php so that rotating the salts does not make stored credentials unreadable.', 'tbsh-compliance-audit-logger' ) }
							/>
						) }

						<p className="tbsh-muted" style={ { marginTop: 0 } }>
							{ __( 'Alerts are queued and delivered in the background by WP-Cron, with automatic retries. Save your changes before sending a test.', 'tbsh-compliance-audit-logger' ) }
						</p>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-alert-min-severity">{ __( 'Minimum Alert Severity', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-alert-min-severity"
								className="tbsh-form-control"
								disabled={ disabled }
								value={ settings.alert_min_severity }
								onChange={ ( e ) => handleChange( 'alert_min_severity', e.target.value ) }
							>
								{ severityOptions().map( ( [ value, label ] ) => (
									<option key={ value } value={ value }>{ label }</option>
								) ) }
							</select>
							<span style={ helpStyle }>
								{ __( 'Only alerts at or above this severity are sent to the enabled channels.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						{ /* Email */ }
						<div style={ subBlockStyle }>
							{ channelHeader( 'email', __( 'Email', 'tbsh-compliance-audit-logger' ), !! settings.email_alerts ) }
							<Toggle
								id="tbsh-email-alerts"
								label={ __( 'Email Alerts', 'tbsh-compliance-audit-logger' ) }
								checked={ settings.email_alerts }
								disabled={ disabled }
								onChange={ ( v ) => handleChange( 'email_alerts', v ) }
								description={ __( 'Send rate-limited alert emails for incidents and critical events.', 'tbsh-compliance-audit-logger' ) }
							/>
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-alert-emails">{ __( 'Recipients', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-alert-emails"
									type="text"
									className="tbsh-form-control"
									disabled={ disabled }
									placeholder={ meta.admin_email || '' }
									value={ settings.alert_emails || '' }
									onChange={ ( e ) => handleChange( 'alert_emails', e.target.value ) }
								/>
								<span style={ helpStyle }>
									{ __( 'Comma-separated email addresses (up to 20). Defaults to the site admin email.', 'tbsh-compliance-audit-logger' ) }
								</span>
							</div>
							{ testResult( 'email' ) }
						</div>

						{ /* Slack / Discord / Teams */ }
						{ webhookChannels.map( ( c ) => (
							<div key={ c.channel } style={ subBlockStyle }>
								{ channelHeader( c.channel, c.title, !! settings[ `${ c.channel }_enabled` ] && !! ( secrets[ c.secret ] || {} ).set ) }
								<Toggle
									id={ `tbsh-${ c.channel }-enabled` }
									label={ sprintf(
										/* translators: %s: channel name, e.g. Slack */
										__( 'Send alerts to %s', 'tbsh-compliance-audit-logger' ),
										c.title
									) }
									checked={ settings[ `${ c.channel }_enabled` ] }
									disabled={ disabled }
									onChange={ ( v ) => handleChange( `${ c.channel }_enabled`, v ) }
								/>
								<SecretField { ...secretProps( c.secret, c.label, { emptyPlaceholder: c.placeholder, help: c.help } ) } />
								{ testResult( c.channel ) }
							</div>
						) ) }

						{ /* Telegram */ }
						<div style={ subBlockStyle }>
							{ channelHeader( 'telegram', __( 'Telegram', 'tbsh-compliance-audit-logger' ), !! settings.telegram_enabled && !! ( secrets.telegram_bot_token || {} ).set && !! settings.telegram_chat_id ) }
							<Toggle
								id="tbsh-telegram-enabled"
								label={ sprintf(
									/* translators: %s: channel name */
									__( 'Send alerts to %s', 'tbsh-compliance-audit-logger' ),
									__( 'Telegram', 'tbsh-compliance-audit-logger' )
								) }
								checked={ settings.telegram_enabled }
								disabled={ disabled }
								onChange={ ( v ) => handleChange( 'telegram_enabled', v ) }
							/>
							<SecretField { ...secretProps( 'telegram_bot_token', __( 'Bot token', 'tbsh-compliance-audit-logger' ), { emptyPlaceholder: '123456:ABC…' } ) } />
							<div className="tbsh-form-group">
								<label className="tbsh-form-label" htmlFor="tbsh-telegram-chat">{ __( 'Chat ID', 'tbsh-compliance-audit-logger' ) }</label>
								<input
									id="tbsh-telegram-chat"
									type="text"
									className="tbsh-form-control"
									disabled={ disabled }
									placeholder="-1001234567890"
									value={ settings.telegram_chat_id || '' }
									onChange={ ( e ) => handleChange( 'telegram_chat_id', e.target.value ) }
								/>
								<span style={ helpStyle }>
									{ __( 'Numeric chat or group ID, or @channelname.', 'tbsh-compliance-audit-logger' ) }
								</span>
							</div>
							{ testResult( 'telegram' ) }
						</div>

						{ /* Generic webhook */ }
						<div style={ subBlockStyle }>
							{ channelHeader( 'webhook', __( 'Custom webhook', 'tbsh-compliance-audit-logger' ), !! settings.webhook_enabled && !! ( secrets.webhook_url || {} ).set ) }
							<Toggle
								id="tbsh-webhook-enabled"
								label={ __( 'Send alerts to a custom webhook', 'tbsh-compliance-audit-logger' ) }
								checked={ settings.webhook_enabled }
								disabled={ disabled }
								onChange={ ( v ) => handleChange( 'webhook_enabled', v ) }
								description={ __( 'Alerts are POSTed as JSON.', 'tbsh-compliance-audit-logger' ) }
							/>
							<SecretField { ...secretProps( 'webhook_url', __( 'Webhook URL', 'tbsh-compliance-audit-logger' ), { emptyPlaceholder: 'https://example.com/hooks/compliance' } ) } />
							<SecretField
								{ ...secretProps( 'webhook_signing_secret', __( 'Signing secret', 'tbsh-compliance-audit-logger' ), {
									reveal: true,
									optional: true,
									onGenerate: () => setSecretInput( 'webhook_signing_secret', randomHex() ),
									help: secretInputs.webhook_signing_secret
										? __( 'Copy this value into your receiver now — it will not be shown again after saving.', 'tbsh-compliance-audit-logger' )
										: __( 'When set, requests carry the header X-TBSH-Signature: sha256=HMAC-SHA256(timestamp + "." + body), using the X-TBSH-Timestamp header value as timestamp.', 'tbsh-compliance-audit-logger' ),
								} ) }
							/>
							{ testResult( 'webhook' ) }
						</div>
					</div>

					{ /* Incident detection */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Incident Detection', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<p className="tbsh-muted" style={ { marginTop: 0 } }>
							{ __( 'Automatically open incidents in the Incident Center when these events are logged.', 'tbsh-compliance-audit-logger' ) }
						</p>
						<Toggle
							id="tbsh-detect-bf"
							label={ __( 'Brute-force logins', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.detect_brute_force }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'detect_brute_force', v ) }
							description={ __( 'Five or more failed logins for the same username within 15 minutes.', 'tbsh-compliance-audit-logger' ) }
						/>
						<Toggle
							id="tbsh-detect-priv"
							label={ __( 'Privilege escalation', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.detect_privilege_escalation }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'detect_privilege_escalation', v ) }
							description={ __( 'A user is granted administrator privileges.', 'tbsh-compliance-audit-logger' ) }
						/>
						<Toggle
							id="tbsh-detect-config"
							label={ __( 'Critical configuration changes', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.detect_config_change }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'detect_config_change', v ) }
							description={ __( 'Changes to siteurl, home, admin_email, users_can_register or default_role.', 'tbsh-compliance-audit-logger' ) }
						/>
						<Toggle
							id="tbsh-detect-integrity"
							label={ __( 'Integrity failures', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.detect_integrity }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'detect_integrity', v ) }
							description={ __( 'Audit log chain tampering or modified WordPress core files.', 'tbsh-compliance-audit-logger' ) }
						/>
						<Toggle
							id="tbsh-detect-vault"
							label={ __( 'Off-site backup failures', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.detect_vault_failure }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'detect_vault_failure', v ) }
							description={ __( 'An off-site evidence vault fails to sync.', 'tbsh-compliance-audit-logger' ) }
						/>
					</div>

					{ /* Behavioural anomaly detection */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Behavioural Anomaly Detection', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<Toggle
							id="tbsh-anomaly"
							label={ __( 'Enable behavioural anomaly detection', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.anomaly_detection }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'anomaly_detection', v ) }
							description={ __( 'Builds a baseline for each user from the last 30 days of activity and scores every logged-in action against it (unusual hour, new or rare action, burst of activity). High scores are logged as anomalies and open an incident. Disabled by default.', 'tbsh-compliance-audit-logger' ) }
						/>
					</div>
				</div>

				{ /* Right column */ }
				<div style={ { display: 'flex', flexDirection: 'column', gap: '24px', minWidth: 0 } }>
					{ /* Evidence Vault Settings */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Evidence Vault & Snapshot Options', 'tbsh-compliance-audit-logger' ) }
						</h3>

						<Toggle
							id="tbsh-auto-evidence"
							label={ __( 'Enable Automated System Snapshots', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.auto_evidence }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'auto_evidence', v ) }
							description={ __( 'Automatically capture active configurations, user capabilities, and plugins state snapshots.', 'tbsh-compliance-audit-logger' ) }
						/>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-evidence-frequency">{ __( 'Automated Collection Frequency', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-evidence-frequency"
								className="tbsh-form-control"
								disabled={ disabled || ! settings.auto_evidence }
								value={ settings.evidence_frequency }
								onChange={ ( e ) => handleChange( 'evidence_frequency', e.target.value ) }
							>
								<option value="daily">{ __( 'Daily', 'tbsh-compliance-audit-logger' ) }</option>
								<option value="weekly">{ __( 'Weekly', 'tbsh-compliance-audit-logger' ) }</option>
							</select>
						</div>

						<div style={ { marginTop: '20px' } }>
							<Toggle
								id="tbsh-core-checksum"
								label={ __( 'Schedule Core File Checksum Scans', 'tbsh-compliance-audit-logger' ) }
								checked={ settings.auto_core_checksum }
								disabled={ disabled }
								onChange={ ( v ) => handleChange( 'auto_core_checksum', v ) }
								description={ __( 'Run WordPress.org core checksum verification on the same cron schedule.', 'tbsh-compliance-audit-logger' ) }
							/>
						</div>
					</div>

					{ /* Data retention */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Data Retention', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<p className="tbsh-muted" style={ { marginTop: 0 } }>
							{ __( 'Purging runs daily. Set any period to 0 to keep that data forever.', 'tbsh-compliance-audit-logger' ) }
						</p>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-retain-logs">{ __( 'Logs Retention Period (Days)', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-retain-logs"
								type="number"
								min="0"
								max="36500"
								disabled={ disabled }
								className="tbsh-form-control"
								value={ settings.retain_logs }
								onChange={ ( e ) => handleDays( 'retain_logs', e.target.value ) }
							/>
							<span style={ helpStyle }>
								{ __( 'Number of days to keep audit records in the database. Set to 0 to keep logs indefinitely.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						<Toggle
							id="tbsh-archive-before-purge"
							label={ __( 'Archive logs before purging', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.archive_before_purge }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'archive_before_purge', v ) }
							description={ __( 'Logs are written to a private compressed archive before deletion. The hash chain is re-anchored after every purge so verification keeps passing.', 'tbsh-compliance-audit-logger' ) }
						/>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-retain-incidents">{ __( 'Incidents Retention Period (Days)', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-retain-incidents"
								type="number"
								min="0"
								max="36500"
								disabled={ disabled }
								className="tbsh-form-control"
								value={ settings.retain_incidents }
								onChange={ ( e ) => handleDays( 'retain_incidents', e.target.value ) }
							/>
							<span style={ helpStyle }>
								{ __( 'Only resolved or closed incidents (with their notes and evidence links) are removed.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-retain-evidence">{ __( 'Evidence Retention Period (Days)', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-retain-evidence"
								type="number"
								min="0"
								max="36500"
								disabled={ disabled }
								className="tbsh-form-control"
								value={ settings.retain_evidence }
								onChange={ ( e ) => handleDays( 'retain_evidence', e.target.value ) }
							/>
							<span style={ helpStyle }>
								{ __( 'Snapshots linked to an open incident are always kept.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						<div className="tbsh-form-group" style={ { marginBottom: 0 } }>
							<label className="tbsh-form-label" htmlFor="tbsh-retain-alert-log">{ __( 'Delivery History Retention (Days)', 'tbsh-compliance-audit-logger' ) }</label>
							<input
								id="tbsh-retain-alert-log"
								type="number"
								min="0"
								max="36500"
								disabled={ disabled }
								className="tbsh-form-control"
								value={ settings.retain_alert_log }
								onChange={ ( e ) => handleDays( 'retain_alert_log', e.target.value ) }
							/>
							<span style={ helpStyle }>
								{ __( 'Alert and off-site vault delivery history.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>
					</div>

					{ /* AI incident analysis */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'AI Incident Analysis', 'tbsh-compliance-audit-logger' ) }
						</h3>

						<Notice
							type="info"
							message={ __( 'Nothing is sent automatically. Only when someone clicks "Analyse" on an incident are its fields (title, type, severity, status, detection time, description, affected systems, impact, root cause and compliance tags) sent to the provider chosen here. The API key is stored encrypted.', 'tbsh-compliance-audit-logger' ) }
						/>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-ai-provider">{ __( 'Provider', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-ai-provider"
								className="tbsh-form-control"
								disabled={ disabled }
								value={ provider }
								onChange={ ( e ) => handleChange( 'ai_provider', e.target.value ) }
							>
								<option value="none">{ __( 'Disabled', 'tbsh-compliance-audit-logger' ) }</option>
								{ Object.keys( providers ).map( ( key ) => (
									<option key={ key } value={ key }>{ providers[ key ].label }</option>
								) ) }
							</select>
						</div>

						{ provider !== 'none' && (
							<>
								<div className="tbsh-form-group">
									<label className="tbsh-form-label" htmlFor="tbsh-ai-model">{ __( 'Model', 'tbsh-compliance-audit-logger' ) }</label>
									<input
										id="tbsh-ai-model"
										type="text"
										className="tbsh-form-control"
										disabled={ disabled }
										placeholder={ providerInfo && providerInfo.default_model ? providerInfo.default_model : __( 'Required', 'tbsh-compliance-audit-logger' ) }
										value={ settings.ai_model || '' }
										onChange={ ( e ) => handleChange( 'ai_model', e.target.value ) }
									/>
									<span style={ helpStyle }>
										{ providerInfo && providerInfo.default_model
											? __( 'Leave empty to use the default model shown.', 'tbsh-compliance-audit-logger' )
											: __( 'Enter the model name exactly as your provider expects it.', 'tbsh-compliance-audit-logger' ) }
									</span>
								</div>

								{ isEndpointProvider && (
									<SecretField
										{ ...secretProps( 'ai_endpoint', __( 'Endpoint URL', 'tbsh-compliance-audit-logger' ), {
											reveal: true,
											emptyPlaceholder: 'http://localhost:11434/v1/chat/completions',
											help: __( 'Full URL of an OpenAI-compatible chat/completions endpoint.', 'tbsh-compliance-audit-logger' ),
										} ) }
									/>
								) }

								<SecretField
									{ ...secretProps( 'ai_api_key', __( 'API key', 'tbsh-compliance-audit-logger' ), {
										optional: isEndpointProvider,
										help: isEndpointProvider ? __( 'Only needed if your endpoint requires a bearer token.', 'tbsh-compliance-audit-logger' ) : '',
									} ) }
								/>
							</>
						) }
					</div>

					{ /* Vault receiver */ }
					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Off-site Vault Receiver', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<Toggle
							id="tbsh-vault-receiver"
							label={ __( 'Accept vault packages from other sites', 'tbsh-compliance-audit-logger' ) }
							checked={ settings.vault_receiver_enabled }
							disabled={ disabled }
							onChange={ ( v ) => handleChange( 'vault_receiver_enabled', v ) }
							description={ __( 'Lets another site running this plugin push its encrypted evidence packages here. Packages stay encrypted; this site never learns their passphrase.', 'tbsh-compliance-audit-logger' ) }
						/>
						<SecretField
							{ ...secretProps( 'vault_receiver_token', __( 'Receiver token', 'tbsh-compliance-audit-logger' ), {
								reveal: true,
								onGenerate: () => setSecretInput( 'vault_receiver_token', randomHex() ),
								help: secretInputs.vault_receiver_token
									? __( 'Copy this token into the sending site now — it will not be shown again after saving.', 'tbsh-compliance-audit-logger' )
									: __( 'The sending site signs each upload with this shared token.', 'tbsh-compliance-audit-logger' ),
							} ) }
						/>
						{ meta.receiver_url && (
							<div className="tbsh-form-group" style={ { marginBottom: 0 } }>
								<label className="tbsh-form-label" htmlFor="tbsh-receiver-url">{ __( 'Receiver URL', 'tbsh-compliance-audit-logger' ) }</label>
								<div className="tbsh-row" style={ { flexWrap: 'nowrap' } }>
									<input
										id="tbsh-receiver-url"
										type="text"
										readOnly
										className="tbsh-form-control"
										value={ meta.receiver_url }
										onFocus={ ( e ) => e.target.select() }
										style={ { flex: 1, minWidth: 0 } }
									/>
									<button type="button" className="tbsh-btn tbsh-btn-secondary tbsh-btn-sm" onClick={ copyReceiverUrl }>
										{ copied ? __( 'Copied', 'tbsh-compliance-audit-logger' ) : __( 'Copy', 'tbsh-compliance-audit-logger' ) }
									</button>
								</div>
								<span style={ helpStyle }>
									{ __( 'On the sending site, add a "Remote site" vault with this URL and the receiver token.', 'tbsh-compliance-audit-logger' ) }
								</span>
							</div>
						) }
					</div>

					<div className="tbsh-card" style={ { padding: '20px' } }>
						<h3 style={ cardTitleStyle }>
							{ __( 'Plugin Removal Policy', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label" htmlFor="tbsh-cleanup">{ __( 'Data Action on Uninstall', 'tbsh-compliance-audit-logger' ) }</label>
							<select
								id="tbsh-cleanup"
								className="tbsh-form-control"
								disabled={ disabled }
								value={ settings.cleanup_on_uninstall }
								onChange={ ( e ) => handleChange( 'cleanup_on_uninstall', e.target.value ) }
							>
								<option value="keep">{ __( 'Keep Data (Recommended for Audit Records)', 'tbsh-compliance-audit-logger' ) }</option>
								<option value="delete">{ __( 'Delete All Tables and Settings', 'tbsh-compliance-audit-logger' ) }</option>
							</select>
							<span style={ helpStyle }>
								{ __( 'Warning: Selecting Delete will remove all audit trails, evidence logs, and scan histories permanently upon deleting the plugin.', 'tbsh-compliance-audit-logger' ) }
							</span>
						</div>

						{ hasManageCapability && (
							<button type="submit" className="tbsh-btn tbsh-btn-primary" style={ { marginTop: '16px' } } disabled={ btnLoading }>
								<Icon name="check" />
								{ btnLoading ? __( 'Saving Settings...', 'tbsh-compliance-audit-logger' ) : __( 'Save Config Policies', 'tbsh-compliance-audit-logger' ) }
							</button>
						) }
					</div>
				</div>
			</form>

			{ /* Rendered outside the form so the modal's close button cannot submit it. */ }
			<Modal
				title={ __( 'Remove credential', 'tbsh-compliance-audit-logger' ) }
				isOpen={ !! removeTarget }
				onClose={ () => ! removing && setRemoveTarget( null ) }
			>
				{ removeTarget && (
					<div className="tbsh-stack">
						<p style={ { margin: 0 } }>
							{ sprintf(
								/* translators: %s: credential label */
								__( 'Remove the stored "%s"? Features that depend on it stop working until a new value is saved.', 'tbsh-compliance-audit-logger' ),
								removeTarget.label
							) }
						</p>
						<div className="tbsh-row" style={ { justifyContent: 'flex-end' } }>
							<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ () => setRemoveTarget( null ) } disabled={ removing }>
								{ __( 'Cancel', 'tbsh-compliance-audit-logger' ) }
							</button>
							<button type="button" className="tbsh-btn tbsh-btn-danger" onClick={ confirmRemove } disabled={ removing }>
								<Icon name="trash" />
								{ removing ? __( 'Removing…', 'tbsh-compliance-audit-logger' ) : __( 'Remove', 'tbsh-compliance-audit-logger' ) }
							</button>
						</div>
					</div>
				) }
			</Modal>
		</div>
	);
}
