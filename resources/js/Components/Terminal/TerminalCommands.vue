<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close', 'add-to-favorites']);

const loading = ref(false);
const commands = ref([]);
const searchQuery = ref('');
const expandedCategories = ref(new Set());
const selectedCommand = ref(null);
const executing = ref(false);
const executionOutput = ref(null);
const executionError = ref(null);
const executionTime = ref(null);
const exitCode = ref(null);
const showConfirmation = ref(false);

// Form state for arguments and options
const argumentValues = ref({});
const optionValues = ref({});

// Load commands
async function loadCommands() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const response = await axios.get(api.url('commands'));
		if (response.data && response.data.success && response.data.result) {
			commands.value = response.data.result.commands || [];
			// Expand all categories by default
			commands.value.forEach(cmd => {
				if (!expandedCategories.value.has(cmd.category)) {
					expandedCategories.value.add(cmd.category);
				}
			});
		} else {
			console.error('Failed to load commands:', response.data);
		}
	} catch (error) {
		console.error('Error loading commands:', error);
		if (error.response) {
			console.error('Response data:', error.response.data);
		}
	} finally {
		loading.value = false;
	}
}

// Toggle category expansion
function toggleCategory(category) {
	if (expandedCategories.value.has(category)) {
		expandedCategories.value.delete(category);
	} else {
		expandedCategories.value.add(category);
	}
}

// Select command
function selectCommand(command) {
	selectedCommand.value = command;
	executionOutput.value = null;
	executionError.value = null;
	executionTime.value = null;
	exitCode.value = null;
	showConfirmation.value = false; // Reset confirmation when selecting a new command
	
	// Initialize form values
	argumentValues.value = {};
	optionValues.value = {};
	
	// Set default values for arguments
	command.arguments.forEach(arg => {
		if (arg.default !== null && arg.default !== undefined) {
			argumentValues.value[arg.name] = arg.default;
		} else if (!arg.required) {
			argumentValues.value[arg.name] = '';
		}
	});
	
	// Set default values for options
	command.options.forEach(option => {
		// Show input for any option that accepts a value OR is optional (not a boolean flag)
		// This covers cases where acceptValue might not be correctly detected
		if (option.acceptValue || option.isValueOptional || option.isValueRequired) {
			// Set default value if available, otherwise empty string
			if (option.default !== null && option.default !== undefined && option.default !== '') {
				optionValues.value[option.name] = String(option.default);
			} else {
				optionValues.value[option.name] = '';
			}
		} else {
			// Boolean flag, default to false
			optionValues.value[option.name] = false;
		}
	});
}

// Execute command
async function executeCommand() {
	if (!selectedCommand.value || executing.value) return;
	
	// First click: show confirmation
	if (!showConfirmation.value) {
		showConfirmation.value = true;
		return;
	}
	
	// Second click: actually execute
	showConfirmation.value = false;
	executing.value = true;
	executionOutput.value = null;
	executionError.value = null;
	executionTime.value = null;
	exitCode.value = null;
	
	try {
		// Build arguments object (only include non-empty required or set optional)
		const args = {};
		selectedCommand.value.arguments.forEach(arg => {
			const value = argumentValues.value[arg.name];
			if (arg.required || (value !== null && value !== undefined && value !== '')) {
				args[arg.name] = value;
			}
		});
		
		// Build options object (only include set options)
		const opts = {};
		selectedCommand.value.options.forEach(option => {
			const value = optionValues.value[option.name];
			if (option.acceptValue) {
				if (value !== null && value !== undefined && value !== '') {
					opts['--' + option.name] = value;
				}
			} else {
				// Boolean flag
				if (value === true) {
					opts['--' + option.name] = true;
				}
			}
		});
		
		const response = await axios.post(api.url('commands/execute'), {
			command: selectedCommand.value.name,
			arguments: args,
			options: opts,
		});
		
		if (response.data && response.data.success && response.data.result) {
			executionOutput.value = response.data.result.output || '';
			executionTime.value = response.data.result.executionTime;
			exitCode.value = response.data.result.exitCode;
			executionError.value = response.data.result.success === false ? 'Command failed' : null;
		} else {
			// Handle error response from backend
			executionError.value = response.data?.error || response.data?.message || 'Failed to execute command';
			executionOutput.value = null;
		}
	} catch (error) {
		// Extract error message from response
		let errorMessage = 'Failed to execute command';
		
		if (error.response) {
			// Server responded with error status
			const errorData = error.response.data;
			if (errorData?.error) {
				errorMessage = errorData.error;
			} else if (errorData?.message) {
				errorMessage = errorData.message;
			} else if (typeof errorData === 'string') {
				errorMessage = errorData;
			} else {
				errorMessage = `Server error (${error.response.status}): ${error.response.statusText}`;
			}
		} else if (error.request) {
			// Request was made but no response received
			errorMessage = 'No response from server. Please check your connection.';
		} else {
			// Error setting up the request
			errorMessage = error.message || 'Failed to execute command';
		}
		
		executionError.value = errorMessage;
		executionOutput.value = null;
		console.error('Command execution error:', error);
	} finally {
		executing.value = false;
	}
}

