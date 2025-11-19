import { ref, computed } from 'vue';

export function useTerminalFavorites({ ensureTabOpen }) {
	const showFavoritesTray = ref(false);
	const favoritesTrayHoverTimeout = ref(null);

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

	// Toggle favorites tab
	function toggleFavorites(isTabActive, closeTab) {
		if (isTabActive && isTabActive('favorites')) {
			if (closeTab) {
				closeTab('favorites');
			}
		} else {
			if (ensureTabOpen) {
				ensureTabOpen('favorites');
			}
		}
	}

	return {
		showFavoritesTray,
		topFavorites,
		getFavoriteTypeColor,
		getFavoriteTypeLabel,
		handleFavoritesTrayHover,
		handleFavoritesTrayLeave,
		toggleFavorites,
	};
}

