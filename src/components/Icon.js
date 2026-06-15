import { Object } from '@wordpress/element';

export default function Icon({ name, className = '', ...props }) {
	const iconHtml = window.tbshCalIcons && window.tbshCalIcons[name];
	if ( ! iconHtml ) {
		return null;
	}

	return (
		<span 
			className={`tbsh-icon tbsh-icon-${name} ${className}`} 
			style={{ display: 'inline-block', width: '1em', height: '1em', verticalAlign: 'middle' }}
			dangerouslySetInnerHTML={{ __html: iconHtml }}
			{...props}
		/>
	);
}
