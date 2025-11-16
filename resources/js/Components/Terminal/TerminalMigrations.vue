<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import MigrationBuilder from './MigrationBuilder.vue';
import Swal from '../../utils/swalConfig';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

const loading = ref(false);
const migrations = ref([]);
const searchQuery = ref('');
const statusFilter = ref('all'); // all, run, pending, failed
const viewMode = ref('list'); // list, builder
const selectedMigration = ref(null);
const migrationDetails = ref(null);
const loadingDetails = ref(false);
const running = ref(false);
const rollingBack = ref(false);
const executionOutput = ref(null);
const executionError = ref(null);
const expandedMigrations = ref(new Set());

// Load migrations
async function loadMigrations() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const response = await axios.get(api.migrations.list());
		if (response.data && response.data.success && response.data.result) {
			migrations.value = response.data.result.migrations || [];
		} else {
			console.error('Failed to load migrations:', response.data);
		}
	} catch (error) {
		console.error('Error loading migrations:', error);
		if (error.response) {
			console.error('Response data:', error.response.data);
		}
	} finally {
		loading.value = false;
	}
}

// Filtered migrations
const filteredMigrations = computed(() => {
	let filtered = migrations.value;

	// Filter by search query
	if (searchQuery.value) {
		const query = searchQuery.value.toLowerCase();
		filtered = filtered.filter(m => 
			m.name.toLowerCase().includes(query) ||
			m.file.toLowerCase().includes(query) ||
			(m.tables && m.tables.some(t => t.toLowerCase().includes(query)))
		);
	}

	// Filter by status
	if (statusFilter.value !== 'all') {
		filtered = filtered.filter(m => m.status === statusFilter.value);
	}

	return filtered;
});

// Grouped migrations by status
const groupedMigrations = computed(() => {
	const groups = {
		run: [],
		pending: [],
		failed: [],
	};

	filteredMigrations.value.forEach(migration => {
		if (groups[migration.status]) {
			groups[migration.status].push(migration);
		}
	});

	return groups;
});

// Statistics
const stats = computed(() => {
	return {
		total: migrations.value.length,
		run: migrations.value.filter(m => m.status === 'run').length,
		pending: migrations.value.filter(m => m.status === 'pending').length,
		failed: migrations.value.filter(m => m.status === 'failed').length,
	};
});

// Toggle migration expansion
function toggleMigration(migration) {
	if (expandedMigrations.value.has(migration.name)) {
		expandedMigrations.value.delete(migration.name);
		selectedMigration.value = null;
		migrationDetails.value = null;
	} else {
		expandedMigrations.value.add(migration.name);
		selectMigration(migration);
	}
}

// Select migration and load details
async function selectMigration(migration) {
	selectedMigration.value = migration;
	migrationDetails.value = null;
	loadingDetails.value = true;

	try {
		const response = await axios.get(api.migrations.show(migration.name));
		if (response.data && response.data.success && response.data.result) {
			migrationDetails.value = response.data.result;
		}
	} catch (error) {
		console.error('Error loading migration details:', error);
	} finally {
		loadingDetails.value = false;
	}
}

