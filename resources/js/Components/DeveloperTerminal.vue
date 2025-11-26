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
import TerminalPhpstan from './Terminal/TerminalPhpstan.vue';
import TerminalDatabase from './Terminal/TerminalDatabase.vue';
import TerminalThemeToggle from './Terminal/TerminalThemeToggle.vue';
import TerminalSettings from './Terminal/TerminalSettings.vue';
import TerminalRoutes from './Terminal/TerminalRoutes.vue';
import TerminalMermaid from './Terminal/TerminalMermaid.vue';
import TerminalBugReport from './Terminal/TerminalBugReport.vue';
import { useTerminalTheme, initThemeRoot } from './useTerminalTheme.js';
import { useTerminalFont, initFontRoot } from './useTerminalFont.js';
// New composables
import { useTerminalTabs, tabConfigs } from './useTerminalTabs.js';
import { buildNavigationConfig } from './config/terminalNavigationConfig.js';
import { useTerminalCommands } from './useTerminalCommands.js';
import { useTerminalAi } from './useTerminalAi.js';
import { useTerminalScans } from './useTerminalScans.js';
import { useTerminalDatabaseScans } from './useTerminalDatabaseScans.js';
import { useTerminalIssues } from './useTerminalIssues.js';
import { useTerminalFavorites } from './useTerminalFavorites.js';
import { useTerminalResize } from './useTerminalResize.js';
import { useTerminalHorizon } from './useTerminalHorizon.js';
import { useTerminalNavigation } from './useTerminalNavigation.js';
// New components
import TerminalSidebar from './Terminal/TerminalSidebar.vue';
import TerminalTabBar from './Terminal/TerminalTabBar.vue';
import TerminalInput from './Terminal/TerminalInput.vue';
import TerminalFavoritesTray from './Terminal/TerminalFavoritesTray.vue';
import TerminalResizeHandle from './Terminal/TerminalResizeHandle.vue';
import TerminalToggleButton from './Terminal/TerminalToggleButton.vue';
import TerminalTabContent from './Terminal/TerminalTabContent.vue';
import TerminalAgent from './Terminal/TerminalAgent.vue';

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
const tabContentRef = ref(null);

// Sidebar navigation state
const sidebarCollapsed = ref(false);

// Agent pane state
const agentPaneVisible = ref(false);
const agentPaneWidth = ref(400); // Default width in pixels
const isResizingAgentPane = ref(false);

// Initialize Horizon composable
const horizon = useTerminalHorizon(api);
const { horizonInstalled, loadHorizonStatus } = horizon;

// Initialize Navigation composable
const navigation = useTerminalNavigation();
const { logsNavigateTo, handleNavigateToReference: handleNavigateToReferenceBase, handleNavigateToSource: handleNavigateToSourceBase } = navigation;

// Issues state
const issuePrefillData = ref(null);

// Dropdown state removed - sidebar replaces dropdowns

// Initialize composables
const tabs = useTerminalTabs();
const { activeTab, openTabs, isTabOpen, isTabActive, openTab, closeTab, closeAllTabs, closeOtherTabs, switchTab, ensureTabOpen, createToggleFunction } = tabs;

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
const { commandInput, inputMode, commandHistory, historyIndex, outputHistory, isExecuting, addOutput, clearTerminal, clearSession, executeCommand, executeShellCommand, handleKeyDown, useCommandFromHistory, insertCommand: insertCommandBase, insertCommandFromAi: insertCommandFromAiBase, executeCommandFromAi: executeCommandFromAiBase, executeCommandFromFavorite: executeCommandFromFavoriteBase, copyOutputToClipboard: copyOutputToClipboardBase, showHelp: showHelpBase } = commands;

// Wrapper functions that pass refs
function insertCommand(command) {
	insertCommandBase(command, inputRef.value);
}

function insertCommandFromAi(command) {
	insertCommandFromAiBase(command, inputRef.value);
}

async function executeCommandFromAi(command) {
		await executeCommandFromAiBase(command, inputRef.value, inputMode, sendAiMessage, aiRef);
}

function executeCommandFromFavorite(command) {
	executeCommandFromFavoriteBase(command);
}

// Initialize AI composable
const ai = useTerminalAi(api, { ensureTabOpen });
const { aiConversationHistory, isSendingAi, loadAiStatus, sendAiMessage } = ai;

// Watch for tabContentRef to be available and sync aiRef
watch(tabContentRef, (newValue) => {
	if (newValue && newValue.aiRef) {
		aiRef.value = newValue.aiRef;
	}
}, { immediate: true });

