<template>
	<div class="agent-log-viewer">
		<div class="log-header">
			<div class="log-title-area">
				<span class="log-title">Activity</span>
				<span v-if="isRunning" class="running-indicator">
					<span class="pulse-dot"></span>
					<span class="running-text">Running</span>
				</span>
				<span v-else-if="status === 'completed'" class="status-badge completed">Complete</span>
				<span v-else-if="status === 'failed'" class="status-badge failed">Failed</span>
				<span v-else-if="status === 'paused'" class="status-badge paused">Paused</span>
			</div>
			<div class="log-stats" v-if="stats.total > 0">
				<span class="stat fixed" v-if="stats.fixed > 0">{{ stats.fixed }} fixed</span>
				<span class="stat failed" v-if="stats.failed > 0">{{ stats.failed }} failed</span>
				<span class="stat skipped" v-if="stats.skipped > 0">{{ stats.skipped }} skipped</span>
			</div>
		</div>
		
		<div class="log-content" ref="logContainerRef" @scroll="handleScroll">
			<div v-if="logs.length === 0" class="empty-state">
				<span v-if="isRunning" class="spinner"></span>
				<span v-else class="empty-icon">◌</span>
				<span>{{ isRunning ? 'Starting scan...' : 'Waiting for activity...' }}</span>
			</div>
			
			<template v-else>
				<template v-for="(log, index) in logs" :key="log.id">
					<!-- Iteration divider -->
					<div v-if="shouldShowIterationDivider(log, index)" class="iteration-divider">
						<span class="divider-text">{{ getIterationLabel(log) }}</span>
					</div>
					
					<!-- Scan complete -->
					<div v-if="log.type === 'scan_complete'" class="log-entry scan-complete">
						<span class="entry-icon">📋</span>
						<span class="entry-text">{{ log.message }}</span>
					</div>
					
					<!-- Issue entries (fixed/failed/skipped) - expandable -->
					<details 
						v-else-if="isIssueLog(log.type)" 
						class="log-entry issue-entry"
						:class="log.type"
					>
						<summary class="issue-summary" :title="getTooltip(log)">
							<span class="issue-icon">{{ getIcon(log.type) }}</span>
							<span class="issue-text">{{ log.message }}</span>
							<span v-if="log.type === 'skipped'" class="issue-status-label skipped-label">UNCHANGED</span>
							<span v-if="log.data?.rule" class="issue-rule">{{ log.data.rule }}</span>
						</summary>
						<div class="issue-details" v-if="log.data">
							<!-- Skipped reason - show prominently at top -->
							<div v-if="log.type === 'skipped'" class="skip-reason-box">
								<span class="skip-reason-icon">ℹ</span>
								<span class="skip-reason-text">{{ log.data?.reason || 'AI returned identical code - no changes were needed' }}</span>
							</div>
							
							<div class="detail-row" v-if="log.data.full_path">
								<span class="detail-label">File:</span>
								<span class="detail-value path">{{ log.data.full_path }}</span>
							</div>
							<div class="detail-row" v-if="log.data.line">
								<span class="detail-label">Line:</span>
								<span class="detail-value">{{ log.data.line }}</span>
							</div>
							<div class="detail-row" v-if="log.data.rule">
								<span class="detail-label">Rule:</span>
								<span class="detail-value rule">{{ log.data.rule }}</span>
							</div>
							<div class="detail-row message" v-if="log.data.message">
								<span class="detail-label">Issue:</span>
								<span class="detail-value">{{ log.data.message }}</span>
							</div>
							<div class="detail-row error" v-if="log.data.error">
								<span class="detail-label">Error:</span>
								<span class="detail-value">{{ log.data.error }}</span>
							</div>
							<div class="detail-row" v-if="log.data.failure_stage">
								<span class="detail-label">Stage:</span>
								<span class="detail-value stage">{{ log.data.failure_stage }}</span>
							</div>
							<div class="detail-row" v-if="log.data.diff_stats">
								<span class="detail-label">Changes:</span>
								<span class="detail-value diff">+{{ log.data.diff_stats.additions }} / -{{ log.data.diff_stats.deletions }}</span>
							</div>
						</div>
					</details>
					
					<!-- Iteration complete - summary bar -->
					<div v-else-if="log.type === 'iteration_complete'" class="log-entry iteration-complete">
						<div class="iteration-stats" v-if="log.data">
							<span class="iter-stat fixed" v-if="log.data.fixed > 0">✓ {{ log.data.fixed }}</span>
							<span class="iter-stat failed" v-if="log.data.failed > 0">✗ {{ log.data.failed }}</span>
							<span class="iter-stat skipped" v-if="log.data.skipped > 0">○ {{ log.data.skipped }}</span>
						</div>
					</div>
					
					<!-- Success message -->
					<div v-else-if="log.type === 'success'" class="log-entry success-entry">
						<span class="entry-icon">✓</span>
						<span class="entry-text">{{ log.message }}</span>
					</div>
					
					<!-- Warning -->
					<div v-else-if="log.type === 'warning'" class="log-entry warning-entry">
						<span class="entry-icon">⚠</span>
						<span class="entry-text">{{ log.message }}</span>
					</div>
					
					<!-- Error -->
					<div v-else-if="log.type === 'error'" class="log-entry error-entry">
						<span class="entry-icon">✗</span>
						<span class="entry-text">{{ log.message }}</span>
					</div>
					
					<!-- Info / other -->
					<div v-else class="log-entry info-entry">
						<span class="entry-text">{{ log.message }}</span>
					</div>
				</template>
			</template>
			
			<!-- Processing indicator at bottom when running -->
			<div v-if="isRunning && logs.length > 0" class="processing-indicator">
				<span class="processing-dots">
					<span class="dot"></span>
					<span class="dot"></span>
					<span class="dot"></span>
				</span>
				<span class="processing-text">Processing</span>
			</div>
			
			<div v-if="!hasMoreLogs && logs.length > 0 && !isRunning" class="end-marker">—</div>
		</div>
	</div>