// Run migrations
async function runMigration(migration = null) {
	// Fetch preview of what will run
	let preview = null;
	try {
		const params = {};
		if (migration) {
			params.migration = migration.full_name || migration.file;
		}
		const previewResponse = await axios.get(api.migrations.previewRun(), { params });
		if (previewResponse.data && previewResponse.data.success) {
			preview = previewResponse.data.result;
		}
	} catch (error) {
		console.error('Failed to fetch preview:', error);
	}

	// Build confirmation message with preview details
	let confirmHtml = '<div style="text-align: center;">';
	
	if (preview && preview.pending_migrations && preview.pending_migrations.length > 0) {
		confirmHtml += `<p style="margin-bottom: 1rem; font-weight: 600; text-align: center;">The following ${preview.total_pending} migration(s) will be executed:</p>`;
		confirmHtml += '<div style="display: flex; justify-content: center; margin-bottom: 1rem;">';
		confirmHtml += '<ul style="padding-left: 1.5rem; text-align: left; margin: 0;">';
		preview.pending_migrations.forEach(m => {
			confirmHtml += `<li style="margin-bottom: 0.5rem;"><strong>${m.name}</strong>`;
			if (m.tables && m.tables.length > 0) {
				confirmHtml += ` <span style="color: var(--terminal-text-secondary);">(${m.tables.join(', ')})</span>`;
			}
			confirmHtml += '</li>';
		});
		confirmHtml += '</ul>';
		confirmHtml += '</div>';

		if (preview.tables_to_create && preview.tables_to_create.length > 0) {
			confirmHtml += `<p style="margin-bottom: 0.5rem; text-align: center;"><strong>Tables to create:</strong> ${preview.tables_to_create.join(', ')}</p>`;
		}
		if (preview.tables_to_modify && preview.tables_to_modify.length > 0) {
			confirmHtml += `<p style="margin-bottom: 1rem; text-align: center;"><strong>Tables to modify:</strong> ${preview.tables_to_modify.join(', ')}</p>`;
		}

		confirmHtml += '<p style="color: var(--terminal-warning); margin-top: 1rem; text-align: center;"><strong>Warning:</strong> This will modify your database schema.</p>';
	} else {
		confirmHtml += migration 
			? `<p style="text-align: center;">Are you sure you want to run the migration "${migration.name}"?</p>`
			: '<p style="text-align: center;">Are you sure you want to run all pending migrations?</p>';
	}
	
	confirmHtml += '</div>';

	const result = await Swal.fire({
		title: 'Run Migrations',
		html: confirmHtml,
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Yes, Run',
		cancelButtonText: 'Cancel',
		confirmButtonColor: 'var(--terminal-primary)',
		width: '600px',
	});

	if (!result.isConfirmed) {
		return;
	}

	running.value = true;
	executionOutput.value = null;
	executionError.value = null;

	try {
		const payload = {};
		if (migration) {
			// Use full_name if available, otherwise fall back to file
			payload.migration = migration.full_name || migration.file;
		}

		const response = await axios.post(api.migrations.run(), payload);
		
		if (response.data && response.data.success) {
			executionOutput.value = response.data.result.output || 'Migration completed successfully.';
			
			// Parse output to extract summary
			const output = executionOutput.value;
			const migrationMatches = output.match(/Migrating:\s+(\d{4}_\d{2}_\d{2}_\d{6}_[^\s]+)/g) || [];
			const migratedCount = migrationMatches.length;
			
			let successHtml = '<div style="text-align: center;">';
			successHtml += `<p style="margin-bottom: 0.5rem;"><strong>✓ Migrations executed successfully!</strong></p>`;
			if (migratedCount > 0) {
				successHtml += `<p style="margin-bottom: 1rem; color: var(--terminal-text-secondary);">${migratedCount} migration(s) completed.</p>`;
			}
			if (output && output.trim().length > 0) {
				successHtml += `<details style="margin-top: 1rem; text-align: left;"><summary style="cursor: pointer; color: var(--terminal-primary); text-align: center; display: block; margin-bottom: 0.5rem;">View Output</summary>`;
				successHtml += `<pre style="margin-top: 0.5rem; padding: 0.75rem; background: var(--terminal-bg-secondary); border-radius: 4px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; text-align: left;">${output}</pre>`;
				successHtml += `</details>`;
			}
			successHtml += '</div>';
			
			await Swal.fire({
				title: 'Success',
				html: successHtml,
				icon: 'success',
				confirmButtonColor: 'var(--terminal-primary)',
				width: '600px',
			});

			// Reload migrations to update status
			await loadMigrations();
		} else {
			executionError.value = response.data?.errors?.[0] || 'Migration failed';
			executionOutput.value = response.data.result?.output || '';
			
			await Swal.fire({
				title: 'Error',
				text: executionError.value,
				icon: 'error',
				confirmButtonColor: 'var(--terminal-error)',
			});
		}
	} catch (error) {
		executionError.value = error.response?.data?.errors?.[0] || error.message || 'Failed to run migration';
		executionOutput.value = error.response?.data?.result?.output || '';
		
		await Swal.fire({
			title: 'Error',
			text: executionError.value,
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
	} finally {
		running.value = false;
	}
}

// Rollback migrations
async function rollbackMigration(migration = null) {
	// Fetch preview of what will be rolled back
	let preview = null;
	try {
		const params = {};
		if (migration && migration.batch) {
			params.batch = migration.batch;
		}
		const previewResponse = await axios.get(api.migrations.previewRollback(), { params });
		if (previewResponse.data && previewResponse.data.success) {
			preview = previewResponse.data.result;
		}
	} catch (error) {
		console.error('Failed to fetch rollback preview:', error);
	}

	// Build confirmation message with preview details
	let confirmHtml = '<div style="text-align: center;">';
	
	if (preview && preview.migrations_to_rollback && preview.migrations_to_rollback.length > 0) {
		confirmHtml += `<p style="margin-bottom: 1rem; font-weight: 600; color: var(--terminal-error);">The following ${preview.total_to_rollback} migration(s) will be rolled back:</p>`;
		confirmHtml += '<ul style="margin-bottom: 1rem; padding-left: 1.5rem; text-align: left; display: inline-block;">';
		preview.migrations_to_rollback.forEach(m => {
			confirmHtml += `<li style="margin-bottom: 0.5rem;"><strong>${m.name}</strong>`;
			if (m.tables && m.tables.length > 0) {
				confirmHtml += ` <span style="color: var(--terminal-text-secondary);">(${m.tables.join(', ')})</span>`;
			}
			confirmHtml += '</li>';
		});
		confirmHtml += '</ul>';

		if (preview.tables_to_affect && preview.tables_to_affect.length > 0) {
			confirmHtml += `<p style="margin-bottom: 1rem;"><strong>Tables that will be affected:</strong> ${preview.tables_to_affect.join(', ')}</p>`;
		}

		if (preview.data_loss_warning) {
			confirmHtml += '<p style="color: var(--terminal-error); font-weight: 600; margin-top: 1rem; padding: 0.75rem; background: color-mix(in srgb, var(--terminal-error) 10%, transparent); border-left: 3px solid var(--terminal-error); text-align: left;"><strong>⚠️ WARNING:</strong> These tables contain data. Rolling back may result in DATA LOSS!</p>';
		}

	} else {
		confirmHtml += migration
			? `<p>Are you sure you want to rollback the migration "${migration.name}"? This may result in data loss.</p>`
			: '<p>Are you sure you want to rollback the last batch of migrations? This may result in data loss.</p>';
	}
	
	confirmHtml += '</div>';

	const result = await Swal.fire({
		title: 'Rollback Migrations',
		html: confirmHtml,
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Yes, Rollback',
		cancelButtonText: 'Cancel',
		confirmButtonColor: 'var(--terminal-error)',
		width: '600px',
	});

	if (!result.isConfirmed) {
		return;
	}

	rollingBack.value = true;
	executionOutput.value = null;
	executionError.value = null;

	try {
		const payload = {};
		if (migration) {
			// Use full_name if available, otherwise fall back to file
			payload.migration = migration.full_name || migration.file;
		}

		const response = await axios.post(api.migrations.rollback(), payload);
		
		if (response.data && response.data.success) {
			executionOutput.value = response.data.result.output || 'Migration rolled back successfully.';
			
			// Parse output to extract summary
			const output = executionOutput.value;
			const rollbackMatches = output.match(/Rolling back:\s+(\d{4}_\d{2}_\d{2}_\d{6}_[^\s]+)/g) || [];
			const rolledBackCount = rollbackMatches.length;
			
			let successHtml = '<div style="text-align: center;">';
			successHtml += `<p style="margin-bottom: 0.5rem;"><strong>✓ Migrations rolled back successfully!</strong></p>`;
			if (rolledBackCount > 0) {
				successHtml += `<p style="margin-bottom: 1rem; color: var(--terminal-text-secondary);">${rolledBackCount} migration(s) rolled back.</p>`;
			}
			if (output && output.trim().length > 0) {
				successHtml += `<details style="margin-top: 1rem; text-align: left;"><summary style="cursor: pointer; color: var(--terminal-primary); text-align: center; display: block; margin-bottom: 0.5rem;">View Output</summary>`;
				successHtml += `<pre style="margin-top: 0.5rem; padding: 0.75rem; background: var(--terminal-bg-secondary); border-radius: 4px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; text-align: left;">${output}</pre>`;
				successHtml += `</details>`;
			}
			successHtml += '</div>';
			
			await Swal.fire({
				title: 'Success',
				html: successHtml,
				icon: 'success',
				confirmButtonColor: 'var(--terminal-primary)',
				width: '600px',
			});

			// Reload migrations to update status
			await loadMigrations();
		} else {
			executionError.value = response.data?.errors?.[0] || 'Rollback failed';
			executionOutput.value = response.data.result?.output || '';
			
			await Swal.fire({
				title: 'Error',
				text: executionError.value,
				icon: 'error',
				confirmButtonColor: 'var(--terminal-error)',
			});
		}
	} catch (error) {
		executionError.value = error.response?.data?.errors?.[0] || error.message || 'Failed to rollback migration';
		executionOutput.value = error.response?.data?.result?.output || '';
		
		await Swal.fire({
			title: 'Error',
			text: executionError.value,
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
	} finally {
		rollingBack.value = false;
	}
}

// Get status badge class
function getStatusClass(status) {
	return {
		'run': 'migration-status-run',
		'pending': 'migration-status-pending',
		'failed': 'migration-status-failed',
	}[status] || '';
}

// Get status icon
function getStatusIcon(status) {
	if (status === 'run') {
		return 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z';
	} else if (status === 'failed') {
		return 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z';
	} else {
		return 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z';
	}
}

// Format date
function formatDate(dateString) {
	if (!dateString) return 'N/A';
	const date = new Date(dateString);
	return date.toLocaleString();
}

// Format file size
function formatFileSize(bytes) {
	if (!bytes) return 'N/A';
	if (bytes < 1024) return bytes + ' B';
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
	return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}



// Handle migration created event
async function handleMigrationCreated() {
	// Reload migrations list
	await loadMigrations();
	// Switch back to list view
	viewMode.value = 'list';
}

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue) {
		if (migrations.value.length === 0) {
			loadMigrations();
		}
	}
});

onMounted(() => {
	if (props.visible) {
		loadMigrations();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-migrations">
		<div class="terminal-migrations-header">
			<div class="terminal-migrations-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
				</svg>
				<span>Migrations</span>
			</div>
			<div class="terminal-migrations-controls">
				<div class="terminal-migrations-view-toggle">
					<button
						@click="viewMode = 'list'"
						class="terminal-btn terminal-btn-secondary"
						:class="{ 'active': viewMode === 'list' }"
						title="List View"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
						</svg>
						<span>List</span>
					</button>
					<button
						@click="viewMode = 'builder'"
						class="terminal-btn terminal-btn-secondary"
						:class="{ 'active': viewMode === 'builder' }"
						title="Migration Builder"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
						</svg>
						<span>Builder</span>
					</button>
				</div>
				<input
					v-if="viewMode === 'list'"
					v-model="searchQuery"
					type="text"
					placeholder="Search migrations..."
					class="terminal-input terminal-migrations-search"
				/>
				<button
					@click="loadMigrations"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload migrations"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Migrations"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<div class="terminal-migrations-content">

			<!-- Statistics Bar -->
			<div v-if="viewMode === 'list'" class="terminal-migrations-stats">
				<div class="migration-stat">
					<span class="stat-label">Total:</span>
					<span class="stat-value">{{ stats.total }}</span>
				</div>
				<div class="migration-stat">
					<span class="stat-label">Run:</span>
					<span class="stat-value migration-stat-run">{{ stats.run }}</span>
				</div>
				<div class="migration-stat">
					<span class="stat-label">Pending:</span>
					<span class="stat-value migration-stat-pending">{{ stats.pending }}</span>
				</div>
				<div class="migration-stat">
					<span class="stat-label">Failed:</span>
					<span class="stat-value migration-stat-failed">{{ stats.failed }}</span>
				</div>
			</div>

			<!-- List View -->
			<div v-if="viewMode === 'list'" class="terminal-migrations-list-view">
				<!-- Filters -->
				<div class="terminal-migrations-filters">
					<select v-model="statusFilter" class="terminal-select">
						<option value="all">All Statuses</option>
						<option value="run">Run</option>
						<option value="pending">Pending</option>
						<option value="failed">Failed</option>
					</select>
					<button
						v-if="stats.pending > 0"
						@click="runMigration()"
						class="terminal-btn terminal-btn-primary"
						:disabled="running"
						title="Run All Pending Migrations"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
						</svg>
						<span>Run All Pending</span>
					</button>
				</div>

				<!-- Loading State -->
				<div v-if="loading" class="terminal-migrations-loading">
					<span class="spinner"></span>
					Loading migrations...
				</div>

				<!-- Migrations List -->
				<div v-else-if="filteredMigrations.length > 0" class="terminal-migrations-list">
					<!-- Pending Migrations -->
					<div v-if="groupedMigrations.pending.length > 0" class="migration-group">
						<div class="migration-group-header">
							<h3>Pending Migrations ({{ groupedMigrations.pending.length }})</h3>
						</div>
						<div class="migration-group-items">
							<div
								v-for="migration in groupedMigrations.pending"
								:key="migration.name"
								class="migration-item"
								:class="{ 'expanded': expandedMigrations.has(migration.name) }"
							>
								<div class="migration-item-header" @click="toggleMigration(migration)">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="migration-expand-icon"
										:class="{ 'expanded': expandedMigrations.has(migration.name) }"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
									</svg>
									<span class="migration-status-badge" :class="getStatusClass(migration.status)">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getStatusIcon(migration.status)" />
										</svg>
										{{ migration.status }}
									</span>
									<span class="migration-name" :title="migration.name">{{ migration.name }}</span>
									<div class="migration-actions" @click.stop>
										<button
											@click="runMigration(migration)"
											class="terminal-btn terminal-btn-primary terminal-btn-xs"
											:disabled="running"
											title="Run Migration"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
											</svg>
										</button>
									</div>
								</div>
								<div v-if="expandedMigrations.has(migration.name)" class="migration-item-details">
									<div v-if="loadingDetails" class="migration-details-loading">
										<span class="spinner"></span>
										Loading details...
									</div>
									<div v-else-if="migrationDetails" class="migration-details-content">
										<div class="migration-details-section">
											<h4>File</h4>
											<code>{{ migrationDetails.file }}</code>
										</div>
										<div class="migration-details-section">
											<h4>Status Information</h4>
											<div class="migration-info-grid">
												<div class="migration-info-item">
													<span class="info-label">Status:</span>
													<span class="migration-status-badge" :class="getStatusClass(migrationDetails.status)">
														{{ migrationDetails.status }}
													</span>
												</div>
												<div v-if="migrationDetails.batch" class="migration-info-item">
													<span class="info-label">Batch:</span>
													<span class="info-value">{{ migrationDetails.batch }}</span>
												</div>
												<div v-if="migrationDetails.file_timestamp" class="migration-info-item">
													<span class="info-label">Created:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_timestamp) }}</span>
												</div>
												<div v-if="migrationDetails.file_modified_at" class="migration-info-item">
													<span class="info-label">Last Modified:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_modified_at) }}</span>
												</div>
												<div v-if="migrationDetails.file_size" class="migration-info-item">
													<span class="info-label">File Size:</span>
													<span class="info-value">{{ formatFileSize(migrationDetails.file_size) }}</span>
												</div>
											</div>
										</div>
										<div v-if="migrationDetails.tables && migrationDetails.tables.length > 0" class="migration-details-section">
											<h4>Tables Affected</h4>
											<div class="migration-tables-list">
												<span v-for="table in migrationDetails.tables" :key="table" class="migration-table-badge">{{ table }}</span>
											</div>
										</div>
										<div v-if="migrationDetails.columns && migrationDetails.columns.length > 0" class="migration-details-section">
											<h4>Columns</h4>
											<div class="migration-columns-list">
												<span v-for="column in migrationDetails.columns" :key="column" class="migration-column-badge">{{ column }}</span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Failed Migrations -->
					<div v-if="groupedMigrations.failed.length > 0" class="migration-group">
						<div class="migration-group-header">
							<h3>Failed Migrations ({{ groupedMigrations.failed.length }})</h3>
						</div>
						<div class="migration-group-items">
							<div
								v-for="migration in groupedMigrations.failed"
								:key="migration.name"
								class="migration-item migration-item-failed"
								:class="{ 'expanded': expandedMigrations.has(migration.name) }"
							>
								<div class="migration-item-header" @click="toggleMigration(migration)">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="migration-expand-icon"
										:class="{ 'expanded': expandedMigrations.has(migration.name) }"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
									</svg>
									<span class="migration-status-badge" :class="getStatusClass(migration.status)">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getStatusIcon(migration.status)" />
										</svg>
										{{ migration.status }}
									</span>
									<span class="migration-name" :title="migration.name">{{ migration.name }}</span>
									<div class="migration-actions" @click.stop>
										<button
											@click="runMigration(migration)"
											class="terminal-btn terminal-btn-primary terminal-btn-xs"
											:disabled="running"
											title="Retry Migration"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
											</svg>
										</button>
									</div>
								</div>
								<div v-if="expandedMigrations.has(migration.name)" class="migration-item-details">
									<div v-if="loadingDetails" class="migration-details-loading">
										<span class="spinner"></span>
										Loading details...
									</div>
									<div v-else-if="migrationDetails" class="migration-details-content">
										<div class="migration-details-section">
											<h4>File</h4>
											<code>{{ migrationDetails.file }}</code>
										</div>
										<div class="migration-details-section">
											<h4>Status Information</h4>
											<div class="migration-info-grid">
												<div class="migration-info-item">
													<span class="info-label">Status:</span>
													<span class="migration-status-badge" :class="getStatusClass(migrationDetails.status)">
														{{ migrationDetails.status }}
													</span>
												</div>
												<div v-if="migrationDetails.batch" class="migration-info-item">
													<span class="info-label">Batch:</span>
													<span class="info-value">{{ migrationDetails.batch }}</span>
												</div>
												<div v-if="migrationDetails.file_timestamp" class="migration-info-item">
													<span class="info-label">Created:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_timestamp) }}</span>
												</div>
												<div v-if="migrationDetails.file_modified_at" class="migration-info-item">
													<span class="info-label">Last Modified:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_modified_at) }}</span>
												</div>
												<div v-if="migrationDetails.file_size" class="migration-info-item">
													<span class="info-label">File Size:</span>
													<span class="info-value">{{ formatFileSize(migrationDetails.file_size) }}</span>
												</div>
											</div>
										</div>
										<div class="migration-details-section">
											<h4>Error</h4>
											<div class="migration-error-message">
												This migration has failed. Check the logs for more details.
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Run Migrations -->
					<div v-if="groupedMigrations.run.length > 0" class="migration-group">
						<div class="migration-group-header">
							<h3>Run Migrations ({{ groupedMigrations.run.length }})</h3>
						</div>
						<div class="migration-group-items">
							<div
								v-for="migration in groupedMigrations.run"
								:key="migration.name"
								class="migration-item"
								:class="{ 'expanded': expandedMigrations.has(migration.name) }"
							>
								<div class="migration-item-header" @click="toggleMigration(migration)">
									<svg
										xmlns="http://www.w3.org/2000/svg"
										class="migration-expand-icon"
										:class="{ 'expanded': expandedMigrations.has(migration.name) }"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
									</svg>
									<span class="migration-status-badge" :class="getStatusClass(migration.status)">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getStatusIcon(migration.status)" />
										</svg>
										{{ migration.status }}
									</span>
									<span class="migration-name" :title="migration.name">{{ migration.name }}</span>
									<span v-if="migration.batch" class="migration-batch">Batch {{ migration.batch }}</span>
									<div class="migration-actions" @click.stop>
										<button
											@click="rollbackMigration(migration)"
											class="terminal-btn terminal-btn-danger terminal-btn-xs"
											:disabled="rollingBack"
											title="Rollback Migration"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
											</svg>
										</button>
									</div>
								</div>
								<div v-if="expandedMigrations.has(migration.name)" class="migration-item-details">
									<div v-if="loadingDetails" class="migration-details-loading">
										<span class="spinner"></span>
										Loading details...
									</div>
									<div v-else-if="migrationDetails" class="migration-details-content">
										<div class="migration-details-section">
											<h4>File</h4>
											<code>{{ migrationDetails.file }}</code>
										</div>
										<div class="migration-details-section">
											<h4>Status Information</h4>
											<div class="migration-info-grid">
												<div class="migration-info-item">
													<span class="info-label">Status:</span>
													<span class="migration-status-badge" :class="getStatusClass(migrationDetails.status)">
														{{ migrationDetails.status }}
													</span>
												</div>
												<div v-if="migrationDetails.batch" class="migration-info-item">
													<span class="info-label">Batch:</span>
													<span class="info-value">{{ migrationDetails.batch }}</span>
												</div>
												<div v-if="migrationDetails.file_timestamp" class="migration-info-item">
													<span class="info-label">Created:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_timestamp) }}</span>
												</div>
												<div v-if="migrationDetails.file_modified_at" class="migration-info-item">
													<span class="info-label">Last Modified:</span>
													<span class="info-value">{{ formatDate(migrationDetails.file_modified_at) }}</span>
												</div>
												<div v-if="migrationDetails.file_size" class="migration-info-item">
													<span class="info-label">File Size:</span>
													<span class="info-value">{{ formatFileSize(migrationDetails.file_size) }}</span>
												</div>
											</div>
										</div>
										<div v-if="migrationDetails.tables && migrationDetails.tables.length > 0" class="migration-details-section">
											<h4>Tables Affected</h4>
											<div class="migration-tables-list">
												<span v-for="table in migrationDetails.tables" :key="table" class="migration-table-badge">{{ table }}</span>
											</div>
										</div>
										<div v-if="migrationDetails.columns && migrationDetails.columns.length > 0" class="migration-details-section">
											<h4>Columns</h4>
											<div class="migration-columns-list">
												<span v-for="column in migrationDetails.columns" :key="column" class="migration-column-badge">{{ column }}</span>
											</div>
										</div>
										<div v-if="migrationDetails.foreign_keys && migrationDetails.foreign_keys.length > 0" class="migration-details-section">
											<h4>Foreign Keys</h4>
											<div class="migration-foreign-keys-list">
												<div v-for="(fk, index) in migrationDetails.foreign_keys" :key="index" class="migration-fk-item">
													<code>{{ fk.column }}</code> → <code>{{ fk.referenced_table }}.{{ fk.referenced_column }}</code>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Empty State -->
				<div v-else class="terminal-migrations-empty">
					<p>No migrations found.</p>
				</div>
			</div>

			<!-- Builder View -->
			<div v-else-if="viewMode === 'builder'" class="terminal-migrations-builder-view">
				<MigrationBuilder
					:visible="true"
					@close="viewMode = 'list'"
					@migration-created="handleMigrationCreated"
				/>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-migrations {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg, #ffffff);
	color: var(--terminal-text, #333333);
	z-index: 10002;
	pointer-events: auto;
}

.terminal-migrations-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	border-bottom: 1px solid var(--terminal-border, #e5e5e5);
}

.terminal-migrations-title {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.terminal-migrations-title svg {
	width: 24px;
	height: 24px;
	color: var(--terminal-primary, #0e639c);
}

.terminal-migrations-controls {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.terminal-migrations-view-toggle {
	display: flex;
	gap: 0.25rem;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	padding: 2px;
}

.terminal-migrations-view-toggle .terminal-btn {
	border: none;
	padding: 4px 8px;
}

.terminal-migrations-view-toggle .terminal-btn.active {
	background: var(--terminal-primary, #0e639c);
	color: #ffffff;
}

.terminal-migrations-search {
	width: 200px;
	padding: 6px 12px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	background: var(--terminal-bg, #ffffff);
	color: var(--terminal-text, #333333);
	font-size: 12px;
}

.terminal-migrations-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-migrations-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-migrations-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #ffffff);
	border-radius: 5px;
}

.terminal-migrations-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-migrations-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.terminal-migrations-stats {
	display: flex;
	gap: 1.5rem;
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--terminal-bg-secondary, #f5f5f5);
	border-radius: 6px;
	border: 1px solid var(--terminal-border, #e5e5e5);
}

.migration-stat {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.stat-label {
	font-size: 0.75rem;
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.stat-value {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--terminal-text, #333333);
}

.migration-stat-run {
	color: var(--terminal-success, #10b981);
}

.migration-stat-pending {
	color: var(--terminal-warning, #f59e0b);
}

.migration-stat-failed {
	color: var(--terminal-error, #ef4444);
}

.terminal-migrations-filters {
	display: flex;
	gap: 0.75rem;
	align-items: center;
	margin-bottom: 1.5rem;
}

.terminal-select {
	padding: 6px 12px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	background: var(--terminal-bg, #ffffff);
	color: var(--terminal-text, #333333);
	font-size: 12px;
	cursor: pointer;
}

.terminal-migrations-loading,
.terminal-migrations-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 3rem;
	color: var(--terminal-text-secondary, #858585);
	text-align: center;
}

.spinner {
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border, #e5e5e5);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
	margin-right: 0.5rem;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.migration-group {
	margin-bottom: 2rem;
}

.migration-group-header {
	margin-bottom: 0.75rem;
}

.migration-group-header h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.migration-group-items {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.migration-item {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 6px;
	overflow: hidden;
	transition: all 0.2s;
}

.migration-item:hover {
	background: var(--terminal-bg-tertiary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
}

.migration-item.expanded {
	border-color: var(--terminal-primary, #0e639c);
}

.migration-item-failed {
	border-color: var(--terminal-error, #ef4444);
}

.migration-item-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 1rem;
	cursor: pointer;
	user-select: none;
}

.migration-expand-icon {
	width: 16px;
	height: 16px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
	flex-shrink: 0;
}

.migration-expand-icon.expanded {
	transform: rotate(90deg);
}

.migration-status-badge {
	display: flex;
	align-items: center;
	gap: 0.25rem;
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.migration-status-badge svg {
	width: 14px;
	height: 14px;
}

.migration-status-run {
	background: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	color: var(--terminal-success, #10b981);
}

.migration-status-pending {
	background: color-mix(in srgb, var(--terminal-warning, #f59e0b) 20%, transparent);
	color: var(--terminal-warning, #f59e0b);
}

.migration-status-failed {
	background: color-mix(in srgb, var(--terminal-error, #ef4444) 20%, transparent);
	color: var(--terminal-error, #ef4444);
}

.migration-name {
	flex: 1;
	font-family: 'Courier New', monospace;
	font-size: 0.875rem;
	color: var(--terminal-text, #333333);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.migration-batch {
	font-size: 0.75rem;
	color: var(--terminal-text-secondary, #858585);
	padding: 0.25rem 0.5rem;
	background: var(--terminal-bg-tertiary, #f0f0f0);
	border-radius: 4px;
}

.migration-actions {
	display: flex;
	gap: 0.5rem;
}

.migration-item-details {
	padding: 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
	background: var(--terminal-bg, #ffffff);
}

.migration-details-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.migration-details-content {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.migration-details-section h4 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.migration-details-section code {
	font-family: 'Courier New', monospace;
	font-size: 0.75rem;
	color: var(--terminal-text, #333333);
	background: var(--terminal-bg-secondary, #f5f5f5);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
}

.migration-tables-list,
.migration-columns-list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.migration-table-badge,
.migration-column-badge {
	padding: 0.25rem 0.5rem;
	background: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	font-size: 0.75rem;
	font-family: 'Courier New', monospace;
	color: var(--terminal-text, #333333);
}

.migration-foreign-keys-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.migration-fk-item {
	font-size: 0.75rem;
	color: var(--terminal-text, #333333);
}

.migration-fk-item code {
	font-family: 'Courier New', monospace;
	background: var(--terminal-bg-secondary, #f5f5f5);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
}

.migration-info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
	margin-top: 0.5rem;
}

.migration-info-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.info-label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.info-value {
	font-size: 0.875rem;
	color: var(--terminal-text, #333333);
	font-weight: 500;
}

.migration-error-message {
	padding: 0.75rem;
	background: color-mix(in srgb, var(--terminal-error, #ef4444) 10%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-error, #ef4444) 30%, transparent);
	border-radius: 4px;
	color: var(--terminal-error, #ef4444);
	font-size: 0.875rem;
}

.terminal-migrations-builder-view {
	flex: 1;
	overflow: hidden;
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
	display: flex;
	align-items: center;
	gap: 4px;
	min-height: 32px;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: #ffffff;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-secondary {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	color: var(--terminal-text, #333333);
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
}

.terminal-btn-danger {
	background: var(--terminal-error, #ef4444);
	color: #ffffff;
}

.terminal-btn-danger:hover:not(:disabled) {
	background: color-mix(in srgb, var(--terminal-error, #ef4444) 90%, black);
}

.terminal-btn-xs {
	padding: 4px 8px;
	font-size: 11px;
	min-height: 24px;
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary, #858585);
	padding: 4px;
	border: none;
	min-width: auto;
}

.terminal-btn-close:hover {
	background: var(--terminal-bg-secondary, #f5f5f5);
	color: var(--terminal-text, #333333);
}

.terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

</style>