// Also watch for when AI tab becomes active to ensure ref is synced
watch(() => isTabActive('ai'), (isActive) => {
	if (isActive && tabContentRef.value && tabContentRef.value.aiRef) {
		nextTick(() => {
			aiRef.value = tabContentRef.value.aiRef;
		});
	}
});

// Initialize scans composables
const scans = useTerminalScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab });
const { activeScanId, handleStartScan, startScanPolling, stopScanPolling, handleViewScan: handleViewScanBase, handleViewScanIssues: handleViewScanIssuesBase, handleScanIssuesCleared: handleScanIssuesClearedBase } = scans;

const databaseScans = useTerminalDatabaseScans(api, { isTabOpen, closeTab, ensureTabOpen, switchTab });
const { activeDatabaseScanId, handleStartDatabaseScan, startDatabaseScanPolling, stopDatabaseScanPolling, handleViewDatabaseScan: handleViewDatabaseScanBase, handleViewDatabaseScanIssues: handleViewDatabaseScanIssuesBase, handleDatabaseIssuesCleared: handleDatabaseIssuesClearedBase } = databaseScans;

// Wrapper functions that pass refs
function handleViewScan(scanId) {
	handleViewScanBase(scanId, closeTab, ensureTabOpen, switchTab);
}

function handleViewScanIssues(scanId) {
	handleViewScanIssuesBase(scanId, closeTab, ensureTabOpen, switchTab);
}

function handleScanIssuesCleared() {
	handleScanIssuesClearedBase(scanHistoryRef);
}

function handleViewDatabaseScan(scanId) {
	handleViewDatabaseScanBase(scanId, closeTab, ensureTabOpen, switchTab);
}

function handleViewDatabaseScanIssues(scanId) {
	handleViewDatabaseScanIssuesBase(scanId, closeTab, ensureTabOpen, switchTab);
}

function handleDatabaseIssuesCleared() {
	handleDatabaseIssuesClearedBase(databaseScanHistoryRef);
}

// Initialize issues composable
const issues = useTerminalIssues(api);
const { issuesStats, issuesCounter, loadIssuesStats, startIssuesStatsPolling, stopIssuesStatsPolling, handleCreateIssueFromLogs: handleCreateIssueFromLogsBase, handleCreateIssueFromTerminal: handleCreateIssueFromTerminalBase, handleCreateIssueFromScan: handleCreateIssueFromScanBase } = issues;

// Wrapper functions that pass refs
function handleCreateIssueFromLogs(prefillData) {
	handleCreateIssueFromLogsBase(prefillData, issuePrefillData, ensureTabOpen, nextTick);
}

function handleCreateIssueFromTerminal(prefillData) {
	handleCreateIssueFromTerminalBase(prefillData, issuePrefillData, ensureTabOpen, nextTick);
}

function handleCreateIssueFromScan(prefillData) {
	handleCreateIssueFromScanBase(prefillData, issuePrefillData, ensureTabOpen, switchTab, nextTick);
}

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

// Navigation config computed - will be passed to TerminalSidebar
// Build navigation config using the config builder
const navigationConfig = computed(() => {
	return buildNavigationConfig({
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
		toggleMermaid,
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
		toggleBugReport,
		issuesCounter,
		togglePhpstan,
		toggleAgent,
	});
});

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
}

// Handle resize start (wraps composable function)
function handleStartResize(event) {
	startResize(event);
}

// Command insertion/execution functions are now in useTerminalCommands composable

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

// Helper functions are now in composables

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
		// Use the composable's sendAiMessage which handles everything properly
		// This ensures messages are added to the AI component with proper styling
		await sendAiMessage(command, aiRef);
		
		// Clear input
		commandInput.value = '';
		if (inputRef.value && typeof inputRef.value.autoResize === 'function') {
			inputRef.value.autoResize();
		}
		
		// Focus input after sending
		await nextTick();
		if (focusInput) focusInput();
		
		return;
	}
	
	// Regular command execution (Tinker) - handled by composable
	await executeCommand();
}

// Navigation handlers are now in useTerminalNavigation composable
function handleNavigateToReference(navData) {
	handleNavigateToReferenceBase(navData, openTab);
}

