<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
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
const classes = ref([]);
const searchQuery = ref('');
const selectedClass = ref(null);
const activeTab = ref('list'); // 'list' or 'diagram'

// Load classes
async function loadClasses() {
	if (loading.value) return;
	
	loading.value = true;
	try {
		const response = await axios.get(api.url('classes'));
		if (response.data && response.data.success && response.data.result) {
			classes.value = response.data.result.classes || [];
		}
	} catch (error) {
		// Silently handle errors
	} finally {
		loading.value = false;
	}
}

// Select class
function selectClass(cls) {
	selectedClass.value = cls;
}

// Filter classes
const filteredClasses = computed(() => {
	if (!searchQuery.value.trim()) {
		return classes.value;
	}
	
	const query = searchQuery.value.toLowerCase();
	return classes.value.filter(cls => {
		return cls.name.toLowerCase().includes(query) ||
			cls.fullName.toLowerCase().includes(query) ||
			cls.namespace.toLowerCase().includes(query) ||
			cls.type.toLowerCase().includes(query) ||
			cls.methods.some(m => m.name.toLowerCase().includes(query)) ||
			cls.properties.some(p => p.name.toLowerCase().includes(query));
	});
});

// Group classes by type
const groupedClasses = computed(() => {
	const groups = {};
	filteredClasses.value.forEach(cls => {
		const type = cls.type || 'Class';
		if (!groups[type]) {
			groups[type] = [];
		}
		groups[type].push(cls);
	});
	return groups;
});

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue && classes.value.length === 0) {
		loadClasses();
	}
});

onMounted(() => {
	if (props.visible) {
		loadClasses();
	}
});
</script>

