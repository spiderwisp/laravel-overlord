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

// Terminal state
const commandInput = ref('');
const commandHistory = ref([]);
const historyIndex = ref(-1);
const outputHistory = ref([]);
const isExecuting = ref(false);
const outputContainerRef = ref(null);
const inputRef = ref(null);
const favoritesRef = ref(null);
const aiRef = ref(null);

// AI conversation history (managed here for unified input)
const aiConversationHistory = ref([]);
const selectedAiModel = ref(null);
const isSendingAi = ref(false);

// Tab system state
const activeTab = ref('terminal');
const openTabs = ref([
	{ id: 'terminal', label: 'Terminal', closable: false }
]);

// Tab configurations
const tabConfigs = {
	'terminal': { id: 'terminal', label: 'Terminal', closable: false },
	'history': { id: 'history', label: 'History', closable: true },
	'templates': { id: 'templates', label: 'Queries', closable: true },
	'database': { id: 'database', label: 'Database', closable: true },
	'horizon': { id: 'horizon', label: 'Horizon', closable: true },
	'logs': { id: 'logs', label: 'Logs', closable: true },
	'issues': { id: 'issues', label: 'Issues', closable: true },
	'controllers': { id: 'controllers', label: 'Controllers', closable: true },
	'classes': { id: 'classes', label: 'Classes', closable: true },
	'traits': { id: 'traits', label: 'Traits', closable: true },
	'services': { id: 'services', label: 'Services', closable: true },
	'requests': { id: 'requests', label: 'Requests', closable: true },
	'providers': { id: 'providers', label: 'Providers', closable: true },
	'middleware': { id: 'middleware', label: 'Middleware', closable: true },
	'jobs': { id: 'jobs', label: 'Jobs', closable: true },
	'exceptions': { id: 'exceptions', label: 'Exceptions', closable: true },
	'command-classes': { id: 'command-classes', label: 'Commands', closable: true },
	'migrations': { id: 'migrations', label: 'Migrations', closable: true },
	'commands': { id: 'commands', label: 'Commands', closable: true },
	'favorites': { id: 'favorites', label: 'Favorites', closable: true },
	'ai': { id: 'ai', label: 'AI', closable: true },
	'model-diagram': { id: 'model-diagram', label: 'Models', closable: true },
	'routes': { id: 'routes', label: 'Routes', closable: true },
	'scan-config': { id: 'scan-config', label: 'Scan Configuration', closable: true },
	'scan-results': { id: 'scan-results', label: 'Scan Results', closable: true },
	'scan-history': { id: 'scan-history', label: 'Scan History', closable: true },
	'database-scan-config': { id: 'database-scan-config', label: 'Database Scan Configuration', closable: true },
	'database-scan-results': { id: 'database-scan-results', label: 'Database Scan Results', closable: true },
	'database-scan-history': { id: 'database-scan-history', label: 'Database Scan History', closable: true },
	'settings': { id: 'settings', label: 'UI Settings', closable: true },
};

// Horizon state
const horizonInstalled = ref(false);

// Issues state
const issuePrefillData = ref(null);
const logsNavigateTo = ref(null);
const issuesStats = ref(null);
const issuesStatsPollingInterval = ref(null);

// Dropdown state (keeping for backward compatibility, but will be replaced by sidebar)
const showSettingsDropdown = ref(false);
const showCommandsDropdown = ref(false);
const showExplorerDropdown = ref(false);
const showToolsDropdown = ref(false);
const showQueriesDropdown = ref(false);

// Sidebar navigation state
const sidebarCollapsed = ref(false);

// Favorites tray state
const showFavoritesTray = ref(false);
const favoritesTrayHoverTimeout = ref(null);

// Scan state
const activeScanId = ref(null);
const scanPollingInterval = ref(null);
const scanHistoryRef = ref(null);
const activeDatabaseScanId = ref(null);
const databaseScanPollingInterval = ref(null);
const databaseScanHistoryRef = ref(null);

// Theme and font system
const terminalDrawerRef = ref(null);
const { currentTheme, setTheme } = useTerminalTheme();
const { fontSize, fontSizeMin, fontSizeMax, adjustFontSize: adjustFontSizeNew } = useTerminalFont();

// Terminal height state (stored in localStorage)
const terminalHeight = ref(60); // Default height in vh
const terminalHeightMin = 30; // Minimum height in vh
const terminalHeightMax = 100; // Maximum height in vh (allows full page height)
const isResizing = ref(false);
const resizeStartY = ref(0);
const resizeStartHeight = ref(0);

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

// Computed style for terminal output (now uses CSS variables, but keep for backward compatibility)
const terminalStyle = computed(() => ({
	fontSize: `var(--terminal-font-size-base, ${fontSize.value}px)`,
	fontFamily: 'var(--terminal-font-family)',
	lineHeight: 'var(--terminal-line-height, 1.6)',
}));

// Computed style for terminal drawer height
const terminalDrawerStyle = computed(() => ({
	height: `${terminalHeight.value}vh`,
}));

// Load terminal height from localStorage
function loadTerminalHeight() {
	const saved = localStorage.getItem('developer_terminal_height');
	if (saved) {
		const parsed = parseFloat(saved);
		if (!isNaN(parsed) && parsed >= terminalHeightMin && parsed <= terminalHeightMax) {
			terminalHeight.value = parsed;
		}
	}
}

// Save terminal height to localStorage
function saveTerminalHeight(height) {
	localStorage.setItem('developer_terminal_height', height.toString());
	terminalHeight.value = height;
}

// Start resizing
function startResize(event) {
	isResizing.value = true;
	resizeStartY.value = event.clientY || event.touches[0].clientY;
	resizeStartHeight.value = terminalHeight.value;
	document.addEventListener('mousemove', handleResize);
	document.addEventListener('mouseup', stopResize);
	document.addEventListener('touchmove', handleResize);
	document.addEventListener('touchend', stopResize);
	event.preventDefault();
}

// Handle resize
function handleResize(event) {
	if (!isResizing.value) return;
	
	const clientY = event.clientY || event.touches[0].clientY;
	const deltaY = resizeStartY.value - clientY; // Negative because we're dragging up
	const deltaVh = (deltaY / window.innerHeight) * 100;
	const newHeight = Math.max(terminalHeightMin, Math.min(terminalHeightMax, resizeStartHeight.value + deltaVh));
	
	terminalHeight.value = newHeight;
}

// Stop resizing
function stopResize() {
	if (isResizing.value) {
		saveTerminalHeight(terminalHeight.value);
		isResizing.value = false;
		document.removeEventListener('mousemove', handleResize);
		document.removeEventListener('mouseup', stopResize);
		document.removeEventListener('touchmove', handleResize);
		document.removeEventListener('touchend', stopResize);
	}
}

