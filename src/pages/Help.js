import { __ } from '@wordpress/i18n';
import Icon from '../components/Icon';

export default function Help() {
	return (
		<div className="tbsh-help-page">
			{/* Overview Info */}
			<div className="tbsh-card" style={{ padding: '24px', marginBottom: '24px' }}>
				<h3 style={{ margin: '0 0 8px 0', fontSize: '18px', fontWeight: '600' }}>
					{__('Compliance Reference Manual', 'tbsh-compliance-audit-logger')}
				</h3>
				<p style={{ margin: 0, color: 'var(--tbsh-text-secondary)', fontSize: '14px', lineHeight: '1.6' }}>
					{__('Compliance Audit Trail & Evidence Logger provides cryptographic, tamper-evident trails of site activity and configures audit evidence vaults. This panel shows how the system complies with common international standards.', 'tbsh-compliance-audit-logger')}
				</p>
			</div>

			{/* Frameworks Mapping */}
			<div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px', marginBottom: '24px' }}>
				{/* ISO 27001 */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h4 style={{ margin: '0 0 10px 0', fontSize: '15px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '8px' }}>
						<Icon name="compliance" />
						{__('ISO/IEC 27001 Alignment', 'tbsh-compliance-audit-logger')}
					</h4>
					<p style={{ margin: 0, fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
						<strong>{__('Control A.12.4.1 (Event Logging):', 'tbsh-compliance-audit-logger')}</strong> {__('Produces detailed log events including actors, timestamps, events types, and IP addresses.', 'tbsh-compliance-audit-logger')}
						<br /><br />
						<strong>{__('Control A.12.4.2 (Protection of Log Information):', 'tbsh-compliance-audit-logger')}</strong> {__('Leverages SHA-256 blockchain hashing chains to detect unauthorized record deletion or modification.', 'tbsh-compliance-audit-logger')}
					</p>
				</div>

				{/* SOC 2 */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h4 style={{ margin: '0 0 10px 0', fontSize: '15px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '8px' }}>
						<Icon name="compliance" />
						{__('SOC 2 Trust Services Criteria', 'tbsh-compliance-audit-logger')}
					</h4>
					<p style={{ margin: 0, fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
						<strong>{__('Common Criteria 6.1 (Logical Access Controls):', 'tbsh-compliance-audit-logger')}</strong> {__('Records every user creation, deletion, login failure, and role escalation.', 'tbsh-compliance-audit-logger')}
						<br /><br />
						<strong>{__('Common Criteria 8.1 (Change Management):', 'tbsh-compliance-audit-logger')}</strong> {__('Logs theme updates, plugin activations, and system-level configuration adjustments.', 'tbsh-compliance-audit-logger')}
					</p>
				</div>

				{/* DORA */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h4 style={{ margin: '0 0 10px 0', fontSize: '15px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '8px' }}>
						<Icon name="compliance" />
						{__('DORA (Digital Operational Resilience Act)', 'tbsh-compliance-audit-logger')}
					</h4>
					<p style={{ margin: 0, fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
						<strong>{__('Article 17 (ICT Incident Logging):', 'tbsh-compliance-audit-logger')}</strong> {__('Captures warning logs and critical errors. The Integrity scan engine identifies anomalous log gaps and logs indicators for rapid security analysis.', 'tbsh-compliance-audit-logger')}
					</p>
				</div>

				{/* NIS 2 */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h4 style={{ margin: '0 0 10px 0', fontSize: '15px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '8px' }}>
						<Icon name="compliance" />
						{__('NIS 2 Directive', 'tbsh-compliance-audit-logger')}
					</h4>
					<p style={{ margin: 0, fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
						<strong>{__('Article 21 (Cybersecurity Risk-Management):', 'tbsh-compliance-audit-logger')}</strong> {__('Establishes continuous logging, verification controls, and evidence collections required for organizational audit reporting.', 'tbsh-compliance-audit-logger')}
					</p>
				</div>
			</div>

			{/* FAQ section */}
			<div className="tbsh-card" style={{ padding: '20px' }}>
				<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
					{__('Frequently Asked Questions', 'tbsh-compliance-audit-logger')}
				</h3>
				<ul style={{ padding: 0, margin: 0, listStyle: 'none', display: 'flex', flexDirection: 'column', gap: '16px' }}>
					<li>
						<strong style={{ fontSize: '14px', color: 'var(--tbsh-text-primary)', display: 'block', marginBottom: '4px' }}>
							{__('1. How does the hash chain work?', 'tbsh-compliance-audit-logger')}
						</strong>
						<span style={{ fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
							{__('Every log row hashes all row contents (UUID, message, actor, timestamp) along with the hash of the preceding record. Because of this connection, changing or deleting a single row will cause the verification engine to report a mismatch on all subsequent logs.', 'tbsh-compliance-audit-logger')}
						</span>
					</li>
					<li>
						<strong style={{ fontSize: '14px', color: 'var(--tbsh-text-primary)', display: 'block', marginBottom: '4px' }}>
							{__('2. Are IP addresses GDPR-compliant?', 'tbsh-compliance-audit-logger')}
						</strong>
						<span style={{ fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
							{__('Yes, when IP Address Masking is enabled in settings (recommended), client IPs are salted and hashed using HMAC-SHA-256 before being recorded. This represents irreversible pseudonymization compliant with GDPR and HIPAA.', 'tbsh-compliance-audit-logger')}
						</span>
					</li>
					<li>
						<strong style={{ fontSize: '14px', color: 'var(--tbsh-text-primary)', display: 'block', marginBottom: '4px' }}>
							{__('3. How do I export audit trails for auditors?', 'tbsh-compliance-audit-logger')}
						</strong>
						<span style={{ fontSize: '13px', color: 'var(--tbsh-text-secondary)', lineHeight: '1.6' }}>
							{__('Navigate to the Export Center, select your required date ranges or category filters, choose CSV or JSON, and click Compile. You can download the completed file securely from the table.', 'tbsh-compliance-audit-logger')}
						</span>
					</li>
				</ul>
			</div>
		</div>
	);
}