<template>
	<div v-if="visible" class="terminal-classes">
		<div class="terminal-classes-header">
			<div class="terminal-classes-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
				</svg>
				<span>Classes</span>
			</div>
			<div class="terminal-classes-controls">
				<input
					v-model="searchQuery"
					type="text"
					placeholder="Search classes..."
					class="terminal-input terminal-classes-search"
				/>
				<button
					@click="loadClasses"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loading"
					title="Reload classes"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Classes"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Tabs -->
		<div class="terminal-classes-tabs">
			<button
				@click="activeTab = 'list'"
				class="terminal-classes-tab"
				:class="{ 'active': activeTab === 'list' }"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
				</svg>
				<span>List View</span>
			</button>
			<button
				@click="activeTab = 'diagram'"
				class="terminal-classes-tab"
				:class="{ 'active': activeTab === 'diagram' }"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
				</svg>
				<span>Diagram View</span>
			</button>
		</div>

		<div class="terminal-classes-content">
			<!-- Loading State -->
			<div v-if="loading" class="terminal-classes-loading">
				<span class="spinner"></span>
				Analyzing classes...
			</div>

			<!-- Empty State -->
			<div v-else-if="classes.length === 0" class="terminal-classes-empty">
				<p>No classes found.</p>
			</div>

			<!-- List View Tab -->
			<div v-else-if="activeTab === 'list'" class="terminal-classes-main">
				<!-- Classes List -->
				<div class="terminal-classes-list">
					<div class="terminal-classes-list-header">
						<h3>Classes ({{ filteredClasses.length }})</h3>
					</div>
					<div class="terminal-classes-list-scroll">
						<div v-for="(typeClasses, type) in groupedClasses" :key="type" class="terminal-classes-group">
							<div class="terminal-classes-group-header">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
								</svg>
								<span class="terminal-classes-group-name">{{ type }}</span>
								<span class="terminal-classes-group-count">({{ typeClasses.length }})</span>
							</div>
							<div
								v-for="cls in typeClasses"
								:key="cls.fullName"
								class="terminal-classes-item"
								:class="{ 'active': selectedClass?.fullName === cls.fullName }"
								@click="selectClass(cls)"
							>
								<div class="terminal-classes-item-header">
									<div class="terminal-classes-item-indent">
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
										</svg>
									</div>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
									</svg>
									<span class="terminal-classes-item-name">{{ cls.name }}</span>
									<span class="terminal-classes-item-type-badge" :class="'type-' + cls.type.toLowerCase()">{{ cls.type }}</span>
									<span class="terminal-classes-item-counts">{{ cls.methods.length }}m / {{ cls.properties.length }}p</span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Class Details -->
				<div class="terminal-classes-details">
				<div v-if="!selectedClass" class="terminal-classes-empty-details">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
						<path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
					</svg>
					<h3>Select a Class</h3>
					<p>Choose a class from the list to view its details</p>
				</div>
					<div v-else class="terminal-classes-details-content">
						<div class="terminal-classes-details-header">
							<div>
								<h3>{{ selectedClass.name }}</h3>
								<span class="terminal-classes-item-type-badge" :class="'type-' + selectedClass.type.toLowerCase()">{{ selectedClass.type }}</span>
							</div>
							<button
								@click="selectedClass = null"
								class="terminal-btn terminal-btn-close terminal-btn-sm"
								title="Clear Selection"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</button>
						</div>

						<!-- Basic Info -->
						<div class="terminal-classes-details-section">
							<h4>Basic Information</h4>
							<div class="terminal-classes-details-info">
								<div class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">Namespace:</span>
									<span class="terminal-classes-info-value">{{ selectedClass.namespace }}</span>
								</div>
								<div class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">Full Name:</span>
									<span class="terminal-classes-info-value">{{ selectedClass.fullName }}</span>
								</div>
								<div v-if="selectedClass.filePath" class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">File:</span>
									<span class="terminal-classes-info-value">{{ selectedClass.filePath }}:{{ selectedClass.startLine }}-{{ selectedClass.endLine }}</span>
								</div>
								<div v-if="selectedClass.isAbstract" class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">Abstract:</span>
									<span class="terminal-classes-info-value">Yes</span>
								</div>
								<div v-if="selectedClass.isFinal" class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">Final:</span>
									<span class="terminal-classes-info-value">Yes</span>
								</div>
								<div v-if="selectedClass.isTrait" class="terminal-classes-info-item">
									<span class="terminal-classes-info-label">Trait:</span>
									<span class="terminal-classes-info-value">Yes</span>
								</div>
							</div>
						</div>

						<!-- Hierarchy -->
						<div v-if="selectedClass.parentChain.length > 0" class="terminal-classes-details-section">
							<h4>Inheritance Hierarchy</h4>
							<div class="terminal-classes-hierarchy">
								<div
									v-for="(parent, index) in [...selectedClass.parentChain].reverse()"
									:key="parent"
									class="terminal-classes-hierarchy-item"
								>
									<span v-if="index > 0" class="terminal-classes-hierarchy-arrow">↓</span>
									<span class="terminal-classes-hierarchy-class">{{ parent }}</span>
								</div>
								<div class="terminal-classes-hierarchy-item">
									<span class="terminal-classes-hierarchy-arrow">↓</span>
									<span class="terminal-classes-hierarchy-class current">{{ selectedClass.fullName }}</span>
								</div>
							</div>
						</div>

						<!-- Traits -->
						<div v-if="selectedClass.traits.length > 0" class="terminal-classes-details-section">
							<h4>Traits Used</h4>
							<div class="terminal-classes-traits">
								<span
									v-for="trait in selectedClass.traits"
									:key="trait"
									class="terminal-classes-trait-badge"
								>
									{{ trait }}
								</span>
							</div>
						</div>

						<!-- Interfaces -->
						<div v-if="selectedClass.interfaces.length > 0" class="terminal-classes-details-section">
							<h4>Interfaces Implemented</h4>
							<div class="terminal-classes-interfaces">
								<span
									v-for="iface in selectedClass.interfaces"
									:key="iface"
									class="terminal-classes-interface-badge"
								>
									{{ iface }}
								</span>
							</div>
						</div>

						<!-- Dependencies -->
						<div v-if="selectedClass.dependencies.length > 0" class="terminal-classes-details-section">
							<h4>Dependencies</h4>
							<div class="terminal-classes-dependencies">
								<span
									v-for="dep in selectedClass.dependencies"
									:key="dep"
									class="terminal-classes-dependency-badge"
								>
									{{ dep }}
								</span>
							</div>
						</div>

						<!-- Docblock -->
						<div v-if="selectedClass.docblock" class="terminal-classes-details-section">
							<h4>Documentation</h4>
							<div class="terminal-classes-docblock">
								<pre>{{ selectedClass.docblock }}</pre>
							</div>
						</div>

						<!-- Properties -->
						<div v-if="selectedClass.properties.length > 0" class="terminal-classes-details-section">
							<h4>Properties ({{ selectedClass.properties.length }})</h4>
							<div class="terminal-classes-properties">
								<div
									v-for="prop in selectedClass.properties"
									:key="prop.name"
									class="terminal-classes-property"
								>
									<div class="terminal-classes-property-header">
										<span class="terminal-classes-visibility-badge" :class="'visibility-' + prop.visibility">{{ prop.visibility }}</span>
										<span v-if="prop.static" class="terminal-classes-static-badge">static</span>
										<span v-if="prop.type" class="terminal-classes-type">{{ prop.type }}</span>
										<span class="terminal-classes-property-name">${{ prop.name }}</span>
									</div>
									<div v-if="prop.defaultValue" class="terminal-classes-property-default">
										Default: <code>{{ prop.defaultValue }}</code>
									</div>
									<div v-if="prop.docblock" class="terminal-classes-property-docblock">
										<pre>{{ prop.docblock }}</pre>
									</div>
								</div>
							</div>
						</div>

						<!-- Constants -->
						<div v-if="selectedClass.constants.length > 0" class="terminal-classes-details-section">
							<h4>Constants ({{ selectedClass.constants.length }})</h4>
							<div class="terminal-classes-constants">
								<div
									v-for="constantItem in selectedClass.constants"
									:key="constantItem.name"
									class="terminal-classes-constant"
								>
									<span class="terminal-classes-constant-name">{{ constantItem.name }}</span>
									<span class="terminal-classes-constant-value"><code>{{ constantItem.value }}</code></span>
								</div>
							</div>
						</div>

						<!-- Methods -->
						<div class="terminal-classes-details-section">
							<h4>Methods ({{ selectedClass.methods.length }})</h4>
							<div class="terminal-classes-methods">
								<div
									v-for="method in selectedClass.methods"
									:key="method.name"
									class="terminal-classes-method"
								>
									<div class="terminal-classes-method-header">
										<span class="terminal-classes-visibility-badge" :class="'visibility-' + method.visibility">{{ method.visibility }}</span>
										<span v-if="method.static" class="terminal-classes-static-badge">static</span>
										<span v-if="method.abstract" class="terminal-classes-abstract-badge">abstract</span>
										<span v-if="method.final" class="terminal-classes-final-badge">final</span>
										<span v-if="method.returnType" class="terminal-classes-type">{{ method.returnType }}</span>
										<span class="terminal-classes-method-name">{{ method.name }}</span>
										<span class="terminal-classes-method-params">({{ method.parameters.length }})</span>
									</div>
									<div v-if="method.parameters.length > 0" class="terminal-classes-method-params-list">
										<div
											v-for="(param, index) in method.parameters"
											:key="param.name"
											class="terminal-classes-method-param"
										>
											<span v-if="param.type" class="terminal-classes-param-type">{{ param.type }}</span>
											<span class="terminal-classes-param-name">${{ param.name }}</span>
											<span v-if="param.hasDefault" class="terminal-classes-param-default">= {{ param.defaultValue }}</span>
											<span v-if="index < method.parameters.length - 1">, </span>
										</div>
									</div>
									<div v-if="method.docblock" class="terminal-classes-method-docblock">
										<pre>{{ method.docblock }}</pre>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Diagram View Tab -->
			<div v-else-if="activeTab === 'diagram'" class="terminal-classes-diagram">
				<div class="terminal-classes-diagram-placeholder">
					<p>Diagram view coming soon...</p>
					<p class="terminal-classes-diagram-note">This will show class relationships, inheritance, traits, and dependencies in a visual graph.</p>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-classes {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
}

