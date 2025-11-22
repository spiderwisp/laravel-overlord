<template>
	<div v-if="visible" class="terminal-agent">
		<div class="terminal-agent-header">
			<h3>AI Agent</h3>
			<button @click="emit('close')" class="terminal-btn terminal-btn-close" title="Close">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
					<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<!-- Configuration Panel (only when no session exists and not starting) -->
		<div v-if="!sessionId && !starting" class="terminal-agent-config">
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

		<!-- Active Session View (show for any session, including completed ones) -->
		<Transition name="fade">
			<div v-if="sessionId || starting" class="terminal-agent-session">
				<!-- Error Message -->
				<Transition name="slide-down">
					<div v-if="errorMessage" class="agent-error">
						<strong>Error:</strong> {{ errorMessage }}
					</div>
				</Transition>

				<!-- Compact Status Bar -->
				<div class="agent-status-bar">
					<div class="status-info">
						<span class="status-badge" :class="statusClass">{{ statusText }}</span>
						<details class="status-details-collapsible">
							<summary class="status-summary">
								<span class="status-summary-text">
									{{ currentIteration }}/{{ maxIterations }} iter
									· {{ totalIssuesFound }} found
									· {{ totalIssuesFixed }} fixed
									<span v-if="status === 'running'" class="status-indicator status-active">●</span>
									<span v-else-if="status === 'paused'" class="status-indicator status-warning">●</span>
									<span v-else-if="status === 'completed'" class="status-indicator status-success">●</span>
								</span>
								<svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
								</svg>
							</summary>
							<div class="status-details-expanded">
								<div class="status-detail-item">
									<span class="status-detail-label">Iteration:</span>
									<span>{{ currentIteration }} / {{ maxIterations }}</span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Issues Found:</span>
									<span>{{ totalIssuesFound }}</span>
								</div>
								<div class="status-detail-item">
									<span class="status-detail-label">Issues Fixed:</span>
									<span>{{ totalIssuesFixed }}</span>
								</div>
								<div v-if="status === 'pending' && currentIteration === 0" class="status-detail-item status-warning">
									Waiting to start...
								</div>
								<div v-if="status === 'pending' && currentIteration > 0" class="status-detail-item status-warning">
									Paused or stuck - try Retry Start
								</div>
								<div v-if="status === 'paused'" class="status-detail-item status-warning">
									Paused - click Resume to continue
								</div>
								<div v-if="status === 'completed'" class="status-detail-item status-success">
									All issues resolved!
								</div>
							</div>
						</details>
					</div>
					<div class="status-actions">
						<button
							v-if="status === 'pending' && currentIteration === 0"
							@click="retryStart"
							class="terminal-btn terminal-btn-primary terminal-btn-xs"
							:disabled="retrying"
							title="Retry starting the agent"
						>
							<span v-if="retrying" class="spinner-small"></span>
							{{ retrying ? 'Retrying...' : 'Retry' }}
						</button>
						<button
							v-if="status === 'running'"
							@click="pauseAgent"
							class="terminal-btn terminal-btn-secondary terminal-btn-xs"
							:disabled="pausing"
						>
							Pause
						</button>
						<button
							v-if="status === 'paused'"
							@click="resumeAgent"
							class="terminal-btn terminal-btn-primary terminal-btn-xs"
							:disabled="resuming"
						>
							Resume
						</button>
						<button
							v-if="canStop"
							@click="stopAgent"
							class="terminal-btn terminal-btn-danger terminal-btn-xs"
							:disabled="stopping"
						>
							Stop
						</button>
						<button
							v-if="status === 'completed' || status === 'stopped' || status === 'failed'"
							@click="sessionId = null; status = 'pending'; errorMessage = null; logs = []; pendingChanges = []; stopPolling();"
							class="terminal-btn terminal-btn-secondary terminal-btn-xs"
							title="Start a new session"
						>
							New
						</button>
					</div>
				</div>

				<!-- Compact Progress Bar -->
				<Transition name="slide-down">
					<div v-if="status === 'running' || status === 'completed'" class="agent-progress">
						<div class="progress-bar" :style="{ width: progressPercentage + '%' }"></div>
					</div>
				</Transition>

				<!-- Logs Viewer -->
				<AgentLogViewer
					:session-id="sessionId"
					:logs="logs"
					@load-more="() => { loadingMore = true; loadMoreLogs(); }"
				/>

				<!-- Pending Changes (Review Mode) -->
				<Transition name="slide-up">
					<div v-if="!autoApply && pendingChanges.length > 0" class="pending-changes-section">
						<details class="pending-changes-header" open>
							<summary class="pending-changes-summary">
								<span>Pending Changes ({{ pendingChanges.length }})</span>
								<svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
								</svg>
							</summary>
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
					</div>
				</Transition>
			</div>
		</Transition>
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
const logs = ref([]);
const pendingChanges = ref([]);
const starting = ref(false);
const errorMessage = ref(null);
const pausing = ref(false);
const resuming = ref(false);
const stopping = ref(false);
const retrying = ref(false);
const statusPollInterval = ref(null);
const logsPollInterval = ref(null);
const isStartingNewSession = ref(false);
const loadingMore = ref(false);

