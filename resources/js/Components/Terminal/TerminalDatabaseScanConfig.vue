<template>
	<div v-if="visible" class="terminal-database-scan-config">
		<div class="terminal-database-scan-config-header">
			<div class="scan-view-tabs">
				<button
					@click="viewMode = 'config'"
					class="scan-view-tab"
					:class="{ active: viewMode === 'config' }"
				>
					Configure
				</button>
				<button
					@click="viewMode = 'history'"
					class="scan-view-tab"
					:class="{ active: viewMode === 'history' }"
				>
					History
				</button>
			</div>
			<button @click="emit('close')" class="terminal-btn terminal-btn-close" title="Close">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<!-- Configuration View -->
		<div v-if="viewMode === 'config'" class="terminal-database-scan-config-content">
			<!-- Scan Type Selection -->
			<div class="scan-type-selection">
				<h3>Scan Type</h3>
				<div class="scan-mode-selection">
					<div class="scan-mode-option" @click="scanType = 'schema'; onTypeChange()">
						<input
							type="radio"
							id="scan-type-schema"
							v-model="scanType"
							value="schema"
							@change="onTypeChange"
						/>
						<label for="scan-type-schema" class="scan-mode-label">
							<div class="scan-mode-icon">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
								</svg>
							</div>
							<div class="scan-mode-content">
								<div class="scan-mode-title">Scan Schema</div>
								<div class="scan-mode-description">Analyze database structure, indexes, foreign keys, and relationships</div>
							</div>
						</label>
					</div>

					<div class="scan-mode-option" @click="scanType = 'data'; onTypeChange()">
						<input
							type="radio"
							id="scan-type-data"
							v-model="scanType"
							value="data"
							@change="onTypeChange"
						/>
						<label for="scan-type-data" class="scan-mode-label">
							<div class="scan-mode-icon">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
								</svg>
							</div>
							<div class="scan-mode-content">
								<div class="scan-mode-title">Scan Data</div>
								<div class="scan-mode-description">Analyze data samples for inconsistencies and validation issues</div>
							</div>
						</label>
					</div>
				</div>
			</div>

			<!-- Scan Mode Selection -->
			<div class="scan-mode-selection-section">
				<h3>Tables to Scan</h3>
				<div class="scan-mode-selection">
					<div class="scan-mode-option" @click="scanMode = 'full'; onModeChange()">
						<input
							type="radio"
							id="scan-mode-full"
							v-model="scanMode"
							value="full"
							@change="onModeChange"
						/>
						<label for="scan-mode-full" class="scan-mode-label">
							<div class="scan-mode-icon">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
								</svg>
							</div>
							<div class="scan-mode-content">
								<div class="scan-mode-title">Scan All Tables</div>
								<div class="scan-mode-description">Scan all tables in the database</div>
							</div>
						</label>
					</div>

					<div class="scan-mode-option" @click="scanMode = 'selective'; onModeChange()">
						<input
							type="radio"
							id="scan-mode-selective"
							v-model="scanMode"
							value="selective"
							@change="onModeChange"
						/>
						<label for="scan-mode-selective" class="scan-mode-label">
							<div class="scan-mode-icon">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
							</div>
							<div class="scan-mode-content">
								<div class="scan-mode-title">Select Specific Tables</div>
								<div class="scan-mode-description">Choose specific tables to scan</div>
							</div>
						</label>
					</div>
				</div>
			</div>

			<!-- Table Selection (when selective mode) -->
			<div v-if="scanMode === 'selective'" class="table-selection-panel">
				<div class="table-selection-header">
					<h3>Select Tables</h3>
					<div class="table-selection-actions">
						<button
							@click="selectAll"
							class="terminal-btn terminal-btn-secondary"
						>
							Select All
						</button>
						<button
							@click="deselectAll"
							class="terminal-btn terminal-btn-secondary"
						>
							Deselect All
						</button>
					</div>
				</div>

				<div class="table-selection-content">
					<div v-if="loadingTables" class="table-selection-loading">
						<span class="spinner"></span>
						Loading tables...
					</div>
					<div v-else-if="tables && tables.length > 0" class="table-list">
						<label
							v-for="table in tables"
							:key="table"
							class="table-item"
						>
							<input
								type="checkbox"
								:value="table"
								v-model="selectedTables"
							/>
							<span class="table-name">{{ table }}</span>
						</label>
					</div>
					<div v-else class="table-selection-error">
						<p>Failed to load tables</p>
					</div>
				</div>

				<div v-if="selectedTables.length > 0" class="selected-summary">
					<strong>{{ selectedTables.length }}</strong> table{{ selectedTables.length !== 1 ? 's' : '' }} selected
				</div>
			</div>

			<!-- Sample Size (for data scans) -->
			<div v-if="scanType === 'data'" class="sample-size-panel">
				<h3>Sample Size</h3>
				<div class="sample-size-controls">
					<label class="sample-size-label">
						Rows per table:
						<input
							type="number"
							v-model.number="sampleSize"
							min="1"
							max="10000"
							class="terminal-input"
							:disabled="overrideSampleSize"
						/>
					</label>
					<label class="override-checkbox">
						<input
							type="checkbox"
							v-model="overrideSampleSize"
						/>
						<span>Override default (use all data)</span>
					</label>
				</div>
				<div v-if="overrideSampleSize" class="sample-size-warning">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
					</svg>
					<div>
						<strong>Warning:</strong> Using all data may consume a large number of tokens and could result in high costs or API failures. Consider using a sample size instead.
					</div>
				</div>
			</div>

			<!-- Actions -->
			<div class="scan-config-actions">
				<button
					@click="emit('close')"
					class="terminal-btn terminal-btn-secondary"
				>
					Cancel
				</button>
				<button
					@click="startScan"
					class="terminal-btn terminal-btn-primary"
					:disabled="scanMode === 'selective' && selectedTables.length === 0"
				>
					Start Scan
				</button>
			</div>
		</div>

		<!-- History View -->
		<TerminalDatabaseScanHistory
			v-if="viewMode === 'history'"
			:visible="true"
			@close="emit('close')"
			@view-scan="handleViewDatabaseScan"
			@view-issues="handleViewDatabaseScanIssues"
		/>
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import TerminalDatabaseScanHistory from './TerminalDatabaseScanHistory.vue';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'start-scan', 'view-scan', 'view-issues']);

