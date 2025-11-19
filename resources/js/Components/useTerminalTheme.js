import { ref, watch, onMounted } from 'vue';

// Available themes with their configurations
export const themes = {
	dark: {
		name: 'Dark',
		description: 'Default dark theme',
		variables: {
			'--terminal-bg': '#1e1e1e',
			'--terminal-bg-secondary': '#252526',
			'--terminal-bg-tertiary': '#2d2d30',
			'--terminal-border': '#3e3e42',
			'--terminal-border-hover': '#4e4e52',
			'--terminal-text': '#d4d4d4',
			'--terminal-text-secondary': '#858585',
			'--terminal-text-muted': '#6b7280',
			'--terminal-primary': '#0e639c',
			'--terminal-primary-hover': '#1177bb',
			'--terminal-accent': '#4ec9b0',
			'--terminal-error': '#f48771',
			'--terminal-warning': '#dcdcaa',
			'--terminal-success': '#10b981',
			'--terminal-info': '#4ec9b0',
			'--terminal-code-bg': '#1e1e1e',
			'--terminal-code-border': '#3e3e42',
			'--terminal-prompt': '#4ec9b0',
			'--terminal-selection': 'rgba(14, 99, 156, 0.3)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.7)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.3)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.1)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.2)',
			'--terminal-primary-shadow': 'rgba(14, 99, 156, 0.2)',
		},
	},
	light: {
		name: 'Light',
		description: 'Light theme with high contrast',
		variables: {
			'--terminal-bg': '#ffffff',
			'--terminal-bg-secondary': '#f5f5f5',
			'--terminal-bg-tertiary': '#e5e5e5',
			'--terminal-border': '#d1d5db',
			'--terminal-border-hover': '#9ca3af',
			'--terminal-text': '#1f2937',
			'--terminal-text-secondary': '#4b5563',
			'--terminal-text-muted': '#6b7280',
			'--terminal-primary': '#2563eb',
			'--terminal-primary-hover': '#1d4ed8',
			'--terminal-accent': '#059669',
			'--terminal-error': '#dc2626',
			'--terminal-warning': '#d97706',
			'--terminal-success': '#059669',
			'--terminal-info': '#0891b2',
			'--terminal-code-bg': '#f9fafb',
			'--terminal-code-border': '#d1d5db',
			'--terminal-prompt': '#059669',
			'--terminal-selection': 'rgba(37, 99, 235, 0.2)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.3)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.15)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.05)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.1)',
			'--terminal-primary-shadow': 'rgba(37, 99, 235, 0.2)',
		},
	},
	'high-contrast-dark': {
		name: 'High Contrast Dark',
		description: 'Dark theme with enhanced contrast for accessibility',
		variables: {
			'--terminal-bg': '#000000',
			'--terminal-bg-secondary': '#1a1a1a',
			'--terminal-bg-tertiary': '#2a2a2a',
			'--terminal-border': '#555555',
			'--terminal-border-hover': '#777777',
			'--terminal-text': '#ffffff',
			'--terminal-text-secondary': '#cccccc',
			'--terminal-text-muted': '#aaaaaa',
			'--terminal-primary': '#00a8ff',
			'--terminal-primary-hover': '#0099ee',
			'--terminal-accent': '#00ff88',
			'--terminal-error': '#ff4444',
			'--terminal-warning': '#ffaa00',
			'--terminal-success': '#00ff88',
			'--terminal-info': '#00a8ff',
			'--terminal-code-bg': '#0a0a0a',
			'--terminal-code-border': '#555555',
			'--terminal-prompt': '#00ff88',
			'--terminal-selection': 'rgba(0, 168, 255, 0.4)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.8)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.4)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.2)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.3)',
			'--terminal-primary-shadow': 'rgba(0, 168, 255, 0.3)',
		},
	},
	'high-contrast-light': {
		name: 'High Contrast Light',
		description: 'Light theme with enhanced contrast for accessibility',
		variables: {
			'--terminal-bg': '#ffffff',
			'--terminal-bg-secondary': '#f0f0f0',
			'--terminal-bg-tertiary': '#e0e0e0',
			'--terminal-border': '#000000',
			'--terminal-border-hover': '#333333',
			'--terminal-text': '#000000',
			'--terminal-text-secondary': '#333333',
			'--terminal-text-muted': '#666666',
			'--terminal-primary': '#0000ff',
			'--terminal-primary-hover': '#0000cc',
			'--terminal-accent': '#006600',
			'--terminal-error': '#cc0000',
			'--terminal-warning': '#cc6600',
			'--terminal-success': '#006600',
			'--terminal-info': '#0066cc',
			'--terminal-code-bg': '#f5f5f5',
			'--terminal-code-border': '#000000',
			'--terminal-prompt': '#006600',
			'--terminal-selection': 'rgba(0, 0, 255, 0.3)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.4)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.2)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.1)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.15)',
			'--terminal-primary-shadow': 'rgba(0, 0, 255, 0.3)',
		},
	},
	'blue-dark': {
		name: 'Blue Dark',
		description: 'Blue-tinted dark theme',
		variables: {
			'--terminal-bg': '#1a1f2e',
			'--terminal-bg-secondary': '#252b3d',
			'--terminal-bg-tertiary': '#2f3649',
			'--terminal-border': '#3d4a5f',
			'--terminal-border-hover': '#4d5a6f',
			'--terminal-text': '#d4e4f7',
			'--terminal-text-secondary': '#8fa3c0',
			'--terminal-text-muted': '#6b7d99',
			'--terminal-primary': '#4a9eff',
			'--terminal-primary-hover': '#5aaeff',
			'--terminal-accent': '#6bc4ff',
			'--terminal-error': '#ff6b6b',
			'--terminal-warning': '#ffd93d',
			'--terminal-success': '#6bcf7f',
			'--terminal-info': '#6bc4ff',
			'--terminal-code-bg': '#1a1f2e',
			'--terminal-code-border': '#3d4a5f',
			'--terminal-prompt': '#6bc4ff',
			'--terminal-selection': 'rgba(74, 158, 255, 0.3)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.7)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.3)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.1)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.2)',
			'--terminal-primary-shadow': 'rgba(74, 158, 255, 0.2)',
		},
	},
	'green-dark': {
		name: 'Green Dark',
		description: 'Green-tinted dark theme, easier on the eyes',
		variables: {
			'--terminal-bg': '#1e2a1e',
			'--terminal-bg-secondary': '#253225',
			'--terminal-bg-tertiary': '#2d3a2d',
			'--terminal-border': '#3d4a3d',
			'--terminal-border-hover': '#4d5a4d',
			'--terminal-text': '#d4f4d4',
			'--terminal-text-secondary': '#8fc08f',
			'--terminal-text-muted': '#6b8d6b',
			'--terminal-primary': '#4caf50',
			'--terminal-primary-hover': '#5cbf60',
			'--terminal-accent': '#81c784',
			'--terminal-error': '#f44336',
			'--terminal-warning': '#ffb74d',
			'--terminal-success': '#81c784',
			'--terminal-info': '#64b5f6',
			'--terminal-code-bg': '#1e2a1e',
			'--terminal-code-border': '#3d4a3d',
			'--terminal-prompt': '#81c784',
			'--terminal-selection': 'rgba(76, 175, 80, 0.3)',
			'--terminal-overlay': 'rgba(0, 0, 0, 0.7)',
			'--terminal-shadow': 'rgba(0, 0, 0, 0.3)',
			'--terminal-shadow-light': 'rgba(0, 0, 0, 0.1)',
			'--terminal-shadow-medium': 'rgba(0, 0, 0, 0.2)',
			'--terminal-primary-shadow': 'rgba(76, 175, 80, 0.2)',
		},
	},
};

