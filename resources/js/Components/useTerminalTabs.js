import { ref, computed } from 'vue';

// Tab configurations
export const tabConfigs = {
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
	'mermaid': { id: 'mermaid', label: 'Mermaid Diagram', closable: true },
	'routes': { id: 'routes', label: 'Routes', closable: true },
	'scan-config': { id: 'scan-config', label: 'Scan Configuration', closable: true },
	'scan-results': { id: 'scan-results', label: 'Scan Results', closable: true },
	'scan-history': { id: 'scan-history', label: 'Scan History', closable: true },
	'database-scan-config': { id: 'database-scan-config', label: 'Database Scan Configuration', closable: true },
	'database-scan-results': { id: 'database-scan-results', label: 'Database Scan Results', closable: true },
	'database-scan-history': { id: 'database-scan-history', label: 'Database Scan History', closable: true },
	'phpstan': { id: 'phpstan', label: 'Larastan', closable: true },
	'settings': { id: 'settings', label: 'UI Settings', closable: true },
	'bug-report': { id: 'bug-report', label: 'Report Bug', closable: true },
};

export function useTerminalTabs() {
	const activeTab = ref('terminal');
	const openTabs = ref([
		{ id: 'terminal', label: 'Terminal', closable: false }
	]);

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

	/**
	 * Creates a toggle function for a tab
	 * @param {string} tabId - The tab ID to toggle
	 * @returns {Function} Toggle function
	 */
	function createToggleFunction(tabId) {
		return function() {
			if (isTabActive(tabId)) {
				closeTab(tabId);
			} else {
				ensureTabOpen(tabId);
			}
		};
	}

	return {
		activeTab,
		openTabs,
		isTabOpen,
		isTabActive,
		openTab,
		closeTab,
		closeAllTabs,
		closeOtherTabs,
		switchTab,
		ensureTabOpen,
		createToggleFunction,
	};
}

