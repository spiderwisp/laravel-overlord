<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
	collapsed: {
		type: Boolean,
		default: false,
	},
	navigationConfig: {
		type: Array,
		required: true,
	},
});

const emit = defineEmits(['update:collapsed', 'toggle-section']);

// Navigation search state
const navSearchQuery = ref('');
const navSearchInputRef = ref(null);

// Navigation sections expanded/collapsed state
const navSectionsExpanded = ref({});

// Initialize navigation sections state from localStorage
function initializeNavSectionsState() {
	try {
		const saved = localStorage.getItem('overlord_nav_sections_state');
		// Initialize with defaults from navigationConfig
		const defaults = {};
		props.navigationConfig.forEach(section => {
			defaults[section.id] = section.defaultExpanded !== false;
		});
		
		if (saved) {
			const parsed = JSON.parse(saved);
			// Merge saved state with defaults (saved takes precedence)
			navSectionsExpanded.value = {
				...defaults,
				...parsed
			};
		} else {
			navSectionsExpanded.value = defaults;
		}
	} catch (e) {
		console.error('Failed to load nav sections state:', e);
		// Fallback to defaults from navigationConfig
		navSectionsExpanded.value = {};
		props.navigationConfig.forEach(section => {
			navSectionsExpanded.value[section.id] = section.defaultExpanded !== false;
		});
	}
}

// Save navigation sections state to localStorage
function saveNavSectionsState() {
	try {
		localStorage.setItem('overlord_nav_sections_state', JSON.stringify(navSectionsExpanded.value));
	} catch (e) {
		console.error('Failed to save nav sections state:', e);
	}
}

// Toggle section expanded state
function toggleNavSection(sectionId) {
	navSectionsExpanded.value[sectionId] = !navSectionsExpanded.value[sectionId];
	saveNavSectionsState();
	emit('toggle-section', sectionId);
}

// Filter navigation config based on search
const filteredNavigationConfig = computed(() => {
	if (!navSearchQuery.value.trim()) {
		return props.navigationConfig;
	}

	const query = navSearchQuery.value.toLowerCase().trim();
	return props.navigationConfig.map(section => {
		const filteredItems = section.items.filter(item => {
			// Check label
			if (item.label.toLowerCase().includes(query)) return true;
			// Check keywords
			if (item.keywords && item.keywords.some(kw => kw.toLowerCase().includes(query))) return true;
			return false;
		});
		return { ...section, items: filteredItems };
	}).filter(section => section.items.length > 0);
});

// Get filtered navigation items for keyboard navigation
const filteredNavItems = computed(() => {
	const items = [];
	filteredNavigationConfig.value.forEach(section => {
		const isExpanded = !section.title || (navSectionsExpanded.value[section.id] !== undefined ? navSectionsExpanded.value[section.id] : section.defaultExpanded !== false);
		if (isExpanded) {
			section.items.forEach(item => {
				const isDisabled = item.disabled && (typeof item.disabled === 'function' ? item.disabled() : item.disabled);
				if (!isDisabled) {
					items.push({ ...item, sectionId: section.id });
				}
			});
		}
	});
	return items;
});

// Keyboard navigation state
const focusedNavItemIndex = ref(-1);

// Handle keyboard navigation
function handleNavKeyboard(event) {
	if (props.collapsed) return;

	const items = filteredNavItems.value;
	if (items.length === 0) return;

	switch (event.key) {
		case 'ArrowDown':
			event.preventDefault();
			focusedNavItemIndex.value = Math.min(focusedNavItemIndex.value + 1, items.length - 1);
			break;
		case 'ArrowUp':
			event.preventDefault();
			focusedNavItemIndex.value = Math.max(focusedNavItemIndex.value - 1, 0);
			break;
		case 'Enter':
		case ' ':
			if (focusedNavItemIndex.value >= 0 && focusedNavItemIndex.value < items.length) {
				event.preventDefault();
				const item = items[focusedNavItemIndex.value];
				if (item.action) {
					item.action();
				}
			}
			break;
		case 'Escape':
			if (navSearchQuery.value) {
				event.preventDefault();
				clearNavSearch();
			}
			break;
	}
}

