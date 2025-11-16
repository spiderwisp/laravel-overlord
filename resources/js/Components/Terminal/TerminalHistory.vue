<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['use-command', 'close']);

const commandLogs = ref([]);
const historyLoading = ref(false);
const historyPage = ref(1);
const historyPagination = ref(null);
const historyFilterSuccess = ref(null);

// Format bytes for display
function formatBytes(bytes) {
	if (bytes === null || bytes === 0) return '0 B';
	const k = 1024;
	const sizes = ['B', 'KB', 'MB', 'GB'];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Load command history
async function loadHistory(page = 1) {
	historyLoading.value = true;
	historyPage.value = page;
	
	try {
		const params = {
			page: page,
			per_page: 20,
		};
		
		if (historyFilterSuccess.value !== null) {
			params.success = historyFilterSuccess.value;
		}
		
		const response = await axios.get(api.url('history'), { params });
		
		if (response.data.success) {
			commandLogs.value = response.data.result?.logs || [];
			historyPagination.value = response.data.result?.pagination || null;
		} else {
			console.error('History API returned success=false:', response.data);
			commandLogs.value = [];
		}
	} catch (error) {
		console.error('Failed to load history:', error);
		commandLogs.value = [];
	} finally {
		historyLoading.value = false;
	}
}

// Filter history by success/failure
function filterHistory(success) {
	historyFilterSuccess.value = success;
	historyPage.value = 1;
	loadHistory(1);
}

// Use command from history
function useCommandFromHistory(log) {
	emit('use-command', log);
}

// Watch for visibility changes to load history
watch(() => props.visible, (visible) => {
	if (visible) {
		loadHistory();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-history-view">
		<div class="terminal-history-header">
			<div class="terminal-history-filters">
				<button
					@click="filterHistory(null)"
					:class="['terminal-history-filter-btn', { 'active': historyFilterSuccess === null }]"
				>
					All
				</button>
				<button
					@click="filterHistory(true)"
					:class="['terminal-history-filter-btn', { 'active': historyFilterSuccess === true }]"
				>
					Success
				</button>
				<button
					@click="filterHistory(false)"
					:class="['terminal-history-filter-btn', { 'active': historyFilterSuccess === false }]"
				>
					Failed
				</button>
			</div>
		</div>
		
		<div class="terminal-history-content">
			<div v-if="historyLoading" class="terminal-history-loading">
				<span class="spinner"></span>
				Loading history...
			</div>
			
			<div v-else-if="commandLogs.length === 0" class="terminal-history-empty">
				<p>No command history found.</p>
				<p class="terminal-history-empty-hint">Commands you execute will be logged here.</p>
			</div>
			
			<div v-else class="terminal-history-list">
				<div
					v-for="log in commandLogs"
					:key="log.id"
					class="terminal-history-item"
					:class="{ 'terminal-history-item-failed': !log.success }"
					@click="useCommandFromHistory(log)"
				>
					<div class="terminal-history-item-header">
						<div class="terminal-history-item-header-left">
							<span class="terminal-history-item-time">
								{{ new Date(log.created_at).toLocaleString() }}
							</span>
							<span v-if="log.user" class="terminal-history-item-user">
								• {{ log.user.email }}
							</span>
						</div>
						<span
							class="terminal-history-item-badge"
							:class="log.success ? 'terminal-history-item-success' : 'terminal-history-item-error'"
						>
							{{ log.success ? 'Success' : 'Failed' }}
						</span>
					</div>
					<div class="terminal-history-item-command">
						{{ log.command.length > 100 ? log.command.substring(0, 100) + '...' : log.command }}
					</div>
					<div v-if="log.execution_time !== null" class="terminal-history-item-meta">
						<span>{{ parseFloat(log.execution_time).toFixed(2) }}ms</span>
						<span v-if="log.memory_usage !== null">
							• {{ formatBytes(log.memory_usage) }}
						</span>
					</div>
				</div>
			</div>
		</div>
		
		<div v-if="historyPagination && historyPagination.last_page > 1" class="terminal-history-pagination">
			<button
				@click="loadHistory(historyPagination.current_page - 1)"
				:disabled="historyPagination.current_page === 1"
				class="terminal-history-pagination-btn"
			>
				Previous
			</button>
			<span class="terminal-history-pagination-info">
				Page {{ historyPagination.current_page }} of {{ historyPagination.last_page }}
			</span>
			<button
				@click="loadHistory(historyPagination.current_page + 1)"
				:disabled="historyPagination.current_page === historyPagination.last_page"
				class="terminal-history-pagination-btn"
			>
				Next
			</button>
		</div>
	</div>
</template>

<style scoped>
/* History View */
.terminal-history-view {
	flex: 1;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg);
	overflow: hidden;
}

.terminal-history-header {
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-history-filters {
	display: flex;
	gap: 8px;
}

.terminal-history-filter-btn {
	padding: 6px 12px;
	background: var(--terminal-border);
	color: var(--terminal-text);
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	transition: all 0.2s;
}

.terminal-history-filter-btn:hover {
	background: var(--terminal-border-hover);
}

.terminal-history-filter-btn.active {
	background: #0e639c;
	color: white;
}

.terminal-history-content {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.terminal-history-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	color: var(--terminal-text-secondary);
	padding: 40px;
}

.terminal-history-empty {
	text-align: center;
	color: var(--terminal-text-secondary);
	padding: 40px;
}

.terminal-history-empty-hint {
	font-size: 11px;
	color: var(--terminal-text-muted);
	margin-top: 8px;
}

.terminal-history-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-history-item {
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-history-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
}

.terminal-history-item-failed {
	border-left: 3px solid var(--terminal-error);
}

.terminal-history-item-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.terminal-history-item-header-left {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-history-item-user {
	font-size: 11px;
	color: var(--terminal-text-secondary);
}

.terminal-history-item-time {
	font-size: 11px;
	color: var(--terminal-text-secondary);
}

.terminal-history-item-badge {
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 10px;
	font-weight: 600;
}

.terminal-history-item-success {
	background: color-mix(in srgb, var(--terminal-success) 20%, var(--terminal-bg));
	color: var(--terminal-accent);
}

.terminal-history-item-error {
	background: color-mix(in srgb, var(--terminal-error) 20%, var(--terminal-bg));
	color: var(--terminal-error);
}

.terminal-history-item-command {
	color: var(--terminal-text);
	font-size: 13px;
	margin-bottom: 6px;
	font-family: 'Courier New', monospace;
	word-break: break-all;
}

.terminal-history-item-meta {
	display: flex;
	gap: 8px;
	font-size: 11px;
	color: var(--terminal-text-secondary);
}

.terminal-history-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-top: 1px solid var(--terminal-border);
}

.terminal-history-pagination-info {
	color: var(--terminal-text);
	font-size: 12px;
}

.terminal-history-pagination-btn {
	padding: 6px 12px;
	background: var(--terminal-border);
	color: var(--terminal-text);
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	transition: all 0.2s;
}

.terminal-history-pagination-btn:hover:not(:disabled) {
	background: var(--terminal-border-hover);
}

.terminal-history-pagination-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.spinner {
	width: 12px;
	height: 12px;
	border: 2px solid #3e3e42;
	border-top-color: var(--terminal-accent);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Custom Scrollbar Styling */
.terminal-history-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-history-content::-webkit-scrollbar-track {
	background: var(--terminal-bg);
}

.terminal-history-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border-hover);
	border-radius: 5px;
}

.terminal-history-content::-webkit-scrollbar-thumb:hover {
	background: #4a4a4a;
}

/* Firefox scrollbar styling */
.terminal-history-content {
	scrollbar-width: thin;
	scrollbar-color: #424242 #1e1e1e;
}
</style>

