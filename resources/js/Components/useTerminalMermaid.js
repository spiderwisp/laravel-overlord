import { ref, computed } from 'vue';
import axios from 'axios';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalMermaid() {
	const api = useOverlordApi();
	
	const loading = ref(false);
	const diagram = ref('');
	const error = ref(null);

	// Drill-down navigation state (for model-focused view)
	const navigationHistory = ref([]); // Stores { name, level }
	const isFocusedView = ref(false);
	const focusedModel = ref(null); // Name of the currently focused model
	const connectionDepth = ref(1); // 1, 2, or 3 levels of relationships

	// Filters state
	const filters = ref({
		namespace: '',
	});

	/**
	 * Get breadcrumb items (last 2-3 levels)
	 */
	const breadcrumb = computed(() => {
		if (navigationHistory.value.length === 0) return [];
		// Show last 2-3 items
		const start = Math.max(0, navigationHistory.value.length - 3);
		return navigationHistory.value.slice(start);
	});

	/**
	 * Load diagram from API
	 */
	async function loadDiagram() {
		if (loading.value) return;
		
		loading.value = true;
		error.value = null;
		isFocusedView.value = false;
		focusedModel.value = null;
		navigationHistory.value = []; // Clear history for main diagram

		try {
			const response = await axios.get(api.mermaid.diagram());
			
			if (response.data && response.data.success && response.data.result) {
				diagram.value = response.data.result.diagram || '';
			} else {
				error.value = 'Failed to load diagram';
			}
		} catch (err) {
			error.value = err.response?.data?.errors?.[0] || err.message || 'Failed to load diagram';
			diagram.value = '';
		} finally {
			loading.value = false;
		}
	}

	/**
	 * Regenerate diagram
	 */
	async function regenerateDiagram() {
		if (loading.value) return;
		
		loading.value = true;
		error.value = null;
		
		// If in focused view, regenerate focused diagram
		if (isFocusedView.value && focusedModel.value) {
			await loadFocusedDiagram(focusedModel.value, connectionDepth.value);
			return;
		}

		try {
			const response = await axios.post(api.mermaid.generate());
			
			if (response.data && response.data.success && response.data.result) {
				diagram.value = response.data.result.diagram || '';
			} else {
				error.value = 'Failed to regenerate diagram';
			}
		} catch (err) {
			error.value = err.response?.data?.errors?.[0] || err.message || 'Failed to regenerate diagram';
		} finally {
			loading.value = false;
		}
	}

	/**
	 * Load focused diagram for a specific model
	 */
	async function loadFocusedDiagram(modelName, depth = null) {
		if (loading.value) return;
		
		loading.value = true;
		error.value = null;
		isFocusedView.value = true;
		focusedModel.value = modelName;
		
		// Add to navigation history
		navigationHistory.value.push({
			name: modelName,
			level: navigationHistory.value.length,
		});
		
		const depthToUse = depth !== null ? depth : connectionDepth.value;
		
		try {
			const response = await axios.get(api.mermaid.focused(), {
				params: {
					node: modelName,
					depth: depthToUse,
				}
			});
			
			if (response.data && response.data.success && response.data.result) {
				diagram.value = response.data.result.diagram || '';
				connectionDepth.value = depthToUse;
			} else {
				error.value = 'Failed to load focused diagram';
			}
		} catch (err) {
			error.value = err.response?.data?.errors?.[0] || err.message || 'Failed to load focused diagram';
			diagram.value = '';
		} finally {
			loading.value = false;
		}
	}

	/**
	 * Navigate back to a specific level in history
	 */
	async function navigateToLevel(level) {
		if (level < 0 || level >= navigationHistory.value.length) {
			// If level is -1 or out of bounds, go to root diagram
			navigationHistory.value = [];
			await loadDiagram();
			return;
		}
		
		// Truncate history to the selected level
		navigationHistory.value = navigationHistory.value.slice(0, level + 1);
		
		// Load focused diagram for the model at this level
		const model = navigationHistory.value[level];
		await loadFocusedDiagram(model.name);
	}

	/**
	 * Navigate back one step in history
	 */
	async function navigateBack() {
		if (navigationHistory.value.length > 1) {
			await navigateToLevel(navigationHistory.value.length - 2);
		} else {
			// If only one item or no items, go to root diagram
			await navigateToLevel(-1);
		}
	}

	/**
	 * Update connection depth and reload focused diagram
	 */
	async function updateConnectionDepth(depth) {
		if (isFocusedView.value && focusedModel.value) {
			await loadFocusedDiagram(focusedModel.value, depth);
		}
	}

	return {
		loading,
		diagram,
		error,
		loadDiagram,
		regenerateDiagram,
		// Drill-down
		navigationHistory,
		breadcrumb,
		isFocusedView,
		focusedModel,
		connectionDepth,
		loadFocusedDiagram,
		navigateToLevel,
		navigateBack,
		updateConnectionDepth,
		// Filters
		filters,
	};
}
