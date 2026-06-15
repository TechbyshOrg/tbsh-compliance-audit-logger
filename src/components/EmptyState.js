import Icon from './Icon';

export default function EmptyState({ title, description, icon = 'info' }) {
	return (
		<div className="tbsh-empty-state">
			<Icon name={icon} style={{ width: '48px', height: '48px', color: 'var(--tbsh-text-muted)', marginBottom: '16px' }} />
			<h3>{title}</h3>
			{description && <p>{description}</p>}
		</div>
	);
}
