<template>
	<div v-if="visible" class="terminal-phpstan-results">
		<div v-if="!props.scanId" class="phpstan-no-scan">
			<p>No scan selected. Please start a Larastan analysis from the Configure tab.</p>
		</div>
		
		<div v-else-if="loading" class="phpstan-loading">
			<div class="phpstan-progress">
				<div class="phpstan-progress-bar" :style="{ width: progress + '%' }"></div>
			</div>
			<p>Running Larastan analysis... {{ progress }}%</p>
			<p v-if="statusMessage" class="phpstan-status">
				<span class="status-indicator">🔍</span>
				{{ statusMessage }}
			</p>
		</div>

		<div v-else-if="error" class="phpstan-error">
			<p class="error-message" v-html="formatErrorMessage(error)"></p>
		</div>

		<div v-else-if="results && results.summary" class="phpstan-content">
			<!-- Actions Bar -->
			<div class="phpstan-actions">
				<button
					@click="clearOldIssues"
					class="terminal-btn terminal-btn-secondary terminal-btn-sm"
					title="Clear old Larastan issues"
					:disabled="clearingIssues"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
					</svg>
					{{ clearingIssues ? 'Clearing...' : 'Clear Old Issues' }}
				</button>
			</div>
			
			<!-- Summary Section -->
			<div class="phpstan-summary">
				<h3>Larastan Analysis Summary</h3>
				<div class="phpstan-stats">
					<div class="stat-item">
						<span class="stat-label">Total Files:</span>
						<span class="stat-value">{{ results.summary?.total_files || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Files with Errors:</span>
						<span class="stat-value">{{ results.summary?.files_with_issues || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Total Errors:</span>
						<span class="stat-value">{{ results.summary?.total_issues || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Critical:</span>
						<span class="stat-value critical">{{ results.summary?.by_severity?.critical || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">High:</span>
						<span class="stat-value high">{{ results.summary?.by_severity?.high || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Medium:</span>
						<span class="stat-value medium">{{ results.summary?.by_severity?.medium || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Low:</span>
						<span class="stat-value low">{{ results.summary?.by_severity?.low || 0 }}</span>
					</div>
				</div>
			</div>

			<!-- Files List -->
			<div class="phpstan-files">
				<h3>Files Analyzed</h3>
				<div v-if="filteredFiles.length === 0" class="no-results">
					<p>No errors found! 🎉</p>
				</div>
				<div v-else class="files-list">
					<div
						v-for="file in filteredFiles"
						:key="file.file"
						class="file-item"
						:class="{ 'has-errors': file.issues && file.issues.length > 0 }"
					>
						<div class="file-header" @click="toggleFile(file.file)">
							<span class="file-name">{{ file.file }}</span>
							<span v-if="file.issues && file.issues.length > 0" class="issue-count">
								{{ file.issues.length }} error{{ file.issues.length !== 1 ? 's' : '' }}
							</span>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="expand-icon"
								:class="{ expanded: expandedFiles.includes(file.file) }"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
							</svg>
						</div>
						<div v-if="expandedFiles.includes(file.file)" class="file-details">
							<div v-if="file.issues && file.issues.length > 0" class="issues-section">
								<div
									v-for="(issue, index) in file.issues"
									:key="index"
									class="issue-item"
									:class="['severity-' + (issue.severity || 'medium'), { 'resolved': getIssueResolvedStatus(issue, file.file) }]"
								>
									<div class="issue-header">
										<span v-if="issue.rule" class="issue-rule">{{ issue.rule }}</span>
										<span v-if="issue.line" class="issue-line">Line {{ issue.line }}</span>
										<span class="issue-severity" :class="'severity-' + (issue.severity || 'medium')">
											{{ issue.severity || 'medium' }}
										</span>
										<span v-if="getIssueResolvedStatus(issue, file.file)" class="resolved-badge">
											Resolved
										</span>
										<div class="issue-actions">
											<button
												v-if="!getIssueResolvedStatus(issue, file.file)"
												@click.stop="resolveIssue(issue, file.file)"
												class="issue-action-btn issue-resolve-btn"
												title="Mark as resolved"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
												</svg>
											</button>
											<button
												v-else
												@click.stop="unresolveIssue(issue, file.file)"
												class="issue-action-btn issue-unresolve-btn"
												title="Mark as unresolved"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
												</svg>
											</button>
											<button
												@click.stop="createIssueFromPhpstan(issue, file.file)"
												class="issue-action-btn issue-create-btn"
												title="Create Issue"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
												</svg>
											</button>
										</div>
									</div>
									<div class="issue-message" v-html="formatIssueMessage(issue.message)"></div>
									<div v-if="issue.tip" class="issue-tip">
										<strong>Tip:</strong> {{ issue.tip }}
									</div>
								</div>
							</div>
							<div v-else class="no-issues">
								<p>No errors found in this file.</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import Swal from '../../utils/swalConfig';
import { useOverlordApi } from '../useOverlordApi';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	scanId: {
		type: String,
		default: null,
	},
});

const emit = defineEmits(['close', 'create-issue', 'issues-cleared']);

const api = useOverlordApi();

const loading = ref(false);
const error = ref(null);
const results = ref(null);
const progress = ref(0);
const statusMessage = ref('');
const expandedFiles = ref([]);
const pollingInterval = ref(null);
const databaseIssues = ref([]);
const loadingIssues = ref(false);
const clearingIssues = ref(false);

const filteredFiles = computed(() => {
	if (!results.value || !results.value.files) {
		return [];
	}
	return results.value.files;
});

function formatErrorMessage(errorText) {
	if (!errorText) return '';
	
	const div = document.createElement('div');
	div.textContent = errorText;
	return div.innerHTML;
}

function formatIssueMessage(message) {
	if (!message || typeof message !== 'string') {
		return '';
	}
	
	// Escape HTML first
	const div = document.createElement('div');
	div.textContent = message;
	let formatted = div.innerHTML;
	
	// Format inline code
	formatted = formatted.replace(/`([^`]+)`/g, '<code class="issue-inline-code">$1</code>');
	
	return formatted;
}

function toggleFile(filePath) {
	const index = expandedFiles.value.indexOf(filePath);
	if (index > -1) {
		expandedFiles.value.splice(index, 1);
	} else {
		expandedFiles.value.push(filePath);
	}
}

function createIssueFromPhpstan(issue, filePath) {
	const severityToPriority = {
		'critical': 'high',
		'high': 'high',
		'medium': 'medium',
		'low': 'low',
	};
	
	const priority = severityToPriority[issue.severity?.toLowerCase()] || 'medium';
	
	let title = `Larastan: ${issue.rule || 'Error'} in ${filePath}`;
	if (issue.line) {
		title += ` (Line ${issue.line})`;
	}
	
	let description = `**File:** ${filePath}\n`;
	if (issue.line) {
		description += `**Line:** ${issue.line}\n`;
	}
	if (issue.rule) {
		description += `**Rule:** ${issue.rule}\n`;
	}
	description += `**Severity:** ${issue.severity || 'medium'}\n\n`;
	description += `**Error:**\n${issue.message}`;
	if (issue.tip) {
		description += `\n\n**Tip:** ${issue.tip}`;
	}
	
	const sourceData = {
		file: filePath,
		line: issue.line || null,
		rule: issue.rule || null,
		severity: issue.severity || 'medium',
		message: issue.message,
		tip: issue.tip || null,
		scan_id: props.scanId,
	};
	
	emit('create-issue', {
		title: title,
		description: description,
		priority: priority,
		source_type: 'phpstan',
		source_id: props.scanId ? `phpstan_${props.scanId}_${filePath}_${issue.line || 'general'}` : null,
		source_data: sourceData,
	});
}

async function loadResults() {
	if (!props.scanId) {
		return;
	}

	let retries = 3;
	let lastError = null;

	while (retries > 0) {
		try {
			const response = await axios.get(api.phpstan.results(props.scanId));
			if (response.data && response.data.success) {
				const result = response.data.result;
				
				if (result && (result.summary || result.issues)) {
					results.value = result;
					loading.value = false;
					error.value = null;
					
					await loadDatabaseIssues();
					return;
				} else {
					lastError = 'Results not yet available';
				}
			} else {
				lastError = response.data.error || 'Failed to load results';
			}
		} catch (err) {
			console.error('Failed to load Larastan results:', err);
			lastError = err.response?.data?.error || 'Failed to load Larastan results';
			
			if (err.response?.status === 404) {
				break;
			}
		}
		
		retries--;
		if (retries > 0) {
			await new Promise(resolve => setTimeout(resolve, 1000 * (4 - retries)));
		}
	}

	error.value = lastError;
	loading.value = false;
}

async function loadDatabaseIssues() {
	if (loadingIssues.value) return;
	
	loadingIssues.value = true;
	try {
		const response = await axios.get(api.phpstan.issues({ scan_id: props.scanId }));
		if (response.data && response.data.success) {
			databaseIssues.value = response.data.result || [];
		}
	} catch (err) {
		console.error('Failed to load database issues:', err);
	} finally {
		loadingIssues.value = false;
	}
}

function getIssueResolvedStatus(issue, filePath) {
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.file_path === filePath &&
		dbIssue.line === (issue.line || null) &&
		dbIssue.message === issue.message &&
		(dbIssue.resolved === true || dbIssue.status === 'resolved')
	);
	return !!dbIssue;
}

function getDatabaseIssueId(issue, filePath) {
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.file_path === filePath &&
		dbIssue.line === (issue.line || null) &&
		dbIssue.message === issue.message
	);
	return dbIssue?.id || null;
}

async function resolveIssue(issue, filePath) {
	const issueId = getDatabaseIssueId(issue, filePath);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.phpstan.resolveIssue(issueId));
		if (response.data && response.data.success) {
			await loadDatabaseIssues();
		} else {
			console.error('Failed to resolve issue:', response.data?.error);
		}
	} catch (err) {
		console.error('Failed to resolve issue:', err);
	}
}

async function unresolveIssue(issue, filePath) {
	const issueId = getDatabaseIssueId(issue, filePath);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.phpstan.unresolveIssue(issueId));
		if (response.data && response.data.success) {
			await loadDatabaseIssues();
		} else {
			console.error('Failed to unresolve issue:', response.data?.error);
		}
	} catch (err) {
		console.error('Failed to unresolve issue:', err);
	}
}

async function clearOldIssues() {
	const result = await Swal.fire({
		icon: 'warning',
		title: 'Clear Old Issues?',
		html: 'This will delete all Larastan issues from the database. This action cannot be undone.',
		showCancelButton: true,
		confirmButtonText: 'Clear All',
		cancelButtonText: 'Cancel',
		confirmButtonColor: '#dc3545',
		cancelButtonColor: '#6c757d',
	});
	
	if (result.isConfirmed) {
		clearingIssues.value = true;
		try {
			const response = await axios.delete(api.phpstan.clearIssues());
			if (response.data && response.data.success) {
				Swal.fire({
					icon: 'success',
					title: 'Issues Cleared',
					text: `Deleted ${response.data.result.deleted_count} issue(s)`,
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 3000,
				});
				
				await loadDatabaseIssues();
				emit('issues-cleared');
			} else {
				Swal.fire({
					icon: 'error',
					title: 'Failed to clear issues',
					text: response.data?.error || 'Unknown error',
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 3000,
				});
			}
		} catch (err) {
			console.error('Failed to clear issues:', err);
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: err.response?.data?.error || 'Failed to clear issues',
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		} finally {
			clearingIssues.value = false;
		}
	}
}

async function checkStatus() {
	if (!props.scanId) {
		loading.value = false;
		return Promise.resolve();
	}

	try {
		const response = await axios.get(api.phpstan.status(props.scanId));
		if (response.data && response.data.success) {
			const status = response.data.result;
			progress.value = status.progress || 0;
			
			if (status.status === 'queued') {
				statusMessage.value = 'Larastan analysis queued, waiting to start...';
				loading.value = true;
				results.value = null;
			} else if (status.status === 'scanning') {
				loading.value = true;
				results.value = null;
				statusMessage.value = status.message || 'Running Larastan analysis...';
			} else if (status.status === 'completed') {
				stopPolling();
				loading.value = true;
				error.value = null;
				try {
					await loadResults();
				} catch (err) {
					console.error('Failed to load results after completion:', err);
					results.value = null;
					error.value = 'Failed to load Larastan results';
					loading.value = false;
				}
			} else if (status.status === 'failed') {
				loading.value = false;
				stopPolling();
				error.value = status.error || 'Larastan analysis failed';
				results.value = null;
			} else {
				loading.value = true;
				results.value = null;
			}
		} else {
			console.error('Failed to get Larastan scan status:', response.data);
			error.value = response.data?.error || 'Failed to get scan status';
			loading.value = false;
			results.value = null;
		}
	} catch (err) {
		console.error('Failed to check Larastan scan status:', err);
		if (err.response?.status === 404) {
			error.value = 'Scan not found. The scan may have been deleted or never existed.';
			loading.value = false;
			results.value = null;
			stopPolling();
			return;
		}
		if (!loading.value) {
			loading.value = true;
		}
	}
}

function startPolling() {
	if (pollingInterval.value) {
		clearInterval(pollingInterval.value);
	}
	
	pollingInterval.value = setInterval(() => {
		if (!props.visible || !props.scanId) {
			stopPolling();
			return;
		}
		checkStatus();
	}, 2500);
}

function stopPolling() {
	if (pollingInterval.value) {
		clearInterval(pollingInterval.value);
		pollingInterval.value = null;
	}
}

watch(() => props.scanId, (newScanId, oldScanId) => {
	if (newScanId && newScanId !== oldScanId) {
		stopPolling();
		
		loading.value = true;
		error.value = null;
		results.value = null;
		progress.value = 0;
		statusMessage.value = 'Initializing Larastan analysis...';
		expandedFiles.value = [];
		
		checkStatus().then(() => {
			if (props.visible && !error.value) {
				startPolling();
			}
		}).catch(() => {
		});
	} else if (!newScanId) {
		stopPolling();
		loading.value = false;
		error.value = null;
		results.value = null;
		progress.value = 0;
		statusMessage.value = '';
		expandedFiles.value = [];
	}
}, { immediate: true });

watch(() => props.visible, (newVisible) => {
	if (newVisible && props.scanId) {
		if (!results.value && !error.value) {
			loading.value = true;
			error.value = null;
		}
		if (!error.value) {
			checkStatus().then(() => {
				if (!error.value) {
					startPolling();
				}
			}).catch(() => {
			});
		}
	} else {
		stopPolling();
	}
});

onMounted(() => {
	if (props.visible && props.scanId && loading.value) {
		startPolling();
	}
});

onUnmounted(() => {
	stopPolling();
});
</script>

<style scoped>
.terminal-phpstan-results {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	padding: 1rem;
	overflow-y: auto;
	z-index: 10002;
	pointer-events: auto;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-phpstan-results::-webkit-scrollbar {
	width: 10px;
}

.terminal-phpstan-results::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-phpstan-results::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-phpstan-results::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.phpstan-no-scan {
	text-align: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.phpstan-loading {
	text-align: center;
	padding: 2rem;
}

.phpstan-progress {
	width: 100%;
	height: 20px;
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 1rem;
}

.phpstan-progress-bar {
	height: 100%;
	background-color: var(--terminal-primary, #0e639c);
	transition: width 0.3s ease;
}

.phpstan-status {
	margin-top: 0.5rem;
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.9rem;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
}

.status-indicator {
	display: inline-block;
	font-size: 1.1rem;
	line-height: 1;
	animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
	0%, 100% {
		opacity: 1;
		transform: scale(1);
	}
	50% {
		opacity: 0.6;
		transform: scale(0.95);
	}
}

.phpstan-error {
	padding: 2rem;
	text-align: center;
}

.error-message {
	color: var(--terminal-error, #ef4444);
}

.phpstan-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.phpstan-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

/* Button Styles */
.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	font-weight: 500;
	transition: all 0.2s;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	min-height: 32px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	text-decoration: none;
	white-space: nowrap;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-border-hover, #464647);
	border-color: var(--terminal-border-hover, #464647);
}

.terminal-btn-secondary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-sm {
	padding: 4px 10px;
	font-size: 11px;
	min-height: 28px;
}

.terminal-btn svg {
	width: 16px;
	height: 16px;
	flex-shrink: 0;
}

.phpstan-summary {
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	padding: 1.5rem;
}

.phpstan-summary h3 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--terminal-text, #333333);
}

.phpstan-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.stat-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.stat-label {
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.stat-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--terminal-text, #333333);
}

.stat-value.critical {
	color: #ef4444;
}

.stat-value.high {
	color: #f59e0b;
}

.stat-value.medium {
	color: #3b82f6;
}

.stat-value.low {
	color: #10b981;
}

.phpstan-files h3 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--terminal-text, #333333);
}

.no-results {
	padding: 2rem;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.files-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.file-item {
	background-color: var(--terminal-bg-tertiary, #f9f9f9);
	border-radius: 6px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	overflow: hidden;
}

.file-item.has-errors {
	border-color: var(--terminal-warning, #f59e0b);
}

.file-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 1rem;
	cursor: pointer;
	user-select: none;
	transition: background-color 0.2s;
}

.file-header:hover {
	background-color: var(--terminal-bg-secondary, #e8e8e8);
}

.file-name {
	flex: 1;
	color: var(--terminal-text, #333333);
	font-family: 'Courier New', monospace;
	font-size: 0.9rem;
}

.issue-count {
	background-color: #f59e0b;
	color: #000;
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
}

.expand-icon {
	width: 20px;
	height: 20px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
}

.expand-icon.expanded {
	transform: rotate(180deg);
}

.file-details {
	padding: 0 1rem 1rem 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
	margin-top: 0.5rem;
	padding-top: 1rem;
}

.issues-section {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.issue-item {
	background-color: var(--terminal-bg, #1e1e1e);
	border-left: 3px solid;
	border-radius: 4px;
	padding: 0.75rem;
}

.issue-item.severity-critical {
	border-left-color: #ef4444;
}

.issue-item.severity-high {
	border-left-color: #f59e0b;
}

.issue-item.severity-medium {
	border-left-color: #3b82f6;
}

.issue-item.severity-low {
	border-left-color: #10b981;
}

.issue-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
	flex-wrap: wrap;
	width: 100%;
}

.issue-rule {
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-family: 'Courier New', monospace;
	color: var(--terminal-text, #333333);
}

.issue-line {
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.875rem;
	font-family: 'Courier New', monospace;
}

.issue-severity {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
	text-transform: uppercase;
}

.issue-severity.severity-critical {
	background-color: #ef4444;
	color: #fff;
}

.issue-severity.severity-high {
	background-color: #f59e0b;
	color: #000;
}

.issue-severity.severity-medium {
	background-color: #3b82f6;
	color: #fff;
}

.issue-severity.severity-low {
	background-color: #10b981;
	color: #000;
}

.issue-message {
	color: var(--terminal-text, #333333);
	font-size: 0.9rem;
	line-height: 1.6;
	margin-bottom: 0.5rem;
}

.issue-message .issue-inline-code {
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 3px;
	padding: 0.15rem 0.35rem;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 0.85em;
	color: var(--terminal-text, #333333);
}

.issue-tip {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border-left: 3px solid var(--terminal-info, #4ec9b0);
	border-radius: 4px;
	font-size: 0.85rem;
	color: var(--terminal-text-secondary, #858585);
}

.issue-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-left: auto;
}

.issue-action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0.4rem;
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
	width: 28px;
	height: 28px;
}

.issue-action-btn:hover {
	background-color: var(--terminal-bg-secondary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
	color: var(--terminal-text, #333333);
}

.issue-action-btn svg {
	width: 16px;
	height: 16px;
}

.issue-resolve-btn {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-success, #10b981) 40%, transparent);
	color: var(--terminal-success, #10b981);
}

.issue-resolve-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-success, #10b981) 60%, transparent);
}

.issue-unresolve-btn {
	background-color: color-mix(in srgb, var(--terminal-error, #ef4444) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-error, #ef4444) 40%, transparent);
	color: var(--terminal-error, #ef4444);
}

.issue-unresolve-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-error, #ef4444) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-error, #ef4444) 60%, transparent);
}

.issue-create-btn {
	background-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 40%, transparent);
	color: var(--terminal-primary, #0e639c);
}

.issue-create-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 60%, transparent);
}

.resolved-badge {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	color: var(--terminal-success, #10b981);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
}

.issue-item.resolved {
	opacity: 0.6;
}

.issue-item.resolved .issue-message {
	text-decoration: line-through;
}

.no-issues {
	padding: 1rem;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}
</style>

