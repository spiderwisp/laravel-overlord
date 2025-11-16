<script setup>
import { ref, onMounted, watch } from 'vue';

const emit = defineEmits(['theme-changed']);

// Available highlight.js themes (dark themes suitable for terminal)
const availableThemes = [
	{ value: 'github-dark', label: 'GitHub Dark' },
	{ value: 'vs2015', label: 'VS2015' },
	{ value: 'monokai', label: 'Monokai' },
	{ value: 'dracula', label: 'Dracula' },
	{ value: 'atom-one-dark', label: 'Atom One Dark' },
	{ value: 'tomorrow-night', label: 'Tomorrow Night' },
	{ value: 'tomorrow-night-blue', label: 'Tomorrow Night Blue' },
	{ value: 'tomorrow-night-bright', label: 'Tomorrow Night Bright' },
	{ value: 'dark', label: 'Dark' },
	{ value: 'default', label: 'Default' },
];

const selectedTheme = ref('github-dark');
const themeLinkId = 'hljs-theme-link';

// Load theme preference from localStorage
function loadThemePreference() {
	const saved = localStorage.getItem('terminal_code_theme');
	if (saved && availableThemes.find(t => t.value === saved)) {
		selectedTheme.value = saved;
	}
}

// Save theme preference to localStorage
function saveThemePreference(theme) {
	localStorage.setItem('terminal_code_theme', theme);
}

// Load base highlight.js CSS (only once)
const baseThemeLinkId = 'hljs-base-link';
let baseThemeLoaded = false;

function loadBaseTheme() {
	if (baseThemeLoaded) return;
	
	// Check if base theme is already loaded
	const existing = document.getElementById(baseThemeLinkId);
	if (existing) {
		baseThemeLoaded = true;
		return;
	}

	const link = document.createElement('link');
	link.id = baseThemeLinkId;
	link.rel = 'stylesheet';
	link.href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/default.min.css`;
	link.onload = () => {
		baseThemeLoaded = true;
	};
	link.onerror = () => {
		console.error('Failed to load base highlight.js CSS');
	};
	document.head.appendChild(link);
}

// Load theme CSS dynamically
function loadThemeCSS(theme) {
	// Ensure base theme is loaded first
	loadBaseTheme();
	
	// Remove existing theme link if present
	const existingLink = document.getElementById(themeLinkId);
	if (existingLink) {
		existingLink.remove();
	}

	// Create new link element
	const link = document.createElement('link');
	link.id = themeLinkId;
	link.rel = 'stylesheet';
	// Use CDN for highlight.js themes (version matches installed highlight.js)
	link.href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/${theme}.min.css`;
	link.onload = () => {
		emit('theme-changed', theme);
	};
	link.onerror = () => {
		// Fallback to default theme
		if (theme !== 'default') {
			selectedTheme.value = 'default';
			saveThemePreference('default');
			loadThemeCSS('default');
		}
	};

	document.head.appendChild(link);
}

// Handle theme change
function handleThemeChange(event) {
	const newTheme = event.target.value;
	selectedTheme.value = newTheme;
	saveThemePreference(newTheme);
	loadThemeCSS(newTheme);
}

// Initialize theme on mount
onMounted(() => {
	loadThemePreference();
	// Load base theme immediately, then load selected theme
	loadBaseTheme();
	// Small delay to ensure base theme loads first
	setTimeout(() => {
		loadThemeCSS(selectedTheme.value);
	}, 100);
});

// Watch for theme changes
watch(selectedTheme, (newTheme) => {
	loadThemeCSS(newTheme);
});
</script>

<template>
	<div class="terminal-theme-toggle">
		<label class="terminal-theme-toggle-label" for="theme-select">
			Code Theme:
		</label>
		<select
			id="theme-select"
			v-model="selectedTheme"
			@change="handleThemeChange"
			class="terminal-theme-toggle-select"
		>
			<option
				v-for="theme in availableThemes"
				:key="theme.value"
				:value="theme.value"
			>
				{{ theme.label }}
			</option>
		</select>
	</div>
</template>

<style scoped>
.terminal-theme-toggle {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-theme-toggle-label {
	color: #d4d4d4;
	font-size: 12px;
	font-weight: 500;
	white-space: nowrap;
}

.terminal-theme-toggle-select {
	padding: 4px 8px;
	background: #2d2d30;
	border: 1px solid #3e3e42;
	border-radius: 4px;
	color: #d4d4d4;
	font-size: 12px;
	cursor: pointer;
	transition: all 0.2s ease;
	min-width: 150px;
}

.terminal-theme-toggle-select:hover {
	border-color: #0e639c;
	background: #1e1e1e;
}

.terminal-theme-toggle-select:focus {
	outline: none;
	border-color: #0e639c;
	box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.3);
}

.terminal-theme-toggle-select option {
	background: #1e1e1e;
	color: #d4d4d4;
}
</style>

