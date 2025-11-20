<template>
	<div v-if="visible" class="terminal-database">
		<!-- Left Sidebar: Tables List -->
		<div class="database-sidebar">
			<div class="sidebar-header">
				<h3>Tables</h3>
			</div>
			<div class="sidebar-search">
				<input
					v-model="tableSearch"
					type="text"
					placeholder="Search tables..."
					class="sidebar-search-input"
				/>
			</div>
			<div class="sidebar-content">
				<div v-if="loadingTables" class="sidebar-loading">
					<p>Loading tables...</p>
				</div>
				<div v-else-if="tableError" class="sidebar-error">
					<p>{{ tableError }}</p>
				</div>
				<div v-else class="sidebar-tables-list">
					<div
						v-for="table in filteredTables"
						:key="table.name"
						class="sidebar-table-item"
						:class="{ 'active': selectedTable === table.name }"
						@click="selectTable(table.name)"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="table-icon">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
						</svg>
						<div class="table-item-info">
							<span class="table-name">{{ table.name }}</span>
							<span v-if="table.rows !== null" class="table-rows">{{ formatNumber(table.rows) }} rows</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Right Content Area -->
		<div class="database-content-area">
			<!-- Header with Breadcrumb and Close -->
			<div class="content-header">
				<div class="content-breadcrumb">
					<span class="breadcrumb-item">Database</span>
					<span v-if="selectedTable" class="breadcrumb-separator">/</span>
					<span v-if="selectedTable" class="breadcrumb-item">{{ selectedTable }}</span>
					<span v-if="selectedTable && activeView !== 'data'" class="breadcrumb-separator">/</span>
					<span v-if="selectedTable && activeView === 'structure'" class="breadcrumb-item">Structure</span>
					<span v-if="selectedTable && activeView === 'sql'" class="breadcrumb-item">SQL</span>
				</div>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px;">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>

			<!-- View Tabs (only show when table is selected) -->
			<div v-if="selectedTable" class="content-tabs">
				<button
					@click="switchView('data')"
					class="content-tab"
					:class="{ 'active': activeView === 'data' }"
				>
					Browse
				</button>
				<button
					@click="switchView('structure')"
					class="content-tab"
					:class="{ 'active': activeView === 'structure' }"
				>
					Structure
				</button>
				<button
					@click="switchView('sql')"
					class="content-tab"
					:class="{ 'active': activeView === 'sql' }"
				>
					SQL
				</button>
			</div>

			<!-- Content Area -->
			<div class="content-body">
				<!-- Empty State -->
				<div v-if="!selectedTable" class="content-empty">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="empty-icon">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
					</svg>
					<h3>Select a table to view</h3>
					<p>Choose a table from the sidebar to browse data, view structure, or run SQL queries.</p>
				</div>

				<!-- Data View -->
				<div v-else-if="activeView === 'data'" class="content-view">
					<div class="view-toolbar">
						<input
							v-model="dataSearch"
							type="text"
							placeholder="Search..."
							class="terminal-input terminal-input-search"
							@keyup.enter="loadTableData"
						/>
						<select v-model="searchColumn" class="terminal-select terminal-select-sm">
							<option value="">All Columns</option>
							<option v-for="col in tableColumns" :key="col" :value="col">{{ col }}</option>
						</select>
						<button
							@click="loadTableData"
							class="terminal-btn terminal-btn-primary terminal-btn-sm"
						>
							Search
						</button>
						<button
							@click="openRowEditor(null)"
							class="terminal-btn terminal-btn-primary terminal-btn-sm"
						>
							+ Insert Row
						</button>
					</div>
					<div v-if="loadingData" class="view-loading">
						<p>Loading data...</p>
					</div>
					<div v-else-if="dataError" class="view-error">
						<p>{{ dataError }}</p>
					</div>
					<div v-else class="view-data-grid">
						<div class="data-grid-wrapper">
							<table class="data-table">
								<thead>
									<tr>
										<th
											v-for="column in tableColumns"
											:key="column"
											@click="sortBy(column)"
											:class="{ 'sortable': true, 'sorted': sortColumn === column }"
										>
											{{ column }}
											<span v-if="sortColumn === column" class="sort-indicator">
												{{ sortDirection === 'asc' ? '↑' : '↓' }}
											</span>
										</th>
										<th class="actions-column">Actions</th>
									</tr>
								</thead>
								<tbody>
									<tr v-if="tableData.length === 0">
										<td :colspan="tableColumns.length + 1" class="no-data">No data found</td>
									</tr>
									<tr v-else v-for="(row, index) in tableData" :key="index" :class="{ 'row-even': index % 2 === 0 }">
										<td v-for="column in tableColumns" :key="column" :title="getFullCellValue(row[column])">
											{{ truncateValue(row[column]) }}
										</td>
										<td class="actions-column">
											<button
												@click="openRowEditor(row)"
												class="terminal-btn terminal-btn-sm terminal-btn-secondary"
												title="Edit"
											>
												Edit
											</button>
											<button
												@click="deleteRow(row)"
												class="terminal-btn terminal-btn-sm terminal-btn-danger"
												title="Delete"
											>
												Delete
											</button>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<div v-if="pagination" class="view-pagination">
							<button
								@click="changePage(pagination.current_page - 1)"
								:disabled="pagination.current_page <= 1"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Previous
							</button>
							<span class="pagination-info">
								Page {{ pagination.current_page }} of {{ pagination.last_page }} ({{ pagination.total }} total)
							</span>
							<button
								@click="changePage(pagination.current_page + 1)"
								:disabled="pagination.current_page >= pagination.last_page"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Next
							</button>
							<select v-model="perPage" @change="loadTableData" class="terminal-select terminal-select-sm">
								<option :value="25">25 per page</option>
								<option :value="50">50 per page</option>
								<option :value="100">100 per page</option>
							</select>
						</div>
					</div>
				</div>

				<!-- Structure View -->
				<div v-else-if="activeView === 'structure'" class="content-view">
					<div v-if="loadingStructure" class="view-loading">
						<p>Loading structure...</p>
					</div>
					<div v-else-if="structureError" class="view-error">
						<p>{{ structureError }}</p>
					</div>
					<div v-else-if="tableStructure" class="view-structure">
						<!-- Columns -->
						<div class="structure-section">
							<h3 class="structure-section-title">Columns</h3>
							<div class="structure-table-wrapper">
								<table class="structure-table">
									<thead>
										<tr>
											<th>Name</th>
											<th>Type</th>
											<th>Nullable</th>
											<th>Default</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(column, name) in tableStructure.columns" :key="name">
											<td class="column-name">{{ name }}</td>
											<td class="column-type">{{ column.type }}</td>
											<td class="column-nullable">{{ column.nullable ? 'Yes' : 'No' }}</td>
											<td class="column-default">{{ column.default || '-' }}</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Indexes -->
						<div v-if="tableStructure.indexes && tableStructure.indexes.length > 0" class="structure-section">
							<h3 class="structure-section-title">Indexes</h3>
							<div class="structure-table-wrapper">
								<table class="structure-table">
									<thead>
										<tr>
											<th>Name</th>
											<th>Columns</th>
											<th>Unique</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="index in tableStructure.indexes" :key="index.name">
											<td>{{ index.name }}</td>
											<td>{{ index.columns.join(', ') }}</td>
											<td><span :class="index.unique ? 'badge-unique' : 'badge-index'">{{ index.unique ? 'Yes' : 'No' }}</span></td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Foreign Keys -->
						<div v-if="tableStructure.foreign_keys && tableStructure.foreign_keys.length > 0" class="structure-section">
							<h3 class="structure-section-title">Foreign Keys</h3>
							<div class="structure-table-wrapper">
								<table class="structure-table">
									<thead>
										<tr>
											<th>Column</th>
											<th>References Table</th>
											<th>References Column</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="fk in tableStructure.foreign_keys" :key="fk.column">
											<td>{{ fk.column }}</td>
											<td class="fk-table">{{ fk.references_table }}</td>
											<td>{{ fk.references_column }}</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>

				<!-- SQL View -->
				<div v-else-if="activeView === 'sql'" class="content-view">
					<div class="view-sql-editor">
						<div class="sql-toolbar">
							<button
								@click="executeQuery"
								:disabled="executingQuery || !sqlQuery.trim()"
								class="terminal-btn terminal-btn-primary"
							>
								Execute
							</button>
							<button
								@click="clearQuery"
								class="terminal-btn terminal-btn-secondary terminal-btn-sm"
							>
								Clear
							</button>
							<select v-model="selectedHistoryQuery" @change="loadHistoryQuery" class="terminal-select terminal-select-sm">
								<option value="">Query History</option>
								<option v-for="(query, index) in queryHistory" :key="index" :value="index">
									{{ query.substring(0, 50) }}{{ query.length > 50 ? '...' : '' }}
								</option>
							</select>
						</div>
						<textarea
							v-model="sqlQuery"
							class="sql-textarea"
							placeholder="Enter SQL query (SELECT only)..."
							rows="10"
						></textarea>
						<div v-if="executingQuery" class="view-loading">
							<p>Executing query...</p>
						</div>
						<div v-else-if="queryError" class="view-error">
							<p>{{ queryError }}</p>
						</div>
						<div v-else-if="queryResults" class="view-query-results">
							<div class="query-results-header">
								<span>{{ queryResults.count }} row(s) returned</span>
								<span v-if="queryResults.execution_time">in {{ queryResults.execution_time }}s</span>
								<span v-if="queryResults.limited" class="warning">(Limited to 1000 rows)</span>
							</div>
							<div class="data-grid-wrapper">
								<table class="data-table">
									<thead>
										<tr>
											<th v-for="column in queryResultColumns" :key="column">{{ column }}</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(row, index) in queryResults.data" :key="index" :class="{ 'row-even': index % 2 === 0 }">
											<td v-for="column in queryResultColumns" :key="column" :title="getFullCellValue(row[column])">
												{{ truncateValue(row[column]) }}
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Row Editor Modal -->
		<div v-if="showRowEditor" class="terminal-database-modal-overlay" @click.self="closeRowEditor">
			<div class="terminal-database-modal">
				<div class="terminal-database-modal-header">
					<h3>{{ editingRow ? 'Edit Row' : 'Insert Row' }}</h3>
					<button @click="closeRowEditor" class="terminal-btn terminal-btn-close">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px;">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>
				<div class="terminal-database-modal-body">
					<div v-if="rowEditorError" class="terminal-database-error">
						<p>{{ rowEditorError }}</p>
					</div>
					<div v-for="(column, name) in tableStructure?.columns" :key="name" class="modal-field">
						<label>{{ name }} <span v-if="!column.nullable" class="required">*</span></label>
						<input
							v-if="column.type !== 'text'"
							v-model="rowEditorData[name]"
							type="text"
							:placeholder="column.default || ''"
							class="terminal-input"
						/>
						<textarea
							v-else
							v-model="rowEditorData[name]"
							:placeholder="column.default || ''"
							class="terminal-input"
							rows="3"
						></textarea>
					</div>
					<div class="modal-actions">
						<button
							@click="saveRow"
							:disabled="savingRow"
							class="terminal-btn terminal-btn-primary"
						>
							{{ savingRow ? 'Saving...' : (editingRow ? 'Update' : 'Insert') }}
						</button>
						<button
							@click="closeRowEditor"
							class="terminal-btn terminal-btn-secondary"
						>
							Cancel
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

