import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import SkeletonLoader from '../components/SkeletonLoader';
import EmptyState from '../components/EmptyState';
import Notice from '../components/Notice';
import Icon from '../components/Icon';

export default function Settings() {
	const [settings, setSettings] = useState(null);
	const [loading, setLoading] = useState(true);
	const [btnLoading, setBtnLoading] = useState(false);
	const [notice, setNotice] = useState({ type: '', message: '' });

	const hasManageCapability = window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities.manage_settings;

	useEffect(() => {
		if ( ! hasManageCapability ) {
			setLoading(false);
			return;
		}
		apiFetch({ path: '/tbsh-compliance-audit-logger/v1/settings' })
			.then((data) => {
				setSettings(data);
				setLoading(false);
			})
			.catch((err) => {
				console.error(err);
				setLoading(false);
			});
	}, [hasManageCapability]);

	const handleChange = (field, val) => {
		setSettings((prev) => ({
			...prev,
			[field]: val,
		}));
	};

	const saveSettings = (e) => {
		e.preventDefault();
		if ( ! hasManageCapability ) return;

		setBtnLoading(true);
		setNotice({ type: '', message: '' });

		apiFetch({
			path: '/tbsh-compliance-audit-logger/v1/settings',
			method: 'POST',
			data: settings,
		})
		.then((res) => {
			setNotice({ type: 'success', message: res.message });
			// Update local cached copy of global settings.
			if (window.tbshCalApiSettings) {
				window.tbshCalApiSettings.settings = res.settings;
			}
			setBtnLoading(false);
		})
		.catch((err) => {
			setNotice({ type: 'error', message: err.message || __('Failed to save settings.', 'tbsh-compliance-audit-logger') });
			setBtnLoading(false);
		});
	};

	if (loading) {
		return <SkeletonLoader rows={4} cols={3} />;
	}

	if ( ! hasManageCapability ) {
		return (
			<EmptyState
				title={__('Access Denied', 'tbsh-compliance-audit-logger')}
				description={__('You do not have the required permissions to view or manage settings.', 'tbsh-compliance-audit-logger')}
				icon="lock"
			/>
		);
	}

	return (
		<div className="tbsh-settings-page">
			{notice.message && <Notice type={notice.type} message={notice.message} />}

			<form onSubmit={saveSettings} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '24px', alignItems: 'start' }}>
				{/* Logging & Security Settings */}
				<div className="tbsh-card" style={{ padding: '20px' }}>
					<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
						{__('Audit Logging Policies', 'tbsh-compliance-audit-logger')}
					</h3>

					<div className="tbsh-form-group" style={{ marginBottom: '20px' }}>
						<label style={{ display: 'flex', alignItems: 'center', gap: '10px', fontSize: '14px', cursor: 'pointer', fontWeight: 600 }}>
							<input 
								type="checkbox" 
								disabled={!hasManageCapability}
								checked={settings.enable_logging} 
								onChange={(e) => handleChange('enable_logging', e.target.checked)} 
							/>
							{__('Enable Compliance Logging', 'tbsh-compliance-audit-logger')}
						</label>
						<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginLeft: '24px', marginTop: '4px' }}>
							{__('Enable or disable recording of all compliance audit trails.', 'tbsh-compliance-audit-logger')}
						</span>
					</div>

					<div className="tbsh-form-group">
						<label className="tbsh-form-label">{__('Minimum Logging Severity', 'tbsh-compliance-audit-logger')}</label>
						<select 
							className="tbsh-form-control" 
							disabled={!hasManageCapability}
							value={settings.min_severity} 
							onChange={(e) => handleChange('min_severity', e.target.value)}
						>
							<option value="info">Info</option>
							<option value="notice">Notice</option>
							<option value="warning">Warning</option>
							<option value="error">Error</option>
							<option value="critical">Critical</option>
						</select>
						<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginTop: '4px' }}>
							{__('Only events at or above this severity level will be written to the database.', 'tbsh-compliance-audit-logger')}
						</span>
					</div>

					<div className="tbsh-form-group" style={{ marginBottom: '20px' }}>
						<label style={{ display: 'flex', alignItems: 'center', gap: '10px', fontSize: '14px', cursor: 'pointer', fontWeight: 600 }}>
							<input 
								type="checkbox" 
								disabled={!hasManageCapability}
								checked={settings.anonymize_ips} 
								onChange={(e) => handleChange('anonymize_ips', e.target.checked)} 
							/>
							{__('Mask Client IP Addresses (GDPR Compliance)', 'tbsh-compliance-audit-logger')}
						</label>
						<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginLeft: '24px', marginTop: '4px' }}>
							{__('Mask and irreversibly hash user IP addresses using SHA-256 with a local site salt. Recommended.', 'tbsh-compliance-audit-logger')}
						</span>
					</div>

					<div className="tbsh-form-group">
						<label className="tbsh-form-label">{__('Logs Retention Period (Days)', 'tbsh-compliance-audit-logger')}</label>
						<input 
							type="number" 
							min="0"
							disabled={!hasManageCapability}
							className="tbsh-form-control" 
							value={settings.retain_logs} 
							onChange={(e) => handleChange('retain_logs', parseInt(e.target.value))} 
						/>
						<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginTop: '4px' }}>
							{__('Number of days to keep audit records in the database. Set to 0 to keep logs indefinitely.', 'tbsh-compliance-audit-logger')}
						</span>
					</div>
				</div>

				{/* Evidence Vault Settings */}
				<div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
					<div className="tbsh-card" style={{ padding: '20px' }}>
						<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
							{__('Evidence Vault & Snapshot Options', 'tbsh-compliance-audit-logger')}
						</h3>

						<div className="tbsh-form-group" style={{ marginBottom: '20px' }}>
							<label style={{ display: 'flex', alignItems: 'center', gap: '10px', fontSize: '14px', cursor: 'pointer', fontWeight: 600 }}>
								<input 
									type="checkbox" 
									disabled={!hasManageCapability}
									checked={settings.auto_evidence} 
									onChange={(e) => handleChange('auto_evidence', e.target.checked)} 
								/>
								{__('Enable Automated System Snapshots', 'tbsh-compliance-audit-logger')}
							</label>
							<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginLeft: '24px', marginTop: '4px' }}>
								{__('Automatically capture active configurations, user capabilities, and plugins state snapshots.', 'tbsh-compliance-audit-logger')}
							</span>
						</div>

						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Automated Collection Frequency', 'tbsh-compliance-audit-logger')}</label>
							<select 
								className="tbsh-form-control" 
								disabled={!hasManageCapability || !settings.auto_evidence}
								value={settings.evidence_frequency} 
								onChange={(e) => handleChange('evidence_frequency', e.target.value)}
							>
								<option value="daily">{__('Daily', 'tbsh-compliance-audit-logger')}</option>
								<option value="weekly">{__('Weekly', 'tbsh-compliance-audit-logger')}</option>
							</select>
						</div>
					</div>

					<div className="tbsh-card" style={{ padding: '20px' }}>
						<h3 style={{ margin: '0 0 16px 0', fontSize: '16px', fontWeight: '600' }}>
							{__('Plugin Removal Policy', 'tbsh-compliance-audit-logger')}
						</h3>
						<div className="tbsh-form-group">
							<label className="tbsh-form-label">{__('Data Action on Uninstall', 'tbsh-compliance-audit-logger')}</label>
							<select 
								className="tbsh-form-control" 
								disabled={!hasManageCapability}
								value={settings.cleanup_on_uninstall} 
								onChange={(e) => handleChange('cleanup_on_uninstall', e.target.value)}
							>
								<option value="keep">{__('Keep Data (Recommended for Audit Records)', 'tbsh-compliance-audit-logger')}</option>
								<option value="delete">{__('Delete All Tables and Settings', 'tbsh-compliance-audit-logger')}</option>
							</select>
							<span style={{ display: 'block', fontSize: '12px', color: 'var(--tbsh-text-muted)', marginTop: '4px' }}>
								{__('Warning: Selecting Delete will remove all audit trails, evidence logs, and scan histories permanently upon deleting the plugin.', 'tbsh-compliance-audit-logger')}
							</span>
						</div>

						{hasManageCapability && (
							<button type="submit" className="tbsh-btn tbsh-btn-primary" style={{ marginTop: '16px' }} disabled={btnLoading}>
								<Icon name="check" />
								{btnLoading ? __('Saving Settings...', 'tbsh-compliance-audit-logger') : __('Save Config Policies', 'tbsh-compliance-audit-logger')}
							</button>
						)}
					</div>
				</div>
			</form>
		</div>
	);
}
