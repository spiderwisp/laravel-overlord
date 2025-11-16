<script setup>
import { ref, computed, nextTick, watch, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	currentCommand: {
		type: String,
		default: '',
	},
});

const emit = defineEmits(['insert-command', 'close', 'add-to-favorites']);

const templatesSearch = ref('');
const activeTemplateTab = ref('templates'); // 'templates', 'snippets', 'builder'
const userSnippets = ref([]);
const builderCommand = ref(''); // Store built command for favorites
const models = ref([]);
const loadingModels = ref(false);
const modelFields = ref([]);
const loadingFields = ref(false);
const commandBuilder = ref({
	model: '',
	action: 'get', // get, count, first, find
	actionValue: null,
	wheres: [],
	withs: [],
	selects: [],
	orderBy: { field: '', direction: 'asc' },
	limit: null,
});

// Dynamic Common Patterns based on actual models
const commonPatternsTemplates = computed(() => {
	if (models.value.length === 0) {
		return {
			category: 'Common Patterns',
			icon: '⭐',
			items: [
				{ name: 'Count All', command: 'Model::count()', description: 'Count all records' },
				{ name: 'Get All', command: 'Model::all()', description: 'Get all records' },
				{ name: 'Get First', command: 'Model::first()', description: 'Get first record' },
			],
		};
	}
	
	const items = [];
	const firstModel = models.value[0];
	const secondModel = models.value[1];
	const thirdModel = models.value[2];
	
	// First model examples
	if (firstModel) {
		items.push(
			{ name: `${firstModel} Count`, command: `${firstModel}::count()`, description: `Count ${firstModel.toLowerCase()} records` },
			{ name: `Get All ${firstModel}`, command: `${firstModel}::all()`, description: `Get all ${firstModel.toLowerCase()} records` }
		);
	}
	
	// Second model examples
	if (secondModel) {
		items.push(
			{ name: `${secondModel} Count`, command: `${secondModel}::count()`, description: `Count ${secondModel.toLowerCase()} records` },
			{ name: `Get Latest ${secondModel}`, command: `${secondModel}::latest('created_at')->take(10)->get()`, description: `Get recent ${secondModel.toLowerCase()} records` }
		);
	}
	
	// Third model examples
	if (thirdModel) {
		items.push(
			{ name: `Find ${thirdModel} by ID`, command: `${thirdModel}::find(1)`, description: `Find ${thirdModel.toLowerCase()} by ID` }
		);
	}
	
	return {
		category: 'Common Patterns',
		icon: '⭐',
		items,
	};
});

