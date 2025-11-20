import { ref, nextTick } from 'vue';
import { tabConfigs } from './useTerminalTabs.js';

export function useTerminalNavigation() {
	const logsNavigateTo = ref(null);

	// Handle navigation to reference (cross-reference system)
	function handleNavigateToReference(navData, openTab) {
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

	// Handle navigate to source
	function handleNavigateToSource(navigationData, ensureTabOpen) {
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

	return {
		logsNavigateTo,
		handleNavigateToReference,
		handleNavigateToSource,
	};
}

