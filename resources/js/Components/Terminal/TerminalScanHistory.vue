<template>
	<div v-if="visible" class="terminal-scan-history">
		<div class="terminal-scan-history-header">
			<h2>Scan History</h2>
			<button @click="emit('close')" class="terminal-btn terminal-btn-close" title="Close">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<div class="terminal-scan-history-content">
			<!-- Filters -->
			<div class="scan-history-filters">
				<select v-model="statusFilter" @change="loadHistory" class="terminal-select">
					<option value="">All Statuses</option>
					<option value="completed">Completed</option>
					<option value="failed">Failed</option>
					<option value="scanning">Scanning</option>
				</select>
			</div>

			<!-- Loading State -->
			<div v-if="loading" class="scan-history-loading">
				<span class="spinner"></span>
				Loading scan history...
			</div>

			<!-- Error State -->
			<div v-else-if="error" class="scan-history-error">
				<p>{{ error }}</p>
			</div>

			<!-- Scan List -->
			<div v-else-if="scans.length > 0" class="scan-history-list">
				<div
					v-for="scan in scans"
					:key="scan.id"
					class="scan-history-item"
					:class="{ 'active': selectedScanId === scan.scan_id }"
					@click="selectScan(scan.scan_id)"
				>
					<div class="scan-history-item-header">
						<div class="scan-history-item-main">
							<div class="scan-history-item-title">
								<span class="scan-id">{{ scan.scan_id }}</span>
								<span class="scan-status" :class="'status-' + scan.status">
									{{ scan.status }}
								</span>
							</div>
							<div class="scan-history-item-meta">
								<span class="scan-date">{{ formatDate(scan.created_at) }}</span>
								<span v-if="scan.scan_mode === 'selective'" class="scan-mode-badge">
									Selective
								</span>
							</div>
						</div>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="expand-icon">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
						</svg>
					</div>
					
					<div v-if="selectedScanId === scan.scan_id" class="scan-history-item-details">
						<div class="scan-details-grid">
							<div class="scan-detail-item">
								<span class="detail-label">Files Scanned:</span>
								<span class="detail-value">{{ scan.total_files }}</span>
							</div>
							<div class="scan-detail-item">
								<span class="detail-label">Issues Found:</span>
								<span class="detail-value">{{ scan.total_issues_found }}</span>
							</div>
							<div class="scan-detail-item">
								<span class="detail-label">Issues Saved:</span>
								<span class="detail-value">{{ scan.issues_saved }}</span>
							</div>
							<div class="scan-detail-item">
								<span class="detail-label">Duration:</span>
								<span class="detail-value">{{ formatDuration(scan.started_at, scan.completed_at) }}</span>
							</div>
						</div>
						
						<div v-if="scan.error" class="scan-error">
							<strong>Error:</strong> {{ scan.error }}
						</div>
						
						<div class="scan-actions">
							<button
								@click.stop="viewScanResults(scan.scan_id)"
								class="terminal-btn terminal-btn-primary"
							>
								View Results
							</button>
							<button
								@click.stop="viewScanIssues(scan.scan_id)"
								class="terminal-btn terminal-btn-secondary"
							>
								View Issues ({{ scan.total_issues_found || 0 }})
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Empty State -->
			<div v-else class="scan-history-empty">
				<p>No scan history found.</p>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'view-scan', 'view-issues']);

const api = useOverlordApi();

const loading = ref(false);
const error = ref(null);
const scans = ref([]);
const selectedScanId = ref(null);
const statusFilter = ref('');

async function loadHistory() {
	loading.value = true;
	error.value = null;
	
	try {
		const params = {};
		if (statusFilter.value) {
			params.status = statusFilter.value;
		}
		
		const response = await axios.get(api.scan.history(params));
		if (response.data && response.data.success) {
			scans.value = response.data.result || [];
		} else {
			error.value = response.data.error || 'Failed to load scan history';
		}
	} catch (err) {
		console.error('Failed to load scan history:', err);
		error.value = err.response?.data?.error || 'Failed to load scan history';
	} finally {
		loading.value = false;
	}
}

function selectScan(scanId) {
	if (selectedScanId.value === scanId) {
		selectedScanId.value = null;
	} else {
		selectedScanId.value = scanId;
	}
}

function viewScanResults(scanId) {
	emit('view-scan', scanId);
}