// Combined command templates with dynamic common patterns
const commandTemplates = computed(() => {
	const staticTemplates = [
		{
			category: 'Basic Queries',
			icon: '📊',
			items: [
				{ name: 'Count All', command: 'Model::count()', description: 'Count all records' },
				{ name: 'Get All', command: 'Model::all()', description: 'Get all records' },
				{ name: 'Get First', command: 'Model::first()', description: 'Get first record' },
				{ name: 'Find by ID', command: 'Model::find(1)', description: 'Find record by ID' },
				{ name: 'Get Latest', command: 'Model::latest()->first()', description: 'Get most recent record' },
			],
		},
		{
			category: 'Where Clauses',
			icon: '🔍',
			items: [
				{ name: 'Where Equal', command: 'Model::where(\'field\', \'value\')->get()', description: 'Where field equals value' },
				{ name: 'Where In', command: 'Model::whereIn(\'id\', [1, 2, 3])->get()', description: 'Where ID in array' },
				{ name: 'Where Null', command: 'Model::whereNull(\'deleted_at\')->get()', description: 'Where field is null' },
				{ name: 'Where Not Null', command: 'Model::whereNotNull(\'email\')->get()', description: 'Where field is not null' },
				{ name: 'Where Between', command: 'Model::whereBetween(\'created_at\', [$start, $end])->get()', description: 'Where between dates' },
				{ name: 'Where Like', command: 'Model::where(\'name\', \'LIKE\', \'%search%\')->get()', description: 'Where like search' },
			],
		},
		{
			category: 'Relationships',
			icon: '🔗',
			items: [
				{ name: 'With Relationship', command: 'Model::with(\'relationship\')->get()', description: 'Eager load relationship' },
				{ name: 'With Multiple', command: 'Model::with([\'rel1\', \'rel2\'])->get()', description: 'Eager load multiple' },
				{ name: 'Has Relationship', command: 'Model::has(\'relationship\')->get()', description: 'Has relationship' },
				{ name: 'Where Has', command: 'Model::whereHas(\'relationship\', fn($q) => $q->where(\'field\', \'value\'))->get()', description: 'Where has relationship' },
				{ name: 'Load Relationship', command: '$model->load(\'relationship\')', description: 'Load relationship on model' },
			],
		},
		{
			category: 'Aggregations',
			icon: '📈',
			items: [
				{ name: 'Sum', command: 'Model::sum(\'field\')', description: 'Sum of field' },
				{ name: 'Average', command: 'Model::avg(\'field\')', description: 'Average of field' },
				{ name: 'Max', command: 'Model::max(\'field\')', description: 'Maximum value' },
				{ name: 'Min', command: 'Model::min(\'field\')', description: 'Minimum value' },
				{ name: 'Group By', command: 'Model::groupBy(\'field\')->selectRaw(\'field, count(*) as count\')->get()', description: 'Group by field' },
			],
		},
		{
			category: 'Chunking & Pagination',
			icon: '📦',
			items: [
				{ name: 'Chunk', command: 'Model::chunk(100, fn($items) => foreach($items as $item) { /* process */ })', description: 'Process in chunks' },
				{ name: 'Chunk By ID', command: 'Model::chunkById(100, fn($items) => { /* process */ })', description: 'Chunk by ID' },
				{ name: 'Paginate', command: 'Model::paginate(15)', description: 'Paginated results' },
				{ name: 'Simple Paginate', command: 'Model::simplePaginate(15)', description: 'Simple pagination' },
				{ name: 'Lazy', command: 'Model::lazy()->each(fn($item) => { /* process */ })', description: 'Lazy collection' },
			],
		},
		{
			category: 'Updates & Deletes',
			icon: '✏️',
			items: [
				{ name: 'Update All', command: 'Model::where(\'field\', \'value\')->update([\'field\' => \'new_value\'])', description: 'Update matching records' },
				{ name: 'Delete', command: 'Model::where(\'field\', \'value\')->delete()', description: 'Delete matching records' },
				{ name: 'Soft Delete', command: '$model->delete()', description: 'Soft delete (if enabled)' },
				{ name: 'Restore', command: 'Model::withTrashed()->where(\'id\', 1)->restore()', description: 'Restore soft deleted' },
				{ name: 'Force Delete', command: 'Model::withTrashed()->where(\'id\', 1)->forceDelete()', description: 'Permanently delete' },
			],
		},
	];
	
	// Add dynamic common patterns at the end
	return [...staticTemplates, commonPatternsTemplates.value];
});

// Load models from API
async function loadModels() {
	if (loadingModels.value) return;
	
	loadingModels.value = true;
	try {
		const response = await axios.get(api.url('model-relationships'));
		if (response.data && response.data.success && response.data.result) {
			models.value = response.data.result.models || [];
		}
	} catch (error) {
		console.error('Failed to load models:', error);
		models.value = [];
	} finally {
		loadingModels.value = false;
	}
}

// Available models computed from API
const availableModels = computed(() => models.value);

// Load model fields from API
async function loadModelFields(modelName) {
	if (!modelName || loadingFields.value) return;
	
	loadingFields.value = true;
	modelFields.value = [];
	
	try {
		const response = await axios.get(api.url('model-fields'), {
			params: { model: modelName }
		});
		if (response.data && response.data.success && response.data.result) {
			modelFields.value = response.data.result.fields || [];
		}
	} catch (error) {
		console.error('Failed to load model fields:', error);
		modelFields.value = [];
	} finally {
		loadingFields.value = false;
	}
}

// Watch for model changes to load fields
watch(() => commandBuilder.value.model, (newModel) => {
	if (newModel) {
		loadModelFields(newModel);
		// Clear existing where clauses when model changes
		commandBuilder.value.wheres = [];
	} else {
		modelFields.value = [];
	}
});

// Load user snippets from localStorage
function loadSnippets() {
	const saved = localStorage.getItem('developer_terminal_snippets');
	if (saved) {
		try {
			userSnippets.value = JSON.parse(saved);
		} catch (e) {
			userSnippets.value = [];
		}
	}
}

// Save user snippets to localStorage
function saveSnippets() {
	localStorage.setItem('developer_terminal_snippets', JSON.stringify(userSnippets.value));
}