const api = useOverlordApi();

// State
const activeView = ref('data');
const selectedTable = ref(null);
const tableSearch = ref('');
const loadingTables = ref(false);
const tableError = ref(null);
const tables = ref([]);

// Data view state
const loadingData = ref(false);
const dataError = ref(null);
const tableData = ref([]);
const tableColumns = ref([]);
const pagination = ref(null);
const currentPage = ref(1);
const perPage = ref(50);
const sortColumn = ref(null);
const sortDirection = ref('asc');
const dataSearch = ref('');
const searchColumn = ref('');

// Structure view state
const loadingStructure = ref(false);
const structureError = ref(null);
const tableStructure = ref(null);

// SQL view state
const sqlQuery = ref('');
const executingQuery = ref(false);
const queryError = ref(null);
const queryResults = ref(null);
const queryHistory = ref([]);
const selectedHistoryQuery = ref('');

// Row editor state
const showRowEditor = ref(false);
const editingRow = ref(null);
const rowEditorData = ref({});
const savingRow = ref(false);
const rowEditorError = ref(null);

// Field truncation max length
const maxFieldLength = ref(50);

// Computed
const filteredTables = computed(() => {
	if (!tableSearch.value) return tables.value;
	const search = tableSearch.value.toLowerCase();
	return tables.value.filter(table => 
		table.name.toLowerCase().includes(search)
	);
});