// Filter commands
const filteredCommands = computed(() => {
	if (!searchQuery.value.trim()) {
		return commands.value;
	}
	
	const query = searchQuery.value.toLowerCase();
	return commands.value.filter(cmd => {
		return cmd.name.toLowerCase().includes(query) ||
			cmd.description.toLowerCase().includes(query) ||
			cmd.category.toLowerCase().includes(query) ||
			cmd.class.toLowerCase().includes(query);
	});
});

// Group commands by category
const groupedCommands = computed(() => {
	const groups = {};
	filteredCommands.value.forEach(cmd => {
		const category = cmd.category || 'Other';
		if (!groups[category]) {
			groups[category] = [];
		}
		groups[category].push(cmd);
	});
	return groups;
});

// Get category badge color
function getCategoryColor(category) {
	const colors = {
		'Custom': '#10b981',
		'Audit': '#8b5cf6',
		'Migrate': '#3b82f6',
		'Laravel': '#6b7280',
		'Generator': '#f59e0b',
		'Database': '#ef4444',
		'Cache': '#06b6d4',
		'Queue': '#ec4899',
		'Route': '#6366f1',
		'Config': '#14b8a6',
		'View': '#a855f7',
		'Schedule': '#f97316',
		'Other': '#64748b',
	};
	return colors[category] || colors['Other'];
}

// Determine input type for option based on name and description
function getOptionInputType(option) {
	const name = option.name.toLowerCase();
	const desc = (option.description || '').toLowerCase();
	
	// Check for numeric indicators
	if (name.includes('number') || name.includes('count') || name.includes('seconds') || name.includes('timeout') || 
		name.includes('port') || name.includes('limit') || name.includes('status') || name.includes('code') ||
		name.includes('retry') || name.includes('refresh') ||
		desc.includes('number') || desc.includes('seconds') || desc.includes('status code') || desc.includes('count')) {
		return 'number';
	}
	
	// Check for URL/email indicators
	if (name.includes('url') || name.includes('email') || desc.includes('url') || desc.includes('email')) {
		return 'url';
	}
	
	// Default to text
	return 'text';
}

// Get placeholder text for option input
function getOptionPlaceholder(option) {
	if (option.default !== null && option.default !== undefined && option.default !== '') {
		return `Enter value (default: ${option.default})`;
	}
	return option.description || `Enter value for --${option.name}`;
}

// Determine if option input should be shown
function shouldShowOptionInput(option) {
	// Boolean flags have acceptValue = false, isValueOptional = false, isValueRequired = false
	// Value-accepting options have at least one of these as true
	// Only show input if the option actually accepts a value (not a boolean flag)
	const shouldShow = Boolean(
		option.acceptValue || 
		option.isValueOptional || 
		option.isValueRequired
	);
	
	return shouldShow;
}

// Add command to favorites
function addCommandToFavorites() {
	if (!selectedCommand.value) return;
	
	// Build command string with current argument/option values
	let commandStr = selectedCommand.value.name;
	
	// Add arguments
	selectedCommand.value.arguments.forEach(arg => {
		const value = argumentValues.value[arg.name];
		if (value !== undefined && value !== null && value !== '') {
			commandStr += ` ${value}`;
		}
	});
	
	// Add options
	selectedCommand.value.options.forEach(option => {
		if (option.acceptValue || option.isValueOptional || option.isValueRequired) {
			const value = optionValues.value[option.name];
			if (value !== undefined && value !== null && value !== '') {
				commandStr += ` --${option.name}=${value}`;
			}
		} else {
			// Boolean flag
			if (optionValues.value[option.name]) {
				commandStr += ` --${option.name}`;
			}
		}
	});
	
	emit('add-to-favorites', {
		name: selectedCommand.value.name,
		description: selectedCommand.value.description,
		category: selectedCommand.value.category || '',
		tags: [],
		content: commandStr,
		type: 'command',
		metadata: {
			commandName: selectedCommand.value.name,
			category: selectedCommand.value.category,
			arguments: selectedCommand.value.arguments.map(arg => ({
				...arg,
				value: argumentValues.value[arg.name],
			})),
			options: selectedCommand.value.options.map(opt => ({
				...opt,
				value: optionValues.value[opt.name],
			})),
		},
	});
}

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue && commands.value.length === 0) {
		loadCommands();
	}
});