const api = useOverlordApi();

const viewMode = ref('config'); // 'config' or 'history'
const scanType = ref('schema'); // 'schema' or 'data'
const scanMode = ref('full'); // 'full' or 'selective'
const tables = ref([]);
const loadingTables = ref(false);
const selectedTables = ref([]);
const sampleSize = ref(100);
const overrideSampleSize = ref(false);

function handleViewDatabaseScan(scanId) {
	emit('view-scan', scanId);
}

function handleViewDatabaseScanIssues(scanId) {
	emit('view-issues', scanId);
}

function onTypeChange() {
	// Reset sample size override when switching types
	if (scanType.value === 'schema') {
		overrideSampleSize.value = false;
	}
}

function onModeChange() {
	if (scanMode.value === 'selective' && tables.value.length === 0) {
		loadTables();
	}
}

async function loadTables() {
	loadingTables.value = true;
	try {
		const response = await axios.get(api.databaseScan.tables());
		if (response.data && response.data.success) {
			tables.value = response.data.result || [];
		}
	} catch (error) {
		console.error('Failed to load tables:', error);
	} finally {
		loadingTables.value = false;
	}
}

function selectAll() {
	selectedTables.value = [...tables.value];
}

function deselectAll() {
	selectedTables.value = [];
}

function startScan() {
	const config = {
		type: scanType.value,
		mode: scanMode.value,
	};
	
	if (scanMode.value === 'selective') {
		config.tables = selectedTables.value;
	}
	
	if (scanType.value === 'data') {
		config.sample_size = overrideSampleSize.value ? null : sampleSize.value;
	}
	
	emit('start-scan', config);
}

onMounted(() => {
	if (props.visible && scanMode.value === 'selective') {
		loadTables();
	}
});
</script>

<style scoped>
.terminal-database-scan-config {
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

.terminal-database-scan-config-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	gap: 1rem;
}

.scan-view-tabs {
	display: flex;
	gap: 0.5rem;
}

.scan-view-tab {
	padding: 0.5rem 1rem;
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.875rem;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.scan-view-tab:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
}

