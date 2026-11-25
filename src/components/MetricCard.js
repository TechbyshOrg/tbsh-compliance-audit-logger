import Icon from './Icon';

/**
 * Key figure. With `onClick` the whole card is a button that opens the related view.
 *
 * @param {Object}   props
 * @param {string}   props.title     Label.
 * @param {*}        props.value     Value.
 * @param {string}   props.icon      Icon name.
 * @param {string}   props.desc      Supporting text.
 * @param {string}   props.status    Optional status modifier.
 * @param {string}   props.className Extra class.
 * @param {Function} props.onClick   Opens the related view.
 * @param {string}   props.cta       Link text shown under the value, e.g. "View events".
 */
export default function MetricCard( { title, value, icon, desc, status = '', className = '', onClick = null, cta = '' } ) {
	const statusClass = status ? `tbsh-card-${ status }` : '';
	const body = (
		<>
			<div className="tbsh-card-header">
				<span>{ title }</span>
				{ icon && <Icon name={ icon } /> }
			</div>
			<div className="tbsh-card-value">{ value }</div>
			{ desc && <div className="tbsh-card-desc">{ desc }</div> }
			{ onClick && cta && <div className="tbsh-card-cta" aria-hidden="true">{ cta } →</div> }
		</>
	);

	if ( onClick ) {
		return (
			<button type="button" className={ `tbsh-card tbsh-card-link ${ statusClass } ${ className }` } onClick={ onClick }>
				{ body }
			</button>
		);
	}

	return <div className={ `tbsh-card ${ statusClass } ${ className }` }>{ body }</div>;
}