const queryResultColumns = computed(() => {
	if (!queryResults.value || !queryResults.value.data || queryResults.value.data.length === 0) {
		return [];
	}
	return Object.keys(queryResults.value.data[0]);
});

// Methods
function selectTable(tableName) {
	selectedTable.value = tableName;
	activeView.value = 'data';
	currentPage.value = 1;
	loadTableData();
}

function switchView(view) {
	activeView.value = view;
	if (view === 'data') {
		loadTableData();
	} else if (view === 'structure') {
		loadTableStructure();
	} else if (view === 'sql' && selectedTable.value) {
		if (!sqlQuery.value.trim()) {
			sqlQuery.value = `SELECT * FROM ${selectedTable.value} LIMIT 100;`;
		}
	}
}

function truncateValue(value, maxLength = null) {
	if (value === null || value === undefined) return 'NULL';
	const str = String(value);
	const limit = maxLength || maxFieldLength.value;
	if (str.length <= limit) return str;
	return str.substring(0, limit) + '...';
}

function getFullCellValue(value) {
	if (value === null || value === undefined) return 'NULL';
	if (typeof value === 'object') return JSON.stringify(value);
	const str = String(value);
	if (str.length <= maxFieldLength.value) return str;
	return str; // Return full value for tooltip
}

function loadTables() {
	loadingTables.value = true;
	tableError.value = null;
	
	axios.get(api.database.tables())
		.then(response => {
			if (response.data.success) {
				tables.value = response.data.tables;
			} else {
				tableError.value = response.data.error || 'Failed to load tables';
			}
		})
		.catch(error => {
			tableError.value = error.response?.data?.error || 'Failed to load tables';
		})
		.finally(() => {
			loadingTables.value = false;
		});
}

