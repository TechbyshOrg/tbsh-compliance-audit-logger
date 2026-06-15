export default function Notice({ type = 'info', message }) {
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

	const suffix = typeMap[type] || 'info';

	return (
		<div className={`tbsh-integrity-banner tbsh-integrity-banner-${suffix}`} style={{ margin: '0 0 20px 0' }}>
			<div style={{ fontWeight: 500 }}>{message}</div>
		</div>
	);
}