// Add snippet
function addSnippet(name = null, command = null, description = '') {
	const snippetName = name || prompt('Enter snippet name:');
	if (!snippetName) return;
	
	const snippetCommand = command || props.currentCommand || prompt('Enter command:');
	if (!snippetCommand) return;
	
	userSnippets.value.push({
		id: Date.now(),
		name: snippetName,
		command: snippetCommand,
		description,
		createdAt: new Date().toISOString(),
	});
	saveSnippets();
}

// Save current command as snippet
function saveCurrentCommandAsSnippet() {
	if (!props.currentCommand.trim()) {
		Swal.fire({
			icon: 'warning',
			title: 'Warning',
			text: 'Please enter a command first',
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
		return;
	}
	const name = prompt('Enter a name for this snippet:');
	if (!name) return;
	addSnippet(name, props.currentCommand, '');
}

// Delete snippet
function deleteSnippet(id) {
	userSnippets.value = userSnippets.value.filter(s => s.id !== id);
	saveSnippets();
}

// Insert command into input
function insertCommand(command) {
	// Replace Model placeholder with actual model if needed
	let finalCommand = command;
	if (commandBuilder.value.model) {
		finalCommand = finalCommand.replace(/Model/g, commandBuilder.value.model);
	}
	
	emit('insert-command', finalCommand);
}

// Add template to favorites
function addTemplateToFavorites(template) {
	emit('add-to-favorites', {
		name: template.name,
		description: template.description || '',
		category: template.category || '',
		tags: [],
		content: template.command,
		type: 'template',
		metadata: {
			templateName: template.name,
			category: template.category,
		},
	});
}

// Add snippet to favorites
function addSnippetToFavorites(snippet) {
	emit('add-to-favorites', {
		name: snippet.name,
		description: snippet.description || '',
		category: '',
		tags: [],
		content: snippet.command,
		type: 'snippet',
		metadata: {
			snippetId: snippet.id,
		},
	});
}

// Add builder command to favorites
function addBuilderToFavorites(command) {
	if (!command || !command.trim()) return;
	
	emit('add-to-favorites', {
		name: '',
		description: '',
		category: '',
		tags: [],
		content: command,
		type: 'builder',
		metadata: {
			builder: { ...commandBuilder.value },
		},
	});
}

// Build command from builder
function buildCommand() {
	const builder = commandBuilder.value;
	if (!builder.model) {
		Swal.fire({
			icon: 'warning',
			title: 'Warning',
			text: 'Please select a model',
			toast: true,
			position: 'top-end',
			showConfirmButton: false,
			timer: 3000,
		});
		return;
	}
	
	let command = builder.model;
	let parts = [];
	
	// Build query parts
	builder.wheres.forEach((where) => {
		if (where.field && where.operator && where.value !== '') {
			let value = where.value;
			if (where.operator === 'IN') {
				const values = value.split(',').map(v => v.trim()).filter(v => v);
				parts.push(`whereIn('${where.field}', [${values.map(v => isNaN(v) ? `'${v}'` : v).join(', ')}])`);
			} else if (!isNaN(value) && where.operator !== 'LIKE') {
				parts.push(`where('${where.field}', '${where.operator}', ${value})`);
			} else {
				parts.push(`where('${where.field}', '${where.operator}', '${value}')`);
			}
		}
	});
	
	// Only add with() if there are non-empty relationships
	const validWiths = builder.withs.filter(w => w && w.trim());
	if (validWiths.length > 0) {
		const withList = validWiths.map(w => `'${w.trim()}'`).join(', ');
		parts.push(`with([${withList}])`);
	}
	
	// Only add select() if there are non-empty fields
	const validSelects = builder.selects.filter(s => s && s.trim());
	if (validSelects.length > 0) {
		const selectList = validSelects.map(s => `'${s.trim()}'`).join(', ');
		parts.push(`select([${selectList}])`);
	}
	
	if (builder.orderBy.field) {
		parts.push(`orderBy('${builder.orderBy.field}', '${builder.orderBy.direction}')`);
	}
	
	if (builder.limit) {
		parts.push(`take(${builder.limit})`);
	}
	
	if (parts.length > 0) {
		command += '::' + parts.join('->');
	}
	
	if (builder.action === 'find' && builder.actionValue) {
		if (parts.length > 0) {
			command += `->find(${builder.actionValue})`;
		} else {
			command += `::find(${builder.actionValue})`;
		}
	} else {
		if (parts.length > 0) {
			command += `->${builder.action}()`;
		} else {
			command += `::${builder.action}()`;
		}
	}
	
	// Store the built command for favorites
	builderCommand.value = command;
	
	insertCommand(command);
}

// Add where clause to builder
function addWhereClause() {
	commandBuilder.value.wheres.push({ field: '', operator: '=', value: '' });
}

// Remove where clause
function removeWhereClause(index) {
	commandBuilder.value.wheres.splice(index, 1);
}

// Add with relationship
function addWithRelationship() {
	commandBuilder.value.withs.push('');
}

// Remove with relationship
function removeWithRelationship(index) {
	commandBuilder.value.withs.splice(index, 1);
}

// Add select field
function addSelectField() {
	commandBuilder.value.selects.push('');
}

// Remove select field
function removeSelectField(index) {
	commandBuilder.value.selects.splice(index, 1);
}

// Filter templates by search
const filteredTemplates = computed(() => {
	if (!templatesSearch.value) {
		return commandTemplates.value;
	}
	
	const search = templatesSearch.value.toLowerCase();
	return commandTemplates.value.map(category => ({
		...category,
		items: category.items.filter(item => 
			item.name.toLowerCase().includes(search) ||
			item.command.toLowerCase().includes(search) ||
			item.description.toLowerCase().includes(search)
		),
	})).filter(category => category.items.length > 0);
});

// Watch for visibility changes to load models
watch(() => props.visible, (newValue) => {
	if (newValue && models.value.length === 0) {
		loadModels();
	}
});

// Load models on mount if visible
onMounted(() => {
	if (props.visible) {
		loadModels();
	}
	loadSnippets();
});
</script>

<template>
	<div v-if="visible" class="terminal-templates-view">
		<div class="terminal-templates-header">
			<div class="terminal-templates-tabs">
				<button
					@click="activeTemplateTab = 'templates'"
					:class="['terminal-template-tab', { 'active': activeTemplateTab === 'templates' }]"
				>
					📋 Queries
				</button>
				<button
					@click="activeTemplateTab = 'snippets'"
					:class="['terminal-template-tab', { 'active': activeTemplateTab === 'snippets' }]"
				>
					⭐ Snippets
				</button>
				<button
					@click="activeTemplateTab = 'builder'"
					:class="['terminal-template-tab', { 'active': activeTemplateTab === 'builder' }]"
				>
					🔨 Builder
				</button>
			</div>
			<button
				@click="$emit('close')"
				class="terminal-btn terminal-btn-close"
				title="Close"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>
		
		<div class="terminal-templates-content">
			<!-- Templates Tab -->
			<div v-if="activeTemplateTab === 'templates'" class="terminal-templates-tab-content">
				<div class="terminal-templates-search">
					<input
						v-model="templatesSearch"
						type="text"
						placeholder="Search templates..."
						class="terminal-templates-search-input"
					/>
				</div>
				<div class="terminal-templates-list">
					<div
						v-for="category in filteredTemplates"
						:key="category.category"
						class="terminal-template-category"
					>
						<div class="terminal-template-category-header">
							<span class="terminal-template-category-icon">{{ category.icon }}</span>
							<span class="terminal-template-category-name">{{ category.category }}</span>
						</div>
						<div class="terminal-template-items">
							<div
								v-for="item in category.items"
								:key="item.name"
								class="terminal-template-item"
								:title="item.description"
							>
								<div class="terminal-template-item-content" @click="insertCommand(item.command)">
									<div class="terminal-template-item-name">{{ item.name }}</div>
									<div class="terminal-template-item-command">{{ item.command }}</div>
									<div class="terminal-template-item-description">{{ item.description }}</div>
								</div>
								<button
									@click.stop="addTemplateToFavorites(item)"
									class="terminal-template-item-favorite"
									title="Add to Favorites"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
									</svg>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Snippets Tab -->
			<div v-if="activeTemplateTab === 'snippets'" class="terminal-templates-tab-content">
				<div class="terminal-snippets-header">
					<button
						@click="addSnippet()"
						class="terminal-btn terminal-btn-secondary"
					>
						+ Add Snippet
					</button>
					<button
						@click="saveCurrentCommandAsSnippet"
						class="terminal-btn terminal-btn-primary"
						:disabled="!currentCommand.trim()"
						title="Save current command as snippet"
					>
						💾 Save Current Command
					</button>
				</div>
				<div v-if="userSnippets.length === 0" class="terminal-snippets-empty">
					<p>No snippets saved yet.</p>
					<p class="terminal-snippets-empty-hint">Click "Add Snippet" to create your first snippet.</p>
				</div>
				<div v-else class="terminal-snippets-list">
					<div
						v-for="snippet in userSnippets"
						:key="snippet.id"
						class="terminal-snippet-item"
					>
						<div class="terminal-snippet-header">
							<div class="terminal-snippet-name">{{ snippet.name }}</div>
							<div class="terminal-snippet-actions">
								<button
									@click.stop="addSnippetToFavorites(snippet)"
									class="terminal-btn terminal-btn-secondary terminal-btn-xs"
									title="Add to Favorites"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
									</svg>
								</button>
								<button
									@click.stop="deleteSnippet(snippet.id)"
									class="terminal-btn terminal-btn-close terminal-btn-xs"
									title="Delete snippet"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
									</svg>
								</button>
							</div>
						</div>
						<div
							class="terminal-snippet-command"
							@click="insertCommand(snippet.command)"
						>
							{{ snippet.command }}
						</div>
						<div v-if="snippet.description" class="terminal-snippet-description">
							{{ snippet.description }}
						</div>
					</div>
				</div>
			</div>
			
			<!-- Command Builder Tab -->
			<div v-if="activeTemplateTab === 'builder'" class="terminal-templates-tab-content">
				<div class="terminal-builder">
					<div class="terminal-builder-section">
						<label class="terminal-builder-label">Model</label>
						<select v-model="commandBuilder.model" class="terminal-builder-select" :disabled="loadingModels">
							<option value="">{{ loadingModels ? 'Loading models...' : 'Select a model...' }}</option>
							<option v-if="!loadingModels && availableModels.length === 0" value="" disabled>No models found</option>
							<option v-for="model in availableModels" :key="model" :value="model">{{ model }}</option>
						</select>
					</div>
					
					<div class="terminal-builder-section">
						<label class="terminal-builder-label">Action</label>
						<select v-model="commandBuilder.action" class="terminal-builder-select">
							<option value="get">Get (all)</option>
							<option value="first">First</option>
							<option value="count">Count</option>
							<option value="find">Find (by ID)</option>
						</select>
						<input
							v-if="commandBuilder.action === 'find'"
							v-model="commandBuilder.actionValue"
							type="number"
							placeholder="ID"
							class="terminal-builder-input"
						/>
					</div>
					
					<div class="terminal-builder-section">
						<div class="terminal-builder-section-header">
							<label class="terminal-builder-label">Where Clauses</label>
							<button @click="addWhereClause" class="terminal-btn terminal-btn-secondary terminal-btn-sm">
								+ Add
							</button>
						</div>
						<div
							v-for="(where, index) in commandBuilder.wheres"
							:key="index"
							class="terminal-builder-where-row"
						>
							<select
								v-model="where.field"
								class="terminal-builder-select terminal-builder-select-sm"
								:disabled="!commandBuilder.model || loadingFields"
							>
								<option value="">{{ loadingFields ? 'Loading fields...' : (commandBuilder.model ? 'Select field...' : 'Select model first') }}</option>
								<option v-if="!loadingFields && commandBuilder.model && modelFields.length === 0" value="" disabled>No fields found</option>
								<option v-for="field in modelFields" :key="field.name" :value="field.name">{{ field.name }} ({{ field.type }})</option>
							</select>
							<select v-model="where.operator" class="terminal-builder-select terminal-builder-select-sm" :disabled="!where.field">
								<option value="=">=</option>
								<option value="!=">!=</option>
								<option value=">">&gt;</option>
								<option value="<">&lt;</option>
								<option value=">=">&gt;=</option>
								<option value="<=">&lt;=</option>
								<option value="LIKE">LIKE</option>
								<option value="IN">IN</option>
							</select>
							<input
								v-model="where.value"
								type="text"
								placeholder="Value"
								class="terminal-builder-input terminal-builder-input-sm"
							/>
							<button
								@click="removeWhereClause(index)"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
							>
								×
							</button>
						</div>
					</div>
					
					<div class="terminal-builder-section">
						<div class="terminal-builder-section-header">
							<label class="terminal-builder-label">Eager Load (with)</label>
							<button @click="addWithRelationship" class="terminal-btn terminal-btn-secondary terminal-btn-sm">
								+ Add
							</button>
						</div>
						<div
							v-for="(withRel, index) in commandBuilder.withs"
							:key="index"
							class="terminal-builder-row"
						>
							<input
								v-model="commandBuilder.withs[index]"
								type="text"
								placeholder="Relationship name"
								class="terminal-builder-input"
							/>
							<button
								@click="removeWithRelationship(index)"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
							>
								×
							</button>
						</div>
					</div>
					
					<div class="terminal-builder-section">
						<div class="terminal-builder-section-header">
							<label class="terminal-builder-label">Select Fields</label>
							<button @click="addSelectField" class="terminal-btn terminal-btn-secondary terminal-btn-sm">
								+ Add
							</button>
						</div>
						<div
							v-for="(select, index) in commandBuilder.selects"
							:key="index"
							class="terminal-builder-row"
						>
							<select
								v-model="commandBuilder.selects[index]"
								class="terminal-builder-select"
								:disabled="!commandBuilder.model || loadingFields"
							>
								<option value="">{{ loadingFields ? 'Loading fields...' : (commandBuilder.model ? 'Select field...' : 'Select model first') }}</option>
								<option v-if="!loadingFields && commandBuilder.model && modelFields.length === 0" value="" disabled>No fields found</option>
								<option v-for="field in modelFields" :key="field.name" :value="field.name">{{ field.name }} ({{ field.type }})</option>
							</select>
							<button
								@click="removeSelectField(index)"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
							>
								×
							</button>
						</div>
					</div>
					
					<div class="terminal-builder-section">
						<label class="terminal-builder-label">Order By</label>
						<div class="terminal-builder-row">
							<select
								v-model="commandBuilder.orderBy.field"
								class="terminal-builder-select"
								:disabled="!commandBuilder.model || loadingFields"
							>
								<option value="">{{ loadingFields ? 'Loading fields...' : (commandBuilder.model ? 'Select field...' : 'Select model first') }}</option>
								<option v-if="!loadingFields && commandBuilder.model && modelFields.length === 0" value="" disabled>No fields found</option>
								<option v-for="field in modelFields" :key="field.name" :value="field.name">{{ field.name }} ({{ field.type }})</option>
							</select>
							<select v-model="commandBuilder.orderBy.direction" class="terminal-builder-select">
								<option value="asc">Ascending</option>
								<option value="desc">Descending</option>
							</select>
						</div>
					</div>
					
					<div class="terminal-builder-section">
						<label class="terminal-builder-label">Limit</label>
						<input
							v-model.number="commandBuilder.limit"
							type="number"
							placeholder="Number of records"
							class="terminal-builder-input"
							min="1"
						/>
					</div>
					
					<div class="terminal-builder-actions">
						<button
							@click="buildCommand"
							class="terminal-btn terminal-btn-primary"
							:disabled="!commandBuilder.model"
						>
							Build & Insert Command
						</button>
						<button
							v-if="builderCommand"
							@click="addBuilderToFavorites(builderCommand)"
							class="terminal-btn terminal-btn-secondary"
							title="Add to Favorites"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
							</svg>
							Add to Favorites
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
/* Templates/Snippets Panel */
.terminal-templates-view {
	flex: 1;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg);
	overflow: hidden;
}

.terminal-templates-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-templates-tabs {
	display: flex;
	gap: 4px;
}

.terminal-template-tab {
	padding: 6px 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary);
	cursor: pointer;
	font-size: 12px;
	border-radius: 4px;
	transition: all 0.2s;
}