// Insert command from templates/snippets
function insertCommand(command) {
	// Preserve line breaks when inserting code
	commandInput.value = command;
	nextTick(() => {
		focusInput();
		// Auto-resize after inserting multi-line code
		autoResizeTextarea();
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
		focusInput();
		// Auto-resize after inserting multi-line code
		autoResizeTextarea();
	});
}

// Execute command from AI
function executeCommandFromAi(command) {
	if (!command) {
		return;
	}
	// Preserve line breaks when inserting code
	commandInput.value = command;
	// Switch to terminal tab
	ensureTabOpen('terminal');
	nextTick(() => {
		executeCommand();
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

// Load AI status and models
async function loadAiStatus() {
	try {
		const response = await axios.get(api.ai.status());
		if (response.data.success && response.data.available) {
			// Load models
			try {
				const modelsResponse = await axios.get(api.ai.models());
				if (modelsResponse.data.success && modelsResponse.data.available) {
					const models = modelsResponse.data.models || [];
					if (models.length > 0 && !selectedAiModel.value) {
						selectedAiModel.value = modelsResponse.data.default_model || models[0].name;
					}
				}
			} catch (error) {
				console.error('Failed to load AI models:', error);
			}
		}
	} catch (error) {
		console.error('Failed to load AI status:', error);
	}
}

// Send message to AI
async function sendAiMessage(message) {
	if (!message.trim() || isSendingAi.value) {
		return;
	}

	const userMessage = message.trim();
	
	// Add user message to conversation history
	aiConversationHistory.value.push({
		role: 'user',
		content: userMessage,
	});

	// Ensure AI tab is open
	ensureTabOpen('ai');
	
	// Notify AI component to add user message
	if (aiRef.value && aiRef.value.addUserMessage) {
		aiRef.value.addUserMessage(userMessage);
	}

	isSendingAi.value = true;

	try {
		const response = await axios.post(api.ai.chat(), {
			message: userMessage,
			model: selectedAiModel.value,
			conversation_history: aiConversationHistory.value.slice(-10), // Last 10 messages
		});

		if (response.data.success) {
			const aiMessage = response.data.message;
			
			// Add AI response to conversation history
			aiConversationHistory.value.push({
				role: 'assistant',
				content: aiMessage,
			});

			// Notify AI component to add assistant message
			if (aiRef.value && aiRef.value.addAssistantMessage) {
				aiRef.value.addAssistantMessage(aiMessage);
			}
		} else {
			const errorMsg = `Error: ${response.data.error || 'Unknown error'}`;
			if (aiRef.value && aiRef.value.addAssistantMessage) {
				aiRef.value.addAssistantMessage(errorMsg, true);
			}
		}
	} catch (error) {
		console.error('Failed to send AI message:', error);
		const errorMsg = `Error: ${error.response?.data?.error || error.message || 'Failed to communicate with AI'}`;
		if (aiRef.value && aiRef.value.addAssistantMessage) {
			aiRef.value.addAssistantMessage(errorMsg, true);
		}
	} finally {
		isSendingAi.value = false;
	}
}

// Execute command
async function executeCommand() {
	if (!commandInput.value.trim() || isExecuting.value || isSendingAi.value) {
		return;
	}

	// Preserve the full command including line breaks for execution
	const command = commandInput.value.trim();
	
	// Check if command starts with /shell
	if (command.startsWith('/shell')) {
		// Extract the command after /shell
		const shellCmd = command.substring(6).trim();
		
		if (!shellCmd) {
			// If just /shell, toggle shell mode
			toggleShell();
			return;
		}
		
		// Clear input but restore /shell prefix to keep shell mode active
		commandInput.value = '/shell ';
		if (inputRef.value) {
			inputRef.value.style.height = 'auto';
		}
		
		// Execute shell command
		await executeShellCommand(shellCmd);
		
		// Focus input after sending
		nextTick(() => {
			if (inputRef.value) {
				inputRef.value.focus();
			}
		});
		return;
	}
	
	// Check if command starts with /ai
	if (command.startsWith('/ai')) {
		// Extract the message after /ai
		const aiMessage = command.substring(3).trim();
		
		if (!aiMessage) {
			// If just /ai, focus input and show AI tab
			toggleAi();
			return;
		}
		
		// Clear input but restore /ai prefix to keep AI mode active
		commandInput.value = '/ai ';
		if (inputRef.value) {
			inputRef.value.style.height = 'auto';
		}
		
		// Send to AI
		await sendAiMessage(aiMessage);
		
		// Focus input after sending
		nextTick(() => {
			if (inputRef.value) {
				inputRef.value.focus();
			}
		});
		return;
	}
	
	// Regular command execution
	// Switch to terminal tab to show output
	ensureTabOpen('terminal');
	
	// Add to history if not duplicate of last command (trimmed for history comparison)
	const trimmedCommand = command.trim();
	if (commandHistory.value.length === 0 || commandHistory.value[commandHistory.value.length - 1] !== trimmedCommand) {
		commandHistory.value.push(trimmedCommand);
	}
	historyIndex.value = -1;

	// Add command to output (preserve line breaks for multi-line commands)
	addOutput('command', command);

	// Clear input
	const cmd = commandInput.value;
	commandInput.value = '';
	// Reset textarea height
	if (inputRef.value) {
		inputRef.value.style.height = 'auto';
	}
	isExecuting.value = true;

	try {
		const response = await axios.post(api.url('execute'), {
			command: cmd,
		}, {
			timeout: 300000, // 5 minutes for long-running commands
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
		let errorMessage = 'Execution failed';
		
		// Handle different error response formats
		if (error.response) {
			// Server responded with error status
			const errorData = error.response.data;
			
			if (errorData?.errors && Array.isArray(errorData.errors) && errorData.errors.length > 0) {
				// Use the first error message from the errors array
				errorMessage = errorData.errors[0];
			} else if (errorData?.error) {
				// Single error message
				errorMessage = errorData.error;
			} else if (errorData?.message) {
				// Generic message field
				errorMessage = errorData.message;
			} else if (typeof errorData === 'string') {
				// Error data is a string
				errorMessage = errorData;
			} else if (error.response.status === 500) {
				// 500 error - should not happen but handle gracefully
				errorMessage = `Server error (500): ${error.response.statusText || 'Internal server error'}. Please check the server logs.`;
			} else {
				// Other HTTP errors
				errorMessage = `Request failed (${error.response.status}): ${error.response.statusText || 'Unknown error'}`;
			}
		} else if (error.request) {
			// Request was made but no response received
			errorMessage = 'No response from server. Please check your connection and try again.';
		} else {
			// Error setting up the request
			errorMessage = error.message || 'Execution failed';
		}
		
		addOutput('error', {
			formatted: errorMessage,
			raw: errorMessage,
		});
	} finally {
		isExecuting.value = false;
		await nextTick();
		scrollToBottom();
		// Refocus input after command execution
		focusInput();
	}
}

// Add output to history
function addOutput(type, output, raw = null) {
	outputHistory.value.push({
		type,
		output,
		raw: raw || output,
		timestamp: new Date(),
	});
}

// Clear terminal
async function clearTerminal() {
	outputHistory.value = [];
	await nextTick();
	scrollToBottom();
}

// Clear session
async function clearSession() {
	try {
		await axios.delete(api.url('session'));
		addOutput('text', 'Session cleared');
	} catch (error) {
		addOutput('error', {
			formatted: 'Failed to clear session: ' + (error.response?.data?.errors?.[0] || error.message),
			raw: 'Failed to clear session: ' + (error.response?.data?.errors?.[0] || error.message),
		});
	}
}

// Tab management functions
function isTabOpen(tabId) {
	return openTabs.value.some(tab => tab.id === tabId);
}

function isTabActive(tabId) {
	return activeTab.value === tabId;
}

function openTab(tabId, options = {}) {
	if (!tabConfigs[tabId]) return;
	
	// If tab is not open, add it
	if (!isTabOpen(tabId)) {
		openTabs.value.push(tabConfigs[tabId]);
	}
	
	// Make it active
	activeTab.value = tabId;
	
	// Store options for component to use (e.g., initialItem)
	if (options.itemId || options.highlight || options.filter) {
		// Store in a way components can access
		// Components can watch activeTab and check for stored options
		if (typeof window !== 'undefined') {
			if (!window.overlordTabOptions) {
				window.overlordTabOptions = {};
			}
			window.overlordTabOptions[tabId] = options;
		}
	}
}

function closeTab(tabId) {
	// Cannot close terminal tab
	if (tabId === 'terminal') return;
	
	// Remove tab from open tabs
	const index = openTabs.value.findIndex(tab => tab.id === tabId);
	if (index !== -1) {
		openTabs.value.splice(index, 1);
		
		// If closed tab was active, switch to terminal
		if (activeTab.value === tabId) {
			activeTab.value = 'terminal';
		}
	}
}

function closeAllTabs() {
	openTabs.value = [{ id: 'terminal', label: 'Terminal', closable: false }];
	activeTab.value = 'terminal';
}

function closeOtherTabs(tabId) {
	openTabs.value = openTabs.value.filter(tab => tab.id === tabId || tab.id === 'terminal');
	if (activeTab.value !== tabId && activeTab.value !== 'terminal') {
		activeTab.value = tabId;
	}
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

function switchTab(tabId) {
	if (isTabOpen(tabId)) {
		activeTab.value = tabId;
	}
}

function ensureTabOpen(tabId) {
	if (!isTabOpen(tabId)) {
		openTab(tabId);
	} else {
		switchTab(tabId);
	}
}

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

function toggleFavorites() {
	if (isTabActive('favorites')) {
		closeTab('favorites');
	} else {
		ensureTabOpen('favorites');
	}
}

// Load favorites from localStorage for tray
function loadFavoritesForTray() {
	try {
		const saved = localStorage.getItem('developer_terminal_favorites');
		if (saved) {
			const data = JSON.parse(saved);
			return data.favorites || [];
		}
	} catch (e) {
		console.error('Failed to load favorites for tray:', e);
	}
	return [];
}

// Get top favorites (most recently used or first 10)
const topFavorites = computed(() => {
	const favorites = loadFavoritesForTray();
	// Return top 10 favorites
	return favorites.slice(0, 10);
});

// Get favorite type color
function getFavoriteTypeColor(type) {
	const colors = {
		command: '#007acc',
		template: '#4fc3f7',
		snippet: '#9ca3af',
		builder: '#00d4aa',
		custom: '#ff9800',
	};
	return colors[type] || '#9ca3af';
}

// Get favorite type label
function getFavoriteTypeLabel(type) {
	const labels = {
		command: 'Command',
		template: 'Template',
		snippet: 'Snippet',
		builder: 'Builder',
		custom: 'Custom',
	};
	return labels[type] || 'Custom';
}

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
		executeCommand();
	}
	showFavoritesTray.value = false;
}

// Handle favorites tray hover with delay
function handleFavoritesTrayHover() {
	if (favoritesTrayHoverTimeout.value) {
		clearTimeout(favoritesTrayHoverTimeout.value);
		favoritesTrayHoverTimeout.value = null;
	}
	favoritesTrayHoverTimeout.value = setTimeout(() => {
		showFavoritesTray.value = true;
	}, 300);
}

// Handle favorites tray leave with delay
function handleFavoritesTrayLeave() {
	if (favoritesTrayHoverTimeout.value) {
		clearTimeout(favoritesTrayHoverTimeout.value);
		favoritesTrayHoverTimeout.value = null;
	}
	favoritesTrayHoverTimeout.value = setTimeout(() => {
		showFavoritesTray.value = false;
	}, 200);
}

function toggleAi() {
	let trimmed = commandInput.value.trim();
	
	// Remove /shell prefix if present
	if (trimmed.startsWith('/shell')) {
		trimmed = trimmed.replace(/^\/shell\s*/, '').trim();
	}
	
	// Toggle /ai prefix: remove if present, add if not
	if (trimmed.startsWith('/ai')) {
		// Remove /ai prefix (handle both "/ai" and "/ai " cases)
		commandInput.value = trimmed.replace(/^\/ai\s*/, '').trim();
	} else {
		// Add /ai prefix
		commandInput.value = '/ai ' + (trimmed ? trimmed + ' ' : '');
	}
	
	// Ensure AI tab is open to show conversation
	ensureTabOpen('ai');
	
	// Load AI status if not already loaded
	loadAiStatus();
	
	// Focus the input
	nextTick(() => {
		if (inputRef.value) {
			inputRef.value.focus();
			// Move cursor to end
			const length = inputRef.value.value.length;
			inputRef.value.setSelectionRange(length, length);
		}
	});
}

function toggleShell() {
	let trimmed = commandInput.value.trim();
	
	// Remove /ai prefix if present
	if (trimmed.startsWith('/ai')) {
		trimmed = trimmed.replace(/^\/ai\s*/, '').trim();
	}
	
	// Toggle /shell prefix: remove if present, add if not
	if (trimmed.startsWith('/shell')) {
		// Remove /shell prefix (handle both "/shell" and "/shell " cases)
		commandInput.value = trimmed.replace(/^\/shell\s*/, '').trim();
	} else {
		// Add /shell prefix
		commandInput.value = '/shell ' + (trimmed ? trimmed + ' ' : '');
	}
	
	// Switch to terminal tab to show output
	ensureTabOpen('terminal');
	
	// Focus the input
	nextTick(() => {
		if (inputRef.value) {
			inputRef.value.focus();
			// Move cursor to end
			const length = inputRef.value.value.length;
			inputRef.value.setSelectionRange(length, length);
		}
	});
}

// Execute shell command
async function executeShellCommand(shellCmd) {
	if (!shellCmd.trim() || isExecuting.value) {
		return;
	}

	// Switch to terminal tab to show output
	ensureTabOpen('terminal');
	
	// Add to history if not duplicate of last command
	const fullCommand = '/shell ' + shellCmd;
	const trimmedCommand = fullCommand.trim();
	if (commandHistory.value.length === 0 || commandHistory.value[commandHistory.value.length - 1] !== trimmedCommand) {
		commandHistory.value.push(trimmedCommand);
	}
	historyIndex.value = -1;

	// Add command to output
	addOutput('command', fullCommand);

	isExecuting.value = true;

	try {
		const response = await axios.post(api.shell.execute(), {
			command: shellCmd,
		}, {
			timeout: 65000, // 65 seconds (slightly more than backend timeout)
		});

		if (response.data.success) {
			const result = response.data.result;
			addOutput(result.type || 'text', result.output, result.raw);
		} else {
			addOutput('error', {
				formatted: response.data.errors?.[0] || 'Unknown error',
				raw: response.data.errors?.[0] || 'Unknown error',
			});
		}
	} catch (error) {
		let errorMessage = 'Shell command execution failed';
		
		// Handle different error response formats
		if (error.response) {
			const errorData = error.response.data;
			
			if (errorData?.errors && Array.isArray(errorData.errors) && errorData.errors.length > 0) {
				errorMessage = errorData.errors[0];
			} else if (errorData?.error) {
				errorMessage = errorData.error;
			} else if (errorData?.message) {
				errorMessage = errorData.message;
			} else if (typeof errorData === 'string') {
				errorMessage = errorData;
			} else if (error.response.status === 500) {
				errorMessage = `Server error (500): ${error.response.statusText || 'Internal server error'}. Please check the server logs.`;
			} else {
				errorMessage = `Request failed (${error.response.status}): ${error.response.statusText || 'Unknown error'}`;
			}
		} else if (error.request) {
			errorMessage = 'No response from server. Please check your connection and try again.';
		} else if (error.code === 'ECONNABORTED') {
			errorMessage = 'Command execution timeout. The command may be taking too long to execute.';
		} else {
			errorMessage = error.message || 'Shell command execution failed';
		}
		
		addOutput('error', {
			formatted: errorMessage,
			raw: errorMessage,
		});
	} finally {
		isExecuting.value = false;
		await nextTick();
		scrollToBottom();
		// Refocus input after command execution
		focusInput();
	}
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

// Load issues stats
async function loadIssuesStats() {
	try {
		const response = await axios.get(api.issues.stats());
		if (response.data && response.data.success) {
			issuesStats.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load issues stats:', error);
		issuesStats.value = null;
	}
}

// Start issues stats polling
function startIssuesStatsPolling() {
	if (issuesStatsPollingInterval.value) {
		return; // Already polling
	}
	
	// Load immediately
	loadIssuesStats();
	
	// Then poll every 30 seconds
	issuesStatsPollingInterval.value = setInterval(() => {
		loadIssuesStats();
	}, 30000);
}

// Stop issues stats polling
function stopIssuesStatsPolling() {
	if (issuesStatsPollingInterval.value) {
		clearInterval(issuesStatsPollingInterval.value);
		issuesStatsPollingInterval.value = null;
	}
}

// Computed for issues counter display
const issuesCounter = computed(() => {
	if (!issuesStats.value) return null;
	
	const openCount = (issuesStats.value.by_status?.open || 0) + (issuesStats.value.by_status?.in_progress || 0);
	const criticalCount = issuesStats.value.by_priority?.critical || 0;
	const highCount = issuesStats.value.by_priority?.high || 0;
	const mediumCount = issuesStats.value.by_priority?.medium || 0;
	
	// Determine color based on highest priority
	let color = 'blue'; // default/low
	if (criticalCount > 0) {
		color = 'red';
	} else if (highCount > 0) {
		color = 'orange';
	} else if (mediumCount > 0) {
		color = 'yellow';
	}
	
	return {
		count: openCount,
		color,
		critical: criticalCount,
		high: highCount,
		medium: mediumCount,
	};
});

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

// Handle scan start from config component
async function handleStartScan(config) {
	try {
		// Check for existing issues first
		const existingIssuesResponse = await axios.get(api.scan.hasExistingIssues());
		let clearExisting = false;
		
		if (existingIssuesResponse.data && existingIssuesResponse.data.success && existingIssuesResponse.data.result.has_issues) {
			const unresolvedCount = existingIssuesResponse.data.result.unresolved_count;
			const result = await Swal.fire({
				icon: 'question',
				title: 'Existing Scan Issues Found',
				html: `You have <strong>${unresolvedCount}</strong> unresolved scan issue${unresolvedCount !== 1 ? 's' : ''} from previous scans.<br><br>Would you like to clear them before starting a new scan?`,
				showCancelButton: true,
				confirmButtonText: 'Clear and Scan',
				cancelButtonText: 'Keep and Scan',
				confirmButtonColor: '#3085d6',
				cancelButtonColor: '#6c757d',
			});
			
			if (result.isConfirmed) {
				clearExisting = true;
			}
		}
		
		// Close config tab and open scan results tab
		closeTab('scan-config');
		ensureTabOpen('scan-results');
		switchTab('scan-results'); // Switch to results tab immediately so user sees loading state
		
		const response = await axios.post(api.scan.start(), {
			clear_existing: clearExisting,
			mode: config.mode,
			paths: config.paths || [],
		});
		
		// Add logging to debug production issue
		console.log('Codebase scan start response:', response.data);
		
		// Check if we have a scan_id even if success is missing
		const scanId = response.data?.result?.scan_id || response.data?.scan_id;
		if (response.data && (response.data.success || scanId)) {
			if (scanId) {
				activeScanId.value = scanId;
				
				// Start polling immediately
				startScanPolling();
				
				// Request notification permission
				if ('Notification' in window && Notification.permission === 'default') {
					Notification.requestPermission();
				}
				return; // Exit early on success
			}
		}
		
		// Only show error if we truly don't have a scan_id
		const errorCode = response.data?.code;
		let errorMessage = response.data?.error || 'Unknown error';
		
		if (errorCode === 'QUOTA_EXCEEDED') {
			errorMessage = response.data.error || 'Unknown error';
		}
		
		Swal.fire({
			icon: 'error',
			title: 'Failed to start scan',
			text: errorMessage,
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
	} catch (error) {
		console.error('Failed to start scan:', error);
		// Check for QUOTA_EXCEEDED code and use error message from response
		const errorCode = error.response?.data?.code;
		let errorMessage = error.response?.data?.error || 'Unknown error';
		
		if (errorCode === 'QUOTA_EXCEEDED') {
			errorMessage = error.response?.data?.error || 'Unknown error';
		}
		
		Swal.fire({
			icon: 'error',
			title: 'Failed to start scan',
			text: errorMessage,
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
	}
}

// Start polling for scan status
function startScanPolling() {
	// Clear any existing interval
	if (scanPollingInterval.value) {
		clearInterval(scanPollingInterval.value);
	}
	
	// Poll every 2.5 seconds
	scanPollingInterval.value = setInterval(async () => {
		if (!activeScanId.value) {
			stopScanPolling();
			return;
		}
		
		try {
			const response = await axios.get(api.scan.status(activeScanId.value));
			if (response.data && response.data.success) {
				const status = response.data.result;
				
				if (status.status === 'completed') {
					stopScanPolling();
					
					// Close config tab if it's still open
					if (isTabOpen('scan-config')) {
						closeTab('scan-config');
					}
					
					// Ensure scan results tab is open and active
					ensureTabOpen('scan-results');
					switchTab('scan-results');
					
					// Show browser notification
					if ('Notification' in window && Notification.permission === 'granted') {
						const notification = new Notification('Codebase Scan Complete', {
							body: `Scan completed successfully. Found ${status.total_issues_found || 0} issues. Click to view results.`,
							icon: '/favicon.ico',
							tag: 'scan-complete',
						});
						
						// Make notification clickable to focus the terminal
						notification.onclick = () => {
							window.focus();
							ensureTabOpen('scan-results');
							switchTab('scan-results');
							notification.close();
						};
					}
					
					// Show toast notification
					Swal.fire({
						icon: 'success',
						title: 'Scan Complete',
						text: `Found ${status.total_issues_found || 0} issues. Results tab opened.`,
						toast: true,
						position: 'top-end',
						showConfirmButton: false,
						timer: 4000,
					});
				} else if (status.status === 'failed') {
					stopScanPolling();
					
					// Check if it's a rate limit error
					const isRateLimit = status.rate_limit_exceeded || 
					                   (status.error && (
					                       status.error.toLowerCase().includes('rate limit') ||
					                       status.error.toLowerCase().includes('quota exceeded') ||
					                       status.error.toLowerCase().includes('rate limit exceeded')
					                   ));
					
					const errorTitle = isRateLimit ? 'Rate Limit Exceeded' : 'Scan Failed';
					let errorText = status.error || (isRateLimit ? 'Rate limit exceeded. Please upgrade your plan.' : 'Unknown error');
					
					// For rate limit errors, ensure we have a proper message with link
					if (isRateLimit) {
						// Remove any existing HTML tags from the error message to avoid duplication
						const cleanError = errorText.replace(/<[^>]*>/g, '').trim();
						
						// Build the message with proper HTML link
						errorText = `${cleanError} Please upgrade your plan at <a href="https://laravel-overlord.com/signin" target="_blank" rel="noopener noreferrer">laravel-overlord.com/signin</a> to continue.`;
						
						// Add animation indicator
						errorText = `<span class="rate-limit-indicator"></span>${errorText}<span class="rate-limit-indicator"></span>`;
					}
					
					// Show error notification
					if ('Notification' in window && Notification.permission === 'granted') {
						// Strip HTML for notification
						const plainText = errorText.replace(/<[^>]*>/g, '');
						new Notification(errorTitle, {
							body: plainText,
							icon: '/favicon.ico',
							tag: 'scan-failed',
						});
					}
					
					Swal.fire({
						icon: isRateLimit ? 'warning' : 'error',
						title: errorTitle,
						html: errorText,
						toast: true,
						position: 'top-end',
						showConfirmButton: false,
						timer: isRateLimit ? 6000 : 3000,
					});
				}
			}
		} catch (error) {
			console.error('Failed to check scan status:', error);
		}
	}, 2500);
}

// Stop polling for scan status
function stopScanPolling() {
	if (scanPollingInterval.value) {
		clearInterval(scanPollingInterval.value);
		scanPollingInterval.value = null;
	}
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

async function handleStartDatabaseScan(config) {
	try {
		// Check for existing issues first
		const existingIssuesResponse = await axios.get(api.databaseScan.hasExistingIssues());
		let clearExisting = false;
		
		if (existingIssuesResponse.data && existingIssuesResponse.data.success && existingIssuesResponse.data.result.has_issues) {
			const unresolvedCount = existingIssuesResponse.data.result.unresolved_count;
			const result = await Swal.fire({
				icon: 'question',
				title: 'Existing Database Scan Issues Found',
				html: `You have <strong>${unresolvedCount}</strong> unresolved database scan issue${unresolvedCount !== 1 ? 's' : ''} from previous scans.<br><br>Would you like to clear them before starting a new scan?`,
				showCancelButton: true,
				confirmButtonText: 'Clear and Scan',
				cancelButtonText: 'Keep and Scan',
			});
			
			if (result.isConfirmed) {
				clearExisting = true;
			}
		}
		
		// Close config tab and open scan results tab
		closeTab('database-scan-config');
		ensureTabOpen('database-scan-results');
		
		const response = await axios.post(api.databaseScan.start(), {
			clear_existing: clearExisting,
			type: config.type,
			mode: config.mode,
			tables: config.tables || [],
			sample_size: config.sample_size || 100,
		});
		
		// Add logging to debug production issue
		console.log('Database scan start response:', response.data);
		
		// Check if we have a scan_id even if success is missing
		const scanId = response.data?.result?.scan_id || response.data?.scan_id;
		if (response.data && (response.data.success || scanId)) {
			if (scanId) {
				activeDatabaseScanId.value = scanId;
				
				// Start polling immediately
				startDatabaseScanPolling();
				
				// Request notification permission
				if ('Notification' in window && Notification.permission === 'default') {
					Notification.requestPermission();
				}
				return; // Exit early on success
			}
		}
		
		// Only show error if we truly don't have a scan_id
		const errorCode = response.data?.code;
		let errorMessage = response.data?.error || 'Unknown error';
		
		if (errorCode === 'QUOTA_EXCEEDED') {
			errorMessage = response.data.error || 'Unknown error';
		}
		
		Swal.fire({
			icon: 'error',
			title: 'Failed to start database scan',
			text: errorMessage,
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
	} catch (error) {
		console.error('Failed to start database scan:', error);
		// Check for QUOTA_EXCEEDED code and use error message from response
		const errorCode = error.response?.data?.code;
		let errorMessage = error.response?.data?.error || 'Unknown error';
		
		if (errorCode === 'QUOTA_EXCEEDED') {
			errorMessage = error.response?.data?.error || 'Unknown error';
		}
		
		Swal.fire({
			icon: 'error',
			title: 'Failed to start database scan',
			text: errorMessage,
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
	}
}

function startDatabaseScanPolling() {
	if (databaseScanPollingInterval.value) {
		clearInterval(databaseScanPollingInterval.value);
	}
	
	databaseScanPollingInterval.value = setInterval(async () => {
		if (!activeDatabaseScanId.value) {
			stopDatabaseScanPolling();
			return;
		}
		
		try {
			const response = await axios.get(api.databaseScan.status(activeDatabaseScanId.value));
			if (response.data && response.data.success) {
				const status = response.data.result;
				
				if (status.status === 'completed') {
					stopDatabaseScanPolling();
					
					// Close config tab if it's still open
					if (isTabOpen('database-scan-config')) {
						closeTab('database-scan-config');
					}
					
					// Ensure scan results tab is open and active
					ensureTabOpen('database-scan-results');
					switchTab('database-scan-results');
					
					// Show browser notification
					if ('Notification' in window && Notification.permission === 'granted') {
						const notification = new Notification('Database Scan Complete', {
							body: `Scan completed successfully. Found ${status.total_issues_found || 0} issues. Click to view results.`,
							icon: '/favicon.ico',
						});
						
						notification.onclick = () => {
							window.focus();
							ensureTabOpen('database-scan-results');
							switchTab('database-scan-results');
							notification.close();
						};
					}
					
					// Show toast notification with proper styling
					Swal.fire({
						icon: 'success',
						title: 'Database Scan Complete',
						text: `Found ${status.total_issues_found || 0} issue${status.total_issues_found !== 1 ? 's' : ''}`,
						toast: true,
						position: 'top-end',
						showConfirmButton: false,
						timer: 3000,
						width: 'auto',
						padding: '1rem',
					});
					
					// No need to force reload - the component's checkStatus() will detect completion
					// and automatically load results via loadResults() in the status check handler
				} else if (status.status === 'failed') {
					stopDatabaseScanPolling();
					
					// Check if it's a rate limit error
					const isRateLimit = status.rate_limit_exceeded || 
					                   (status.error && (
					                       status.error.toLowerCase().includes('rate limit') ||
					                       status.error.toLowerCase().includes('quota exceeded') ||
					                       status.error.toLowerCase().includes('rate limit exceeded')
					                   ));
					
					const errorTitle = isRateLimit ? 'Rate Limit Exceeded' : 'Database Scan Failed';
					let errorText = status.error || (isRateLimit ? 'Rate limit exceeded. Please upgrade your plan.' : 'Unknown error');
					
					// For rate limit errors, ensure we have a proper message with link
					if (isRateLimit) {
						// Remove any existing HTML tags from the error message to avoid duplication
						const cleanError = errorText.replace(/<[^>]*>/g, '').trim();
						
						// Build the message with proper HTML link
						errorText = `${cleanError} Please upgrade your plan at <a href="https://laravel-overlord.com/signin" target="_blank" rel="noopener noreferrer">laravel-overlord.com/signin</a> to continue.`;
						
						// Add animation indicator
						errorText = `<span class="rate-limit-indicator"></span>${errorText}<span class="rate-limit-indicator"></span>`;
					}
					
					Swal.fire({
						icon: isRateLimit ? 'warning' : 'error',
						title: errorTitle,
						html: errorText,
						toast: true,
						position: 'top-end',
						showConfirmButton: false,
						timer: isRateLimit ? 6000 : 5000,
					});
				}
			}
		} catch (error) {
			console.error('Failed to check database scan status:', error);
		}
	}, 2500);
}

function stopDatabaseScanPolling() {
	if (databaseScanPollingInterval.value) {
		clearInterval(databaseScanPollingInterval.value);
		databaseScanPollingInterval.value = null;
	}
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

// Use command from history
async function useCommandFromHistory(log) {
	// Set command in input
	commandInput.value = log.command;
	
	// Switch to terminal tab to show output
	ensureTabOpen('terminal');
	
	// Show the output from this log entry
	outputHistory.value = [];
	
	// Add the command
	addOutput('command', log.command);
	
	// Add the output or error
	if (log.success && log.output) {
		// Try to parse output type
		let outputType = log.output_type || 'text';
		let outputData = log.output;
		
		// Try to parse as JSON if it's a json type
		if (outputType === 'json' || outputType === 'object') {
			try {
				outputData = JSON.parse(log.output);
				// Format for JsonViewer
				addOutput(outputType, outputData);
			} catch (e) {
				// If parsing fails, treat as text
				addOutput('text', log.output);
			}
		} else {
			addOutput(outputType, log.output);
		}
	} else if (!log.success && log.error) {
		addOutput('error', {
			formatted: log.error,
			raw: log.error,
		});
	}
	
	// Switch to terminal tab to show output
	ensureTabOpen('terminal');
	
	// Scroll to show the output
	await nextTick();
	scrollToBottom();
	focusInput();
}

// Scroll to bottom
function scrollToBottom() {
	if (outputContainerRef.value && typeof outputContainerRef.value.scrollToBottom === 'function') {
		outputContainerRef.value.scrollToBottom();
	}
}

// Handle keyboard input
function handleKeyDown(event) {
	// Enter to execute
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault();
		executeCommand();
		return;
	}

	// Up arrow for history
	if (event.key === 'ArrowUp') {
		event.preventDefault();
		if (commandHistory.value.length > 0) {
			if (historyIndex.value === -1) {
				historyIndex.value = commandHistory.value.length - 1;
			} else if (historyIndex.value > 0) {
				historyIndex.value--;
			}
			if (historyIndex.value >= 0) {
				commandInput.value = commandHistory.value[historyIndex.value];
			}
		}
		return;
	}

	// Down arrow for history
	if (event.key === 'ArrowDown') {
		event.preventDefault();
		if (historyIndex.value >= 0) {
			if (historyIndex.value < commandHistory.value.length - 1) {
				historyIndex.value++;
				commandInput.value = commandHistory.value[historyIndex.value];
			} else {
				historyIndex.value = -1;
				commandInput.value = '';
			}
		}
		return;
	}

	// Ctrl+L to clear
	if (event.key === 'l' && event.ctrlKey) {
		event.preventDefault();
		clearTerminal();
		return;
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

// Auto-resize textarea based on content
function autoResizeTextarea() {
	if (inputRef.value) {
		// Reset height to auto to get the correct scrollHeight
		inputRef.value.style.height = 'auto';
		// Set height based on scrollHeight, with min and max constraints
		const newHeight = Math.min(Math.max(inputRef.value.scrollHeight, 40), 200);
		inputRef.value.style.height = `${newHeight}px`;
	}
}

// Focus input field
function focusInput() {
	if (inputRef.value) {
		inputRef.value.focus();
		// Auto-resize when focusing
		nextTick(() => {
			autoResizeTextarea();
		});
	}
}

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
	// Clean up resize listeners
	stopResize();
	
	// Clean up click outside listener
	document.removeEventListener('click', handleClickOutside);
	
	// Clean up scan polling
	stopScanPolling();
	stopDatabaseScanPolling();
	
	// Clean up issues stats polling
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
		<button
			v-if="!isOpen && props.floating"
			@click="isOpen = true"
			class="terminal-toggle-btn"
			title="Open Laravel Overlord"
		>
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
			</svg>
			<span>DEV</span>
		</button>

		<!-- Terminal Drawer -->
		<transition :name="props.floating ? 'slide-up' : ''">
			<div v-if="isOpen" ref="terminalDrawerRef" class="terminal-drawer" :style="props.floating ? terminalDrawerStyle : {}">
				<!-- Resize Handle (only show when floating) -->
				<div
					v-if="props.floating"
					class="terminal-resize-handle"
					@mousedown="startResize"
					@touchstart="startResize"
					:class="{ 'terminal-resizing': isResizing }"
					title="Drag to resize terminal"
				>
					<div class="terminal-resize-handle-indicator"></div>
				</div>
				
				<!-- Terminal Layout with Sidebar -->
				<div class="terminal-layout">
					<!-- Sidebar Navigation -->
					<aside class="terminal-sidebar" :class="{ 'collapsed': sidebarCollapsed }">
						<div class="terminal-sidebar-header">
							<div v-if="!sidebarCollapsed" class="terminal-sidebar-branding">
								<div class="terminal-sidebar-title-wrapper">
									<span class="terminal-beta-badge-sidebar">BETA</span>
									<span class="terminal-sidebar-title-laravel">Laravel</span>
									<span class="terminal-sidebar-title-overlord">Overlord</span>
								</div>
							</div>
							<div v-if="!sidebarCollapsed" class="terminal-sidebar-nav-label">Navigation</div>
							<button
								@click="sidebarCollapsed = !sidebarCollapsed"
								class="terminal-sidebar-toggle"
								:title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path v-if="!sidebarCollapsed" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
									<path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
								</svg>
							</button>
						</div>
						
						<nav class="terminal-sidebar-nav">
							<!-- Quick Actions Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Quick Actions</div>
								<button
									@click="toggleHistory"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('history') }"
									:title="sidebarCollapsed ? 'History' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">History</span>
								</button>
								<button
									@click="toggleFavorites"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('favorites') }"
									:title="sidebarCollapsed ? 'Favorites' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
									</svg>
									<span v-if="!sidebarCollapsed">Favorites</span>
								</button>
								<button
									@click="showHelp"
									class="terminal-nav-item"
									:title="sidebarCollapsed ? 'Help' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">Help</span>
								</button>
							</div>
							
							<!-- Components Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Components</div>
								<button
									@click="toggleControllers"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('controllers') }"
									:title="sidebarCollapsed ? 'Controllers' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
									</svg>
									<span v-if="!sidebarCollapsed">Controllers</span>
								</button>
								<button
									@click="toggleRoutes"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('routes') }"
									:title="sidebarCollapsed ? 'Routes' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
									</svg>
									<span v-if="!sidebarCollapsed">Routes</span>
								</button>
								<button
									@click="toggleClasses"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('classes') }"
									:title="sidebarCollapsed ? 'Classes' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
									</svg>
									<span v-if="!sidebarCollapsed">Classes</span>
								</button>
								<button
									@click="toggleModelDiagram"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('model-diagram') }"
									:title="sidebarCollapsed ? 'Models' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
									</svg>
									<span v-if="!sidebarCollapsed">Models</span>
								</button>
								<button
									@click="toggleTraits"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('traits') }"
									:title="sidebarCollapsed ? 'Traits' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
									</svg>
									<span v-if="!sidebarCollapsed">Traits</span>
								</button>
								<button
									@click="toggleServices"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('services') }"
									:title="sidebarCollapsed ? 'Services' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
									</svg>
									<span v-if="!sidebarCollapsed">Services</span>
								</button>
								<button
									@click="toggleRequests"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('requests') }"
									:title="sidebarCollapsed ? 'Requests' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Requests</span>
								</button>
								<button
									@click="toggleProviders"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('providers') }"
									:title="sidebarCollapsed ? 'Providers' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
									</svg>
									<span v-if="!sidebarCollapsed">Providers</span>
								</button>
								<button
									@click="toggleMiddleware"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('middleware') }"
									:title="sidebarCollapsed ? 'Middleware' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
									</svg>
									<span v-if="!sidebarCollapsed">Middleware</span>
								</button>
								<button
									@click="toggleJobs"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('jobs') }"
									:title="sidebarCollapsed ? 'Jobs' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Jobs</span>
								</button>
								<button
									@click="toggleExceptions"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('exceptions') }"
									:title="sidebarCollapsed ? 'Exceptions' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
									</svg>
									<span v-if="!sidebarCollapsed">Exceptions</span>
								</button>
							</div>
							
							<!-- Database Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Database</div>
								<button
									@click="toggleDatabase"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('database') }"
									:title="sidebarCollapsed ? 'Database' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
									</svg>
									<span v-if="!sidebarCollapsed">Explorer</span>
								</button>
								<button
									@click="toggleTemplates"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('templates') }"
									:title="sidebarCollapsed ? 'Templates' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Templates</span>
								</button>
								<button
									@click="toggleMigrations"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('migrations') }"
									:title="sidebarCollapsed ? 'Migrations' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
									</svg>
									<span v-if="!sidebarCollapsed">Migrations</span>
								</button>
							</div>
							
							<!-- Commands Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Commands</div>
								<button
									@click="toggleCommandClasses"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('command-classes') }"
									:title="sidebarCollapsed ? 'Command Classes' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Command Classes</span>
								</button>
								<button
									@click="toggleCommands"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('commands') }"
									:title="sidebarCollapsed ? 'Artisan Commands' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
									</svg>
									<span v-if="!sidebarCollapsed">Artisan Commands</span>
								</button>
							</div>
							
							<!-- Tools Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Tools</div>
								<button
									@click="startScan"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('scan-config') || isTabActive('scan-results') }"
									:title="sidebarCollapsed ? 'Scan Codebase' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">Scan Codebase</span>
								</button>
								<button
									@click="toggleScanHistory"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('scan-history') }"
									:title="sidebarCollapsed ? 'Scan History' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">Scan History</span>
								</button>
								<button
									@click="startDatabaseScan"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('database-scan-config') || isTabActive('database-scan-results') }"
									:title="sidebarCollapsed ? 'Scan Database' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
									</svg>
									<span v-if="!sidebarCollapsed">Scan Database</span>
								</button>
								<button
									@click="toggleDatabaseScanHistory"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('database-scan-history') }"
									:title="sidebarCollapsed ? 'Database Scan History' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">DB Scan History</span>
								</button>
							</div>
							
							<!-- System Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">System</div>
								<button
									@click="toggleLogs"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('logs') }"
									:title="sidebarCollapsed ? 'Logs' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Logs</span>
								</button>
								<button
									@click="toggleIssues"
									class="terminal-nav-item"
									:class="{ 'active': isTabActive('issues') }"
									:title="sidebarCollapsed ? 'Issues' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Issues</span>
								</button>
								<button
									@click="toggleHorizon"
									class="terminal-nav-item"
									:class="{ 
										'active': isTabActive('horizon'),
										'disabled': !horizonInstalled
									}"
									:disabled="!horizonInstalled"
									:title="sidebarCollapsed ? (horizonInstalled ? 'Horizon' : 'Horizon (Not Installed)') : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
									</svg>
									<span v-if="!sidebarCollapsed">Horizon</span>
								</button>
							</div>
							
							<!-- Settings Section -->
							<div class="terminal-nav-section">
								<div v-if="!sidebarCollapsed" class="terminal-nav-section-title">Settings</div>
								<button
									@click="openSettings($event)"
									class="terminal-nav-item"
									:title="sidebarCollapsed ? 'UI Settings' : ''"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
									</svg>
									<span v-if="!sidebarCollapsed">UI Settings</span>
								</button>
							</div>
						</nav>
					</aside>
					
					<!-- Main Content Area -->
					<div class="terminal-main-content">
						<!-- Favorites Tray (Top-Aligned Full-Width Drawer) -->
						<div 
							class="terminal-favorites-tray"
							:class="{ 'drawer-open': showFavoritesTray }"
							@mouseenter="handleFavoritesTrayHover"
							@mouseleave="handleFavoritesTrayLeave"
						>
							<div class="terminal-favorites-tray-shelf">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
								</svg>
								<span class="terminal-favorites-tray-label">Favorites</span>
							</div>
							<transition name="favorites-tray">
								<div 
									v-if="showFavoritesTray" 
									class="terminal-favorites-tray-content"
								>
									<div class="terminal-favorites-tray-header">
										<h3>Quick Access</h3>
										<button @click="toggleFavorites" class="terminal-favorites-tray-view-all">
											View All
										</button>
									</div>
									<div v-if="topFavorites.length > 0" class="terminal-favorites-tray-list">
										<div
											v-for="favorite in topFavorites"
											:key="favorite.id"
											class="terminal-favorites-tray-item"
										>
											<div class="terminal-favorites-tray-item-info">
												<span class="terminal-favorites-tray-item-name">{{ favorite.name }}</span>
												<span 
													class="terminal-favorites-tray-item-type"
													:style="{ color: getFavoriteTypeColor(favorite.type) }"
												>
													{{ getFavoriteTypeLabel(favorite.type) }}
												</span>
											</div>
											<div class="terminal-favorites-tray-item-actions">
												<button
													@click="insertFavoriteCommand(favorite)"
													class="terminal-favorites-tray-action-btn"
													title="Insert"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
														<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l3-3m0 0l3 3m-3-3H15" />
													</svg>
												</button>
												<button
													@click="executeFavoriteCommand(favorite)"
													class="terminal-favorites-tray-action-btn"
													title="Execute"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
														<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z" />
													</svg>
												</button>
											</div>
										</div>
									</div>
									<div v-else class="terminal-favorites-tray-empty">
										<p>No favorites yet. Add commands to favorites for quick access.</p>
									</div>
								</div>
							</transition>
						</div>
						
						<!-- Tab Bar -->
						<div v-if="openTabs.length > 0" class="terminal-tabs" :class="{ 'favorites-drawer-open': showFavoritesTray }">
							<div class="terminal-tabs-header">
								<div class="terminal-tabs-container">
									<button
										v-for="tab in openTabs"
										:key="tab.id"
										@click="switchTab(tab.id)"
										@contextmenu.prevent="handleTabContextMenu($event, tab.id)"
										:class="['terminal-tab', { 'active': isTabActive(tab.id) }]"
										:title="tab.label"
									>
										<span class="terminal-tab-label">{{ tab.label }}</span>
										<button
											v-if="tab.closable"
											@click.stop="closeTab(tab.id)"
											class="terminal-tab-close"
											title="Close tab"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
												<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
											</svg>
										</button>
									</button>
								</div>
								<!-- Issues/Notifications Actions -->
								<div class="terminal-nav-actions">
									<!-- Issues Counter -->
									<button
										v-if="issuesCounter"
										@click="toggleIssues"
										class="terminal-issues-counter"
										:class="`terminal-issues-counter-${issuesCounter.color}`"
										:title="`${issuesCounter.count} open issues${issuesCounter.critical > 0 ? `, ${issuesCounter.critical} critical` : ''}${issuesCounter.high > 0 ? `, ${issuesCounter.high} high priority` : ''}`"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
										</svg>
										<span class="terminal-issues-counter-badge">{{ issuesCounter.count }}</span>
									</button>
									<!-- Notifications Placeholder -->
									<button
										class="terminal-notifications-btn"
										title="Notifications (Coming Soon)"
										disabled
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
										</svg>
									</button>
								</div>
							</div>
						</div>

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
						<div class="terminal-input-area">
					<!-- AI Toggle Button -->
					<button
						@click="toggleShell"
						class="terminal-btn terminal-btn-secondary terminal-btn-icon terminal-shell-toggle"
						:class="{ 'terminal-btn-active': commandInput.trim().startsWith('/shell') }"
						title="Toggle Shell Terminal (/shell prefix)"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
						</svg>
					</button>
					<button
						@click="toggleAi"
						class="terminal-btn terminal-btn-secondary terminal-btn-icon terminal-ai-toggle"
						:class="{ 'terminal-btn-active': commandInput.trim().startsWith('/ai') }"
						title="Toggle AI Assistant (/ai prefix)"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
						</svg>
					</button>
					<div class="terminal-prompt">$</div>
					<textarea
						ref="inputRef"
						v-model="commandInput"
						@keydown="handleKeyDown"
						@input="autoResizeTextarea"
						:disabled="isExecuting"
						class="terminal-input"
						:style="terminalStyle"
						placeholder="Enter command, type /shell for shell commands, or /ai for AI questions... (Shift+Enter for newline)"
						autocomplete="off"
						spellcheck="false"
						rows="1"
					/>
					<button
						@click="addCurrentCommandToFavorites"
						:disabled="!commandInput.trim() || isExecuting"
						class="terminal-btn terminal-btn-secondary terminal-btn-icon"
						title="Add to Favorites"
					>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
						</svg>
					</button>
					<button
						@click="executeCommand"
						:disabled="!commandInput.trim() || isExecuting || isSendingAi"
						class="terminal-btn terminal-btn-primary"
					>
						{{ isSendingAi ? 'Sending...' : 'Execute' }}
					</button>
						</div>
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
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
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
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
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
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
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

</style>

