<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import Swal from '../../utils/swalConfig';
import { useOverlordApi } from '../useOverlordApi';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

const loadingStats = ref(false);
const loadingJobs = ref(false);
const stats = ref(null);
const jobs = ref([]);
const selectedJob = ref(null);
const activeTab = ref('stats'); // 'stats', 'pending', 'completed', 'silenced', 'failed'
const searchQuery = ref('');
const currentPage = ref(1);
const perPage = ref(50);
const totalPages = ref(1);
const totalJobs = ref(0);
const selectedQueue = ref('');
const showCreateJobModal = ref(false);
const creatingJob = ref(false);
const newJobForm = ref({
	job_class: '',
	job_data: '',
	queue: 'default',
});

// Load Horizon statistics
async function loadStats() {
	if (loadingStats.value) return;
	
	loadingStats.value = true;
	try {
		const response = await axios.get(api.horizon.stats());
		if (response.data && response.data.success && response.data.result) {
			stats.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load Horizon stats:', error);
		stats.value = null;
	} finally {
		loadingStats.value = false;
	}
}

// Load Horizon jobs
async function loadJobs() {
	if (loadingJobs.value) return;
	
	loadingJobs.value = true;
	try {
		const params = {
			type: activeTab.value === 'stats' ? 'pending' : activeTab.value,
			page: currentPage.value,
			per_page: perPage.value,
		};
		
		if (searchQuery.value.trim()) {
			params.search = searchQuery.value.trim();
		}
		
		if (selectedQueue.value) {
			params.queue = selectedQueue.value;
		}
		
		const response = await axios.get(api.horizon.jobs(params));
		if (response.data && response.data.success && response.data.result) {
			jobs.value = response.data.result.jobs || [];
			totalJobs.value = response.data.result.total || 0;
			totalPages.value = response.data.result.total_pages || 1;
		} else {
			jobs.value = [];
		}
	} catch (error) {
		console.error('Failed to load Horizon jobs:', error);
		jobs.value = [];
	} finally {
		loadingJobs.value = false;
	}
}

// Load job details
async function loadJobDetails(jobId) {
	try {
		const response = await axios.get(api.horizon.jobDetails(jobId));
		if (response.data && response.data.success && response.data.result) {
			selectedJob.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load job details:', error);
		selectedJob.value = null;
	}
}

// Select job
function selectJob(job) {
	const jobId = job.id || job.uuid || job.job_id;
	if (jobId) {
		loadJobDetails(jobId);
	}
}

// Close job details
function closeJobDetails() {
	selectedJob.value = null;
}

// Retry a failed job
async function retryJob(jobId) {
	try {
		const response = await axios.post(api.horizon.retryJob(jobId));
		if (response.data && response.data.success) {
			Swal.fire({
				icon: 'success',
				title: 'Job Retried',
				text: response.data.result?.message || 'Job has been retried successfully',
				timer: 2000,
				showConfirmButton: false,
			});
			// Reload jobs list
			loadJobs();
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data?.errors?.[0] || 'Failed to retry job',
			});
		}
	} catch (error) {
		console.error('Failed to retry job:', error);
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || error.message || 'Failed to retry job',
		});
	}
}

// Delete a job
async function deleteJob(jobId) {
	try {
		const result = await Swal.fire({
			title: 'Delete Job?',
			text: 'Are you sure you want to delete this job? This action cannot be undone.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Yes, delete it',
		});
		
		if (!result.isConfirmed) {
			return;
		}
		
		const response = await axios.delete(api.horizon.deleteJob(jobId));
		if (response.data && response.data.success) {
			Swal.fire({
				icon: 'success',
				title: 'Job Deleted',
				text: response.data.result?.message || 'Job has been deleted successfully',
				timer: 2000,
				showConfirmButton: false,
			});
			// Close job details if it was the deleted job
			if (selectedJob.value && (selectedJob.value.id === jobId || selectedJob.value.uuid === jobId)) {
				closeJobDetails();
			}
			// Reload jobs list
			loadJobs();
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data?.errors?.[0] || 'Failed to delete job',
			});
		}
	} catch (error) {
		console.error('Failed to delete job:', error);
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || error.message || 'Failed to delete job',
		});
	}
}

// Execute a job (re-dispatch)
async function executeJob(jobId) {
	try {
		const response = await axios.post(api.horizon.executeJob(jobId));
		if (response.data && response.data.success) {
			Swal.fire({
				icon: 'success',
				title: 'Job Executed',
				text: response.data.result?.message || 'Job has been dispatched successfully',
				timer: 2000,
				showConfirmButton: false,
			});
			// Reload jobs list
			loadJobs();
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data?.errors?.[0] || 'Failed to execute job',
			});
		}
	} catch (error) {
		console.error('Failed to execute job:', error);
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || error.message || 'Failed to execute job',
		});
	}
}

// Check if job can be retried (must be failed)
function canRetryJob(job) {
	return job.status === 'failed' || activeTab.value === 'failed';
}

// Check if job can be executed (has payload data)
function canExecuteJob(job) {
	return job.payload && !job.payload.displayName?.includes('cleaned up');
}

// Check if job can be deleted
function canDeleteJob(job) {
	return true; // All jobs can be deleted
}

