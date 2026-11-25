import { dateI18n, getDate, getSettings, humanTimeDiff } from '@wordpress/date';

/**
 * Log timestamps are stored in the site's timezone as "Y-m-d H:i:s". These helpers show them
 * with the site's date and time formats (Settings → General), not the browser's.
 */

/**
 * Parse a stored timestamp (site timezone).
 *
 * @param {string} value Stored timestamp.
 * @return {Date|null} Date, or null when empty or invalid.
 */
export const parseSiteTime = ( value ) => {
	if ( ! value || ! /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test( String( value ) ) ) {
		return null;
	}
	const date = getDate( String( value ).replace( ' ', 'T' ) );
	return Number.isNaN( date.getTime() ) ? null : date;
};

/**
 * Exact date and time in the site's formats.
 *
 * @param {string} value Stored timestamp.
 * @return {string} Formatted, or the raw value when it cannot be parsed.
 */
export const formatSiteTime = ( value ) => {
	const date = parseSiteTime( value );
	if ( ! date ) {
		return value || '';
	}
	const { formats } = getSettings();
	return dateI18n( `${ formats.date } ${ formats.time }`, date );
};

/**
 * Relative time ("5 mins ago"), or the exact date when older than a week.
 *
 * @param {string} value Stored timestamp.
 * @return {string} Text.
 */
export const relativeSiteTime = ( value ) => {
	const date = parseSiteTime( value );
	if ( ! date ) {
		return value || '';
	}
	if ( Math.abs( Date.now() - date.getTime() ) > 7 * 24 * 60 * 60 * 1000 ) {
		return formatSiteTime( value );
	}
	return humanTimeDiff( date );
};

/**
 * <time> element showing relative time; the exact time is in the tooltip and read by screen readers.
 *
 * @param {Object} props
 * @param {string} props.value Stored timestamp.
 * @return {Element} Time.
 */
export function SiteTime( { value } ) {
	const date = parseSiteTime( value );
	if ( ! date ) {
		return <span>{ value || '—' }</span>;
	}
	const exact = formatSiteTime( value );
	const relative = relativeSiteTime( value );
	return (
		<time className="tbsh-time" dateTime={ date.toISOString() } title={ exact }>
			<span aria-hidden={ relative !== exact ? 'true' : undefined }>{ relative }</span>
			{ relative !== exact && <span className="tbsh-sr-only">{ exact }</span> }
		</time>
	);
}
