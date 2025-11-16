<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	prefillData: {
		type: Object,
		default: null,
	},
});

const emit = defineEmits(['close', 'create-issue', 'navigate-to-source', 'issue-updated']);

// State
const loading = ref(false);
const issues = ref([]);
const stats = ref(null);
const currentPage = ref(1);
const perPage = ref(20);
const totalPages = ref(1);
const totalItems = ref(0);
const users = ref([]);
const loadingUsers = ref(false);

// Filters
const statusFilter = ref('all');
const priorityFilter = ref('all');
const assigneeFilter = ref('all');
const creatorFilter = ref('all');
const sourceTypeFilter = ref('all');
const searchQuery = ref('');

// Modal state
const showModal = ref(false);
const editingIssue = ref(null);
const modalTitle = ref('');
const modalDescription = ref('');
const modalPriority = ref('medium');
const modalStatus = ref('open');
const modalAssignee = ref(null);
const modalTags = ref('');
const modalSourceType = ref(null);
const modalSourceId = ref(null);
const modalSourceData = ref(null);
const modalError = ref(null);
const saving = ref(false);

// Load users
async function loadUsers() {
	if (loadingUsers.value) return;
	
	loadingUsers.value = true;
	try {
		const response = await axios.get(api.issues.users());
		if (response.data && response.data.success) {
			users.value = response.data.users || [];
		}
	} catch (error) {
		console.error('Failed to load users:', error);
		// Don't fail the entire component if users can't be loaded
		users.value = [];
	} finally {
		loadingUsers.value = false;
	}
}

// Load issues
async function loadIssues() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const params = {
			page: currentPage.value,
			per_page: perPage.value,
		};
		
		if (statusFilter.value !== 'all') {
			params.status = statusFilter.value;
		}
		if (priorityFilter.value !== 'all') {
			params.priority = priorityFilter.value;
		}
		if (assigneeFilter.value !== 'all' && assigneeFilter.value !== 'unassigned') {
			params.assignee_id = assigneeFilter.value;
		} else if (assigneeFilter.value === 'unassigned') {
			params.assignee_id = 'unassigned';
		}
		if (creatorFilter.value !== 'all') {
			params.creator_id = creatorFilter.value;
		}
		if (sourceTypeFilter.value !== 'all') {
			params.source_type = sourceTypeFilter.value;
		}
		if (searchQuery.value.trim()) {
			params.search = searchQuery.value.trim();
		}
		
		const response = await axios.get(api.issues.list(params));
		if (response.data && response.data.success) {
			const result = response.data.result || response.data.data || {};
			issues.value = result.data || result.items || [];
			currentPage.value = result.current_page || 1;
			totalPages.value = result.last_page || 1;
			totalItems.value = result.total || 0;
			perPage.value = result.per_page || 20;
		} else {
			// If response is not successful, set empty data
			issues.value = [];
		}
	} catch (error) {
		console.error('Failed to load issues:', error);
		// Don't fail the entire component if issues can't be loaded
		issues.value = [];
		currentPage.value = 1;
		totalPages.value = 1;
		totalItems.value = 0;
	} finally {
		loading.value = false;
	}
}