function loadTableData() {
	if (!selectedTable.value) return;
	
	loadingData.value = true;
	dataError.value = null;
	
	const params = {
		page: currentPage.value,
		per_page: perPage.value,
	};
	
	if (sortColumn.value) {
		params.sort_column = sortColumn.value;
		params.sort_direction = sortDirection.value;
	}
	
	if (dataSearch.value) {
		params.search = dataSearch.value;
		if (searchColumn.value) {
			params.search_column = searchColumn.value;
		}
	}
	
	axios.get(api.database.tableData(selectedTable.value, params))
		.then(response => {
			if (response.data.success) {
				tableData.value = response.data.data;
				pagination.value = response.data.pagination;
				
				// Extract columns from first row
				if (tableData.value.length > 0) {
					tableColumns.value = Object.keys(tableData.value[0]);
				} else if (tableColumns.value.length === 0) {
					// If no data, try to get columns from structure
					loadTableStructure(true);
				}
			} else {
				dataError.value = response.data.error || 'Failed to load table data';
			}
		})
		.catch(error => {
			dataError.value = error.response?.data?.error || 'Failed to load table data';
		})
		.finally(() => {
			loadingData.value = false;
		});
}

function loadTableStructure(silent = false) {
	if (!selectedTable.value) return;
	
	if (!silent) {
		loadingStructure.value = true;
		structureError.value = null;
	}
	
	axios.get(api.database.tableStructure(selectedTable.value))
		.then(response => {
			if (response.data.success) {
				tableStructure.value = response.data.structure;
				
				// If we don't have columns yet, extract from structure
				if (tableColumns.value.length === 0 && tableStructure.value.columns) {
					tableColumns.value = Object.keys(tableStructure.value.columns);
				}
			} else {
				structureError.value = response.data.error || 'Failed to load table structure';
			}
		})
		.catch(error => {
			structureError.value = error.response?.data?.error || 'Failed to load table structure';
		})
		.finally(() => {
			if (!silent) {
				loadingStructure.value = false;
			}
		});
}

