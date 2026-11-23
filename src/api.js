/**
 * Small REST helper shared by all pages.
 *
 * Every route lives in one namespace; paths passed here are relative to it,
 * e.g. get( '/incidents', { status: 'open' } ).
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const settings = () => window.tbshCalApiSettings || {};

export const NS = settings().namespace || 'tbsh-compliance-audit-logger/v1';

/**
 * Build a REST path inside the plugin namespace.
 *
 * @param {string} path  Relative path starting with '/'.
 * @param {Object} query Optional query args (empty values are dropped).
 * @return {string} Path for apiFetch.
 */
export const apiPath = ( path, query = {} ) => {
	const clean = Object.fromEntries(
		Object.entries( query ).filter( ( [ , v ] ) => v !== '' && v !== null && v !== undefined )
	);
	return addQueryArgs( `/${ NS }${ path }`, clean );
};

export const get = ( path, query = {} ) => apiFetch( { path: apiPath( path, query ) } );

export const post = ( path, data = {} ) => apiFetch( { path: apiPath( path ), method: 'POST', data } );

export const put = ( path, data = {} ) => apiFetch( { path: apiPath( path ), method: 'PUT', data } );

export const del = ( path ) => apiFetch( { path: apiPath( path ), method: 'DELETE' } );

/**
 * Absolute URL for a file-download route, authenticated with the REST nonce.
 *
 * @param {string} path  Relative path starting with '/'.
 * @param {Object} query Optional query args.
 * @return {string} URL.
 */
export const downloadUrl = ( path, query = {} ) => {
	const s = settings();
	const args = { ...query };
	if ( s.nonce ) {
		args._wpnonce = s.nonce;
	}
	return addQueryArgs( `${ s.root || '/wp-json/' }${ NS }${ path }`, args );
};

/**
 * Whether the current user has a capability (short name, e.g. 'manage_incidents').
 *
 * @param {string} cap Capability short name.
 * @return {boolean} Granted.
 */
export const can = ( cap ) => !! ( settings().capabilities || {} )[ cap ];

/**
 * Whether the app is running inside the read-only auditor portal.
 *
 * @return {boolean} Auditor mode.
 */
export const isAuditor = () => settings().mode === 'auditor';
