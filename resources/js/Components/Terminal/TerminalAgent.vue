<template>
	<div v-if="visible" class="terminal-agent">
		<div class="terminal-agent-header">
			<h3>AI Agent <span class="experimental-tag">EXPERIMENTAL</span></h3>
			<button @click="emit('close')" class="terminal-btn terminal-btn-close" title="Close">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
					<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<!-- Configuration Panel (when not running) -->
		<div v-if="!sessionId" class="terminal-agent-config">
			<div class="config-section">
				<h4>Start Agent Session</h4>
				
				<div class="config-field">
					<label for="larastan-level">Larastan Level (0-9)</label>
					<div class="config-field-help">
						Select the Larastan analysis level. Higher levels are more strict.
					</div>
					<input
						id="larastan-level"
						v-model.number="config.larastan_level"
						type="number"
						min="0"
						max="9"
						class="terminal-input"
						:value="config.larastan_level"
					/>
				</div>

				<div class="config-field">
					<label for="max-iterations">Max Iterations</label>
					<div class="config-field-help">
						Maximum number of fix iterations (default: 50)
					</div>
					<input
						id="max-iterations"
						v-model.number="config.max_iterations"
						type="number"
						min="1"
						max="100"
						class="terminal-input"
						:value="config.max_iterations"
					/>
				</div>

				<div class="config-field">
					<label for="max-retries">Max Retries per Issue</label>
					<div class="config-field-help">
						Number of times the AI will retry generating code when validation fails (default: 3)
					</div>
					<input
						id="max-retries"
						v-model.number="config.max_retries"
						type="number"
						min="1"
						max="10"
						class="terminal-input"
						:value="config.max_retries"
					/>
				</div>

				<div class="config-field">
					<label class="config-checkbox-label">
						<input
							v-model="config.auto_apply"
							type="checkbox"
							class="terminal-checkbox"
						/>
						<span>Auto-apply fixes</span>
					</label>
					<div class="config-field-help">
						When enabled, fixes are applied automatically. When disabled, you'll review and approve each fix.
					</div>
				</div>

				<div class="config-actions">
					<button
						@click="startAgent"
						class="terminal-btn terminal-btn-primary"
						:disabled="starting"
					>
						<span v-if="starting" class="spinner-small"></span>
						{{ starting ? 'Starting...' : 'Start Agent' }}
					</button>
				</div>
			</div>
		</div>

			<!-- Active Session View -->
			<div v-else class="terminal-agent-session">
				<!-- Error Message -->
				<div v-if="errorMessage" class="agent-error">
					<strong>Error:</strong> {{ errorMessage }}
				</div>

				<!-- Unified Status Header -->
				<div class="agent-status-header">
					<!-- Left: Status and Progress -->
					<div class="status-left">
						<div class="status-badge-wrapper">
							<span class="status-badge" :class="statusClass">
								<span v-if="status === 'running'" class="status-pulse"></span>
								{{ statusText }}
							</span>
						</div>
						<div class="status-iteration">
							Iteration {{ currentIteration }}<span class="iteration-max">/{{ maxIterations }}</span>
						</div>
					</div>
					
					<!-- Center: Stats Cards -->
					<div class="status-stats">
						<div class="stat-card">
							<span class="stat-value">{{ totalIssuesFound }}</span>
							<span class="stat-label">Found</span>
						</div>
						<div class="stat-card stat-fixed">
							<span class="stat-value">{{ totalIssuesFixed }}</span>
							<span class="stat-label">Fixed</span>
						</div>
						<div class="stat-card stat-failed" v-if="failedIssuesCount > 0">
							<span class="stat-value">{{ failedIssuesCount }}</span>
							<span class="stat-label">Failed</span>
						</div>
					</div>
					
					<!-- Right: Actions -->
					<div class="status-actions">
						<button
							v-if="status === 'running'"
							@click="pauseAgent"
							class="action-btn action-pause"
							:disabled="pausing"
							title="Pause"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="16" height="16">
								<path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
							</svg>
						</button>
						<button
							v-if="status === 'paused'"
							@click="resumeAgent"
							class="action-btn action-resume"
							:disabled="resuming"
							title="Resume"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="16" height="16">
								<path d="M8 5v14l11-7z"/>
							</svg>
						</button>
						<button
							v-if="canStop"
							@click="stopAgent"
							class="action-btn action-stop"
							:disabled="stopping"
							title="Stop"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24" width="16" height="16">
								<path d="M6 6h12v12H6z"/>
							</svg>
						</button>
					</div>
				</div>
				
				<!-- Progress Bar -->
				<div v-if="status === 'running'" class="agent-progress-bar">
					<div class="progress-fill" :class="{ 'progress-animated': status === 'running' }"></div>
				</div>
				
				<!-- Config Summary (collapsed inline) -->
				<div class="config-summary">
					<span class="config-item">Level {{ config.larastan_level }}</span>
					<span class="config-divider">•</span>
					<span class="config-item">{{ config.auto_apply ? 'Auto-apply' : 'Review mode' }}</span>
				</div>

			<!-- Logs Viewer -->
			<AgentLogViewer
				:session-id="sessionId"
				:logs="logs"
				:status="status"
				@load-more="() => { loadingMore = true; loadMoreLogs(); }"
			/>

			<!-- Pending Changes (Review Mode) -->
			<details v-if="!autoApply && pendingChanges.length > 0" class="pending-changes-section" open>
				<summary class="pending-changes-summary">Pending Changes ({{ pendingChanges.length }})</summary>
				<div class="pending-changes-list">
					<FileChangePreview
						v-for="change in pendingChanges"
						:key="change.id"
						:change="change"
						@approve="approveChange"
						@reject="rejectChange"
					/>
				</div>
			</details>

			<!-- Applied Changes (Show what was actually changed) -->
			<details v-if="appliedChanges.length > 0" class="applied-changes-section" :open="false">
				<summary class="applied-changes-summary">
					<span>Applied Changes ({{ appliedChanges.length }})</span>
					<span class="applied-changes-badge">✓</span>
				</summary>
				<div class="applied-changes-list">
					<FileChangePreview
						v-for="change in appliedChanges"
						:key="change.id"
						:change="change"
					/>
				</div>
			</details>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import AgentLogViewer from './AgentLogViewer.vue';
