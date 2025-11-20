<template>
	<div v-if="visible" class="terminal-scan-config">
		<div class="terminal-scan-config-header">
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
		<div v-if="viewMode === 'config'" class="terminal-scan-config-content">
			<!-- Scan Mode Selection -->
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
							<div class="scan-mode-title">Scan Entire Codebase</div>
							<div class="scan-mode-description">Scan all PHP files in the app directory</div>
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
							<div class="scan-mode-title">Select Files/Folders</div>
							<div class="scan-mode-description">Choose specific files or folders to scan</div>
						</div>
					</label>
				</div>
			</div>

			<!-- File/Folder Selection (when selective mode) -->
			<div v-if="scanMode === 'selective'" class="file-selection-panel">
				<div class="file-selection-header">
					<h3>Select Files and Folders</h3>
					<div class="file-selection-actions">
						<button
							@click="expandAll"
							class="terminal-btn terminal-btn-secondary"
						>
							Expand All
						</button>
						<button
							@click="collapseAll"
							class="terminal-btn terminal-btn-secondary"
						>
							Collapse All
						</button>
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

				<div class="file-selection-content">
					<div v-if="loadingFiles" class="file-selection-loading">
						<span class="spinner"></span>
						Loading file structure...
					</div>
					<div v-else-if="fileTree" class="file-tree">
						<FileTreeNode
							v-for="node in fileTree"
							:key="node.path"
							:node="node"
							:selected-paths="selectedPaths"
							:expanded-paths="expandedPaths"
							@toggle="toggleSelection"
							@expand="toggleExpand"
						/>
					</div>
					<div v-else class="file-selection-error">
						<p>Failed to load file structure</p>
					</div>
				</div>

				<div v-if="selectedPaths.length > 0" class="selected-summary">
					<strong>{{ selectedPaths.length }}</strong> item{{ selectedPaths.length !== 1 ? 's' : '' }} selected
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
					:disabled="scanMode === 'selective' && selectedPaths.length === 0"
				>
					Start Scan
				</button>
			</div>
		</div>

		<!-- History View -->
		<TerminalScanHistory
			v-if="viewMode === 'history'"
			:visible="true"
			@close="emit('close')"
			@view-scan="handleViewScan"
			@view-issues="handleViewScanIssues"
		/>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import FileTreeNode from './FileTreeNode.vue';
import TerminalScanHistory from './TerminalScanHistory.vue';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'start-scan', 'view-scan', 'view-issues']);

const api = useOverlordApi();

const viewMode = ref('config'); // 'config' or 'history'
const scanMode = ref('full'); // 'full' or 'selective'
const fileTree = ref(null);
const loadingFiles = ref(false);
const selectedPaths = ref([]);
const expandedPaths = ref(new Set());

function handleViewScan(scanId) {
	emit('view-scan', scanId);
}

function handleViewScanIssues(scanId) {
	emit('view-issues', scanId);
}

function onModeChange() {
	if (scanMode.value === 'selective' && !fileTree.value) {
		loadFileTree();
	}
}

