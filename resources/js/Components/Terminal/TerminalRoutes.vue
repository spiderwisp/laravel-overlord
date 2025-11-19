<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';
import TerminalCrossReference from './TerminalCrossReference.vue';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	initialRoute: {
		type: String,
		default: null,
	},
});

// Safely get initial route from window options
const getInitialRoute = computed(() => {
	if (props.initialRoute) {
		return props.initialRoute;
	}
	if (typeof window !== 'undefined' && window.overlordTabOptions?.routes?.itemId) {
		return window.overlordTabOptions.routes.itemId;
	}
	return null;
});

const emit = defineEmits(['close', 'navigate-to']);

const loading = ref(false);
const routes = ref([]);
const selectedRoute = ref(null);
const searchQuery = ref('');
const filterMethod = ref('');
const filterUri = ref('');
const filterName = ref('');
const filterMiddleware = ref('');
const sortColumn = ref('uri');
const sortDirection = ref('asc');
const showDetailModal = ref(false);
const urlGeneratorName = ref('');
const urlGeneratorParams = ref({});
const routeTesterParams = ref({});
const testingRoute = ref(false);
const generatingUrl = ref(false);

// Load routes
async function loadRoutes() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const params = {};
		if (filterMethod.value) params.method = filterMethod.value;
		if (filterUri.value) params.uri = filterUri.value;
		if (filterName.value) params.name = filterName.value;
		if (filterMiddleware.value) params.middleware = filterMiddleware.value;
		if (searchQuery.value) params.search = searchQuery.value;

		const response = await axios.get(api.routes.list(params));
		if (response.data && response.data.success && response.data.result) {
			routes.value = response.data.result.routes || [];
			
			// If initialRoute is provided, select it
			const initialRouteId = getInitialRoute.value;
			if (initialRouteId && routes.value.length > 0) {
				const route = routes.value.find(r => 
					r.identifier === initialRouteId || 
					r.name === initialRouteId
				);
				if (route) {
					selectRoute(route);
				}
			}
		}
	} catch (error) {
		console.error('Failed to load routes:', error);
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || 'Failed to load routes',
		});
	} finally {
		loading.value = false;
	}
}

// Select route
function selectRoute(route) {
	selectedRoute.value = route;
	showDetailModal.value = true;
	// Reset form values
	urlGeneratorName.value = route.name || '';
	urlGeneratorParams.value = {};
	routeTesterParams.value = {};
	// Pre-fill defaults if available
	if (route.parameters) {
		route.parameters.forEach(param => {
			if (param.default !== null && param.default !== undefined) {
				routeTesterParams.value[param.name] = param.default;
				urlGeneratorParams.value[param.name] = param.default;
			}
		});
	}
}

// Filter routes
const filteredRoutes = computed(() => {
	let filtered = routes.value;

	if (searchQuery.value.trim()) {
		const query = searchQuery.value.toLowerCase();
		filtered = filtered.filter(route => {
			return route.uri.toLowerCase().includes(query) ||
				(route.name && route.name.toLowerCase().includes(query)) ||
				route.action.toLowerCase().includes(query);
		});
	}

	// Sort
	filtered = [...filtered].sort((a, b) => {
		let aVal = a[sortColumn.value] || '';
		let bVal = b[sortColumn.value] || '';
		
		if (Array.isArray(aVal)) {
			aVal = aVal.join(', ');
		}
		if (Array.isArray(bVal)) {
			bVal = bVal.join(', ');
		}
		
		aVal = String(aVal).toLowerCase();
		bVal = String(bVal).toLowerCase();
		
		if (sortDirection.value === 'asc') {
			return aVal.localeCompare(bVal);
		} else {
			return bVal.localeCompare(aVal);
		}
	});

	return filtered;
});

// Sort by column
function sortBy(column) {
	if (sortColumn.value === column) {
		sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
	} else {
		sortColumn.value = column;
		sortDirection.value = 'asc';
	}
}