import FileChangePreview from './FileChangePreview.vue';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

// State
const sessionId = ref(null);
const status = ref('pending');
const currentIteration = ref(0);
const maxIterations = ref(50);
const totalIssuesFound = ref(0);
const totalIssuesFixed = ref(0);
const failedIssuesCount = ref(0);
const logs = ref([]);
const pendingChanges = ref([]);
const appliedChanges = ref([]);
const starting = ref(false);
const errorMessage = ref(null);
const pausing = ref(false);
const resuming = ref(false);
const stopping = ref(false);
const statusPollInterval = ref(null);
const logsPollInterval = ref(null);

const config = ref({
	larastan_level: 1,
	max_iterations: 50,
	max_retries: 3,
	auto_apply: true,
});

const autoApply = computed(() => {
	// Get from session if available, otherwise from config
	return config.value.auto_apply;
});

// Computed
const statusText = computed(() => {
	const statusMap = {
		pending: 'Pending',
		running: 'Running',
		paused: 'Paused',
		completed: 'Completed',
		stopped: 'Stopped',
		failed: 'Failed',
	};
	return statusMap[status.value] || status.value;
});

const statusClass = computed(() => {
	return `status-${status.value}`;
});

const canStop = computed(() => {
	return ['running', 'paused'].includes(status.value);
});

const progressPercentage = computed(() => {
	if (maxIterations.value === 0) return 0;
	return Math.min(100, (currentIteration.value / maxIterations.value) * 100);
});

