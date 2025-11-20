<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useTerminalTheme, themes, initThemeRoot } from '../useTerminalTheme.js';
import { useTerminalFont, fontFamilies, initFontRoot } from '../useTerminalFont.js';
import TerminalDropdown from './TerminalDropdown.vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi.js';
import Swal from 'sweetalert2';

const props = defineProps({
	visible: {
		type: Boolean,
		default: true,
	},
	isModal: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'theme-changed']);

const { currentTheme, setTheme } = useTerminalTheme();
const {
	fontSize,
	fontFamily,
	lineHeight,
	fontSizeMin,
	fontSizeMax,
	setFontSize,
	adjustFontSize,
	setFontFamily,
	setLineHeight,
} = useTerminalFont();

// Code Theme (from TerminalThemeToggle)
const availableCodeThemes = [
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

const selectedCodeTheme = ref('github-dark');
const themeLinkId = 'hljs-theme-link';
const baseThemeLinkId = 'hljs-base-link';
let baseThemeLoaded = false;

// Load theme preference from localStorage
function loadThemePreference() {
	const saved = localStorage.getItem('terminal_code_theme');
	if (saved && availableCodeThemes.find(t => t.value === saved)) {
		selectedCodeTheme.value = saved;
	}
}

// Save theme preference to localStorage
function saveThemePreference(theme) {
	localStorage.setItem('terminal_code_theme', theme);
}

// Load base highlight.js CSS (only once)
function loadBaseTheme() {
	if (baseThemeLoaded) return;
	
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
	loadBaseTheme();
	
	const existingLink = document.getElementById(themeLinkId);
	if (existingLink) {
		existingLink.remove();
	}

	const link = document.createElement('link');
	link.id = themeLinkId;
	link.rel = 'stylesheet';
	link.href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/${theme}.min.css`;
	link.onload = () => {
		emit('theme-changed', theme);
	};
	link.onerror = () => {
		if (theme !== 'default') {
			selectedCodeTheme.value = 'default';
			saveThemePreference('default');
			loadThemeCSS('default');
		}
	};

	document.head.appendChild(link);
}

// Handle code theme change
function handleCodeThemeChange(value) {
	selectedCodeTheme.value = value;
	saveThemePreference(value);
	loadThemeCSS(value);
}

// Code theme options for dropdown
const codeThemeOptions = computed(() => {
	return availableCodeThemes.map(theme => ({
		value: theme.value,
		label: theme.label,
		description: theme.label,
	}));
});

const settingsRef = ref(null);
const api = useOverlordApi();

// API Key settings
const apiKey = ref('');
const maskedApiKey = ref('');
const showApiKey = ref(false);
const apiKeySource = ref(null); // 'env' or 'database'
const isFromEnv = ref(false);
const isLoadingApiKey = ref(false);
const isSavingApiKey = ref(false);

// Apply theme variables to settings component
function applyThemeToSettings() {
	if (!settingsRef.value) return;
	
	const theme = themes[currentTheme.value];
	if (!theme || !theme.variables) return;
	
	// Apply CSS variables directly to the settings component
	Object.entries(theme.variables).forEach(([key, value]) => {
		settingsRef.value.style.setProperty(key, value);
	});
}

// Apply font settings to settings component
function applyFontToSettings() {
	if (!settingsRef.value) return;
	
	settingsRef.value.style.setProperty('--terminal-font-size-base', `${fontSize.value}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-xs', `${Math.max(8, fontSize.value - 4)}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-sm', `${Math.max(10, fontSize.value - 2)}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-md', `${fontSize.value}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-lg', `${fontSize.value + 2}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-xl', `${fontSize.value + 4}px`);
	settingsRef.value.style.setProperty('--terminal-font-size-2xl', `${fontSize.value + 6}px`);
	
	const familyConfig = fontFamilies[fontFamily.value] || fontFamilies.monospace;
	settingsRef.value.style.setProperty('--terminal-font-family', familyConfig.value);
	settingsRef.value.style.setProperty('--terminal-line-height', lineHeight.value.toString());
}

// Re-apply theme/font when settings change to update all components
watch([currentTheme, fontSize, fontFamily, lineHeight], () => {
	// Update the main terminal wrapper
	const wrapper = document.querySelector('.developer-terminal-wrapper');
	if (wrapper) {
		initThemeRoot(wrapper);
		initFontRoot(wrapper);
	}
	
	// Update the settings popup itself
	applyThemeToSettings();
	applyFontToSettings();
});