</template>

<script setup>
import { ref, watch, nextTick, computed } from 'vue';
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
	status: {
		type: String,
		default: 'pending',
	},
});

// Check if agent is actively running
const isRunning = computed(() => {
	return ['running', 'scanning'].includes(props.status);
});

const emit = defineEmits(['load-more']);

const logContainerRef = ref(null);
const loadingMore = ref(false);
const hasMoreLogs = ref(true);
const currentPage = ref(1);
const logsPerPage = 50;
const autoScrollEnabled = ref(true);

// Calculate stats
const stats = computed(() => {
	let fixed = 0, failed = 0, skipped = 0;
	props.logs.forEach(log => {
		if (log.type === 'fixed') fixed++;
		else if (log.type === 'failed') failed++;
		else if (log.type === 'skipped') skipped++;
	});
	return { fixed, failed, skipped, total: fixed + failed + skipped };
});

function isIssueLog(type) {
	return ['fixed', 'failed', 'skipped'].includes(type);
}

function getIcon(type) {
	const icons = { fixed: '✓', failed: '✗', skipped: '○' };
	return icons[type] || '•';
}

function getTooltip(log) {
	if (log.type === 'skipped') {
		return 'AI returned identical code - no changes made. Click to see issue details.';
	}
	if (log.type === 'fixed') {
		return 'Successfully fixed. Click to see details.';
	}
	if (log.type === 'failed') {
		return 'Failed to fix. Click to see error details.';
	}
	return 'Click to see details';
}

function shouldShowIterationDivider(log, index) {
	// Show divider before scan_complete (start of iteration)
	if (log.type === 'scan_complete' && log.data?.iteration) {
		return true;
	}
	return false;
}

function getIterationLabel(log) {
	if (log.data?.iteration) {
		return `Iteration ${log.data.iteration}`;
	}
	return 'Iteration';
}

// Debounce scroll handling to prevent flicker
let scrollTimeout = null;
function handleScroll() {
	const container = logContainerRef.value;
	if (!container) return;
	
	// Clear any pending scroll processing
	if (scrollTimeout) {
		clearTimeout(scrollTimeout);
	}
	
	scrollTimeout = setTimeout(() => {
		if (!container) return;
		
		if (container.scrollTop < 50 && !loadingMore.value && hasMoreLogs.value) {
			currentPage.value++;
			fetchMoreLogs();
		}
		
		const isAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 10;
		autoScrollEnabled.value = isAtBottom;
	}, 50);
}

async function fetchMoreLogs() {
	if (!props.sessionId) return;
	loadingMore.value = true;
	try {
		const response = await axios.get(api.agent.logs(props.sessionId, {
			limit: logsPerPage,
			offset: (currentPage.value - 1) * logsPerPage,
		}));
		if (response.data.success) {
			const newLogs = response.data.result.logs || [];
			if (newLogs.length > 0) {
				emit('load-more');
			}
			hasMoreLogs.value = newLogs.length === logsPerPage;
		}
	} catch (error) {
		console.error('Failed to fetch logs:', error);
	} finally {
		loadingMore.value = false;
	}
}

// Auto-scroll - only when running and user hasn't scrolled away
watch(() => props.logs, (newLogs, oldLogs) => {
	// Only auto-scroll if agent is running and auto-scroll is enabled
	if (!isRunning.value && props.status !== 'pending') {
		return; // Don't auto-scroll when completed/stopped
	}
	
	nextTick(() => {
		if (logContainerRef.value && autoScrollEnabled.value) {
			if ((newLogs?.length || 0) > (oldLogs?.length || 0)) {
				requestAnimationFrame(() => {
					if (logContainerRef.value && autoScrollEnabled.value) {
						logContainerRef.value.scrollTop = logContainerRef.value.scrollHeight;
					}
				});
			}
		}
	});
}, { deep: false });

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
	font-family: 'SF Mono', 'Fira Code', 'JetBrains Mono', Consolas, monospace;
	font-size: 12px;
}