function sortBy(column) {
	if (sortColumn.value === column) {
		sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
	} else {
		sortColumn.value = column;
		sortDirection.value = 'asc';
	}
	currentPage.value = 1;
	loadTableData();
}

function changePage(page) {
	if (page < 1 || (pagination.value && page > pagination.value.last_page)) return;
	currentPage.value = page;
	loadTableData();
}

function executeQuery() {
	if (!sqlQuery.value.trim()) return;
	
	executingQuery.value = true;
	queryError.value = null;
	queryResults.value = null;
	
	// Add to history
	if (!queryHistory.value.includes(sqlQuery.value)) {
		queryHistory.value.unshift(sqlQuery.value);
		if (queryHistory.value.length > 20) {
			queryHistory.value = queryHistory.value.slice(0, 20);
		}
		// Save to localStorage
		localStorage.setItem('overlord_database_query_history', JSON.stringify(queryHistory.value));
	}
	
	axios.post(api.database.executeQuery(), { query: sqlQuery.value })
		.then(response => {
			if (response.data.success) {
				queryResults.value = response.data;
			} else {
				queryError.value = response.data.error || 'Query execution failed';
			}
		})
		.catch(error => {
			queryError.value = error.response?.data?.error || 'Query execution failed';
		})
		.finally(() => {
			executingQuery.value = false;
		});
}

function clearQuery() {
	sqlQuery.value = '';
	queryResults.value = null;
	queryError.value = null;
}

function loadHistoryQuery() {
	if (selectedHistoryQuery.value !== '') {
		const index = parseInt(selectedHistoryQuery.value);
		if (queryHistory.value[index]) {
			sqlQuery.value = queryHistory.value[index];
		}
		selectedHistoryQuery.value = '';
	}
}

function openRowEditor(row) {
	editingRow.value = row;
	rowEditorError.value = null;
	showRowEditor.value = true;
	
	// Load structure if not loaded
	if (!tableStructure.value) {
		loadingStructure.value = true;
		axios.get(api.database.tableStructure(selectedTable.value))
			.then(response => {
				if (response.data.success) {
					tableStructure.value = response.data.structure;
					initializeRowEditor();
				} else {
					rowEditorError.value = response.data.error || 'Failed to load table structure';
				}
			})
			.catch(error => {
				rowEditorError.value = error.response?.data?.error || 'Failed to load table structure';
			})
			.finally(() => {
				loadingStructure.value = false;
			});
	} else {
		initializeRowEditor();
	}
}

function initializeRowEditor() {
	rowEditorData.value = {};
	
	if (tableStructure.value && tableStructure.value.columns) {
		Object.keys(tableStructure.value.columns).forEach(column => {
			if (editingRow.value && editingRow.value[column] !== undefined) {
				rowEditorData.value[column] = editingRow.value[column];
			} else {
				rowEditorData.value[column] = '';
			}
		});
	}
}

function closeRowEditor() {
	showRowEditor.value = false;
	editingRow.value = null;
	rowEditorData.value = {};
	rowEditorError.value = null;
}

function saveRow() {
	if (!selectedTable.value) return;
	
	savingRow.value = true;
	rowEditorError.value = null;
	
	// Clean up empty strings for nullable fields
	const data = {};
	Object.keys(rowEditorData.value).forEach(key => {
		const value = rowEditorData.value[key];
		if (value !== '' || (tableStructure.value.columns[key] && !tableStructure.value.columns[key].nullable)) {
			data[key] = value;
		}
	});
	
	// Determine primary key for update
	let rowId = null;
	if (editingRow.value) {
		rowId = editingRow.value.id;
		if (!rowId && tableColumns.value.length > 0) {
			rowId = editingRow.value[tableColumns.value[0]];
		}
		if (!rowId) {
			rowEditorError.value = 'Could not determine row identifier';
			savingRow.value = false;
			return;
		}
	}
	
	const request = editingRow.value
		? axios.put(api.database.updateRow(selectedTable.value, rowId), { data })
		: axios.post(api.database.createRow(selectedTable.value), { data });
	
	request
		.then(response => {
			if (response.data.success) {
				Swal.fire({
					icon: 'success',
					title: editingRow.value ? 'Row Updated' : 'Row Created',
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 2000,
				});
				closeRowEditor();
				if (activeView.value === 'data') {
					loadTableData();
				}
			} else {
				rowEditorError.value = response.data.error || 'Failed to save row';
			}
		})
		.catch(error => {
			rowEditorError.value = error.response?.data?.error || 'Failed to save row';
		})
		.finally(() => {
			savingRow.value = false;
		});
}

