import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Icon from '../components/Icon';

export default function ComplianceOverview() {
	const [data, setData] = useState(null);
	const [loading, setLoading] = useState(true);

	useEffect(() => {
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/compliance' })
			.then((res) => {
				setData(res);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	}, []);

	if (loading) {
		return <SkeletonLoader rows={5} cols={4} />;
	}

	const cats = data.categories;
	const readiness = data.readiness;
	const coverage = data.coverage;

	// Color helpers for readiness scores.
	const getScoreColor = (score) => {
		if (score >= 90) return 'var(--tbsh-success)';
		if (score >= 60) return 'var(--tbsh-warning)';
		return 'var(--tbsh-danger)';
	};

	return (
		<div className="tbsh-compliance-page">
			{/* Overview Row */}
			<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px', marginBottom: '24px' }}>
				{/* Audit Readiness Score Card */}
				<div className="tbsh-card" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '30px' }}>
					<h4 style={{ margin: '0 0 16px 0', fontSize: '15px', color: 'var(--tbsh-text-secondary)', textTransform: 'uppercase', fontWeight: 600, letterSpacing: '0.05em' }}>
						{__('Audit Readiness Posture Score', 'tbsh-compliance-audit-logger')}
					</h4>
					<div 
						style={{ 
							fontSize: '64px', 
							fontWeight: '800', 
							color: getScoreColor(readiness.score),
							lineHeight: 1,
							marginBottom: '8px'
						}}
					>
						{readiness.score}%
					</div>
					<p style={{ margin: 0, color: 'var(--tbsh-text-muted)', fontSize: '13px', textAlign: 'center' }}>
						{__('Calculated based on policy logs activity, IP hashing privacy, and chain validations.', 'tbsh-compliance-audit-logger')}
					</p>
				</div>

				{/* Evidence Coverage Card */}
				<div className="tbsh-card" style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '30px' }}>
					<h4 style={{ margin: '0 0 16px 0', fontSize: '15px', color: 'var(--tbsh-text-secondary)', textTransform: 'uppercase', fontWeight: 600, letterSpacing: '0.05em' }}>
						{__('Evidence Archival Coverage', 'tbsh-compliance-audit-logger')}
					</h4>
					<div 
						style={{ 
							fontSize: '64px', 
							fontWeight: '800', 
							color: getScoreColor(coverage.score),
							lineHeight: 1,
							marginBottom: '8px'
						}}
					>
						{coverage.score}%
					</div>
					<p style={{ margin: 0, color: 'var(--tbsh-text-muted)', fontSize: '13px', textAlign: 'center' }}>
						{sprintf(__('Recorded %d system snapshots in compliance evidence vaults.', 'tbsh-compliance-audit-logger'), coverage.snapshots_recorded)}
					</p>
				</div>
			</div>

			{/* Framework Checklist */}
			<div className="tbsh-card" style={{ padding: '20px', marginBottom: '24px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Compliance Readiness Checklists', 'tbsh-compliance-audit-logger')}
				</h3>
				<div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
					{Object.keys(readiness.items).map((key) => {
						const item = readiness.items[key];
						let iconName = 'check';
						let iconColor = 'var(--tbsh-success)';
						if (item.status === 'fail') {
							iconName = 'alert';
							iconColor = 'var(--tbsh-danger)';
						} else if (item.status === 'warn') {
							iconName = 'info';
							iconColor = 'var(--tbsh-warning)';
						}

						return (
							<div 
								key={key} 
								style={{ 
									display: 'flex', 
									alignItems: 'flex-start', 
									gap: '12px', 
									padding: '12px', 
									border: '1px solid var(--tbsh-border-color)', 
									borderRadius: '6px',
									backgroundColor: 'var(--tbsh-bg-secondary)'
								}}
							>
								<Icon name={iconName} style={{ fontSize: '20px', color: iconColor, marginTop: '2px', flexShrink: 0 }} />
								<div>
									<h4 style={{ margin: '0 0 4px 0', fontSize: '14px', fontWeight: 600, color: 'var(--tbsh-text-primary)' }}>
										{item.title}
									</h4>
									<p style={{ margin: 0, fontSize: '13px', color: 'var(--tbsh-text-secondary)' }}>
										{item.desc}
									</p>
								</div>
							</div>
						);
					})}
				</div>
			</div>

			{/* Events distribution mapped by Governance Standards */}
			<div className="tbsh-card" style={{ padding: '20px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Compliance Framework Events Mapping', 'tbsh-compliance-audit-logger')}
				</h3>
				<p style={{ fontSize: '14px', color: 'var(--tbsh-text-secondary)', marginBottom: '20px' }}>
					{__('Audit logs counts classified under standard regulatory controls (inspired by ISO 27001, SOC 2, DORA, and NIS2).', 'tbsh-compliance-audit-logger')}
				</p>

				<div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: '16px' }}>
					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('Access Control & Identity', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('ISO 27001 Annex A.9 / SOC 2 CC6.1 - User registrations, creations, profile alterations.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.access_control}</span>
					</div>

					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('Authentication Events', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('ISO 27001 Annex A.9.4.2 - Logins, logouts, passwords changes, failed credentials attempts.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.authentication}</span>
					</div>

					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('Authorization Modifications', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('ISO 27001 Annex A.9.2 - User role changes, capability modifications, escalation alerts.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.authorization}</span>
					</div>

					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('Change & Config Management', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('ISO 27001 Annex A.12.1.2 / SOC 2 CC8.1 - Theme activations, plugin upgrades, systems updates.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.changes}</span>
					</div>

					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('Security & Incident Monitor', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('ISO 27001 Annex A.16 / DORA Article 17 - Excessive failed logins, blockchain integrity anomalies.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.security}</span>
					</div>

					<div style={{ padding: '16px', border: '1px solid var(--tbsh-border-color)', borderRadius: '6px' }}>
						<h4 style={{ margin: '0 0 8px 0', fontSize: '14px', fontWeight: '600' }}>
							{__('General Operational Audits', 'tbsh-compliance-audit-logger')}
						</h4>
						<p style={{ fontSize: '12px', color: 'var(--tbsh-text-muted)', margin: '0 0 12px 0' }}>
							{__('General audit trails, backup validations, scheduled processes executions.', 'tbsh-compliance-audit-logger')}
						</p>
						<span style={{ fontSize: '24px', fontWeight: '700', color: 'var(--tbsh-primary)' }}>{cats.operational}</span>
					</div>
				</div>
			</div>
		</div>
	);
}