onMounted(() => {
	if (props.visible) {
		loadCommands();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-commands">
		<div class="terminal-commands-header">
			<div class="terminal-commands-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
				</svg>
				<span>Artisan Commands</span>
			</div>
			<div class="terminal-commands-controls">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search commands..."
					class="terminal-input terminal-commands-search"
				/>
				<button
					@click="loadCommands"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload commands"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Commands"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<div class="terminal-commands-content">
			<div v-if="loading" class="terminal-commands-loading">
				<span class="spinner"></span>
				Loading commands...
			</div>

			<div v-else-if="commands.length === 0" class="terminal-commands-empty">
				<p>No commands found.</p>
			</div>

			<div v-else class="terminal-commands-main">
				<!-- Commands List -->
				<div class="terminal-commands-list">
					<div class="terminal-commands-list-header">
						<h3>Commands ({{ filteredCommands.length }})</h3>
					</div>
					<div class="terminal-commands-list-scroll">
						<div v-for="(categoryCommands, category) in groupedCommands" :key="category" class="terminal-commands-group">
							<div class="terminal-commands-group-header" @click="toggleCategory(category)">
								<svg
									xmlns="http://www.w3.org/2000/svg"
									class="terminal-commands-group-toggle"
									:class="{ 'expanded': expandedCategories.has(category) }"
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
								>
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
								</svg>
								<span class="terminal-commands-category-badge" :style="{ backgroundColor: getCategoryColor(category) }">
									{{ category }}
								</span>
								<span class="terminal-commands-group-count">({{ categoryCommands.length }})</span>
							</div>
							<div v-if="expandedCategories.has(category)" class="terminal-commands-group-items">
								<div
									v-for="cmd in categoryCommands"
									:key="cmd.name"
									class="terminal-commands-item"
									:class="{ 'active': selectedCommand && selectedCommand.name === cmd.name }"
									@click="selectCommand(cmd)"
								>
									<div class="terminal-commands-item-header">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
										</svg>
										<span class="terminal-commands-item-name" :title="cmd.name">{{ cmd.name }}</span>
									</div>
									<div class="terminal-commands-item-description">{{ cmd.description }}</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Command Details -->
				<div class="terminal-commands-details">
					<div v-if="!selectedCommand" class="terminal-commands-details-empty">
						<p>Select a command to view details and execute it.</p>
					</div>

					<div v-else class="terminal-commands-details-content">
						<div class="terminal-commands-details-header">
							<h3>{{ selectedCommand.name }}</h3>
							<div class="terminal-commands-details-header-actions">
								<!-- Confirmation buttons in header -->
								<template v-if="showConfirmation">
									<button
										@click="executeCommand"
										class="terminal-btn terminal-btn-primary terminal-btn-xs terminal-commands-execute-btn-header"
										:disabled="executing"
										title="Confirm and Execute"
									>
										<svg v-if="executing" xmlns="http://www.w3.org/2000/svg" class="terminal-commands-spinner" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
										</svg>
										<svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
										</svg>
										<span>{{ executing ? 'Executing...' : 'Yes, Execute' }}</span>
									</button>
									<button
										@click="showConfirmation = false"
										class="terminal-btn terminal-btn-secondary terminal-btn-xs"
										:disabled="executing"
										title="Cancel"
									>
										Cancel
									</button>
								</template>
								<!-- Normal execute button -->
								<button
									v-else
									@click="executeCommand"
									class="terminal-btn terminal-btn-primary terminal-btn-xs terminal-commands-execute-btn-header"
									:disabled="executing"
									title="Execute Command"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
									</svg>
									<span v-if="!executing">Execute</span>
									<span v-else>Executing...</span>
								</button>
								<button
									@click="addCommandToFavorites"
									class="terminal-btn terminal-btn-secondary terminal-btn-xs"
									title="Add to Favorites"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
									</svg>
								</button>
								<button
									@click="selectedCommand = null"
									class="terminal-btn terminal-btn-close terminal-btn-xs"
									title="Clear selection"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
										<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
									</svg>
								</button>
							</div>
						</div>

						<div class="terminal-commands-details-body">
							<div class="terminal-commands-details-section">
								<h4>Description</h4>
								<p>{{ selectedCommand.description }}</p>
							</div>

							<div class="terminal-commands-details-section">
								<h4>Category</h4>
								<span class="terminal-commands-category-badge" :style="{ backgroundColor: getCategoryColor(selectedCommand.category) }">
									{{ selectedCommand.category }}
								</span>
							</div>

							<div class="terminal-commands-details-section">
								<h4>Class</h4>
								<code>{{ selectedCommand.class }}</code>
							</div>

							<!-- Arguments -->
							<div v-if="selectedCommand.arguments.length > 0" class="terminal-commands-details-section">
								<h4>Arguments</h4>
								<div class="terminal-commands-form-group">
									<div
										v-for="arg in selectedCommand.arguments"
										:key="arg.name"
										class="terminal-commands-form-field"
									>
										<label>
											{{ arg.name }}
											<span v-if="arg.required" class="terminal-commands-required">*</span>
											<span v-if="!arg.required" class="terminal-commands-optional">(optional)</span>
										</label>
										<input
											v-model="argumentValues[arg.name]"
											type="text"
											class="terminal-input terminal-commands-input"
											:placeholder="arg.description || (arg.required ? 'Required' : 'Optional')"
											:required="arg.required"
										/>
										<div v-if="arg.description" class="terminal-commands-field-help">{{ arg.description }}</div>
										<div v-if="arg.default !== null && arg.default !== undefined" class="terminal-commands-field-default">
											Default: <code>{{ arg.default }}</code>
										</div>
									</div>
								</div>
							</div>

							<!-- Options -->
							<div v-if="selectedCommand.options.length > 0" class="terminal-commands-details-section">
								<h4>Options</h4>
								<div class="terminal-commands-form-group">
									<div
										v-for="option in selectedCommand.options"
										:key="option.name"
										class="terminal-commands-form-field"
									>
										<label class="terminal-commands-option-label">
											<!-- Checkbox for boolean options -->
											<input
												v-if="!shouldShowOptionInput(option)"
												v-model="optionValues[option.name]"
												type="checkbox"
												class="terminal-commands-checkbox"
											/>
											<!-- Option name and flag -->
											<span class="terminal-commands-option-name">
												<code class="terminal-commands-option-flag">--{{ option.name }}</code>
												<span v-if="option.shortcut" class="terminal-commands-shortcut">(-{{ option.shortcut }})</span>
												<span v-if="option.isValueRequired" class="terminal-commands-required">*</span>
											</span>
											<!-- Inline input for value-accepting options -->
											<input
												v-if="shouldShowOptionInput(option)"
												v-model="optionValues[option.name]"
												:type="getOptionInputType(option)"
												class="terminal-commands-input-inline"
												:placeholder="getOptionPlaceholder(option)"
												:required="option.isValueRequired"
												autocomplete="off"
												:key="'option-input-' + option.name"
											/>
										</label>
										<div v-if="option.description" class="terminal-commands-field-help">{{ option.description }}</div>
										<div v-if="option.default !== null && option.default !== undefined && (option.acceptValue || option.isValueOptional) && !optionValues[option.name]" class="terminal-commands-field-default">
											Default: <code>{{ option.default }}</code>
										</div>
									</div>
								</div>
							</div>

							<!-- Execute Button -->
							<div class="terminal-commands-execute-section">
								<div v-if="showConfirmation" class="terminal-commands-confirmation">
									<p class="terminal-commands-confirmation-text">Are you super duper sure you want to run this command?</p>
									<div class="terminal-commands-confirmation-buttons">
										<button
											@click="executeCommand"
											class="terminal-btn terminal-btn-primary terminal-commands-execute-btn"
											:disabled="executing"
										>
											<svg v-if="executing" xmlns="http://www.w3.org/2000/svg" class="terminal-commands-spinner" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
											</svg>
											<span v-else>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
												</svg>
											</span>
											{{ executing ? 'Executing...' : 'Yes, Execute' }}
										</button>
										<button
											@click="showConfirmation = false"
											class="terminal-btn terminal-btn-secondary"
											:disabled="executing"
										>
											Cancel
										</button>
									</div>
								</div>
								<button
									v-else
									@click="executeCommand"
									class="terminal-btn terminal-btn-primary terminal-commands-execute-btn"
									:disabled="executing"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
									</svg>
									Execute Command
								</button>
							</div>

							<!-- Output -->
							<div v-if="executionOutput !== null || executionError" class="terminal-commands-output-section">
								<div class="terminal-commands-output-header">
									<h4 v-if="executionError">Error</h4>
									<h4 v-else>Output</h4>
									<div class="terminal-commands-output-meta">
										<span v-if="executionTime !== null" class="terminal-commands-meta-item">
											Time: {{ executionTime }}s
										</span>
										<span v-if="exitCode !== null" class="terminal-commands-meta-item" :class="{ 'success': exitCode === 0, 'error': exitCode !== 0 }">
											Exit: {{ exitCode }}
										</span>
									</div>
								</div>
								<div class="terminal-commands-output-content" :class="{ 'error': executionError }">
									<div v-if="executionError" class="terminal-commands-error">
										<strong>Error:</strong> {{ executionError }}
									</div>
									<pre v-if="executionOutput && !executionError">{{ executionOutput }}</pre>
									<pre v-if="executionOutput && executionError" class="terminal-commands-error-output">{{ executionOutput }}</pre>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-commands {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg);
	color: var(--terminal-text);
	z-index: 10001;
}

.terminal-commands-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
	flex-shrink: 0;
}

.terminal-commands-title {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: var(--terminal-font-size-md, 14px);
}

.terminal-commands-title svg {
	width: 20px !important;
	height: 20px !important;
	max-width: 20px !important;
	max-height: 20px !important;
	flex-shrink: 0;
}

.terminal-commands-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-commands-controls .terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-commands-search {
	width: 250px;
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
	padding: 6px 12px !important;
	font-size: var(--terminal-font-size-md, 13px);
}

.terminal-commands-search:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-commands-search::placeholder {
	color: var(--terminal-text-muted);
}

.terminal-commands-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
}

