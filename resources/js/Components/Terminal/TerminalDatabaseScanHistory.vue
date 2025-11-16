<template>
	<div v-if="visible" class="terminal-database-scan-history">
		<div class="terminal-scan-history-header">
			<h2>Database Scan History</h2>
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
				<select v-model="typeFilter" @change="loadHistory" class="terminal-select">
					<option value="">All Types</option>
					<option value="schema">Schema</option>
					<option value="data">Data</option>
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
								<span class="scan-type-badge" :class="'type-' + scan.scan_type">
									{{ scan.scan_type }}
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
								<span class="detail-label">Tables Scanned:</span>
								<span class="detail-value">{{ scan.total_tables }}</span>
							</div>
							<div class="scan-detail-item">
								<span class="detail-label">Issues Found:</span>
								<span class="detail-value">{{ scan.total_issues_found }}</span>
							</div>
							<div class="scan-detail-item">
								<span class="detail-label">Issues Saved:</span>
								<span class="detail-value">{{ scan.issues_saved }}</span>
							</div>
							<div v-if="scan.scan_type === 'data'" class="scan-detail-item">
								<span class="detail-label">Sample Size:</span>
								<span class="detail-value">{{ scan.sample_size || 'N/A' }}</span>
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
				<p>No database scan history found.</p>
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
const typeFilter = ref('');

async function loadHistory() {
	loading.value = true;
	error.value = null;
	
	try {
		const params = {};
		if (statusFilter.value) {
			params.status = statusFilter.value;
		}
		if (typeFilter.value) {
			params.type = typeFilter.value;
		}
		
		const response = await axios.get(api.databaseScan.history(params));
		if (response.data && response.data.success) {
			scans.value = response.data.result || [];
		} else {
			error.value = response.data.error || 'Failed to load scan history';
		}
	} catch (err) {
		console.error('Failed to load database scan history:', err);
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

watch(() => props.visible, (visible) => {
	if (visible) {
		loadHistory();
	}
});

// Expose loadHistory so parent can call it to refresh
defineExpose({
	loadHistory,
});
</script>

<style scoped>
.terminal-database-scan-history {
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

.terminal-scan-history-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-scan-history-header h2 {
	margin: 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-scan-history-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}

.terminal-scan-history-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-scan-history-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-scan-history-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #1e1e1e);
}

.terminal-scan-history-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.scan-history-filters {
	display: flex;
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.terminal-select {
	padding: 0.5rem 1rem;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-size: 0.875rem;
	cursor: pointer;
}

.terminal-select:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
}

.scan-history-loading,
.scan-history-error,
.scan-history-empty {
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

.scan-history-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.scan-history-item {
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	overflow: hidden;
	cursor: pointer;
	transition: all 0.2s;
}

.scan-history-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
}

.scan-history-item.active {
	border-color: var(--terminal-primary, #0e639c);
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, var(--terminal-bg-secondary, #252526));
}

.scan-history-item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
}

.scan-history-item-main {
	flex: 1;
}

.scan-history-item-title {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
	flex-wrap: wrap;
}

.scan-id {
	font-family: 'Courier New', monospace;
	color: var(--terminal-text, #d4d4d4);
	font-size: 0.875rem;
}

.scan-status {
	padding: 0.25rem 0.75rem;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.scan-status.status-completed {
	background: rgba(76, 175, 80, 0.2);
	color: #4caf50;
}

.scan-status.status-failed {
	background: rgba(244, 67, 54, 0.2);
	color: #f44336;
}

.scan-status.status-scanning {
	background: rgba(255, 193, 7, 0.2);
	color: #ffc107;
}

.scan-type-badge {
	padding: 0.25rem 0.75rem;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.scan-type-badge.type-schema {
	background: rgba(14, 99, 156, 0.2);
	color: #0e639c;
}

.scan-type-badge.type-data {
	background: rgba(156, 39, 176, 0.2);
	color: #9c27b0;
}

.scan-history-item-meta {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.scan-mode-badge {
	padding: 0.25rem 0.5rem;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 4px;
	font-size: 0.75rem;
}

.expand-icon {
	width: 20px;
	height: 20px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
}

.scan-history-item.active .expand-icon {
	transform: rotate(90deg);
}

.scan-history-item-details {
	padding: 1rem 1.5rem;
	border-top: 1px solid var(--terminal-border, #3e3e42);
	background: var(--terminal-bg, #1e1e1e);
}

.scan-details-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
	margin-bottom: 1rem;
}

.scan-detail-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.detail-label {
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.detail-value {
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.scan-error {
	padding: 0.75rem;
	background: rgba(244, 67, 54, 0.1);
	border-left: 3px solid #f44336;
	border-radius: 4px;
	margin-bottom: 1rem;
	color: #f44336;
	font-size: 0.875rem;
}

.scan-actions {
	display: flex;
	gap: 0.75rem;
	margin-top: 1rem;
}

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

