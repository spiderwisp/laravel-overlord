<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import { highlightCodeAsync } from '../../utils/syntaxHighlight.js';
import NamespaceTreeNode from './NamespaceTreeNode.vue';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	initialController: {
		type: String,
		default: null,
	},
});

const emit = defineEmits(['close']);

const loading = ref(false);
const controllers = ref([]);
const searchQuery = ref('');
const expandedControllers = ref(new Set());
const selectedController = ref(null);
const selectedNamespacePath = ref(null); // Track current namespace navigation
const expandedNamespaces = ref(new Set()); // Track expanded namespace nodes
const selectedMethod = ref(null); // Track which method is expanded
const methodSourceCode = ref({}); // Store loaded source code by method key
const loadingMethodCode = ref({}); // Track loading state by method key
const highlightedCode = ref({}); // Store highlighted code by method key

// Safely get initial controller from window options
const getInitialController = computed(() => {
	if (props.initialController) {
		return props.initialController;
	}
	if (typeof window !== 'undefined' && window.overlordTabOptions?.controllers?.itemId) {
		return window.overlordTabOptions.controllers.itemId;
	}
	return null;
});

// Load controllers
async function loadControllers() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const response = await axios.get(api.url('controllers'));
		if (response.data && response.data.success && response.data.result) {
			controllers.value = response.data.result.controllers || [];
		}
	} catch (error) {
		// Silently handle errors
	} finally {
		loading.value = false;
	}
}

// Navigate to namespace
function navigateToNamespace(path) {
	selectedNamespacePath.value = path;
}

// Navigate to namespace path (from clickable links)
function navigateToNamespacePath(path) {
	if (!path) return;
	selectedNamespacePath.value = path;
	// Scroll to top of list
	nextTick(() => {
		const scrollContainer = document.querySelector('.terminal-controllers-list-scroll');
		if (scrollContainer) {
			scrollContainer.scrollTop = 0;
		}
	});
}

// Navigate back to root
function navigateToRoot() {
	selectedNamespacePath.value = null;
}

// Get namespace segments for clickable navigation
function getNamespaceSegments(namespace) {
	if (!namespace) return [];
	const segments = namespace.split('\\');
	const result = [];
	let currentPath = '';
	
	segments.forEach(segment => {
		currentPath = currentPath ? currentPath + '\\' + segment : segment;
		result.push({
			name: segment,
			path: currentPath,
		});
	});
	
	return result;
}

// Get full name segments (namespace + class name)
function getFullNameSegments(fullName) {
	if (!fullName) return [];
	const segments = fullName.split('\\');
	const result = [];
	let currentPath = '';
	
	segments.forEach((segment, index) => {
		const isLast = index === segments.length - 1;
		const isNamespace = !isLast;
		
		if (isNamespace) {
			currentPath = currentPath ? currentPath + '\\' + segment : segment;
			result.push({
				name: segment,
				path: currentPath,
				isNamespace: true,
			});
		} else {
			result.push({
				name: segment,
				path: null,
				isNamespace: false,
			});
		}
	});
	
	return result;
}

// Toggle namespace expansion (for tree view)
function toggleNamespaceExpansion(path) {
	if (expandedNamespaces.value.has(path)) {
		expandedNamespaces.value.delete(path);
	} else {
		expandedNamespaces.value.add(path);
	}
}

// Toggle controller expansion
function toggleController(controllerName) {
	if (expandedControllers.value.has(controllerName)) {
		expandedControllers.value.delete(controllerName);
	} else {
		expandedControllers.value.add(controllerName);
	}
}

// Select controller
function selectController(controller) {
	selectedController.value = controller;
	// Expand the namespace group if needed
	if (controller.namespace) {
		expandedControllers.value.add(controller.namespace);
	}
	// Scroll to the selected controller after a brief delay
	nextTick(() => {
		setTimeout(() => {
			const element = document.querySelector(`[data-controller-fullname="${controller.fullName}"]`);
			if (element) {
				element.scrollIntoView({ behavior: 'smooth', block: 'center' });
				// Add a highlight effect
				element.classList.add('highlighted');
				setTimeout(() => {
					element.classList.remove('highlighted');
				}, 2000);
			}
		}, 100);
	});
}