// Load statistics
async function loadStats() {
	try {
		const response = await axios.get(api.issues.stats());
		if (response.data && response.data.success && response.data.result) {
			stats.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load stats:', error);
		// Don't fail the entire component if stats can't be loaded
		stats.value = null;
	}
}

// Open create modal
function openCreateModal(prefill = null) {
	editingIssue.value = null;
	modalTitle.value = prefill?.title || '';
	modalDescription.value = prefill?.description || '';
	modalPriority.value = prefill?.priority || 'medium';
	modalStatus.value = prefill?.status || 'open';
	modalAssignee.value = prefill?.assignee_id || null;
	modalTags.value = Array.isArray(prefill?.tags) ? prefill.tags.join(', ') : (prefill?.tags || '');
	modalSourceType.value = prefill?.source_type || null;
	modalSourceId.value = prefill?.source_id || null;
	modalSourceData.value = prefill?.source_data || null;
	modalError.value = null;
	showModal.value = true;
}

// Open edit modal
function openEditModal(issue) {
	editingIssue.value = issue;
	modalTitle.value = issue.title || '';
	modalDescription.value = issue.description || '';
	modalPriority.value = issue.priority || 'medium';
	modalStatus.value = issue.status || 'open';
	modalAssignee.value = issue.assignee_id || null;
	modalTags.value = Array.isArray(issue.tags) ? issue.tags.join(', ') : (issue.tags || '');
	modalSourceType.value = issue.source_type || null;
	modalSourceId.value = issue.source_id || null;
	modalSourceData.value = issue.source_data || null;
	modalError.value = null;
	showModal.value = true;
}

// Close modal
function closeModal() {
	showModal.value = false;
	editingIssue.value = null;
	modalTitle.value = '';
	modalDescription.value = '';
	modalPriority.value = 'medium';
	modalStatus.value = 'open';
	modalAssignee.value = null;
	modalTags.value = '';
	modalSourceType.value = null;
	modalSourceId.value = null;
	modalSourceData.value = null;
	modalError.value = null;
}

// Save issue
async function saveIssue() {
	if (!modalTitle.value.trim()) {
		modalError.value = 'Title is required';
		return;
	}
	
	saving.value = true;
	modalError.value = null;
	
	try {
		const tagsArray = modalTags.value
			.split(',')
			.map(tag => tag.trim())
			.filter(tag => tag.length > 0);
		
		const payload = {
			title: modalTitle.value.trim(),
			description: modalDescription.value.trim() || null,
			priority: modalPriority.value,
			status: modalStatus.value,
			assignee_id: modalAssignee.value || null,
			tags: tagsArray.length > 0 ? tagsArray : null,
			source_type: modalSourceType.value,
			source_id: modalSourceId.value,
			source_data: modalSourceData.value,
		};
		
		let response;
		if (editingIssue.value) {
			response = await axios.put(api.issues.update(editingIssue.value.id), payload);
		} else {
			response = await axios.post(api.issues.create(), payload);
		}
		
		if (response.data && response.data.success) {
			closeModal();
			loadIssues();
			loadStats();
			emit('issue-updated');
			if (!editingIssue.value && props.prefillData) {
				emit('create-issue', response.data.result);
			}
		} else {
			modalError.value = response.data?.error || 'Failed to save issue';
		}
	} catch (error) {
		console.error('Failed to save issue:', error);
		modalError.value = error.response?.data?.error || error.message || 'Failed to save issue';
	} finally {
		saving.value = false;
	}
}

// Resolve issue
async function resolveIssue(issue) {
	try {
		const response = await axios.post(api.issues.resolve(issue.id));
		if (response.data && response.data.success) {
			loadIssues();
			loadStats();
			emit('issue-updated');
		}
	} catch (error) {
		console.error('Failed to resolve issue:', error);
	}
}

// Close issue
async function closeIssue(issue) {
	try {
		const response = await axios.post(api.issues.close(issue.id));
		if (response.data && response.data.success) {
			loadIssues();
			loadStats();
			emit('issue-updated');
		}
	} catch (error) {
		console.error('Failed to close issue:', error);
	}
}

// Reopen issue
async function reopenIssue(issue) {
	try {
		const response = await axios.post(api.issues.reopen(issue.id));
		if (response.data && response.data.success) {
			loadIssues();
			loadStats();
			emit('issue-updated');
		}
	} catch (error) {
		console.error('Failed to reopen issue:', error);
	}
}

// Delete issue
async function deleteIssue(issue) {
	const result = await Swal.fire({
		icon: 'warning',
		title: 'Delete Issue?',
		text: `Are you sure you want to delete issue #${issue.id}?`,
		showCancelButton: true,
		confirmButtonText: 'Delete',
		cancelButtonText: 'Cancel',
	});
	
	if (!result.isConfirmed) {
		return;
	}
	
	try {
		const response = await axios.delete(api.issues.delete(issue.id));
		if (response.data && response.data.success) {
			loadIssues();
			loadStats();
			emit('issue-updated');
		}
	} catch (error) {
		console.error('Failed to delete issue:', error);
		Swal.fire({
			icon: 'error',
			title: 'Failed to delete issue',
			text: error.response?.data?.error || error.message || 'Unknown error',
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
	}
}

// Assign issue
async function assignIssue(issue, userId) {
	try {
		const response = await axios.post(api.issues.assign(issue.id), {
			user_id: userId || null,
		});
		if (response.data && response.data.success) {
			loadIssues();
			loadStats();
		}
	} catch (error) {
		console.error('Failed to assign issue:', error);
	}
}

// Navigate to source
function navigateToSource(issue) {
	if (!issue.source_type || !issue.source_data) {
		return;
	}
	
	// Emit event to parent to handle navigation
	emit('navigate-to-source', {
		type: issue.source_type,
		data: issue.source_data,
		issue: issue,
	});
}

// Format date
function formatDate(dateString) {
	if (!dateString) return 'N/A';
	const date = new Date(dateString);
	return date.toLocaleString();
}

// Get status badge class
function getStatusClass(status) {
	const classes = {
		open: 'terminal-issues-status-open',
		in_progress: 'terminal-issues-status-in-progress',
		resolved: 'terminal-issues-status-resolved',
		closed: 'terminal-issues-status-closed',
	};
	return classes[status] || '';
}

// Get priority badge class
function getPriorityClass(priority) {
	const classes = {
		low: 'terminal-issues-priority-low',
		medium: 'terminal-issues-priority-medium',
		high: 'terminal-issues-priority-high',
		critical: 'terminal-issues-priority-critical',
	};
	return classes[priority] || '';
}

// Get user name
function getUserName(user) {
	if (!user) return 'Unassigned';
	return user.name || user.email || `User #${user.id}`;
}

// Watch for prefill data
watch(() => props.prefillData, (newData) => {
	if (newData) {
		// Ensure users are loaded for the assignee dropdown
		if (users.value.length === 0) {
			loadUsers();
		}
		// Open modal even if tab is not visible (for database scans)
		// The modal will render as an overlay
		openCreateModal(newData);
	}
}, { immediate: true });

// Watch visibility
watch(() => props.visible, (visible) => {
	if (visible) {
		loadIssues();
		loadStats();
		loadUsers();
		
		// If prefill data is provided, open modal
		if (props.prefillData) {
			openCreateModal(props.prefillData);
		}
	}
});

// Watch filters and reload
watch([statusFilter, priorityFilter, assigneeFilter, creatorFilter, sourceTypeFilter, searchQuery], () => {
	if (props.visible) {
		currentPage.value = 1;
		loadIssues();
	}
});

// Pagination
function goToPage(page) {
	if (page >= 1 && page <= totalPages.value) {
		currentPage.value = page;
		loadIssues();
	}
}

onMounted(() => {
	// Only load data if component is visible on mount
	if (props.visible) {
		loadIssues().catch(err => console.error('Error loading issues:', err));
		loadStats().catch(err => console.error('Error loading stats:', err));
		loadUsers().catch(err => console.error('Error loading users:', err));
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-issues">
		<!-- Header -->
		<div class="terminal-issues-header">
			<div class="terminal-issues-header-left">
				<h2>Issues</h2>
				<button
					@click="openCreateModal()"
					class="terminal-btn terminal-btn-primary"
					title="Create new issue"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px; margin-right: 4px;">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
					</svg>
					New Issue
				</button>
			</div>
			<div class="terminal-issues-header-right">
				<button
					@click="loadIssues()"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Refresh"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px;">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Statistics Panel -->
		<div v-if="stats" class="terminal-issues-stats">
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">Total</span>
				<span class="terminal-issues-stat-value">{{ stats.total || 0 }}</span>
			</div>
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">Open</span>
				<span class="terminal-issues-stat-value terminal-issues-stat-open">{{ stats.by_status?.open || 0 }}</span>
			</div>
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">In Progress</span>
				<span class="terminal-issues-stat-value terminal-issues-stat-in-progress">{{ stats.by_status?.in_progress || 0 }}</span>
			</div>
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">Resolved</span>
				<span class="terminal-issues-stat-value terminal-issues-stat-resolved">{{ stats.by_status?.resolved || 0 }}</span>
			</div>
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">Closed</span>
				<span class="terminal-issues-stat-value terminal-issues-stat-closed">{{ stats.by_status?.closed || 0 }}</span>
			</div>
			<div class="terminal-issues-stat-item">
				<span class="terminal-issues-stat-label">Unassigned</span>
				<span class="terminal-issues-stat-value">{{ stats.unassigned || 0 }}</span>
			</div>
		</div>

		<!-- Filters -->
		<div class="terminal-issues-filters">
			<input
				v-model="searchQuery"
				type="text"
				placeholder="Search issues..."
				class="terminal-issues-search"
			/>
			<select v-model="statusFilter" class="terminal-issues-filter">
				<option value="all">All Statuses</option>
				<option value="open">Open</option>
				<option value="in_progress">In Progress</option>
				<option value="resolved">Resolved</option>
				<option value="closed">Closed</option>
			</select>
			<select v-model="priorityFilter" class="terminal-issues-filter">
				<option value="all">All Priorities</option>
				<option value="low">Low</option>
				<option value="medium">Medium</option>
				<option value="high">High</option>
				<option value="critical">Critical</option>
			</select>
			<select v-model="assigneeFilter" class="terminal-issues-filter">
				<option value="all">All Assignees</option>
				<option value="unassigned">Unassigned</option>
			</select>
			<select v-model="sourceTypeFilter" class="terminal-issues-filter">
				<option value="all">All Sources</option>
				<option value="log">Log</option>
				<option value="terminal">Terminal</option>
				<option value="ai">AI</option>
				<option value="manual">Manual</option>
			</select>
		</div>

		<!-- Issues List -->
		<div class="terminal-issues-list">
			<div v-if="loading && issues.length === 0" class="terminal-issues-loading">
				<span class="spinner"></span>
				Loading issues...
			</div>
			<div v-else-if="issues.length === 0" class="terminal-issues-empty">
				<p>No issues found</p>
				<button @click="openCreateModal()" class="terminal-btn terminal-btn-primary">Create First Issue</button>
			</div>
			<div v-else class="terminal-issues-items">
				<div
					v-for="issue in issues"
					:key="issue.id"
					class="terminal-issues-item"
				>
					<div class="terminal-issues-item-header">
						<div class="terminal-issues-item-title-row">
							<span class="terminal-issues-item-id">#{{ issue.id }}</span>
							<h3 class="terminal-issues-item-title">{{ issue.title }}</h3>
							<span :class="['terminal-issues-badge', 'terminal-issues-status-badge', getStatusClass(issue.status)]">
								{{ issue.status.replace('_', ' ') }}
							</span>
							<span :class="['terminal-issues-badge', 'terminal-issues-priority-badge', getPriorityClass(issue.priority)]">
								{{ issue.priority }}
							</span>
						</div>
						<div class="terminal-issues-item-actions">
							<button
								v-if="issue.source_type && issue.source_data"
								@click="navigateToSource(issue)"
								class="terminal-issues-action-btn"
								title="View Source"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
								</svg>
							</button>
							<button
								@click="openEditModal(issue)"
								class="terminal-issues-action-btn"
								title="Edit"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
								</svg>
							</button>
							<button
								v-if="issue.status !== 'resolved'"
								@click="resolveIssue(issue)"
								class="terminal-issues-action-btn"
								title="Resolve"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
								</svg>
							</button>
							<button
								v-if="issue.status !== 'closed'"
								@click="closeIssue(issue)"
								class="terminal-issues-action-btn"
								title="Close"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
							<button
								v-if="issue.status === 'closed' || issue.status === 'resolved'"
								@click="reopenIssue(issue)"
								class="terminal-issues-action-btn"
								title="Reopen"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
								</svg>
							</button>
							<button
								@click="deleteIssue(issue)"
								class="terminal-issues-action-btn terminal-issues-action-btn-danger"
								title="Delete"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px;">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
								</svg>
							</button>
						</div>
					</div>
					<div v-if="issue.description" class="terminal-issues-item-description">
						{{ issue.description }}
					</div>
					<div class="terminal-issues-item-meta">
						<span v-if="issue.creator">
							Created by {{ getUserName(issue.creator) }}
						</span>
						<span v-if="issue.assignee">
							• Assigned to {{ getUserName(issue.assignee) }}
						</span>
						<span v-else>
							• Unassigned
						</span>
						<span v-if="issue.source_type">
							• Source: {{ issue.source_type }}
						</span>
						<span v-if="issue.tags && issue.tags.length > 0">
							• Tags: {{ Array.isArray(issue.tags) ? issue.tags.join(', ') : issue.tags }}
						</span>
						<span>
							• Created: {{ formatDate(issue.created_at) }}
						</span>
						<span v-if="issue.resolved_at">
							• Resolved: {{ formatDate(issue.resolved_at) }}
						</span>
						<span v-if="issue.closed_at">
							• Closed: {{ formatDate(issue.closed_at) }}
						</span>
					</div>
					<div v-if="issue.source_type && issue.source_data" class="terminal-issues-item-source">
						<button
							@click="navigateToSource(issue)"
							class="terminal-btn terminal-btn-secondary terminal-btn-sm"
						>
							View Source
						</button>
					</div>
				</div>
			</div>
		</div>

		<!-- Pagination -->
		<div v-if="totalPages > 1" class="terminal-issues-pagination">
			<button
				@click="goToPage(currentPage - 1)"
				:disabled="currentPage === 1 || loading"
				class="terminal-btn terminal-btn-secondary"
			>
				Previous
			</button>
			<span class="terminal-issues-pagination-info">
				Page {{ currentPage }} of {{ totalPages }} ({{ totalItems }} total)
			</span>
			<button
				@click="goToPage(currentPage + 1)"
				:disabled="currentPage === totalPages || loading"
				class="terminal-btn terminal-btn-secondary"
			>
				Next
			</button>
		</div>
		</div>

	<!-- Create/Edit Modal - Rendered outside v-if so it can show as overlay even when tab is not visible -->
		<div v-if="showModal" class="terminal-issues-modal-overlay" @click.self="closeModal">
			<div class="terminal-issues-modal">
				<div class="terminal-issues-modal-header">
					<h3>{{ editingIssue ? 'Edit Issue' : 'Create Issue' }}</h3>
					<button @click="closeModal" class="terminal-issues-modal-close">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px;">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>
				<div class="terminal-issues-modal-body">
					<div v-if="modalError" class="terminal-issues-modal-error">
						{{ modalError }}
					</div>
					<div class="terminal-issues-modal-field">
						<label>Title *</label>
						<input
							v-model="modalTitle"
							type="text"
							placeholder="Issue title"
							class="terminal-issues-modal-input"
						/>
					</div>
					<div class="terminal-issues-modal-field">
						<label>Description</label>
						<textarea
							v-model="modalDescription"
							placeholder="Issue description"
							class="terminal-issues-modal-textarea"
							rows="4"
						></textarea>
					</div>
					<div class="terminal-issues-modal-row">
						<div class="terminal-issues-modal-field">
							<label>Priority</label>
							<select v-model="modalPriority" class="terminal-issues-modal-input">
								<option value="low">Low</option>
								<option value="medium">Medium</option>
								<option value="high">High</option>
								<option value="critical">Critical</option>
							</select>
						</div>
						<div class="terminal-issues-modal-field">
							<label>Status</label>
							<select v-model="modalStatus" class="terminal-issues-modal-input">
								<option value="open">Open</option>
								<option value="in_progress">In Progress</option>
								<option value="resolved">Resolved</option>
								<option value="closed">Closed</option>
							</select>
						</div>
					</div>
					<div class="terminal-issues-modal-field">
						<label>Assignee</label>
						<select v-model.number="modalAssignee" class="terminal-issues-modal-input">
							<option :value="null">Unassigned</option>
							<option v-for="user in users" :key="user.id" :value="user.id">
								{{ user.name }} ({{ user.email }})
							</option>
						</select>
					</div>
					<div class="terminal-issues-modal-field">
						<label>Tags (comma-separated)</label>
						<input
							v-model="modalTags"
							type="text"
							placeholder="bug, feature, urgent"
							class="terminal-issues-modal-input"
						/>
					</div>
				</div>
				<div class="terminal-issues-modal-footer">
					<button
						@click="closeModal"
						class="terminal-btn terminal-btn-secondary"
						:disabled="saving"
					>
						Cancel
					</button>
					<button
						@click="saveIssue"
						class="terminal-btn terminal-btn-primary"
						:disabled="saving || !modalTitle.trim()"
					>
						{{ saving ? 'Saving...' : (editingIssue ? 'Update' : 'Create') }}
					</button>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-issues {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
}

.terminal-issues-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-issues-header-left {
	display: flex;
	align-items: center;
	gap: 12px;
}

.terminal-issues-header-left h2 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-issues-stats {
	display: flex;
	gap: 16px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.terminal-issues-stat-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.terminal-issues-stat-label {
	font-size: 11px;
	color: var(--terminal-text-secondary);
	text-transform: uppercase;
}

.terminal-issues-stat-value {
	font-size: 18px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-issues-stat-open {
	color: var(--terminal-accent);
}

.terminal-issues-stat-in-progress {
	color: var(--terminal-warning);
}

.terminal-issues-stat-resolved {
	color: var(--terminal-accent);
}

.terminal-issues-stat-closed {
	color: var(--terminal-text-secondary);
}

.terminal-issues-filters {
	display: flex;
	gap: 8px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.terminal-issues-search {
	flex: 1;
	padding: 8px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 12px;
}

.terminal-issues-search:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-issues-filter {
	padding: 8px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 12px;
	cursor: pointer;
}

.terminal-issues-filter:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-issues-list {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.terminal-issues-loading,
.terminal-issues-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px;
	color: var(--terminal-text-secondary);
}

.terminal-issues-items {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-issues-item {
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	transition: border-color 0.2s;
}

.terminal-issues-item:hover {
	border-color: var(--terminal-primary);
}

.terminal-issues-item-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 8px;
	gap: 12px;
}

.terminal-issues-item-title-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	flex-wrap: wrap;
	min-width: 0;
}

.terminal-issues-item-id {
	font-size: 12px;
	color: var(--terminal-text-secondary);
	font-weight: 600;
	flex-shrink: 0;
}

.terminal-issues-item-title {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--terminal-text);
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-issues-badge {
	padding: 4px 10px;
	border-radius: 3px;
	font-size: 11px;
	font-weight: 500;
	text-transform: capitalize;
	letter-spacing: 0.3px;
	border: 1px solid transparent;
	white-space: nowrap;
	flex-shrink: 0;
	margin-left: 4px;
}

.terminal-issues-status-badge {
	background: var(--terminal-bg-tertiary, #f0f0f0);
	color: var(--terminal-text-secondary);
	border-color: var(--terminal-border, #e5e5e5);
}

.terminal-issues-status-open {
	background: color-mix(in srgb, var(--terminal-success, #10b981) 15%, transparent);
	color: var(--terminal-success, #10b981);
	border-color: var(--terminal-success, #10b981);
}

.terminal-issues-status-in-progress {
	background: color-mix(in srgb, var(--terminal-warning, #f59e0b) 15%, transparent);
	color: var(--terminal-warning, #f59e0b);
	border-color: var(--terminal-warning, #f59e0b);
}

.terminal-issues-status-resolved {
	background: color-mix(in srgb, var(--terminal-success, #10b981) 15%, transparent);
	color: var(--terminal-success, #10b981);
	border-color: var(--terminal-success, #10b981);
}

.terminal-issues-status-closed {
	background: var(--terminal-bg-tertiary, #f0f0f0);
	color: var(--terminal-text-secondary);
	border-color: var(--terminal-border, #e5e5e5);
}

.terminal-issues-priority-badge {
	background: var(--terminal-bg-tertiary, #f0f0f0);
	color: var(--terminal-text-secondary, #858585);
	border-color: var(--terminal-border, #e5e5e5);
}

.terminal-issues-priority-low {
	background: var(--terminal-bg-tertiary, #f0f0f0);
	color: var(--terminal-text-secondary, #858585);
	border-color: var(--terminal-border, #e5e5e5);
}

.terminal-issues-priority-medium {
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 15%, transparent);
	color: var(--terminal-primary, #0e639c);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-issues-priority-high {
	background: color-mix(in srgb, var(--terminal-error, #f48771) 15%, transparent);
	color: var(--terminal-error, #f48771);
	border-color: var(--terminal-error, #f48771);
}

.terminal-issues-priority-critical {
	background: color-mix(in srgb, var(--terminal-error, #f48771) 20%, transparent);
	color: var(--terminal-error, #f48771);
	border-color: var(--terminal-error, #f48771);
}

.terminal-issues-item-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
	align-items: center;
}

.terminal-issues-action-btn {
	padding: 6px;
	background: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s;
	min-width: 32px;
	height: 32px;
}

.terminal-issues-action-btn:hover {
	background: var(--terminal-bg-secondary, #e8e8e8);
	border-color: var(--terminal-primary, #0e639c);
	color: var(--terminal-text, #333333);
}

.terminal-issues-action-btn-danger {
	border-color: var(--terminal-error, #f48771);
	background: color-mix(in srgb, var(--terminal-error, #f48771) 10%, transparent);
	color: var(--terminal-error, #f48771);
}

.terminal-issues-action-btn-danger:hover {
	background: color-mix(in srgb, var(--terminal-error, #f48771) 20%, transparent);
	border-color: var(--terminal-error, #f48771);
	color: var(--terminal-error, #f48771);
}

.terminal-issues-item-description {
	margin-bottom: 8px;
	color: var(--terminal-text-secondary);
	font-size: 12px;
	line-height: 1.5;
}

.terminal-issues-item-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 11px;
	color: var(--terminal-text-secondary);
	margin-bottom: 8px;
}

.terminal-issues-item-source {
	margin-top: 8px;
}

.terminal-issues-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
	background: var(--terminal-bg-secondary, #f5f5f5);
}

.terminal-issues-pagination-info {
	font-size: 12px;
	color: var(--terminal-text-secondary);
}

/* Modal */
.terminal-issues-modal-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: color-mix(in srgb, var(--terminal-text, #333333) 70%, transparent);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.terminal-issues-modal {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	width: 90%;
	max-width: 600px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
}

.terminal-issues-modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-issues-modal-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-issues-modal-close {
	background: transparent;
	border: none;
	padding: 4px;
	cursor: pointer;
	color: var(--terminal-text-secondary);
	display: flex;
	align-items: center;
	justify-content: center;
	transition: color 0.2s;
}

.terminal-issues-modal-close:hover {
	color: var(--terminal-text);
}

.terminal-issues-modal-body {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.terminal-issues-modal-error {
	padding: 12px;
	background: color-mix(in srgb, var(--terminal-error, #f48771) 10%, transparent);
	border: 1px solid var(--terminal-error, #f48771);
	border-radius: 4px;
	color: var(--terminal-error, #f48771);
	font-size: 12px;
	margin-bottom: 16px;
}

.terminal-issues-modal-field {
	margin-bottom: 16px;
}

.terminal-issues-modal-field label {
	display: block;
	margin-bottom: 6px;
	font-size: 12px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-issues-modal-input,
.terminal-issues-modal-textarea {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 12px;
	font-family: inherit;
}

.terminal-issues-modal-input:focus,
.terminal-issues-modal-textarea:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-issues-modal-textarea {
	resize: vertical;
	min-height: 80px;
}

.terminal-issues-modal-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.terminal-issues-modal-footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 16px;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
}

.terminal-btn {
	padding: 6px 12px;
	font-size: 12px;
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	background: var(--terminal-bg);
	color: var(--terminal-text);
	cursor: pointer;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	gap: 6px;
	font-family: inherit;
}

.terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg-secondary, #e8e8e8);
	border-color: var(--terminal-primary);
}

.terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	border-color: var(--terminal-primary);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-secondary {
	background: var(--terminal-bg);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg-secondary, #e8e8e8);
	border-color: var(--terminal-primary);
}

.terminal-btn-sm {
	padding: 4px 8px;
	font-size: 11px;
}

.spinner {
	display: inline-block;
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border, #e5e5e5);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.6s linear infinite;
	margin-right: 8px;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>

