/**
 * Inline status message, announced to screen readers when it appears.
 *
 * @param {Object}  props
 * @param {string}  props.type    success|warning|error|info.
 * @param {unknown} props.message Message.
 */
export default function Notice( { type = 'info', message } ) {
	if ( ! message ) {
		return null;
	}

	// Maps types to correct CSS classes.
	const typeMap = {
		success: 'success',
		warning: 'warning',
		error: 'danger',
		info: 'info',
	};

	const suffix = typeMap[ type ] || 'info';
	const urgent = 'error' === type || 'warning' === type;

	return (
		<div
			className={ `tbsh-integrity-banner tbsh-integrity-banner-${ suffix }` }
			role={ urgent ? 'alert' : 'status' }
			style={ { margin: '0 0 20px 0' } }
		>
			<div style={ { fontWeight: 500 } }>{ message }</div>
		</div>
	);
}