.terminal-template-tab:hover {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-template-tab.active {
	background: var(--terminal-primary);
	color: white;
}

.terminal-templates-content {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.terminal-templates-tab-content {
	height: 100%;
}

/* Templates Tab */
.terminal-templates-search {
	margin-bottom: 16px;
}

.terminal-templates-search-input {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	outline: none;
}

.terminal-templates-search-input:focus {
	border-color: var(--terminal-primary);
	background: var(--terminal-bg);
}

.terminal-templates-list {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.terminal-template-category {
	background: var(--terminal-bg-secondary);
	border-radius: 8px;
	padding: 16px;
	border: 1px solid var(--terminal-border);
}

.terminal-template-category-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-template-category-icon {
	font-size: 18px;
}

.terminal-template-category-name {
	font-weight: 600;
	color: var(--terminal-text);
	font-size: 14px;
}

.terminal-template-items {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 12px;
}

.terminal-template-item {
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 6px;
	padding: 12px;
	transition: all 0.2s;
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 8px;
	position: relative;
}

.terminal-template-item-content {
	flex: 1;
	cursor: pointer;
}

.terminal-template-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(14, 99, 156, 0.2);
}

.terminal-template-item-name {
	font-weight: 600;
	color: var(--terminal-accent);
	font-size: 13px;
	margin-bottom: 6px;
}

.terminal-template-item-command {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
	margin-bottom: 4px;
	word-break: break-all;
}

.terminal-template-item-description {
	color: var(--terminal-text-secondary);
	font-size: 11px;
	font-style: italic;
}

.terminal-template-item-favorite {
	padding: 4px;
	background: transparent;
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text-muted);
	cursor: pointer;
	transition: all 0.2s;
	flex-shrink: 0;
}

.terminal-template-item-favorite:hover {
	background: var(--terminal-border);
	border-color: var(--terminal-primary);
	color: var(--terminal-primary);
}

.terminal-template-item-favorite svg {
	width: 16px;
	height: 16px;
}

/* Snippets Tab */
.terminal-snippets-header {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.terminal-snippets-empty {
	text-align: center;
	padding: 40px 20px;
	color: var(--terminal-text-secondary);
}

.terminal-snippets-empty-hint {
	font-size: 12px;
	margin-top: 8px;
}

.terminal-snippets-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-snippet-item {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 6px;
	padding: 12px;
	transition: all 0.2s;
}

.terminal-snippet-item:hover {
	border-color: var(--terminal-primary);
	background: var(--terminal-bg-tertiary);
}

.terminal-snippet-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.terminal-snippet-actions {
	display: flex;
	gap: 4px;
	align-items: center;
}

.terminal-snippet-name {
	font-weight: 600;
	color: var(--terminal-accent);
	font-size: 13px;
}

.terminal-snippet-command {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
	padding: 8px;
	background: var(--terminal-bg);
	border-radius: 4px;
	cursor: pointer;
	margin-bottom: 4px;
	word-break: break-all;
	transition: background 0.2s;
}

.terminal-snippet-command:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-snippet-description {
	color: var(--terminal-text-secondary);
	font-size: 11px;
	margin-top: 4px;
}

/* Command Builder Tab */
.terminal-builder {
	display: flex;
	flex-direction: column;
	gap: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.terminal-builder-section {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 6px;
	padding: 16px;
}

.terminal-builder-section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.terminal-builder-label {
	display: block;
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 8px;
}

.terminal-builder-select,
.terminal-builder-input {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	outline: none;
	font-family: inherit;
}

.terminal-builder-select:focus,
.terminal-builder-input:focus {
	border-color: var(--terminal-primary);
	background: var(--terminal-bg-tertiary);
}

.terminal-builder-select-sm,
.terminal-builder-input-sm {
	font-size: 12px;
	padding: 6px 8px;
}

.terminal-builder-row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.terminal-builder-row:last-child {
	margin-bottom: 0;
}

.terminal-builder-row .terminal-builder-input {
	flex: 1;
}

.terminal-builder-where-row {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 8px;
}

.terminal-builder-where-row:last-child {
	margin-bottom: 0;
}

.terminal-builder-where-row .terminal-builder-input-sm {
	flex: 1;
}

.terminal-builder-where-row .terminal-builder-select-sm {
	width: 100px;
}

.terminal-builder-actions {
	display: flex;
	justify-content: center;
	gap: 8px;
	margin-top: 8px;
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
}

.terminal-btn-primary {
	background: var(--terminal-primary);
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

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary);
	padding: 4px;
}

.terminal-btn-close:hover {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-btn-sm {
	padding: 4px 8px;
	font-size: 11px;
}

/* Scrollbar for templates panel */
.terminal-templates-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-templates-content::-webkit-scrollbar-track {
	background: var(--terminal-bg);
}

.terminal-templates-content::-webkit-scrollbar-thumb {
	background: var(--terminal-bg-tertiary);
	border-radius: 5px;
}

.terminal-templates-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-bg-secondary);
}

/* Firefox scrollbar styling */
.terminal-templates-content {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-bg-tertiary) var(--terminal-bg);
}
</style>