// Current theme state
const currentTheme = ref('dark');
const themeRoot = ref(null);

// Initialize theme root element
export function initThemeRoot(element) {
	themeRoot.value = element;
	loadTheme();
}

// Load theme from localStorage
function loadTheme() {
	const saved = localStorage.getItem('terminal_theme');
	if (saved && themes[saved]) {
		currentTheme.value = saved;
	}
	applyTheme(currentTheme.value);
}

// Apply theme to root element
function applyTheme(themeName) {
	if (!themeRoot.value || !themes[themeName]) {
		return;
	}

	const theme = themes[themeName];
	
	// Set data attribute for CSS targeting
	themeRoot.value.setAttribute('data-terminal-theme', themeName);
	
	// Apply CSS variables to root element so all children inherit
	Object.entries(theme.variables).forEach(([key, value]) => {
		themeRoot.value.style.setProperty(key, value);
	});
	
	// Also apply to document root for global access if it's the wrapper
	if (themeRoot.value.classList.contains('developer-terminal-wrapper')) {
		// Apply to wrapper - children will inherit via CSS cascade
		Object.entries(theme.variables).forEach(([key, value]) => {
			themeRoot.value.style.setProperty(key, value);
		});
	}
}

// Set theme
export function setTheme(themeName) {
	if (!themes[themeName]) {
		return;
	}
	
	currentTheme.value = themeName;
	localStorage.setItem('terminal_theme', themeName);
	applyTheme(themeName);
}

// Get current theme
export function getCurrentTheme() {
	return currentTheme.value;
}

// Get theme config
export function getThemeConfig(themeName = null) {
	const name = themeName || currentTheme.value;
	return themes[name] || themes.dark;
}

// Composable for Vue components
export function useTerminalTheme() {
	onMounted(() => {
		loadTheme();
	});

	watch(currentTheme, (newTheme) => {
		applyTheme(newTheme);
	});

	return {
		currentTheme,
		themes,
		setTheme,
		getCurrentTheme,
		getThemeConfig,
	};
}

