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

export default function App() {
	// 1. Hash Routing.
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

	// 2. Persistent Dark Mode.
	const [darkMode, setDarkMode] = useState(() => {
		return localStorage.getItem('tbshCalDarkMode') === 'true';
	});

	useEffect(() => {
		localStorage.setItem('tbshCalDarkMode', darkMode);
	}, [darkMode]);

	// 3. Page Renderer.
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

	// Navigation Items definition.
	const navItems = [
		{ id: 'dashboard', label: __('Dashboard', 'tbsh-compliance-audit-logger'), icon: 'dashboard' },
		{ id: 'logs', label: __('Activity Logs', 'tbsh-compliance-audit-logger'), icon: 'logs' },
		{ id: 'timeline', label: __('Forensic Timeline', 'tbsh-compliance-audit-logger'), icon: 'timeline' },
		{ id: 'evidence', label: __('Evidence Vault', 'tbsh-compliance-audit-logger'), icon: 'evidence' },
		{ id: 'integrity', label: __('Integrity Center', 'tbsh-compliance-audit-logger'), icon: 'integrity' },
		{ id: 'compliance', label: __('Compliance Overview', 'tbsh-compliance-audit-logger'), icon: 'compliance' },
		{ id: 'health', label: __('System Health', 'tbsh-compliance-audit-logger'), icon: 'health' },
		{ id: 'exports', label: __('Export Center', 'tbsh-compliance-audit-logger'), icon: 'export' },
		{ id: 'settings', label: __('Settings Module', 'tbsh-compliance-audit-logger'), icon: 'settings' },
		{ id: 'help', label: __('Help & Reference', 'tbsh-compliance-audit-logger'), icon: 'help' },
	];

	return (
		<div className={`tbsh-cal-app ${darkMode ? 'tbsh-dark' : ''}`}>
			{/* Top Bar / Header */}
			<header className="tbsh-app-header">
				<div className="tbsh-app-title">
					<h1>{__('Compliance Audit Trail & Evidence Logger', 'tbsh-compliance-audit-logger')}</h1>
					<p>{__('Enterprise Governance, Risk Management & Cryptographic Verification', 'tbsh-compliance-audit-logger')}</p>
				</div>
				<div className="tbsh-header-actions">
					{/* Dark Mode Switcher */}
					<button 
						className="tbsh-btn tbsh-btn-secondary" 
						onClick={() => setDarkMode(!darkMode)}
						title={__('Toggle Light/Dark Theme', 'tbsh-compliance-audit-logger')}
						style={{ padding: '8px' }}
					>
						<Icon name="settings" style={{ fontSize: '18px' }} />
						{darkMode ? __('Light Mode', 'tbsh-compliance-audit-logger') : __('Dark Mode', 'tbsh-compliance-audit-logger')}
					</button>
				</div>
			</header>

			{/* Layout Wrapper */}
			<div className="tbsh-app-layout">
				{/* Sidebar Menu */}
				<aside className="tbsh-sidebar-nav">
					{navItems.map((item) => (
						<a
							key={item.id}
							className={`tbsh-nav-item ${route === item.id ? 'active' : ''}`}
							onClick={() => navigate(item.id)}
						>
							<Icon name={item.icon} />
							<span>{item.label}</span>
						</a>
					))}
				</aside>

				{/* Main Content Pane */}
				<main className="tbsh-app-content">
					{renderPage()}
				</main>
			</div>
		</div>
	);
}