const config = ref({
	larastan_level: 1,
	max_iterations: 50,
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
	isStartingNewSession.value = true; // Prevent loadActiveSession from interfering
	
	// Clear previous session state if it's completed/stopped/failed
	if (sessionId.value && ['stopped', 'completed', 'failed'].includes(status.value)) {
		sessionId.value = null;
		status.value = 'pending';
		errorMessage.value = null;
		logs.value = [];
		pendingChanges.value = [];
	}
	
	try {
		const response = await axios.post(api.agent.start(), {
			larastan_level: config.value.larastan_level,
			max_iterations: config.value.max_iterations,
			auto_apply: config.value.auto_apply,
		});

		if (response.data.success) {
			// Set the new session immediately - don't let anything override this
			const newSessionId = response.data.result.session_id;
			sessionId.value = newSessionId;
			status.value = response.data.result.status || 'pending';
			errorMessage.value = null;
			logs.value = [];
			pendingChanges.value = [];
			currentIteration.value = response.data.result.current_iteration || 0;
			maxIterations.value = response.data.result.max_iterations || config.value.max_iterations;
			totalIssuesFound.value = response.data.result.total_issues_found || 0;
			totalIssuesFixed.value = response.data.result.total_issues_fixed || 0;
			
			console.log('New session created:', newSessionId, 'Status:', status.value);
			
			// Load status immediately (bypass isStartingNewSession flag for initial load)
			// This ensures the UI shows the session right away
			loadStatus(true).then(() => {
				console.log('Initial status loaded for session:', newSessionId);
			}).catch(err => {
				console.error('Failed to load initial status:', err);
			});
			
			// Start polling immediately - this will continue to update status and logs
			startPolling();
		} else {
			errorMessage.value = response.data.error || 'Unknown error';
			
			// If there's an existing ACTIVE session, offer to load it
			if (response.data.existing_session && 
				['pending', 'running', 'paused'].includes(response.data.existing_session.status)) {
				const loadExisting = confirm(
					'You have an active session (ID: ' + response.data.existing_session.id + 
					', Status: ' + response.data.existing_session.status + 
					'). Would you like to load it?'
				);
				if (loadExisting) {
					sessionId.value = response.data.existing_session.id;
					await loadStatus();
					startPolling();
				}
			}
		}
	} catch (error) {
		console.error('Failed to start agent:', error);
		errorMessage.value = error.response?.data?.error || error.message || 'Failed to start agent';
	} finally {
		starting.value = false;
		// Clear the flag after a short delay to allow the session to be established
		// This prevents loadActiveSession and loadStatus from interfering
		setTimeout(() => {
			isStartingNewSession.value = false;
			console.log('isStartingNewSession flag cleared, polling can now update status');
		}, 3000); // Increased to 3 seconds to ensure session is fully established
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

async function retryStart() {
	if (retrying.value || !sessionId.value) return;

	retrying.value = true;
	try {
		// First, try to resume if paused
		if (status.value === 'paused') {
			await resumeAgent();
		} else {
			// If pending, try to resume (which will restart the job)
			const response = await axios.post(api.agent.resume(sessionId.value));
			if (response.data.success) {
				status.value = response.data.result.status;
				errorMessage.value = null;
				startPolling();
			} else {
				errorMessage.value = response.data.error || 'Failed to retry';
			}
		}
	} catch (error) {
		console.error('Failed to retry start:', error);
		errorMessage.value = error.response?.data?.error || error.message || 'Failed to retry start';
	} finally {
		retrying.value = false;
	}
}

async function loadStatus(force = false) {
	if (!sessionId.value) {
		console.log('loadStatus: No sessionId, skipping');
		return;
	}

	// Don't load status if we're starting a new session (prevent race condition)
	// Unless force=true (for initial load after session creation)
	if (isStartingNewSession.value && !force) {
		console.log('loadStatus: Starting new session, skipping load');
		return;
	}

	try {
		const currentSessionId = sessionId.value; // Capture current session ID
		const response = await axios.get(api.agent.status(currentSessionId));
		
		// Check if sessionId changed while we were loading (user started new session)
		if (sessionId.value !== currentSessionId) {
			console.log('loadStatus: Session ID changed during load, ignoring result');
			return;
		}
		
		if (response.data.success) {
			const result = response.data.result;
			const oldStatus = status.value;
			
			// Only update if we still have the same session
			if (sessionId.value === currentSessionId) {
				status.value = result.status;
				currentIteration.value = result.current_iteration;
				maxIterations.value = result.max_iterations;
				totalIssuesFound.value = result.total_issues_found;
				totalIssuesFixed.value = result.total_issues_fixed;
				
				// Update config from session
				if (result.auto_apply !== undefined) {
					config.value.auto_apply = result.auto_apply;
				}

				// Log status changes
				if (oldStatus !== result.status) {
					console.log('Agent status changed:', oldStatus, '->', result.status, 'Session:', currentSessionId);
				}

				// Check if session has been pending too long (likely stuck)
				if (result.status === 'pending' && currentIteration.value === 0) {
					const sessionAge = new Date() - new Date(result.created_at);
					const minutesOld = sessionAge / 1000 / 60;
					
					if (minutesOld > 2) {
						// Session has been pending for more than 2 minutes, likely stuck
						if (!errorMessage.value || !errorMessage.value.includes('stuck')) {
							errorMessage.value = 'Session appears to be stuck in pending status. Try clicking "Retry Start" or stop and start a new session.';
						}
					}
				}

				// Show error message if failed
				if (result.status === 'failed' && result.error_message) {
					errorMessage.value = result.error_message;
				} else if (result.status !== 'failed') {
					errorMessage.value = null;
				}

			// Stop polling if stopped/failed (but keep polling for completed to show final state)
			if (['stopped', 'failed'].includes(result.status)) {
				stopPolling();
			} else if (result.status === 'completed') {
				// For completed, stop polling after a short delay to show final state
				setTimeout(() => {
					stopPolling();
				}, 2000);
			}
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

// Helper function to sort logs by created_at then id (oldest first, stable)
function sortLogs(logsArray) {
	return [...logsArray].sort((a, b) => {
		const dateA = new Date(a.created_at || 0);
		const dateB = new Date(b.created_at || 0);
		if (dateA.getTime() === dateB.getTime()) {
			// If timestamps are equal, use ID as tiebreaker for stability
			return (a.id || 0) - (b.id || 0);
		}
		return dateA - dateB;
	});
}

async function loadLogs() {
	if (!sessionId.value) return;

	try {
		const response = await axios.get(api.agent.logs(sessionId.value, {
			limit: 50,
			offset: 0,
		}));

		if (response.data.success) {
			const newLogs = response.data.result.logs || [];
			
			// Backend now returns logs in ASC order (oldest first)
			// If we have no logs yet, just set them
			if (logs.value.length === 0) {
				logs.value = newLogs;
			} else {
				// Merge: add only new logs that don't exist yet (newer logs at the end)
				const existingIds = new Set(logs.value.map(log => log.id));
				const newLogsToAdd = newLogs.filter(log => !existingIds.has(log.id));
				
				if (newLogsToAdd.length > 0) {
					// Append new logs and re-sort to maintain order
					logs.value = sortLogs([...logs.value, ...newLogsToAdd]);
				}
			}
		}
	} catch (error) {
		console.error('Failed to load logs:', error);
	}
}

async function loadMoreLogs() {
	if (!sessionId.value || loadingMore.value) return;

	loadingMore.value = true;

	try {
		// Load older logs (before the current oldest log)
		const response = await axios.get(api.agent.logs(sessionId.value, {
			limit: 50,
			offset: logs.value.length,
		}));

		if (response.data.success && response.data.result.logs.length > 0) {
			const olderLogs = response.data.result.logs || [];
			
			// Filter out logs we already have
			const existingIds = new Set(logs.value.map(log => log.id));
			const newOlderLogs = olderLogs.filter(log => !existingIds.has(log.id));
			
			if (newOlderLogs.length > 0) {
				// Prepend older logs to the beginning and re-sort
				logs.value = sortLogs([...newOlderLogs, ...logs.value]);
			}
		}
	} catch (error) {
		console.error('Failed to load more logs:', error);
	} finally {
		loadingMore.value = false;
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
	
	if (!sessionId.value) {
		console.log('startPolling: No sessionId, cannot start polling');
		return;
	}
	
	console.log('startPolling: Starting for session', sessionId.value);
	
	// Load logs and pending changes immediately (these don't interfere with session creation)
	loadLogs();
	loadPendingChanges();
	
	// Load status immediately only if not starting a new session
	// (If starting new session, loadStatus was already called in startAgent)
	if (!isStartingNewSession.value) {
		loadStatus();
	}

	// Poll more frequently for better feedback
	statusPollInterval.value = setInterval(() => {
		if (sessionId.value && !isStartingNewSession.value) {
			loadStatus();
		}
		loadPendingChanges();
	}, 1000); // Poll every 1 second for better responsiveness

	logsPollInterval.value = setInterval(() => {
		if (sessionId.value) {
			loadLogs();
		}
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
	if (newValue) {
		if (sessionId.value) {
			startPolling();
		} else if (!isStartingNewSession.value) {
			// Try to load existing active session when pane opens
			// But only if we're not in the process of starting a new one
			loadActiveSession();
		}
	} else if (!newValue) {
		stopPolling();
	}
});

// Load active session on mount
async function loadActiveSession() {
	// Don't load if we're in the process of starting a new session
	if (isStartingNewSession.value) {
		return;
	}
	
	// Don't load if we already have a session
	if (sessionId.value) {
		return;
	}
	
	try {
		const response = await axios.get(api.agent.active());
		if (response.data.success && response.data.result) {
			const activeSession = response.data.result;
			
			// Only load if it's actually active (not completed/stopped/failed)
			if (['pending', 'running', 'paused'].includes(activeSession.status)) {
				sessionId.value = activeSession.session_id;
				status.value = activeSession.status;
				currentIteration.value = activeSession.current_iteration;
				maxIterations.value = activeSession.max_iterations;
				totalIssuesFound.value = activeSession.total_issues_found;
				totalIssuesFixed.value = activeSession.total_issues_fixed;
				config.value.larastan_level = activeSession.larastan_level;
				config.value.auto_apply = activeSession.auto_apply;
				config.value.max_iterations = activeSession.max_iterations;
				
				if (activeSession.error_message) {
					errorMessage.value = activeSession.error_message;
				}
				
				// Start polling for active sessions
				startPolling();
			} else {
				// Session is completed/stopped/failed - show it but allow new session
				sessionId.value = activeSession.session_id;
				status.value = activeSession.status;
				currentIteration.value = activeSession.current_iteration;
				maxIterations.value = activeSession.max_iterations;
				totalIssuesFound.value = activeSession.total_issues_found;
				totalIssuesFixed.value = activeSession.total_issues_fixed;
				config.value.larastan_level = activeSession.larastan_level;
				config.value.auto_apply = activeSession.auto_apply;
				config.value.max_iterations = activeSession.max_iterations;
				
				if (activeSession.error_message) {
					errorMessage.value = activeSession.error_message;
				}
			}
		}
	} catch (error) {
		console.error('Failed to load active session:', error);
	}
}

onMounted(() => {
	if (props.visible) {
		if (sessionId.value) {
			startPolling();
		} else if (!isStartingNewSession.value) {
			// Try to load existing active session
			// But only if we're not in the process of starting a new one
			loadActiveSession();
		}
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
	padding: 8px 10px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-agent-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
}

.terminal-agent-config,
.terminal-agent-session {
	flex: 1;
	overflow-y: auto;
	padding: 10px;
}

.config-section {
	margin-bottom: 24px;
}

.config-section h4 {
	margin: 0 0 16px 0;
	font-size: var(--terminal-font-size-base, 14px);
	font-weight: 600;
}

.config-field {
	margin-bottom: 16px;
}

.config-field label {
	display: block;
	margin-bottom: 4px;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
}

.config-field-help {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	margin-bottom: 8px;
}

.config-checkbox-label {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
}

.agent-status-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 10px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.status-info {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	min-width: 0;
}

.status-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
}

.status-pending {
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text-secondary, #858585);
}

.status-running {
	background: #0e639c;
	color: white;
}

.status-paused {
	background: #f59e0b;
	color: white;
}

.status-completed {
	background: #10b981;
	color: white;
}

.status-stopped,
.status-failed {
	background: #ef4444;
	color: white;
}

.status-details-collapsible {
	flex: 1;
	min-width: 0;
}

.status-details-collapsible summary {
	list-style: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
	user-select: none;
}

.status-details-collapsible summary::-webkit-details-marker {
	display: none;
}

.status-summary {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
	min-width: 0;
}

.status-summary-text {
	font-size: 11px;
	color: var(--terminal-text-secondary, #858585);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.status-indicator {
	display: inline-block;
	font-size: 8px;
	vertical-align: middle;
	margin-left: 4px;
}

.chevron-icon {
	width: 12px;
	height: 12px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s ease;
	flex-shrink: 0;
}

.status-details-collapsible[open] .chevron-icon {
	transform: rotate(180deg);
}

.status-details-expanded {
	padding: 6px 0 0 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.status-detail-item {
	font-size: 11px;
	color: var(--terminal-text-secondary, #858585);
	display: flex;
	gap: 8px;
}

.status-detail-label {
	font-weight: 500;
	min-width: 80px;
}

.status-warning {
	color: #f59e0b;
	font-weight: 600;
}

.status-active {
	color: #10b981;
	font-weight: 600;
	animation: pulse 2s infinite;
}

.status-success {
	color: #10b981;
	font-weight: 600;
}

@keyframes pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.6; }
}

.status-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.terminal-btn-xs {
	padding: 3px 8px;
	font-size: 10px;
	line-height: 1.4;
}

.agent-progress {
	height: 2px;
	background: var(--terminal-bg-tertiary, #3e3e42);
	overflow: hidden;
}

.progress-bar {
	height: 100%;
	background: linear-gradient(90deg, var(--terminal-primary, #0e639c), #1a8cd8);
	transition: width 0.3s ease;
	box-shadow: 0 0 4px rgba(14, 99, 156, 0.5);
}

.pending-changes-section {
	padding: 8px 10px;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.pending-changes-header {
	width: 100%;
}

.pending-changes-summary {
	list-style: none;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 4px 0;
	font-size: 11px;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	user-select: none;
}

.pending-changes-summary::-webkit-details-marker {
	display: none;
}

.pending-changes-header[open] .chevron-icon {
	transform: rotate(180deg);
}

.pending-changes-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 6px;
}

.previous-session-info {
	margin-top: 16px;
	padding: 12px;
	background: var(--terminal-bg-secondary, #252526);
	border-radius: 4px;
	border-left: 3px solid var(--terminal-primary, #0e639c);
}

.previous-session-info p {
	margin: 0 0 8px 0;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
}

.previous-session-info .session-note {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	font-style: italic;
	margin-top: 4px;
}

.agent-error {
	padding: 6px 10px;
	background: rgba(239, 68, 68, 0.1);
	border-left: 2px solid #ef4444;
	border-radius: 3px;
	margin: 6px 10px;
	color: var(--terminal-text, #d4d4d4);
	font-size: 11px;
	line-height: 1.4;
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}

.slide-down-enter-active,
.slide-down-leave-active {
	transition: all 0.2s ease;
}

.slide-down-enter-from {
	opacity: 0;
	transform: translateY(-4px);
}

.slide-down-leave-to {
	opacity: 0;
	transform: translateY(-4px);
}

.slide-up-enter-active,
.slide-up-leave-active {
	transition: all 0.2s ease;
}

.slide-up-enter-from {
	opacity: 0;
	transform: translateY(4px);
}

.slide-up-leave-to {
	opacity: 0;
	transform: translateY(4px);
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

