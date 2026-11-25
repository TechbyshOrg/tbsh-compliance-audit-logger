import { useState, useEffect, useRef, lazy, Suspense } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Icon from './components/Icon';
import SkeletonLoader from './components/SkeletonLoader';
import { confirmLeave } from './components/navState';
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
const SiteNetwork = lazy( () => import( /* webpackChunkName: "page-network" */ './pages/SiteNetwork' ) );

const THEME_KEY = 'tbshCalDarkMode';

function getCaps() {
	return ( window.tbshCalApiSettings && window.tbshCalApiSettings.capabilities ) || {};
}

/**
 * Stored theme choice: true (dark), false (light) or null (follow the system setting).
 *
 * @return {boolean|null} Choice.
 */
function storedTheme() {
	try {
		const value = window.localStorage.getItem( THEME_KEY );
		if ( 'true' === value || 'false' === value ) {
			return 'true' === value;
		}
	} catch ( e ) {
		// Storage unavailable.
	}
	return null;
}

/**
 * Whether the operating system prefers a dark theme.
 *
 * @return {boolean} Dark.
 */
function systemPrefersDark() {
	return !! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches );
}

// Routes a read-only auditor link may open (mirrors the server-side allowlist).
const AUDITOR_ROUTES = [ 'executive', 'dashboard', 'compliance', 'logs', 'timeline', 'incidents', 'risks', 'vendors', 'resilience', 'analytics', 'evidence', 'integrity', 'reports' ];

