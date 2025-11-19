import { ref, computed, onUnmounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalIssues(api) {
	const issuesStats = ref(null);
	const issuesStatsPollingInterval = ref(null);

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

	// Cleanup on unmount
	onUnmounted(() => {
		stopIssuesStatsPolling();
	});

	return {
		issuesStats,
		issuesCounter,
		loadIssuesStats,
		startIssuesStatsPolling,
		stopIssuesStatsPolling,
	};
}