// Clear search
function clearNavSearch() {
	navSearchQuery.value = '';
	focusedNavItemIndex.value = -1;
	if (navSearchInputRef.value) {
		navSearchInputRef.value.focus();
	}
}

// Toggle sidebar
function toggleSidebar() {
	emit('update:collapsed', !props.collapsed);
}

onMounted(() => {
	initializeNavSectionsState();
});
</script>

<template>
	<aside class="terminal-sidebar" :class="{ 'collapsed': collapsed }">
		<div class="terminal-sidebar-header">
			<div v-if="!collapsed" class="terminal-sidebar-branding">
				<div class="terminal-sidebar-title-wrapper">
					<span class="terminal-beta-badge-sidebar">BETA</span>
					<span class="terminal-sidebar-title-laravel">Laravel</span>
					<span class="terminal-sidebar-title-overlord">Overlord</span>
				</div>
			</div>
			<div v-if="!collapsed" class="terminal-sidebar-nav-label">Navigation</div>
			<button
				@click="toggleSidebar"
				class="terminal-sidebar-toggle"
				:title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path v-if="!collapsed" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
					<path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
				</svg>
			</button>
		</div>
		
		<nav class="terminal-sidebar-nav" @keydown="handleNavKeyboard">
			<!-- Search Input -->
			<div v-if="!collapsed" class="terminal-nav-search">
				<div class="terminal-nav-search-wrapper">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="terminal-nav-search-icon">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
					</svg>
					<input
						ref="navSearchInputRef"
						v-model="navSearchQuery"
						type="text"
						class="terminal-nav-search-input"
						placeholder="Search navigation..."
						@keydown.escape="clearNavSearch"
					/>
					<button
						v-if="navSearchQuery"
						@click="clearNavSearch"
						class="terminal-nav-search-clear"
						title="Clear search"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>
			</div>
			
			<!-- Navigation Sections -->
			<template v-if="filteredNavigationConfig && filteredNavigationConfig.length > 0">
				<div
					v-for="section in filteredNavigationConfig"
					:key="section.id"
					class="terminal-nav-section"
					:class="{
						'nav-section-primary': section.priority === 'primary',
						'nav-section-secondary': section.priority === 'secondary',
						'nav-section-tertiary': section.priority === 'tertiary'
					}"
				>
					<!-- Section Header (collapsible if has title) -->
					<div
						v-if="section.title"
						@click="toggleNavSection(section.id)"
						class="terminal-nav-section-header"
						:class="{ 'collapsed': !(navSectionsExpanded[section.id] !== undefined ? navSectionsExpanded[section.id] : section.defaultExpanded !== false) }"
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
							class="terminal-nav-section-chevron"
							:class="{ 'expanded': navSectionsExpanded[section.id] !== undefined ? navSectionsExpanded[section.id] : section.defaultExpanded !== false }"
						>
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
						</svg>
						<span v-if="!collapsed" class="terminal-nav-section-title">{{ section.title }}</span>
					</div>
					
					<!-- Section Items -->
					<transition name="nav-section">
						<div
							v-show="!section.title || (navSectionsExpanded[section.id] !== undefined ? navSectionsExpanded[section.id] : section.defaultExpanded !== false)"
							class="terminal-nav-section-items"
						>
							<button
								v-for="item in section.items"
								:key="item.id"
								@click="item.action && item.action()"
								class="terminal-nav-item"
								:class="{
									'active': item.isActive && item.isActive(),
									'disabled': item.disabled && (typeof item.disabled === 'function' ? item.disabled() : item.disabled),
									'nav-item-primary': item.priority === 'primary',
									'nav-item-secondary': item.priority === 'secondary',
									'nav-item-tertiary': item.priority === 'tertiary'
								}"
								:disabled="item.disabled && (typeof item.disabled === 'function' ? item.disabled() : item.disabled)"
								:title="collapsed ? item.label : ''"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
									<path v-if="item.icon2" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon2" />
								</svg>
								<span v-if="!collapsed" class="terminal-nav-item-label">{{ item.label }}</span>
								<span
									v-if="!collapsed && item.badge"
									class="terminal-nav-item-badge"
									:class="{
										'badge-error': item.badge && item.badge.color === 'red',
										'badge-warning': item.badge && (item.badge.color === 'orange' || item.badge.color === 'yellow'),
										'badge-info': item.badge && item.badge.color === 'blue'
									}"
								>
									{{ item.badge && item.badge.count ? item.badge.count : '' }}
								</span>
							</button>
						</div>
					</transition>
				</div>
			</template>
			
			<!-- No Results -->
			<div v-if="navSearchQuery && filteredNavigationConfig.every(s => s.items.length === 0)" class="terminal-nav-no-results">
				<p>No results found</p>
			</div>
		</nav>
	</aside>
