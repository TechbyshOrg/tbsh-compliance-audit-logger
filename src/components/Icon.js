export default function Icon({ name, className = '', style = {}, ...props }) {
	const iconHtml = window.tbshCalIcons && window.tbshCalIcons[name];
	if ( ! iconHtml ) {
		return null;
	}

	const mergedStyle = {
		display: 'inline-block',
		width: '1em',
		height: '1em',
		verticalAlign: 'middle',
		...style
	};

	return (
		<span 
			className={`tbsh-icon tbsh-icon-${name} ${className}`} 
			style={mergedStyle}
			dangerouslySetInnerHTML={{ __html: iconHtml }}
			{...props}
		/>
	);
}

