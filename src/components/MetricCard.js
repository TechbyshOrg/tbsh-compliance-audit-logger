import Icon from './Icon';

export default function MetricCard({ title, value, icon, desc, status = '' }) {
	let statusClass = '';
	if ( status ) {
		statusClass = `tbsh-card-${status}`;
	}

	return (
		<div className={`tbsh-card ${statusClass}`}>
			<div className="tbsh-card-header">
				<span>{title}</span>
				{icon && <Icon name={icon} />}
			</div>
			<div className="tbsh-card-value">{value}</div>
			{desc && <div className="tbsh-card-desc">{desc}</div>}
		</div>
	);
}