// Methods
async function startAgent() {
	if (starting.value) return;

	starting.value = true;
	try {
		const response = await axios.post(api.agent.start(), {
			larastan_level: config.value.larastan_level,
			max_iterations: config.value.max_iterations,
			max_retries: config.value.max_retries,
			auto_apply: config.value.auto_apply,
		});

			if (response.data.success) {
				sessionId.value = response.data.result.session_id;
				status.value = response.data.result.status;
				errorMessage.value = null;
				startPolling();
			} else {
				errorMessage.value = response.data.error || 'Unknown error';
			}
	} catch (error) {
		console.error('Failed to start agent:', error);
		alert('Failed to start agent: ' + (error.response?.data?.error || error.message));
	} finally {
		starting.value = false;
	}
}

async function pauseAgent() {
	if (pausing.value || !sessionId.value) return;

	pausing.value = true;
	try {
		const response = await axios.post(api.agent.pause(sessionId.value));
		if (response.data.success) {
			status.value = response.data.result.status;
		}
	} catch (error) {
		console.error('Failed to pause agent:', error);
	} finally {
		pausing.value = false;
	}
}

async function resumeAgent() {
	if (resuming.value || !sessionId.value) return;

	resuming.value = true;
	try {
		const response = await axios.post(api.agent.resume(sessionId.value));
		if (response.data.success) {
			status.value = response.data.result.status;
		}
	} catch (error) {
		console.error('Failed to resume agent:', error);
	} finally {
		resuming.value = false;
	}
}

async function stopAgent() {
	if (stopping.value || !sessionId.value) return;

	if (!confirm('Are you sure you want to stop the agent?')) {
		return;
	}

	stopping.value = true;
	try {
		const response = await axios.post(api.agent.stop(sessionId.value));
		if (response.data.success) {
			status.value = response.data.result.status;
			stopPolling();
		}
	} catch (error) {
		console.error('Failed to stop agent:', error);
	} finally {
		stopping.value = false;
	}
}

async function loadStatus() {
	if (!sessionId.value) return;

	try {
		const response = await axios.get(api.agent.status(sessionId.value));
		if (response.data.success) {
			const result = response.data.result;
			const oldStatus = status.value;
			status.value = result.status;
			currentIteration.value = result.current_iteration;
			maxIterations.value = result.max_iterations;
			totalIssuesFound.value = result.total_issues_found;
			totalIssuesFixed.value = result.total_issues_fixed;
			failedIssuesCount.value = result.failed_issues_count || 0;
			
			// Update config from session
			if (result.auto_apply !== undefined) {
				config.value.auto_apply = result.auto_apply;
			}

			// Log status changes
			if (oldStatus !== result.status) {
				console.log('Agent status changed:', oldStatus, '->', result.status);
			}

			// Show error message if failed
			if (result.status === 'failed' && result.error_message) {
				errorMessage.value = result.error_message;
			} else if (result.status !== 'failed') {
				errorMessage.value = null;
			}

			// Stop polling if completed/stopped/failed
			if (['completed', 'stopped', 'failed'].includes(result.status)) {
				stopPolling();
			}
		} else {
			console.error('Failed to load status:', response.data.error);
		}
	} catch (error) {
		console.error('Failed to load status:', error);
		if (error.response?.data?.error) {
			console.error('Error details:', error.response.data.error);
		}
	}
}

async function loadLogs() {
	if (!sessionId.value) return;

	try {
		const response = await axios.get(api.agent.logs(sessionId.value, {
			limit: 50,
			offset: 0,
		}));

		if (response.data.success) {
			logs.value = response.data.result.logs.reverse(); // Reverse to show oldest first
		}
	} catch (error) {
		console.error('Failed to load logs:', error);
	}
}

async function loadMoreLogs() {
	if (!sessionId.value) return;

	try {
		const response = await axios.get(api.agent.logs(sessionId.value, {
			limit: 50,
			offset: logs.value.length,
		}));

		if (response.data.success && response.data.result.logs.length > 0) {
			logs.value = [...logs.value, ...response.data.result.logs.reverse()];
		}
	} catch (error) {
		console.error('Failed to load more logs:', error);
	}
}