// Create a new job
async function createJob() {
	if (!newJobForm.value.job_class.trim()) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: 'Job class is required',
		});
		return;
	}
	
	creatingJob.value = true;
	try {
		// Parse job data if provided
		let jobData = [];
		if (newJobForm.value.job_data.trim()) {
			try {
				jobData = JSON.parse(newJobForm.value.job_data);
				if (!Array.isArray(jobData)) {
					jobData = [jobData];
				}
			} catch (e) {
				Swal.fire({
					icon: 'error',
					title: 'Error',
					text: 'Invalid JSON in job data field',
				});
				creatingJob.value = false;
				return;
			}
		}
		
		const response = await axios.post(api.horizon.createJob(), {
			job_class: newJobForm.value.job_class.trim(),
			job_data: jobData,
			queue: newJobForm.value.queue || 'default',
		});
		
		if (response.data && response.data.success) {
			Swal.fire({
				icon: 'success',
				title: 'Job Created',
				text: response.data.result?.message || 'Job has been created and dispatched successfully',
				timer: 2000,
				showConfirmButton: false,
			});
			
			// Reset form
			newJobForm.value = {
				job_class: '',
				job_data: '',
				queue: 'default',
			};
			showCreateJobModal.value = false;
			
			// Reload jobs list
			loadJobs();
		} else {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: response.data?.errors?.[0] || 'Failed to create job',
			});
		}
	} catch (error) {
		console.error('Failed to create job:', error);
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || error.message || 'Failed to create job',
		});
	} finally {
		creatingJob.value = false;
	}
}

// Close create job modal
function closeCreateJobModal() {
	showCreateJobModal.value = false;
	newJobForm.value = {
		job_class: '',
		job_data: '',
		queue: 'default',
	};
}

// Fill form with template
function fillTemplate(template) {
	if (template === 'test-failed') {
		newJobForm.value.job_class = 'App\\Jobs\\TestFailedJob';
		newJobForm.value.job_data = JSON.stringify(['This is a test failed job for testing retry functionality'], null, 2);
		newJobForm.value.queue = 'default';
	} else if (template === 'test-success') {
		newJobForm.value.job_class = 'App\\Jobs\\TestHorizonJob';
		newJobForm.value.job_data = JSON.stringify(['This is a test success job'], null, 2);
		newJobForm.value.queue = 'default';
	}
}

// Get job class name for display
function getJobClassName(job) {
	if (!job) return 'Unknown Job';
	return job.displayName || job.name || (job.payload && job.payload.displayName) || 'Unknown Job';
}

// Format JSON with syntax highlighting
function formatJson(obj) {
	try {
		if (!obj) return '';
		
		const jsonString = JSON.stringify(obj, null, 2);
		
		// Escape HTML
		const escapeHtml = (text) => {
			const map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
			};
			return text.replace(/[&<>]/g, m => map[m]);
		};
		
		// Process the entire JSON string
		let result = jsonString;
		
		// Step 1: Mark keys first (quoted string followed by colon)
		result = result.replace(/("(?:[^"\\]|\\.)*")\s*:/g, (match) => {
			return `__KEY__${match}__KEY__:`;
		});
		
		// Step 2: Mark string values (quoted strings that aren't keys)
		result = result.replace(/("(?:[^"\\]|\\.)*")/g, (match, offset) => {
			// Check if this is already part of a key
			const before = result.substring(Math.max(0, offset - 100), offset);
			if (before.includes('__KEY__' + match)) {
				return match; // Already a key
			}
			return `__STR__${match}__STR__`;
		});
		
		// Step 3: Mark booleans (not inside strings)
		result = result.replace(/\b(true|false)\b/g, (match, bool, offset) => {
			const before = result.substring(0, offset);
			const strCount = (before.match(/__STR__/g) || []).length;
			if (strCount % 2 === 1) return match; // Inside string
			return `__BOOL__${match}__BOOL__`;
		});
		
		// Step 4: Mark null (not inside strings)
		result = result.replace(/\bnull\b/g, (match, offset) => {
			const before = result.substring(0, offset);
			const strCount = (before.match(/__STR__/g) || []).length;
			if (strCount % 2 === 1) return match; // Inside string
			return `__NULL__null__NULL__`;
		});
		
		// Step 5: Mark numbers (not inside strings)
		result = result.replace(/(-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, (match, offset) => {
			const before = result.substring(0, offset);
			const strCount = (before.match(/__STR__/g) || []).length;
			if (strCount % 2 === 1) return match; // Inside string
			return `__NUM__${match}__NUM__`;
		});
		
		// Step 6: Escape HTML
		result = escapeHtml(result);
		
		// Step 7: Replace markers with spans
		result = result.replace(/__KEY__(.*?)__KEY__/g, '<span class="json-key">$1</span>');
		result = result.replace(/__STR__(.*?)__STR__/g, '<span class="json-string">$1</span>');
		result = result.replace(/__BOOL__(.*?)__BOOL__/g, '<span class="json-boolean">$1</span>');
		result = result.replace(/__NULL__(.*?)__NULL__/g, '<span class="json-null">$1</span>');
		result = result.replace(/__NUM__(.*?)__NUM__/g, '<span class="json-number">$1</span>');
		
		return result;
	} catch (error) {
		console.error('Error formatting JSON:', error);
		// Fallback to plain JSON stringify
		try {
			return JSON.stringify(obj, null, 2);
		} catch (e) {
			return String(obj);
		}
	}
}