// Generate URL
async function generateUrl() {
	if (!urlGeneratorName.value.trim()) {
		Swal.fire({
			icon: 'warning',
			title: 'Route Name Required',
			text: 'Please enter a route name',
		});
		return;
	}

	generatingUrl.value = true;
	try {
		const response = await axios.post(api.routes.generateUrl(), {
			name: urlGeneratorName.value,
			parameters: urlGeneratorParams.value,
		});

		if (response.data && response.data.success) {
			const url = response.data.result.url;
			await navigator.clipboard.writeText(url);
			Swal.fire({
				icon: 'success',
				title: 'URL Generated',
				text: `URL copied to clipboard: ${url}`,
			});
		}
	} catch (error) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || 'Failed to generate URL',
		});
	} finally {
		generatingUrl.value = false;
	}
}

// Test route
async function testRoute() {
	// Build URI from route and parameters
	let uri = selectedRoute.value.uri;
	
	// Replace parameters in URI
	if (selectedRoute.value.parameters.length > 0) {
		for (const param of selectedRoute.value.parameters) {
			const value = routeTesterParams.value[param.name] || param.default || '';
			if (value) {
				uri = uri.replace('{' + param.name + '}', value);
				uri = uri.replace('{' + param.name + '?}', value);
			} else if (param.required) {
				Swal.fire({
					icon: 'warning',
					title: 'Required Parameter Missing',
					text: `Please provide a value for the required parameter: ${param.name}`,
				});
				return;
			}
		}
	}

	const result = await Swal.fire({
		icon: 'warning',
		title: 'Test Route',
		html: `This will make a GET request to:<br><code style="background: var(--terminal-bg-secondary); padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 8px;">${uri}</code><br><br>Continue?`,
		showCancelButton: true,
		confirmButtonText: 'Test',
		cancelButtonText: 'Cancel',
	});

	if (!result.isConfirmed) return;

	testingRoute.value = true;
	try {
		const response = await axios.post(api.routes.test(), {
			uri: uri,
			method: 'GET',
			parameters: routeTesterParams.value,
		});

		if (response.data && response.data.success) {
			const result = response.data.result;
			Swal.fire({
				icon: 'info',
				title: 'Route Test Result',
				html: `
					<p><strong>Status:</strong> ${result.status}</p>
					<p><strong>Body Length:</strong> ${result.body_length} bytes</p>
					<pre style="text-align: left; max-height: 300px; overflow: auto; background: var(--terminal-code-bg, #1e1e1e); padding: 10px; border-radius: 4px; color: var(--terminal-text, #d4d4d4); border: 1px solid var(--terminal-code-border, #3e3e42);">${result.body_preview}</pre>
				`,
				width: '800px',
			});
		}
	} catch (error) {
		Swal.fire({
			icon: 'error',
			title: 'Error',
			text: error.response?.data?.errors?.[0] || 'Failed to test route',
		});
	} finally {
		testingRoute.value = false;
	}
}

// Handle cross-reference navigation
function handleNavigate(navData) {
	emit('navigate-to', navData);
}

// Check if required parameters are filled
const hasRequiredParams = computed(() => {
	if (!selectedRoute.value || selectedRoute.value.parameters.length === 0) {
		return true;
	}
	
	const requiredParams = selectedRoute.value.parameters.filter(p => p.required);
	if (requiredParams.length === 0) {
		return true;
	}
	
	return requiredParams.every(param => {
		const value = routeTesterParams.value[param.name];
		return value !== undefined && value !== null && value.trim() !== '';
	});
});

// Watch for visibility changes
watch(() => props.visible, async (newValue) => {
	if (newValue && routes.value.length === 0) {
		await loadRoutes();
		// After loading, check for initial route
		await nextTick();
		const initialRouteId = getInitialRoute.value;
		if (initialRouteId && routes.value.length > 0) {
			const route = routes.value.find(r => 
				r.identifier === initialRouteId || 
				r.name === initialRouteId
			);
			if (route) {
				selectRoute(route);
			}
		}
	}
});

watch(() => getInitialRoute.value, (newValue) => {
	if (newValue && routes.value.length > 0) {
		const route = routes.value.find(r => 
			r.identifier === newValue || 
			r.name === newValue
		);
		if (route) {
			selectRoute(route);
		}
	}
});

