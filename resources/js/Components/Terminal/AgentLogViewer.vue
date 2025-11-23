<template>
	<div class="agent-log-viewer">
		<div class="log-viewer-header">
			<h4>Activity Log</h4>
		</div>
		<div class="log-viewer-content" ref="logContainerRef" @scroll="handleScroll">
			<div v-if="loadingMore" class="loading-more">Loading more logs...</div>
			<div v-if="logs.length === 0" class="no-logs">
				<p>No logs yet. Agent activity will appear here.</p>
			</div>
			<TransitionGroup v-else name="log-fade" tag="div" class="log-entries">
				<details
					v-for="log in logs"
					:key="`log-${log.id}`"
					class="log-entry"
					:class="`log-${log.type}`"
					:open="log.type === 'error' || (log.type !== 'info' && log.type !== 'fix_generated') || (log.data && log.data.failed_issues && log.data.failed_issues.length > 0)"
				>
					<summary class="log-summary">
						<span class="log-icon" :class="`icon-${log.type}`">{{ getLogIcon(log.type) }}</span>
						<span class="log-timestamp" :class="{'timestamp-just-now': formatRelativeTime(log.created_at) === 'just now'}">{{ formatRelativeTime(log.created_at) }}</span>
						<span class="log-type-badge" :class="`type-${log.type}`">{{ log.type }}</span>
						<span class="log-message-summary" :class="{'log-message-error': log.type === 'error'}">{{ log.message }}</span>
					</summary>
					<div class="log-details-content">
						<div class="log-message" v-html="formatLogMessage(log.message)"></div>
						<div v-if="log.data && Object.keys(log.data).length > 0" class="log-data">
							<div v-if="log.data.failed_issues && Array.isArray(log.data.failed_issues) && log.data.failed_issues.length > 0" class="failed-issues-list">
								<strong class="failed-issues-header">Failed Issues ({{ log.data.failed_issues.length }}):</strong>
								<ul class="failed-issues-ul">
									<li v-for="(failedIssue, idx) in log.data.failed_issues" :key="idx" class="failed-issue-item">
										<span class="failed-issue-number">{{ idx + 1 }}.</span>
										<span class="failed-issue-file"><strong>{{ failedIssue.file }}</strong></span>
										<span v-if="failedIssue.line" class="failed-issue-line">(line {{ failedIssue.line }})</span>
										<span class="failed-issue-separator">:</span>
										<span class="failed-issue-error">{{ failedIssue.error }}</span>
										<span v-if="failedIssue.failure_stage" class="failed-issue-stage">[{{ failedIssue.failure_stage }}]</span>
									</li>
								</ul>
							</div>
							<pre v-if="shouldShowJsonData(log.data)" class="log-data-json">{{ JSON.stringify(log.data, null, 2) }}</pre>
						</div>
					</div>
				</details>
			</TransitionGroup>
			<div v-if="!hasMoreLogs && logs.length > 0" class="no-more-logs">End of logs.</div>
		</div>
	</div>
</template>

