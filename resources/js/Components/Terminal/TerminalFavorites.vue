<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import Swal from '../../utils/swalConfig';

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

const emit = defineEmits(['insert-command', 'execute-command', 'close']);

// Expose methods for parent component
defineExpose({
	addFavorite,
});

// State
const favorites = ref([]);
const categories = ref([]);
const tags = ref([]);
const searchQuery = ref('');
const selectedType = ref(null); // 'command' | 'template' | 'snippet' | 'builder' | 'custom' | null
const selectedCategory = ref(null);
const selectedTags = ref(new Set());
const expandedCategories = ref(new Set());
const editingFavorite = ref(null);
const showAddModal = ref(false);
const showEditModal = ref(false);
const showOrganizeModal = ref(false);

// Form state for add/edit
const favoriteForm = ref({
	name: '',
	description: '',
	category: '',
	tags: [],
	content: '',
	type: 'custom',
	metadata: {},
});

// Load favorites from localStorage
function loadFavorites() {
	const saved = localStorage.getItem('developer_terminal_favorites');
	if (saved) {
		try {
			const data = JSON.parse(saved);
			favorites.value = data.favorites || [];
			categories.value = data.categories || [];
			tags.value = data.tags || [];
		} catch (e) {
			console.error('Failed to load favorites:', e);
			favorites.value = [];
			categories.value = [];
			tags.value = [];
		}
	} else {
		favorites.value = [];
		categories.value = [];
		tags.value = [];
	}
}

// Save favorites to localStorage
function saveFavorites() {
	const data = {
		favorites: favorites.value,
		categories: categories.value,
		tags: tags.value,
	};
	localStorage.setItem('developer_terminal_favorites', JSON.stringify(data));
}

// Get type badge color
function getTypeColor(type) {
	const colors = {
		command: '#007acc',
		template: '#4fc3f7',
		snippet: '#9ca3af',
		builder: '#00d4aa',
		custom: '#ff9800',
	};
	return colors[type] || '#9ca3af';
}

// Get type label
function getTypeLabel(type) {
	const labels = {
		command: 'Command',
		template: 'Template',
		snippet: 'Snippet',
		builder: 'Builder',
		custom: 'Custom',
	};
	return labels[type] || 'Custom';
}

// Filter favorites
const filteredFavorites = computed(() => {
	let filtered = favorites.value;

	// Filter by search query
	if (searchQuery.value.trim()) {
		const query = searchQuery.value.toLowerCase();
		filtered = filtered.filter(fav => 
			fav.name.toLowerCase().includes(query) ||
			fav.description.toLowerCase().includes(query) ||
			fav.content.toLowerCase().includes(query)
		);
	}

	// Filter by type
	if (selectedType.value) {
		filtered = filtered.filter(fav => fav.type === selectedType.value);
	}

	// Filter by category
	if (selectedCategory.value) {
		filtered = filtered.filter(fav => fav.category === selectedCategory.value);
	}

	// Filter by tags
	if (selectedTags.value.size > 0) {
		filtered = filtered.filter(fav => 
			fav.tags.some(tag => selectedTags.value.has(tag))
		);
	}

	return filtered;
});

// Group favorites by type and category
const groupedFavorites = computed(() => {
	const groups = {};
	
	filteredFavorites.value.forEach(fav => {
		const typeKey = fav.type || 'custom';
		const categoryKey = fav.category || 'Uncategorized';
		
		if (!groups[typeKey]) {
			groups[typeKey] = {};
		}
		if (!groups[typeKey][categoryKey]) {
			groups[typeKey][categoryKey] = [];
		}
		groups[typeKey][categoryKey].push(fav);
	});

	return groups;
});

// Add new favorite
function addFavorite(data = null) {
	if (data) {
		favoriteForm.value = { ...data };
	} else {
		favoriteForm.value = {
			name: '',
			description: '',
			category: '',
			tags: [],
			content: props.currentCommand || '',
			type: 'custom',
			metadata: {},
		};
	}
	showAddModal.value = true;
}

