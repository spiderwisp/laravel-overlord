<template>
	<div v-if="visible" class="terminal-phpstan">
		<div class="terminal-phpstan-header">
			<div class="phpstan-view-tabs">
				<button
					@click="viewMode = 'config'"
					class="phpstan-view-tab"
					:class="{ active: viewMode === 'config' }"
				>
					Configure
				</button>
				<button
					@click="viewMode = 'results'"
					class="phpstan-view-tab"
					:class="{ active: viewMode === 'results' }"
				>
					Results
				</button>
			</div>
			<div class="phpstan-header-actions">
				<button
					v-if="viewMode === 'config' && detectedConfig.phpstan_installed"
					@click="startAnalysis"
					class="terminal-btn terminal-btn-primary terminal-btn-sm"
					:disabled="starting"
				>
					<span v-if="starting" class="spinner-small"></span>
					{{ starting ? 'Starting...' : 'Run Larastan' }}
				</button>
				<button @click="emit('close')" class="terminal-btn terminal-btn-close" title="Close">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Configuration View -->
		<div v-if="viewMode === 'config'" class="terminal-phpstan-config-content">
			<div v-if="loadingConfig" class="config-loading">
				<span class="spinner"></span>
				Loading Larastan configuration...
			</div>

			<div v-else-if="configError" class="config-error">
				<p class="error-message">{{ configError }}</p>
			</div>

			<div v-else class="config-panel">
				<!-- Auto-detected Configuration Display -->
				<div v-if="detectedConfig.config_file" class="config-section">
					<h3>Detected Configuration</h3>
					<div class="config-info">
						<div class="config-info-item">
							<span class="config-label">Config File:</span>
							<span class="config-value">{{ detectedConfig.config_file }}</span>
						</div>
						<div v-if="detectedConfig.level !== null" class="config-info-item">
							<span class="config-label">Level:</span>
							<span class="config-value">{{ detectedConfig.level }}</span>
						</div>
						<div v-if="detectedConfig.paths && detectedConfig.paths.length > 0" class="config-info-item">
							<span class="config-label">Paths:</span>
							<span class="config-value">{{ detectedConfig.paths.join(', ') }}</span>
						</div>
						<div v-if="detectedConfig.memory_limit" class="config-info-item">
							<span class="config-label">Memory Limit:</span>
							<span class="config-value">{{ detectedConfig.memory_limit }}</span>
						</div>
					</div>
				</div>

				<div v-else class="config-section">
					<div class="config-warning">
						<p>No Larastan configuration file detected. Using defaults.</p>
					</div>
				</div>

				<!-- Override Options -->
				<div class="config-section">
					<h3>Override Options</h3>
					
					<div class="config-field">
						<label for="phpstan-level">Analysis Level (0-9)</label>
						<div class="config-field-help">
							Leave empty to use config file value or default
						</div>
						<input
							id="phpstan-level"
							v-model.number="overrideConfig.level"
							type="number"
							min="0"
							max="9"
							class="terminal-input"
							placeholder="Auto-detect"
						/>
					</div>

					<div class="config-field">
						<label for="phpstan-paths">Paths to Analyze</label>
						<div class="config-field-help">
							Comma-separated paths relative to project root (e.g., "app,config")
						</div>
						<input
							id="phpstan-paths"
							v-model="overrideConfig.paths"
							type="text"
							class="terminal-input"
							placeholder="app"
						/>
					</div>

					<div class="config-field">
						<label for="phpstan-memory">Memory Limit</label>
						<div class="config-field-help">
							PHP memory limit (e.g., "512M", "1G")
						</div>
						<input
							id="phpstan-memory"
							v-model="overrideConfig.memory_limit"
							type="text"
							class="terminal-input"
							placeholder="Auto-detect"
						/>
					</div>

					<div class="config-field">
						<label for="phpstan-baseline">Baseline File</label>
						<div class="config-field-help">
							Path to baseline file (optional)
						</div>
						<input
							id="phpstan-baseline"
							v-model="overrideConfig.baseline_file"
							type="text"
							class="terminal-input"
							placeholder="phpstan-baseline.neon"
						/>
					</div>

					<div class="config-field">
						<label class="config-checkbox-label">
							<input
								v-model="overrideConfig.ignore_errors"
								type="checkbox"
								class="terminal-checkbox"
							/>
							<span>Ignore Errors</span>
						</label>
					</div>
				</div>

				<!-- Larastan Installation Check -->
				<div v-if="!detectedConfig.phpstan_installed" class="config-section config-error-section">
					<div class="error-message">
						<p><strong>Larastan is not installed.</strong></p>
						<p>Please install it via Composer:</p>
						<code class="install-command">composer require --dev phpstan/phpstan larastan/larastan</code>
					</div>
				</div>
			</div>

			<!-- Actions -->
			<div class="phpstan-config-actions">
				<button
					@click="emit('close')"
					class="terminal-btn terminal-btn-secondary"
				>
					Cancel
				</button>
				<button
					@click="startAnalysis"
					class="terminal-btn terminal-btn-primary"
					:disabled="!detectedConfig.phpstan_installed || starting"
				>
					<span v-if="starting" class="spinner-small"></span>
					{{ starting ? 'Starting...' : 'Run Larastan' }}
				</button>
			</div>
		</div>

		<!-- Results View -->
		<TerminalPhpstanResults
			v-if="viewMode === 'results' && currentScanId"
			:visible="true"
			:scan-id="currentScanId"
			@close="emit('close')"
			@create-issue="handleCreateIssue"
		/>
		<div v-else-if="viewMode === 'results' && !currentScanId" class="terminal-phpstan-results-placeholder">
			<div class="phpstan-no-scan">
				<p>No scan selected. Please start a Larastan analysis from the Configure tab.</p>
				<button
					@click="viewMode = 'config'"
					class="terminal-btn terminal-btn-primary"
				>
					Go to Configure
				</button>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import TerminalPhpstanResults from './TerminalPhpstanResults.vue';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'create-issue']);