async function loadPendingChanges() {
	if (!sessionId.value || config.value.auto_apply) {
		pendingChanges.value = [];
		return;
	}

	try {
		const response = await axios.get(api.agent.pendingChanges(sessionId.value));
		if (response.data.success) {
			pendingChanges.value = response.data.result.changes;
		}
	} catch (error) {
		console.error('Failed to load pending changes:', error);
	}
}

async function loadAppliedChanges() {
	if (!sessionId.value) {
		appliedChanges.value = [];
		return;
	}

	try {
		const response = await axios.get(api.agent.appliedChanges(sessionId.value));
		if (response.data.success) {
			appliedChanges.value = response.data.result.changes;
		}
	} catch (error) {
		console.error('Failed to load applied changes:', error);
	}
}

async function approveChange(changeId) {
	try {
		const response = await axios.post(api.agent.approveChange(changeId));
		if (response.data.success) {
			// Reload pending changes
			await loadPendingChanges();
			await loadLogs();
		}
	} catch (error) {
		console.error('Failed to approve change:', error);
		alert('Failed to approve change: ' + (error.response?.data?.error || error.message));
	}
}

async function rejectChange(changeId) {
	const reason = prompt('Reason for rejection (optional):') || 'Rejected by user';
	try {
		const response = await axios.post(api.agent.rejectChange(changeId), { reason });
		if (response.data.success) {
			// Reload pending changes
			await loadPendingChanges();
			await loadLogs();
		}
	} catch (error) {
		console.error('Failed to reject change:', error);
		alert('Failed to reject change: ' + (error.response?.data?.error || error.message));
	}
}

function startPolling() {
	stopPolling();
	
	// Load immediately
	loadStatus();
	loadLogs();
	loadPendingChanges();
	loadAppliedChanges();

	// Poll more frequently for better feedback
	statusPollInterval.value = setInterval(() => {
		loadStatus();
		loadPendingChanges();
		loadAppliedChanges();
	}, 1000); // Poll every 1 second for better responsiveness

	logsPollInterval.value = setInterval(() => {
		loadLogs();
	}, 2000); // Poll logs every 2 seconds
}

function stopPolling() {
	if (statusPollInterval.value) {
		clearInterval(statusPollInterval.value);
		statusPollInterval.value = null;
	}
	if (logsPollInterval.value) {
		clearInterval(logsPollInterval.value);
		logsPollInterval.value = null;
	}
}

// Watch for visibility
watch(() => props.visible, (newValue) => {
	if (newValue && sessionId.value) {
		startPolling();
	} else if (!newValue) {
		stopPolling();
	}
});

onMounted(() => {
	if (props.visible && sessionId.value) {
		startPolling();
	}
});

onUnmounted(() => {
	stopPolling();
});
</script>

<style scoped>
.terminal-agent {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-agent-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-agent-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-base, 14px);
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
}

.experimental-tag {
	font-size: 9px;
	font-weight: 700;
	padding: 3px 6px;
	background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
	color: #1e1e1e;
	border-radius: 4px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	line-height: 1;
}

