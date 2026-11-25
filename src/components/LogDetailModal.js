import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import Modal from './Modal';
import Notice from './Notice';
import SkeletonLoader from './SkeletonLoader';
import { originText } from './origin';
import { SeverityBadge } from './labels';
import { formatSiteTime } from './time';

const codeBlock = {
	display: 'block',
	padding: '6px',
	backgroundColor: 'var(--tbsh-bg-tertiary)',
	borderRadius: 'var(--tbsh-radius-sm)',
	marginTop: '4px',
	fontSize: '12px',
	wordBreak: 'break-all',
};

const sectionStyle = { borderTop: '1px solid var(--tbsh-border-color)', paddingTop: '12px' };

/**
 * Details of one audit log entry.
 *
 * @param {Object}     props
 * @param {Object}     props.log     Entry already loaded (Activity Logs), or null.
 * @param {number}     props.logId   Entry ID to load (Dashboard), or 0.
 * @param {() => void} props.onClose Close handler.
 */
export default function LogDetailModal( { log = null, logId = 0, onClose } ) {
	const [ loaded, setLoaded ] = useState( null );
	const [ error, setError ] = useState( '' );
	const isOpen = !! log || !! logId;

	useEffect( () => {
		setLoaded( null );
		setError( '' );
		if ( log || ! logId ) {
			return;
		}
		apiFetch( { path: `/tbsh-compliance-audit-logger/v1/logs/${ logId }` } )
			.then( setLoaded )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'The entry could not be loaded.', 'tbsh-compliance-audit-logger' ) ) );
	}, [ log, logId ] );

	const entry = log || loaded;
	const metadata = entry && entry.metadata ? entry.metadata : {};
	const tags = entry && entry.compliance_tags ? String( entry.compliance_tags ).split( ',' ).map( ( t ) => t.trim() ).filter( Boolean ) : [];

	return (
		<Modal title={ __( 'Event details', 'tbsh-compliance-audit-logger' ) } isOpen={ isOpen } onClose={ onClose }>
			{ error && <Notice type="error" message={ error } /> }
			{ ! entry && ! error && <SkeletonLoader rows={ 4 } cols={ 2 } /> }
			{ entry && (
				<div style={ { display: 'flex', flexDirection: 'column', gap: '12px' } }>
					<div>
						<strong>{ __( 'Event:', 'tbsh-compliance-audit-logger' ) }</strong> { entry.event_title }
					</div>

					<div>
						<strong>{ __( 'Description:', 'tbsh-compliance-audit-logger' ) }</strong>
						<p style={ { margin: '4px 0 0 0', padding: '8px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: 'var(--tbsh-radius-sm)' } }>
							{ entry.event_message }
						</p>
					</div>

					<dl className="tbsh-kv">
						<dt>{ __( 'Time', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ formatSiteTime( entry.created_at ) }</dd>
						<dt>{ __( 'User', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>
							{ entry.username }
							{ entry.user_id ? ` (#${ entry.user_id })` : '' }
						</dd>
						<dt>{ __( 'Severity', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>
							<SeverityBadge severity={ entry.severity } />
						</dd>
						<dt>{ __( 'Category', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ entry.event_category }</dd>
						{ originText( metadata ) && (
							<>
								<dt>{ __( 'Made by', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>{ originText( metadata ) }</dd>
							</>
						) }
						{ metadata._ability && (
							<>
								<dt>{ __( 'Ability', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>
									<code>{ metadata._ability }</code>
								</dd>
							</>
						) }
						{ metadata._app_password && (
							<>
								<dt>{ __( 'Application password', 'tbsh-compliance-audit-logger' ) }</dt>
								<dd>{ metadata._app_password }</dd>
							</>
						) }
						<dt>{ __( 'Request method', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>
							<code>{ entry.request_method || 'GET' }</code>
						</dd>
						<dt>{ __( 'Site ID', 'tbsh-compliance-audit-logger' ) }</dt>
						<dd>{ entry.site_id }</dd>
					</dl>

					<div>
						<strong>{ __( 'Request URI:', 'tbsh-compliance-audit-logger' ) }</strong>
						<code style={ codeBlock }>{ entry.request_uri || '-' }</code>
					</div>

					<div>
						<strong>{ __( 'Referrer:', 'tbsh-compliance-audit-logger' ) }</strong>
						<code style={ codeBlock }>{ entry.referrer || '-' }</code>
					</div>

					<div className="tbsh-grid-fields">
						<div>
							<strong>{ __( 'Client IP hash:', 'tbsh-compliance-audit-logger' ) }</strong>
							<code style={ codeBlock }>{ entry.ip_hash || '-' }</code>
						</div>
						<div>
							<strong>{ __( 'User agent hash:', 'tbsh-compliance-audit-logger' ) }</strong>
							<code style={ codeBlock }>{ entry.user_agent_hash || '-' }</code>
						</div>
					</div>

					<div>
						<strong>{ __( 'Event ID (UUID):', 'tbsh-compliance-audit-logger' ) }</strong>
						<code style={ codeBlock }>{ entry.event_uuid }</code>
					</div>

					{ tags.length > 0 && (
						<div style={ sectionStyle }>
							<strong>{ __( 'Compliance tags:', 'tbsh-compliance-audit-logger' ) }</strong>
							<div style={ { display: 'flex', gap: '6px', flexWrap: 'wrap', marginTop: '6px' } }>
								{ tags.map( ( tag ) => (
									<span key={ tag } className="tbsh-badge tbsh-badge-info">
										{ tag }
									</span>
								) ) }
							</div>
						</div>
					) }

					<div style={ sectionStyle }>
						<h3 className="tbsh-section-title" style={ { fontSize: '13px', marginBottom: '8px' } }>
							{ __( 'Tamper-evidence hashes', 'tbsh-compliance-audit-logger' ) }
						</h3>
						<div style={ { display: 'flex', flexDirection: 'column', gap: '8px' } }>
							<div>
								<span className="tbsh-muted">{ __( 'This entry (SHA-256):', 'tbsh-compliance-audit-logger' ) }</span>
								<code style={ { ...codeBlock, color: 'var(--tbsh-success-text)' } }>{ entry.integrity_hash }</code>
							</div>
							<div>
								<span className="tbsh-muted">{ __( 'Previous entry (SHA-256):', 'tbsh-compliance-audit-logger' ) }</span>
								<code style={ codeBlock }>{ entry.previous_hash }</code>
							</div>
						</div>
					</div>

					{ Object.keys( metadata ).length > 0 && (
						<div style={ sectionStyle }>
							<h3 className="tbsh-section-title" style={ { fontSize: '13px', marginBottom: '8px' } }>
								{ __( 'Additional details (metadata)', 'tbsh-compliance-audit-logger' ) }
							</h3>
							<pre style={ { margin: 0, padding: '10px', backgroundColor: 'var(--tbsh-bg-tertiary)', borderRadius: 'var(--tbsh-radius-md)', fontSize: '12px', overflowX: 'auto' } }>
								{ JSON.stringify( metadata, null, 2 ) }
							</pre>
						</div>
					) }
				</div>
			) }
		</Modal>
	);
}