.terminal-commands-loading,
.terminal-commands-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	gap: 12px;
	color: var(--terminal-text-muted);
}

.terminal-commands-empty p {
	margin: 0;
}

.terminal-commands-main {
	flex: 1;
	display: flex;
	overflow: hidden;
	min-height: 0;
}

.terminal-commands-list {
	width: 450px;
	min-width: 450px;
	height: 100%;
	overflow: hidden;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg-secondary);
	border-right: 1px solid var(--terminal-border);
}

.terminal-commands-list-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	flex-shrink: 0;
}

.terminal-commands-list-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-md, 13px);
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-commands-list-scroll {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px;
	min-height: 0;
}

.terminal-commands-group {
	margin-bottom: 8px;
}

.terminal-commands-group-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	user-select: none;
	border-radius: 4px;
	transition: background 0.2s;
}

.terminal-commands-group-header:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-commands-group-toggle {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	transition: transform 0.2s;
	flex-shrink: 0;
}

.terminal-commands-group-toggle.expanded {
	transform: rotate(90deg);
}

.terminal-commands-category-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
	color: var(--terminal-text);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-commands-group-count {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-muted);
	margin-left: auto;
}

.terminal-commands-group-items {
	margin-left: 24px;
}

.terminal-commands-item {
	padding: 10px 12px;
	cursor: pointer;
	border-radius: 4px;
	transition: background 0.2s;
	margin-bottom: 4px;
}

