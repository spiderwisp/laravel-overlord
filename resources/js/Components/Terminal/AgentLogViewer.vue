<template>
	<div class="agent-log-viewer">
		<div class="log-viewer-header">
			<h4>Activity Log</h4>
		</div>
		<div class="log-viewer-content" ref="logContainerRef">
			<div v-if="logs.length === 0" class="no-logs">
				<p>No logs yet. Agent activity will appear here.</p>
			</div>
			<div v-else class="log-entries">
				<div
					v-for="log in logs"
					:key="log.id"
					class="log-entry"
					:class="`log-${log.type}`"
				>
					<div class="log-header">
						<div class="log-timestamp">
							{{ formatTimestamp(log.created_at) }}
						</div>
						<div class="log-type-badge" :class="`type-${log.type}`">
							{{ log.type }}
						</div>
					</div>
					<div class="log-message">
						{{ log.message }}
					</div>
					<div v-if="log.data && Object.keys(log.data).length > 0" class="log-data">
						<details>
							<summary>Details</summary>
							<pre>{{ JSON.stringify(log.data, null, 2) }}</pre>
						</details>
					</div>
				</div>
			</div>
		</div>
		<div class="log-viewer-footer">
			<button
				@click="$emit('load-more')"
				class="terminal-btn terminal-btn-secondary terminal-btn-sm"
			>
				Load More
			</button>
		</div>
	</div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';

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

function formatTimestamp(timestamp) {
	if (!timestamp) return '';
	const date = new Date(timestamp);
	return date.toLocaleTimeString();
}

// Auto-scroll to bottom when new logs are added
watch(() => props.logs.length, () => {
	nextTick(() => {
		if (logContainerRef.value) {
			logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
		}
	});
}, { immediate: true });
</script>

<style scoped>
.agent-log-viewer {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
}

.log-viewer-header {
	padding: 8px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.log-viewer-header h4 {
	margin: 0;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
}

.log-viewer-content {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
	min-height: 200px;
}

.no-logs {
	padding: 24px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.log-entries {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.log-entry {
	padding: 8px 12px;
	border-radius: 4px;
	font-size: var(--terminal-font-size-sm, 12px);
	line-height: 1.5;
}

.log-info {
	background: var(--terminal-bg-secondary, #252526);
}

.log-success {
	background: rgba(16, 185, 129, 0.1);
	border-left: 3px solid #10b981;
}

.log-error {
	background: rgba(239, 68, 68, 0.1);
	border-left: 3px solid #ef4444;
}

.log-warning {
	background: rgba(245, 158, 11, 0.1);
	border-left: 3px solid #f59e0b;
}

.log-scan_start,
.log-scan_complete {
	background: rgba(14, 99, 156, 0.1);
	border-left: 3px solid #0e639c;
}

.log-fix_generated,
.log-fix_applied {
	background: rgba(16, 185, 129, 0.1);
	border-left: 3px solid #10b981;
}

.log-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.log-timestamp {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
}

.log-type-badge {
	font-size: var(--terminal-font-size-xs, 11px);
	padding: 2px 6px;
	border-radius: 3px;
	font-weight: 600;
	text-transform: uppercase;
}

.type-info {
	background: rgba(14, 99, 156, 0.2);
	color: #0e639c;
}

.type-success {
	background: rgba(16, 185, 129, 0.2);
	color: #10b981;
}

.type-error {
	background: rgba(239, 68, 68, 0.2);
	color: #ef4444;
}

.type-warning {
	background: rgba(245, 158, 11, 0.2);
	color: #f59e0b;
}

.type-scan_start,
.type-scan_complete {
	background: rgba(14, 99, 156, 0.2);
	color: #0e639c;
}

.type-fix_generated,
.type-fix_applied {
	background: rgba(16, 185, 129, 0.2);
	color: #10b981;
}

.log-message {
	color: var(--terminal-text, #d4d4d4);
}

.log-data {
	margin-top: 8px;
	padding: 8px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 4px;
	font-size: var(--terminal-font-size-xs, 11px);
	overflow-x: auto;
}

.log-data summary {
	cursor: pointer;
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-xs, 11px);
	margin-bottom: 4px;
}

.log-data summary:hover {
	color: var(--terminal-text, #d4d4d4);
}

.log-data pre {
	margin: 8px 0 0 0;
	color: var(--terminal-text-secondary, #858585);
}
</style>