// Format timestamp
function formatTimestamp(timestamp) {
	if (!timestamp) return 'N/A';
	
	// Handle different timestamp formats
	let date;
	if (typeof timestamp === 'string') {
		// Try parsing as ISO string or other formats
		date = new Date(timestamp);
		if (isNaN(date.getTime())) {
			// Try as Unix timestamp (seconds)
			date = new Date(parseFloat(timestamp) * 1000);
		}
	} else if (typeof timestamp === 'number') {
		// If it's a Unix timestamp, check if it's in seconds or milliseconds
		date = timestamp > 1000000000000 ? new Date(timestamp) : new Date(timestamp * 1000);
	} else {
		return 'N/A';
	}
	
	if (isNaN(date.getTime())) {
		return 'N/A';
	}
	
	// Format as YYYY-MM-DD HH:MM:SS
	const year = date.getFullYear();
	const month = String(date.getMonth() + 1).padStart(2, '0');
	const day = String(date.getDate()).padStart(2, '0');
	const hours = String(date.getHours()).padStart(2, '0');
	const minutes = String(date.getMinutes()).padStart(2, '0');
	const seconds = String(date.getSeconds()).padStart(2, '0');
	
	return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

// Format duration
function formatDuration(seconds) {
	if (!seconds && seconds !== 0) return 'N/A';
	if (seconds < 1) return `${(seconds * 1000).toFixed(0)}ms`;
	if (seconds < 60) return `${seconds.toFixed(2)}s`;
	if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${(seconds % 60).toFixed(0)}s`;
	return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

// Format number with commas
function formatNumber(num) {
	if (num === null || num === undefined) return '0';
	return num.toLocaleString();
}

// Get health status color
function getHealthColor(value, thresholds) {
	if (value <= thresholds.good) return 'var(--terminal-success)';
	if (value <= thresholds.warning) return 'var(--terminal-warning)';
	return 'var(--terminal-error)';
}

// Calculate queue utilization percentage
function getQueueUtilization(queue) {
	if (!queue || queue.jobs === 0) return 0;
	// Simple heuristic: if wait time is high, queue is busy
	const waitThreshold = 10; // seconds
	return Math.min(100, (queue.wait / waitThreshold) * 100);
}

// Get available queues from stats
const availableQueues = computed(() => {
	if (!stats.value || !stats.value.queues) return [];
	return Object.keys(stats.value.queues);
});

// Watch for tab changes
watch(activeTab, (newTab) => {
	if (newTab !== 'stats') {
		currentPage.value = 1;
		loadJobs();
	} else {
		loadStats();
	}
});

// Watch for search query changes
watch(searchQuery, () => {
	if (activeTab.value !== 'stats') {
		currentPage.value = 1;
		loadJobs();
	}
});

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue) {
		if (activeTab.value === 'stats') {
			loadStats();
		} else {
			loadJobs();
		}
	}
});

onMounted(() => {
	if (props.visible) {
		if (activeTab.value === 'stats') {
			loadStats();
		} else {
			loadJobs();
		}
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-horizon">
		<div class="terminal-horizon-header">
			<div class="terminal-horizon-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
				</svg>
				<span>Horizon</span>
			</div>
			<div class="terminal-horizon-controls">
				<button
					v-if="activeTab !== 'stats'"
					@click="loadJobs"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loadingJobs"
					title="Reload jobs"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					v-else
					@click="loadStats"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loadingStats"
					title="Reload statistics"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Horizon"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Tabs -->
		<div class="terminal-horizon-tabs">
				<button
					@click="activeTab = 'stats'"
					:class="['terminal-horizon-tab', { 'active': activeTab === 'stats' }]"
				>
					Statistics
				</button>
				<button
					@click="activeTab = 'pending'"
					:class="['terminal-horizon-tab', { 'active': activeTab === 'pending' }]"
				>
					Pending
				</button>
				<button
					@click="activeTab = 'completed'"
					:class="['terminal-horizon-tab', { 'active': activeTab === 'completed' }]"
				>
					Completed
				</button>
				<button
					@click="activeTab = 'silenced'"
					:class="['terminal-horizon-tab', { 'active': activeTab === 'silenced' }]"
				>
					Silenced
				</button>
				<button
					@click="activeTab = 'failed'"
					:class="['terminal-horizon-tab', { 'active': activeTab === 'failed' }]"
				>
					Failed
				</button>
		</div>

		<div class="terminal-horizon-content">
			<!-- Main Content Area (Stats or Jobs) -->
			<div class="terminal-horizon-main-content" :class="{ 'with-details': selectedJob }">
				<!-- Statistics View -->
				<div v-if="activeTab === 'stats'" class="terminal-horizon-stats">
					<div v-if="loadingStats" class="terminal-horizon-loading">
						<span class="spinner"></span>
						Loading statistics...
					</div>

					<div v-else-if="!stats" class="terminal-horizon-empty">
						<p>No statistics available.</p>
					</div>

					<div v-else class="terminal-horizon-stats-container">
						<!-- System Overview Section -->
						<div class="terminal-horizon-stats-section">
							<h3 class="terminal-horizon-section-title">System Overview</h3>
							<div class="terminal-horizon-stats-grid">
								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Throughput</div>
									<div class="terminal-horizon-stat-value">{{ formatNumber(stats.jobsPerMinute || 0) }}</div>
									<div class="terminal-horizon-stat-desc">Jobs per minute</div>
									<div class="terminal-horizon-stat-sub">
										{{ formatNumber(stats.throughput?.perSecond || 0) }}/sec • {{ formatNumber(stats.throughput?.perHour || 0) }}/hr
									</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Pending Jobs</div>
									<div class="terminal-horizon-stat-value" :style="{ color: (stats.pendingJobs || 0) > 100 ? 'var(--terminal-warning)' : 'var(--terminal-text)' }">
										{{ formatNumber(stats.pendingJobs || 0) }}
									</div>
									<div class="terminal-horizon-stat-desc">Waiting in queues</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Success Rate</div>
									<div class="terminal-horizon-stat-value" :style="{ color: getHealthColor(stats.successRate || 0, { good: 95, warning: 80 }) }">
										{{ (stats.successRate || 0).toFixed(1) }}%
									</div>
									<div class="terminal-horizon-stat-desc">
										{{ formatNumber(stats.completedJobs || 0) }} completed
									</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Total Processed</div>
									<div class="terminal-horizon-stat-value">{{ formatNumber(stats.totalJobsProcessed || 0) }}</div>
									<div class="terminal-horizon-stat-desc">
										{{ formatNumber(stats.completedJobs || 0) }} completed • {{ formatNumber(stats.totalFailedJobs || 0) }} failed
									</div>
								</div>
							</div>
						</div>

						<!-- Performance Metrics Section -->
						<div class="terminal-horizon-stats-section">
							<h3 class="terminal-horizon-section-title">Performance Metrics</h3>
							<div class="terminal-horizon-stats-grid">
								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Average Wait Time</div>
									<div class="terminal-horizon-stat-value" :style="{ color: getHealthColor(stats.wait || 0, { good: 5, warning: 15 }) }">
										{{ formatDuration(stats.wait) }}
									</div>
									<div class="terminal-horizon-stat-desc">Time jobs wait before processing</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Average Process Time</div>
									<div class="terminal-horizon-stat-value" :style="{ color: getHealthColor(stats.process || 0, { good: 10, warning: 30 }) }">
										{{ formatDuration(stats.process) }}
									</div>
									<div class="terminal-horizon-stat-desc">Average job execution duration</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Recent Failures</div>
									<div class="terminal-horizon-stat-value" :style="{ color: (stats.recentJobsFailed || 0) > 0 ? 'var(--terminal-error)' : 'var(--terminal-text)' }">
										{{ formatNumber(stats.recentJobsFailed || 0) }}
									</div>
									<div class="terminal-horizon-stat-desc">Failed in recent period</div>
								</div>

								<div class="terminal-horizon-stat-card">
									<div class="terminal-horizon-stat-label">Active Workers</div>
									<div class="terminal-horizon-stat-value" :style="{ color: (stats.workers || 0) > 0 ? 'var(--terminal-success)' : 'var(--terminal-text-muted)' }">
										{{ formatNumber(stats.workers || 0) }}
									</div>
									<div class="terminal-horizon-stat-desc">Horizon worker processes</div>
								</div>
							</div>
						</div>

						<!-- Queue Breakdown Section -->
						<div v-if="stats.queues && Object.keys(stats.queues).length > 0" class="terminal-horizon-stats-section">
							<h3 class="terminal-horizon-section-title">Queue Details</h3>
							<div class="terminal-horizon-queue-list">
								<div
									v-for="(queueData, queueName) in stats.queues"
									:key="queueName"
									class="terminal-horizon-queue-item"
								>
									<div class="terminal-horizon-queue-header">
										<div class="terminal-horizon-queue-name">{{ queueName }}</div>
										<div class="terminal-horizon-queue-utilization">
											<div class="terminal-horizon-utilization-bar">
												<div 
													class="terminal-horizon-utilization-fill"
													:style="{ 
														width: `${getQueueUtilization(queueData)}%`,
														backgroundColor: getHealthColor(getQueueUtilization(queueData), { good: 50, warning: 75 })
													}"
												></div>
											</div>
											<span class="terminal-horizon-utilization-text">{{ getQueueUtilization(queueData).toFixed(0) }}%</span>
										</div>
									</div>
									<div class="terminal-horizon-queue-stats">
										<div class="terminal-horizon-queue-stat-item">
											<span class="terminal-horizon-queue-stat-label">Pending</span>
											<span class="terminal-horizon-queue-stat-value">{{ formatNumber(queueData.jobs || 0) }}</span>
										</div>
										<div class="terminal-horizon-queue-stat-item">
											<span class="terminal-horizon-queue-stat-label">Wait Time</span>
											<span class="terminal-horizon-queue-stat-value" :style="{ color: getHealthColor(queueData.wait || 0, { good: 5, warning: 15 }) }">
												{{ formatDuration(queueData.wait) }}
											</span>
										</div>
										<div class="terminal-horizon-queue-stat-item">
											<span class="terminal-horizon-queue-stat-label">Process Time</span>
											<span class="terminal-horizon-queue-stat-value" :style="{ color: getHealthColor(queueData.process || 0, { good: 10, warning: 30 }) }">
												{{ formatDuration(queueData.process) }}
											</span>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Workers Section -->
						<div v-if="stats.processes && stats.processes.length > 0" class="terminal-horizon-stats-section">
							<h3 class="terminal-horizon-section-title">Active Processes</h3>
							<div class="terminal-horizon-processes-list">
								<div
									v-for="(process, index) in stats.processes"
									:key="index"
									class="terminal-horizon-process-item"
								>
									<div class="terminal-horizon-process-name">{{ process.name || 'Unknown' }}</div>
									<div class="terminal-horizon-process-meta">
										<span class="terminal-horizon-process-status" :class="`status-${process.status || 'unknown'}`">
											{{ (process.status || 'unknown').toUpperCase() }}
										</span>
										<span v-if="process.pid" class="terminal-horizon-process-pid">PID: {{ process.pid }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Jobs View -->
				<div v-else class="terminal-horizon-jobs">
				<!-- Search and Filters -->
				<div class="terminal-horizon-jobs-filters">
					<input
						v-model="searchQuery"
						type="text"
						placeholder="Search jobs by class name, queue, or tags..."
						class="terminal-input terminal-horizon-search"
					/>
					<select
						v-if="availableQueues.length > 0"
						v-model="selectedQueue"
						class="terminal-select terminal-horizon-queue-filter"
						@change="loadJobs"
					>
						<option value="">All Queues</option>
						<option v-for="queue in availableQueues" :key="queue" :value="queue">{{ queue }}</option>
					</select>
					<button
						@click="showCreateJobModal = true"
						class="terminal-btn terminal-btn-primary"
						title="Create new job"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
							<path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
						</svg>
						Create Job
					</button>
				</div>

				<!-- Jobs List -->
				<div v-if="loadingJobs" class="terminal-horizon-loading">
					<span class="spinner"></span>
					Loading jobs...
				</div>

				<div v-else-if="jobs.length === 0" class="terminal-horizon-empty">
					<p>No {{ activeTab }} jobs found.</p>
				</div>

				<div v-else class="terminal-horizon-jobs-list">
					<div class="terminal-horizon-jobs-header">
						<div class="terminal-horizon-jobs-count">
							Showing {{ jobs.length }} of {{ totalJobs }} jobs
						</div>
						<div v-if="totalPages > 1" class="terminal-horizon-pagination">
							<button
								@click="currentPage--; loadJobs();"
								:disabled="currentPage === 1"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Previous
							</button>
							<span class="terminal-horizon-page-info">Page {{ currentPage }} of {{ totalPages }}</span>
							<button
								@click="currentPage++; loadJobs();"
								:disabled="currentPage >= totalPages"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Next
							</button>
						</div>
					</div>

					<div class="terminal-horizon-jobs-table">
						<div
							v-for="job in jobs"
							:key="job.id || job.uuid"
							class="terminal-horizon-job-item"
						>
							<div class="terminal-horizon-job-row">
								<div class="terminal-horizon-job-main" @click="selectJob(job)">
									<div class="terminal-horizon-job-name">{{ job.displayName || job.name || (job.payload && job.payload.displayName) || 'Unknown Job' }}</div>
									<div class="terminal-horizon-job-meta">
										<span class="terminal-horizon-job-queue">{{ job.queue || 'default' }}</span>
										<span class="terminal-horizon-job-time">{{ formatTimestamp(job.created_at) }}</span>
									</div>
								</div>
								<div class="terminal-horizon-job-actions">
									<button
										v-if="canRetryJob(job)"
										@click.stop="retryJob(job.id || job.uuid)"
										class="terminal-btn terminal-btn-secondary terminal-btn-sm"
										title="Retry job"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
										</svg>
									</button>
									<button
										v-if="canExecuteJob(job)"
										@click.stop="executeJob(job.id || job.uuid)"
										class="terminal-btn terminal-btn-secondary terminal-btn-sm"
										title="Execute job"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
											<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
										</svg>
									</button>
									<button
										@click.stop="deleteJob(job.id || job.uuid)"
										class="terminal-btn terminal-btn-secondary terminal-btn-sm"
										title="Delete job"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
										</svg>
									</button>
								</div>
							</div>
							<div v-if="job.tags && job.tags.length > 0" class="terminal-horizon-job-tags">
								<span
									v-for="tag in job.tags"
									:key="tag"
									class="terminal-horizon-job-tag"
								>{{ tag }}</span>
							</div>
						</div>
					</div>
				</div>
				</div>
			</div>

			<!-- Job Details View -->
			<div v-if="selectedJob" class="terminal-horizon-job-details">
				<div class="terminal-horizon-job-details-header">
					<h3>{{ getJobClassName(selectedJob) }}</h3>
					<div class="terminal-horizon-job-details-actions">
						<button
							v-if="canRetryJob(selectedJob)"
							@click="retryJob(selectedJob.id || selectedJob.uuid)"
							class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							title="Retry this failed job"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
							</svg>
							Retry
						</button>
						<button
							v-if="canExecuteJob(selectedJob)"
							@click="executeJob(selectedJob.id || selectedJob.uuid)"
							class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							title="Execute this job again"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
								<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
							</svg>
							Execute
						</button>
						<button
							v-if="canDeleteJob(selectedJob)"
							@click="deleteJob(selectedJob.id || selectedJob.uuid)"
							class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							title="Delete this job"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
							</svg>
							Delete
						</button>
						<button
							@click="closeJobDetails"
							class="terminal-btn terminal-btn-close terminal-btn-sm"
							title="Close"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
					</div>
				</div>
				<div class="terminal-horizon-job-details-content">
					<!-- Job Info Section -->
					<div class="terminal-horizon-job-info-section">
						<div class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">ID</div>
							<div class="terminal-horizon-job-info-value">{{ selectedJob.id || selectedJob.uuid || selectedJob.payload?.id || selectedJob.payload?.uuid || 'N/A' }}</div>
						</div>
						<div class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Queue</div>
							<div class="terminal-horizon-job-info-value">{{ selectedJob.queue || selectedJob.payload?.queue || 'default' }}</div>
						</div>
						<div class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Pushed</div>
							<div class="terminal-horizon-job-info-value">{{ formatTimestamp(selectedJob.pushed_at || selectedJob.created_at || selectedJob.payload?.pushedAt || selectedJob.payload?.createdAt) }}</div>
						</div>
						<div v-if="selectedJob.completed_at || selectedJob.finished_at || selectedJob.status === 'completed'" class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Completed</div>
							<div class="terminal-horizon-job-info-value">{{ formatTimestamp(selectedJob.completed_at || selectedJob.finished_at) }}</div>
						</div>
						<div v-if="selectedJob.connection" class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Connection</div>
							<div class="terminal-horizon-job-info-value">{{ selectedJob.connection }}</div>
						</div>
						<div v-if="selectedJob.status" class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Status</div>
							<div class="terminal-horizon-job-info-value">{{ selectedJob.status }}</div>
						</div>
						<div v-if="selectedJob.payload?.attempts !== undefined" class="terminal-horizon-job-info-item">
							<div class="terminal-horizon-job-info-label">Attempts</div>
							<div class="terminal-horizon-job-info-value">{{ selectedJob.payload.attempts }}</div>
						</div>
					</div>

					<!-- Data Section - Show full payload structure -->
					<div v-if="selectedJob.payload" class="terminal-horizon-job-detail-section">
						<div class="terminal-horizon-job-detail-section-title">Data</div>
						<pre class="terminal-horizon-job-detail-code" v-html="formatJson(selectedJob.payload)"></pre>
					</div>
					<!-- Fallback if payload is not available but data is -->
					<div v-else-if="selectedJob.data" class="terminal-horizon-job-detail-section">
						<div class="terminal-horizon-job-detail-section-title">Data</div>
						<pre class="terminal-horizon-job-detail-code" v-html="formatJson(selectedJob.data)"></pre>
					</div>

					<!-- Tags Section - Check both top-level and payload -->
					<div v-if="(selectedJob.tags && selectedJob.tags.length > 0) || (selectedJob.payload?.tags && selectedJob.payload.tags.length > 0)" class="terminal-horizon-job-detail-section">
						<div class="terminal-horizon-job-detail-section-title">Tags</div>
						<div class="terminal-horizon-job-tags-list">
							<span
								v-for="tag in (selectedJob.tags || selectedJob.payload?.tags || [])"
								:key="tag"
								class="terminal-horizon-job-tag"
							>{{ tag }}</span>
						</div>
					</div>

					<!-- Exception Section -->
					<div v-if="selectedJob.exception" class="terminal-horizon-job-detail-section">
						<div class="terminal-horizon-job-detail-section-title">Exception</div>
						<pre class="terminal-horizon-job-detail-code terminal-horizon-job-detail-error" v-html="formatJson(selectedJob.exception)"></pre>
					</div>
				</div>
			</div>
		</div>
		
		<!-- Create Job Modal -->
		<div v-if="showCreateJobModal" class="terminal-horizon-modal-overlay" @click.self="closeCreateJobModal">
			<div class="terminal-horizon-modal">
				<div class="terminal-horizon-modal-header">
					<h3>Create New Job</h3>
					<button
						@click="closeCreateJobModal"
						class="terminal-btn terminal-btn-close terminal-btn-sm"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
							<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>
				<div class="terminal-horizon-modal-content">
					<div class="terminal-horizon-form-group">
						<label class="terminal-horizon-form-label">Job Class *</label>
						<input
							v-model="newJobForm.job_class"
							type="text"
							placeholder="App\Jobs\YourJobClass"
							class="terminal-input"
						/>
						<small class="terminal-horizon-form-help">Full class name including namespace (e.g., App\Jobs\ProcessOrder)</small>
					</div>
					
					<div class="terminal-horizon-form-group">
						<label class="terminal-horizon-form-label">Job Data (JSON)</label>
						<textarea
							v-model="newJobForm.job_data"
							placeholder='["arg1", "arg2"] or {"key": "value"}'
							class="terminal-input"
							rows="4"
						></textarea>
						<small class="terminal-horizon-form-help">JSON array or object representing constructor arguments</small>
					</div>
					
					<div class="terminal-horizon-form-group">
						<label class="terminal-horizon-form-label">Queue</label>
						<select
							v-model="newJobForm.queue"
							class="terminal-select"
						>
							<option value="default">default</option>
							<option v-for="queue in availableQueues" :key="queue" :value="queue">{{ queue }}</option>
						</select>
					</div>
					
					<div class="terminal-horizon-form-group">
						<label class="terminal-horizon-form-label">Quick Templates</label>
						<div class="terminal-horizon-quick-templates">
							<button
								type="button"
								@click="fillTemplate('test-failed')"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Test Failed Job
							</button>
							<button
								type="button"
								@click="fillTemplate('test-success')"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Test Success Job
							</button>
						</div>
					</div>
				</div>
				<div class="terminal-horizon-modal-footer">
					<button
						@click="closeCreateJobModal"
						class="terminal-btn terminal-btn-secondary"
					>
						Cancel
					</button>
					<button
						@click="createJob"
						class="terminal-btn terminal-btn-primary"
						:disabled="creatingJob || !newJobForm.job_class.trim()"
					>
						<svg v-if="creatingJob" class="spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
						</svg>
						{{ creatingJob ? 'Creating...' : 'Create Job' }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>


<style>
/* JSON syntax highlighting - must be global for v-html content */
.terminal-horizon-job-detail-code .json-key {
	color: #9cdcfe !important;
}

.terminal-horizon-job-detail-code .json-string {
	color: #ce9178 !important;
}

.terminal-horizon-job-detail-code .json-number {
	color: #b5cea8 !important;
}

.terminal-horizon-job-detail-code .json-boolean {
	color: #569cd6 !important;
}

.terminal-horizon-job-detail-code .json-null {
	color: #569cd6 !important;
	font-style: italic;
}
</style>

<style scoped>
.terminal-horizon {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
}

.terminal-horizon-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-horizon-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: 16px;
}

.terminal-horizon-title svg {
	width: 20px;
	height: 20px;
}

.terminal-horizon-controls {
	display: flex;
	gap: 8px;
}

.terminal-horizon-content {
	flex: 1;
	display: flex;
	flex-direction: row;
	overflow: hidden;
	position: relative;
}

.terminal-horizon-tabs {
	display: flex;
	border-bottom: 1px solid var(--terminal-border);
	padding: 0 16px;
	gap: 4px;
}

.terminal-horizon-tab {
	padding: 12px 16px;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--terminal-text-muted);
	cursor: pointer;
	font-size: 14px;
	transition: all 0.2s;
}

.terminal-horizon-tab:hover {
	color: var(--terminal-text);
}

.terminal-horizon-tab.active {
	color: var(--terminal-primary);
	border-bottom-color: var(--terminal-primary);
}

.terminal-horizon-main-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	transition: width 0.3s ease;
}

.terminal-horizon-main-content.with-details {
	width: 50%;
}

.terminal-horizon-stats,
.terminal-horizon-jobs {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	width: 100%;
}

.terminal-horizon-loading,
.terminal-horizon-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--terminal-text-muted);
	gap: 12px;
}

.terminal-horizon-stats-container {
	display: flex;
	flex-direction: column;
	gap: 32px;
}

.terminal-horizon-stats-section {
	margin-bottom: 24px;
}

.terminal-horizon-section-title {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 16px;
	color: var(--terminal-text);
	border-bottom: 1px solid var(--terminal-border);
	padding-bottom: 8px;
}

.terminal-horizon-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
}

.terminal-horizon-stat-card {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 16px;
}

.terminal-horizon-stat-label {
	font-size: 12px;
	color: var(--terminal-text-muted);
	margin-bottom: 8px;
}

.terminal-horizon-stat-value {
	font-size: 24px;
	font-weight: 600;
	color: var(--terminal-text);
	margin-bottom: 4px;
}

.terminal-horizon-stat-desc {
	font-size: 11px;
	color: var(--terminal-text-muted);
	margin-top: 4px;
}

.terminal-horizon-stat-sub {
	font-size: 10px;
	color: var(--terminal-text-muted);
	margin-top: 2px;
	opacity: 0.8;
}

.terminal-horizon-processes-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-horizon-process-item {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.terminal-horizon-process-name {
	font-weight: 600;
	color: var(--terminal-text);
	font-size: 13px;
}

.terminal-horizon-process-meta {
	display: flex;
	align-items: center;
	gap: 12px;
	font-size: 11px;
}

.terminal-horizon-process-status {
	padding: 2px 8px;
	border-radius: 3px;
	font-weight: 600;
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-horizon-process-status.status-running {
	background: rgba(76, 175, 80, 0.2);
	color: var(--terminal-success);
}

.terminal-horizon-process-status.status-paused {
	background: rgba(255, 152, 0, 0.2);
	color: var(--terminal-warning);
}

.terminal-horizon-process-status.status-unknown {
	background: rgba(158, 158, 158, 0.2);
	color: var(--terminal-text-muted);
}

.terminal-horizon-process-pid {
	color: var(--terminal-text-muted);
	font-family: 'Courier New', monospace;
}

.terminal-horizon-queue-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-horizon-queue-item {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
}

.terminal-horizon-queue-name {
	font-weight: 600;
	margin-bottom: 8px;
	color: var(--terminal-text);
}

.terminal-horizon-queue-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.terminal-horizon-queue-utilization {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 120px;
}

.terminal-horizon-utilization-bar {
	flex: 1;
	height: 6px;
	background: var(--terminal-bg);
	border-radius: 3px;
	overflow: hidden;
}

.terminal-horizon-utilization-fill {
	height: 100%;
	transition: width 0.3s ease, background-color 0.3s ease;
	border-radius: 3px;
}

.terminal-horizon-utilization-text {
	font-size: 11px;
	color: var(--terminal-text-muted);
	min-width: 35px;
	text-align: right;
}

.terminal-horizon-queue-stats {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 12px;
	font-size: 12px;
}

.terminal-horizon-queue-stat-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.terminal-horizon-queue-stat-label {
	color: var(--terminal-text-muted);
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-horizon-queue-stat-value {
	color: var(--terminal-text);
	font-size: 13px;
	font-weight: 600;
	font-family: 'Courier New', monospace;
}

.terminal-horizon-jobs-filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
}

.terminal-horizon-search {
	flex: 1;
	background: var(--terminal-bg, #1e1e1e) !important;
	border: 1px solid var(--terminal-border, #3e3e42) !important;
	color: var(--terminal-text, #d4d4d4) !important;
	padding: 6px 12px !important;
	font-size: 13px;
	border-radius: 4px;
	box-sizing: border-box;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.terminal-horizon-search:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c) !important;
	box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.2);
	background: var(--terminal-bg, #1e1e1e) !important;
}

.terminal-horizon-search::placeholder {
	color: var(--terminal-text-muted, #6b7280) !important;
}

.terminal-horizon-queue-filter {
	min-width: 150px;
}

.terminal-horizon-jobs-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.terminal-horizon-jobs-count {
	font-size: 12px;
	color: var(--terminal-text-muted);
}

.terminal-horizon-pagination {
	display: flex;
	align-items: center;
	gap: 12px;
}

.terminal-horizon-page-info {
	font-size: 12px;
	color: var(--terminal-text-muted);
}

.terminal-horizon-jobs-table {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-horizon-job-item {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	transition: all 0.2s;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-horizon-job-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
}

.terminal-horizon-job-main {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0;
	cursor: pointer;
	flex: 1;
}

.terminal-horizon-job-name {
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-horizon-job-meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--terminal-text-muted);
}

.terminal-horizon-job-tags {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.terminal-horizon-job-tag {
	background: var(--terminal-primary);
	color: white;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 11px;
}

.terminal-horizon-job-details {
	width: 50%;
	height: 100%;
	background: var(--terminal-bg);
	border-left: 1px solid var(--terminal-border);
	display: flex;
	flex-direction: column;
	overflow-y: auto;
	flex-shrink: 0;
}

.terminal-horizon-job-details-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-horizon-job-details-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.terminal-horizon-job-actions {
	display: flex;
	gap: 4px;
	align-items: center;
	flex-shrink: 0;
}

.terminal-horizon-job-item {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	transition: all 0.2s;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-horizon-job-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
}

.terminal-horizon-job-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.terminal-horizon-job-main {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex: 1;
	cursor: pointer;
}

.terminal-horizon-job-details-header h3 {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
}

/* Standardize close button styling to match top design */
/* Button Styles - Ensure consistency with package */
.terminal-horizon .terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 12px;
	font-weight: 500;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	gap: 4px;
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-horizon .terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-horizon .terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-horizon .terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-horizon .terminal-btn-secondary {
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-horizon .terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-horizon .terminal-btn-close {
	background: transparent !important;
	color: var(--terminal-text-secondary, #858585) !important;
	padding: 4px !important;
	border: none !important;
	min-width: auto !important;
}

.terminal-horizon .terminal-btn-close:hover {
	background: var(--terminal-border, #3e3e42) !important;
	color: var(--terminal-text, #d4d4d4) !important;
}

.terminal-horizon .terminal-btn-sm {
	padding: 4px 8px;
	font-size: 11px;
}

.terminal-horizon .terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-horizon-job-details-header .terminal-btn-close svg,
.terminal-horizon-header .terminal-btn-close svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-horizon-job-details-content {
	padding: 16px;
}

.terminal-horizon-job-info-section {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-bottom: 24px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-horizon-job-info-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.terminal-horizon-job-info-label {
	font-size: 12px;
	color: var(--terminal-text-muted);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-horizon-job-info-value {
	color: var(--terminal-text);
	font-size: 14px;
	word-break: break-word;
	font-family: 'Courier New', monospace;
}

.terminal-horizon-job-detail-section {
	margin-bottom: 24px;
}

.terminal-horizon-job-detail-section-title {
	font-size: 14px;
	color: var(--terminal-text-muted);
	margin-bottom: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-horizon-job-detail-label {
	font-size: 12px;
	color: var(--terminal-text-muted);
	margin-bottom: 6px;
	font-weight: 600;
}

.terminal-horizon-job-detail-value {
	color: var(--terminal-text);
	font-size: 14px;
	word-break: break-word;
}

.terminal-horizon-job-tags-list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.terminal-horizon-job-detail-code {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	font-size: 12px;
	overflow-x: auto;
	color: var(--terminal-text);
	font-family: 'Courier New', 'Consolas', 'Monaco', monospace;
	white-space: pre-wrap;
	word-break: break-word;
	line-height: 1.5;
}

.terminal-horizon-job-detail-error {
	color: #f48771;
}

.spinner {
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border);
	border-top-color: var(--terminal-primary);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}

/* Custom Scrollbar Styling */
.terminal-horizon-content::-webkit-scrollbar,
.terminal-horizon-main-content::-webkit-scrollbar,
.terminal-horizon-stats::-webkit-scrollbar,
.terminal-horizon-jobs::-webkit-scrollbar,
.terminal-horizon-job-details::-webkit-scrollbar,
.terminal-horizon-job-details-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-horizon-content::-webkit-scrollbar-track,
.terminal-horizon-main-content::-webkit-scrollbar-track,
.terminal-horizon-stats::-webkit-scrollbar-track,
.terminal-horizon-jobs::-webkit-scrollbar-track,
.terminal-horizon-job-details::-webkit-scrollbar-track,
.terminal-horizon-job-details-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-horizon-content::-webkit-scrollbar-thumb,
.terminal-horizon-main-content::-webkit-scrollbar-thumb,
.terminal-horizon-stats::-webkit-scrollbar-thumb,
.terminal-horizon-jobs::-webkit-scrollbar-thumb,
.terminal-horizon-job-details::-webkit-scrollbar-thumb,
.terminal-horizon-job-details-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
}

.terminal-horizon-content::-webkit-scrollbar-thumb:hover,
.terminal-horizon-main-content::-webkit-scrollbar-thumb:hover,
.terminal-horizon-stats::-webkit-scrollbar-thumb:hover,
.terminal-horizon-jobs::-webkit-scrollbar-thumb:hover,
.terminal-horizon-job-details::-webkit-scrollbar-thumb:hover,
.terminal-horizon-job-details-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

/* Firefox scrollbar styling */
.terminal-horizon-content,
.terminal-horizon-main-content,
.terminal-horizon-stats,
.terminal-horizon-jobs,
.terminal-horizon-job-details,
.terminal-horizon-job-details-content {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}

/* Create Job Modal */
.terminal-horizon-modal-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.7);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10003;
}

.terminal-horizon-modal {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	width: 90%;
	max-width: 600px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

.terminal-horizon-modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-horizon-modal-header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-horizon-modal-content {
	padding: 16px;
	overflow-y: auto;
	flex: 1;
}

.terminal-horizon-form-group {
	margin-bottom: 20px;
}

.terminal-horizon-form-label {
	display: block;
	margin-bottom: 8px;
	font-size: 14px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-horizon-form-help {
	display: block;
	margin-top: 4px;
	font-size: 12px;
	color: var(--terminal-text-muted);
}

/* Form Input Styles - Match package dark theme */
.terminal-horizon-modal .terminal-input,
.terminal-horizon-modal .terminal-select,
.terminal-horizon-modal textarea.terminal-input {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	font-family: inherit;
	outline: none;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
	box-sizing: border-box;
}

.terminal-horizon-modal .terminal-input:focus,
.terminal-horizon-modal .terminal-select:focus,
.terminal-horizon-modal textarea.terminal-input:focus {
	border-color: var(--terminal-primary, #0e639c);
	box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.2);
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-horizon-modal .terminal-input::placeholder,
.terminal-horizon-modal textarea.terminal-input::placeholder {
	color: var(--terminal-text-muted, #6b7280);
}

.terminal-horizon-modal .terminal-select {
	cursor: pointer;
	appearance: none;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23d4d4d4' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 8px center;
	background-size: 12px;
	padding-right: 28px;
}

.terminal-horizon-modal textarea.terminal-input {
	resize: vertical;
	min-height: 80px;
	font-family: 'Courier New', monospace;
}

.terminal-horizon-quick-templates {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.terminal-horizon-modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	padding: 16px;
	border-top: 1px solid var(--terminal-border);
}
</style>