.terminal-commands-item:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-commands-item.active {
	background: var(--terminal-primary);
	color: var(--terminal-text);
}

.terminal-commands-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 4px;
}

.terminal-commands-item-header svg {
	flex-shrink: 0;
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-commands-item-name {
	font-weight: 600;
	font-size: 13px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-commands-item-description {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-muted);
	margin-left: 24px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-commands-item.active .terminal-commands-item-description {
	color: color-mix(in srgb, var(--terminal-text) 80%, transparent);
}

.terminal-commands-details {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
	background: var(--terminal-bg);
}

.terminal-commands-details-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	color: var(--terminal-text-muted);
}

.terminal-commands-details-empty p {
	margin: 0;
}

.terminal-commands-details-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	min-height: 0;
}

.terminal-commands-details-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
	flex-shrink: 0;
}

.terminal-commands-details-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-lg, 16px);
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-commands-details-body {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 16px;
	padding-bottom: 72px;
	min-height: 0;
}

.terminal-commands-details-section {
	margin-bottom: 24px;
}

.terminal-commands-details-section h4 {
	margin: 0 0 12px 0;
	font-size: var(--terminal-font-size-md, 14px);
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-commands-details-section p {
	margin: 0;
	color: var(--terminal-text-secondary);
	font-size: var(--terminal-font-size-md, 13px);
	line-height: var(--terminal-line-height, 1.5);
}

.terminal-commands-details-section code {
	background: var(--terminal-bg-tertiary);
	padding: 2px 6px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-info);
	font-family: var(--terminal-font-family, 'Courier New', monospace);
}

