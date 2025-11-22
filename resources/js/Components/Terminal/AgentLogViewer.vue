<template>
	<div class="agent-log-viewer">
		<div class="log-viewer-header">
			<h4>Activity Log</h4>
			<span v-if="logs.length > 0" class="log-count">{{ logs.length }} entries</span>
		</div>
		<div class="log-viewer-content" ref="logContainerRef" @scroll="handleScroll">
			<div v-if="loadingMore" class="loading-indicator">
				<span class="spinner-small"></span>
				<span>Loading older logs...</span>
			</div>
			<div v-if="logs.length === 0" class="no-logs">
				<p>No logs yet. Agent activity will appear here.</p>
			</div>
			<TransitionGroup v-else name="log-entry" tag="div" class="log-entries">
				<details
					v-for="log in logs"
					:key="`log-${log.id}`"
					class="log-entry"
					:class="`log-${log.type}`"
				>
					<summary class="log-summary">
						<div class="log-summary-content">
							<span class="log-type-badge" :class="`type-${log.type}`">
								{{ log.type }}
							</span>
							<span class="log-message-preview">{{ getLogPreview(log) }}</span>
							<span class="log-timestamp">{{ formatTimestamp(log.created_at) }}</span>
						</div>
						<svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
							<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
						</svg>
					</summary>
					<div class="log-details">
						<div class="log-message-full">
							{{ log.message }}
						</div>
						<div v-if="log.data && Object.keys(log.data).length > 0" class="log-data">
							<details class="log-data-details">
								<summary class="log-data-summary">Details</summary>
								<pre>{{ JSON.stringify(log.data, null, 2) }}</pre>
							</details>
						</div>
					</div>
				</details>
			</TransitionGroup>
		</div>
	</div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';

const props = defineProps({
	sessionId: {
		type: [String, Number],
		required: true,
	},
	logs: {
		type: Array,
		default: () => [],
	},
});

const emit = defineEmits(['load-more']);

const logContainerRef = ref(null);
const loadingMore = ref(false);
const isUserScrolling = ref(false);
const autoScrollEnabled = ref(true);
const lastScrollTop = ref(0);
const scrollTimeout = ref(null);

function formatTimestamp(timestamp) {
	if (!timestamp) return '';
	const date = new Date(timestamp);
	const now = new Date();
	const diff = now - date;
	
	// Show relative time for recent logs
	if (diff < 60000) { // Less than 1 minute
		return 'just now';
	} else if (diff < 3600000) { // Less than 1 hour
		const minutes = Math.floor(diff / 60000);
		return `${minutes}m ago`;
	} else {
		return date.toLocaleTimeString();
	}
}

function getLogPreview(log) {
	const message = log.message || '';
	// Truncate long messages
	if (message.length > 60) {
		return message.substring(0, 60) + '...';
	}
	return message;
}

function handleScroll() {
	if (!logContainerRef.value) return;
	
	const container = logContainerRef.value;
	const scrollTop = container.scrollTop;
	const scrollHeight = container.scrollHeight;
	const clientHeight = container.clientHeight;
	
	// Check if user scrolled up (not at bottom)
	const isAtBottom = scrollTop + clientHeight >= scrollHeight - 10;
	
	if (isAtBottom) {
		autoScrollEnabled.value = true;
		isUserScrolling.value = false;
	} else {
		autoScrollEnabled.value = false;
		isUserScrolling.value = true;
	}
	
	// Infinite scroll: load more when near top
	if (scrollTop < 100 && !loadingMore.value && props.logs.length > 0) {
		loadingMore.value = true;
		emit('load-more');
	}
	
	lastScrollTop.value = scrollTop;
}

// Auto-scroll to bottom when new logs are added (only if user hasn't scrolled up)
watch(() => props.logs, (newLogs, oldLogs) => {
	nextTick(() => {
		if (logContainerRef.value && autoScrollEnabled.value) {
			// Check if new logs were added at the end (newer logs)
			const oldLength = oldLogs?.length || 0;
			const newLength = newLogs?.length || 0;
			
			if (newLength > oldLength) {
				// Small delay to ensure DOM is updated
				requestAnimationFrame(() => {
					if (logContainerRef.value && autoScrollEnabled.value) {
						logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
					}
				});
			}
		}
	});
}, { immediate: true });

// Reset scroll position when session changes
watch(() => props.sessionId, () => {
	nextTick(() => {
		if (logContainerRef.value) {
			logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
			autoScrollEnabled.value = true;
		}
	});
});
</script>

