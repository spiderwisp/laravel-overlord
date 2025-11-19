<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';
import Swal from '../utils/swalConfig';
import { useOverlordApi } from './useOverlordApi';
import TerminalHistory from './Terminal/TerminalHistory.vue';
import TerminalTemplates from './Terminal/TerminalTemplates.vue';
import TerminalOutput from './Terminal/TerminalOutput.vue';
import TerminalModelDiagram from './Terminal/TerminalModelDiagram.vue';
import TerminalControllers from './Terminal/TerminalControllers.vue';
import TerminalClasses from './Terminal/TerminalClasses.vue';
import TerminalTraits from './Terminal/TerminalTraits.vue';
import TerminalServices from './Terminal/TerminalServices.vue';
import TerminalRequests from './Terminal/TerminalRequests.vue';
import TerminalProviders from './Terminal/TerminalProviders.vue';
import TerminalMiddleware from './Terminal/TerminalMiddleware.vue';
import TerminalJobs from './Terminal/TerminalJobs.vue';
import TerminalExceptions from './Terminal/TerminalExceptions.vue';
import TerminalCommandClasses from './Terminal/TerminalCommandClasses.vue';
import TerminalMigrations from './Terminal/TerminalMigrations.vue';
import TerminalCommands from './Terminal/TerminalCommands.vue';
import TerminalFavorites from './Terminal/TerminalFavorites.vue';
import TerminalAi from './Terminal/TerminalAi.vue';
import TerminalHorizon from './Terminal/TerminalHorizon.vue';
import TerminalLogs from './Terminal/TerminalLogs.vue';
import TerminalIssues from './Terminal/TerminalIssues.vue';
import TerminalScanResults from './Terminal/TerminalScanResults.vue';
import TerminalScanConfig from './Terminal/TerminalScanConfig.vue';
import TerminalScanHistory from './Terminal/TerminalScanHistory.vue';
import TerminalDatabaseScanResults from './Terminal/TerminalDatabaseScanResults.vue';
import TerminalDatabaseScanConfig from './Terminal/TerminalDatabaseScanConfig.vue';
import TerminalDatabaseScanHistory from './Terminal/TerminalDatabaseScanHistory.vue';
import TerminalDatabase from './Terminal/TerminalDatabase.vue';
import TerminalThemeToggle from './Terminal/TerminalThemeToggle.vue';
import TerminalSettings from './Terminal/TerminalSettings.vue';
import TerminalRoutes from './Terminal/TerminalRoutes.vue';
import { useTerminalTheme, initThemeRoot } from './useTerminalTheme.js';
import { useTerminalFont, initFontRoot } from './useTerminalFont.js';
// New composables
import { useTerminalTabs, tabConfigs } from './useTerminalTabs.js';
import { useTerminalCommands } from './useTerminalCommands.js';
import { useTerminalAi } from './useTerminalAi.js';
import { useTerminalScans } from './useTerminalScans.js';
import { useTerminalDatabaseScans } from './useTerminalDatabaseScans.js';
import { useTerminalIssues } from './useTerminalIssues.js';
import { useTerminalFavorites } from './useTerminalFavorites.js';
import { useTerminalResize } from './useTerminalResize.js';
// New components
import TerminalSidebar from './Terminal/TerminalSidebar.vue';
import TerminalTabBar from './Terminal/TerminalTabBar.vue';
import TerminalInput from './Terminal/TerminalInput.vue';
import TerminalFavoritesTray from './Terminal/TerminalFavoritesTray.vue';
import TerminalResizeHandle from './Terminal/TerminalResizeHandle.vue';
import TerminalToggleButton from './Terminal/TerminalToggleButton.vue';

// Get API base URL
const api = useOverlordApi();

// Configure Swal to use higher z-index so toasts appear above terminal (z-index: 10002)
const swalStyleElement = ref(null);

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	floating: {
		type: Boolean,
		default: true, // Default to floating mode for backward compatibility
	},
});

const emit = defineEmits(['update:visible', 'close']);

const isOpen = computed({
	get: () => props.visible,
	set: (value) => emit('update:visible', value),
});

// Refs for component communication
const outputContainerRef = ref(null);
const inputRef = ref(null);
const favoritesRef = ref(null);
const aiRef = ref(null);
const scanHistoryRef = ref(null);
const databaseScanHistoryRef = ref(null);

// Sidebar navigation state
const sidebarCollapsed = ref(false);

// Horizon state
const horizonInstalled = ref(false);

// Issues state
const issuePrefillData = ref(null);
const logsNavigateTo = ref(null);

// Dropdown state (keeping for backward compatibility, but will be replaced by sidebar)
const showSettingsDropdown = ref(false);
const showCommandsDropdown = ref(false);
const showExplorerDropdown = ref(false);
const showToolsDropdown = ref(false);
const showQueriesDropdown = ref(false);

// Initialize composables
const tabs = useTerminalTabs();
const { activeTab, openTabs, isTabOpen, isTabActive, openTab, closeTab, closeAllTabs, closeOtherTabs, switchTab, ensureTabOpen } = tabs;

// Helper functions for composables
function scrollToBottom() {
	if (outputContainerRef.value && typeof outputContainerRef.value.scrollToBottom === 'function') {
		outputContainerRef.value.scrollToBottom();
	}
}

function focusInput() {
	if (inputRef.value && typeof inputRef.value.focus === 'function') {
		inputRef.value.focus();
	}
}

// Initialize commands composable
const commands = useTerminalCommands(api, {
	ensureTabOpen,
	scrollToBottom,
	focusInput,
	inputRef,
});
const { commandInput, inputMode, commandHistory, historyIndex, outputHistory, isExecuting, addOutput, clearTerminal, clearSession, executeCommand, executeShellCommand, handleKeyDown, useCommandFromHistory } = commands;

// Initialize AI composable
const ai = useTerminalAi(api, { ensureTabOpen });
const { aiConversationHistory, selectedAiModel, isSendingAi, loadAiStatus, sendAiMessage } = ai;

// Initialize scans composables
const scans = useTerminalScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab });
const { activeScanId, handleStartScan, startScanPolling, stopScanPolling } = scans;

const databaseScans = useTerminalDatabaseScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab });
const { activeDatabaseScanId, handleStartDatabaseScan, startDatabaseScanPolling, stopDatabaseScanPolling } = databaseScans;

// Initialize issues composable
const issues = useTerminalIssues(api);
const { issuesStats, issuesCounter, loadIssuesStats, startIssuesStatsPolling, stopIssuesStatsPolling } = issues;

// Initialize favorites composable
const favorites = useTerminalFavorites({ ensureTabOpen });
const { showFavoritesTray, topFavorites, getFavoriteTypeColor, getFavoriteTypeLabel, handleFavoritesTrayHover, handleFavoritesTrayLeave, toggleFavorites: toggleFavoritesBase } = favorites;

// Wrapper for toggleFavorites with tab management
function toggleFavorites() {
	toggleFavoritesBase(isTabActive, closeTab);
}

// Initialize resize composable
const resize = useTerminalResize();
const { terminalHeight, isResizing, terminalDrawerStyle, loadTerminalHeight, startResize, handleResize, stopResize } = resize;

// Navigation config will be computed and passed to TerminalSidebar

