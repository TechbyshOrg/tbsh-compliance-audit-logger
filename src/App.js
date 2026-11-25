import { useState, useEffect, lazy, Suspense } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Icon from './components/Icon';
import SkeletonLoader from './components/SkeletonLoader';
import { isAuditor, can } from './api';

// The default page ships in the main bundle; every other page is a separate chunk
// loaded the first time it is opened.
import Dashboard from './pages/Dashboard';

const ExecutiveDashboard = lazy( () => import( /* webpackChunkName: "page-executive" */ './pages/ExecutiveDashboard' ) );
const ComplianceOverview = lazy( () => import( /* webpackChunkName: "page-compliance" */ './pages/ComplianceOverview' ) );
const ActivityLogs = lazy( () => import( /* webpackChunkName: "page-logs" */ './pages/ActivityLogs' ) );
const Timeline = lazy( () => import( /* webpackChunkName: "page-timeline" */ './pages/Timeline' ) );
const BehavioralAnomaly = lazy( () => import( /* webpackChunkName: "page-anomalies" */ './pages/BehavioralAnomaly' ) );
const AdvancedAnalytics = lazy( () => import( /* webpackChunkName: "page-analytics" */ './pages/AdvancedAnalytics' ) );
const AlertCenter = lazy( () => import( /* webpackChunkName: "page-alerts" */ './pages/AlertCenter' ) );
const Sessions = lazy( () => import( /* webpackChunkName: "page-sessions" */ './pages/Sessions' ) );
const IncidentCenter = lazy( () => import( /* webpackChunkName: "page-incidents" */ './pages/IncidentCenter' ) );
const RiskRegister = lazy( () => import( /* webpackChunkName: "page-risks" */ './pages/RiskRegister' ) );
const VendorGovernance = lazy( () => import( /* webpackChunkName: "page-vendors" */ './pages/VendorGovernance' ) );
const ResilienceModule = lazy( () => import( /* webpackChunkName: "page-resilience" */ './pages/ResilienceModule' ) );
const EvidenceVault = lazy( () => import( /* webpackChunkName: "page-evidence" */ './pages/EvidenceVault' ) );
const IntegrityCenter = lazy( () => import( /* webpackChunkName: "page-integrity" */ './pages/IntegrityCenter' ) );
const ComplianceReporting = lazy( () => import( /* webpackChunkName: "page-reports" */ './pages/ComplianceReporting' ) );
const ExportCenter = lazy( () => import( /* webpackChunkName: "page-exports" */ './pages/ExportCenter' ) );
const AuditorAccess = lazy( () => import( /* webpackChunkName: "page-auditor" */ './pages/AuditorAccess' ) );
const SystemHealth = lazy( () => import( /* webpackChunkName: "page-health" */ './pages/SystemHealth' ) );
const Settings = lazy( () => import( /* webpackChunkName: "page-settings" */ './pages/Settings' ) );
const Setup = lazy( () => import( /* webpackChunkName: "page-setup" */ './pages/Setup' ) );
const Help = lazy( () => import( /* webpackChunkName: "page-help" */ './pages/Help' ) );

function getCaps() {
	return ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};
}

// Routes a read-only auditor link may open (mirrors the server-side allowlist).
const AUDITOR_ROUTES = [ 'executive', 'dashboard', 'compliance', 'logs', 'timeline', 'incidents', 'risks', 'vendors', 'resilience', 'analytics', 'evidence', 'integrity', 'reports' ];