<style scoped>
.agent-log-viewer {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
}

.log-viewer-header {
	padding: 6px 10px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.log-viewer-header h4 {
	margin: 0;
	font-size: 11px;
	font-weight: 600;
}

.log-count {
	font-size: 10px;
	color: var(--terminal-text-secondary, #858585);
}

.log-viewer-content {
	flex: 1;
	overflow-y: auto;
	padding: 4px;
	min-height: 200px;
}

.loading-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px;
	font-size: 10px;
	color: var(--terminal-text-secondary, #858585);
	justify-content: center;
}

.no-logs {
	padding: 16px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
	font-size: 11px;
}

.log-entries {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.log-entry {
	border-radius: 3px;
	font-size: 11px;
	line-height: 1.4;
	overflow: hidden;
}

.log-entry summary {
	list-style: none;
	cursor: pointer;
	user-select: none;
}

.log-entry summary::-webkit-details-marker {
	display: none;
}

.log-summary {
	padding: 4px 6px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

.log-summary-content {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
	min-width: 0;
}

.log-message-preview {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--terminal-text, #d4d4d4);
}

.log-details {
	padding: 4px 6px 6px 6px;
	border-top: 1px solid rgba(255, 255, 255, 0.05);
	margin-top: 4px;
}

.log-message-full {
	color: var(--terminal-text, #d4d4d4);
	word-wrap: break-word;
}

.log-info {
	background: var(--terminal-bg-secondary, #252526);
}

.log-success {
	background: rgba(16, 185, 129, 0.08);
	border-left: 2px solid #10b981;
}

.log-error {
	background: rgba(239, 68, 68, 0.08);
	border-left: 2px solid #ef4444;
}

.log-warning {
	background: rgba(245, 158, 11, 0.08);
	border-left: 2px solid #f59e0b;
}

.log-scan_start,
.log-scan_complete {
	background: rgba(14, 99, 156, 0.08);
	border-left: 2px solid #0e639c;
}

.log-fix_generated,
.log-fix_applied {
	background: rgba(16, 185, 129, 0.08);
	border-left: 2px solid #10b981;
}

.log-timestamp {
	font-size: 9px;
	color: var(--terminal-text-secondary, #858585);
	white-space: nowrap;
	flex-shrink: 0;
}

.log-type-badge {
	font-size: 9px;
	padding: 1px 4px;
	border-radius: 2px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
	flex-shrink: 0;
}

.chevron-icon {
	width: 10px;
	height: 10px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s ease;
	flex-shrink: 0;
}

.log-entry[open] .chevron-icon {
	transform: rotate(180deg);
}

.type-info {
	background: rgba(14, 99, 156, 0.15);
	color: #0e639c;
}

.type-success {
	background: rgba(16, 185, 129, 0.15);
	color: #10b981;
}

.type-error {
	background: rgba(239, 68, 68, 0.15);
	color: #ef4444;
}

.type-warning {
	background: rgba(245, 158, 11, 0.15);
	color: #f59e0b;
}

.type-scan_start,
.type-scan_complete {
	background: rgba(14, 99, 156, 0.15);
	color: #0e639c;
}

.type-fix_generated,
.type-fix_applied {
	background: rgba(16, 185, 129, 0.15);
	color: #10b981;
}

.log-data {
	margin-top: 4px;
}

.log-data-details {
	font-size: 10px;
}

.log-data-summary {
	cursor: pointer;
	color: var(--terminal-text-secondary, #858585);
	font-size: 10px;
	padding: 2px 0;
}

.log-data-summary:hover {
	color: var(--terminal-text, #d4d4d4);
}

.log-data pre {
	margin: 4px 0 0 0;
	padding: 4px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 2px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 9px;
	overflow-x: auto;
	max-height: 150px;
	overflow-y: auto;
}

.spinner-small {
	display: inline-block;
	width: 10px;
	height: 10px;
	border: 1.5px solid rgba(255, 255, 255, 0.3);
	border-top-color: var(--terminal-text-secondary, #858585);
	border-radius: 50%;
	animation: spin 0.6s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

/* Log entry transitions */
.log-entry-enter-active {
	transition: all 0.2s ease;
}

.log-entry-leave-active {
	transition: all 0.15s ease;
	position: absolute;
	width: 100%;
}

.log-entry-enter-from {
	opacity: 0;
	transform: translateY(4px);
}

.log-entry-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

.log-entry-move {
	transition: transform 0.2s ease;
}

.log-entries {
	position: relative;
}
</style>