</template>

<style scoped>
/* Sidebar Navigation */
.terminal-sidebar {
	width: 220px;
	background: var(--terminal-bg-secondary, #252526);
	border-right: 1px solid var(--terminal-border, #3e3e42);
	display: flex;
	flex-direction: column;
	flex-shrink: 0;
	transition: width 0.3s ease;
	overflow: hidden;
}

.terminal-sidebar.collapsed {
	width: 48px;
}

.terminal-sidebar-header {
	display: flex;
	flex-direction: column;
	padding: 12px 12px;
	padding-right: 40px; /* Space for toggle button */
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
	gap: 8px;
	position: relative;
}

.terminal-sidebar-branding {
	width: 100%;
	margin-bottom: 4px;
}

.terminal-sidebar-title-wrapper {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	text-align: left;
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
	font-weight: 700;
	line-height: 1.15;
	gap: 4px;
}

.terminal-sidebar-title-laravel {
	font-size: 24px;
	color: #1e3a5f; /* Dark blue/navy for light mode */
	letter-spacing: -0.02em;
}

/* Lighter blue for dark mode themes */
[data-terminal-theme="dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="high-contrast-dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="blue-dark"] .terminal-sidebar-title-laravel,
[data-terminal-theme="green-dark"] .terminal-sidebar-title-laravel {
	color: #4a7ba7; /* Lighter blue for dark backgrounds */
}

.terminal-sidebar-title-overlord {
	font-size: 24px;
	color: #ff6b35; /* Vibrant orange for light mode */
	letter-spacing: -0.02em;
}

/* Muted orange for dark mode themes */
[data-terminal-theme="dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="high-contrast-dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="blue-dark"] .terminal-sidebar-title-overlord,
[data-terminal-theme="green-dark"] .terminal-sidebar-title-overlord {
	color: #d45a1f; /* Muted/darker orange for dark backgrounds */
}

.terminal-beta-badge-sidebar {
	display: inline-block;
	padding: 2px 6px;
	background: var(--terminal-primary, #0e639c);
	color: white;
	font-size: 9px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	border-radius: 3px;
	line-height: 1.2;
	align-self: flex-start;
	margin-bottom: 2px;
}

.terminal-sidebar-nav-label {
	font-weight: 600;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-top: 0;
}

.terminal-sidebar-toggle {
	position: absolute;
	top: 12px;
	right: 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 4px;
	border-radius: 4px;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.terminal-sidebar-toggle:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-sidebar-toggle svg {
	width: 16px !important;
	height: 16px !important;
}

.terminal-sidebar-nav {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 8px 0;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg-secondary, #252526);
}

.terminal-sidebar-nav::-webkit-scrollbar {
	width: 6px;
}

.terminal-sidebar-nav::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-sidebar-nav::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 3px;
}

.terminal-sidebar-nav::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-nav-section {
	margin-bottom: 16px;
}

.terminal-nav-section-title {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 8px 12px 4px;
	font-weight: 600;
}

.terminal-nav-item {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 8px 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	text-align: left;
	position: relative;
}

.terminal-nav-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-nav-item.active {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-nav-item.active::before {
	content: '';
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 3px;
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-nav-item.disabled {
	opacity: 0.5;
	cursor: not-allowed;
	pointer-events: none;
}

.terminal-nav-item svg {
	width: 16px !important;
	height: 16px !important;
	flex-shrink: 0;
}

.terminal-nav-item span {
	flex: 1;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.terminal-sidebar.collapsed .terminal-nav-item {
	justify-content: center;
	padding: 8px;
}

.terminal-sidebar.collapsed .terminal-nav-item span {
	display: none;
}

/* Navigation Search */
.terminal-nav-search {
	padding: 8px 12px;
	margin-bottom: 8px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-nav-search-wrapper {
	position: relative;
	display: flex;
	align-items: center;
}

.terminal-nav-search-icon {
	position: absolute;
	left: 8px;
	width: 16px !important;
	height: 16px !important;
	color: var(--terminal-text-secondary, #858585);
	pointer-events: none;
	z-index: 1;
}

.terminal-nav-search-input {
	width: 100%;
	padding: 6px 32px 6px 32px;
	background: var(--terminal-bg-tertiary, #2d2d30);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	transition: all 0.2s;
}

.terminal-nav-search-input:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-nav-search-input::placeholder {
	color: var(--terminal-text-secondary, #858585);
}

.terminal-nav-search-clear {
	position: absolute;
	right: 6px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	padding: 4px;
	border-radius: 4px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s;
}

.terminal-nav-search-clear:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #ffffff);
}

.terminal-nav-search-clear svg {
	width: 14px !important;
	height: 14px !important;
}

/* Navigation Section Header */
.terminal-nav-section-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	user-select: none;
	transition: background 0.2s;
	border-radius: 4px;
	margin-bottom: 4px;
}

.terminal-nav-section-header:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-nav-section-chevron {
	width: 14px !important;
	height: 14px !important;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
	flex-shrink: 0;
}

.terminal-nav-section-chevron.expanded {
	transform: rotate(90deg);
}

.terminal-nav-section-items {
	overflow: hidden;
}

/* Navigation Section Transitions */
.nav-section-enter-active,
.nav-section-leave-active {
	transition: all 0.3s ease;
	max-height: 2000px;
}

.nav-section-enter-from,
.nav-section-leave-to {
	max-height: 0;
	opacity: 0;
	overflow: hidden;
}

/* Priority-based Navigation Styles */
.nav-section-primary {
	margin-bottom: 20px;
}

.nav-section-secondary {
	margin-bottom: 16px;
}

.nav-section-tertiary {
	margin-bottom: 12px;
}

.nav-item-primary {
	font-weight: 600;
	padding: 10px 12px;
}

.nav-item-primary svg {
	width: 18px !important;
	height: 18px !important;
}

.nav-item-secondary {
	font-weight: 500;
}

.nav-item-tertiary {
	font-weight: 400;
	opacity: 0.85;
}

.nav-item-tertiary:hover {
	opacity: 1;
}

/* Navigation Item Badge */
.terminal-nav-item-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 18px;
	height: 18px;
	padding: 0 6px;
	border-radius: 9px;
	font-size: 10px;
	font-weight: 600;
	margin-left: auto;
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-nav-item-badge.badge-error {
	background: var(--terminal-error, #f48771);
}

.terminal-nav-item-badge.badge-warning {
	background: #f59e0b;
}

.terminal-nav-item-badge.badge-info {
	background: var(--terminal-primary, #0e639c);
}

/* No Results */
.terminal-nav-no-results {
	padding: 24px 12px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-sm, 12px);
}
</style>