.log-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.log-title-area {
	display: flex;
	align-items: center;
	gap: 10px;
}

.log-title {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--terminal-text-secondary, #858585);
}

/* Running indicator with pulse animation */
.running-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 3px 8px;
	background: rgba(96, 165, 250, 0.15);
	border-radius: 10px;
	border: 1px solid rgba(96, 165, 250, 0.3);
}

.pulse-dot {
	width: 8px;
	height: 8px;
	background: #60a5fa;
	border-radius: 50%;
	animation: pulse-animation 1.5s ease-in-out infinite;
	box-shadow: 0 0 8px rgba(96, 165, 250, 0.6);
}

@keyframes pulse-animation {
	0%, 100% {
		opacity: 1;
		transform: scale(1);
		box-shadow: 0 0 8px rgba(96, 165, 250, 0.6);
	}
	50% {
		opacity: 0.6;
		transform: scale(0.85);
		box-shadow: 0 0 4px rgba(96, 165, 250, 0.3);
	}
}

.running-text {
	font-size: 10px;
	font-weight: 600;
	color: #60a5fa;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Status badges */
.status-badge {
	font-size: 9px;
	font-weight: 600;
	padding: 3px 8px;
	border-radius: 10px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.status-badge.completed {
	background: rgba(52, 211, 153, 0.15);
	color: #34d399;
	border: 1px solid rgba(52, 211, 153, 0.3);
}

.status-badge.failed {
	background: rgba(248, 113, 113, 0.15);
	color: #f87171;
	border: 1px solid rgba(248, 113, 113, 0.3);
}

.status-badge.paused {
	background: rgba(245, 158, 11, 0.15);
	color: #d97706;
	border: 1px solid rgba(245, 158, 11, 0.3);
}

.log-stats {
	display: flex;
	gap: 8px;
}

.stat {
	font-size: 10px;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: 3px;
}

.stat.fixed { color: #34d399; background: rgba(52, 211, 153, 0.15); }
.stat.failed { color: #f87171; background: rgba(248, 113, 113, 0.15); }
.stat.skipped { color: #94a3b8; background: rgba(148, 163, 184, 0.15); }

.log-content {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
	background: var(--terminal-bg, #1e1e1e);
	/* Performance optimizations */
	contain: layout style;
	will-change: scroll-position;
}

.empty-state {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	padding: 32px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 12px;
}

.empty-icon {
	opacity: 0.5;
}

/* Spinner animation */
.spinner {
	width: 16px;
	height: 16px;
	border: 2px solid rgba(96, 165, 250, 0.3);
	border-top-color: #60a5fa;
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Iteration divider */
.iteration-divider {
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 12px 0 8px 0;
	padding: 4px 0;
}

.divider-text {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 1.5px;
	color: var(--terminal-text-secondary, #858585);
	background: var(--terminal-bg, #1e1e1e);
	padding: 0 12px;
}

/* Log entries */
.log-entry {
	margin: 2px 0;
	padding: 6px 10px;
	border-radius: 4px;
	line-height: 1.4;
}

.entry-icon {
	margin-right: 8px;
	flex-shrink: 0;
}

.entry-text {
	color: var(--terminal-text, #d4d4d4);
}

/* Scan complete */
.scan-complete {
	display: flex;
	align-items: center;
	background: rgba(96, 165, 250, 0.1);
	border-left: 3px solid #60a5fa;
	color: #93c5fd;
	font-weight: 500;
}

/* Issue entries */
.issue-entry {
	cursor: pointer;
	/* Prevent layout shifts */
	contain: layout;
}

.issue-entry[open] {
	background: var(--terminal-bg-secondary, #252526);
}

.issue-summary {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	list-style: none;
	border-radius: 4px;
	user-select: none;
}

.issue-summary::-webkit-details-marker {
	display: none;
}

.issue-summary:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	/* Prevent layout thrashing on hover */
	transform: translateZ(0);
}

.issue-icon {
	flex-shrink: 0;
	font-weight: bold;
}

.issue-text {
	flex: 1;
	color: var(--terminal-text, #d4d4d4);
}

.issue-rule {
	font-size: 10px;
	padding: 2px 6px;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 3px;
	color: var(--terminal-text-secondary, #858585);
}

.issue-status-label {
	font-size: 9px;
	padding: 2px 6px;
	border-radius: 3px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.3px;
}

.skipped-label {
	background: rgba(148, 163, 184, 0.15);
	color: #64748b;
	border: 1px solid rgba(148, 163, 184, 0.3);
}

/* Issue type colors */
.issue-entry.fixed .issue-icon { color: #34d399; }
.issue-entry.failed .issue-icon { color: #f87171; }
.issue-entry.skipped .issue-icon { color: #94a3b8; }

.issue-entry.fixed { border-left: 3px solid #34d399; }
.issue-entry.failed { border-left: 3px solid #f87171; background: rgba(248, 113, 113, 0.05); }
.issue-entry.skipped { border-left: 3px solid #94a3b8; background: rgba(148, 163, 184, 0.05); }

/* Issue details (expanded) */
.issue-details {
	padding: 8px 10px 8px 28px;
	font-size: 11px;
	border-top: 1px solid var(--terminal-border, #3e3e42);
	margin-top: 4px;
}

/* Skip reason box - prominent explanation */
.skip-reason-box {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 12px;
	margin-bottom: 10px;
	background: rgba(148, 163, 184, 0.1);
	border: 1px solid rgba(148, 163, 184, 0.2);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
}

.skip-reason-icon {
	flex-shrink: 0;
	font-size: 14px;
	color: #64748b;
}

.skip-reason-text {
	font-size: 12px;
	line-height: 1.5;
	color: var(--terminal-text-secondary, #a1a1aa);
}

.detail-row {
	display: flex;
	gap: 8px;
	padding: 3px 0;
}

.detail-row.message,
.detail-row.error {
	flex-direction: column;
	gap: 4px;
}

.detail-label {
	color: var(--terminal-text-secondary, #858585);
	flex-shrink: 0;
	min-width: 50px;
}

.detail-value {
	color: var(--terminal-text, #d4d4d4);
	word-break: break-word;
}

.detail-value.path {
	color: #93c5fd;
	font-family: inherit;
}

.detail-value.rule {
	color: #a78bfa;
}

.detail-value.stage {
	color: #f97316;
	text-transform: uppercase;
	font-size: 10px;
	font-weight: 600;
}

.detail-value.diff {
	color: #34d399;
}

.detail-row.error .detail-value {
	color: #fca5a5;
	background: rgba(248, 113, 113, 0.1);
	padding: 6px 8px;
	border-radius: 4px;
	font-size: 11px;
}

/* Iteration complete */
.iteration-complete {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	padding: 8px 10px;
	margin: 8px 0 4px 0;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 4px;
}

.iteration-stats {
	display: flex;
	gap: 12px;
}

.iter-stat {
	font-size: 11px;
	font-weight: 600;
}

.iter-stat.fixed { color: #34d399; }
.iter-stat.failed { color: #f87171; }
.iter-stat.skipped { color: #94a3b8; }

/* Success entry */
.success-entry {
	display: flex;
	align-items: center;
	background: rgba(52, 211, 153, 0.1);
	border: 1px solid rgba(52, 211, 153, 0.2);
	color: #34d399;
	font-weight: 500;
}

/* Warning entry */
.warning-entry {
	display: flex;
	align-items: center;
	background: rgba(245, 158, 11, 0.1);
	color: #d97706;
}

/* Error entry */
.error-entry {
	display: flex;
	align-items: center;
	background: rgba(248, 113, 113, 0.1);
	border-left: 3px solid #f87171;
	color: #fca5a5;
}

/* Info entry */
.info-entry {
	color: var(--terminal-text-secondary, #858585);
	font-size: 11px;
	padding: 4px 10px;
}

/* Processing indicator */
.processing-indicator {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 12px;
	margin-top: 8px;
}

.processing-dots {
	display: flex;
	gap: 4px;
}

.processing-dots .dot {
	width: 6px;
	height: 6px;
	background: #60a5fa;
	border-radius: 50%;
	animation: bounce-dot 1.4s ease-in-out infinite;
}

.processing-dots .dot:nth-child(1) { animation-delay: 0s; }
.processing-dots .dot:nth-child(2) { animation-delay: 0.2s; }
.processing-dots .dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes bounce-dot {
	0%, 80%, 100% {
		opacity: 0.3;
		transform: scale(0.8);
	}
	40% {
		opacity: 1;
		transform: scale(1);
	}
}

.processing-text {
	font-size: 11px;
	color: #60a5fa;
	font-weight: 500;
}

/* End marker */
.end-marker {
	text-align: center;
	padding: 8px;
	color: var(--terminal-text-secondary, #858585);
	opacity: 0.5;
	font-size: 10px;
}

/* Scrollbar */
.log-content::-webkit-scrollbar {
	width: 6px;
}

.log-content::-webkit-scrollbar-track {
	background: transparent;
}

.log-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 3px;
}

.log-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-text-secondary, #858585);
}
</style>