<script setup>
import { ref, watch, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';

const api = useOverlordApi();

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
const hasMoreLogs = ref(true);
const currentPage = ref(1);
const logsPerPage = 50;
const autoScrollEnabled = ref(true);

function formatRelativeTime(timestamp) {
	if (!timestamp) return '';
	const date = new Date(timestamp);
	const now = new Date();
	const diff = now - date;
	
	if (diff < 60000) {
		return 'just now';
	} else if (diff < 3600000) {
		const minutes = Math.floor(diff / 60000);
		return `${minutes}m ago`;
	} else {
		return date.toLocaleTimeString();
	}
}

function getLogIcon(type) {
	const icons = {
		error: '✗',
		warning: '⚠',
		success: '✓',
		info: 'ℹ',
		fix_applied: '✓',
		fix_generated: '→',
		scan_complete: '✓',
		scan_start: '→',
	};
	return icons[type] || '•';
}

function formatLogMessage(message) {
	// Replace newlines with <br> for better formatting
	let formatted = message.replace(/\n/g, '<br>');
	
	// Highlight key failure indicators with emojis
	formatted = formatted
		.replace(/❌ FAILURE REASON:/g, '<span class="log-highlight-error-bold">❌ FAILURE REASON:</span>')
		.replace(/📍 FAILURE STAGE:/g, '<span class="log-highlight-warning-bold">📍 FAILURE STAGE:</span>')
		.replace(/📋 ORIGINAL ISSUE:/g, '<span class="log-highlight-info-bold">📋 ORIGINAL ISSUE:</span>')
		.replace(/\b(FAILED|FAIL|ERROR|✗)\b/gi, '<span class="log-highlight-error">$1</span>')
		.replace(/\b(SUCCESS|SUCCESSFULLY|✓)\b/gi, '<span class="log-highlight-success">$1</span>')
		.replace(/\b(WARNING|⚠)\b/gi, '<span class="log-highlight-warning">$1</span>')
		// Highlight failure stages
		.replace(/\b(code_generation|code_extraction|code_validation|ai_service|file_write|final_validation|file_application|exception)\b/gi, '<span class="log-highlight-stage">[$1]</span>');
	
	return formatted;
}

function shouldShowJsonData(data) {
	// Don't show JSON if we're already showing failed_issues in a formatted way
	if (data.failed_issues && Array.isArray(data.failed_issues) && data.failed_issues.length > 0) {
		// Only show JSON if there are other fields besides failed_issues
		const otherKeys = Object.keys(data).filter(key => key !== 'failed_issues');
		return otherKeys.length > 0;
	}
	return Object.keys(data).length > 0;
}

async function fetchLogs(offset, limit, append = false) {
	if (!props.sessionId) return;
	loadingMore.value = true;
	try {
		const response = await axios.get(api.agent.logs(props.sessionId, {
			limit: limit,
			offset: offset,
		}));
		if (response.data.success) {
			const newLogs = response.data.result.logs || [];
			if (append) {
				emit('load-more');
			}
			hasMoreLogs.value = newLogs.length === limit;
		}
	} catch (error) {
		console.error('Failed to fetch logs:', error);
	} finally {
		loadingMore.value = false;
	}
}

function handleScroll() {
	const container = logContainerRef.value;
	if (container && container.scrollTop < 50 && !loadingMore.value && hasMoreLogs.value) {
		currentPage.value++;
		fetchLogs((currentPage.value - 1) * logsPerPage, logsPerPage, true);
	}
	
	const isAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 10;
	autoScrollEnabled.value = isAtBottom;
}

// Auto-scroll to bottom when new logs are added (only if user hasn't scrolled up)
watch(() => props.logs, (newLogs, oldLogs) => {
	nextTick(() => {
		if (logContainerRef.value && autoScrollEnabled.value) {
			const oldLength = oldLogs?.length || 0;
			const newLength = newLogs?.length || 0;
			
			if (newLength > oldLength) {
				requestAnimationFrame(() => {
					if (logContainerRef.value && autoScrollEnabled.value) {
						logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
					}
				});
			}
		}
	});
}, { immediate: true });

watch(() => props.sessionId, () => {
	nextTick(() => {
		if (logContainerRef.value) {
			logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
			autoScrollEnabled.value = true;
			currentPage.value = 1;
			hasMoreLogs.value = true;
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
	padding: 4px;
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
	padding: 4px 8px;
	margin-bottom: 2px;
	border-radius: 4px;
	font-size: var(--terminal-font-size-xs, 11px);
	line-height: 1.4;
}

.log-info {
	background: var(--terminal-bg-secondary, #252526);
}

.log-success {
	background: rgba(16, 185, 129, 0.1);
	border-left: 3px solid #10b981;
}

.log-error {
	background: rgba(239, 68, 68, 0.15);
	border-left: 4px solid #ef4444;
	border: 1px solid rgba(239, 68, 68, 0.3);
	font-weight: 500;
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

.log-timestamp {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	flex-shrink: 0;
}

.log-timestamp.timestamp-just-now {
	font-size: 9px;
	vertical-align: super;
	opacity: 0.7;
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
	margin-top: 4px;
	padding: 4px 8px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 4px;
	font-size: var(--terminal-font-size-xs, 11px);
	overflow-x: auto;
}

.loading-more,
.no-more-logs {
	text-align: center;
	padding: 8px;
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
}

.log-fade-enter-active,
.log-fade-leave-active {
	transition: opacity 0.3s ease, transform 0.3s ease;
}

.log-fade-enter-from,
.log-fade-leave-to {
	opacity: 0;
	transform: translateY(10px);
}

.log-fade-move {
	transition: transform 0.3s ease;
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

.failed-issues-list {
	margin: 12px 0;
	padding: 12px;
	background: rgba(239, 68, 68, 0.1);
	border-left: 3px solid #ef4444;
	border-radius: 4px;
}

.failed-issues-header {
	display: block;
	color: #ef4444;
	font-size: var(--terminal-font-size-sm, 12px);
	margin-bottom: 8px;
	font-weight: 600;
}

.failed-issues-ul {
	margin: 0;
	padding-left: 20px;
	list-style: none;
}

.failed-issue-item {
	margin: 6px 0;
	padding: 4px 0;
	font-size: var(--terminal-font-size-xs, 11px);
	line-height: 1.5;
	color: var(--terminal-text, #d4d4d4);
}

.failed-issue-number {
	color: var(--terminal-text-secondary, #858585);
	margin-right: 6px;
	font-weight: 600;
}

.failed-issue-file {
	color: var(--terminal-text, #d4d4d4);
}

.failed-issue-file strong {
	color: #f59e0b;
	font-weight: 600;
}

.failed-issue-line {
	color: var(--terminal-text-secondary, #858585);
	margin: 0 4px;
}

.failed-issue-separator {
	color: var(--terminal-text-secondary, #858585);
	margin: 0 4px;
}

.failed-issue-error {
	color: #ef4444;
	font-weight: 500;
}

.failed-issue-stage {
	color: #f59e0b;
	font-size: 10px;
	margin-left: 6px;
	font-weight: 600;
	text-transform: uppercase;
}

.log-highlight-error {
	color: #ef4444;
	font-weight: 600;
}

.log-highlight-error-bold {
	color: #ef4444;
	font-weight: 700;
	font-size: 1.1em;
}

.log-highlight-success {
	color: #10b981;
	font-weight: 600;
}

.log-highlight-warning {
	color: #f59e0b;
	font-weight: 600;
}

.log-highlight-warning-bold {
	color: #f59e0b;
	font-weight: 700;
}

.log-highlight-info {
	color: #0e639c;
	font-weight: 500;
}

.log-highlight-info-bold {
	color: #0e639c;
	font-weight: 600;
}

.log-highlight-stage {
	color: #f59e0b;
	font-weight: 600;
	background: rgba(245, 158, 11, 0.1);
	padding: 2px 4px;
	border-radius: 3px;
}
</style>