async function loadFileTree() {
	loadingFiles.value = true;
	try {
		const response = await axios.get(api.scan.fileTree());
		if (response.data && response.data.success) {
			fileTree.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load file tree:', error);
	} finally {
		loadingFiles.value = false;
	}
}

function toggleSelection(path) {
	const index = selectedPaths.value.indexOf(path);
	if (index > -1) {
		selectedPaths.value.splice(index, 1);
	} else {
		// Create a new array to trigger reactivity
		selectedPaths.value = [...selectedPaths.value, path];
	}
}

function toggleExpand(path) {
	if (expandedPaths.value.has(path)) {
		expandedPaths.value.delete(path);
	} else {
		expandedPaths.value.add(path);
	}
}

function expandAll() {
	// Recursively collect all paths
	const allPaths = [];
	function collectPaths(nodes) {
		nodes.forEach(node => {
			if (node.type === 'directory') {
				allPaths.push(node.path);
				if (node.children) {
					collectPaths(node.children);
				}
			}
		});
	}
	if (fileTree.value) {
		collectPaths(fileTree.value);
		allPaths.forEach(path => expandedPaths.value.add(path));
	}
}

function collapseAll() {
	expandedPaths.value.clear();
}

function selectAll() {
	const allPaths = [];
	function collectPaths(nodes) {
		nodes.forEach(node => {
			allPaths.push(node.path);
			if (node.children && expandedPaths.value.has(node.path)) {
				collectPaths(node.children);
			}
		});
	}
	if (fileTree.value) {
		collectPaths(fileTree.value);
		selectedPaths.value = [...new Set([...selectedPaths.value, ...allPaths])];
	}
}

function deselectAll() {
	selectedPaths.value = [];
}

function startScan() {
	const config = {
		mode: scanMode.value,
	};
	
	if (scanMode.value === 'selective') {
		config.paths = selectedPaths.value;
	}
	
	emit('start-scan', config);
}
</script>

<style scoped>
.terminal-scan-config {
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

.terminal-scan-config-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--terminal-border, #e5e5e5);
	gap: 1rem;
}

.scan-view-tabs {
	display: flex;
	gap: 0.5rem;
}

.scan-view-tab {
	padding: 0.5rem 1rem;
	background: transparent;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.875rem;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.scan-view-tab:hover {
	background: var(--terminal-bg-secondary, #f5f5f5);
	color: var(--terminal-text, #333333);
}

.scan-view-tab.active {
	background: var(--terminal-primary, #0e639c);
	color: white;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-scan-config-header h2 {
	margin: 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.terminal-scan-config-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-scan-config-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-scan-config-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-scan-config-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-scan-config-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.scan-mode-selection {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	margin-bottom: 2rem;
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
	background: var(--terminal-bg-tertiary, #f0f0f0);
	border: 2px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	cursor: pointer;
	transition: all 0.2s;
	position: relative;
	z-index: 1;
	pointer-events: auto;
}

.scan-mode-option input[type="radio"]:checked + .scan-mode-label {
	border-color: var(--terminal-primary, #0e639c);
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-tertiary, #f0f0f0));
}

.scan-mode-label:hover {
	border-color: var(--terminal-border-hover, #d0d0d0);
	background: var(--terminal-bg-secondary, #f5f5f5);
}

.scan-mode-icon {
	width: 48px;
	height: 48px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, var(--terminal-bg-tertiary, #f0f0f0));
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
	color: var(--terminal-text, #333333);
	margin-bottom: 0.25rem;
}

.scan-mode-description {
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.file-selection-panel {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	padding: 1rem;
	margin-bottom: 2rem;
}

.file-selection-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.file-selection-header h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.file-selection-actions {
	display: flex;
	gap: 0.5rem;
}

.file-selection-content {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	padding: 0.5rem;
	background: var(--terminal-bg, #1e1e1e);
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.file-selection-content::-webkit-scrollbar {
	width: 10px;
}

.file-selection-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.file-selection-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.file-selection-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.file-selection-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.file-selection-error {
	padding: 2rem;
	text-align: center;
	color: var(--terminal-error, #f48771);
}

.selected-summary {
	padding: 0.75rem;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-secondary, #f5f5f5));
	border-radius: 4px;
	margin-top: 1rem;
	color: var(--terminal-primary, #0e639c);
	font-size: 0.875rem;
}

.scan-config-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	padding-top: 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
}

.file-tree {
	display: flex;
	flex-direction: column;
}

.spinner {
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border, #e5e5e5);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Terminal Button Styles - matching global styles */
.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	font-weight: 500;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	min-height: 32px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	color: var(--terminal-text, #333333);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary, #858585);
	padding: 4px;
	border: none;
	min-width: auto;
}

.terminal-btn-close:hover {
	background: var(--terminal-bg-secondary, #f5f5f5);
	color: var(--terminal-text, #333333);
}

.terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}
</style>