.terminal-commands-form-group {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.terminal-commands-form-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	position: relative;
	min-height: 50px;
}

.terminal-commands-input-inline {
	background: var(--terminal-bg-tertiary) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
	padding: 6px 10px !important;
	font-size: 13px;
	border-radius: 4px;
	min-width: 200px;
	max-width: 300px;
	margin-left: 12px;
	flex-shrink: 0;
	box-sizing: border-box;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

.terminal-commands-input-inline:focus {
	outline: none;
	border-color: var(--terminal-primary) !important;
	box-shadow: 0 0 0 2px rgba(0, 122, 204, 0.2);
	background: var(--terminal-bg-tertiary) !important;
}

.terminal-commands-input-inline::placeholder {
	color: var(--terminal-text-muted);
	font-style: italic;
}

.terminal-commands-form-field label {
	font-size: 13px;
	font-weight: 500;
	color: var(--terminal-text);
	display: flex;
	align-items: center;
	gap: 6px;
}

.terminal-commands-option-label {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	cursor: pointer;
	flex-wrap: wrap;
}

.terminal-commands-option-name {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
	flex-shrink: 0;
	min-width: 0;
}

.terminal-commands-option-flag {
	background: var(--terminal-bg-tertiary);
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 12px;
	color: var(--terminal-accent);
	font-family: 'Courier New', monospace;
	font-weight: 600;
}

.terminal-commands-checkbox-label-text {
	margin-left: 4px;
}

.terminal-commands-required {
	color: var(--terminal-error);
}

.terminal-commands-optional {
	color: var(--terminal-text-muted);
	font-size: 11px;
	font-weight: normal;
}

.terminal-commands-input {
	background: var(--terminal-bg-tertiary) !important;
	border: 1px solid var(--terminal-border) !important;
	border-left: none !important;
	color: var(--terminal-text) !important;
	padding: 10px 12px !important;
	font-size: var(--terminal-font-size-md, 13px);
	border-radius: 0 4px 4px 0;
	width: 100% !important;
	margin-top: 0 !important;
	display: block !important;
	visibility: visible !important;
	opacity: 1 !important;
	cursor: text !important;
	min-height: 40px !important;
	box-sizing: border-box;
	transition: border-color 0.2s ease, box-shadow 0.2s ease;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Courier New', monospace);
}

.terminal-commands-input:focus {
	outline: none;
	border-color: var(--terminal-primary) !important;
	border-left: none !important;
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--terminal-primary) 30%, transparent), inset 0 0 0 1px color-mix(in srgb, var(--terminal-primary) 10%, transparent);
	background: var(--terminal-bg-tertiary) !important;
}

.terminal-commands-input-container:focus-within::before {
	background: var(--terminal-primary-hover);
	box-shadow: 0 0 8px color-mix(in srgb, var(--terminal-primary) 40%, transparent);
}

.terminal-commands-input::placeholder {
	color: var(--terminal-text-muted);
}

.terminal-commands-input:disabled,
.terminal-commands-input[disabled] {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-bg-tertiary) !important;
	color: var(--terminal-text-muted) !important;
	cursor: not-allowed;
	opacity: 0.5;
}

.terminal-commands-checkbox {
	width: 18px;
	height: 18px;
	cursor: pointer;
	appearance: none;
	-webkit-appearance: none;
	-moz-appearance: none;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 3px;
	flex-shrink: 0;
	position: relative;
	transition: background-color 0.2s ease, border-color 0.2s ease;
}

