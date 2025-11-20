import { tabConfigs } from '../useTerminalTabs.js';

/**
 * Builds the navigation configuration for the terminal sidebar
 * @param {Object} dependencies - Functions and refs needed for navigation
 * @returns {Array} Navigation sections configuration
 */
export function buildNavigationConfig({
	isTabActive,
	openTab,
	toggleAi,
	toggleHistory,
	toggleTemplates,
	toggleDatabase,
	toggleMigrations,
	startDatabaseScan,
	toggleRoutes,
	toggleControllers,
	toggleModelDiagram,
	startScan,
	toggleCommands,
	toggleIssues,
	toggleTraits,
	toggleServices,
	toggleRequests,
	toggleProviders,
	toggleMiddleware,
	toggleJobs,
	toggleHorizon,
	toggleExceptions,
	toggleCommandClasses,
	toggleClasses,
	toggleLogs,
	showHelp,
	openSettings,
	issuesCounter,
}) {
	const sections = [
		{
			id: 'explore',
			title: 'EXPLORE',
			priority: 'primary',
			defaultExpanded: true,
			items: [
				{
					id: 'routes',
					label: 'Routes',
					icon: 'M13 7l5 5m0 0l-5 5m5-5H6',
					action: toggleRoutes,
					isActive: () => isTabActive('routes'),
					priority: 'secondary',
					keywords: ['routes', 'endpoints', 'api']
				},
				{
					id: 'controllers',
					label: 'Controllers',
					icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
					action: toggleControllers,
					isActive: () => isTabActive('controllers'),
					priority: 'secondary',
					keywords: ['controllers', 'http', 'logic']
				},
				{
					id: 'model-diagram',
					label: 'Models (Diagram)',
					icon: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
					action: toggleModelDiagram,
					isActive: () => isTabActive('model-diagram'),
					priority: 'secondary',
					keywords: ['models', 'diagram', 'eloquent', 'relationships']
				},
				{
					id: 'classes',
					label: 'Classes',
					icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
					action: toggleClasses,
					isActive: () => isTabActive('classes'),
					priority: 'tertiary',
					keywords: ['classes']
				},
				{
					id: 'traits',
					label: 'Traits',
					icon: 'M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01',
					action: toggleTraits,
					isActive: () => isTabActive('traits'),
					priority: 'tertiary',
					keywords: ['traits']
				},
				{
					id: 'providers',
					label: 'Providers',
					icon: 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
					action: toggleProviders,
					isActive: () => isTabActive('providers'),
					priority: 'tertiary',
					keywords: ['providers', 'service providers', 'boot']
				},
				{
					id: 'services',
					label: 'Services',
					icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
					action: toggleServices,
					isActive: () => isTabActive('services'),
					priority: 'tertiary',
					keywords: ['services', 'logic']
				},
				{
					id: 'requests',
					label: 'Requests',
					icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
					action: toggleRequests,
					isActive: () => isTabActive('requests'),
					priority: 'tertiary',
					keywords: ['requests', 'validation']
				},
				{
					id: 'middleware',
					label: 'Middleware',
					icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
					action: toggleMiddleware,
					isActive: () => isTabActive('middleware'),
					priority: 'tertiary',
					keywords: ['middleware', 'layers']
				},
				{
					id: 'command-classes',
					label: 'Command Classes',
					icon: 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
					action: toggleCommandClasses,
					isActive: () => isTabActive('command-classes'),
					priority: 'tertiary',
					keywords: ['commands', 'classes']
				}
			]
		},
		{
			id: 'database',
			title: 'DATABASE',
			priority: 'primary',
			defaultExpanded: true,
			items: [
				{
					id: 'database',
					label: 'Explorer',
					icon: 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
					action: toggleDatabase,
					isActive: () => isTabActive('database'),
					priority: 'primary',
					keywords: ['database', 'sql', 'query', 'table']
				},
				{
					id: 'migrations',
					label: 'Migrations',
					icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
					action: toggleMigrations,
					isActive: () => isTabActive('migrations'),
					priority: 'primary',
					keywords: ['migrations', 'schema', 'alter']
				}
			]
		},
		{
			id: 'analyze',
			title: 'ANALYZE',
			priority: 'secondary',
			defaultExpanded: true,
			items: [
				{
					id: 'scan-config',
					label: 'Code Scans',
					icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
					action: startScan,
					isActive: () => isTabActive('scan-config') || isTabActive('scan-results'),
					priority: 'secondary',
					keywords: ['scan', 'codebase', 'analyze', 'security']
				},
				{
					id: 'database-scan-config',
					label: 'DB Scans',
					icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
					action: startDatabaseScan,
					isActive: () => isTabActive('database-scan-config') || isTabActive('database-scan-results'),
					priority: 'secondary',
					keywords: ['scan', 'database', 'schema', 'analyze']
				}
			]
		},
		{
			id: 'issues',
			title: 'ISSUES',
			priority: 'secondary',
			defaultExpanded: true,
			items: [
				{
					id: 'issues',
					label: 'Issues',
					icon: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
					action: toggleIssues,
					isActive: () => isTabActive('issues'),
					priority: 'secondary',
					keywords: ['issues', 'bugs', 'problems'],
					badge: issuesCounter
				}
			]
		},
		{
			id: 'tools',
			title: 'TOOLS',
			priority: 'secondary',
			defaultExpanded: true,
			items: [
				{
					id: 'commands',
					label: 'Artisan Commands',
					icon: 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4',
					action: toggleCommands,
					isActive: () => isTabActive('commands'),
					priority: 'secondary',
					keywords: ['artisan', 'cli']
				},
				{
					id: 'templates',
					label: 'Templates',
					icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
					action: toggleTemplates,
					isActive: () => isTabActive('templates'),
					priority: 'secondary',
					keywords: ['templates', 'snippets']
				},
				{
					id: 'history',
					label: 'History',
					icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
					action: toggleHistory,
					isActive: () => isTabActive('history'),
					priority: 'tertiary',
					keywords: ['history', 'past', 'commands']
				}
			]
		},
		{
			id: 'queues',
			title: 'QUEUES',
			priority: 'secondary',
			defaultExpanded: true,
			items: [
				{
					id: 'jobs',
					label: 'Jobs',
					icon: 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
					action: toggleJobs,
					isActive: () => isTabActive('jobs'),
					priority: 'secondary',
					keywords: ['jobs', 'queues', 'workers']
				},
				{
					id: 'horizon',
					label: 'Horizon',
					icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
					action: toggleHorizon,
					isActive: () => isTabActive('horizon'),
					priority: 'secondary',
					keywords: ['horizon', 'queue', 'monitoring', 'dashboard']
				},
				{
					id: 'exceptions',
					label: 'Exceptions',
					icon: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
					action: toggleExceptions,
					isActive: () => isTabActive('exceptions'),
					priority: 'secondary',
					keywords: ['exceptions', 'errors']
				}
			]
		},
		{
			id: 'system',
			title: 'SYSTEM',
			priority: 'secondary',
			defaultExpanded: true,
			items: [
				{
					id: 'logs',
					label: 'Logs',
					icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
					action: toggleLogs,
					isActive: () => isTabActive('logs'),
					priority: 'secondary',
					keywords: ['logs', 'errors', 'debug']
				},
				{
					id: 'settings',
					label: 'Settings',
					icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
					icon2: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
					action: openSettings,
					isActive: () => isTabActive('settings'),
					priority: 'primary',
					keywords: ['settings', 'config', 'api key', 'preferences']
				},
				{
					id: 'help',
					label: 'Help',
					icon: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
					action: showHelp,
					isActive: () => false,
					priority: 'tertiary',
					keywords: ['help', 'docs']
				}
			]
		}
	];

	return sections;
}

