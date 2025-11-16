<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'migration-created']);

// Form state
const migrationName = ref('');
const migrationType = ref('create');
const tableName = ref('');
const columns = ref([]);
const indexes = ref([]);
const foreignKeys = ref([]);
const userPrompt = ref('');
const generatedCode = ref('');
const editableCode = ref('');
const generating = ref(false);
const saving = ref(false);
const showPreview = ref(false);
const codeEdited = ref(false);
const errors = ref({});

// Column types
const columnTypes = [
	'string', 'text', 'integer', 'bigInteger', 'unsignedInteger', 'unsignedBigInteger',
	'float', 'double', 'decimal', 'boolean', 'date', 'dateTime', 'time', 'timestamp',
	'json', 'jsonb', 'uuid', 'char', 'binary', 'enum'
];

// Computed
const hasGeneratedCode = computed(() => generatedCode.value.trim().length > 0);
const highlightedCode = computed(() => {
	if (!editableCode.value) return '';
	return highlightCode(editableCode.value, 'php');
});

// Add column
function addColumn() {
	columns.value.push({
		name: '',
		type: 'string',
		length: null,
		nullable: false,
		default: null,
		unique: false,
		index: false,
	});
}

// Remove column
function removeColumn(index) {
	columns.value.splice(index, 1);
}

// Add index
function addIndex() {
	indexes.value.push({
		name: '',
		columns: [],
		unique: false,
	});
}

// Remove index
function removeIndex(index) {
	indexes.value.splice(index, 1);
}

// Add foreign key
function addForeignKey() {
	foreignKeys.value.push({
		column: '',
		referenced_table: '',
		referenced_column: 'id',
		onDelete: 'cascade',
		onUpdate: 'cascade',
	});
}

// Remove foreign key
function removeForeignKey(index) {
	foreignKeys.value.splice(index, 1);
}

// Validate form
function validateForm() {
	errors.value = {};
	
	if (!tableName.value.trim()) {
		errors.value.tableName = 'Table name is required';
		return false;
	}
	
	if (columns.value.length === 0 && !userPrompt.value.trim()) {
		errors.value.columns = 'Please add at least one column or provide a description';
		return false;
	}
	
	// Validate columns
	columns.value.forEach((col, index) => {
		if (!col.name.trim()) {
			errors.value[`column_${index}`] = 'Column name is required';
		}
	});
	
	return Object.keys(errors.value).length === 0;
}