.terminal-commands-checkbox:checked {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
}

.terminal-commands-checkbox:checked::after {
	content: '✓';
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: bold;
	line-height: 1;
}

.terminal-commands-checkbox:focus {
	outline: 2px solid color-mix(in srgb, var(--terminal-primary) 30%, transparent);
	outline-offset: 2px;
}

.terminal-commands-shortcut {
	color: var(--terminal-text-muted);
	font-size: var(--terminal-font-size-xs, 11px);
}

.terminal-commands-field-help {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-muted);
	margin-top: 2px;
}

.terminal-commands-field-default {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-muted);
	margin-top: 2px;
}

.terminal-commands-execute-section {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--terminal-border);
}

.terminal-commands-confirmation {
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-warning);
	border-radius: 6px;
	padding: 16px;
}

.terminal-commands-confirmation-text {
	margin: 0 0 16px 0;
	color: var(--terminal-warning);
	font-size: 14px;
	font-weight: 600;
	text-align: center;
}

.terminal-commands-confirmation-buttons {
	display: flex;
	gap: 12px;
	justify-content: center;
}

.terminal-commands-confirmation-buttons .terminal-btn {
	flex: 1;
	max-width: 200px;
}

.terminal-commands-execute-btn {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	justify-content: center;
	padding: 12px;
	font-size: 14px;
	font-weight: 600;
}

.terminal-commands-execute-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-commands-spinner {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	animation: spin 1s linear infinite;
}

.terminal-commands-output-section {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--terminal-border);
}

.terminal-commands-output-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.terminal-commands-output-header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-commands-output-meta {
	display: flex;
	gap: 12px;
}

.terminal-commands-meta-item {
	font-size: 12px;
	color: var(--terminal-text-muted);
}

.terminal-commands-meta-item.success {
	color: var(--terminal-success);
}

.terminal-commands-meta-item.error {
	color: var(--terminal-error);
}

.terminal-commands-output-content {
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	max-height: 400px;
	overflow-y: auto;
}

.terminal-commands-output-content.error {
	border-color: var(--terminal-error);
}

.terminal-commands-output-content pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 12px;
	line-height: 1.5;
	color: var(--terminal-text);
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-commands-error {
	color: var(--terminal-error);
	font-size: 13px;
	font-weight: 500;
	margin-bottom: 8px;
	line-height: 1.5;
}

.terminal-commands-error strong {
	font-weight: 600;
}

.terminal-commands-error-output {
	color: var(--terminal-error);
}

/* Scrollbar styling */
.terminal-commands-list-scroll,
.terminal-commands-details-body,
.terminal-commands-output-content {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border) var(--terminal-bg);
}

.terminal-commands-list-scroll::-webkit-scrollbar,
.terminal-commands-details-body::-webkit-scrollbar,
.terminal-commands-output-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-commands-list-scroll::-webkit-scrollbar-track,
.terminal-commands-details-body::-webkit-scrollbar-track,
.terminal-commands-output-content::-webkit-scrollbar-track {
	background: var(--terminal-bg);
	border-radius: 5px;
}

.terminal-commands-list-scroll::-webkit-scrollbar-thumb,
.terminal-commands-details-body::-webkit-scrollbar-thumb,
.terminal-commands-output-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg);
}

.terminal-commands-list-scroll::-webkit-scrollbar-thumb:hover,
.terminal-commands-details-body::-webkit-scrollbar-thumb:hover,
.terminal-commands-output-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-bg-tertiary);
}

/* Animations */
@keyframes spin {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}


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

.terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary);
}

.terminal-btn-close {
	background: transparent;
	color: var(--terminal-text-secondary);
	padding: 4px;
	border: none;
	min-width: auto;
}

.terminal-btn-close:hover {
	background: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-btn-xs {
	padding: 4px 6px;
	font-size: 11px;
}

.terminal-commands-details-header-actions {
	display: flex;
	gap: 6px;
	align-items: center;
}

.terminal-commands-details-header-actions .terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-commands-execute-btn-header {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px !important;
	font-weight: 600;
}

.terminal-commands-execute-btn-header svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.spinner {
	width: 12px;
	height: 12px;
	border: 2px solid var(--terminal-border);
	border-top-color: var(--terminal-accent);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>