.terminal-agent-config,
.terminal-agent-session {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.config-section {
	margin-bottom: 24px;
}

.config-section h4 {
	margin: 0 0 20px 0;
	font-size: var(--terminal-font-size-base, 14px);
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.config-field {
	margin-bottom: 16px;
}

.config-field label {
	display: block;
	margin-bottom: 4px;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.config-field-help {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	margin-bottom: 8px;
	margin-top: 4px;
}

.config-checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	margin-bottom: 8px;
}

/* Input Styles */
.terminal-input {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Courier New', monospace);
	font-size: var(--terminal-font-size-sm, 12px);
	line-height: 1.5;
	outline: none;
	transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
	box-sizing: border-box;
}

.terminal-input:focus {
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-secondary, #252526);
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent);
}

.terminal-input:hover:not(:disabled) {
	border-color: var(--terminal-border-hover, #464647);
}

.terminal-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-input::placeholder {
	color: var(--terminal-text-secondary, #858585);
	opacity: 0.7;
}

/* Checkbox Styles */
.terminal-checkbox {
	width: 18px;
	height: 18px;
	cursor: pointer;
	appearance: none;
	-webkit-appearance: none;
	-moz-appearance: none;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 3px;
	flex-shrink: 0;
	position: relative;
	transition: background-color 0.2s ease, border-color 0.2s ease;
	margin: 0;
}

.terminal-checkbox:checked {
	background: var(--terminal-primary, #0e639c);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-checkbox:checked::after {
	content: '✓';
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	color: white;
	font-size: 14px;
	font-weight: bold;
	line-height: 1;
}

.terminal-checkbox:focus {
	outline: 2px solid color-mix(in srgb, var(--terminal-primary, #0e639c) 30%, transparent);
	outline-offset: 2px;
}

.terminal-checkbox:hover:not(:disabled) {
	border-color: var(--terminal-border-hover, #464647);
}

.terminal-checkbox:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Button Styles */
.terminal-btn {
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	transition: all 0.2s ease;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	min-height: 36px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Courier New', monospace);
	text-decoration: none;
	white-space: nowrap;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
	border: 1px solid var(--terminal-primary, #0e639c);
	font-weight: 600;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
	box-shadow: 0 2px 4px color-mix(in srgb, var(--terminal-primary, #0e639c) 30%, transparent);
}

.terminal-btn-primary:active:not(:disabled) {
	transform: translateY(1px);
	box-shadow: 0 1px 2px color-mix(in srgb, var(--terminal-primary, #0e639c) 30%, transparent);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border-color: var(--terminal-border, #3e3e42);
	color: var(--terminal-text-secondary, #858585);
}

.config-actions {
	margin-top: 24px;
	padding-top: 20px;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.config-actions .terminal-btn {
	width: 100%;
	justify-content: center;
}

/* Unified Status Header */
.agent-status-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: linear-gradient(180deg, var(--terminal-bg-secondary, #252526) 0%, var(--terminal-bg, #1e1e1e) 100%);
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	gap: 16px;
}

.status-left {
	display: flex;
	align-items: center;
	gap: 12px;
}

.status-badge-wrapper {
	position: relative;
}

.status-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border-radius: 16px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.status-pulse {
	width: 8px;
	height: 8px;
	background: currentColor;
	border-radius: 50%;
	animation: status-pulse 1.5s ease-in-out infinite;
}

@keyframes status-pulse {
	0%, 100% { opacity: 1; transform: scale(1); }
	50% { opacity: 0.5; transform: scale(0.8); }
}

.status-pending {
	background: rgba(156, 163, 175, 0.2);
	color: #9ca3af;
	border: 1px solid rgba(156, 163, 175, 0.3);
}

.status-running {
	background: rgba(96, 165, 250, 0.2);
	color: #60a5fa;
	border: 1px solid rgba(96, 165, 250, 0.3);
}

.status-paused {
	background: rgba(245, 158, 11, 0.2);
	color: #d97706;
	border: 1px solid rgba(245, 158, 11, 0.3);
}

.status-completed {
	background: rgba(52, 211, 153, 0.2);
	color: #34d399;
	border: 1px solid rgba(52, 211, 153, 0.3);
}

.status-stopped,
.status-failed {
	background: rgba(248, 113, 113, 0.2);
	color: #f87171;
	border: 1px solid rgba(248, 113, 113, 0.3);
}

.status-iteration {
	font-size: 13px;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.iteration-max {
	font-weight: 400;
	color: var(--terminal-text-secondary, #858585);
}

/* Stats Cards */
.status-stats {
	display: flex;
	gap: 8px;
	flex: 1;
	justify-content: center;
}

.stat-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 6px 16px;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 8px;
	min-width: 60px;
}

.stat-card .stat-value {
	font-size: 18px;
	font-weight: 700;
	color: var(--terminal-text, #d4d4d4);
	line-height: 1.2;
}

.stat-card .stat-label {
	font-size: 9px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--terminal-text-secondary, #858585);
	margin-top: 2px;
}

.stat-card.stat-fixed .stat-value {
	color: #34d399;
}

.stat-card.stat-failed .stat-value {
	color: #f87171;
}

/* Action Buttons */
.status-actions {
	display: flex;
	gap: 6px;
}

.action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.action-btn:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.action-pause {
	background: rgba(245, 158, 11, 0.15);
	color: #d97706;
}

.action-pause:hover:not(:disabled) {
	background: rgba(245, 158, 11, 0.25);
}

.action-resume {
	background: rgba(52, 211, 153, 0.15);
	color: #34d399;
}

.action-resume:hover:not(:disabled) {
	background: rgba(52, 211, 153, 0.25);
}

.action-stop {
	background: rgba(248, 113, 113, 0.15);
	color: #f87171;
}

.action-stop:hover:not(:disabled) {
	background: rgba(248, 113, 113, 0.25);
}

/* Progress Bar */
.agent-progress-bar {
	height: 3px;
	background: var(--terminal-bg-tertiary, #3e3e42);
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	width: 30%;
	background: linear-gradient(90deg, #60a5fa, #3b82f6);
	border-radius: 2px;
}

.progress-fill.progress-animated {
	animation: progress-slide 1.5s ease-in-out infinite;
}

@keyframes progress-slide {
	0% { transform: translateX(-100%); width: 30%; }
	50% { width: 50%; }
	100% { transform: translateX(400%); width: 30%; }
}

/* Config Summary */
.config-summary {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 6px 12px;
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	font-size: 10px;
	color: var(--terminal-text-secondary, #858585);
}

.config-item {
	font-weight: 500;
}

.config-divider {
	opacity: 0.5;
}

.pending-changes-section,
.applied-changes-section {
	margin: 8px 0;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	background: var(--terminal-bg-secondary, #252526);
}

.applied-changes-section {
	border-left: 3px solid #10b981;
}

.applied-changes-summary {
	padding: 8px 12px;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
	cursor: pointer;
	user-select: none;
	display: flex;
	align-items: center;
	justify-content: space-between;
	list-style: none;
}

.applied-changes-summary::-webkit-details-marker {
	display: none;
}

.applied-changes-summary::before {
	content: '▶';
	display: inline-block;
	margin-right: 6px;
	transition: transform 0.2s;
}

.applied-changes-section[open] .applied-changes-summary::before {
	transform: rotate(90deg);
}

.applied-changes-badge {
	background: #10b981;
	color: white;
	padding: 2px 6px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
}

.applied-changes-list {
	padding: 8px 12px;
	font-size: var(--terminal-font-size-xs, 11px);
	border-top: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pending-changes-summary {
	padding: 8px 12px;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
	cursor: pointer;
	user-select: none;
	display: block;
	list-style: none;
}

.pending-changes-summary::-webkit-details-marker {
	display: none;
}

.pending-changes-summary::before {
	content: '▶';
	display: inline-block;
	margin-right: 6px;
	transition: transform 0.2s;
}

.pending-changes-section[open] .pending-changes-summary::before {
	transform: rotate(90deg);
}

.pending-changes-list {
	padding: 8px 12px;
	font-size: var(--terminal-font-size-xs, 11px);
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.pending-changes-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.agent-error {
	padding: 12px 16px;
	background: rgba(239, 68, 68, 0.1);
	border-left: 3px solid #ef4444;
	border-radius: 4px;
	margin: 16px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
}

.agent-error strong {
	color: #ef4444;
}

.spinner-small {
	display: inline-block;
	width: 12px;
	height: 12px;
	border: 2px solid rgba(255, 255, 255, 0.3);
	border-top-color: white;
	border-radius: 50%;
	animation: spin 0.6s linear infinite;
	margin-right: 6px;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}
</style>