// Generate migration with AI
async function generateWithAI() {
	if (!validateForm()) {
		const errorMessages = Object.values(errors.value).join(', ');
		await Swal.fire({
			title: 'Validation Error',
			text: errorMessages,
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
		return;
	}

	generating.value = true;
	generatedCode.value = '';
	editableCode.value = '';
	codeEdited.value = false;
	errors.value = {};

	try {
		const specs = {
			type: migrationType.value,
			table_name: tableName.value,
			columns: columns.value.filter(c => c.name.trim()),
			indexes: indexes.value.filter(i => i.name.trim() && i.columns.length > 0),
			foreign_keys: foreignKeys.value.filter(fk => fk.column.trim() && fk.referenced_table.trim()),
		};

		const payload = {
			specs,
		};

		if (userPrompt.value.trim()) {
			payload.user_prompt = userPrompt.value;
		}

		const response = await axios.post(api.migrations.generate(), payload);

		if (response.data && response.data.success) {
			generatedCode.value = response.data.result.code || '';
			editableCode.value = generatedCode.value;
			showPreview.value = true;
			codeEdited.value = false;

			await Swal.fire({
				title: 'Success',
				text: 'Migration code generated successfully!',
				icon: 'success',
				confirmButtonColor: 'var(--terminal-primary)',
				timer: 2000,
				showConfirmButton: false,
			});
		} else {
			throw new Error(response.data?.errors?.[0] || 'Failed to generate migration');
		}
	} catch (error) {
		const errorMessage = error.response?.data?.errors?.[0] || error.message || 'Failed to generate migration';
		await Swal.fire({
			title: 'Generation Error',
			html: `<div style="text-align: left;">${errorMessage}</div>`,
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
	} finally {
		generating.value = false;
	}
}

// Copy code to clipboard
async function copyToClipboard() {
	try {
		await navigator.clipboard.writeText(editableCode.value);
		await Swal.fire({
			title: 'Copied!',
			text: 'Code copied to clipboard',
			icon: 'success',
			confirmButtonColor: 'var(--terminal-primary)',
			timer: 1500,
			showConfirmButton: false,
		});
	} catch (error) {
		await Swal.fire({
			title: 'Error',
			text: 'Failed to copy to clipboard',
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
	}
}

// Save migration
async function saveMigration() {
	if (!migrationName.value.trim()) {
		await Swal.fire({
			title: 'Error',
			text: 'Please enter a migration name',
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
		return;
	}

	if (!editableCode.value.trim()) {
		await Swal.fire({
			title: 'Error',
			text: 'Please generate migration code first',
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
		return;
	}

	const result = await Swal.fire({
		title: 'Save Migration',
		html: `<div style="text-align: center;">
			<p>Create migration file: <strong>${migrationName.value}</strong>?</p>
			${codeEdited.value ? '<p style="color: var(--terminal-warning, #ff9800); margin-top: 0.5rem;"><small>Note: You have edited the generated code.</small></p>' : ''}
		</div>`,
		icon: 'question',
		showCancelButton: true,
		confirmButtonText: 'Yes, Save',
		cancelButtonText: 'Cancel',
		confirmButtonColor: 'var(--terminal-primary)',
	});

	if (!result.isConfirmed) {
		return;
	}

	saving.value = true;

	try {
		const response = await axios.post(api.migrations.create(), {
			migration_name: migrationName.value,
			code: editableCode.value,
		});

		if (response.data && response.data.success) {
			await Swal.fire({
				title: 'Success',
				text: `Migration file created: ${response.data.result.filename}`,
				icon: 'success',
				confirmButtonColor: 'var(--terminal-primary)',
			});

			// Reset form
			resetForm();
			emit('migration-created');
		} else {
			throw new Error(response.data?.errors?.[0] || 'Failed to create migration');
		}
	} catch (error) {
		await Swal.fire({
			title: 'Error',
			text: error.response?.data?.errors?.[0] || error.message || 'Failed to create migration file',
			icon: 'error',
			confirmButtonColor: 'var(--terminal-error)',
		});
	} finally {
		saving.value = false;
	}
}

// Clear all
function clearAll() {
	resetForm();
	errors.value = {};
}

// Reset form
function resetForm() {
	migrationName.value = '';
	migrationType.value = 'create';
	tableName.value = '';
	columns.value = [];
	indexes.value = [];
	foreignKeys.value = [];
	userPrompt.value = '';
	generatedCode.value = '';
	editableCode.value = '';
	showPreview.value = false;
	codeEdited.value = false;
	errors.value = {};
}

// Watch for code edits
watch(editableCode, (newValue) => {
	if (generatedCode.value && newValue !== generatedCode.value) {
		codeEdited.value = true;
	} else {
		codeEdited.value = false;
	}
});

// Auto-generate migration name from table name
watch(tableName, (newValue) => {
	if (newValue && !migrationName.value) {
		const name = migrationType.value === 'create' 
			? `create_${newValue}_table`
			: migrationType.value === 'modify'
			? `modify_${newValue}_table`
			: `drop_${newValue}_table`;
		migrationName.value = name;
	}
});

watch(migrationType, () => {
	if (tableName.value && !migrationName.value) {
		const name = migrationType.value === 'create' 
			? `create_${tableName.value}_table`
			: migrationType.value === 'modify'
			? `modify_${tableName.value}_table`
			: `drop_${tableName.value}_table`;
		migrationName.value = name;
	}
});
</script>

<template>
	<div v-if="visible" class="migration-builder">
		<div class="migration-builder-header">
			<div class="migration-builder-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
				</svg>
				<span>Create Migration</span>
			</div>
			<button
				@click="$emit('close')"
				class="terminal-btn terminal-btn-close"
				title="Close Builder"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
					<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<div class="migration-builder-content">
			<!-- Basic Info -->
			<div class="builder-section">
				<h3 class="builder-section-title">Basic Information</h3>
				<div class="builder-form-grid">
					<div class="builder-form-group">
						<label>Migration Name <span class="required">*</span></label>
						<input
							v-model="migrationName"
							type="text"
							placeholder="create_users_table"
							class="terminal-input"
							:class="{ 'error': errors.migrationName }"
						/>
						<span v-if="errors.migrationName" class="error-message">{{ errors.migrationName }}</span>
					</div>
					<div class="builder-form-group">
						<label>Migration Type <span class="required">*</span></label>
						<select v-model="migrationType" class="terminal-input">
							<option value="create">Create Table</option>
							<option value="modify">Modify Table</option>
							<option value="drop">Drop Table</option>
						</select>
					</div>
					<div class="builder-form-group">
						<label>Table Name <span class="required">*</span></label>
						<input
							v-model="tableName"
							type="text"
							placeholder="users"
							class="terminal-input"
							:class="{ 'error': errors.tableName }"
						/>
						<span v-if="errors.tableName" class="error-message">{{ errors.tableName }}</span>
					</div>
				</div>
			</div>

			<!-- Columns -->
			<div class="builder-section">
				<div class="builder-section-header">
					<h3 class="builder-section-title">Columns</h3>
					<button @click="addColumn" class="terminal-btn terminal-btn-secondary terminal-btn-sm">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
						</svg>
						<span>Add Column</span>
					</button>
				</div>
				<div v-if="columns.length === 0" class="builder-empty-state">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="empty-icon">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
					</svg>
					<p>No columns added yet.</p>
					<p class="empty-hint">Click "Add Column" to get started, or use AI assistance below.</p>
				</div>
				<div v-else class="builder-columns-list">
					<div v-for="(column, index) in columns" :key="index" class="builder-column-item">
						<div class="builder-column-header">
							<span class="column-number">Column {{ index + 1 }}</span>
							<button
								@click="removeColumn(index)"
								class="terminal-btn terminal-btn-danger terminal-btn-xs"
								title="Remove Column"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</div>
						<div class="builder-form-grid builder-form-grid-compact">
							<div class="builder-form-group">
								<label>Name <span class="required">*</span></label>
								<input
									v-model="column.name"
									type="text"
									placeholder="email"
									class="terminal-input"
									:class="{ 'error': errors[`column_${index}`] }"
								/>
								<span v-if="errors[`column_${index}`]" class="error-message">{{ errors[`column_${index}`] }}</span>
							</div>
							<div class="builder-form-group">
								<label>Type</label>
								<select v-model="column.type" class="terminal-input">
									<option v-for="type in columnTypes" :key="type" :value="type">{{ type }}</option>
								</select>
							</div>
							<div class="builder-form-group">
								<label>Length</label>
								<input
									v-model.number="column.length"
									type="number"
									placeholder="255"
									class="terminal-input"
								/>
							</div>
							<div class="builder-form-group builder-checkbox-group">
								<label class="checkbox-label">
									<input
										v-model="column.nullable"
										type="checkbox"
										class="checkbox-input"
									/>
									<span>Nullable</span>
								</label>
							</div>
							<div class="builder-form-group">
								<label>Default Value</label>
								<input
									v-model="column.default"
									type="text"
									placeholder="null"
									class="terminal-input"
								/>
							</div>
							<div class="builder-form-group builder-checkbox-group">
								<label class="checkbox-label">
									<input
										v-model="column.unique"
										type="checkbox"
										class="checkbox-input"
									/>
									<span>Unique</span>
								</label>
							</div>
							<div class="builder-form-group builder-checkbox-group">
								<label class="checkbox-label">
									<input
										v-model="column.index"
										type="checkbox"
										class="checkbox-input"
									/>
									<span>Index</span>
								</label>
							</div>
						</div>
					</div>
				</div>
				<span v-if="errors.columns" class="error-message section-error">{{ errors.columns }}</span>
			</div>

			<!-- AI Prompt -->
			<div class="builder-section builder-section-ai">
				<div class="builder-section-header">
					<h3 class="builder-section-title">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="ai-icon">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
						</svg>
						AI Assistance (Optional)
					</h3>
				</div>
				<div class="builder-form-group">
					<label>Describe your migration in natural language</label>
					<textarea
						v-model="userPrompt"
						placeholder="e.g., Create a posts table with title (string), content (text), author_id (unsignedBigInteger), published_at (timestamp, nullable). Add a foreign key from author_id to users.id."
						class="terminal-input"
						rows="4"
					></textarea>
					<p class="help-text">You can use AI alone, or combine it with the columns above for more precise control.</p>
				</div>
				<button
					@click="generateWithAI"
					class="terminal-btn terminal-btn-primary terminal-btn-lg"
					:disabled="generating"
				>
					<svg v-if="generating" xmlns="http://www.w3.org/2000/svg" class="spinner" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
					<svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
					</svg>
					<span>{{ generating ? 'Generating...' : 'Generate with AI' }}</span>
				</button>
			</div>

			<!-- Code Preview -->
			<div v-if="showPreview && hasGeneratedCode" class="builder-section builder-section-code">
				<div class="builder-section-header">
					<h3 class="builder-section-title">Generated Code Preview</h3>
					<div class="builder-section-actions">
						<button
							@click="copyToClipboard"
							class="terminal-btn terminal-btn-secondary terminal-btn-xs"
							title="Copy to Clipboard"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
							</svg>
							<span>Copy</span>
						</button>
						<button
							@click="showPreview = false"
							class="terminal-btn terminal-btn-secondary terminal-btn-xs"
						>
							<span>Hide</span>
						</button>
					</div>
				</div>
				<div v-if="codeEdited" class="code-edited-notice">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
					</svg>
					<span>Code has been edited</span>
				</div>
				<div class="builder-code-preview-wrapper">
					<pre class="builder-code-preview"><code class="hljs language-php" v-html="highlightedCode"></code></pre>
					<textarea
						v-model="editableCode"
						class="builder-code-editor"
						spellcheck="false"
						placeholder="Generated code will appear here..."
					></textarea>
				</div>
				<div class="builder-actions">
					<button
						@click="saveMigration"
						class="terminal-btn terminal-btn-primary"
						:disabled="saving || !migrationName.trim()"
					>
						<svg v-if="saving" xmlns="http://www.w3.org/2000/svg" class="spinner" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
						</svg>
						<svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
						</svg>
						<span>{{ saving ? 'Saving...' : 'Save Migration' }}</span>
					</button>
					<button
						@click="clearAll"
						class="terminal-btn terminal-btn-secondary"
					>
						<span>Clear All</span>
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.migration-builder {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg, #ffffff);
}

.migration-builder-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 1.25rem 1.75rem;
	border-bottom: 1px solid var(--terminal-border, #e5e5e5);
	background: var(--terminal-bg-secondary, #f5f5f5);
}

.migration-builder-title {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.migration-builder-title svg {
	width: 28px;
	height: 28px;
	color: var(--terminal-primary, #0e639c);
}

.migration-builder-content {
	flex: 1;
	overflow-y: auto;
	padding: 2rem;
	gap: 1.5rem;
	display: flex;
	flex-direction: column;
}

.builder-section {
	margin-bottom: 0;
	padding: 1.75rem;
	background: var(--terminal-bg-secondary, #f5f5f5);
	border-radius: 8px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	transition: box-shadow 0.2s ease;
}

.builder-section:hover {
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.builder-section-ai {
	background: linear-gradient(135deg, var(--terminal-bg-secondary, #f5f5f5) 0%, color-mix(in srgb, var(--terminal-primary, #0e639c) 5%, var(--terminal-bg-secondary, #f5f5f5)) 100%);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, var(--terminal-border, #e5e5e5));
}

.builder-section-code {
	background: var(--terminal-bg, #ffffff);
}

.builder-section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 1.5rem;
}

.builder-section-title {
	margin: 0;
	font-size: 1.125rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.ai-icon {
	width: 20px;
	height: 20px;
	color: var(--terminal-primary, #0e639c);
}

.builder-section-actions {
	display: flex;
	gap: 0.5rem;
}

.builder-form-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1.25rem;
}

.builder-form-grid-compact {
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.builder-form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.builder-form-group label {
	font-size: 0.875rem;
	font-weight: 500;
	color: var(--terminal-text, #333333);
	display: flex;
	align-items: center;
	gap: 0.25rem;
}

.required {
	color: var(--terminal-error, #dc3545);
}

.builder-checkbox-group {
	justify-content: flex-end;
}

.checkbox-label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
	flex-direction: row;
}

.checkbox-input {
	width: auto;
	margin: 0;
	cursor: pointer;
	accent-color: var(--terminal-primary, #0e639c);
}

/* Ensure terminal-input styles are applied */
.terminal-input {
	background: var(--terminal-bg-tertiary, #3e3e42) !important;
	color: var(--terminal-text, #d4d4d4) !important;
	border: 1px solid var(--terminal-border-hover, #464647) !important;
	border-radius: 4px !important;
	padding: 8px 12px !important;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace) !important;
	font-size: var(--terminal-font-size-base, 14px) !important;
	line-height: var(--terminal-line-height, 1.6) !important;
	outline: none !important;
	transition: border-color 0.2s ease, background-color 0.2s ease !important;
	width: 100% !important;
	box-sizing: border-box !important;
}

.terminal-input:focus {
	border-color: var(--terminal-primary, #0e639c) !important;
	background: var(--terminal-bg-secondary, #2d2d30) !important;
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent) !important;
}

.terminal-input::placeholder {
	color: var(--terminal-text-secondary, #858585) !important;
	opacity: 0.7 !important;
}

.terminal-input.error {
	border-color: var(--terminal-error, #dc3545) !important;
}

.terminal-input.error:focus {
	border-color: var(--terminal-error, #dc3545) !important;
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--terminal-error, #dc3545) 20%, transparent) !important;
}

/* Select styling */
select.terminal-input {
	appearance: none;
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23d4d4d4'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
	background-repeat: no-repeat;
	background-position: right 8px center;
	background-size: 16px;
	padding-right: 32px !important;
	cursor: pointer;
}

select.terminal-input:focus {
	background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%230e639c'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
}

/* Textarea styling */
textarea.terminal-input {
	resize: vertical;
	min-height: 80px;
}

.error-message {
	font-size: 0.75rem;
	color: var(--terminal-error, #dc3545);
	margin-top: 0.25rem;
}

.section-error {
	margin-top: 1rem;
	display: block;
}

.terminal-input.error {
	border-color: var(--terminal-error, #dc3545);
}

.help-text {
	font-size: 0.8125rem;
	color: var(--terminal-text-secondary, #858585);
	margin-top: 0.5rem;
	margin-bottom: 0;
}

.builder-empty-state {
	padding: 3rem 2rem;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.empty-icon {
	width: 64px;
	height: 64px;
	margin: 0 auto 1rem;
	opacity: 0.5;
}

.empty-hint {
	font-size: 0.875rem;
	margin-top: 0.5rem;
	opacity: 0.8;
}

.builder-columns-list {
	display: flex;
	flex-direction: column;
	gap: 1.25rem;
}

.builder-column-item {
	padding: 1.25rem;
	background: var(--terminal-bg, #ffffff);
	border-radius: 6px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.builder-column-item:hover {
	border-color: var(--terminal-primary, #0e639c);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.builder-column-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 1rem;
	padding-bottom: 0.75rem;
	border-bottom: 1px solid var(--terminal-border, #e5e5e5);
}

.column-number {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.builder-code-preview-wrapper {
	position: relative;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 6px;
	overflow: hidden;
	background: var(--terminal-bg, #ffffff);
}

.builder-code-preview {
	margin: 0;
	padding: 1.25rem;
	overflow-x: auto;
	font-family: 'Courier New', 'Consolas', 'Monaco', monospace;
	font-size: 0.875rem;
	line-height: 1.6;
	color: var(--terminal-text, #333333);
	max-height: 500px;
	overflow-y: auto;
	background: var(--terminal-bg, #ffffff);
	white-space: pre;
}

.builder-code-editor {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	width: 100%;
	height: 100%;
	padding: 1.25rem;
	margin: 0;
	border: none;
	outline: none;
	resize: none;
	font-family: 'Courier New', 'Consolas', 'Monaco', monospace;
	font-size: 0.875rem;
	line-height: 1.6;
	color: transparent;
	background: transparent;
	caret-color: var(--terminal-text, #333333);
	z-index: 1;
	white-space: pre;
	overflow: auto;
	tab-size: 4;
}

.builder-code-preview code {
	display: block;
	position: relative;
	z-index: 0;
}

.code-edited-notice {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	margin-bottom: 1rem;
	background: color-mix(in srgb, var(--terminal-warning, #ff9800) 10%, var(--terminal-bg, #ffffff));
	border: 1px solid color-mix(in srgb, var(--terminal-warning, #ff9800) 30%, var(--terminal-border, #e5e5e5));
	border-radius: 6px;
	color: var(--terminal-warning, #ff9800);
	font-size: 0.875rem;
	font-weight: 500;
}

.code-edited-notice svg {
	width: 18px;
	height: 18px;
}

.builder-actions {
	display: flex;
	gap: 1rem;
	margin-top: 1.5rem;
	flex-wrap: wrap;
}

/* Ensure terminal-btn styles are applied */
.terminal-btn {
	padding: 6px 12px !important;
	border: none !important;
	border-radius: 4px !important;
	cursor: pointer !important;
	font-size: var(--terminal-font-size-sm, 12px) !important;
	font-weight: 500 !important;
	transition: all 0.2s !important;
	display: inline-flex !important;
	align-items: center !important;
	justify-content: center !important;
	gap: 6px !important;
	min-height: 32px !important;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace) !important;
	text-decoration: none !important;
	white-space: nowrap !important;
	flex-shrink: 0 !important;
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c) !important;
	color: white !important;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb) !important;
}

.terminal-btn-primary:disabled {
	opacity: 0.5 !important;
	cursor: not-allowed !important;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-tertiary, #3e3e42) !important;
	color: var(--terminal-text, #d4d4d4) !important;
}

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-border-hover, #464647) !important;
}

.terminal-btn-secondary:disabled {
	opacity: 0.5 !important;
	cursor: not-allowed !important;
}

.terminal-btn-danger {
	background: transparent !important;
	color: var(--terminal-error, #f48771) !important;
}

.terminal-btn-danger:hover:not(:disabled) {
	background: var(--terminal-error, #f48771) !important;
	color: #ffffff !important;
}

.terminal-btn-close {
	background: transparent !important;
	color: var(--terminal-text-secondary, #858585) !important;
	padding: 4px !important;
}

.terminal-btn-close:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #2d2d30) !important;
	color: var(--terminal-text, #d4d4d4) !important;
}

.terminal-btn-xs {
	padding: 4px 8px !important;
	font-size: 11px !important;
	min-height: 24px !important;
	gap: 4px !important;
}

.terminal-btn-sm {
	padding: 0.5rem 1rem !important;
	font-size: 0.875rem !important;
}

.terminal-btn-lg {
	padding: 0.875rem 1.5rem !important;
	font-size: 1rem !important;
	min-height: 44px !important;
}

.terminal-btn svg {
	flex-shrink: 0 !important;
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-btn span {
	white-space: nowrap !important;
}

.terminal-btn-xs svg {
	width: 12px !important;
	height: 12px !important;
	max-width: 12px !important;
	max-height: 12px !important;
}

.spinner {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

/* Scrollbar styling */
.builder-code-preview::-webkit-scrollbar,
.builder-code-editor::-webkit-scrollbar {
	width: 8px;
	height: 8px;
}

.builder-code-preview::-webkit-scrollbar-track,
.builder-code-editor::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #f5f5f5);
}

.builder-code-preview::-webkit-scrollbar-thumb,
.builder-code-editor::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 4px;
}

.builder-code-preview::-webkit-scrollbar-thumb:hover,
.builder-code-editor::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-text-secondary, #858585);
}

/* Responsive */
@media (max-width: 768px) {
	.migration-builder-content {
		padding: 1rem;
	}
	
	.builder-section {
		padding: 1.25rem;
	}
	
	.builder-form-grid {
		grid-template-columns: 1fr;
	}
	
	.builder-form-grid-compact {
		grid-template-columns: 1fr;
	}
}
</style>
