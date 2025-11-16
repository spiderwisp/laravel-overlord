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

const emit = defineEmits(['close']);

const loading = ref(false);
const controllers = ref([]);
const searchQuery = ref('');
const expandedControllers = ref(new Set());
const selectedController = ref(null);

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

// Group controllers by namespace
const groupedControllers = computed(() => {
	const groups = {};
	filteredControllers.value.forEach(controller => {
		const namespace = controller.namespace || 'App\\Http\\Controllers';
		if (!groups[namespace]) {
			groups[namespace] = [];
		}
		groups[namespace].push(controller);
	});
	return groups;
});

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue && controllers.value.length === 0) {
		loadControllers();
	}
});

onMounted(() => {
	if (props.visible) {
		loadControllers();
	}
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
					<div class="terminal-controllers-list-scroll">
						<div v-for="(groupControllers, namespace) in groupedControllers" :key="namespace" class="terminal-controllers-group">
							<div class="terminal-controllers-group-header">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
								</svg>
								<span class="terminal-controllers-group-name">{{ namespace }}</span>
								<span class="terminal-controllers-group-count">({{ groupControllers.length }})</span>
							</div>
							<div
								v-for="controller in groupControllers"
								:key="controller.fullName"
								class="terminal-controllers-item"
								:class="{ 'active': selectedController?.fullName === controller.fullName }"
								@click="selectController(controller)"
							>
								<div class="terminal-controllers-item-header">
									<div class="terminal-controllers-item-indent">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
										</svg>
									</div>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
									</svg>
									<span class="terminal-controllers-item-name">{{ controller.name }}</span>
									<span class="terminal-controllers-item-methods-count">{{ controller.methods.length }} methods</span>
								</div>
							</div>
						</div>
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
								<span class="terminal-controllers-info-value">{{ selectedController.namespace }}</span>
							</div>
							<div class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">Full Name:</span>
								<span class="terminal-controllers-info-value">{{ selectedController.fullName }}</span>
							</div>
							<div class="terminal-controllers-info-item">
								<span class="terminal-controllers-info-label">Methods:</span>
								<span class="terminal-controllers-info-value">{{ selectedController.methods.length }}</span>
							</div>
						</div>
						<div class="terminal-controllers-methods">
							<h4>Methods</h4>
							<div class="terminal-controllers-methods-list">
								<div
									v-for="method in selectedController.methods"
									:key="method.name"
									class="terminal-controllers-method"
								>
									<div class="terminal-controllers-method-name">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
										</svg>
										<span>{{ method.name }}</span>
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
	transition: background 0.2s;
}

.terminal-controllers-item:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-controllers-item.active {
	background: var(--terminal-bg-tertiary);
}

.terminal-controllers-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-left: 20px;
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
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-controllers-item-name {
	flex: 1;
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 500;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-controllers-item-methods-count {
	color: var(--terminal-text-muted);
	font-size: 10px;
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
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
	flex-shrink: 0;
	color: var(--terminal-text-secondary);
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
</style>

