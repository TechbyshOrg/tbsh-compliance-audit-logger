import Icon from './Icon';

/**
 * Empty, no-results or error state, optionally with the next step as a button.
 *
 * @param {Object}   props
 * @param {string}   props.title       Heading.
 * @param {string}   props.description Explanation.
 * @param {string}   props.icon        Icon name.
 * @param {string}   props.actionLabel Button text.
 * @param {Function} props.onAction    Button handler.
 */
export default function EmptyState( { title, description, icon = 'info', actionLabel = '', onAction = null } ) {
	return (
		<div className="tbsh-empty-state">
			<Icon name={ icon } style={ { width: '48px', height: '48px', color: 'var(--tbsh-text-muted)', marginBottom: '16px' } } />
			<h3>{ title }</h3>
			{ description && <p>{ description }</p> }
			{ actionLabel && onAction && (
				<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ onAction }>
					{ actionLabel }
				</button>
			) }
		</div>
	);
}