onMounted(() => {
	if (props.visible) {
		loadRoutes();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-routes">
		<div class="terminal-routes-header">
			<div class="terminal-routes-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
				</svg>
				<span>Routes</span>
			</div>
			<div class="terminal-routes-controls">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search routes..."
					class="terminal-input terminal-routes-search"
				/>
				<button
					@click="loadRoutes"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload routes"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Routes"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Filters -->
		<div class="terminal-routes-filters">
			<input
				v-model="filterMethod"
				type="text"
				placeholder="Method (GET, POST...)"
				class="terminal-input terminal-routes-filter"
			/>
			<input
				v-model="filterUri"
				type="text"
				placeholder="URI pattern"
				class="terminal-input terminal-routes-filter"
			/>
			<input
				v-model="filterName"
				type="text"
				placeholder="Route name"
				class="terminal-input terminal-routes-filter"
			/>
			<input
				v-model="filterMiddleware"
				type="text"
				placeholder="Middleware"
				class="terminal-input terminal-routes-filter"
			/>
			<button
				@click="filterMethod = ''; filterUri = ''; filterName = ''; filterMiddleware = '';"
				class="terminal-btn terminal-btn-secondary terminal-btn-sm"
				title="Clear filters"
			>
				Clear
			</button>
		</div>

		<div class="terminal-routes-content">
			<!-- Loading State -->
			<div v-if="loading" class="terminal-routes-loading">
				<span class="spinner"></span>
				Loading routes...
			</div>

			<!-- Empty State -->
			<div v-else-if="filteredRoutes.length === 0" class="terminal-routes-empty">
				<p>No routes found.</p>
			</div>

			<!-- Routes Table -->
			<div v-else class="terminal-routes-table-container">
				<table class="terminal-routes-table">
					<thead>
						<tr>
							<th @click="sortBy('methods')" class="sortable">
								Methods
								<span v-if="sortColumn === 'methods'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '↑' : '↓' }}
								</span>
							</th>
							<th @click="sortBy('uri')" class="sortable">
								URI
								<span v-if="sortColumn === 'uri'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '↑' : '↓' }}
								</span>
							</th>
							<th @click="sortBy('name')" class="sortable">
								Name
								<span v-if="sortColumn === 'name'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '↑' : '↓' }}
								</span>
							</th>
							<th @click="sortBy('action')" class="sortable">
								Action
								<span v-if="sortColumn === 'action'" class="sort-indicator">
									{{ sortDirection === 'asc' ? '↑' : '↓' }}
								</span>
							</th>
							<th>Middleware</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="route in filteredRoutes"
							:key="route.identifier"
							:class="{ 'selected': selectedRoute?.identifier === route.identifier }"
							@click="selectRoute(route)"
						>
							<td>
								<div class="terminal-routes-methods">
									<span
										v-for="method in route.methods"
										:key="method"
										class="terminal-routes-method-badge"
										:class="'method-' + method.toLowerCase()"
									>
										{{ method }}
									</span>
								</div>
							</td>
							<td class="terminal-routes-uri">{{ route.uri }}</td>
							<td class="terminal-routes-name">{{ route.name || '-' }}</td>
							<td class="terminal-routes-action">
								<span v-if="route.controller">
									{{ route.controller.class.split('\\').pop() }}
									<span v-if="route.controller.method">@{{ route.controller.method }}</span>
								</span>
								<span v-else>{{ route.action }}</span>
							</td>
							<td>
								<span class="terminal-routes-middleware-count">
									{{ route.middleware.length }} middleware
								</span>
							</td>
							<td>
								<button
									@click.stop="selectRoute(route)"
									class="terminal-btn terminal-btn-sm"
									title="View details"
								>
									View
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Route Detail Modal -->
		<div v-if="showDetailModal && selectedRoute" class="terminal-routes-modal" @click.self="showDetailModal = false">
			<div class="terminal-routes-modal-content">
				<div class="terminal-routes-modal-header">
					<h3>Route Details</h3>
					<button
						@click="showDetailModal = false"
						class="terminal-btn terminal-btn-close terminal-btn-sm"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
							<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>

				<div class="terminal-routes-modal-body">
					<!-- Basic Info -->
					<div class="terminal-routes-detail-section">
						<h4>Basic Information</h4>
						<div class="terminal-routes-detail-info">
							<div class="terminal-routes-info-item">
								<span class="terminal-routes-info-label">URI:</span>
								<span class="terminal-routes-info-value">{{ selectedRoute.uri }}</span>
							</div>
							<div class="terminal-routes-info-item">
								<span class="terminal-routes-info-label">Methods:</span>
								<span class="terminal-routes-info-value">{{ selectedRoute.methods.join(', ') }}</span>
							</div>
							<div v-if="selectedRoute.name" class="terminal-routes-info-item">
								<span class="terminal-routes-info-label">Name:</span>
								<span class="terminal-routes-info-value">{{ selectedRoute.name }}</span>
							</div>
							<div class="terminal-routes-info-item">
								<span class="terminal-routes-info-label">Action:</span>
								<span class="terminal-routes-info-value">{{ selectedRoute.action }}</span>
							</div>
							<div v-if="selectedRoute.domain" class="terminal-routes-info-item">
								<span class="terminal-routes-info-label">Domain:</span>
								<span class="terminal-routes-info-value">{{ selectedRoute.domain }}</span>
							</div>
						</div>
					</div>

					<!-- Parameters -->
					<div v-if="selectedRoute.parameters.length > 0" class="terminal-routes-detail-section">
						<h4>Parameters</h4>
						<div class="terminal-routes-parameters">
							<div
								v-for="param in selectedRoute.parameters"
								:key="param.name"
								class="terminal-routes-parameter"
							>
								<span class="terminal-routes-param-name">{{ param.name }}</span>
								<span v-if="param.required" class="terminal-routes-param-badge required">Required</span>
								<span v-else class="terminal-routes-param-badge optional">Optional</span>
								<span v-if="param.pattern" class="terminal-routes-param-pattern">Pattern: {{ param.pattern }}</span>
								<span v-if="param.default !== null" class="terminal-routes-param-default">Default: {{ param.default }}</span>
							</div>
						</div>
					</div>

					<!-- Middleware -->
					<div v-if="selectedRoute.middleware.length > 0" class="terminal-routes-detail-section">
						<h4>Middleware</h4>
						<TerminalCrossReference
							:references="selectedRoute.middleware"
							type="middleware"
							@navigate="handleNavigate"
						/>
					</div>

					<!-- Cross-References -->
					<div v-if="selectedRoute.cross_references" class="terminal-routes-detail-section">
						<h4>Related</h4>
						<div v-if="selectedRoute.cross_references.controller" class="terminal-routes-cross-ref-group">
							<span class="terminal-routes-cross-ref-label">Controller:</span>
							<TerminalCrossReference
								:references="selectedRoute.cross_references.controller"
								type="controller"
								@navigate="handleNavigate"
							/>
						</div>
						<div v-if="selectedRoute.cross_references.models.length > 0" class="terminal-routes-cross-ref-group">
							<span class="terminal-routes-cross-ref-label">Models:</span>
							<TerminalCrossReference
								:references="selectedRoute.cross_references.models"
								type="model"
								@navigate="handleNavigate"
							/>
						</div>
						<div v-if="selectedRoute.cross_references.services.length > 0" class="terminal-routes-cross-ref-group">
							<span class="terminal-routes-cross-ref-label">Services:</span>
							<TerminalCrossReference
								:references="selectedRoute.cross_references.services"
								type="service"
								@navigate="handleNavigate"
							/>
						</div>
					</div>

					<!-- URL Generator -->
					<div v-if="selectedRoute.name" class="terminal-routes-detail-section">
						<h4>Generate URL</h4>
						<p class="terminal-routes-section-desc">Generate a URL for this named route. The route name is pre-filled.</p>
						<div class="terminal-routes-url-generator">
							<div class="terminal-routes-url-input-group">
								<label class="terminal-routes-url-label">Route Name</label>
								<input
									v-model="urlGeneratorName"
									type="text"
									:placeholder="selectedRoute.name"
									class="terminal-input"
								/>
							</div>
							<div v-if="selectedRoute.parameters.length > 0" class="terminal-routes-params-input">
								<div class="terminal-routes-params-header">
									<span class="terminal-routes-params-title">Route Parameters</span>
									<span class="terminal-routes-params-subtitle">Enter values for each parameter</span>
								</div>
								<div
									v-for="param in selectedRoute.parameters"
									:key="param.name"
									class="terminal-routes-param-input"
								>
									<label>
										<span class="terminal-routes-param-label-name">{{ param.name }}</span>
										<span v-if="param.required" class="terminal-routes-param-badge required">Required</span>
										<span v-else class="terminal-routes-param-badge optional">Optional</span>
									</label>
									<input
										v-model="urlGeneratorParams[param.name]"
										type="text"
										:placeholder="param.default || (param.required ? 'Enter value' : 'Optional value')"
										class="terminal-input"
									/>
								</div>
							</div>
							<button
								@click="generateUrl"
								class="terminal-btn terminal-btn-primary terminal-routes-generate-btn"
								:disabled="generatingUrl || !urlGeneratorName.trim()"
							>
								<svg v-if="!generatingUrl" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
								</svg>
								<span v-if="generatingUrl" class="spinner spinner-sm"></span>
								{{ generatingUrl ? 'Generating...' : 'Generate & Copy URL' }}
							</button>
						</div>
					</div>

					<!-- Route Tester -->
					<div class="terminal-routes-detail-section">
						<h4>Test Route (GET only)</h4>
						<p class="terminal-routes-section-desc">Test this route by providing parameter values. The URI will be automatically constructed.</p>
						<div class="terminal-routes-tester">
							<div v-if="selectedRoute.parameters.length > 0" class="terminal-routes-params-input">
								<div class="terminal-routes-params-header">
									<span class="terminal-routes-params-title">Route Parameters</span>
									<span class="terminal-routes-params-subtitle">Enter values for each parameter</span>
								</div>
								<div
									v-for="param in selectedRoute.parameters"
									:key="param.name"
									class="terminal-routes-param-input"
								>
									<label>
										<span class="terminal-routes-param-label-name">{{ param.name }}</span>
										<span v-if="param.required" class="terminal-routes-param-badge required">Required</span>
										<span v-else class="terminal-routes-param-badge optional">Optional</span>
									</label>
									<input
										v-model="routeTesterParams[param.name]"
										type="text"
										:placeholder="param.default || (param.required ? 'Enter value' : 'Optional value')"
										class="terminal-input"
									/>
									<span v-if="param.pattern" class="terminal-routes-param-hint" :title="`Pattern: ${param.pattern}`">
										Pattern: {{ param.pattern }}
									</span>
								</div>
							</div>
							<div v-else class="terminal-routes-no-params">
								<p>This route has no parameters. Click the button below to test it.</p>
							</div>
							<button
								@click="testRoute"
								class="terminal-btn terminal-btn-primary terminal-routes-test-btn"
								:disabled="testingRoute || (selectedRoute.parameters.length > 0 && !hasRequiredParams)"
							>
								<svg v-if="!testingRoute" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
									<path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
								</svg>
								<span v-if="testingRoute" class="spinner spinner-sm"></span>
								{{ testingRoute ? 'Testing...' : 'Test Route' }}
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-routes {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
	font-family: var(--terminal-font-family, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif);
	font-size: var(--terminal-font-size-base, 14px);
	line-height: var(--terminal-line-height, 1.6);
}

.terminal-routes-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-routes-title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-text);
	font-weight: 600;
	font-size: var(--terminal-font-size-md, 14px);
}

.terminal-routes-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-routes-search {
	width: 250px;
	font-size: var(--terminal-font-size-sm, 12px);
	padding: 6px 12px !important;
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
}

.terminal-routes-search:focus {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-primary) !important;
	outline: none !important;
}