function viewScanIssues(scanId) {
	emit('view-issues', scanId);
}

function formatDate(dateString) {
	if (!dateString) return 'N/A';
	const date = new Date(dateString);
	return date.toLocaleString();
}

function formatDuration(startedAt, completedAt) {
	if (!startedAt || !completedAt) return 'N/A';
	const start = new Date(startedAt);
	const end = new Date(completedAt);
	const diff = Math.floor((end - start) / 1000); // seconds
	
	if (diff < 60) {
		return `${diff}s`;
	} else if (diff < 3600) {
		return `${Math.floor(diff / 60)}m ${diff % 60}s`;
	} else {
		const hours = Math.floor(diff / 3600);
		const minutes = Math.floor((diff % 3600) / 60);
		return `${hours}h ${minutes}m`;
	}
}

onMounted(() => {
	if (props.visible) {
		loadHistory();
	}
});

// Watch for visibility changes
watch(() => props.visible, (newVal) => {
	if (newVal) {
		loadHistory();
	}
});

// Expose loadHistory so parent can call it to refresh
defineExpose({
	loadHistory,
});
</script>

<style scoped>
.terminal-scan-history {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #333333);
	z-index: 10002;
	pointer-events: auto;
}

.terminal-scan-history-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--terminal-border, #e5e5e5);
}

.terminal-scan-history-header h2 {
	margin: 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.terminal-scan-history-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-scan-history-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-scan-history-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-scan-history-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-scan-history-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.scan-history-filters {
	margin-bottom: 1.5rem;
}

.terminal-select {
	padding: 0.5rem 0.75rem;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text, #333333);
	font-size: 0.875rem;
	cursor: pointer;
}

.terminal-select:focus {
	border-color: var(--terminal-primary, #0e639c);
	outline: none;
}

.scan-history-loading,
.scan-history-error,
.scan-history-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 3rem;
	color: var(--terminal-text-secondary, #858585);
	text-align: center;
}

.scan-history-error {
	color: var(--terminal-error, #ef4444);
}

.scan-history-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.scan-history-item {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 6px;
	padding: 1rem;
	cursor: pointer;
	transition: all 0.2s;
}

.scan-history-item:hover {
	background: var(--terminal-bg-tertiary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
}

.scan-history-item.active {
	border-color: var(--terminal-primary, #0e639c);
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-secondary, #f5f5f5));
}

.scan-history-item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.scan-history-item-main {
	flex: 1;
}

.scan-history-item-title {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
}

.scan-id {
	font-family: monospace;
	font-size: 0.875rem;
	color: var(--terminal-text, #333333);
}

.scan-status {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.scan-status.status-completed {
	background: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	color: var(--terminal-success, #10b981);
}

.scan-status.status-failed {
	background: color-mix(in srgb, var(--terminal-error, #ef4444) 20%, transparent);
	color: var(--terminal-error, #ef4444);
}

.scan-status.status-scanning {
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent);
	color: var(--terminal-primary, #0e639c);
}

.scan-history-item-meta {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	font-size: 0.75rem;
	color: var(--terminal-text-secondary, #858585);
}

.scan-mode-badge {
	padding: 0.125rem 0.375rem;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent);
	color: var(--terminal-primary, #0e639c);
	border-radius: 3px;
	font-size: 0.7rem;
}

.expand-icon {
	width: 16px;
	height: 16px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
}

.scan-history-item.active .expand-icon {
	transform: rotate(90deg);
}

.scan-history-item-details {
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
}

.scan-details-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 0.75rem;
	margin-bottom: 1rem;
}

.scan-detail-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.detail-label {
	font-size: 0.75rem;
	color: var(--terminal-text-secondary, #858585);
}

.detail-value {
	font-size: 0.875rem;
	color: var(--terminal-text, #333333);
	font-weight: 600;
}

.scan-error {
	padding: 0.75rem;
	background: color-mix(in srgb, var(--terminal-error, #ef4444) 10%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-error, #ef4444) 30%, transparent);
	border-radius: 4px;
	color: var(--terminal-error, #ef4444);
	font-size: 0.875rem;
	margin-bottom: 1rem;
}

.scan-actions {
	display: flex;
	gap: 0.5rem;
	margin-top: 1rem;
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
	color: #ffffff;
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