.scan-view-tab.active {
	background: var(--terminal-primary, #0e639c);
	color: white;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-database-scan-config-header h2 {
	margin: 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-database-scan-config-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}

.terminal-database-scan-config-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-database-scan-config-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-database-scan-config-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #1e1e1e);
}

.terminal-database-scan-config-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.scan-type-selection,
.scan-mode-selection-section {
	margin-bottom: 2rem;
}

.scan-type-selection h3,
.scan-mode-selection-section h3 {
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	margin-bottom: 1rem;
}

.scan-mode-selection {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.scan-mode-option {
	position: relative;
	cursor: pointer;
}

.scan-mode-option input[type="radio"] {
	position: absolute;
	opacity: 0;
	pointer-events: none;
	z-index: -1;
}

.scan-mode-label {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s;
	position: relative;
	z-index: 1;
	pointer-events: auto;
}

.scan-mode-option input[type="radio"]:checked + .scan-mode-label {
	border-color: var(--terminal-primary, #0e639c);
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-tertiary, #2d2d30));
}

.scan-mode-label:hover {
	border-color: var(--terminal-border-hover, #4e4e52);
	background: var(--terminal-bg-secondary, #252526);
}

.scan-mode-icon {
	width: 48px;
	height: 48px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, var(--terminal-bg-tertiary, #2d2d30));
	border-radius: 8px;
	flex-shrink: 0;
}

.scan-mode-icon svg {
	width: 24px;
	height: 24px;
	color: var(--terminal-primary, #0e639c);
}

.scan-mode-content {
	flex: 1;
}

.scan-mode-title {
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	margin-bottom: 0.25rem;
}

.scan-mode-description {
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.table-selection-panel {
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	padding: 1rem;
	margin-bottom: 2rem;
}

.table-selection-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.table-selection-header h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.table-selection-actions {
	display: flex;
	gap: 0.5rem;
}

.table-selection-content {
	max-height: 300px;
	overflow-y: auto;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}

.table-selection-content::-webkit-scrollbar {
	width: 10px;
}

.table-selection-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.table-selection-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #1e1e1e);
}

.table-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.table-item {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 0.75rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 4px;
	cursor: pointer;
	transition: background 0.2s;
}

.table-item:hover {
	background: var(--terminal-bg-secondary, #252526);
}

.table-item input[type="checkbox"] {
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.table-name {
	color: var(--terminal-text, #d4d4d4);
	font-family: 'Courier New', monospace;
}

.table-selection-loading,
.table-selection-error {
	text-align: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.spinner {
	display: inline-block;
	width: 20px;
	height: 20px;
	border: 3px solid var(--terminal-border, #3e3e42);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
	margin-right: 0.5rem;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

.selected-summary {
	margin-top: 1rem;
	padding: 0.75rem;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-secondary, #252526));
	border-radius: 4px;
	color: var(--terminal-primary, #0e639c);
	font-size: 0.875rem;
}

.sample-size-panel {
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	padding: 1rem;
	margin-bottom: 2rem;
}

.sample-size-panel h3 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.sample-size-controls {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.sample-size-label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-input {
	width: 100px;
	padding: 0.5rem;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-size: 0.875rem;
}

.terminal-input:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.override-checkbox {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--terminal-text, #d4d4d4);
	cursor: pointer;
}

.override-checkbox input[type="checkbox"] {
	width: 18px;
	height: 18px;
	cursor: pointer;
}

.sample-size-warning {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 1rem;
	background: rgba(244, 67, 54, 0.1);
	border: 1px solid rgba(244, 67, 54, 0.3);
	border-radius: 4px;
	color: #f44336;
	font-size: 0.875rem;
	margin-top: 1rem;
}

.sample-size-warning svg {
	width: 20px;
	height: 20px;
	flex-shrink: 0;
	margin-top: 2px;
}

.scan-config-actions {
	display: flex;
	justify-content: flex-end;
	gap: 1rem;
	margin-top: 2rem;
	padding-top: 1.5rem;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

/* Button styles to match terminal theme */
.terminal-btn {
	padding: 0.5rem 1rem;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	cursor: pointer;
	font-size: 0.875rem;
	transition: all 0.2s;
}

.terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	border-color: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-secondary {
	background: var(--terminal-bg-secondary, #252526);
	border-color: var(--terminal-border, #3e3e42);
}

.terminal-btn-close {
	padding: 0.25rem;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-btn-close:hover {
	color: var(--terminal-text, #d4d4d4);
	background: var(--terminal-border, #3e3e42);
}
</style>

