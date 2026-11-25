/**
 * Small in-memory hand-offs between pages (no URL or route changes).
 */

let pending = {};

/**
 * Ask a page to open with preset state, e.g. Activity Logs with filters from a dashboard card.
 *
 * @param {string} page  Route id.
 * @param {Object} state State for that page.
 */
export const setPendingState = ( page, state ) => {
	pending[ page ] = state;
};

/**
 * Take (and clear) the preset state for a page.
 *
 * @param {string} page Route id.
 * @return {Object|null} State.
 */
export const takePendingState = ( page ) => {
	const state = pending[ page ] || null;
	delete pending[ page ];
	return state;
};

/**
 * Reset all hand-offs (tests).
 */
export const resetPendingState = () => {
	pending = {};
};

let unsavedMessage = '';

/**
 * Register (or clear, with '') a confirmation shown before leaving a page with unsaved changes.
 *
 * @param {string} message Confirmation text.
 */
export const setUnsavedChanges = ( message ) => {
	unsavedMessage = message || '';
};

/**
 * Whether navigation may continue (asks the user when there are unsaved changes).
 *
 * @return {boolean} Continue.
 */
export const confirmLeave = () => {
	// eslint-disable-next-line no-alert
	if ( unsavedMessage && ! window.confirm( unsavedMessage ) ) {
		return false;
	}
	unsavedMessage = '';
	return true;
};