.terminal-classes-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-classes-title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-text);
	font-weight: 600;
	font-size: 14px;
}

.terminal-classes-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-classes-search {
	width: 250px;
	font-size: 12px;
	padding: 6px 12px !important;
	background: var(--terminal-bg) !important;
	border: 1px solid var(--terminal-border) !important;
	color: var(--terminal-text) !important;
}

.terminal-classes-search:focus {
	background: var(--terminal-bg) !important;
	border-color: var(--terminal-primary) !important;
	outline: none !important;
}

.terminal-classes-search::placeholder {
	color: var(--terminal-text-muted) !important;
}

.terminal-classes-tabs {
	display: flex;
	gap: 4px;
	padding: 8px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-classes-tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 16px;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--terminal-text-secondary);
	font-size: 12px;
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-classes-tab:hover {
	color: var(--terminal-text);
	background: var(--terminal-bg-tertiary);
}

.terminal-classes-tab.active {
	color: var(--terminal-primary);
	border-bottom-color: var(--terminal-primary);
	background: var(--terminal-bg);
}

.terminal-classes-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-classes-loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 40px;
	color: var(--terminal-text-secondary);
}

.terminal-classes-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--terminal-text-secondary);
}

.terminal-classes-main {
	flex: 1;
	display: flex;
	overflow: hidden;
}