export default function App() {
	const caps = getCaps();
	const auditor = isAuditor();
	const defaultRoute = auditor ? 'executive' : 'dashboard';

	const [ route, setRoute ] = useState( () => window.location.hash.replace( '#/', '' ) || defaultRoute );
	const [ navOpen, setNavOpen ] = useState( false );
	const headingRef = useRef( null );
	const firstRender = useRef( true );
	const baseTitle = useRef( document.title );

	// Current route for the hashchange handler, and a flag for the address we restore ourselves.
	const routeRef = useRef( route );
	const restoringHash = useRef( false );
	routeRef.current = route;

	useEffect( () => {
		const handleHashChange = () => {
			const next = window.location.hash.replace( '#/', '' ) || defaultRoute;
			if ( restoringHash.current ) {
				restoringHash.current = false;
				return;
			}
			// Back/forward buttons and typed addresses also respect unsaved changes.
			if ( next !== routeRef.current && ! confirmLeave() ) {
				restoringHash.current = true;
				window.location.hash = `#/${ routeRef.current }`;
				return;
			}
			setRoute( next );
		};
		window.addEventListener( 'hashchange', handleHashChange );
		return () => window.removeEventListener( 'hashchange', handleHashChange );
	}, [ defaultRoute ] );

	const navigate = ( newRoute ) => {
		if ( newRoute !== route && ! confirmLeave() ) {
			return;
		}
		setNavOpen( false );
		window.location.hash = `#/${ newRoute }`;
	};

	// Theme: an explicit choice wins; otherwise follow the operating system.
	const [ themeChoice, setThemeChoice ] = useState( storedTheme );
	const [ systemDark, setSystemDark ] = useState( systemPrefersDark );
	const darkMode = null === themeChoice ? systemDark : themeChoice;

	useEffect( () => {
		if ( ! window.matchMedia ) {
			return undefined;
		}
		const query = window.matchMedia( '(prefers-color-scheme: dark)' );
		const onChange = ( e ) => setSystemDark( e.matches );
		if ( query.addEventListener ) {
			query.addEventListener( 'change', onChange );
			return () => query.removeEventListener( 'change', onChange );
		}
		return undefined;
	}, [] );

	const toggleTheme = () => {
		const next = ! darkMode;
		setThemeChoice( next );
		try {
			window.localStorage.setItem( THEME_KEY, next ? 'true' : 'false' );
		} catch ( e ) {
			// Storage unavailable; the choice lasts for this visit only.
		}
	};

	const groups = [
		{
			label: __( 'Overview', 'tbsh-compliance-audit-logger' ),
			items: [
				{
					id: 'dashboard',
					label: __( 'Dashboard', 'tbsh-compliance-audit-logger' ),
					icon: 'dashboard',
					cap: 'view_logs',
					description: __( 'What happened on your site and what needs attention.', 'tbsh-compliance-audit-logger' ),
					action: { label: __( 'View activity log', 'tbsh-compliance-audit-logger' ), route: 'logs' },
				},
				{ id: 'executive', label: __( 'Executive Dashboard', 'tbsh-compliance-audit-logger' ), icon: 'executive', cap: 'view_analytics', description: __( 'Compliance readiness, risk and trends at a glance for management.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'compliance', label: __( 'Compliance Overview', 'tbsh-compliance-audit-logger' ), icon: 'compliance', cap: 'view_logs', description: __( 'How well the site meets each framework, and the controls that need work.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'network', label: __( 'Site Network', 'tbsh-compliance-audit-logger' ), icon: 'shield', cap: 'view_analytics', description: __( 'Readiness, errors and incidents of your other sites in one place, read through their auditor links.', 'tbsh-compliance-audit-logger' ) },
			],
		},
		{
			label: __( 'Monitoring', 'tbsh-compliance-audit-logger' ),
			items: [
				{
					id: 'logs',
					label: __( 'Activity Logs', 'tbsh-compliance-audit-logger' ),
					icon: 'logs',
					cap: 'view_logs',
					description: __( 'Every recorded change, sign-in and system event. Entries are hash-chained and cannot be edited.', 'tbsh-compliance-audit-logger' ),
					action: { label: __( 'Export…', 'tbsh-compliance-audit-logger' ), route: 'exports' },
				},
				{ id: 'timeline', label: __( 'Forensic Timeline', 'tbsh-compliance-audit-logger' ), icon: 'timeline', cap: 'view_logs', description: __( 'Events in time order, to reconstruct what happened during an incident.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'anomalies', label: __( 'Behavioral Anomalies', 'tbsh-compliance-audit-logger' ), icon: 'anomaly', cap: 'view_analytics', description: __( 'Unusual activity compared with each user’s normal behaviour, such as sign-ins at odd hours.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'analytics', label: __( 'Advanced Analytics', 'tbsh-compliance-audit-logger' ), icon: 'analytics', cap: 'view_analytics', description: __( 'Activity trends by day, hour, category and user.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'alerts', label: __( 'Alert Center', 'tbsh-compliance-audit-logger' ), icon: 'alerts', cap: 'view_alerts', description: __( 'Alerts sent by email, chat and webhooks, and whether they arrived.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'sessions', label: __( 'Active Sessions', 'tbsh-compliance-audit-logger' ), icon: 'user', cap: 'manage_sessions', description: __( 'Who is signed in right now. End a session to sign someone out.', 'tbsh-compliance-audit-logger' ) },
			],
		},
		{
			label: __( 'Response & Governance', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'incidents', label: __( 'Incident Center', 'tbsh-compliance-audit-logger' ), icon: 'incidents', cap: 'view_incidents', description: __( 'Security incidents, their investigation and reporting deadlines.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'risks', label: __( 'Risk Register', 'tbsh-compliance-audit-logger' ), icon: 'risk', cap: 'view_risks', description: __( 'Known risks, their owners and how they are treated.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'vendors', label: __( 'Vendor Governance', 'tbsh-compliance-audit-logger' ), icon: 'vendors', cap: 'view_vendors', description: __( 'Third-party suppliers, plugins and services, and their risk.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'resilience', label: __( 'Operational Resilience', 'tbsh-compliance-audit-logger' ), icon: 'resilience', cap: 'view_analytics', description: __( 'Backups, recovery tests and drills that show the site can recover.', 'tbsh-compliance-audit-logger' ) },
			],
		},
		{
			label: __( 'Evidence & Reporting', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'evidence', label: __( 'Evidence Vault', 'tbsh-compliance-audit-logger' ), icon: 'evidence', cap: 'view_evidence', description: __( 'Signed snapshots of configuration and logs, kept as audit evidence.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'integrity', label: __( 'Integrity Center', 'tbsh-compliance-audit-logger' ), icon: 'integrity', cap: 'view_logs', description: __( 'Check that the log, WordPress core and plugin files have not been altered.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'reports', label: __( 'Compliance Reports', 'tbsh-compliance-audit-logger' ), icon: 'reports', cap: 'view_reports', description: __( 'Generate signed reports for auditors and management.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'exports', label: __( 'Export Center', 'tbsh-compliance-audit-logger' ), icon: 'export', cap: 'export_data', description: __( 'Download log entries as CSV or JSON for analysis or archiving.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'auditor', label: __( 'Auditor Access', 'tbsh-compliance-audit-logger' ), icon: 'auditor', cap: 'manage_auditor_access', description: __( 'Give an external auditor time-limited, read-only access.', 'tbsh-compliance-audit-logger' ) },
			],
		},
		{
			label: __( 'System', 'tbsh-compliance-audit-logger' ),
			items: [
				{ id: 'health', label: __( 'System Health', 'tbsh-compliance-audit-logger' ), icon: 'health', cap: 'view_logs', description: __( 'Whether logging, scheduled tasks and storage are working correctly.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'settings', label: __( 'Settings', 'tbsh-compliance-audit-logger' ), icon: 'settings', cap: 'manage_settings', description: __( 'Choose what is logged, how long it is kept, and where alerts go.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'help', label: __( 'Help & Reference', 'tbsh-compliance-audit-logger' ), icon: 'help', cap: null, description: __( 'How each feature works, and answers to common questions.', 'tbsh-compliance-audit-logger' ) },
				{ id: 'setup', label: __( 'Setup Assistant', 'tbsh-compliance-audit-logger' ), icon: 'check', cap: 'manage_settings', hidden: true, description: __( 'Recommended settings for your compliance frameworks in four steps.', 'tbsh-compliance-audit-logger' ) },
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
	const current = allItems.find( ( i ) => i.id === route );

	// Redirect away from unknown or unauthorized routes.
	useEffect( () => {
		if ( ! current || ! isAllowed( current ) ) {
			const fallback = visibleGroups.length ? visibleGroups[ 0 ].items[ 0 ].id : defaultRoute;
			if ( fallback !== route ) {
				window.location.hash = `#/${ fallback }`;
			}
		}
	}, [ route ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Announce the new page: update the browser tab title and move focus to the page heading.
	useEffect( () => {
		if ( current ) {
			document.title = `${ current.label } ‹ ${ baseTitle.current }`;
		}
		if ( firstRender.current ) {
			firstRender.current = false;
			return;
		}
		if ( headingRef.current ) {
			headingRef.current.focus();
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
			case 'network':
				return <SiteNetwork />;
			default:
				return <Dashboard navigate={ navigate } />;
		}
	};

	const settings = window.tbshCalApiSettings || {};
	const showSetupBanner = ! auditor && settings.setupState === 'pending' && route !== 'setup' && can( 'manage_settings' );
	const action = current && current.action && allItems.find( ( i ) => i.id === current.action.route );
	const showAction = action && isAllowed( action );

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
					<button type="button" className="tbsh-btn tbsh-btn-secondary" onClick={ toggleTheme } aria-pressed={ darkMode }>
						<Icon name={ darkMode ? 'sun' : 'moon' } style={ { fontSize: '18px' } } />
						{ __( 'Dark mode', 'tbsh-compliance-audit-logger' ) }
					</button>
				</div>
			</header>

			<div className="tbsh-app-layout">
				<div>
					<button
						type="button"
						className="tbsh-btn tbsh-btn-secondary tbsh-nav-toggle"
						aria-expanded={ navOpen }
						aria-controls="tbsh-cal-nav"
						onClick={ () => setNavOpen( ! navOpen ) }
					>
						<span className="tbsh-row">
							<Icon name="menu" />
							{ __( 'Menu', 'tbsh-compliance-audit-logger' ) }
						</span>
						<span className="tbsh-nav-toggle-current">{ current ? current.label : '' }</span>
					</button>
					<nav id="tbsh-cal-nav" className="tbsh-sidebar-nav" data-open={ navOpen ? 'true' : 'false' } aria-label={ __( 'Compliance navigation', 'tbsh-compliance-audit-logger' ) }>
						{ visibleGroups.map( ( group ) => (
							<div key={ group.label } role="group" aria-label={ group.label }>
								<div className="tbsh-nav-group" aria-hidden="true">
									{ group.label }
								</div>
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
				</div>

				<main className="tbsh-app-content">
					{ current && (
						<div className="tbsh-page-header">
							<div className="tbsh-page-header-text">
								<h2 className="tbsh-page-title" ref={ headingRef } tabIndex="-1">
									{ current.label }
								</h2>
								{ current.description && <p className="tbsh-page-description">{ current.description }</p> }
							</div>
							{ showAction && (
								<div className="tbsh-page-actions">
									<button type="button" className="tbsh-btn tbsh-btn-primary" onClick={ () => navigate( action.id ) }>
										{ current.action.label }
									</button>
								</div>
							) }
						</div>
					) }
					{ showSetupBanner && (
						<div className="tbsh-card" role="region" aria-label={ __( 'Finish setup', 'tbsh-compliance-audit-logger' ) } style={ { padding: '16px 20px', marginBottom: '24px', borderInlineStart: '4px solid var(--tbsh-primary)' } }>
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
					<Suspense fallback={ <SkeletonLoader rows={ 4 } cols={ 3 } /> }>{ renderPage() }</Suspense>
				</main>
			</div>
		</div>
	);
}