.terminal-routes-search::placeholder {
	color: var(--terminal-text-muted) !important;
}

.terminal-routes-filters {
	display: flex;
	gap: 8px;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
	flex-wrap: wrap;
}

.terminal-routes-filter {
	width: 150px;
	font-size: var(--terminal-font-size-sm, 12px);
	padding: 6px 12px !important;
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
}

.terminal-routes-filter:focus {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-primary) !important;
	outline: none !important;
}

.terminal-routes-filter::placeholder {
	color: var(--terminal-text-muted) !important;
}

.terminal-routes-content {
	flex: 1;
	overflow: hidden;
	display: flex;
	flex-direction: column;
}

.terminal-routes-loading,
.terminal-routes-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 40px;
	color: var(--terminal-text-secondary);
}

.terminal-routes-table-container {
	flex: 1;
	overflow: auto;
}

.terminal-routes-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--terminal-font-size-sm, 12px);
}

.terminal-routes-table thead {
	position: sticky;
	top: 0;
	background: var(--terminal-bg-secondary);
	z-index: 10;
}

.terminal-routes-table th {
	padding: 12px;
	text-align: left;
	font-weight: 600;
	border-bottom: 2px solid var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-routes-table th.sortable {
	cursor: pointer;
	user-select: none;
}

.terminal-routes-table th.sortable:hover {
	background: var(--terminal-bg);
}

.sort-indicator {
	margin-left: 4px;
	color: var(--terminal-primary);
}

.terminal-routes-table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-routes-table tbody tr {
	cursor: pointer;
	transition: background 0.2s;
}

.terminal-routes-table tbody tr:hover {
	background: var(--terminal-bg-secondary);
}

.terminal-routes-table tbody tr.selected {
	background: var(--terminal-primary);
	color: var(--terminal-bg);
}

.terminal-routes-methods {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
}

.terminal-routes-method-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-xs, 10px);
	font-weight: 600;
	text-transform: uppercase;
}