// Save favorite
function saveFavorite() {
	if (!favoriteForm.value.name.trim()) {
		Swal.fire({
			toast: true,
			icon: 'warning',
			title: 'Name is required',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}

	if (!favoriteForm.value.content.trim()) {
		Swal.fire({
			toast: true,
			icon: 'warning',
			title: 'Content is required',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}

	const favorite = {
		id: Date.now(),
		name: favoriteForm.value.name.trim(),
		description: favoriteForm.value.description.trim(),
		category: favoriteForm.value.category.trim() || 'Uncategorized',
		tags: favoriteForm.value.tags.filter(t => t.trim()),
		content: favoriteForm.value.content.trim(),
		type: favoriteForm.value.type,
		metadata: favoriteForm.value.metadata || {},
		createdAt: new Date().toISOString(),
		updatedAt: new Date().toISOString(),
		usageCount: 0,
	};

	favorites.value.push(favorite);

	// Update categories
	if (favorite.category && !categories.value.includes(favorite.category)) {
		categories.value.push(favorite.category);
		categories.value.sort();
	}

	// Update tags
	favorite.tags.forEach(tag => {
		if (!tags.value.includes(tag)) {
			tags.value.push(tag);
		}
	});
	tags.value.sort();

	saveFavorites();
	showAddModal.value = false;
	
	Swal.fire({
		toast: true,
		icon: 'success',
		title: 'Favorite added',
		position: 'bottom-end',
		showConfirmButton: false,
		timer: 2000,
	});
}

// Edit favorite
function editFavorite(favorite) {
	favoriteForm.value = {
		name: favorite.name,
		description: favorite.description,
		category: favorite.category,
		tags: [...favorite.tags],
		content: favorite.content,
		type: favorite.type,
		metadata: favorite.metadata || {},
	};
	editingFavorite.value = favorite;
	showEditModal.value = true;
}

// Update favorite
function updateFavorite() {
	if (!favoriteForm.value.name.trim()) {
		Swal.fire({
			toast: true,
			icon: 'warning',
			title: 'Name is required',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}

	if (!favoriteForm.value.content.trim()) {
		Swal.fire({
			toast: true,
			icon: 'warning',
			title: 'Content is required',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
		return;
	}

	const index = favorites.value.findIndex(f => f.id === editingFavorite.value.id);
	if (index !== -1) {
		const oldCategory = favorites.value[index].category;
		const oldTags = [...favorites.value[index].tags];

		favorites.value[index] = {
			...favorites.value[index],
			name: favoriteForm.value.name.trim(),
			description: favoriteForm.value.description.trim(),
			category: favoriteForm.value.category.trim() || 'Uncategorized',
			tags: favoriteForm.value.tags.filter(t => t.trim()),
			content: favoriteForm.value.content.trim(),
			type: favoriteForm.value.type,
			metadata: favoriteForm.value.metadata || {},
			updatedAt: new Date().toISOString(),
		};

		// Update categories
		if (favorites.value[index].category && !categories.value.includes(favorites.value[index].category)) {
			categories.value.push(favorites.value[index].category);
			categories.value.sort();
		}
		// Remove old category if no favorites use it
		if (oldCategory && !favorites.value.some(f => f.category === oldCategory)) {
			categories.value = categories.value.filter(c => c !== oldCategory);
		}

		// Update tags
		favorites.value[index].tags.forEach(tag => {
			if (!tags.value.includes(tag)) {
				tags.value.push(tag);
			}
		});
		// Remove old tags if no favorites use them
		oldTags.forEach(tag => {
			if (!favorites.value.some(f => f.tags.includes(tag))) {
				tags.value = tags.value.filter(t => t !== tag);
			}
		});
		tags.value.sort();

		saveFavorites();
		showEditModal.value = false;
		editingFavorite.value = null;
		
		Swal.fire({
			toast: true,
			icon: 'success',
			title: 'Favorite updated',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
	}
}

// Delete favorite
function deleteFavorite(favorite) {
	Swal.fire({
		title: 'Delete Favorite?',
		text: `Are you sure you want to delete "${favorite.name}"?`,
		icon: 'warning',
		showCancelButton: true,
		confirmButtonColor: '#ef4444',
		cancelButtonColor: '#6b7280',
		confirmButtonText: 'Delete',
		cancelButtonText: 'Cancel',
	}).then((result) => {
		if (result.isConfirmed) {
			const index = favorites.value.findIndex(f => f.id === favorite.id);
			if (index !== -1) {
				const deletedCategory = favorites.value[index].category;
				const deletedTags = [...favorites.value[index].tags];
				
				favorites.value.splice(index, 1);

				// Remove category if no favorites use it
				if (deletedCategory && !favorites.value.some(f => f.category === deletedCategory)) {
					categories.value = categories.value.filter(c => c !== deletedCategory);
				}

				// Remove tags if no favorites use them
				deletedTags.forEach(tag => {
					if (!favorites.value.some(f => f.tags.includes(tag))) {
						tags.value = tags.value.filter(t => t !== tag);
					}
				});

				saveFavorites();
				
				Swal.fire({
					toast: true,
					icon: 'success',
					title: 'Favorite deleted',
					position: 'bottom-end',
					showConfirmButton: false,
					timer: 2000,
				});
			}
		}
	});
}

// Duplicate favorite
function duplicateFavorite(favorite) {
	const newFavorite = {
		...favorite,
		id: Date.now(),
		name: `${favorite.name} (Copy)`,
		createdAt: new Date().toISOString(),
		updatedAt: new Date().toISOString(),
		usageCount: 0,
	};
	favorites.value.push(newFavorite);
	saveFavorites();
	
	Swal.fire({
		toast: true,
		icon: 'success',
		title: 'Favorite duplicated',
		position: 'bottom-end',
		showConfirmButton: false,
		timer: 2000,
	});
}

// Build command from favorite (shared logic)
function buildCommandFromFavorite(favorite) {
	let command = favorite.content;
	if (favorite.type === 'command') {
		// Extract just the command name (first word)
		const commandName = favorite.content.split(/\s+/)[0];
		// Escape single quotes in command name
		const escapedCommand = commandName.replace(/'/g, "\\'");
		command = `$output = new \\Symfony\\Component\\Console\\Output\\BufferedOutput();\n\\Illuminate\\Support\\Facades\\Artisan::call('${escapedCommand}', [], $output);\n$output->fetch();`;
	}
	return command;
}

// Insert favorite into terminal
function insertFavorite(favorite) {
	favorite.usageCount = (favorite.usageCount || 0) + 1;
	saveFavorites();
	emit('insert-command', buildCommandFromFavorite(favorite));
}

// Execute favorite
function executeFavorite(favorite) {
	favorite.usageCount = (favorite.usageCount || 0) + 1;
	saveFavorites();
	emit('execute-command', buildCommandFromFavorite(favorite));
}

// Toggle category filter
function toggleCategory(category) {
	if (selectedCategory.value === category) {
		selectedCategory.value = null;
	} else {
		selectedCategory.value = category;
	}
}

// Toggle tag filter
function toggleTag(tag) {
	if (selectedTags.value.has(tag)) {
		selectedTags.value.delete(tag);
	} else {
		selectedTags.value.add(tag);
	}
}

// Clear all filters
function clearFilters() {
	searchQuery.value = '';
	selectedType.value = null;
	selectedCategory.value = null;
	selectedTags.value.clear();
}

// Add tag input handling
const newTag = ref('');
function addTag() {
	const tag = newTag.value.trim();
	if (tag && !favoriteForm.value.tags.includes(tag)) {
		favoriteForm.value.tags.push(tag);
		newTag.value = '';
	}
}

function removeTag(tag) {
	favoriteForm.value.tags = favoriteForm.value.tags.filter(t => t !== tag);
}

// Watch for visibility to load favorites
watch(() => props.visible, (visible) => {
	if (visible) {
		loadFavorites();
	}
});

onMounted(() => {
	if (props.visible) {
		loadFavorites();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-favorites-view">
		<!-- Header -->
		<div class="terminal-favorites-header">
			<div class="terminal-favorites-header-left">
				<h3>Favorites</h3>
			</div>
			<div class="terminal-favorites-search">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search favorites..."
					class="terminal-favorites-search-input"
				/>
			</div>
			<button @click="$emit('close')" class="terminal-favorites-close-btn">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<!-- Main Content -->
		<div class="terminal-favorites-content">
			<!-- Sidebar -->
			<div class="terminal-favorites-sidebar">
				<!-- Type Filters -->
				<div class="terminal-favorites-sidebar-section">
					<h4>Type</h4>
					<button
						@click="selectedType = null; clearFilters()"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === null }]"
					>
						All
					</button>
					<button
						@click="selectedType = 'command'"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === 'command' }]"
					>
						Commands
					</button>
					<button
						@click="selectedType = 'template'"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === 'template' }]"
					>
						Templates
					</button>
					<button
						@click="selectedType = 'snippet'"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === 'snippet' }]"
					>
						Snippets
					</button>
					<button
						@click="selectedType = 'builder'"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === 'builder' }]"
					>
						Builder
					</button>
					<button
						@click="selectedType = 'custom'"
						:class="['terminal-favorites-filter-btn', { 'active': selectedType === 'custom' }]"
					>
						Custom
					</button>
				</div>

				<!-- Categories -->
				<div class="terminal-favorites-sidebar-section">
					<h4>Categories</h4>
					<div v-if="categories.length === 0" class="terminal-favorites-empty">
						No categories
					</div>
					<button
						v-for="category in categories"
						:key="category"
						@click="toggleCategory(category)"
						:class="['terminal-favorites-category-btn', { 'active': selectedCategory === category }]"
					>
						{{ category }}
					</button>
				</div>

				<!-- Tags -->
				<div class="terminal-favorites-sidebar-section">
					<h4>Tags</h4>
					<div v-if="tags.length === 0" class="terminal-favorites-empty">
						No tags
					</div>
					<div class="terminal-favorites-tags">
						<button
							v-for="tag in tags"
							:key="tag"
							@click="toggleTag(tag)"
							:class="['terminal-favorites-tag-btn', { 'active': selectedTags.has(tag) }]"
						>
							{{ tag }}
						</button>
					</div>
				</div>

				<!-- Clear Filters -->
				<div v-if="selectedType || selectedCategory || selectedTags.size > 0 || searchQuery" class="terminal-favorites-sidebar-section">
					<button @click="clearFilters()" class="terminal-favorites-clear-filters">
						Clear Filters
					</button>
				</div>
			</div>

			<!-- Main Area -->
			<div class="terminal-favorites-main">
				<!-- Favorite Items as Chips -->
				<div v-if="filteredFavorites.length > 0" class="terminal-favorites-chips-container">
					<div class="terminal-favorites-chips-header">
						<h4>Quick Select</h4>
						<span class="terminal-favorites-chips-count">{{ filteredFavorites.length }} favorites</span>
					</div>
					<div class="terminal-favorites-chips-grid">
						<button
							v-for="favorite in filteredFavorites"
							:key="favorite.id"
							@click="executeFavorite(favorite)"
							@contextmenu.prevent="editFavorite(favorite)"
							class="terminal-favorites-item-chip"
							:style="{ '--chip-color': getTypeColor(favorite.type) }"
							:title="favorite.description || favorite.name + ' - ' + favorite.content"
						>
							<span class="terminal-favorites-item-chip-icon" :style="{ backgroundColor: getTypeColor(favorite.type) }"></span>
							<span class="terminal-favorites-item-chip-name">{{ favorite.name }}</span>
						</button>
					</div>
				</div>
				
				<div v-if="filteredFavorites.length === 0" class="terminal-favorites-empty-main">
					<div v-if="favorites.length === 0" class="terminal-favorites-empty-state">
						<p>No favorites yet.</p>
						<p class="terminal-favorites-empty-hint">Add favorites by clicking the star icon on commands, templates, snippets, or builder-generated commands. You can also use the star button in the terminal input area.</p>
					</div>
					<p v-else>No favorites match your filters.</p>
				</div>

				<div v-else class="terminal-favorites-groups">
					<div v-for="(categoryGroups, type) in groupedFavorites" :key="type" class="terminal-favorites-type-group">
						<h3 class="terminal-favorites-type-header">
							<span class="terminal-favorites-type-badge" :style="{ backgroundColor: getTypeColor(type) }">
								{{ getTypeLabel(type) }}
							</span>
							<span class="terminal-favorites-type-count">({{ Object.values(categoryGroups).flat().length }})</span>
						</h3>

						<div v-for="(favs, category) in categoryGroups" :key="category" class="terminal-favorites-category-group">
							<h4 class="terminal-favorites-category-header">{{ category }}</h4>
							<div class="terminal-favorites-list">
								<div v-for="favorite in favs" :key="favorite.id" class="terminal-favorites-item">
									<div class="terminal-favorites-item-header">
										<div class="terminal-favorites-item-info">
											<h5>{{ favorite.name }}</h5>
											<p v-if="favorite.description" class="terminal-favorites-item-description">
												{{ favorite.description }}
											</p>
										</div>
										<div class="terminal-favorites-item-actions">
											<button @click="insertFavorite(favorite)" class="terminal-favorites-action-btn" title="Insert">
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
												</svg>
											</button>
											<button @click="executeFavorite(favorite)" class="terminal-favorites-action-btn" title="Execute">
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
												</svg>
											</button>
											<button @click="editFavorite(favorite)" class="terminal-favorites-action-btn" title="Edit">
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
												</svg>
											</button>
											<button @click="duplicateFavorite(favorite)" class="terminal-favorites-action-btn" title="Duplicate">
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
												</svg>
											</button>
											<button @click="deleteFavorite(favorite)" class="terminal-favorites-action-btn terminal-favorites-action-btn-danger" title="Delete">
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
												</svg>
											</button>
										</div>
									</div>
									<div class="terminal-favorites-item-content">
										<code class="terminal-favorites-item-code">{{ favorite.content }}</code>
									</div>
									<div class="terminal-favorites-item-footer">
										<div v-if="favorite.category" class="terminal-favorites-item-badge">
											{{ favorite.category }}
										</div>
										<div v-if="favorite.tags.length > 0" class="terminal-favorites-item-tags">
											<span v-for="tag in favorite.tags" :key="tag" class="terminal-favorites-item-tag">
												{{ tag }}
											</span>
										</div>
										<div class="terminal-favorites-item-meta">
											<span>Used {{ favorite.usageCount || 0 }} times</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Add Modal -->
		<Teleport to="body">
			<div v-if="showAddModal" class="terminal-favorites-modal-overlay" @click.self="showAddModal = false">
				<div class="terminal-favorites-modal">
					<h3>Add Favorite</h3>
					<div class="terminal-favorites-form">
						<div class="terminal-favorites-form-field">
							<label>Name *</label>
							<input v-model="favoriteForm.name" type="text" placeholder="Enter favorite name" />
						</div>
						<div class="terminal-favorites-form-field">
							<label>Description</label>
							<input v-model="favoriteForm.description" type="text" placeholder="Enter description" />
						</div>
						<div class="terminal-favorites-form-field">
							<label>Type</label>
							<select v-model="favoriteForm.type">
								<option value="custom">Custom</option>
								<option value="command">Command</option>
								<option value="template">Template</option>
								<option value="snippet">Snippet</option>
								<option value="builder">Builder</option>
							</select>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Category</label>
							<input v-model="favoriteForm.category" type="text" placeholder="Enter category" list="categories-list" />
							<datalist id="categories-list">
								<option v-for="cat in categories" :key="cat" :value="cat" />
							</datalist>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Tags</label>
							<div class="terminal-favorites-tags-input">
								<span v-for="tag in favoriteForm.tags" :key="tag" class="terminal-favorites-tag-chip">
									{{ tag }}
									<button @click="removeTag(tag)" class="terminal-favorites-tag-remove">×</button>
								</span>
								<input
									v-model="newTag"
									@keyup.enter="addTag"
									type="text"
									placeholder="Add tag and press Enter"
									class="terminal-favorites-tag-input"
								/>
							</div>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Content *</label>
							<textarea v-model="favoriteForm.content" rows="4" placeholder="Enter command or code"></textarea>
						</div>
					</div>
					<div class="terminal-favorites-modal-actions">
						<button @click="showAddModal = false" class="terminal-favorites-modal-btn terminal-favorites-modal-btn-cancel">
							Cancel
						</button>
						<button @click="saveFavorite()" class="terminal-favorites-modal-btn terminal-favorites-modal-btn-primary">
							Save
						</button>
					</div>
				</div>
			</div>
		</Teleport>

		<!-- Edit Modal -->
		<Teleport to="body">
			<div v-if="showEditModal" class="terminal-favorites-modal-overlay" @click.self="showEditModal = false">
				<div class="terminal-favorites-modal">
					<h3>Edit Favorite</h3>
					<div class="terminal-favorites-form">
						<div class="terminal-favorites-form-field">
							<label>Name *</label>
							<input v-model="favoriteForm.name" type="text" placeholder="Enter favorite name" />
						</div>
						<div class="terminal-favorites-form-field">
							<label>Description</label>
							<input v-model="favoriteForm.description" type="text" placeholder="Enter description" />
						</div>
						<div class="terminal-favorites-form-field">
							<label>Type</label>
							<select v-model="favoriteForm.type">
								<option value="custom">Custom</option>
								<option value="command">Command</option>
								<option value="template">Template</option>
								<option value="snippet">Snippet</option>
								<option value="builder">Builder</option>
							</select>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Category</label>
							<input v-model="favoriteForm.category" type="text" placeholder="Enter category" list="categories-list-edit" />
							<datalist id="categories-list-edit">
								<option v-for="cat in categories" :key="cat" :value="cat" />
							</datalist>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Tags</label>
							<div class="terminal-favorites-tags-input">
								<span v-for="tag in favoriteForm.tags" :key="tag" class="terminal-favorites-tag-chip">
									{{ tag }}
									<button @click="removeTag(tag)" class="terminal-favorites-tag-remove">×</button>
								</span>
								<input
									v-model="newTag"
									@keyup.enter="addTag"
									type="text"
									placeholder="Add tag and press Enter"
									class="terminal-favorites-tag-input"
								/>
							</div>
						</div>
						<div class="terminal-favorites-form-field">
							<label>Content *</label>
							<textarea v-model="favoriteForm.content" rows="4" placeholder="Enter command or code"></textarea>
						</div>
					</div>
					<div class="terminal-favorites-modal-actions">
						<button @click="showEditModal = false; editingFavorite = null" class="terminal-favorites-modal-btn terminal-favorites-modal-btn-cancel">
							Cancel
						</button>
						<button @click="updateFavorite()" class="terminal-favorites-modal-btn terminal-favorites-modal-btn-primary">
							Update
						</button>
					</div>
				</div>
			</div>
		</Teleport>
	</div>
</template>

<style scoped>
.terminal-favorites-view {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--terminal-bg);
	display: flex;
	flex-direction: column;
	z-index: 10000;
}

.terminal-favorites-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.terminal-favorites-header-left {
	display: flex;
	align-items: center;
	gap: 16px;
}

.terminal-favorites-header-left h3 {
	margin: 0;
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
}

.terminal-favorites-add-btn {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	background: var(--terminal-primary);
	color: #ffffff;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	transition: background-color 0.2s;
}

.terminal-favorites-add-btn:hover {
	background: var(--terminal-primary-hover);
}

.terminal-favorites-add-btn svg {
	width: 16px;
	height: 16px;
}

.terminal-favorites-search {
	flex: 1;
	max-width: 400px;
	margin: 0 16px;
}

.terminal-favorites-search-input {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
}

.terminal-favorites-search-input:focus {
	outline: none;
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 2px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.terminal-favorites-search-input::placeholder {
	color: var(--terminal-text-muted);
}

.terminal-favorites-close-btn {
	padding: 6px;
	background: transparent;
	border: none;
	color: var(--terminal-text-muted);
	cursor: pointer;
	border-radius: 4px;
	transition: background-color 0.2s;
}

.terminal-favorites-close-btn:hover {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-favorites-close-btn svg {
	width: 20px;
	height: 20px;
}

.terminal-favorites-content {
	display: flex;
	flex: 1;
	overflow: hidden;
}

.terminal-favorites-sidebar {
	width: 250px;
	border-right: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
	overflow-y: auto;
	padding: 16px;
}

.terminal-favorites-sidebar-section {
	margin-bottom: 24px;
}

.terminal-favorites-sidebar-section h4 {
	margin: 0 0 12px 0;
	color: var(--terminal-text);
	font-size: 13px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-favorites-filter-btn,
.terminal-favorites-category-btn {
	display: block;
	width: 100%;
	padding: 8px 12px;
	margin-bottom: 4px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	text-align: left;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-filter-btn:hover,
.terminal-favorites-category-btn:hover {
	background: var(--terminal-border);
}

.terminal-favorites-filter-btn.active,
.terminal-favorites-category-btn.active {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: #ffffff;
}

.terminal-favorites-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.terminal-favorites-tag-btn {
	padding: 4px 8px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 12px;
	color: var(--terminal-text-muted);
	font-size: 11px;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-tag-btn:hover {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-favorites-tag-btn.active {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: #ffffff;
}

.terminal-favorites-clear-filters {
	width: 100%;
	padding: 8px 12px;
	background: var(--terminal-border);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	cursor: pointer;
	transition: background-color 0.2s;
}

.terminal-favorites-clear-filters:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-favorites-empty {
	color: var(--terminal-text-muted);
	font-size: 12px;
	font-style: italic;
}

.terminal-favorites-main {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

/* Favorite Item Chips */
.terminal-favorites-chips-container {
	margin-bottom: 24px;
}

.terminal-favorites-chips-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-favorites-chips-header h4 {
	margin: 0;
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
}

.terminal-favorites-chips-count {
	color: var(--terminal-text-muted);
	font-size: 13px;
}

.terminal-favorites-chips-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.terminal-favorites-item-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 10px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-left: 2px solid var(--chip-color, #007acc);
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.15s;
	text-align: left;
	position: relative;
	white-space: nowrap;
}

.terminal-favorites-item-chip:hover {
	background: var(--terminal-border);
	border-color: var(--chip-color, #007acc);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
}

.terminal-favorites-item-chip:active {
	transform: scale(0.98);
}

.terminal-favorites-item-chip-icon {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	flex-shrink: 0;
}

.terminal-favorites-item-chip-name {
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 200px;
}

.terminal-favorites-empty-main {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--terminal-text-muted);
	font-size: 14px;
}

.terminal-favorites-empty-state {
	text-align: center;
	padding: 40px 20px;
}

.terminal-favorites-empty-state p {
	margin: 0 0 12px 0;
}

.terminal-favorites-empty-hint {
	font-size: 12px;
	color: var(--terminal-text-muted);
	line-height: 1.6;
	max-width: 500px;
	margin: 0 auto;
}

.terminal-favorites-type-group {
	margin-bottom: 32px;
}

.terminal-favorites-type-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 0 16px 0;
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
}

.terminal-favorites-type-badge {
	padding: 4px 10px;
	border-radius: 12px;
	color: #ffffff;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-favorites-type-count {
	color: var(--terminal-text-muted);
	font-size: 14px;
	font-weight: normal;
}

.terminal-favorites-category-group {
	margin-bottom: 24px;
}

.terminal-favorites-category-header {
	margin: 0 0 12px 0;
	color: var(--terminal-text-secondary);
	font-size: 14px;
	font-weight: 500;
	padding-left: 8px;
	border-left: 3px solid var(--terminal-primary);
}

.terminal-favorites-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-favorites-item {
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 6px;
	padding: 12px;
	transition: all 0.2s;
}

.terminal-favorites-item:hover {
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 1px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.terminal-favorites-item-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 8px;
}

.terminal-favorites-item-info {
	flex: 1;
}

.terminal-favorites-item-info h5 {
	margin: 0 0 4px 0;
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: 600;
}

.terminal-favorites-item-description {
	margin: 0;
	color: var(--terminal-text-muted);
	font-size: 12px;
}

.terminal-favorites-item-actions {
	display: flex;
	gap: 4px;
}

.terminal-favorites-action-btn {
	padding: 4px;
	background: transparent;
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text-muted);
	cursor: pointer;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-favorites-action-btn:hover {
	background: var(--terminal-border);
	border-color: var(--terminal-primary);
	color: var(--terminal-primary);
}

.terminal-favorites-action-btn-danger:hover {
	border-color: var(--terminal-error);
	color: var(--terminal-error);
}

.terminal-favorites-action-btn svg {
	width: 16px;
	height: 16px;
}

.terminal-favorites-item-content {
	margin: 8px 0;
	padding: 8px;
	background: var(--terminal-bg);
	border-radius: 4px;
}

.terminal-favorites-item-code {
	color: var(--terminal-accent);
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
	font-size: 12px;
	white-space: pre-wrap;
	word-break: break-all;
}

.terminal-favorites-item-footer {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-favorites-item-badge {
	padding: 2px 8px;
	background: var(--terminal-border);
	border-radius: 10px;
	color: var(--terminal-text-muted);
	font-size: 11px;
}

.terminal-favorites-item-tags {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.terminal-favorites-item-tag {
	padding: 2px 6px;
	background: var(--terminal-primary);
	border-radius: 8px;
	color: #ffffff;
	font-size: 10px;
}

.terminal-favorites-item-meta {
	margin-left: auto;
	color: var(--terminal-text-muted);
	font-size: 11px;
}

.terminal-favorites-modal-overlay {
	position: fixed;
	top: 0 !important;
	left: 0 !important;
	right: 0 !important;
	bottom: 0 !important;
	background: var(--terminal-overlay, rgba(0, 0, 0, 0.7));
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10005 !important;
}

.terminal-favorites-modal {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	width: 90%;
	max-width: 600px;
	max-height: 90vh;
	overflow-y: auto;
	padding: 24px;
}

.terminal-favorites-modal h3 {
	margin: 0 0 20px 0;
	color: var(--terminal-text);
	font-size: 18px;
	font-weight: 600;
}

.terminal-favorites-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.terminal-favorites-form-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.terminal-favorites-form-field label {
	color: var(--terminal-text);
	font-size: 13px;
	font-weight: 500;
}

.terminal-favorites-form-field input,
.terminal-favorites-form-field textarea,
.terminal-favorites-form-field select {
	padding: 8px 12px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 13px;
	font-family: inherit;
}

.terminal-favorites-form-field input:focus,
.terminal-favorites-form-field textarea:focus,
.terminal-favorites-form-field select:focus {
	outline: none;
	border-color: var(--terminal-primary);
	box-shadow: 0 0 0 2px var(--terminal-primary-shadow, rgba(14, 99, 156, 0.2));
}

.terminal-favorites-form-field textarea {
	resize: vertical;
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

.terminal-favorites-tags-input {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 8px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	min-height: 40px;
}

.terminal-favorites-tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: var(--terminal-primary);
	border-radius: 12px;
	color: var(--terminal-text, #ffffff);
	font-size: 12px;
}

.terminal-favorites-tag-remove {
	background: transparent;
	border: none;
	color: #ffffff;
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	padding: 0;
	width: 16px;
	height: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-favorites-tag-remove:hover {
	color: #ffcccc;
}

.terminal-favorites-tag-input {
	flex: 1;
	min-width: 120px;
	border: none;
	background: transparent;
	padding: 0;
}

.terminal-favorites-tag-input:focus {
	outline: none;
}

.terminal-favorites-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}

.terminal-favorites-modal-btn {
	padding: 8px 16px;
	border: none;
	border-radius: 4px;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-modal-btn-cancel {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-favorites-modal-btn-cancel:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-favorites-modal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: var(--terminal-text, #ffffff);
}

.terminal-favorites-modal-btn-primary:hover {
	background: var(--terminal-primary-hover);
}

/* Custom Scrollbar Styling */
.terminal-favorites-sidebar::-webkit-scrollbar,
.terminal-favorites-main::-webkit-scrollbar,
.terminal-favorites-modal::-webkit-scrollbar {
	width: 10px;
}

.terminal-favorites-sidebar::-webkit-scrollbar-track,
.terminal-favorites-main::-webkit-scrollbar-track,
.terminal-favorites-modal::-webkit-scrollbar-track {
	background: var(--terminal-bg);
}

.terminal-favorites-sidebar::-webkit-scrollbar-thumb,
.terminal-favorites-main::-webkit-scrollbar-thumb,
.terminal-favorites-modal::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
}

.terminal-favorites-sidebar::-webkit-scrollbar-thumb:hover,
.terminal-favorites-main::-webkit-scrollbar-thumb:hover,
.terminal-favorites-modal::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

/* Firefox scrollbar styling */
.terminal-favorites-sidebar,
.terminal-favorites-main,
.terminal-favorites-modal {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg, #1e1e1e);
}
</style>