const api = useOverlordApi();

const viewMode = ref('config');
const loadingConfig = ref(false);
const configError = ref(null);
const detectedConfig = ref({
	config_file: null,
	level: null,
	paths: [],
	memory_limit: null,
	phpstan_installed: false,
});
const overrideConfig = ref({
	level: null,
	paths: '',
	memory_limit: '',
	baseline_file: '',
	ignore_errors: false,
});
const starting = ref(false);
const currentScanId = ref(null);

async function loadConfig() {
	loadingConfig.value = true;
	configError.value = null;
	try {
		const response = await axios.get(api.phpstan.config());
		if (response.data && response.data.success) {
			detectedConfig.value = response.data.result;
		} else {
			configError.value = response.data?.error || 'Failed to load configuration';
		}
	} catch (error) {
		console.error('Failed to load Larastan config:', error);
		configError.value = error.response?.data?.error || 'Failed to load configuration';
	} finally {
		loadingConfig.value = false;
	}
}

async function startAnalysis() {
	if (!detectedConfig.value.phpstan_installed) {
		return;
	}

	starting.value = true;
	try {
		// Build config from overrides
		const config = {};
		
		// Level: use override if provided, otherwise use detected config level, otherwise default to 1
		if (overrideConfig.value.level !== null && overrideConfig.value.level !== '') {
			config.level = parseInt(overrideConfig.value.level);
		} else if (detectedConfig.value.level !== null && detectedConfig.value.level !== undefined) {
			config.level = parseInt(detectedConfig.value.level);
		} else {
			config.level = 1; // Default level
		}
		
		if (overrideConfig.value.paths && overrideConfig.value.paths.trim()) {
			config.paths = overrideConfig.value.paths.split(',').map(p => p.trim()).filter(p => p);
		}
		
		if (overrideConfig.value.memory_limit && overrideConfig.value.memory_limit.trim()) {
			config.memory_limit = overrideConfig.value.memory_limit.trim();
		}
		
		if (overrideConfig.value.baseline_file && overrideConfig.value.baseline_file.trim()) {
			config.baseline_file = overrideConfig.value.baseline_file.trim();
		}
		
		config.ignore_errors = overrideConfig.value.ignore_errors;

		const response = await axios.post(api.phpstan.start(), config);
		if (response.data && response.data.success) {
			currentScanId.value = response.data.result.scan_id;
			viewMode.value = 'results';
		} else {
			configError.value = response.data?.error || 'Failed to start analysis';
		}
	} catch (error) {
		console.error('Failed to start Larastan analysis:', error);
		configError.value = error.response?.data?.error || 'Failed to start analysis';
	} finally {
		starting.value = false;
	}
}