// Navigation configuration structure
const navigationConfig = computed(() => {
	const sections = [
		{
			id: 'general',
			title: 'GENERAL',
			priority: 'primary',
			defaultExpanded: true,
			items: [
				{
					id: 'terminal',
					label: 'Terminal',
					icon: 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
					action: () => openTab('terminal'),
					isActive: () => isTabActive('terminal'),
					priority: 'primary',
					keywords: ['terminal', 'console', 'tinker', 'shell']
				},
				{
					id: 'ai',
					label: 'AI Chat',
					icon: 'M13 10V3L4 14h7v7l9-11h-7z',
					action: toggleAi,
					isActive: () => isTabActive('ai'),
					priority: 'primary',
					keywords: ['ai', 'assistant', 'chat', 'help', 'gpt']
				},
				{
					id: 'history',
					label: 'History',
					icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
					action: toggleHistory,
					isActive: () => isTabActive('history'),
					priority: 'primary',
					keywords: ['history', 'past', 'commands']
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
				},
				{
					id: 'database-scan-config',
					label: 'DB Scans',
					icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
					action: startDatabaseScan,
					isActive: () => isTabActive('database-scan-config') || isTabActive('database-scan-results'),
					priority: 'primary',
					keywords: ['scan', 'database', 'schema', 'analyze']
				}
			]
		},
		{
			id: 'codebase',
			title: 'CODEBASE',
			priority: 'secondary',
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
					id: 'scan-config',
					label: 'Code Scans',
					icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
					action: startScan,
					isActive: () => isTabActive('scan-config') || isTabActive('scan-results'),
					priority: 'secondary',
					keywords: ['scan', 'codebase', 'analyze', 'security']
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
					id: 'issues',
					label: 'Issues',
					icon: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
					action: toggleIssues,
					isActive: () => isTabActive('issues'),
					priority: 'secondary',
					keywords: ['issues', 'bugs', 'problems'],
					badge: issuesCounter
				},
				{
					id: 'templates',
					label: 'Templates',
					icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
					action: toggleTemplates,
					isActive: () => isTabActive('templates'),
					priority: 'secondary',
					keywords: ['templates', 'snippets']
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
					id: 'jobs',
					label: 'Jobs / Horizon',
					icon: 'M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
					action: toggleJobs,
					isActive: () => isTabActive('jobs') || isTabActive('horizon'),
					priority: 'secondary',
					keywords: ['jobs', 'queues', 'workers', 'horizon']
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
			id: 'reference',
			title: 'REFERENCE',
			priority: 'tertiary',
			defaultExpanded: false,
			items: [
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
			id: 'footer',
			title: '',
			priority: 'tertiary',
			defaultExpanded: false,
			items: [
				{
					id: 'help',
					label: 'Help',
					icon: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
					action: showHelp,
					isActive: () => false,
					priority: 'tertiary',
					keywords: ['help', 'docs']
				},
				{
					id: 'settings',
					label: 'UI Settings',
					icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
					icon2: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
					action: openSettings,
					isActive: () => isTabActive('settings'),
					priority: 'tertiary',
					keywords: ['settings', 'config']
				}
			]
		}
	];

	return sections;
});

// Navigation config computed - will be passed to TerminalSidebar

// Theme and font system
const terminalDrawerRef = ref(null);
const { currentTheme, setTheme } = useTerminalTheme();
const { fontSize, fontSizeMin, fontSizeMax, adjustFontSize: adjustFontSizeNew } = useTerminalFont();

// Wrapper ref for global theme application
const wrapperRef = ref(null);

// Initialize theme and font system
function initializeThemeAndFont() {
	// Apply to wrapper so all components inherit
	const rootElement = wrapperRef.value || terminalDrawerRef.value;
	if (rootElement) {
		initThemeRoot(rootElement);
		initFontRoot(rootElement);
	}
}

// Open settings modal
function openSettings(event) {
	if (event) {
		event.stopPropagation();
		event.preventDefault();
	}
	// Open settings as a tab instead of modal
	if (!isTabOpen('settings')) {
		openTabs.value.push(tabConfigs.settings);
	}
	switchTab('settings');
	showSettingsDropdown.value = false;
}

// Handle resize start (wraps composable function)
function handleStartResize(event) {
	startResize(event);
}

// Insert command from templates/snippets
function insertCommand(command) {
	// Preserve line breaks when inserting code
	commandInput.value = command;
	nextTick(() => {
		if (inputRef.value && typeof inputRef.value.focus === 'function') {
			inputRef.value.focus();
		}
		if (inputRef.value && typeof inputRef.value.autoResize === 'function') {
			inputRef.value.autoResize();
		}
	});
}

// Insert command from AI
function insertCommandFromAi(command) {
	if (!command) {
		return;
	}
	// Preserve line breaks when inserting code
	commandInput.value = command;
	// Switch to terminal tab
	ensureTabOpen('terminal');
	nextTick(() => {
		if (inputRef.value && typeof inputRef.value.focus === 'function') {
			inputRef.value.focus();
		}
		if (inputRef.value && typeof inputRef.value.autoResize === 'function') {
			inputRef.value.autoResize();
		}
	});
}

// Execute command from AI
async function executeCommandFromAi(command) {
	if (!command) {
		return;
	}
	// Preserve line breaks when inserting code
	commandInput.value = command;
	// Switch to terminal tab
	ensureTabOpen('terminal');
	nextTick(async () => {
		// Check if it's AI mode
		if (inputMode.value === 'ai') {
			await sendAiMessage(command, aiRef.value);
			commandInput.value = '';
			if (inputRef.value && typeof inputRef.value.autoResize === 'function') {
				inputRef.value.autoResize();
			}
			nextTick(() => {
				if (inputRef.value && typeof inputRef.value.focus === 'function') {
					inputRef.value.focus();
				}
			});
		} else {
			await executeCommand();
		}
	});
}

// Execute command from favorite
function executeCommandFromFavorite(command) {
	commandInput.value = command;
	nextTick(() => {
		executeCommand();
	});
}

// Handle add to favorites from any source
function handleAddToFavorites(data) {
	// Open favorites panel and trigger add modal
	ensureTabOpen('favorites');
	nextTick(() => {
		if (favoritesRef.value) {
			favoritesRef.value.addFavorite(data);
		}
	});
}

// Add current command to favorites
function addCurrentCommandToFavorites() {
	if (!commandInput.value.trim()) {
		Swal.fire({
			toast: true,
			icon: 'warning',
			title: 'Please enter a command first',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}
	
	handleAddToFavorites({
		name: '',
		description: '',
		category: '',
		tags: [],
		content: commandInput.value.trim(),
		type: 'custom',
		metadata: {},
	});
}

// Copy output to clipboard
async function copyOutputToClipboard() {
	if (outputHistory.value.length === 0) {
		Swal.fire({
			toast: true,
			icon: 'info',
			title: 'No output to copy',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}
	
	// Build text representation of all output
	let text = '';
	outputHistory.value.forEach((item, index) => {
		if (item.type === 'command') {
			text += `$ ${item.output}\n`;
		} else if (item.type === 'json' || item.type === 'object') {
			const data = item.output?.formatted || item.output;
			if (typeof data === 'string') {
				text += data + '\n';
			} else {
				text += JSON.stringify(data, null, 2) + '\n';
			}
		} else {
			const output = item.output?.formatted || item.output || item.raw;
			if (typeof output === 'string') {
				text += output + '\n';
			} else {
				text += String(output) + '\n';
			}
		}
		text += '\n';
	});
	
	try {
		await navigator.clipboard.writeText(text);
		Swal.fire({
			toast: true,
			icon: 'success',
			title: 'Output copied to clipboard',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
	} catch (err) {
		// Fallback for older browsers
		const textArea = document.createElement('textarea');
		textArea.value = text;
		textArea.style.position = 'fixed';
		textArea.style.opacity = '0';
		document.body.appendChild(textArea);
		textArea.select();
		try {
			document.execCommand('copy');
			Swal.fire({
				toast: true,
				icon: 'success',
				title: 'Output copied to clipboard',
				position: 'bottom-end',
				showConfirmButton: false,
				timer: 2000,
			});
		} catch (e) {
			Swal.fire({
				toast: true,
				icon: 'error',
				title: 'Failed to copy to clipboard',
				position: 'bottom-end',
				showConfirmButton: false,
				timer: 2000,
			});
		}
		document.body.removeChild(textArea);
	}
}

// Execute command wrapper - handles AI mode
async function handleExecuteCommand() {
	if (!commandInput.value.trim() || isExecuting.value || isSendingAi.value) {
		return;
	}

	// Preserve the full command including line breaks for execution
	let command = commandInput.value.trim();
	
	// Check for mode overrides via prefixes
	if (command.startsWith('/shell')) {
		inputMode.value = 'shell';
		command = command.substring(6).trim();
	} else if (command.startsWith('/ai')) {
		inputMode.value = 'ai';
		command = command.substring(3).trim();
	} else if (command.startsWith('/tinker')) {
		inputMode.value = 'tinker';
		command = command.substring(7).trim();
	}

	if (!command) {
		// Just changing mode
		commandInput.value = '';
		return;
	}

	// Handle based on input mode
	if (inputMode.value === 'shell') {
		await executeShellCommand(command);
		return;
	}
	
	if (inputMode.value === 'ai') {
		// Send to AI
		await sendAiMessage(command, aiRef.value);
		
		// Clear input
		commandInput.value = '';
		if (inputRef.value && typeof inputRef.value.autoResize === 'function') {
			inputRef.value.autoResize();
		}
		
		// Focus input after sending
		nextTick(() => {
			if (inputRef.value && typeof inputRef.value.focus === 'function') {
				inputRef.value.focus();
			}
		});
		return;
	}
	
	// Regular command execution (Tinker) - handled by composable
	await executeCommand();
}

// Handle navigation to reference (cross-reference system)
function handleNavigateToReference(navData) {
	if (!navData || !navData.type) return;
	
	const typeMap = {
		'controller': 'controllers',
		'middleware': 'middleware', // Future feature
		'model': 'model-diagram',
		'service': 'services', // Future feature
		'trait': 'traits', // Future feature
		'route': 'routes',
	};
	
	const targetTab = typeMap[navData.type];
	if (!targetTab || !tabConfigs[targetTab]) {
		console.warn('Unknown navigation type:', navData.type);
		return;
	}
	
	// Open tab with item identifier
	openTab(targetTab, {
		itemId: navData.identifier,
		highlight: true,
		method: navData.method,
	});
}

async function handleTabContextMenu(event, tabId) {
	// Simple context menu - could be enhanced with a proper menu component
	if (tabId === 'terminal') return;
	
	const result = await Swal.fire({
		icon: 'question',
		title: 'Close other tabs?',
		text: 'Do you want to close all other tabs?',
		showCancelButton: true,
		confirmButtonText: 'Yes',
		cancelButtonText: 'Cancel',
	});
	
	if (result.isConfirmed) {
		closeOtherTabs(tabId);
	}
}

// Tab functions are now from composable

// Toggle functions - now use tab system
function toggleHistory() {
	if (isTabActive('history')) {
		closeTab('history');
	} else {
		ensureTabOpen('history');
	}
}

function toggleTemplates() {
	if (isTabActive('templates')) {
		closeTab('templates');
	} else {
		ensureTabOpen('templates');
	}
}

function toggleDatabase() {
	if (isTabActive('database')) {
		closeTab('database');
	} else {
		ensureTabOpen('database');
	}
}

// toggleFavorites is provided by useTerminalFavorites composable

// Insert favorite command from tray
function insertFavoriteCommand(favorite) {
	if (favorite.content) {
		insertCommand(favorite.content);
	}
	showFavoritesTray.value = false;
}

// Execute favorite command from tray
function executeFavoriteCommand(favorite) {
	if (favorite.content) {
		commandInput.value = favorite.content;
		handleExecuteCommand();
	}
	showFavoritesTray.value = false;
}

// Handle input focus
function handleInputFocus() {
	// Switch to terminal tab when input is focused
	if (activeTab.value !== 'terminal') {
		activeTab.value = 'terminal';
	}
}

function toggleAi() {
	// Toggle input mode
	if (inputMode.value === 'ai') {
		inputMode.value = 'tinker';
	} else {
		inputMode.value = 'ai';
	}
	
	// Switch to terminal tab (where input is)
	activeTab.value = 'terminal';
	
	// Load AI status if switching to AI
	if (inputMode.value === 'ai') {
		loadAiStatus();
	}
	
	// Focus the input
	nextTick(() => {
		if (inputRef.value && typeof inputRef.value.focus === 'function') {
			inputRef.value.focus();
		}
	});
}

function toggleShell() {
	// Toggle input mode
	if (inputMode.value === 'shell') {
		inputMode.value = 'tinker';
	} else {
		inputMode.value = 'shell';
	}
	
	// Switch to terminal tab (where input is)
	activeTab.value = 'terminal';
	
	// Focus the input
	nextTick(() => {
		if (inputRef.value && typeof inputRef.value.focus === 'function') {
			inputRef.value.focus();
		}
	});
}

function toggleModelDiagram() {
	if (isTabActive('model-diagram')) {
		closeTab('model-diagram');
	} else {
		ensureTabOpen('model-diagram');
	}
}

function toggleControllers() {
	if (isTabActive('controllers')) {
		closeTab('controllers');
	} else {
		ensureTabOpen('controllers');
	}
}

function toggleRoutes() {
	if (isTabActive('routes')) {
		closeTab('routes');
	} else {
		ensureTabOpen('routes');
	}
}

function toggleClasses() {
	if (isTabActive('classes')) {
		closeTab('classes');
	} else {
		ensureTabOpen('classes');
	}
}

function toggleTraits() {
	if (isTabActive('traits')) {
		closeTab('traits');
	} else {
		ensureTabOpen('traits');
	}
}

function toggleServices() {
	if (isTabActive('services')) {
		closeTab('services');
	} else {
		ensureTabOpen('services');
	}
}

function toggleRequests() {
	if (isTabActive('requests')) {
		closeTab('requests');
	} else {
		ensureTabOpen('requests');
	}
}

function toggleProviders() {
	if (isTabActive('providers')) {
		closeTab('providers');
	} else {
		ensureTabOpen('providers');
	}
}

function toggleMiddleware() {
	if (isTabActive('middleware')) {
		closeTab('middleware');
	} else {
		ensureTabOpen('middleware');
	}
}

function toggleJobs() {
	if (isTabActive('jobs')) {
		closeTab('jobs');
	} else {
		ensureTabOpen('jobs');
	}
}

function toggleExceptions() {
	if (isTabActive('exceptions')) {
		closeTab('exceptions');
	} else {
		ensureTabOpen('exceptions');
	}
}

function toggleCommandClasses() {
	if (isTabActive('command-classes')) {
		closeTab('command-classes');
	} else {
		ensureTabOpen('command-classes');
	}
}

// Toggle commands
function toggleMigrations() {
	if (isTabActive('migrations')) {
		closeTab('migrations');
	} else {
		ensureTabOpen('migrations');
	}
}

function toggleCommands() {
	if (isTabActive('commands')) {
		closeTab('commands');
	} else {
		ensureTabOpen('commands');
	}
}

// Toggle Horizon
function toggleHorizon() {
	if (!horizonInstalled.value) return;
	if (isTabActive('horizon')) {
		closeTab('horizon');
	} else {
		ensureTabOpen('horizon');
	}
}

// Toggle Logs
function toggleLogs() {
	if (isTabActive('logs')) {
		closeTab('logs');
	} else {
		ensureTabOpen('logs');
	}
}

// Toggle Issues
function toggleIssues() {
	if (isTabActive('issues')) {
		closeTab('issues');
	} else {
		ensureTabOpen('issues');
	}
}

// Toggle Scan Results
function toggleScanResults() {
	if (isTabActive('scan-results')) {
		closeTab('scan-results');
	} else {
		ensureTabOpen('scan-results');
	}
}

function toggleScanHistory() {
	if (isTabActive('scan-history')) {
		closeTab('scan-history');
	} else {
		ensureTabOpen('scan-history');
	}
	showToolsDropdown.value = false;
}

function handleViewScan(scanId) {
	activeScanId.value = scanId;
	closeTab('scan-history');
	ensureTabOpen('scan-results');
	switchTab('scan-results');
}

function handleViewScanIssues(scanId) {
	// Open issues tab filtered by scan ID
	// For now, just open the scan results which shows issues
	handleViewScan(scanId);
}

function handleScanIssuesCleared() {
	// Refresh the scan history component if it's open
	if (scanHistoryRef.value && scanHistoryRef.value.loadHistory) {
		scanHistoryRef.value.loadHistory();
	}
}

// Open scan configuration
function startScan() {
	ensureTabOpen('scan-config');
	showToolsDropdown.value = false;
}

// Database scan functions
function toggleDatabaseScanHistory() {
	if (isTabActive('database-scan-history')) {
		closeTab('database-scan-history');
	} else {
		ensureTabOpen('database-scan-history');
	}
	showToolsDropdown.value = false;
}

function handleViewDatabaseScan(scanId) {
	activeDatabaseScanId.value = scanId;
	closeTab('database-scan-history');
	ensureTabOpen('database-scan-results');
	switchTab('database-scan-results');
}

function handleViewDatabaseScanIssues(scanId) {
	handleViewDatabaseScan(scanId);
}

function handleDatabaseIssuesCleared() {
	// Refresh the scan history component if it's open
	if (databaseScanHistoryRef.value && databaseScanHistoryRef.value.loadHistory) {
		databaseScanHistoryRef.value.loadHistory();
	}
}

function startDatabaseScan() {
	ensureTabOpen('database-scan-config');
	showToolsDropdown.value = false;
}

// Handle create issue from logs
function handleCreateIssueFromLogs(prefillData) {
	issuePrefillData.value = prefillData;
	ensureTabOpen('issues');
	// Clear prefill data after a short delay to allow modal to open
	nextTick(() => {
		setTimeout(() => {
			issuePrefillData.value = null;
		}, 100);
	});
}

// Handle create issue from terminal
function handleCreateIssueFromTerminal(prefillData) {
	issuePrefillData.value = prefillData;
	ensureTabOpen('issues');
	// Clear prefill data after a short delay to allow modal to open
	nextTick(() => {
		setTimeout(() => {
			issuePrefillData.value = null;
		}, 100);
	});
}

// Handle create issue from scan results
function handleCreateIssueFromScan(prefillData) {
	issuePrefillData.value = prefillData;
	
	// For database scans, keep user on scan results page
	// For other scans, switch to issues tab
	if (prefillData?.source_type === 'database_scan') {
		// Ensure issues tab is open (but don't switch to it) so it can receive prefillData
		// The modal will open as an overlay without switching tabs
	ensureTabOpen('issues');
		// Don't switch tabs - stay on current tab
	} else {
		// For codebase scans, switch to issues tab
		ensureTabOpen('issues');
		switchTab('issues');
	}
	
	// Clear prefill data after a short delay to allow modal to open
	nextTick(() => {
		setTimeout(() => {
			issuePrefillData.value = null;
		}, 100);
	});
}

// Handle navigate to source
function handleNavigateToSource(navigationData) {
	const { type, data } = navigationData;
	
	if (type === 'log') {
		// Navigate to Logs tab
		ensureTabOpen('logs');
		// Set navigation data for TerminalLogs component
		logsNavigateTo.value = { type, data };
		// Clear after navigation is handled
		nextTick(() => {
			setTimeout(() => {
				logsNavigateTo.value = null;
			}, 1000);
		});
	} else if (type === 'terminal') {
		// Navigate to Terminal tab
		ensureTabOpen('terminal');
		// This would require storing command log IDs and matching them
	} else if (type === 'ai') {
		// Navigate to AI tab
		ensureTabOpen('ai');
		// This would require storing conversation IDs and matching them
	}
}

// Load Horizon installation status
async function loadHorizonStatus() {
	try {
		const response = await axios.get(api.horizon.check());
		if (response.data && response.data.success && response.data.result) {
			horizonInstalled.value = response.data.result.installed || false;
		}
	} catch (error) {
		horizonInstalled.value = false;
	}
}

// Show help
async function showHelp() {
	commandInput.value = '';
	isExecuting.value = true;

	try {
		// Use GET request to the help endpoint instead of executing a command
		const response = await axios.get(api.url('help'), {
			timeout: 30000, // 30 seconds should be enough for help content
		});

		if (response.data.success) {
			const result = response.data.result;
			addOutput(result.type, result.output, result.raw);
		} else {
			addOutput('error', {
				formatted: response.data.errors?.[0] || 'Unknown error',
				raw: response.data.errors?.[0] || 'Unknown error',
			});
		}
	} catch (error) {
		addOutput('error', {
			formatted: error.response?.data?.errors?.[0] || error.message || 'Failed to load help',
			raw: error.response?.data?.errors?.[0] || error.message || 'Failed to load help',
		});
	} finally {
		isExecuting.value = false;
		await nextTick();
		scrollToBottom();
		// Refocus input after command execution
		focusInput();
	}
}

// Use command from history - handled by composable

// Handle keyboard input wrapper
function handleKeyDownWrapper(event) {
	// Enter to execute
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault();
		handleExecuteCommand();
		return;
	}

	// Ctrl+L to clear
	if (event.key === 'l' && event.ctrlKey) {
		event.preventDefault();
		clearTerminal();
		return;
	}

	// History navigation handled by composable
	const handled = handleKeyDown(event);
	if (handled) {
		return; // Event was handled by composable
	}
}

// Format output component
function renderOutput(item) {
	if (item.type === 'json' || item.type === 'object') {
		const data = item.output?.formatted || item.output;
		return { component: 'json', data };
	}
	
	if (item.type === 'error') {
		return { component: 'error', data: item.output?.formatted || item.output || item.raw };
	}

	return { component: 'text', data: item.output?.formatted || item.output || item.raw };
}

// Close terminal
function closeTerminal() {
	isOpen.value = false;
	emit('close');
}

// Focus and auto-resize handled by TerminalInput component

// Watch for terminal opening to focus input
watch(isOpen, (newValue) => {
	if (newValue) {
		nextTick(() => {
			focusInput();
		});
	}
});


// Close dropdowns when clicking outside
function handleClickOutside(event) {
	if (!event.target.closest('.terminal-dropdown')) {
		showSettingsDropdown.value = false;
		showCommandsDropdown.value = false;
		showExplorerDropdown.value = false;
		showToolsDropdown.value = false;
		showQueriesDropdown.value = false;
	}
}

onMounted(async () => {
	// Load preferences from localStorage
	loadTerminalHeight();
	// Initialize theme and font after next tick to ensure ref is available
	await nextTick();
	// Try to initialize immediately, and also watch for when drawer opens
	initializeThemeAndFont();
	// Re-initialize when drawer opens to ensure it's applied
	watch(isOpen, (newValue) => {
		if (newValue) {
			nextTick(() => {
				initializeThemeAndFont();
			});
		}
	});
	
	// Load AI status
	loadAiStatus();
	
	// Start issues stats polling when terminal is open
	if (isOpen.value) {
		startIssuesStatsPolling();
	}
	
	// Watch for terminal open/close to start/stop polling
	watch(isOpen, (newValue) => {
		if (newValue) {
			startIssuesStatsPolling();
		} else {
			stopIssuesStatsPolling();
		}
	});
	
	// Ensure highlight.js CSS and JS are loaded immediately
	if (typeof window !== 'undefined') {
		// Load CSS
		if (!document.getElementById('hljs-base-link')) {
			const link = document.createElement('link');
			link.id = 'hljs-base-link';
			link.rel = 'stylesheet';
			link.href = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/default.min.css';
			document.head.appendChild(link);
			
			// Load saved theme preference
			const savedTheme = localStorage.getItem('terminal_code_theme') || 'github-dark';
			const themeLink = document.createElement('link');
			themeLink.id = 'hljs-theme-link';
			themeLink.rel = 'stylesheet';
			themeLink.href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/${savedTheme}.min.css`;
			document.head.appendChild(themeLink);
		}

		// Load JS from CDN (if not already loaded)
		if (!window.hljs && !document.getElementById('hljs-script')) {
			const script = document.createElement('script');
			script.id = 'hljs-script';
			script.src = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
			document.head.appendChild(script);
		}
	}
	
	// Load Horizon status
	loadHorizonStatus();
	
	// Add click outside listener for dropdowns
	document.addEventListener('click', handleClickOutside);
	
	// Add welcome message
		addOutput('text', 'Laravel Overlord - Type commands and press Enter to execute (supports PHP, Laravel, and more)');
	
	// Focus input when terminal is first opened
	if (isOpen.value) {
		nextTick(() => {
			focusInput();
		});
	}
	
	// Add style to ensure SweetAlert2 toasts appear above terminal
	const style = document.createElement('style');
	style.textContent = `
		.swal2-container {
			z-index: 10002 !important;
		}
	`;
	document.head.appendChild(style);
	swalStyleElement.value = style;
});

onUnmounted(() => {
	// Clean up resize listeners (handled by composable)
	stopResize();
	
	// Clean up click outside listener
	document.removeEventListener('click', handleClickOutside);
	
	// Clean up scan polling (handled by composables)
	stopScanPolling();
	stopDatabaseScanPolling();
	
	// Clean up issues stats polling (handled by composable)
	stopIssuesStatsPolling();
	
	// Clean up SweetAlert2 z-index style
	if (swalStyleElement.value && document.head.contains(swalStyleElement.value)) {
		document.head.removeChild(swalStyleElement.value);
	}
});
</script>

<template>
	<div ref="wrapperRef" class="developer-terminal-wrapper" :data-embedded="visible && !props.floating">
		<!-- Toggle Button (fixed at bottom left when closed) -->
		<TerminalToggleButton
			v-if="!isOpen && props.floating"
			@click="isOpen = true"
		/>

		<!-- Terminal Drawer -->
		<transition :name="props.floating ? 'slide-up' : ''">
			<div v-if="isOpen" ref="terminalDrawerRef" class="terminal-drawer" :style="props.floating ? terminalDrawerStyle : {}">
				<!-- Resize Handle (only show when floating) -->
				<TerminalResizeHandle
					v-if="props.floating"
					:is-resizing="isResizing"
					@start-resize="handleStartResize"
				/>
				
				<!-- Terminal Layout with Sidebar -->
				<div class="terminal-layout">
					<!-- Sidebar Navigation -->
					<TerminalSidebar
						v-model:collapsed="sidebarCollapsed"
						:navigation-config="navigationConfig"
						@toggle-section="() => {}"
					/>
					
					<!-- Main Content Area -->
					<div class="terminal-main-content">
						<!-- Favorites Tray (Top-Aligned Full-Width Drawer) -->
						<TerminalFavoritesTray
							:show="showFavoritesTray"
							:top-favorites="topFavorites"
							:get-favorite-type-color="getFavoriteTypeColor"
							:get-favorite-type-label="getFavoriteTypeLabel"
							@insert-favorite="insertFavoriteCommand"
							@execute-favorite="executeFavoriteCommand"
							@toggle-favorites="toggleFavorites"
							@mouseenter="handleFavoritesTrayHover"
							@mouseleave="handleFavoritesTrayLeave"
						/>
						
						<!-- Tab Bar -->
						<TerminalTabBar
							:open-tabs="openTabs"
							:active-tab="activeTab"
							:issues-counter="issuesCounter"
							:favorites-drawer-open="showFavoritesTray"
							@switch-tab="switchTab"
							@close-tab="closeTab"
							@close-other-tabs="closeOtherTabs"
							@toggle-issues="toggleIssues"
						/>

						<!-- Content Area (panels and output) -->
						<div class="terminal-content-area">
							<!-- History View -->
					<TerminalHistory
						:visible="isTabActive('history')"
						@use-command="useCommandFromHistory"
						@close="closeTab('history')"
					/>

					<!-- Templates/Snippets Panel -->
					<TerminalTemplates
						:visible="isTabActive('templates')"
						:current-command="commandInput"
						@insert-command="insertCommand"
						@add-to-favorites="handleAddToFavorites"
						@close="closeTab('templates')"
					/>

					<!-- Model Relationships Diagram -->
					<TerminalModelDiagram
						:visible="isTabActive('model-diagram')"
						@close="closeTab('model-diagram')"
					/>

					<!-- Controllers View -->
					<TerminalControllers
						:visible="isTabActive('controllers')"
						@close="closeTab('controllers')"
						@navigate-to="handleNavigateToReference"
					/>

					<!-- Routes View -->
					<TerminalRoutes
						:visible="isTabActive('routes')"
						@close="closeTab('routes')"
						@navigate-to="handleNavigateToReference"
					/>

					<!-- Classes View -->
					<TerminalClasses
						:visible="isTabActive('classes')"
						@close="closeTab('classes')"
					/>

					<!-- Traits View -->
					<TerminalTraits
						:visible="isTabActive('traits')"
						@close="closeTab('traits')"
					/>

					<!-- Services View -->
					<TerminalServices
						:visible="isTabActive('services')"
						@close="closeTab('services')"
					/>

					<!-- Requests View -->
					<TerminalRequests
						:visible="isTabActive('requests')"
						@close="closeTab('requests')"
					/>

					<!-- Providers View -->
					<TerminalProviders
						:visible="isTabActive('providers')"
						@close="closeTab('providers')"
					/>

					<!-- Middleware View -->
					<TerminalMiddleware
						:visible="isTabActive('middleware')"
						@close="closeTab('middleware')"
					/>

					<!-- Jobs View -->
					<TerminalJobs
						:visible="isTabActive('jobs')"
						@close="closeTab('jobs')"
					/>

					<!-- Exceptions View -->
					<TerminalExceptions
						:visible="isTabActive('exceptions')"
						@close="closeTab('exceptions')"
					/>

					<!-- Command Classes View -->
					<TerminalCommandClasses
						:visible="isTabActive('command-classes')"
						@close="closeTab('command-classes')"
					/>

					<!-- Migrations View -->
					<TerminalMigrations
						:visible="isTabActive('migrations')"
						@close="closeTab('migrations')"
					/>

					<!-- Commands View -->
					<TerminalCommands
						:visible="isTabActive('commands')"
						@close="closeTab('commands')"
						@add-to-favorites="handleAddToFavorites"
					/>

					<!-- Favorites View -->
					<TerminalFavorites
						ref="favoritesRef"
						:visible="isTabActive('favorites')"
						:current-command="commandInput"
						@insert-command="insertCommand"
						@execute-command="executeCommandFromFavorite"
						@close="closeTab('favorites')"
					/>

					<!-- AI View -->
					<TerminalAi
						ref="aiRef"
						:visible="isTabActive('ai')"
						:hide-input="true"
						@insert-command="insertCommandFromAi"
						@execute-command="executeCommandFromAi"
						@close="closeTab('ai')"
					/>

					<!-- Horizon View -->
					<TerminalHorizon
						:visible="isTabActive('horizon')"
						@close="closeTab('horizon')"
					/>

					<!-- Logs View -->
				<TerminalLogs
					:visible="isTabActive('logs')"
					:navigate-to="logsNavigateTo"
					@close="closeTab('logs')"
					@insert-command="insertCommandFromAi"
					@execute-command="executeCommandFromAi"
					@create-issue="handleCreateIssueFromLogs"
				/>

					<!-- Issues View -->
					<TerminalIssues
						:visible="isTabActive('issues')"
						:prefill-data="issuePrefillData"
						@close="closeTab('issues')"
						@create-issue="(issue) => {}"
						@navigate-to-source="handleNavigateToSource"
						@issue-updated="loadIssuesStats"
					/>

							<!-- Scan Results View -->
							<TerminalScanConfig
								v-if="isTabActive('scan-config')"
								:visible="true"
								@close="closeTab('scan-config')"
								@start-scan="handleStartScan"
							/>
							<TerminalScanResults
								v-if="isTabActive('scan-results')"
								:visible="true"
								:scan-id="activeScanId"
								@close="closeTab('scan-results')"
								@create-issue="handleCreateIssueFromScan"
								@issues-cleared="handleScanIssuesCleared"
							/>
							<TerminalScanHistory
								v-if="isTabActive('scan-history')"
								ref="scanHistoryRef"
								:visible="true"
								@close="closeTab('scan-history')"
								@view-scan="handleViewScan"
								@view-issues="handleViewScanIssues"
							/>

							<!-- Database Scan Views -->
							<TerminalDatabaseScanConfig
								v-if="isTabActive('database-scan-config')"
								:visible="true"
								@close="closeTab('database-scan-config')"
								@start-scan="handleStartDatabaseScan"
							/>
							<TerminalDatabaseScanResults
								v-if="isTabActive('database-scan-results')"
								:visible="true"
								:scan-id="activeDatabaseScanId"
								@close="closeTab('database-scan-results')"
								@create-issue="handleCreateIssueFromScan"
								@issues-cleared="handleDatabaseIssuesCleared"
							/>
							<TerminalDatabaseScanHistory
								v-if="isTabActive('database-scan-history')"
								ref="databaseScanHistoryRef"
								:visible="true"
								@close="closeTab('database-scan-history')"
								@view-scan="handleViewDatabaseScan"
								@view-issues="handleViewDatabaseScanIssues"
							/>
							
							<TerminalDatabase
								v-if="isTabActive('database')"
								:visible="true"
								@close="closeTab('database')"
							/>

							<!-- Settings View -->
							<TerminalSettings
								v-if="isTabActive('settings')"
								:visible="true"
								:is-modal="false"
								@close="closeTab('settings')"
							/>

							<!-- Terminal Output Area -->
							<TerminalOutput
								v-if="isTabActive('terminal')"
								ref="outputContainerRef"
								:output-history="outputHistory"
								:is-executing="isExecuting"
								:font-size="fontSize"
								:show-actions="isTabActive('terminal')"
								@insert-command="insertCommandFromAi"
								@execute-command="executeCommandFromAi"
								@create-issue="handleCreateIssueFromTerminal"
								@clear-output="clearTerminal"
								@clear-session="clearSession"
								@close-terminal="closeTerminal"
							/>
						</div>

						<!-- Terminal Input Area -->
						<TerminalInput
							ref="inputRef"
							v-model:command-input="commandInput"
							v-model:input-mode="inputMode"
							:is-executing="isExecuting"
							:is-sending-ai="isSendingAi"
							:font-size="fontSize"
							@execute="handleExecuteCommand"
							@add-to-favorites="addCurrentCommandToFavorites"
							@focus="handleInputFocus"
							@keydown="handleKeyDownWrapper"
						/>
					</div>
				</div>
			</div>
		</transition>

	</div>
</template>

<style scoped>
.developer-terminal-wrapper {
	position: relative;
	z-index: 9999;
	/* Theme and font variables will be applied here for all child components */
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Menlo', monospace);
	font-size: var(--terminal-font-size-base, 14px);
	line-height: var(--terminal-line-height, 1.6);
	color: var(--terminal-text, #d4d4d4);
	background: var(--terminal-bg, #1e1e1e);
}

/* Toggle Button */
.terminal-toggle-btn {
	position: fixed;
	bottom: 20px;
	left: 20px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 20px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	cursor: pointer;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	transition: all 0.2s;
	z-index: 10000;
	font-weight: 500;
}

.terminal-toggle-btn:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	transform: translateY(-2px);
	box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-toggle-btn svg {
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
}

/* Terminal Drawer */
.terminal-drawer {
	position: fixed;
	bottom: 0;
	left: 0;
	right: 0;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #d4d4d4);
	display: flex;
	flex-direction: column;
	box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.3);
	z-index: 10000;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Menlo', monospace);
	font-size: var(--terminal-font-size-base, 14px);
	line-height: var(--terminal-line-height, 1.6);
	/* height is now controlled by inline style */
	min-height: 30vh;
	max-height: 100vh;
	overflow: visible; /* Allow favorites tray drawer to extend */
}

/* When embedded in a page (not floating), use relative positioning and fill container */
.developer-terminal-wrapper[data-embedded="true"] {
	position: relative;
	height: 100%;
	width: 100%;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.developer-terminal-wrapper[data-embedded="true"] .terminal-drawer {
	position: relative !important;
	top: auto !important;
	bottom: auto !important;
	left: auto !important;
	right: auto !important;
	min-height: 0 !important;
	max-height: none !important;
	height: 100% !important;
	flex: 1;
	box-shadow: none;
	margin: 0;
	overflow: hidden;
}

.developer-terminal-wrapper[data-embedded="true"] .terminal-content-area {
	flex: 1;
	min-height: 0;
}

.developer-terminal-wrapper[data-embedded="true"] .terminal-header {
	margin-top: 0;
	padding-top: 12px;
}

.developer-terminal-wrapper[data-embedded="true"] .terminal-layout {
	height: 100%;
}

.developer-terminal-wrapper[data-embedded="true"] .terminal-sidebar {
	height: 100%;
}

/* Tab Bar */
.terminal-tabs {
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: none; /* Remove border, tabs will handle their own borders */
	padding-top: 8px; /* Space for resize handle */
	padding-bottom: 0; /* Remove bottom padding */
	transition: margin-top 0.3s ease;
	position: relative;
	z-index: 10002; /* Above favorites tray */
	margin-bottom: 0; /* Remove margin to make tabs look connected */
}


.terminal-tabs-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 0; /* Remove any default padding */
	margin: 0; /* Remove any default margin */
}

.terminal-tabs-container {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: flex-end; /* Align tabs to bottom of container */
	gap: 4px;
	padding: 8px 0 0 0; /* Only top padding for resize handle, no side padding */
	overflow-x: auto;
	overflow-y: hidden;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg-secondary, #252526);
}

.terminal-nav-actions {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px 0 12px; /* Match tabs container top padding, no bottom padding */
}

.terminal-tabs-container::-webkit-scrollbar {
	height: 6px;
}

.terminal-tabs-container::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-tabs-container::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 3px;
}

.terminal-tabs-container::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 14px;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-bottom: 1px solid var(--terminal-border, #3e3e42); /* Keep bottom border for inactive tabs */
	border-radius: 6px 6px 0 0;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	white-space: nowrap;
	position: relative;
	min-width: 80px;
	flex-shrink: 0;
	max-width: 200px;
	margin-bottom: 0; /* Remove any margin */
}

.terminal-tab:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
}

.terminal-tab.active {
	background: var(--terminal-bg, #1e1e1e);
	border-color: var(--terminal-primary, #0e639c);
	border-bottom-color: var(--terminal-bg, #1e1e1e);
	border-bottom-width: 1px;
	color: var(--terminal-text, #ffffff);
	font-weight: 500;
	z-index: 1;
	margin-bottom: -1px; /* Connect to content below */
}

.terminal-tab.active::after {
	content: '';
	position: absolute;
	bottom: -1px;
	left: 0;
	right: 0;
	height: 2px;
	background: var(--terminal-primary, #0e639c);
}

.terminal-tab-label {
	flex: 1;
	user-select: none;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-tab-close {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 2px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	border-radius: 2px;
	transition: all 0.2s;
	width: 16px;
	height: 16px;
	flex-shrink: 0;
}

.terminal-tab-close:hover {
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #ffffff);
}

.terminal-tab-close svg {
	width: 12px !important;
	height: 12px !important;
	max-width: 12px !important;
	max-height: 12px !important;
}

/* Issues Counter */
.terminal-issues-counter {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	position: relative;
}

.terminal-issues-counter:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
	transform: translateY(-1px);
}

.terminal-issues-counter svg {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
}

.terminal-issues-counter-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1;
}

.terminal-issues-counter-red .terminal-issues-counter-badge {
	background: #dc2626;
	color: #ffffff;
}

.terminal-issues-counter-orange .terminal-issues-counter-badge {
	background: #ea580c;
	color: #ffffff;
}

.terminal-issues-counter-yellow .terminal-issues-counter-badge {
	background: #ca8a04;
	color: #ffffff;
}

.terminal-issues-counter-blue .terminal-issues-counter-badge {
	background: #2563eb;
	color: #ffffff;
}

.terminal-issues-counter-red svg {
	color: #dc2626;
}

.terminal-issues-counter-orange svg {
	color: #ea580c;
}

.terminal-issues-counter-yellow svg {
	color: #ca8a04;
}

.terminal-issues-counter-blue svg {
	color: #2563eb;
}

/* Notifications Button */
.terminal-notifications-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	padding: 0;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text-secondary, #858585);
	cursor: not-allowed;
	opacity: 0.5;
	transition: all 0.2s;
}

.terminal-notifications-btn:disabled {
	cursor: not-allowed;
}

.terminal-notifications-btn svg {
	width: 20px;
	height: 20px;
}

/* Favorites Tray (Top-Aligned Full-Width Drawer) */
.terminal-favorites-tray {
	position: absolute;
	top: 0;
	left: 50%;
	transform: translateX(-50%);
	z-index: 10003; /* High enough to be visible */
	pointer-events: none;
	width: 200px;
	overflow: visible; /* Allow content to break out */
}

.terminal-favorites-tray-shelf {
	pointer-events: auto;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	height: 24px; /* More visible, easier to hover */
	padding: 0 12px;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-top: none;
	border-radius: 0 0 4px 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s ease;
	width: 100%;
	position: absolute;
	top: 0;
	left: 0;
	z-index: 10003; /* Above tabs (10002) and tray content */
}

.terminal-favorites-tray-shelf:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-shelf:hover .terminal-favorites-tray-label {
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-shelf svg {
	width: 16px;
	height: 16px;
	transition: all 0.2s;
	flex-shrink: 0;
	opacity: 0.8;
}

.terminal-favorites-tray-shelf:hover svg {
	color: var(--terminal-primary, #0e639c);
	opacity: 1;
}

.terminal-favorites-tray-label {
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
	opacity: 0.9;
}

.terminal-favorites-tray-content {
	pointer-events: auto;
	position: absolute;
	top: 0; /* Extend to top of page */
	left: calc(-50vw + 110px + 50%); /* Position from left edge of main content */
	width: calc(100vw - 220px); /* Full width minus sidebar */
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-top: none;
	border-radius: 0 0 8px 8px;
	padding: 16px;
	padding-top: 40px; /* Space for the shelf button */
	max-height: 500px;
	overflow-y: auto;
	overflow-x: hidden;
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	z-index: 9999; /* Below shelf (10003) and tabs (10002) but above content */
	margin-top: 0;
}

.terminal-favorites-tray-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-favorites-tray-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-base, 14px);
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-view-all {
	padding: 4px 12px;
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-primary, #0e639c);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-tray-view-all:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-favorites-tray-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
}

.terminal-favorites-tray-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	transition: all 0.2s;
}

.terminal-favorites-tray-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
}

.terminal-favorites-tray-item-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
	min-width: 0;
}

.terminal-favorites-tray-item-name {
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	color: var(--terminal-text, #d4d4d4);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-favorites-tray-item-type {
	font-size: 10px;
	opacity: 0.7;
}

.terminal-favorites-tray-item-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.terminal-favorites-tray-action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-tray-action-btn:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
	color: var(--terminal-primary, #0e639c);
}

.terminal-favorites-tray-action-btn svg {
	width: 14px;
	height: 14px;
}

.terminal-favorites-tray-empty {
	padding: 24px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-sm, 12px);
}

/* Favorites Tray Transitions */
.favorites-tray-enter-active,
.favorites-tray-leave-active {
	transition: all 0.3s ease;
}

.favorites-tray-enter-from,
.favorites-tray-leave-to {
	opacity: 0;
	transform: translateY(-10px);
}

/* Terminal Content Area - wraps panels and output */
.terminal-content-area {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
	position: relative;
	border-top: 1px solid var(--terminal-primary, #0e639c); /* Connect active tab border */
	margin-top: 0; /* No margin needed, border connects directly */
}

/* Resize Handle */
.terminal-resize-handle {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	height: 8px;
	cursor: ns-resize;
	z-index: 10001;
	display: flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	transition: background 0.2s;
}

.terminal-resize-handle:hover,
.terminal-resize-handle.terminal-resizing {
	background: var(--terminal-selection, rgba(14, 99, 156, 0.2));
}

.terminal-resize-handle-indicator {
	width: 40px;
	height: 4px;
	background: var(--terminal-primary, #0e639c);
	border-radius: 2px;
	opacity: 0.5;
	transition: opacity 0.2s;
}

.terminal-resize-handle:hover .terminal-resize-handle-indicator,
.terminal-resize-handle.terminal-resizing .terminal-resize-handle-indicator {
	opacity: 1;
}

/* Slide Up Animation */
.slide-up-enter-active,
.slide-up-leave-active {
	transition: transform 0.3s ease-out;
}

.slide-up-enter-from,
.slide-up-leave-to {
	transform: translateY(100%);
}

/* Terminal Layout */
.terminal-layout {
	display: flex;
	flex: 1;
	min-height: 0;
	overflow: hidden;
	transition: padding-top 0.3s ease;
}

.terminal-layout.favorites-drawer-open {
	/* No padding needed since tray is absolutely positioned */
}

/* Sidebar Navigation */
.terminal-sidebar {
	width: 220px;
	background: var(--terminal-bg-secondary, #252526);
	border-right: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	flex-shrink: 0;
	transition: width 0.3s ease;
	overflow: hidden;
}

.terminal-sidebar.collapsed {
	width: 48px;
}

.terminal-sidebar-header {
	display: flex;
	flex-direction: column;
	padding: 12px 12px;
	padding-right: 40px; /* Space for toggle button */
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	gap: 8px;
	position: relative;
}

.terminal-sidebar-branding {
	width: 100%;
	margin-bottom: 4px;
}

.terminal-sidebar-title-wrapper {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	text-align: left;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
	font-weight: 700;
	line-height: 1.15;
	gap: 4px;
}

.terminal-sidebar-title-laravel {
	font-size: 24px;
	color: #1e3a5f; /* Dark blue/navy for light mode */
	letter-spacing: -0.02em;
}

/* Lighter blue for dark mode themes */
[data-terminal-theme="dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="high-contrast-dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="blue-dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="green-dark"] .terminal-sidebar-title-laravel {
	color: #4a7ba7; /* Lighter blue for dark backgrounds */
}

.terminal-sidebar-title-overlord {
	font-size: 24px;
	color: #ff6b35; /* Vibrant orange for light mode */
	letter-spacing: -0.02em;
}

/* Muted orange for dark mode themes */
[data-terminal-theme="dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="high-contrast-dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="blue-dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="green-dark"] .terminal-sidebar-title-overlord {
	color: #d45a1f; /* Muted/darker orange for dark backgrounds */
}

.terminal-beta-badge-sidebar {
	display: inline-block;
	padding: 2px 6px;
	background: var(--terminal-primary, #0e639c);
	color: white;
	font-size: 9px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	border-radius: 3px;
	line-height: 1.2;
	align-self: flex-start;
	margin-bottom: 2px;
}

.terminal-sidebar-nav-label {
	font-weight: 600;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-top: 0;
}

.terminal-sidebar-toggle {
	position: absolute;
	top: 12px;
	right: 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 4px;
	border-radius: 4px;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.terminal-sidebar-toggle:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-sidebar-toggle svg {
	width: 16px !important;
	height: 16px !important;
}

.terminal-sidebar-nav {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px 0;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg-secondary, #252526);
}

.terminal-sidebar-nav::-webkit-scrollbar {
	width: 6px;
}

.terminal-sidebar-nav::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-sidebar-nav::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 3px;
}

.terminal-sidebar-nav::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-nav-section {
	margin-bottom: 16px;
}

.terminal-nav-section-title {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 8px 12px 4px;
	font-weight: 600;
}

.terminal-nav-item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 8px 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	text-align: left;
	position: relative;
}

.terminal-nav-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-nav-item.active {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-nav-item.active::before {
	content: '';
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 3px;
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-nav-item.disabled {
	opacity: 0.5;
	cursor: not-allowed;
	pointer-events: none;
}

.terminal-nav-item svg {
	width: 16px !important;
	height: 16px !important;
	flex-shrink: 0;
}

.terminal-nav-item span {
	flex: 1;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.terminal-sidebar.collapsed .terminal-nav-item {
	justify-content: center;
	padding: 8px;
}

.terminal-sidebar.collapsed .terminal-nav-item span {
	display: none;
}

.terminal-nav-theme-toggle {
	padding: 8px 12px;
}

.terminal-nav-item-danger {
	color: var(--terminal-error, #f48771);
}

.terminal-nav-item-danger:hover {
	background: rgba(244, 135, 113, 0.1);
}

/* Navigation Search */
.terminal-nav-search {
	padding: 8px 12px;
	margin-bottom: 8px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-nav-search-wrapper {
	position: relative;
	display: flex;
	align-items: center;
}

.terminal-nav-search-icon {
	position: absolute;
	left: 8px;
	width: 16px !important;
	height: 16px !important;
	color: var(--terminal-text-secondary, #858585);
	pointer-events: none;
	z-index: 1;
}

.terminal-nav-search-input {
	width: 100%;
	padding: 6px 32px 6px 32px;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	transition: all 0.2s;
}

.terminal-nav-search-input:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-nav-search-input::placeholder {
	color: var(--terminal-text-secondary, #858585);
}

.terminal-nav-search-clear {
	position: absolute;
	right: 6px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 4px;
	border-radius: 4px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s;
}

.terminal-nav-search-clear:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-nav-search-clear svg {
	width: 14px !important;
	height: 14px !important;
}

/* Navigation Section Header */
.terminal-nav-section-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	user-select: none;
	transition: background 0.2s;
	border-radius: 4px;
	margin-bottom: 4px;
}

.terminal-nav-section-header:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-nav-section-chevron {
	width: 14px !important;
	height: 14px !important;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
	flex-shrink: 0;
}

.terminal-nav-section-chevron.expanded {
	transform: rotate(90deg);
}

.terminal-nav-section-items {
	overflow: hidden;
}

/* Navigation Section Transitions */
.nav-section-enter-active,
.nav-section-leave-active {
	transition: all 0.3s ease;
	max-height: 2000px;
}

.nav-section-enter-from,
.nav-section-leave-to {
	max-height: 0;
	opacity: 0;
	overflow: hidden;
}

/* Priority-based Navigation Styles */
.nav-section-primary {
	margin-bottom: 20px;
}

.nav-section-secondary {
	margin-bottom: 16px;
}

.nav-section-tertiary {
	margin-bottom: 12px;
}

.nav-item-primary {
	font-weight: 600;
	padding: 10px 12px;
}

.nav-item-primary svg {
	width: 18px !important;
	height: 18px !important;
}

.nav-item-secondary {
	font-weight: 500;
}

.nav-item-tertiary {
	font-weight: 400;
	opacity: 0.85;
}

.nav-item-tertiary:hover {
	opacity: 1;
}

/* Navigation Item Badge */
.terminal-nav-item-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 6px;
	border-radius: 9px;
	font-size: 10px;
	font-weight: 600;
	margin-left: auto;
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-nav-item-badge.badge-error {
	background: var(--terminal-error, #f48771);
}

.terminal-nav-item-badge.badge-warning {
	background: #f59e0b;
}

.terminal-nav-item-badge.badge-info {
	background: var(--terminal-primary, #0e639c);
}

/* No Results */
.terminal-nav-no-results {
	padding: 24px 12px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-sm, 12px);
}

/* Footer section styling (tertiary) */
.nav-section-tertiary .terminal-nav-section-title {
	font-size: var(--terminal-font-size-xs, 10px);
	opacity: 0.7;
}

.nav-section-tertiary .terminal-nav-item {
	font-size: var(--terminal-font-size-xs, 11px);
	padding: 6px 12px;
}

.terminal-main-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow: visible; /* Allow favorites tray to be visible */
	position: relative; /* For absolute positioning of overlay */
}


/* Dropdown */
.terminal-dropdown {
	position: relative;
}

.dropdown-arrow {
	width: 12px !important;
	height: 12px !important;
	max-width: 12px !important;
	max-height: 12px !important;
	transition: transform 0.2s;
	margin-left: 4px;
	flex-shrink: 0;
}

.dropdown-arrow.rotated {
	transform: rotate(180deg);
}

.terminal-dropdown-menu {
	position: absolute;
	top: calc(100% + 4px);
	right: 0;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	min-width: 200px;
	z-index: 10003; /* Higher than scan components (10002) to ensure dropdown appears above */
	overflow: hidden;
}

.terminal-dropdown-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: background 0.2s;
	border: none;
	background: none;
	width: 100%;
	text-align: left;
}

.terminal-dropdown-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-dropdown-item.active {
	background: var(--terminal-bg-tertiary, #37373d);
	color: var(--terminal-text, #ffffff);
}

.terminal-dropdown-item svg,
.terminal-dropdown-item .dropdown-icon {
	flex-shrink: 0;
	width: 14px !important;
	height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
}

/* Override any Tailwind classes that might be applied */
.terminal-dropdown-menu .terminal-dropdown-item svg {
	width: 14px !important;
	height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
}

.terminal-dropdown-section {
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	padding: 12px;
	cursor: default;
}

.terminal-dropdown-section:hover {
	background: transparent;
}

.terminal-font-size-control-inline {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
}

.terminal-font-size-label-inline {
	font-size: 11px;
	color: var(--terminal-text-secondary, #858585);
	min-width: 40px;
	text-align: center;
	user-select: none;
}

.terminal-btn-xs {
	padding: 2px 4px;
	min-width: 20px;
}

.terminal-btn-icon {
	padding: 4px 6px;
	min-width: 24px;
}

.terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-btn-close svg {
	width: 20px !important;
	height: 20px !important;
	max-width: 20px !important;
	max-height: 20px !important;
}

.terminal-btn-xs svg {
	width: 12px !important;
	height: 12px !important;
	max-width: 12px !important;
	max-height: 12px !important;
}

/* Terminal Buttons */
.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	min-height: 32px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-btn-secondary:hover {
	background: var(--terminal-border-hover, #464647);
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary, #858585);
	padding: 4px;
}

.terminal-btn-close:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-btn-danger {
	color: var(--terminal-error, #f48771);
}

.terminal-btn-danger:hover {
	background: var(--terminal-error, #f48771);
	color: #ffffff;
}

.terminal-beta-badge {
	background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
	color: #ffffff;
	font-size: 8px;
	font-weight: 700;
	letter-spacing: 0.5px;
	padding: 1px 4px;
	border-radius: 2px;
	text-transform: uppercase;
	margin-left: 4px;
	box-shadow: 0 1px 2px var(--terminal-shadow-medium, rgba(0, 0, 0, 0.2));
	line-height: 1.2;
}

.terminal-beta-badge-header {
	background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
	color: #ffffff;
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.5px;
	padding: 2px 6px;
	border-radius: 3px;
	text-transform: uppercase;
	margin-left: 8px;
	box-shadow: 0 1px 3px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	line-height: 1.2;
}

/* Terminal Output Area - moved to TerminalOutput component */

.terminal-prompt {
	color: var(--terminal-prompt, #4ec9b0);
	font-weight: 600;
	user-select: none;
	flex-shrink: 0;
}

/* Output styles moved to TerminalOutput and TerminalOutputItem components */

/* Terminal Input Area */
.terminal-input-area {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-top: 1px solid var(--terminal-border, #3e3e42);
	position: relative;
	z-index: 10002;
	flex-shrink: 0;
}

.terminal-input-area .terminal-prompt {
	flex-shrink: 0;
	color: var(--terminal-prompt, #4ec9b0);
	font-weight: 500;
	line-height: 1;
	align-self: center;
}

.terminal-input-area .terminal-btn {
	flex-shrink: 0;
	min-height: 40px;
	align-self: stretch;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-input-area .terminal-ai-toggle {
	min-width: 40px;
	min-height: 40px;
}

.terminal-ai-toggle {
	flex-shrink: 0;
}

.terminal-ai-toggle svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-input {
	flex: 1;
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border-hover, #464647);
	border-radius: 4px;
	padding: 8px 12px;
	font-family: var(--terminal-font-family, inherit);
	font-size: var(--terminal-font-size-base, 14px);
	line-height: var(--terminal-line-height, 1.6);
	outline: none;
	resize: none;
	overflow-y: auto;
	min-height: 40px;
	max-height: 200px;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-input:focus {
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-secondary, #2d2d30);
}

.terminal-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Scrollbar styling for textarea */
.terminal-input::-webkit-scrollbar {
	width: 8px;
}

.terminal-input::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #2d2d30);
	border-radius: 4px;
}

.terminal-input::-webkit-scrollbar-thumb {
	background: var(--terminal-border-hover, #464647);
	border-radius: 4px;
}

.terminal-input::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #525252);
}

/* Global Scrollbar Styling */
/* Apply to all scrollable areas within the terminal */
.developer-terminal-wrapper *::-webkit-scrollbar {
	width: 10px;
	height: 10px;
}

.developer-terminal-wrapper *::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.developer-terminal-wrapper *::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #1e1e1e);
}

.developer-terminal-wrapper *::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

/* Firefox scrollbar styling */
.developer-terminal-wrapper * {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}

.terminal-btn-active {
	background: var(--terminal-primary, #0e639c) !important;
	color: white !important;
}

.terminal-btn-disabled {
	opacity: 0.5;
	cursor: not-allowed;
	pointer-events: none;
}

.terminal-btn-sm {
	padding: 4px 8px;
	font-size: var(--terminal-font-size-xs, 11px);
}


/* Mode Selector Control */
.terminal-mode-control {
	display: flex;
	align-items: center;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border-radius: 4px;
	padding: 2px;
	border: 1px solid var(--terminal-border-hover, #464647);
	height: 32px;
}

.terminal-mode-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 0 10px;
	background: transparent;
	border: none;
	color: var(--terminal-text-muted, #a0a0a0);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	font-size: 11px;
	font-weight: 600;
	cursor: pointer;
	border-radius: 2px;
	transition: all 0.15s ease;
}

.terminal-mode-btn:hover {
	color: var(--terminal-text, #d4d4d4);
	background: rgba(255, 255, 255, 0.05);
}

.terminal-mode-btn.active {
	color: #fff;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.terminal-mode-btn.active.mode-tinker {
	background: #4f5d95; /* PHP Blue */
}

.terminal-mode-btn.active.mode-shell {
	background: #42b883; /* Shell Greenish */
	color: #1e1e1e;
}

.terminal-mode-btn.active.mode-ai {
	background: #8e44ad; /* AI Purple */
}

/* Input styling based on mode */
.terminal-input.mode-tinker {
	border-left: 3px solid #4f5d95;
}

.terminal-input.mode-shell {
	border-left: 3px solid #42b883;
}

.terminal-input.mode-ai {
	border-left: 3px solid #8e44ad;
}

/* Sidebar Header Styling */
.nav-section-header {
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.5px;
	color: var(--terminal-text-muted, #858585);
	text-transform: uppercase;
	padding: 8px 12px 4px;
	margin-top: 8px;
	display: flex;
	align-items: center;
	user-select: none;
}

.nav-section-header:first-child {
	margin-top: 0;
}

/* Hide legacy styles */
.terminal-mode-wrapper,
.terminal-mode-select,
.terminal-mode-icon,
.terminal-shell-toggle, 
.terminal-ai-toggle {
	display: none !important;
}

</style>