// Handle navigation from Mermaid diagram
function handleNavigateFromDiagram(navData) {
	if (!navData || !navData.tab) return;
	
	// Open the target tab
	ensureTabOpen(navData.tab);
	
	// If there's a name/identifier, store it for the component to use
	if (navData.name) {
		if (typeof window !== 'undefined') {
			if (!window.overlordTabOptions) {
				window.overlordTabOptions = {};
			}
			window.overlordTabOptions[navData.tab] = {
				itemId: navData.name,
				highlight: navData.name,
			};
		}
	}
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
// Use createToggleFunction for simple toggles
const toggleHistory = createToggleFunction('history');
const toggleTemplates = createToggleFunction('templates');
const toggleDatabase = createToggleFunction('database');

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
	// Only switch to terminal tab if not in AI mode (AI mode should stay on AI tab)
	if (inputMode.value !== 'ai' && activeTab.value !== 'terminal') {
		activeTab.value = 'terminal';
	} else if (inputMode.value === 'ai' && activeTab.value !== 'ai') {
		// If in AI mode, switch to AI tab
		activeTab.value = 'ai';
	}
}

function toggleAi() {
	// Open or switch to AI tab (don't toggle input mode)
	if (isTabActive('ai')) {
		// If AI tab is already active, close it and switch to terminal
		closeTab('ai');
		activeTab.value = 'terminal';
	} else {
		// Open AI tab
		ensureTabOpen('ai');
		loadAiStatus();
	}
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

// Use createToggleFunction for simple toggles
const toggleModelDiagram = createToggleFunction('model-diagram');
const toggleMermaid = createToggleFunction('mermaid');
const toggleControllers = createToggleFunction('controllers');
const toggleRoutes = createToggleFunction('routes');
const toggleClasses = createToggleFunction('classes');
const toggleTraits = createToggleFunction('traits');
const toggleServices = createToggleFunction('services');
const toggleRequests = createToggleFunction('requests');
const toggleProviders = createToggleFunction('providers');
const toggleMiddleware = createToggleFunction('middleware');
const toggleJobs = createToggleFunction('jobs');
const toggleExceptions = createToggleFunction('exceptions');
const toggleCommandClasses = createToggleFunction('command-classes');
const toggleMigrations = createToggleFunction('migrations');
const toggleCommands = createToggleFunction('commands');

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
const toggleLogs = createToggleFunction('logs');

// Toggle Issues
const toggleIssues = createToggleFunction('issues');

// Toggle Bug Report
const toggleBugReport = createToggleFunction('bug-report');

// Toggle Scan Results
function toggleScanResults() {
	if (isTabActive('scan-results')) {
		closeTab('scan-results');
	} else {
		ensureTabOpen('scan-results');
	}
}

// toggleScanHistory removed - not used

// Scan view handlers are now in useTerminalScans and useTerminalDatabaseScans composables

// Open scan configuration
function startScan() {
	ensureTabOpen('scan-config');
}

function startDatabaseScan() {
	ensureTabOpen('database-scan-config');
}

function togglePhpstan() {
	ensureTabOpen('phpstan');
}

// Toggle agent pane
function toggleAgent() {
	agentPaneVisible.value = !agentPaneVisible.value;
}

// Agent pane resize handlers
function startAgentPaneResize(event) {
	isResizingAgentPane.value = true;
	const startX = event.clientX;
	const startWidth = agentPaneWidth.value;

	function handleMouseMove(e) {
		const diff = startX - e.clientX; // Reverse because we're resizing from right
		const newWidth = Math.max(300, Math.min(800, startWidth + diff));
		agentPaneWidth.value = newWidth;
	}

	function handleMouseUp() {
		isResizingAgentPane.value = false;
		document.removeEventListener('mousemove', handleMouseMove);
		document.removeEventListener('mouseup', handleMouseUp);
	}

	document.addEventListener('mousemove', handleMouseMove);
	document.addEventListener('mouseup', handleMouseUp);
}

// Issue handlers are now in useTerminalIssues composable

// Navigation and helper functions are now in composables
function handleNavigateToSource(navigationData) {
	handleNavigateToSourceBase(navigationData, ensureTabOpen);
}

function showHelp() {
	showHelpBase(api, commandInput, isExecuting, addOutput, scrollToBottom, focusInput);
}

function copyOutputToClipboard() {
	copyOutputToClipboardBase(outputHistory, Swal);
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

// renderOutput function removed - not used

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


// handleClickOutside removed - dropdowns replaced by sidebar

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
					<div class="terminal-main-content" :style="agentPaneVisible ? { marginRight: agentPaneWidth + 'px' } : {}">
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
							<TerminalTabContent
								ref="tabContentRef"
								:active-tab="activeTab"
								:is-tab-active="isTabActive"
								:favorites-ref="favoritesRef"
								:scan-history-ref="scanHistoryRef"
								:database-scan-history-ref="databaseScanHistoryRef"
								:output-container-ref="outputContainerRef"
								:command-input="commandInput"
								:output-history="outputHistory"
								:is-executing="isExecuting"
								:font-size="fontSize"
								:active-scan-id="activeScanId"
								:active-database-scan-id="activeDatabaseScanId"
								:issue-prefill-data="issuePrefillData"
								:logs-navigate-to="logsNavigateTo"
								:use-command-from-history="useCommandFromHistory"
								:insert-command="insertCommand"
								:insert-command-from-ai="insertCommandFromAi"
								:execute-command-from-ai="executeCommandFromAi"
								:execute-command-from-favorite="executeCommandFromFavorite"
								:handle-add-to-favorites="handleAddToFavorites"
								:handle-navigate-to-reference="handleNavigateToReference"
								:handle-navigate-to-source="handleNavigateToSource"
								:handle-navigate-from-diagram="handleNavigateFromDiagram"
								:handle-create-issue-from-logs="handleCreateIssueFromLogs"
								:handle-create-issue-from-terminal="handleCreateIssueFromTerminal"
								:handle-create-issue-from-scan="handleCreateIssueFromScan"
								:handle-start-scan="handleStartScan"
								:handle-start-database-scan="handleStartDatabaseScan"
								:handle-view-scan="handleViewScan"
								:handle-view-scan-issues="handleViewScanIssues"
								:handle-scan-issues-cleared="handleScanIssuesCleared"
								:handle-view-database-scan="handleViewDatabaseScan"
								:handle-view-database-scan-issues="handleViewDatabaseScanIssues"
								:handle-database-issues-cleared="handleDatabaseIssuesCleared"
								:close-tab="closeTab"
								:clear-terminal="clearTerminal"
								:clear-session="clearSession"
								:close-terminal="closeTerminal"
								:load-issues-stats="loadIssuesStats"
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

				<!-- Agent Pane (Right Side) -->
				<transition name="slide-left">
					<div v-if="agentPaneVisible" class="terminal-agent-pane" :style="{ width: agentPaneWidth + 'px' }">
						<!-- Resize Handle -->
						<div
							class="agent-pane-resize-handle"
							@mousedown="startAgentPaneResize"
							:class="{ resizing: isResizingAgentPane }"
						></div>
						<TerminalAgent
							:visible="agentPaneVisible"
							@close="agentPaneVisible = false"
						/>
					</div>
				</transition>
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

/* Toggle Button styles are now in TerminalToggleButton.vue component */

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
/* Tab bar styles are now in TerminalTabBar.vue component */

/* Favorites Tray styles and transitions are now in TerminalFavoritesTray.vue component */

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

/* Resize Handle styles are now in TerminalResizeHandle.vue component */

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

/* Favorites drawer open state - no additional styling needed since tray is absolutely positioned */

/* Sidebar and Navigation styles are now in TerminalSidebar.vue component */

.terminal-main-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow: visible; /* Allow favorites tray to be visible */
	position: relative; /* For absolute positioning of overlay */
	transition: margin-right 0.3s ease;
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

/* Terminal Input Area styles are now in TerminalInput.vue component */

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


/* Mode Selector and Input styles are now in TerminalInput.vue component */

/* Hide legacy styles */
.terminal-mode-wrapper,
.terminal-mode-select,
.terminal-mode-icon,
.terminal-shell-toggle, 
.terminal-ai-toggle {
	display: none !important;
}

/* Agent Pane Styles */
.terminal-agent-pane {
	position: fixed;
	right: 0;
	top: 0;
	bottom: 0;
	background: var(--terminal-bg, #1e1e1e);
	border-left: 1px solid var(--terminal-border, #3e3e42);
	z-index: 10001;
	display: flex;
	flex-direction: column;
	box-shadow: -4px 0 20px rgba(0, 0, 0, 0.3);
}

.agent-pane-resize-handle {
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 4px;
	cursor: ew-resize;
	background: transparent;
	z-index: 10;
	transition: background 0.2s;
}

.agent-pane-resize-handle:hover,
.agent-pane-resize-handle.resizing {
	background: var(--terminal-primary, #0e639c);
}

/* Slide Left Animation */
.slide-left-enter-active,
.slide-left-leave-active {
	transition: transform 0.3s ease-out;
}

.slide-left-enter-from,
.slide-left-leave-to {
	transform: translateX(100%);
}

</style>