function handleCreateIssue(issueData) {
	emit('create-issue', issueData);
}

onMounted(() => {
	if (props.visible) {
		loadConfig();
	}
});
</script>

<style scoped>
.terminal-phpstan {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #d4d4d4);
	z-index: 10002;
	pointer-events: auto;
}

.terminal-phpstan-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 1rem;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	background: var(--terminal-bg-secondary, #252526);
	position: relative;
	z-index: 10;
}

.phpstan-header-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.phpstan-view-tabs {
	display: flex;
	gap: 0.5rem;
}

.phpstan-view-tab {
	padding: 0.5rem 1rem;
	background: transparent;
	border: 1px solid transparent;
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
	font-size: 0.9rem;
}

.phpstan-view-tab:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
}

.phpstan-view-tab.active {
	background: var(--terminal-primary, #0e639c);
	color: #fff;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-phpstan-config-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.config-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.config-error {
	padding: 1rem;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-error, #f48771);
	border-radius: 4px;
}

.error-message {
	color: var(--terminal-error, #f48771);
	margin: 0;
}

.config-panel {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.config-section {
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	padding: 1.5rem;
}

.config-section h3 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--terminal-text, #d4d4d4);
	font-size: 1.1rem;
}

.config-info {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.config-info-item {
	display: flex;
	gap: 0.5rem;
}

.config-label {
	font-weight: 600;
	color: var(--terminal-text-secondary, #858585);
	min-width: 120px;
}

.config-value {
	color: var(--terminal-text, #d4d4d4);
	font-family: 'Courier New', monospace;
}

.config-warning {
	padding: 0.75rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-left: 3px solid var(--terminal-warning, #dcdcaa);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
}

.config-error-section {
	border-color: var(--terminal-error, #f48771);
}

.install-command {
	display: block;
	padding: 0.5rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	margin-top: 0.5rem;
	font-family: 'Courier New', monospace;
	color: var(--terminal-text, #d4d4d4);
}

.config-field {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.config-field:last-child {
	margin-bottom: 0;
}

.config-field label {
	color: var(--terminal-text, #d4d4d4);
	font-weight: 500;
	font-size: 0.9rem;
}

.config-field-help {
	font-size: 0.85rem;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-input {
	padding: 0.5rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-family: 'Courier New', monospace;
	font-size: 0.9rem;
}

.terminal-input:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
}

.config-checkbox-label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
}

.terminal-checkbox {
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.phpstan-config-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	padding-top: 1rem;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

/* Button Styles */
.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	font-weight: 500;
	transition: all 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	min-height: 32px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	text-decoration: none;
	white-space: nowrap;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
	border: 1px solid var(--terminal-primary, #0e639c);
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-border-hover, #464647);
	border-color: var(--terminal-border-hover, #464647);
}

.terminal-btn-secondary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-close {
	background: transparent !important;
	color: var(--terminal-text-secondary, #858585) !important;
	padding: 4px 8px !important;
	border: 1px solid transparent !important;
	min-width: auto !important;
}

.terminal-btn-close:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #2d2d30) !important;
	color: var(--terminal-text, #d4d4d4) !important;
	border-color: var(--terminal-border, #3e3e42) !important;
}

.terminal-btn-close svg {
	width: 16px;
	height: 16px;
}

.terminal-btn-sm {
	padding: 4px 10px;
	font-size: 11px;
	min-height: 28px;
}

.spinner {
	display: inline-block;
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border, #3e3e42);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

.spinner-small {
	display: inline-block;
	width: 12px;
	height: 12px;
	border: 2px solid rgba(255, 255, 255, 0.3);
	border-top-color: #fff;
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
	margin-right: 0.5rem;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.terminal-phpstan-results-placeholder {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-phpstan-results-placeholder .phpstan-no-scan {
	text-align: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 1rem;
}
</style>