.terminal-routes-method-badge.method-get {
	background: #10b981;
	color: white;
}

.terminal-routes-method-badge.method-post {
	background: #3b82f6;
	color: white;
}

.terminal-routes-method-badge.method-put {
	background: #f59e0b;
	color: white;
}

.terminal-routes-method-badge.method-patch {
	background: #8b5cf6;
	color: white;
}

.terminal-routes-method-badge.method-delete {
	background: #ef4444;
	color: white;
}

.terminal-routes-uri {
	font-family: var(--terminal-font-family, monospace);
	font-size: var(--terminal-font-size-xs, 11px);
}

.terminal-routes-name {
	font-family: var(--terminal-font-family, monospace);
	font-size: var(--terminal-font-size-xs, 11px);
}

.terminal-routes-action {
	font-family: var(--terminal-font-family, monospace);
	font-size: var(--terminal-font-size-xs, 11px);
}

.terminal-routes-middleware-count {
	color: var(--terminal-text-secondary);
	font-size: var(--terminal-font-size-xs, 11px);
}

/* Modal */
.terminal-routes-modal {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: var(--terminal-overlay, rgba(0, 0, 0, 0.7));
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.terminal-routes-modal-content {
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	width: 90%;
	max-width: 800px;
	max-height: 90vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-routes-modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-routes-modal-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-lg, 16px);
}

