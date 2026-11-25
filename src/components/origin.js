import { __ } from '@wordpress/i18n';

/**
 * Labels for who or what made a change (see Logging\Origin).
 *
 * @return {Object} Actor => label.
 */
export const actorLabels = () => ( {
	person: __( 'Person', 'tbsh-compliance-audit-logger' ),
	agent: __( 'AI agent', 'tbsh-compliance-audit-logger' ),
	integration: __( 'Integration', 'tbsh-compliance-audit-logger' ),
	automation: __( 'Automation', 'tbsh-compliance-audit-logger' ),
	visitor: __( 'Visitor', 'tbsh-compliance-audit-logger' ),
} );

/**
 * Labels for the channel a change came through.
 *
 * @return {Object} Channel => label.
 */
export const viaLabels = () => ( {
	web: __( 'Browser', 'tbsh-compliance-audit-logger' ),
	ajax: __( 'Browser (AJAX)', 'tbsh-compliance-audit-logger' ),
	rest: __( 'REST API', 'tbsh-compliance-audit-logger' ),
	mcp: __( 'MCP', 'tbsh-compliance-audit-logger' ),
	xmlrpc: __( 'XML-RPC', 'tbsh-compliance-audit-logger' ),
	cron: __( 'Scheduled task', 'tbsh-compliance-audit-logger' ),
	cli: __( 'WP-CLI', 'tbsh-compliance-audit-logger' ),
} );

/**
 * Short "AI agent · MCP" description of an entry's origin, or an empty string.
 *
 * @param {Object} metadata Entry metadata.
 * @return {string} Description.
 */
export const originText = ( metadata ) => {
	if ( ! metadata || ! metadata._actor ) {
		return '';
	}
	const actor = actorLabels()[ metadata._actor ] || metadata._actor;
	const via = metadata._via ? viaLabels()[ metadata._via ] || metadata._via : '';
	return via ? `${ actor } · ${ via }` : actor;
};