.terminal-classes-list {
	width: 450px;
	min-width: 450px;
	background: var(--terminal-bg-secondary);
	border-right: 1px solid var(--terminal-border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
	height: 100%;
}

.terminal-classes-list-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-classes-list-header h3 {
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 600;
	margin: 0;
}

.terminal-classes-list-scroll {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px;
	min-height: 0;
}

.terminal-classes-group {
	margin-bottom: 16px;
}

.terminal-classes-group-header {
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

.terminal-classes-group-header svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

.terminal-classes-group-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-classes-group-count {
	color: var(--terminal-text-muted);
	font-weight: normal;
}

.terminal-classes-item {
	padding: 8px 12px;
	margin-bottom: 2px;
	border-radius: 4px;
	cursor: pointer;
	transition: background 0.2s;
}

.terminal-classes-item:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-classes-item.active {
	background: var(--terminal-bg-tertiary);
}

.terminal-classes-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding-left: 20px;
}

.terminal-classes-item-indent {
	display: flex;
	align-items: center;
	margin-left: -20px;
	color: var(--terminal-text-muted);
	flex-shrink: 0;
}

.terminal-classes-item-indent svg {
	width: 12px;
	height: 12px;
}

.terminal-classes-item-header svg {
	flex-shrink: 0;
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-classes-item-name {
	flex: 1;
	color: var(--terminal-text);
	font-size: 12px;
	font-weight: 500;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-classes-item-type-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 9px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
}

.terminal-classes-item-type-badge.type-service {
	background: var(--terminal-primary);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-helper {
	background: var(--terminal-success);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-trait {
	background: var(--terminal-warning);
	color: #1f2937;
}

.terminal-classes-item-type-badge.type-job {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-mail {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-provider {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-exception {
	background: var(--terminal-error);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-command {
	background: var(--terminal-primary);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-middleware {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-request {
	background: var(--terminal-warning);
	color: #1f2937;
}

.terminal-classes-item-type-badge.type-observer {
	background: var(--terminal-success);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-extension {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-class {
	background: var(--terminal-text-muted);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-model {
	background: var(--terminal-accent);
	color: var(--terminal-text);
}

.terminal-classes-item-type-badge.type-controller {
	background: var(--terminal-primary);
	color: var(--terminal-text);
}

.terminal-classes-item-counts {
	color: var(--terminal-text-muted);
	font-size: 10px;
}

.terminal-classes-details {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-classes-empty-details {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 16px;
	color: var(--terminal-text-secondary);
}

.terminal-classes-empty-details h3 {
	color: var(--terminal-text);
	font-size: 16px;
	margin: 0;
}

.terminal-classes-empty-details p {
	font-size: 12px;
	margin: 0;
}

.terminal-classes-empty-details svg {
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
	flex-shrink: 0;
	color: var(--terminal-text-secondary);
}

.terminal-classes-details-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 20px;
	min-height: 0;
	padding-bottom: 72px;
}

.terminal-classes-details-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 20px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-classes-details-header h3 {
	color: var(--terminal-text);
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 8px 0;
}

.terminal-classes-details-section {
	margin-bottom: 24px;
}

.terminal-classes-details-section h4 {
	color: var(--terminal-text);
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 12px 0;
}

.terminal-classes-details-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-classes-info-item {
	display: flex;
	gap: 12px;
}

.terminal-classes-info-label {
	color: var(--terminal-text-secondary);
	font-size: 12px;
	font-weight: 500;
	min-width: 100px;
}

.terminal-classes-info-value {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
}

.terminal-classes-hierarchy {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-classes-hierarchy-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-classes-hierarchy-arrow {
	color: var(--terminal-text-muted);
	font-size: 14px;
}

.terminal-classes-hierarchy-class {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
}

.terminal-classes-hierarchy-class.current {
	color: var(--terminal-accent);
	font-weight: 600;
}

.terminal-classes-traits,
.terminal-classes-interfaces,
.terminal-classes-dependencies {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-classes-trait-badge,
.terminal-classes-interface-badge,
.terminal-classes-dependency-badge {
	padding: 4px 8px;
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 3px;
	color: var(--terminal-text);
	font-size: 11px;
	font-family: 'Courier New', monospace;
}

.terminal-classes-docblock {
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-classes-docblock pre {
	margin: 0;
	color: var(--terminal-text-secondary);
	font-size: 11px;
	font-family: 'Courier New', monospace;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-classes-properties {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-classes-property {
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
}

.terminal-classes-property-header {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.terminal-classes-property-name {
	color: var(--terminal-accent);
	font-size: 13px;
	font-weight: 600;
	font-family: 'Courier New', monospace;
}

.terminal-classes-property-default {
	color: var(--terminal-text-secondary);
	font-size: 11px;
	margin-top: 4px;
}

.terminal-classes-property-default code {
	color: var(--terminal-warning);
	font-family: 'Courier New', monospace;
}

.terminal-classes-property-docblock {
	margin-top: 8px;
}

.terminal-classes-property-docblock pre {
	margin: 0;
	color: var(--terminal-text-secondary);
	font-size: 11px;
	font-family: 'Courier New', monospace;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-classes-constants {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--terminal-bg-secondary);
	border-radius: 4px;
	border: 1px solid var(--terminal-border);
}

.terminal-classes-constant {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-classes-constant:last-child {
	border-bottom: none;
}

.terminal-classes-constant-name {
	color: var(--terminal-accent);
	font-size: 12px;
	font-weight: 600;
	font-family: 'Courier New', monospace;
}

.terminal-classes-constant-value {
	color: var(--terminal-text);
	font-size: 12px;
	font-family: 'Courier New', monospace;
}

.terminal-classes-constant-value code {
	color: var(--terminal-warning);
}

.terminal-classes-methods {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 8px;
	overflow-y: auto;
	overflow-x: hidden;
	padding-right: 8px;
	padding-bottom: 72px;
	min-height: 0;
}

.terminal-classes-method {
	padding: 12px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
}

.terminal-classes-method-header {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 8px;
}

.terminal-classes-method-name {
	color: var(--terminal-accent);
	font-size: 13px;
	font-weight: 600;
	font-family: 'Courier New', monospace;
}

.terminal-classes-method-params {
	color: var(--terminal-text-secondary);
	font-size: 11px;
}

.terminal-classes-method-params-list {
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

.terminal-classes-method-param {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.terminal-classes-method-docblock {
	margin-top: 8px;
}

.terminal-classes-method-docblock pre {
	margin: 0;
	color: var(--terminal-text-secondary);
	font-size: 11px;
	font-family: 'Courier New', monospace;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-classes-visibility-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 9px;
	font-weight: 600;
	text-transform: uppercase;
}

.terminal-classes-visibility-badge.visibility-public {
	background: var(--terminal-success);
	color: var(--terminal-text);
}

.terminal-classes-visibility-badge.visibility-protected {
	background: var(--terminal-warning);
	color: #1f2937;
}

.terminal-classes-visibility-badge.visibility-private {
	background: var(--terminal-error);
	color: var(--terminal-text);
}

.terminal-classes-static-badge,
.terminal-classes-abstract-badge,
.terminal-classes-final-badge {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 9px;
	font-weight: 600;
	text-transform: uppercase;
	background: var(--terminal-text-muted);
	color: var(--terminal-text);
}

.terminal-classes-type {
	color: var(--terminal-warning);
	font-size: 11px;
	font-family: 'Courier New', monospace;
}

.terminal-classes-param-type {
	color: var(--terminal-warning);
	font-family: 'Courier New', monospace;
}

.terminal-classes-param-name {
	color: var(--terminal-text);
	font-family: 'Courier New', monospace;
}

.terminal-classes-param-default {
	color: var(--terminal-text-muted);
	font-size: 10px;
	font-style: italic;
}

.terminal-classes-diagram {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
}

.terminal-classes-diagram-placeholder {
	text-align: center;
	color: var(--terminal-text-secondary);
}

.terminal-classes-diagram-note {
	font-size: 12px;
	margin-top: 8px;
}

/* Scrollbar styling */
.terminal-classes-list-scroll,
.terminal-classes-methods,
.terminal-classes-details-content {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border) var(--terminal-bg);
}

.terminal-classes-list-scroll::-webkit-scrollbar,
.terminal-classes-methods::-webkit-scrollbar,
.terminal-classes-details-content::-webkit-scrollbar {
	width: 10px;
}

.terminal-classes-list-scroll::-webkit-scrollbar-track,
.terminal-classes-methods::-webkit-scrollbar-track,
.terminal-classes-details-content::-webkit-scrollbar-track {
	background: var(--terminal-bg);
	border-radius: 5px;
}

.terminal-classes-list-scroll::-webkit-scrollbar-thumb,
.terminal-classes-methods::-webkit-scrollbar-thumb,
.terminal-classes-details-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg);
}

.terminal-classes-list-scroll::-webkit-scrollbar-thumb:hover,
.terminal-classes-methods::-webkit-scrollbar-thumb:hover,
.terminal-classes-details-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-bg-tertiary);
}
</style>