.terminal-routes-modal-body {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
}

.terminal-routes-detail-section {
	margin-bottom: 24px;
}

.terminal-routes-detail-section h4 {
	margin: 0 0 8px 0;
	font-size: var(--terminal-font-size-md, 14px);
	font-weight: 600;
	color: var(--terminal-text);
}

.terminal-routes-section-desc {
	margin: 0 0 12px 0;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary);
	line-height: var(--terminal-line-height, 1.5);
}

.terminal-routes-detail-info {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-routes-info-item {
	display: flex;
	gap: 12px;
}

.terminal-routes-info-label {
	font-weight: 600;
	min-width: 100px;
	color: var(--terminal-text-secondary);
}

.terminal-routes-info-value {
	font-family: var(--terminal-font-family, monospace);
	color: var(--terminal-text);
}

.terminal-routes-parameters {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-routes-parameter {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
}

.terminal-routes-param-name {
	font-weight: 600;
	font-family: var(--terminal-font-family, monospace);
}

.terminal-routes-param-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-xs, 10px);
	font-weight: 600;
}

.terminal-routes-param-badge.required {
	background: #ef4444;
	color: white;
}

.terminal-routes-param-badge.optional {
	background: #10b981;
	color: white;
}

.terminal-routes-param-pattern,
.terminal-routes-param-default {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary);
	font-family: var(--terminal-font-family, monospace);
}