// Initialize theme and font on mount
onMounted(() => {
	applyThemeToSettings();
	applyFontToSettings();
	loadThemePreference();
	loadBaseTheme();
	setTimeout(() => {
		loadThemeCSS(selectedCodeTheme.value);
	}, 100);
	loadApiKeySetting();
});

// Preview text
const previewText = ref('User::where("active", true)->count()');

// Theme options
const themeOptions = computed(() => {
	return Object.entries(themes).map(([key, theme]) => ({
		value: key,
		label: theme.name,
		description: theme.description,
	}));
});

// Font family options
const fontFamilyOptions = computed(() => {
	return Object.entries(fontFamilies).map(([key, font]) => ({
		value: key,
		label: font.name,
		description: font.description,
	}));
});

// Line height options
const lineHeightOptions = [
	{ value: 1.4, label: 'Compact', description: '1.4' },
	{ value: 1.6, label: 'Normal', description: '1.6' },
	{ value: 1.8, label: 'Comfortable', description: '1.8' },
	{ value: 2.0, label: 'Spacious', description: '2.0' },
];

// Handle theme change
function handleThemeChange(value) {
	setTheme(value);
}

// Handle font family change
function handleFontFamilyChange(value) {
	setFontFamily(value);
}

// Handle line height change
function handleLineHeightChange(value) {
	setLineHeight(parseFloat(value));
}

// Preview style
const previewStyle = computed(() => ({
	fontSize: `${fontSize.value}px`,
	fontFamily: fontFamilies[fontFamily.value]?.value || fontFamilies.monospace.value,
	lineHeight: lineHeight.value.toString(),
}));

// Load API key setting
async function loadApiKeySetting() {
	isLoadingApiKey.value = true;
	try {
		const response = await axios.get(api.ai.getApiKeySetting());
		if (response.data.success) {
			maskedApiKey.value = response.data.masked_key || '';
			apiKeySource.value = response.data.source;
			isFromEnv.value = response.data.is_from_env || false;
			
			// If from ENV, show a message that it can't be edited
			if (isFromEnv.value) {
				apiKey.value = '';
			} else if (response.data.has_api_key) {
				// If from database, don't pre-fill the input for security
				apiKey.value = '';
			}
		}
	} catch (error) {
		console.error('Failed to load API key setting:', error);
	} finally {
		isLoadingApiKey.value = false;
	}
}

// Save API key
async function saveApiKey() {
	if (!apiKey.value.trim()) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: 'Please enter an API key',
		});
		return;
	}

	isSavingApiKey.value = true;
	try {
		const response = await axios.put(api.ai.updateApiKeySetting(), {
			api_key: apiKey.value.trim(),
		});

		if (response.data.success) {
			maskedApiKey.value = response.data.masked_key || '';
			apiKeySource.value = 'database';
			isFromEnv.value = false;
			apiKey.value = ''; // Clear input after saving
			
			Swal.fire({
				icon: 'success',
				title: 'Success',
				text: 'API key saved successfully',
				timer: 2000,
				showConfirmButton: false,
			});
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data.error || 'Failed to save API key',
			});
		}
	} catch (error) {
		const errorMessage = error.response?.data?.error || error.message || 'Failed to save API key';
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: errorMessage,
		});
	} finally {
		isSavingApiKey.value = false;
	}
}

// Delete API key
async function deleteApiKey() {
	const result = await Swal.fire({
		icon: 'warning',
		title: 'Delete API Key?',
		text: 'Are you sure you want to remove the API key? AI features will be disabled.',
		showCancelButton: true,
		confirmButtonText: 'Delete',
		cancelButtonText: 'Cancel',
		confirmButtonColor: '#d33',
	});

	if (!result.isConfirmed) {
		return;
	}

	try {
		const response = await axios.delete(api.ai.deleteApiKeySetting());

		if (response.data.success) {
			maskedApiKey.value = '';
			apiKeySource.value = null;
			isFromEnv.value = false;
			apiKey.value = '';
			
			Swal.fire({
				icon: 'success',
				title: 'Success',
				text: 'API key removed successfully',
				timer: 2000,
				showConfirmButton: false,
			});
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data.error || 'Failed to remove API key',
			});
		}
	} catch (error) {
		const errorMessage = error.response?.data?.error || error.message || 'Failed to remove API key';
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: errorMessage,
		});
	}
}

// Toggle show/hide API key
function toggleShowApiKey() {
	showApiKey.value = !showApiKey.value;
}
</script>

