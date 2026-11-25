import { __ } from '@wordpress/i18n';

/**
 * Severity values in ascending order.
 */
export const SEVERITIES = [ 'info', 'notice', 'warning', 'error', 'critical' ];

/**
 * Filter value matching both error and critical entries (the logs and export APIs accept lists).
 */
export const ERRORS_AND_CRITICAL = 'error,critical';

/**
 * Translated severity names.
 *
 * @return {Object} Severity => label.
 */
export const severityLabels = () => ( {
	info: __( 'Info', 'tbsh-compliance-audit-logger' ),
	notice: __( 'Notice', 'tbsh-compliance-audit-logger' ),
	warning: __( 'Warning', 'tbsh-compliance-audit-logger' ),
	error: __( 'Error', 'tbsh-compliance-audit-logger' ),
	critical: __( 'Critical', 'tbsh-compliance-audit-logger' ),
} );

/**
 * Translated label for a severity (falls back to the raw value).
 *
 * @param {string} severity Severity.
 * @return {string} Label.
 */
export const severityLabel = ( severity ) => {
	if ( ERRORS_AND_CRITICAL === severity ) {
		return __( 'Errors and critical', 'tbsh-compliance-audit-logger' );
	}
	return severityLabels()[ severity ] || severity || '';
};

/**
 * Badge colour for a severity.
 *
 * @param {string} severity Severity.
 * @return {string} Badge modifier.
 */
export const severityTone = ( severity ) => {
	if ( 'critical' === severity || 'error' === severity ) {
		return 'danger';
	}
	if ( 'warning' === severity ) {
		return 'warning';
	}
	return 'info';
};

/**
 * Severity badge (text plus colour, never colour alone).
 *
 * @param {Object} props
 * @param {string} props.severity Severity.
 * @return {Element} Badge.
 */
export function SeverityBadge( { severity } ) {
	return <span className={ `tbsh-badge tbsh-badge-${ severityTone( severity ) }` }>{ severityLabel( severity ) }</span>;
}

/**
 * Severity <option> elements, preceded by an "all" option.
 *
 * @param {Object}  props
 * @param {string}  props.allLabel Label of the empty option.
 * @param {boolean} props.combined Also offer "Errors and critical" (APIs that accept lists).
 * @return {Element} Options.
 */
export function SeverityOptions( { allLabel, combined = false } ) {
	return (
		<>
			<option value="">{ allLabel }</option>
			{ combined && <option value={ ERRORS_AND_CRITICAL }>{ severityLabel( ERRORS_AND_CRITICAL ) }</option> }
			{ SEVERITIES.map( ( value ) => (
				<option key={ value } value={ value }>
					{ severityLabel( value ) }
				</option>
			) ) }
		</>
	);
}