.terminal-routes-cross-ref-group {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.terminal-routes-cross-ref-label {
	font-weight: 600;
	min-width: 100px;
	color: var(--terminal-text-secondary);
}

.terminal-routes-url-generator,
.terminal-routes-tester {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border-radius: 6px;
	border: 1px solid var(--terminal-border);
}

.terminal-routes-params-input {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-top: 8px;
}

.terminal-routes-param-input {
	display: flex;
	align-items: center;
	gap: 10px;
}

.terminal-routes-param-input {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.terminal-routes-param-input label {
	display: flex;
	align-items: center;
	gap: 8px;
	font-weight: 600;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text);
	flex-shrink: 0;
}

.terminal-routes-param-label-name {
	font-family: var(--terminal-font-family, monospace);
}

.terminal-routes-param-hint {
	font-size: var(--terminal-font-size-xs, 10px);
	color: var(--terminal-text-muted);
	font-family: var(--terminal-font-family, monospace);
	margin-top: -4px;
	padding-left: 4px;
}

.terminal-routes-params-header {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 4px;
}

.terminal-routes-params-title {
	font-weight: 600;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text);
}

.terminal-routes-params-subtitle {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary);
}

.terminal-routes-no-params {
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
	text-align: center;
}

.terminal-routes-no-params p {
	margin: 0;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary);
}

.terminal-routes-url-input-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.terminal-routes-url-label {
	font-weight: 600;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text);
}

.terminal-routes-url-generator .terminal-input,
.terminal-routes-tester .terminal-input {
	width: 100%;
	padding: 8px 12px !important;
	font-size: var(--terminal-font-size-sm, 12px) !important;
}

.terminal-routes-generate-btn,
.terminal-routes-test-btn {
	padding: 10px 16px !important;
	font-size: var(--terminal-font-size-md, 13px) !important;
	font-weight: 600 !important;
	margin-top: 4px;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	gap: 8px !important;
	color: white !important;
}

.terminal-routes-generate-btn svg,
.terminal-routes-test-btn svg {
	width: 16px;
	height: 16px;
	flex-shrink: 0;
}

.spinner-sm {
	width: 14px;
	height: 14px;
	border-width: 2px;
}

/* Input styling for dark mode */
.terminal-routes .terminal-input {
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
}

.terminal-routes .terminal-input:focus {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-primary) !important;
	outline: none !important;
}

.terminal-routes .terminal-input::placeholder {
	color: var(--terminal-text-muted) !important;
}

.terminal-routes .terminal-input-sm {
	font-size: var(--terminal-font-size-xs, 11px);
	padding: 4px 8px !important;
}

/* Button styling for dark mode */
.terminal-routes .terminal-btn {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	color: var(--terminal-text);
	transition: all 0.2s;
}

.terminal-routes .terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg);
	border-color: var(--terminal-primary);
	color: var(--terminal-text);
}

.terminal-routes .terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-routes .terminal-btn-primary {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: var(--terminal-bg);
}

.terminal-routes .terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary);
	opacity: 0.9;
}

.terminal-routes .terminal-btn-secondary {
	background: var(--terminal-bg-secondary);
	border-color: var(--terminal-border);
	color: var(--terminal-text);
}

.terminal-routes .terminal-btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg);
	border-color: var(--terminal-primary);
}

.terminal-routes .terminal-btn-close {
	background: transparent;
	border: none;
	color: var(--terminal-text) !important;
	opacity: 0.8;
	transition: opacity 0.2s, background 0.2s;
}

.terminal-routes .terminal-btn-close:hover {
	color: var(--terminal-text) !important;
	background: var(--terminal-bg-secondary);
	opacity: 1;
}

.terminal-routes-modal-header .terminal-btn-close {
	background: transparent !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
	opacity: 1 !important;
	padding: 6px !important;
	min-width: 28px !important;
	min-height: 28px !important;
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
}

.terminal-routes-modal-header .terminal-btn-close svg {
	stroke: var(--terminal-text) !important;
	width: 16px !important;
	height: 16px !important;
}

.terminal-routes-modal-header .terminal-btn-close:hover {
	color: var(--terminal-text) !important;
	background: var(--terminal-bg-secondary) !important;
	border-color: var(--terminal-primary) !important;
	opacity: 1 !important;
}

.terminal-routes .terminal-btn-sm {
	padding: 4px 8px;
	font-size: var(--terminal-font-size-xs, 11px);
}
</style>