function deleteRow(row) {
	Swal.fire({
		title: 'Delete Row?',
		text: 'This action cannot be undone.',
		icon: 'warning',
		showCancelButton: true,
		confirmButtonText: 'Delete',
		cancelButtonText: 'Cancel',
	}).then((result) => {
		if (result.isConfirmed) {
			// Try to find primary key (id or first column)
			let id = row.id;
			if (!id && tableColumns.value.length > 0) {
				id = row[tableColumns.value[0]];
			}
			if (!id) {
				Swal.fire({
					icon: 'error',
					title: 'Cannot Delete',
					text: 'Could not determine row identifier',
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 3000,
				});
				return;
			}
			
			axios.delete(api.database.deleteRow(selectedTable.value, id))
				.then(response => {
					if (response.data.success) {
						Swal.fire({
							icon: 'success',
							title: 'Row Deleted',
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: 2000,
						});
						loadTableData();
					} else {
						Swal.fire({
							icon: 'error',
							title: 'Failed to Delete',
							text: response.data.error || 'Unknown error',
							toast: true,
							position: 'top-end',
							showConfirmButton: false,
							timer: 3000,
						});
					}
				})
				.catch(error => {
					Swal.fire({
						icon: 'error',
						title: 'Failed to Delete',
						text: error.response?.data?.error || 'Unknown error',
						toast: true,
						position: 'top-end',
						showConfirmButton: false,
						timer: 3000,
					});
				});
		}
	});
}

function formatNumber(num) {
	return new Intl.NumberFormat().format(num);
}

// Lifecycle
onMounted(() => {
	loadTables();
	
	// Load query history from localStorage
	const savedHistory = localStorage.getItem('overlord_database_query_history');
	if (savedHistory) {
		try {
			queryHistory.value = JSON.parse(savedHistory);
		} catch (e) {
			// Ignore
		}
	}
});

watch(() => props.visible, (newVal) => {
	if (newVal) {
		loadTables();
	}
});
</script>

<style scoped>
.terminal-database {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--terminal-bg);
	display: flex;
	flex-direction: row;
	z-index: 10002;
	pointer-events: auto;
	overflow: hidden;
}

