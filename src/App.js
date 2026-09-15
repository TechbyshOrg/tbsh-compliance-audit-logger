import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Icon from './components/Icon';

// Pages.
import Dashboard from './pages/Dashboard';
import ActivityLogs from './pages/ActivityLogs';
import Timeline from './pages/Timeline';
import EvidenceVault from './pages/EvidenceVault';
import IntegrityCenter from './pages/IntegrityCenter';
import ComplianceOverview from './pages/ComplianceOverview';
import SystemHealth from './pages/SystemHealth';
import ExportCenter from './pages/ExportCenter';
import Settings from './pages/Settings';
import Help from './pages/Help';

function getCaps() {
	return ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};
}

export default function App() {
	const caps = getCaps();

	const [route, setRoute] = useState(() => {
		return window.location.hash.replace('#/', '') || 'dashboard';
	});

	useEffect(() => {
		const handleHashChange = () => {
			setRoute(window.location.hash.replace('#/', '') || 'dashboard');
		};
		window.addEventListener('hashchange', handleHashChange);
		return () => window.removeEventListener('hashchange', handleHashChange);
	}, []);

	const navigate = (newRoute) => {
		window.location.hash = `#/${newRoute}`;
	};

	const [darkMode, setDarkMode] = useState(() => {
		return localStorage.getItem('tbshCalDarkMode') === 'true';
	});

	useEffect(() => {
		localStorage.setItem('tbshCalDarkMode', darkMode);
	}, [darkMode]);

	const allNavItems = [
		{ id: 'dashboard', label: __('Dashboard', 'tbsh-compliance-audit-logger'), icon: 'dashboard', cap: 'view_logs' },
		{ id: 'logs', label: __('Activity Logs', 'tbsh-compliance-audit-logger'), icon: 'logs', cap: 'view_logs' },
		{ id: 'timeline', label: __('Forensic Timeline', 'tbsh-compliance-audit-logger'), icon: 'timeline', cap: 'view_logs' },
		{ id: 'evidence', label: __('Evidence Vault', 'tbsh-compliance-audit-logger'), icon: 'evidence', cap: 'view_evidence' },
		{ id: 'integrity', label: __('Integrity Center', 'tbsh-compliance-audit-logger'), icon: 'integrity', cap: 'view_logs' },
		{ id: 'compliance', label: __('Compliance Overview', 'tbsh-compliance-audit-logger'), icon: 'compliance', cap: 'view_logs' },
		{ id: 'health', label: __('System Health', 'tbsh-compliance-audit-logger'), icon: 'health', cap: 'view_logs' },
		{ id: 'exports', label: __('Export Center', 'tbsh-compliance-audit-logger'), icon: 'export', cap: 'export_data' },
		{ id: 'settings', label: __('Settings Module', 'tbsh-compliance-audit-logger'), icon: 'settings', cap: 'manage_settings' },
		{ id: 'help', label: __('Help & Reference', 'tbsh-compliance-audit-logger'), icon: 'help', cap: null },
	];

	const navItems = allNavItems.filter( ( item ) => ! item.cap || caps[ item.cap ] );

	// Redirect away from unauthorized routes.
	useEffect(() => {
		const current = allNavItems.find( ( i ) => i.id === route );
		if ( current && current.cap && ! caps[ current.cap ] ) {
			navigate( 'dashboard' );
		}
	}, [route] ); // eslint-disable-line react-hooks/exhaustive-deps

	const renderPage = () => {
		switch (route) {
			case 'dashboard':
				return <Dashboard navigate={navigate} />;
			case 'logs':
				return <ActivityLogs />;
			case 'timeline':
				return <Timeline />;
			case 'evidence':
				return <EvidenceVault />;
			case 'integrity':
				return <IntegrityCenter />;
			case 'compliance':
				return <ComplianceOverview />;
			case 'health':
				return <SystemHealth />;
			case 'exports':
				return <ExportCenter />;
			case 'settings':
				return <Settings />;
			case 'help':
				return <Help />;
			default:
				return <Dashboard navigate={navigate} />;
		}
	};

	return (
		<div className={`tbsh-cal-app ${darkMode ? 'tbsh-dark' : ''}`}>
			<header className="tbsh-app-header">
				<div className="tbsh-app-title">
					<h1>{__('Compliance Audit Trail & Evidence Logger', 'tbsh-compliance-audit-logger')}</h1>
					<p>{__('Enterprise Governance, Risk Management & Cryptographic Verification', 'tbsh-compliance-audit-logger')}</p>
				</div>
				<div className="tbsh-header-actions">
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary"
						onClick={() => setDarkMode(!darkMode)}
						title={__('Toggle Light/Dark Theme', 'tbsh-compliance-audit-logger')}
						aria-label={__('Toggle Light/Dark Theme', 'tbsh-compliance-audit-logger')}
						style={{ padding: '8px' }}
					>
						<Icon name="settings" style={{ fontSize: '18px' }} />
						{darkMode ? __('Light Mode', 'tbsh-compliance-audit-logger') : __('Dark Mode', 'tbsh-compliance-audit-logger')}
					</button>
				</div>
			</header>

			<div className="tbsh-app-layout">
				<aside className="tbsh-sidebar-nav" aria-label={__('Compliance navigation', 'tbsh-compliance-audit-logger')}>
					{navItems.map((item) => (
						<a
							key={item.id}
							href={`#/${item.id}`}
							role="link"
							className={`tbsh-nav-item ${route === item.id ? 'active' : ''}`}
							aria-current={route === item.id ? 'page' : undefined}
							onClick={(e) => {
								e.preventDefault();
								navigate(item.id);
							}}
						>
							<Icon name={item.icon} />
							<span>{item.label}</span>
						</a>
					))}
				</aside>

				<main className="tbsh-app-content">
					{renderPage()}
				</main>
			</div>
		</div>
	);
}