export default function App() {
	const caps = getCaps();
	const auditor = isAuditor();
	const defaultRoute = auditor ? 'executive' : 'dashboard';

	const [ route, setRoute ] = useState( () => window.location.hash.replace( '#/', '' ) || defaultRoute );

	useEffect( () => {
		const handleHashChange = () => {
			setRoute( window.location.hash.replace( '#/', '' ) || defaultRoute );
		};
		window.addEventListener( 'hashchange', handleHashChange );
		return () => window.removeEventListener( 'hashchange', handleHashChange );
	}, [ defaultRoute ] );

	const navigate = ( newRoute ) => {
		window.location.hash = `#/${ newRoute }`;
	};

	const [ darkMode, setDarkMode ] = useState( () => {
		try {
			return window.localStorage.getItem( 'tbshCalDarkMode' ) === 'true';
		} catch ( e ) {
			return false;
		}
	} );

	useEffect( () => {
		try {
			window.localStorage.setItem( 'tbshCalDarkMode', darkMode );
		} catch ( e ) {
			// Storage unavailable; ignore.
		}
	}, [ darkMode ] );

	const groups = [
		{
			label: __( 'Overview', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'dashboard', label: __( 'Dashboard', 'tbsh-compliance-audit-logger' ), icon: 'dashboard', cap: 'view_logs' },
				{ id: 'executive', label: __( 'Executive Dashboard', 'tbsh-compliance-audit-logger' ), icon: 'executive', cap: 'view_analytics' },
				{ id: 'compliance', label: __( 'Compliance Overview', 'tbsh-compliance-audit-logger' ), icon: 'compliance', cap: 'view_logs' },
			],
		},
		{
			label: __( 'Monitoring', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'logs', label: __( 'Activity Logs', 'tbsh-compliance-audit-logger' ), icon: 'logs', cap: 'view_logs' },
				{ id: 'timeline', label: __( 'Forensic Timeline', 'tbsh-compliance-audit-logger' ), icon: 'timeline', cap: 'view_logs' },
				{ id: 'anomalies', label: __( 'Behavioral Anomalies', 'tbsh-compliance-audit-logger' ), icon: 'anomaly', cap: 'view_analytics' },
				{ id: 'analytics', label: __( 'Advanced Analytics', 'tbsh-compliance-audit-logger' ), icon: 'analytics', cap: 'view_analytics' },
				{ id: 'alerts', label: __( 'Alert Center', 'tbsh-compliance-audit-logger' ), icon: 'alerts', cap: 'view_alerts' },
				{ id: 'sessions', label: __( 'Active Sessions', 'tbsh-compliance-audit-logger' ), icon: 'user', cap: 'manage_sessions' },
			],
		},
		{
			label: __( 'Response & Governance', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'incidents', label: __( 'Incident Center', 'tbsh-compliance-audit-logger' ), icon: 'incidents', cap: 'view_incidents' },
				{ id: 'risks', label: __( 'Risk Register', 'tbsh-compliance-audit-logger' ), icon: 'risk', cap: 'view_risks' },
				{ id: 'vendors', label: __( 'Vendor Governance', 'tbsh-compliance-audit-logger' ), icon: 'vendors', cap: 'view_vendors' },
				{ id: 'resilience', label: __( 'Operational Resilience', 'tbsh-compliance-audit-logger' ), icon: 'resilience', cap: 'view_analytics' },
			],
		},
		{
			label: __( 'Evidence & Reporting', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'evidence', label: __( 'Evidence Vault', 'tbsh-compliance-audit-logger' ), icon: 'evidence', cap: 'view_evidence' },
				{ id: 'integrity', label: __( 'Integrity Center', 'tbsh-compliance-audit-logger' ), icon: 'integrity', cap: 'view_logs' },
				{ id: 'reports', label: __( 'Compliance Reports', 'tbsh-compliance-audit-logger' ), icon: 'reports', cap: 'view_reports' },
				{ id: 'exports', label: __( 'Export Center', 'tbsh-compliance-audit-logger' ), icon: 'export', cap: 'export_data' },
				{ id: 'auditor', label: __( 'Auditor Access', 'tbsh-compliance-audit-logger' ), icon: 'auditor', cap: 'manage_auditor_access' },
			],
		},
		{
			label: __( 'System', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'health', label: __( 'System Health', 'tbsh-compliance-audit-logger' ), icon: 'health', cap: 'view_logs' },
				{ id: 'settings', label: __( 'Settings', 'tbsh-compliance-audit-logger' ), icon: 'settings', cap: 'manage_settings' },
				{ id: 'help', label: __( 'Help & Reference', 'tbsh-compliance-audit-logger' ), icon: 'help', cap: null },
				{ id: 'setup', label: __( 'Setup Assistant', 'tbsh-compliance-audit-logger' ), icon: 'check', cap: 'manage_settings', hidden: true },
			],
		},
	];

	const isAllowed = ( item ) => {
		if ( auditor ) {
			return AUDITOR_ROUTES.includes( item.id ) && ( ! item.cap || caps[ item.cap ] );
		}
		return ! item.cap || caps[ item.cap ];
	};

	const visibleGroups = groups
		.map( ( group ) => ( { ...group, items: group.items.filter( ( item ) => ! item.hidden && isAllowed( item ) ) } ) )
		.filter( ( group ) => group.items.length > 0 );
	const allItems = groups.flatMap( ( group ) => group.items );

	// Redirect away from unknown or unauthorized routes.
	useEffect( () => {
		const current = allItems.find( ( i ) => i.id === route );
		if ( ! current || ! isAllowed( current ) ) {
			const fallback = visibleGroups.length ? visibleGroups[ 0 ].items[ 0 ].id : defaultRoute;
			if ( fallback !== route ) {
				navigate( fallback );
			}
		}
	}, [ route ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const renderPage = () => {
		switch ( route ) {
			case 'executive':
				return <ExecutiveDashboard navigate={ navigate } />;
			case 'compliance':
				return <ComplianceOverview />;
			case 'logs':
				return <ActivityLogs />;
			case 'timeline':
				return <Timeline />;
			case 'anomalies':
				return <BehavioralAnomaly />;
			case 'analytics':
				return <AdvancedAnalytics />;
			case 'alerts':
				return <AlertCenter navigate={ navigate } />;
			case 'sessions':
				return <Sessions />;
			case 'setup':
				return <Setup navigate={ navigate } />;
			case 'incidents':
				return <IncidentCenter />;
			case 'risks':
				return <RiskRegister />;
			case 'vendors':
				return <VendorGovernance />;
			case 'resilience':
				return <ResilienceModule />;
			case 'evidence':
				return <EvidenceVault />;
			case 'integrity':
				return <IntegrityCenter />;
			case 'reports':
				return <ComplianceReporting />;
			case 'exports':
				return <ExportCenter />;
			case 'auditor':
				return <AuditorAccess />;
			case 'health':
				return <SystemHealth />;
			case 'settings':
				return <Settings />;
			case 'help':
				return <Help />;
			default:
				return <Dashboard navigate={ navigate } />;
		}
	};

	const settings = window.tbshCalApiSettings || {};
	const showSetupBanner = ! auditor && settings.setupState === 'pending' && route !== 'setup' && can( 'manage_settings' );

	return (
		<div className={ `tbsh-cal-app ${ darkMode ? 'tbsh-dark' : '' }` }>
			<header className="tbsh-app-header">
				<div className="tbsh-app-title">
					<h1>{ auditor ? __( 'Compliance Audit Portal', 'tbsh-compliance-audit-logger' ) : __( 'Compliance Audit Trail & Evidence Logger', 'tbsh-compliance-audit-logger' ) }</h1>
					<p>
						{ auditor
							? sprintf(
								/* translators: 1: site name, 2: expiry date */
								__( '%1$s · read-only access until %2$s', 'tbsh-compliance-audit-logger' ),
								settings.siteName || '',
								settings.expiresAt || ''
							)
							: __( 'Governance, Risk, Incident Response & Cryptographic Verification', 'tbsh-compliance-audit-logger' ) }
					</p>
				</div>
				<div className="tbsh-header-actions">
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary"
						onClick={ () => setDarkMode( ! darkMode ) }
						title={ __( 'Toggle Light/Dark Theme', 'tbsh-compliance-audit-logger' ) }
						aria-label={ __( 'Toggle Light/Dark Theme', 'tbsh-compliance-audit-logger' ) }
						style={ { padding: '8px' } }
					>
						<Icon name="settings" style={ { fontSize: '18px' } } />
						{ darkMode ? __( 'Light Mode', 'tbsh-compliance-audit-logger' ) : __( 'Dark Mode', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</header>

			<div className="tbsh-app-layout">
				<nav className="tbsh-sidebar-nav" aria-label={ __( 'Compliance navigation', 'tbsh-compliance-audit-logger' ) }>
					{ visibleGroups.map( ( group ) => (
						<div key={ group.label } role="group" aria-label={ group.label }>
							<div className="tbsh-nav-group">{ group.label }</div>
							{ group.items.map( ( item ) => (
								<a
									key={ item.id }
									href={ `#/${ item.id }` }
									className={ `tbsh-nav-item ${ route === item.id ? 'active' : '' }` }
									aria-current={ route === item.id ? 'page' : undefined }
									onClick={ ( e ) => {
										e.preventDefault();
										navigate( item.id );
									} }
								>
									<Icon name={ item.icon } />
									<span>{ item.label }</span>
								</a>
							) ) }
						</div>
					) ) }
				</nav>

				<main className="tbsh-app-content">
					{ showSetupBanner && (
						<div className="tbsh-card" role="region" aria-label={ __( 'Finish setup', 'tbsh-compliance-audit-logger' ) } style={ { padding: '16px 20px', marginBottom: '24px', borderLeft: '4px solid var(--tbsh-primary)' } }>
							<div className="tbsh-row-between" style={ { gap: '12px', flexWrap: 'wrap' } }>
								<div style={ { minWidth: 0 } }>
									<strong>{ __( 'Finish setting up compliance logging', 'tbsh-compliance-audit-logger' ) }</strong>
									<p className="tbsh-muted" style={ { margin: '4px 0 0' } }>
										{ __( 'Pick your frameworks and apply recommended alerts, scheduled checks and retention in four short steps.', 'tbsh-compliance-audit-logger' ) }
									</p>
								</div>
								<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ () => navigate( 'setup' ) }>
									{ __( 'Start setup', 'tbsh-compliance-audit-logger' ) }
								</button>
							</div>
						</div>
					) }
					<Suspense fallback={ <SkeletonLoader rows={ 4 } cols={ 3 } /> }>
						{ renderPage() }
					</Suspense>
				</main>
			</div>
		</div>
	);
}
