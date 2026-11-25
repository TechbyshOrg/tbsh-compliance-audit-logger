/**
 * Inline SVG icon. Decorative by default (hidden from assistive technology); pass a `label`
 * when the icon is the only content of a control.
 *
 * @param {Object} props
 * @param {string} props.name      Icon name.
 * @param {string} props.className Extra class.
 * @param {Object} props.style     Inline style.
 * @param {string} props.label     Accessible label (makes the icon meaningful).
 */
export default function Icon( { name, className = '', style = {}, label = '', ...props } ) {
	const iconHtml = window.tbshCalIcons && window.tbshCalIcons[ name ];
	if ( ! iconHtml ) {
		return null;
	}

	const mergedStyle = {
		display: 'inline-block',
		width: '1em',
		height: '1em',
		verticalAlign: 'middle',
		...style,
	};

	const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': 'true' };

	return (
		<span
			className={ `tbsh-icon tbsh-icon-${ name } ${ className }` }
			style={ mergedStyle }
			dangerouslySetInnerHTML={ { __html: iconHtml } }
			{ ...a11y }
			{ ...props }
		/>
	);
}