/* Left Sidebar */
.database-sidebar {
	width: 280px;
	min-width: 280px;
	background: var(--terminal-bg-secondary);
	border-right: 1px solid var(--terminal-border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	flex-shrink: 0;
}

.sidebar-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.sidebar-header h3 {
	margin: 0;
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.sidebar-search {
	padding: 12px;
	border-bottom: 1px solid var(--terminal-border);
}

.sidebar-search-input {
	width: 100%;
	padding: 6px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 12px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.sidebar-search-input:focus {
	outline: none;
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 2px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.sidebar-search-input::placeholder {
	color: var(--terminal-text-muted);
}

.sidebar-content {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
}

.sidebar-loading,
.sidebar-error {
	padding: 20px;
	text-align: center;
	color: var(--terminal-text-secondary);
	font-size: 12px;
}

.sidebar-error {
	color: var(--terminal-error);
}

.sidebar-tables-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.sidebar-table-item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 12px;
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
}

.sidebar-table-item:hover {
	background: var(--terminal-bg-tertiary);
}

.sidebar-table-item.active {
	background: var(--terminal-bg-tertiary);
	border-left: 3px solid var(--terminal-primary);
	padding-left: 9px;
}

.table-icon {
	width: 18px;
	height: 18px;
	color: var(--terminal-primary);
	flex-shrink: 0;
}

.table-item-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.table-name {
	font-weight: 500;
	color: var(--terminal-text);
	font-size: 12px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.table-rows {
	color: var(--terminal-text-secondary);
	font-size: 10px;
}

/* Right Content Area */
.database-content-area {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	background: var(--terminal-bg);
}

.content-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.content-breadcrumb {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	color: var(--terminal-text);
}

.breadcrumb-separator {
	color: var(--terminal-text-secondary);
}

.breadcrumb-item {
	color: var(--terminal-text);
}

.content-tabs {
	display: flex;
	gap: 0;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.content-tab {
	padding: 10px 20px;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--terminal-text-secondary);
	font-size: 12px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.content-tab:hover {
	color: var(--terminal-text);
	background: var(--terminal-bg-tertiary);
}

.content-tab.active {
	color: var(--terminal-primary);
	border-bottom-color: var(--terminal-primary);
	background: var(--terminal-bg);
}

.content-body {
	flex: 1;
	overflow-y: auto;
	background: var(--terminal-bg);
}

.content-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 40px;
	text-align: center;
}

.empty-icon {
	width: 64px;
	height: 64px;
	color: var(--terminal-border);
	margin-bottom: 16px;
}

.content-empty h3 {
	margin: 0 0 8px 0;
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
}

.content-empty p {
	margin: 0;
	color: var(--terminal-text-secondary);
	font-size: 13px;
}

.content-view {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.view-toolbar {
	display: flex;
	gap: 8px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
	align-items: center;
}

.view-loading,
.view-error {
	padding: 40px;
	text-align: center;
	color: var(--terminal-text-secondary);
	font-size: 13px;
}

.view-error {
	color: var(--terminal-error);
}

.view-data-grid,
.view-structure,
.view-sql-editor {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.view-data-grid {
	padding: 16px;
}

.data-grid-wrapper {
	flex: 1;
	overflow: auto;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
}

.data-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}

.data-table thead {
	background: var(--terminal-bg-tertiary);
	position: sticky;
	top: 0;
	z-index: 10;
}

.data-table th {
	padding: 10px 12px;
	text-align: left;
	font-weight: 600;
	color: var(--terminal-text);
	border-bottom: 1px solid var(--terminal-border);
	white-space: nowrap;
}

.data-table th.sortable {
	cursor: pointer;
	user-select: none;
}

.data-table th.sortable:hover {
	background: var(--terminal-border);
}

.data-table th.sorted {
	color: var(--terminal-primary);
}

.sort-indicator {
	margin-left: 4px;
	color: var(--terminal-primary);
	font-weight: bold;
}

.data-table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--terminal-border);
	color: var(--terminal-text);
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.data-table tbody tr {
	transition: background 0.15s;
}

.data-table tbody tr:hover {
	background: var(--terminal-bg-tertiary);
}

.data-table tbody tr.row-even {
	background: var(--terminal-bg-secondary);
}

.data-table tbody tr.row-even:hover {
	background: var(--terminal-bg-tertiary);
}

.data-table tbody tr:last-child td {
	border-bottom: none;
}

.actions-column {
	width: 120px;
	text-align: center;
	white-space: nowrap;
}

.no-data {
	text-align: center;
	color: var(--terminal-text-secondary);
	padding: 40px;
}

.view-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-top: 1px solid var(--terminal-border);
	margin-top: 16px;
	border-radius: 4px;
}

.pagination-info {
	color: var(--terminal-text-secondary);
	font-size: 12px;
}

.view-structure {
	padding: 16px;
	gap: 24px;
}

.structure-section {
	margin-bottom: 32px;
}

.structure-section-title {
	margin: 0 0 12px 0;
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.structure-table-wrapper {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	overflow: hidden;
}

.structure-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}

.structure-table thead {
	background: var(--terminal-bg-tertiary);
}

.structure-table th {
	padding: 10px 12px;
	text-align: left;
	font-weight: 600;
	color: var(--terminal-text);
	border-bottom: 1px solid var(--terminal-border);
}

.structure-table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--terminal-border);
	color: var(--terminal-text);
}

.structure-table tbody tr:last-child td {
	border-bottom: none;
}

.column-name {
	font-weight: 500;
	color: var(--terminal-accent);
}

.column-type {
	color: var(--terminal-text-secondary);
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

.column-nullable {
	color: var(--terminal-text-secondary);
}

.column-default {
	color: var(--terminal-text-secondary);
	font-style: italic;
}

.fk-table {
	color: var(--terminal-accent);
	font-weight: 500;
}

.badge-unique {
	display: inline-block;
	padding: 2px 6px;
	background: var(--terminal-bg-tertiary);
	color: var(--terminal-accent);
	border-radius: 3px;
	font-size: 10px;
	font-weight: 600;
}

.badge-index {
	display: inline-block;
	padding: 2px 6px;
	background: var(--terminal-bg-tertiary);
	color: var(--terminal-text-secondary);
	border-radius: 3px;
	font-size: 10px;
}

.view-sql-editor {
	padding: 16px;
	gap: 16px;
}

.sql-toolbar {
	display: flex;
	gap: 8px;
	align-items: center;
}

.sql-textarea {
	width: 100%;
	padding: 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
	font-size: 13px;
	resize: vertical;
	min-height: 200px;
	flex: 1;
}

.sql-textarea:focus {
	outline: none;
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 2px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.view-query-results {
	display: flex;
	flex-direction: column;
	gap: 12px;
	flex: 1;
	overflow: hidden;
}

.query-results-header {
	display: flex;
	gap: 12px;
	align-items: center;
	color: var(--terminal-text-secondary);
	font-size: 12px;
}

.query-results-header .warning {
	color: var(--terminal-error);
}

/* Modal */
.terminal-database-modal-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--terminal-overlay, rgba(0, 0, 0, 0.7));
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10010;
}

.terminal-database-modal {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	width: 90%;
	max-width: 600px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
}

.terminal-database-modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-database-modal-header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-database-modal-body {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.modal-field {
	margin-bottom: 16px;
}

.modal-field label {
	display: block;
	margin-bottom: 6px;
	font-size: 12px;
	font-weight: 600;
	color: var(--terminal-text);
}

.modal-field .required {
	color: var(--terminal-error);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}

/* Form Inputs */
.terminal-input,
.terminal-select {
	padding: 8px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	width: 100%;
}

.terminal-input:focus,
.terminal-select:focus {
	outline: none;
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 2px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.terminal-input-search {
	max-width: 300px;
}

.terminal-select-sm {
	font-size: 12px;
	padding: 6px 8px;
}

/* Buttons */
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
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-btn-secondary:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-btn-danger {
	background: var(--terminal-error);
	color: #ffffff;
	border: 1px solid var(--terminal-error);
}

.terminal-btn-danger:hover {
	background: var(--terminal-error);
	border-color: var(--terminal-error);
	opacity: 0.9;
}

.terminal-btn-sm {
	padding: 4px 8px;
	font-size: 11px;
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary);
	padding: 4px;
}

.terminal-btn-close:hover {
	color: var(--terminal-text);
}

/* Scrollbar styling */
.sidebar-content::-webkit-scrollbar,
.content-body::-webkit-scrollbar,
.data-grid-wrapper::-webkit-scrollbar,
.terminal-database-modal-body::-webkit-scrollbar {
	width: 8px;
	height: 8px;
}

.sidebar-content::-webkit-scrollbar-track,
.content-body::-webkit-scrollbar-track,
.data-grid-wrapper::-webkit-scrollbar-track,
.terminal-database-modal-body::-webkit-scrollbar-track {
	background: var(--terminal-bg);
}

.sidebar-content::-webkit-scrollbar-thumb,
.content-body::-webkit-scrollbar-thumb,
.data-grid-wrapper::-webkit-scrollbar-thumb,
.terminal-database-modal-body::-webkit-scrollbar-thumb {
	background: var(--terminal-border);
	border-radius: 4px;
}

.sidebar-content::-webkit-scrollbar-thumb:hover,
.content-body::-webkit-scrollbar-thumb:hover,
.data-grid-wrapper::-webkit-scrollbar-thumb:hover,
.terminal-database-modal-body::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-bg-tertiary);
}

.sidebar-content,
.content-body,
.data-grid-wrapper,
.terminal-database-modal-body {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border) var(--terminal-bg);
}
</style>