<template>
	<div ref="settingsRef" class="terminal-settings" :class="{ 'terminal-settings-pane': !isModal }">
		<!-- Header (only show in modal mode) -->
		<div v-if="isModal" class="terminal-settings-header">
			<h3 class="terminal-settings-title">UI Settings</h3>
			<button @click="emit('close')" class="terminal-settings-close" title="Close" aria-label="Close settings">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<!-- Content -->
		<div class="terminal-settings-content">
			<!-- Theme Selection -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Theme</span>
					<span class="terminal-settings-label-desc">Choose a color theme</span>
				</label>
				<TerminalDropdown
					:model-value="currentTheme"
					:options="themeOptions"
					placeholder="Select a theme"
					@update:model-value="handleThemeChange"
				/>
			</div>

			<!-- Font Size -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Font Size</span>
					<span class="terminal-settings-label-desc">{{ fontSize }}px</span>
				</label>
				<div class="terminal-settings-control-group">
					<button
						@click="adjustFontSize(-1)"
						class="terminal-settings-btn"
						:disabled="fontSize <= fontSizeMin"
						title="Decrease font size"
						aria-label="Decrease font size"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
						</svg>
					</button>
					<input
						type="range"
						:min="fontSizeMin"
						:max="fontSizeMax"
						:value="fontSize"
						@input="setFontSize(parseInt($event.target.value, 10))"
						class="terminal-settings-slider"
						aria-label="Font size"
					/>
					<button
						@click="adjustFontSize(1)"
						class="terminal-settings-btn"
						:disabled="fontSize >= fontSizeMax"
						title="Increase font size"
						aria-label="Increase font size"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
						</svg>
					</button>
				</div>
			</div>

			<!-- Font Family -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Font Family</span>
					<span class="terminal-settings-label-desc">Choose a font family</span>
				</label>
				<TerminalDropdown
					:model-value="fontFamily"
					:options="fontFamilyOptions"
					placeholder="Select a font family"
					@update:model-value="handleFontFamilyChange"
				/>
			</div>

			<!-- Line Height -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Line Height</span>
					<span class="terminal-settings-label-desc">Adjust spacing between lines</span>
				</label>
				<TerminalDropdown
					:model-value="lineHeight"
					:options="lineHeightOptions"
					placeholder="Select line height"
					@update:model-value="handleLineHeightChange"
				/>
			</div>

			<!-- Code Theme -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Code Theme</span>
					<span class="terminal-settings-label-desc">Choose a syntax highlighting theme for code blocks</span>
				</label>
				<TerminalDropdown
					:model-value="selectedCodeTheme"
					:options="codeThemeOptions"
					placeholder="Select a code theme"
					@update:model-value="handleCodeThemeChange"
				/>
			</div>

			<!-- API Key -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">AI API Key</span>
					<span class="terminal-settings-label-desc">
						Get your API key from 
						<a href="https://laravel-overlord.com" target="_blank" rel="noopener noreferrer" class="terminal-settings-link">laravel-overlord.com</a>
						to enable AI features
					</span>
				</label>
				<div v-if="isLoadingApiKey" class="terminal-settings-loading">
					Loading...
				</div>
				<div v-else class="terminal-settings-api-key">
					<!-- Show masked key if configured -->
					<div v-if="maskedApiKey" class="terminal-settings-api-key-status">
						<div class="terminal-settings-api-key-masked">
							<span class="terminal-settings-api-key-label">Current Key:</span>
							<span class="terminal-settings-api-key-value">{{ maskedApiKey }}</span>
							<span v-if="isFromEnv" class="terminal-settings-api-key-badge">(from ENV)</span>
							<span v-else-if="apiKeySource === 'database'" class="terminal-settings-api-key-badge">(from settings)</span>
						</div>
						<div v-if="!isFromEnv" class="terminal-settings-api-key-actions">
							<button
								@click="deleteApiKey"
								class="terminal-settings-api-key-delete"
								title="Remove API key"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
								</svg>
							</button>
						</div>
					</div>
					<!-- Input for new/updating API key -->
					<div class="terminal-settings-api-key-input-group">
						<div class="terminal-settings-api-key-input-wrapper">
							<input
								v-model="apiKey"
								type="password"
								:placeholder="maskedApiKey ? 'Enter new API key to update' : 'Enter your API key'"
								class="terminal-settings-api-key-input"
								:disabled="isFromEnv"
							/>
							<button
								v-if="apiKey"
								@click="toggleShowApiKey"
								type="button"
								class="terminal-settings-api-key-toggle"
								:title="showApiKey ? 'Hide' : 'Show'"
							>
								<svg v-if="!showApiKey" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
								</svg>
								<svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
								</svg>
							</button>
						</div>
						<input
							v-if="showApiKey && apiKey"
							:value="apiKey"
							type="text"
							readonly
							class="terminal-settings-api-key-input terminal-settings-api-key-input-visible"
						/>
						<button
							@click="saveApiKey"
							:disabled="!apiKey.trim() || isSavingApiKey || isFromEnv"
							class="terminal-settings-api-key-save"
						>
							<span v-if="isSavingApiKey">Saving...</span>
							<span v-else>{{ maskedApiKey ? 'Update' : 'Save' }}</span>
						</button>
					</div>
					<div v-if="isFromEnv" class="terminal-settings-api-key-note">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
						<span>API key is set in environment variables. Remove LARAVEL_OVERLORD_API_KEY from your .env file to manage it here.</span>
					</div>
				</div>
			</div>

			<!-- Preview -->
			<div class="terminal-settings-section">
				<label class="terminal-settings-label">
					<span class="terminal-settings-label-text">Preview</span>
					<span class="terminal-settings-label-desc">See how your settings look</span>
				</label>
				<div class="terminal-settings-preview" :style="previewStyle">
					<div class="terminal-settings-preview-prompt">$</div>
					<div class="terminal-settings-preview-text">{{ previewText }}</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-settings {
	background: var(--terminal-bg, #1e1e1e);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	max-width: 520px;
	width: 100%;
	position: relative;
	z-index: 10005;
	box-shadow: 
		0 4px 20px var(--terminal-shadow, rgba(0, 0, 0, 0.3)),
		0 8px 32px var(--terminal-shadow-medium, rgba(0, 0, 0, 0.2)),
		0 0 0 1px var(--terminal-border, #3e3e42);
	overflow: visible;
	display: flex;
	flex-direction: column;
}

.terminal-settings-pane {
	max-width: none;
	width: 100%;
	height: 100%;
	border: none;
	border-radius: 0;
	box-shadow: none;
	overflow-y: auto;
}

.terminal-settings-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 18px 20px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	background: var(--terminal-bg-secondary, #252526);
	border-radius: 6px 6px 0 0;
}

.terminal-settings-title {
	margin: 0;
	font-size: var(--terminal-font-size-lg, 18px);
	font-weight: 700;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-settings-close {
	background: transparent;
	border: 1px solid transparent;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 6px;
	border-radius: 4px;
	transition: all 0.2s ease;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
}

.terminal-settings-close:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
	border-color: var(--terminal-border, #3e3e42);
}

.terminal-settings-close:focus {
	outline: none;
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
	border-color: var(--terminal-primary, #0e639c);
	box-shadow: 0 0 0 2px var(--terminal-primary, #0e639c);
}

.terminal-settings-close svg {
	width: 20px;
	height: 20px;
}

.terminal-settings-content {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 24px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 0 0 6px 6px;
	overflow: visible;
}

.terminal-settings-section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.terminal-settings-label {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.terminal-settings-label-text {
	font-size: var(--terminal-font-size-md, 14px);
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-settings-label-desc {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
	font-weight: 400;
}

.terminal-settings-control-group {
	display: flex;
	align-items: center;
	gap: 12px;
}

.terminal-settings-btn {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	cursor: pointer;
	padding: 8px 10px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s ease;
	flex-shrink: 0;
	width: 40px;
	height: 40px;
}

.terminal-settings-btn:hover:not(:disabled) {
	background: var(--terminal-bg-secondary, #252526);
	border-color: var(--terminal-primary, #0e639c);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-settings-btn:focus:not(:disabled) {
	outline: 2px solid var(--terminal-primary, #0e639c);
	outline-offset: 2px;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-settings-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.terminal-settings-btn svg {
	width: 18px;
	height: 18px;
}

.terminal-settings-slider {
	flex: 1;
	height: 6px;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 3px;
	outline: none;
	-webkit-appearance: none;
	appearance: none;
	cursor: pointer;
}

.terminal-settings-slider:focus {
	border-color: var(--terminal-primary, #0e639c);
	outline: 2px solid var(--terminal-primary, #0e639c);
	outline-offset: 2px;
}

.terminal-settings-slider::-webkit-slider-thumb {
	-webkit-appearance: none;
	appearance: none;
	width: 18px;
	height: 18px;
	background: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	cursor: pointer;
	transition: all 0.2s ease;
	border: 2px solid var(--terminal-bg, #1e1e1e);
	box-shadow: 0 0 0 1px var(--terminal-border, #3e3e42);
}

.terminal-settings-slider::-webkit-slider-thumb:hover {
	background: var(--terminal-primary-hover, #1177bb);
	transform: scale(1.15);
}

.terminal-settings-slider::-moz-range-thumb {
	width: 18px;
	height: 18px;
	background: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	cursor: pointer;
	border: 2px solid var(--terminal-bg, #1e1e1e);
	box-shadow: 0 0 0 1px var(--terminal-border, #3e3e42);
	transition: all 0.2s ease;
}

.terminal-settings-slider::-moz-range-thumb:hover {
	background: var(--terminal-primary-hover, #1177bb);
	transform: scale(1.15);
}

.terminal-settings-preview {
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	padding: 14px;
	display: flex;
	align-items: center;
	gap: 8px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	line-height: var(--terminal-line-height, 1.6);
	min-height: 60px;
	transition: all 0.2s ease;
}

.terminal-settings-preview:hover {
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-settings-preview-prompt {
	color: var(--terminal-prompt, #4ec9b0);
	font-weight: 600;
	user-select: none;
	flex-shrink: 0;
}

.terminal-settings-preview-text {
	color: var(--terminal-text, #d4d4d4);
	white-space: pre-wrap;
	word-wrap: break-word;
	font-weight: 500;
}

.terminal-settings-link {
	color: var(--terminal-primary, #0e639c);
	text-decoration: none;
	transition: color 0.2s ease;
}

.terminal-settings-link:hover {
	color: var(--terminal-primary-hover, #1177bb);
	text-decoration: underline;
}

.terminal-settings-loading {
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-sm, 12px);
	padding: 10px 0;
}

.terminal-settings-api-key {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-settings-api-key-status {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
}

.terminal-settings-api-key-masked {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
}

.terminal-settings-api-key-label {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
	font-weight: 500;
}

.terminal-settings-api-key-value {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text, #d4d4d4);
	font-family: 'Consolas', 'Monaco', monospace;
	font-weight: 600;
	letter-spacing: 1px;
}

.terminal-settings-api-key-badge {
	font-size: var(--terminal-font-size-xs, 10px);
	color: var(--terminal-text-secondary, #858585);
	background: var(--terminal-bg-tertiary, #2d2d30);
	padding: 2px 6px;
	border-radius: 4px;
	font-weight: 500;
}

.terminal-settings-api-key-actions {
	display: flex;
	gap: 8px;
}

.terminal-settings-api-key-delete {
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 6px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s ease;
	width: 32px;
	height: 32px;
}

.terminal-settings-api-key-delete:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: #dc3545;
	color: #dc3545;
}

.terminal-settings-api-key-delete svg {
	width: 16px;
	height: 16px;
}

.terminal-settings-api-key-input-group {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.terminal-settings-api-key-input-wrapper {
	position: relative;
	display: flex;
	align-items: center;
}

.terminal-settings-api-key-input {
	width: 100%;
	padding: 10px 40px 10px 14px;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-md, 14px);
	font-family: 'Consolas', 'Monaco', monospace;
	transition: all 0.2s ease;
}

.terminal-settings-api-key-input:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
	box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.2);
}

.terminal-settings-api-key-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-settings-api-key-input-visible {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--terminal-bg, #1e1e1e);
	z-index: 1;
}

.terminal-settings-api-key-toggle {
	position: absolute;
	right: 8px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 4px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: color 0.2s ease;
	z-index: 2;
}

.terminal-settings-api-key-toggle:hover {
	color: var(--terminal-text, #d4d4d4);
}

.terminal-settings-api-key-toggle svg {
	width: 18px;
	height: 18px;
}

.terminal-settings-api-key-save {
	background: var(--terminal-primary, #0e639c);
	border: 2px solid var(--terminal-primary, #0e639c);
	border-radius: 6px;
	color: white;
	cursor: pointer;
	padding: 10px 20px;
	font-size: var(--terminal-font-size-md, 14px);
	font-weight: 600;
	transition: all 0.2s ease;
	align-self: flex-start;
}

.terminal-settings-api-key-save:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
}

.terminal-settings-api-key-save:focus:not(:disabled) {
	outline: 2px solid var(--terminal-primary, #0e639c);
	outline-offset: 2px;
}

.terminal-settings-api-key-save:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.terminal-settings-api-key-note {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 14px;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
}

.terminal-settings-api-key-note svg {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
	margin-top: 2px;
	color: var(--terminal-primary, #0e639c);
}

.terminal-settings-api-key-note span {
	line-height: 1.5;
}
</style>