// Filter controllers
const filteredControllers = computed(() => {
	if (!searchQuery.value.trim()) {
		return controllers.value;
	}
	
	const query = searchQuery.value.toLowerCase();
	return controllers.value.filter(controller => {
		return controller.name.toLowerCase().includes(query) ||
			controller.fullName.toLowerCase().includes(query) ||
			controller.namespace.toLowerCase().includes(query) ||
			controller.methods.some(method => method.name.toLowerCase().includes(query));
	});
});

// Build namespace hierarchy tree
const namespaceTree = computed(() => {
	const tree = {};
	
	filteredControllers.value.forEach(controller => {
		const namespace = controller.namespace || 'App\\Http\\Controllers';
		const segments = namespace.split('\\');
		
		let current = tree;
		let currentPath = '';
		
		segments.forEach((segment, index) => {
			currentPath = currentPath ? currentPath + '\\' + segment : segment;
			
			if (!current[segment]) {
				current[segment] = {
					name: segment,
					fullPath: currentPath,
					controllers: [],
					children: {},
					level: index,
				};
			}
			
			// If this is the last segment, add the controller here
			if (index === segments.length - 1) {
				current[segment].controllers.push(controller);
			}
			
			current = current[segment].children;
		});
	});
	
	// Convert to array format for easier rendering
	function convertToArray(obj) {
		return Object.keys(obj)
			.sort()
			.map(key => ({
				...obj[key],
				children: convertToArray(obj[key].children),
			}));
	}
	
	return convertToArray(tree);
});

// Get controllers for currently selected namespace
const displayedControllers = computed(() => {
	if (!selectedNamespacePath.value) {
		// Show all controllers grouped hierarchically
		return filteredControllers.value;
	}
	
	// Filter controllers matching the selected namespace path
	return filteredControllers.value.filter(controller => {
		const namespace = controller.namespace || 'App\\Http\\Controllers';
		return namespace === selectedNamespacePath.value || namespace.startsWith(selectedNamespacePath.value + '\\');
	});
});

// Get namespace segments for breadcrumb
const namespaceBreadcrumbs = computed(() => {
	if (!selectedNamespacePath.value) {
		return [];
	}
	
	const segments = selectedNamespacePath.value.split('\\');
	const breadcrumbs = [];
	let currentPath = '';
	
	segments.forEach(segment => {
		currentPath = currentPath ? currentPath + '\\' + segment : segment;
		breadcrumbs.push({
			name: segment,
			path: currentPath,
		});
	});
	
	return breadcrumbs;
});

// Watch for visibility changes
watch(() => props.visible, async (newValue) => {
	if (newValue) {
		if (controllers.value.length === 0) {
			await loadControllers();
		}
		// After loading or if already loaded, check for initial controller
		await nextTick();
		// Check window options directly
		const controllerId = typeof window !== 'undefined' && window.overlordTabOptions?.controllers?.itemId;
		if (controllerId && controllers.value.length > 0) {
			// Small delay to ensure DOM is ready
			setTimeout(() => {
				selectControllerById(controllerId);
			}, 300);
		}
	}
});

// Watch for initial controller changes (from window options)
watch(() => {
	if (typeof window !== 'undefined' && window.overlordTabOptions?.controllers?.itemId) {
		return window.overlordTabOptions.controllers.itemId;
	}
	return null;
}, (newValue) => {
	if (newValue && controllers.value.length > 0 && props.visible) {
		setTimeout(() => {
			selectControllerById(newValue);
		}, 300);
	}
}, { immediate: false });

// Select controller by ID (fullName, name, or class name)
function selectControllerById(controllerId) {
	if (!controllerId) return;
	
	// Try multiple matching strategies
	const controller = controllers.value.find(c => {
		// Exact match on fullName
		if (c.fullName === controllerId) return true;
		
		// Exact match on name
		if (c.name === controllerId) return true;
		
		// Match if fullName ends with the identifier (handles namespaced classes)
		if (c.fullName.endsWith('\\' + controllerId)) return true;
		
		// Match if identifier is the class name (last part of fullName)
		const className = c.fullName.split('\\').pop();
		if (className === controllerId || controllerId.endsWith('\\' + className)) return true;
		
		// Match if identifier contains the fullName
		if (controllerId.includes(c.fullName)) return true;
		
		// Match if fullName contains the identifier (for partial matches)
		if (c.fullName.includes(controllerId)) return true;
		
		return false;
	});
	
	if (controller) {
		selectController(controller);
	}
}

// Load method source code
async function loadMethodSourceCode(controller, method) {
	const methodKey = `${controller}::${method}`;
	
	// If already loaded, just toggle
	if (methodSourceCode.value[methodKey]) {
		if (selectedMethod.value === methodKey) {
			selectedMethod.value = null;
		} else {
			selectedMethod.value = methodKey;
		}
		return;
	}
	
	// Set loading state
	loadingMethodCode.value[methodKey] = true;
	selectedMethod.value = methodKey;
	
	try {
		const response = await axios.get(api.controllers.methodSource(controller, method));
		
		if (response.data && response.data.success && response.data.result) {
			const sourceCode = response.data.result.source;
			methodSourceCode.value[methodKey] = sourceCode;
			
			// Highlight the code
			const highlighted = await highlightCodeAsync(sourceCode, 'php');
			highlightedCode.value[methodKey] = highlighted;
			
			// Load code theme CSS
			loadCodeTheme();
		}
	} catch (error) {
		console.error('Failed to load method source code:', error);
		methodSourceCode.value[methodKey] = '// Error loading source code';
		highlightedCode.value[methodKey] = '// Error loading source code';
	} finally {
		loadingMethodCode.value[methodKey] = false;
	}
}

// Load code theme CSS
function loadCodeTheme() {
	const theme = localStorage.getItem('terminal_code_theme') || 'github-dark';
	const themeLinkId = 'hljs-theme-link';
	
	// Remove existing theme link if any
	const existingLink = document.getElementById(themeLinkId);
	if (existingLink) {
		existingLink.remove();
	}
	
	// Load new theme
	const link = document.createElement('link');
	link.id = themeLinkId;
	link.rel = 'stylesheet';
	link.href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/${theme}.min.css`;
	document.head.appendChild(link);
}

// Watch for code theme changes
if (typeof window !== 'undefined') {
	window.addEventListener('storage', (e) => {
		if (e.key === 'terminal_code_theme') {
			loadCodeTheme();
			// Re-highlight all loaded code with new theme
			Object.keys(methodSourceCode.value).forEach(async (methodKey) => {
				const code = methodSourceCode.value[methodKey];
				if (code) {
					highlightedCode.value[methodKey] = await highlightCodeAsync(code, 'php');
				}
			});
		}
	});
}

// Copy code to clipboard
async function copyMethodCode(methodKey) {
	const code = methodSourceCode.value[methodKey];
	if (!code) return;
	
	try {
		await navigator.clipboard.writeText(code);
		// Could add a toast notification here
	} catch (error) {
		console.error('Failed to copy code:', error);
	}
}


onMounted(() => {
	if (props.visible) {
		loadControllers();
	}
	// Load code theme on mount
	loadCodeTheme();
});
</script>

<template>
	<div v-if="visible" class="terminal-controllers">
		<div class="terminal-controllers-header">
			<div class="terminal-controllers-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
				</svg>
				<span>Controllers</span>
			</div>
			<div class="terminal-controllers-controls">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search controllers..."
					class="terminal-input terminal-controllers-search"
				/>
				<button
					@click="loadControllers"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload controllers"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Controllers"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<div class="terminal-controllers-content">
			<div v-if="loading" class="terminal-controllers-loading">
				<span class="spinner"></span>
				Analyzing controllers...
			</div>

			<div v-else-if="controllers.length === 0" class="terminal-controllers-empty">
				<p>No controllers found.</p>
			</div>

			<div v-else class="terminal-controllers-main">
				<!-- Controllers List -->
				<div class="terminal-controllers-list">
					<div class="terminal-controllers-list-header">
						<h3>Controllers ({{ filteredControllers.length }})</h3>
					</div>
					<!-- Breadcrumb Navigation -->
					<div v-if="namespaceBreadcrumbs.length > 0" class="terminal-controllers-breadcrumb">
						<button
							@click="navigateToRoot"
							class="terminal-controllers-breadcrumb-item"
							title="Root"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
							</svg>
						</button>
						<span class="terminal-controllers-breadcrumb-separator">/</span>
						<button
							v-for="(crumb, index) in namespaceBreadcrumbs"
							:key="crumb.path"
							@click="navigateToNamespace(crumb.path)"
							class="terminal-controllers-breadcrumb-item"
							:class="{ 'active': index === namespaceBreadcrumbs.length - 1 }"
						>
							{{ crumb.name }}
						</button>
					</div>
					<div class="terminal-controllers-list-scroll">
						<!-- Hierarchical Tree View -->
						<template v-if="!selectedNamespacePath">
							<template v-for="node in namespaceTree" :key="node.fullPath">
								<NamespaceTreeNode
									:node="node"
									:selected-controller="selectedController"
									:expanded-namespaces="expandedNamespaces"
									@navigate="navigateToNamespace"
									@toggle-expansion="toggleNamespaceExpansion"
									@select-controller="selectController"
								/>
							</template>
						</template>
						<!-- Flat View for Selected Namespace -->
						<template v-else>
							<div class="terminal-controllers-namespace-controllers">
								<div
									v-for="controller in displayedControllers"
									:key="controller.fullName"
									:data-controller-fullname="controller.fullName"
									class="terminal-controllers-item"
									:class="{ 'active': selectedController?.fullName === controller.fullName }"
									@click="selectController(controller)"
								>
									<div class="terminal-controllers-item-header" style="padding-left: 0;">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
										</svg>
										<span class="terminal-controllers-item-name">{{ controller.name }}</span>
										<span class="terminal-controllers-item-methods-count">{{ controller.methods.length }} methods</span>
									</div>
								</div>
							</div>
						</template>
					</div>
				</div>

				<!-- Controller Details -->
				<div class="terminal-controllers-details">
					<div v-if="!selectedController" class="terminal-controllers-empty-details">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
							<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
						</svg>
						<h3>Select a Controller</h3>
						<p>Choose a controller from the list to view its methods</p>
					</div>
					<div v-else class="terminal-controllers-details-content">
						<div class="terminal-controllers-details-header">
							<h3>{{ selectedController.name }}</h3>
							<button
								@click="selectedController = null"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
								title="Clear Selection"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</div>
						<div class="terminal-controllers-details-info">
							<div class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">Namespace:</span>
								<span class="terminal-controllers-info-value">
									<span
										v-for="(segment, index) in getNamespaceSegments(selectedController.namespace)"
										:key="index"
									>
										<button
											@click="navigateToNamespacePath(segment.path)"
											class="terminal-controllers-path-link"
											:title="'Navigate to ' + segment.path"
										>
											{{ segment.name }}
										</button>
										<span v-if="index < getNamespaceSegments(selectedController.namespace).length - 1" class="terminal-controllers-path-separator">\</span>
									</span>
								</span>
							</div>
							<div class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">Full Name:</span>
								<span class="terminal-controllers-info-value">
									<span
										v-for="(segment, index) in getFullNameSegments(selectedController.fullName)"
										:key="index"
									>
										<button
											v-if="segment.isNamespace"
											@click="navigateToNamespacePath(segment.path)"
											class="terminal-controllers-path-link"
											:title="'Navigate to ' + segment.path"
										>
											{{ segment.name }}
										</button>
										<span v-else class="terminal-controllers-path-class">{{ segment.name }}</span>
										<span v-if="index < getFullNameSegments(selectedController.fullName).length - 1" class="terminal-controllers-path-separator">\</span>
									</span>
								</span>
							</div>
							<div v-if="selectedController.filePath" class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">File:</span>
								<span class="terminal-controllers-info-value">{{ selectedController.filePath }}:{{ selectedController.startLine }}-{{ selectedController.endLine }}</span>
							</div>
							<div class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">Methods:</span>
								<span class="terminal-controllers-info-value">{{ selectedController.methods.length }}</span>
							</div>
						</div>
						<!-- Inheritance Hierarchy -->
						<div v-if="selectedController.parentChain && selectedController.parentChain.length > 0" class="terminal-controllers-details-section">
							<h4>Inheritance Hierarchy</h4>
							<div class="terminal-controllers-hierarchy">
								<div
									v-for="(parent, index) in [...selectedController.parentChain].reverse()"
									:key="parent"
									class="terminal-controllers-hierarchy-item"
								>
									<span v-if="index > 0" class="terminal-controllers-hierarchy-arrow">↓</span>
									<span class="terminal-controllers-hierarchy-class">{{ parent }}</span>
								</div>
								<div class="terminal-controllers-hierarchy-item">
									<span class="terminal-controllers-hierarchy-arrow">↓</span>
									<span class="terminal-controllers-hierarchy-class current">{{ selectedController.fullName }}</span>
								</div>
							</div>
						</div>
						<div class="terminal-controllers-methods">
							<h4>Methods</h4>
							<div class="terminal-controllers-methods-list">
								<div
									v-for="method in selectedController.methods"
									:key="method.name"
									class="terminal-controllers-method"
									:class="{ 'expanded': selectedMethod === `${selectedController.fullName}::${method.name}` }"
								>
									<div 
										class="terminal-controllers-method-header"
										@click="loadMethodSourceCode(selectedController.fullName, method.name)"
									>
										<div class="terminal-controllers-method-name">
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
											</svg>
											<span>{{ method.name }}</span>
											<svg 
												class="terminal-controllers-method-toggle"
												:class="{ 'expanded': selectedMethod === `${selectedController.fullName}::${method.name}` }"
												xmlns="http://www.w3.org/2000/svg" 
												fill="none" 
												viewBox="0 0 24 24" 
												stroke="currentColor"
											>
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
											</svg>
										</div>
										<div v-if="method.parameters.length > 0" class="terminal-controllers-method-params">
											<span class="terminal-controllers-param-label">Parameters:</span>
											<span
												v-for="(param, index) in method.parameters"
												:key="param.name"
												class="terminal-controllers-param"
											>
												<span v-if="param.type" class="terminal-controllers-param-type">{{ param.type }}</span>
												<span class="terminal-controllers-param-name">${{ param.name }}</span>
												<span v-if="param.hasDefault" class="terminal-controllers-param-default">(optional)</span>
												<span v-if="index < method.parameters.length - 1">, </span>
											</span>
										</div>
									</div>
									<!-- Method Source Code -->
									<div 
										v-if="selectedMethod === `${selectedController.fullName}::${method.name}`"
										class="terminal-controllers-method-code"
									>
										<div v-if="loadingMethodCode[`${selectedController.fullName}::${method.name}`]" class="terminal-controllers-method-code-loading">
											<span class="spinner"></span>
											Loading source code...
										</div>
										<div v-else-if="highlightedCode[`${selectedController.fullName}::${method.name}`]" class="terminal-controllers-method-code-content">
											<div class="terminal-controllers-method-code-header">
												<span class="terminal-controllers-method-code-title">Source Code</span>
												<button
													@click.stop="copyMethodCode(`${selectedController.fullName}::${method.name}`)"
													class="terminal-controllers-method-code-copy"
													title="Copy code"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
														<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
													</svg>
												</button>
											</div>
											<pre class="terminal-controllers-method-code-pre"><code class="hljs language-php" v-html="highlightedCode[`${selectedController.fullName}::${method.name}`]"></code></pre>
										</div>
										<div v-else class="terminal-controllers-method-code-error">
											Error loading source code
										</div>
									</div>
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
.terminal-controllers {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
}

.terminal-controllers-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-controllers-title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-text);
	font-weight: 600;
	font-size: 14px;
}

.terminal-controllers-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-controllers-search {
	width: 250px;
	font-size: 12px;
	padding: 6px 12px !important;
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
}

.terminal-controllers-search:focus {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-primary) !important;
	outline: none !important;
}

.terminal-controllers-search::placeholder {
	color: var(--terminal-text-muted) !important;
}

.terminal-controllers-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-controllers-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 40px;
	color: var(--terminal-text-secondary);
}

.terminal-controllers-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--terminal-text-secondary);
}

.terminal-controllers-main {
	flex: 1;
	display: flex;
	overflow: hidden;
}

.terminal-controllers-list {
	width: 450px;
	min-width: 450px;
	background: var(--terminal-bg-secondary);
	border-right: 1px solid var(--terminal-border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	height: 100%;
}

.terminal-controllers-list-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-controllers-list-header h3 {
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 600;
	margin: 0;
}

.terminal-controllers-list-scroll {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px;
	min-height: 0;
}

.terminal-controllers-group {
	margin-bottom: 16px;
}

.terminal-controllers-group-header {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 12px;
	color: var(--terminal-text-secondary);
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	margin-bottom: 4px;
}

.terminal-controllers-group-header svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-controllers-group-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-controllers-group-count {
	color: var(--terminal-text-muted);
	font-weight: normal;
}

.terminal-controllers-item {
	padding: 8px 12px;
	margin-bottom: 2px;
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
	border: 1px solid transparent;
}

.terminal-controllers-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-border);
}

.terminal-controllers-item:hover .terminal-controllers-item-name {
	color: var(--terminal-primary);
}

.terminal-controllers-item.active {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
}

.terminal-controllers-item.active .terminal-controllers-item-name {
	color: var(--terminal-primary);
	font-weight: 600;
}

.terminal-controllers-item.highlighted {
	background: var(--terminal-primary);
	color: var(--terminal-bg);
	animation: highlight-pulse 0.5s ease-in-out;
}

@keyframes highlight-pulse {
	0% {
		background: var(--terminal-primary);
		transform: scale(1);
	}
	50% {
		background: var(--terminal-primary-hover);
		transform: scale(1.02);
	}
	100% {
		background: var(--terminal-primary);
		transform: scale(1);
	}
}

.terminal-controllers-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-left: 0;
}

.terminal-controllers-item-indent {
	display: flex;
	align-items: center;
	margin-left: -20px;
	color: var(--terminal-text-muted);
	flex-shrink: 0;
}

.terminal-controllers-item-indent svg {
	width: 12px;
	height: 12px;
}

.terminal-controllers-item-header svg {
	flex-shrink: 0;
	width: 14px !important;
	height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
}

.terminal-controllers-item-name {
	flex: 1;
	color: var(--terminal-text);
	font-size: var(--terminal-font-size-xs, 12px);
	font-family: var(--terminal-font-family, monospace);
	font-weight: 500;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	transition: color 0.2s, font-weight 0.2s;
}

.terminal-controllers-item-methods-count {
	color: var(--terminal-text-muted);
	font-size: var(--terminal-font-size-xxs, 10px);
	font-family: var(--terminal-font-family, monospace);
	margin-left: auto;
	flex-shrink: 0;
	padding-left: 12px;
	white-space: nowrap;
}

.terminal-controllers-details {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-controllers-empty-details {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 16px;
	color: var(--terminal-text-secondary);
}

.terminal-controllers-empty-details h3 {
	color: var(--terminal-text);
	font-size: 16px;
	margin: 0;
}

.terminal-controllers-empty-details p {
	font-size: 12px;
	margin: 0;
}

.terminal-controllers-empty-details svg {
	width: 32px !important;
	height: 32px !important;
	max-width: 32px !important;
	max-height: 32px !important;
	flex-shrink: 0;
	color: var(--terminal-text-secondary);
	opacity: 0.3;
}

.terminal-controllers-details-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	padding: 20px;
	min-height: 0;
}

.terminal-controllers-details-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-controllers-details-header h3 {
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
	margin: 0;
}

.terminal-controllers-details-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-controllers-info-item {
	display: flex;
	gap: 12px;
}

.terminal-controllers-info-label {
	color: var(--terminal-text-secondary);
	font-size: 12px;
	font-weight: 500;
	min-width: 100px;
}

.terminal-controllers-info-value {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0;
}

.terminal-controllers-path-link {
	background: transparent;
	border: none;
	color: var(--terminal-primary);
	font-size: 12px;
	font-family: 'Courier New', monospace;
	cursor: pointer;
	padding: 2px 4px;
	border-radius: 3px;
	transition: background 0.2s, color 0.2s;
	text-decoration: none;
}

.terminal-controllers-path-link:hover {
	background: var(--terminal-bg-tertiary);
	color: var(--terminal-primary-hover);
	text-decoration: underline;
}

.terminal-controllers-path-separator {
	color: var(--terminal-text-muted);
	margin: 0 2px;
}

.terminal-controllers-path-class {
	color: var(--terminal-text);
	font-family: 'Courier New', monospace;
}

.terminal-controllers-details-section {
	margin-bottom: 24px;
}

.terminal-controllers-details-section h4 {
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 12px 0;
}

.terminal-controllers-hierarchy {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-controllers-hierarchy-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-controllers-hierarchy-arrow {
	color: var(--terminal-text-muted);
	font-size: 14px;
}

.terminal-controllers-hierarchy-class {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
}

.terminal-controllers-hierarchy-class.current {
	color: var(--terminal-accent);
	font-weight: 600;
}

.terminal-controllers-methods {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-height: 0;
	overflow: hidden;
}

.terminal-controllers-methods h4 {
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 16px 0;
	flex-shrink: 0;
}

.terminal-controllers-methods-list {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 8px;
	overflow-y: auto;
	overflow-x: hidden;
	padding-right: 8px;
	padding-bottom: 72px;
}

.terminal-controllers-method {
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	transition: all 0.2s;
}

.terminal-controllers-method.expanded {
	border-color: var(--terminal-primary);
}

.terminal-controllers-method-header {
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-controllers-method-header:hover {
	opacity: 0.8;
}

.terminal-controllers-method-name {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-accent);
	font-size: 13px;
	font-weight: 600;
	font-family: 'Courier New', monospace;
	margin-bottom: 8px;
}

.terminal-controllers-method-toggle {
	width: 16px;
	height: 16px;
	margin-left: auto;
	transition: transform 0.2s;
	flex-shrink: 0;
}

.terminal-controllers-method-toggle.expanded {
	transform: rotate(90deg);
}

.terminal-controllers-method-name svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-controllers-method-params {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	font-size: 11px;
	color: var(--terminal-text-secondary);
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--terminal-border);
}

.terminal-controllers-param-label {
	color: var(--terminal-text-muted);
	font-weight: 500;
}

.terminal-controllers-param {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.terminal-controllers-param-type {
	color: var(--terminal-warning);
	font-family: 'Courier New', monospace;
}

.terminal-controllers-param-name {
	color: var(--terminal-text);
	font-family: 'Courier New', monospace;
}

.terminal-controllers-param-default {
	color: var(--terminal-text-muted);
	font-size: 10px;
	font-style: italic;
}

/* Method Source Code Styles */
.terminal-controllers-method-code {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--terminal-border);
}

.terminal-controllers-method-code-loading {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	color: var(--terminal-text-secondary);
	font-size: 12px;
}

.terminal-controllers-method-code-content {
	display: flex;
	flex-direction: column;
}

.terminal-controllers-method-code-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	background: var(--terminal-bg);
	border-bottom: 1px solid var(--terminal-border);
	border-radius: 4px 4px 0 0;
}

.terminal-controllers-method-code-title {
	color: var(--terminal-text);
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
}

.terminal-controllers-method-code-copy {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	background: transparent;
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text-secondary);
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-controllers-method-code-copy:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
	color: var(--terminal-primary);
}

.terminal-controllers-method-code-copy svg {
	width: 14px;
	height: 14px;
}

.terminal-controllers-method-code-pre {
	margin: 0;
	padding: 16px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-top: none;
	border-radius: 0 0 4px 4px;
	overflow-x: auto;
	font-family: var(--terminal-font-family, 'Courier New', monospace);
	font-size: var(--terminal-font-size-xs, 12px);
	line-height: var(--terminal-line-height, 1.6);
}

.terminal-controllers-method-code-pre code {
	background: transparent;
	padding: 0;
	border: none;
	font-family: inherit;
	font-size: inherit;
	line-height: inherit;
	color: inherit;
}

.terminal-controllers-method-code-error {
	padding: 16px;
	color: var(--terminal-error);
	font-size: 12px;
	text-align: center;
}

/* Scrollbar styling */
.terminal-controllers-list-scroll::-webkit-scrollbar,
.terminal-controllers-methods-list::-webkit-scrollbar {
	width: 10px;
}

.terminal-controllers-list-scroll::-webkit-scrollbar-track,
.terminal-controllers-methods-list::-webkit-scrollbar-track {
	background: var(--terminal-bg);
}

.terminal-controllers-list-scroll::-webkit-scrollbar-thumb,
.terminal-controllers-methods-list::-webkit-scrollbar-thumb {
	background: var(--terminal-bg-tertiary);
	border-radius: 5px;
}

.terminal-controllers-list-scroll::-webkit-scrollbar-thumb:hover,
.terminal-controllers-methods-list::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-bg-secondary);
}

/* Firefox scrollbar styling */
.terminal-controllers-list-scroll,
.terminal-controllers-methods-list {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-bg-tertiary) var(--terminal-bg);
}

/* Breadcrumb Navigation */
.terminal-controllers-breadcrumb {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg);
	flex-wrap: wrap;
}

.terminal-controllers-breadcrumb-item {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 4px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary);
	font-size: 11px;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-controllers-breadcrumb-item:hover {
	background: var(--terminal-bg-secondary);
	color: var(--terminal-text);
}

.terminal-controllers-breadcrumb-item.active {
	color: var(--terminal-text);
	font-weight: 600;
}

.terminal-controllers-breadcrumb-item svg {
	width: 14px;
	height: 14px;
}

.terminal-controllers-breadcrumb-separator {
	color: var(--terminal-text-muted);
	font-size: 11px;
	margin: 0 2px;
}

/* Namespace Tree Styles */
.terminal-controllers-namespace-node {
	margin-bottom: 4px;
}

.terminal-controllers-namespace-wrapper {
	display: flex;
	flex-direction: column;
}

.terminal-controllers-namespace-item {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border-radius: 4px;
	cursor: pointer;
	transition: all 0.2s;
	color: var(--terminal-text);
	font-size: var(--terminal-font-size-xs, 12px);
	font-family: var(--terminal-font-family, monospace);
	border: 1px solid transparent;
}

.terminal-controllers-namespace-item:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-border);
}

.terminal-controllers-namespace-item:hover .terminal-controllers-namespace-name {
	color: var(--terminal-primary);
}

.terminal-controllers-namespace-toggle {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 16px;
	height: 16px;
	padding: 0;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary);
	cursor: pointer;
	transition: transform 0.2s, color 0.2s;
	flex-shrink: 0;
}

.terminal-controllers-namespace-toggle:hover {
	color: var(--terminal-text);
}

.terminal-controllers-namespace-toggle.expanded {
	transform: rotate(90deg);
}

.terminal-controllers-namespace-toggle svg {
	width: 12px;
	height: 12px;
}

.terminal-controllers-namespace-spacer {
	width: 16px;
	height: 16px;
	flex-shrink: 0;
	opacity: 0;
}

.terminal-controllers-namespace-folder-icon {
	width: 14px !important;
	height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	flex-shrink: 0;
	display: block;
}

/* Ensure all namespace item SVGs are properly sized */
.terminal-controllers-namespace-item svg:not(.terminal-controllers-namespace-spacer) {
	width: 14px !important;
	height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	flex-shrink: 0;
	display: block;
}

/* Ensure controller icons in namespace tree are properly sized */
.terminal-controllers-namespace-wrapper .terminal-controllers-item-header svg {
	width: 14px !important;
	height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	flex-shrink: 0;
}

.terminal-controllers-namespace-name {
	flex: 1;
	color: var(--terminal-text);
	font-weight: 500;
	transition: color 0.2s;
}

.terminal-controllers-namespace-count {
	color: var(--terminal-text-muted);
	font-size: 11px;
	font-weight: normal;
}

.terminal-controllers-namespace-controllers {
	display: flex;
	flex-direction: column;
}
</style>

