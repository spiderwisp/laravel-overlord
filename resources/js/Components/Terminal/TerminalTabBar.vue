<script setup>
import Swal from '../../utils/swalConfig';

const props = defineProps({
	openTabs: {
		type: Array,
		required: true,
	},
	activeTab: {
		type: String,
		required: true,
	},
	issuesCounter: {
		type: Object,
		default: null,
	},
	favoritesDrawerOpen: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['switch-tab', 'close-tab', 'toggle-issues']);

function isTabActive(tabId) {
	return props.activeTab === tabId;
}

async function handleTabContextMenu(event, tabId) {
	// Simple context menu - could be enhanced with a proper menu component
	if (tabId === 'terminal') return;
	
	const result = await Swal.fire({
		icon: 'question',
		title: 'Close other tabs?',
		text: 'Do you want to close all other tabs?',
		showCancelButton: true,
		confirmButtonText: 'Yes',
		cancelButtonText: 'Cancel',
	});
	
	if (result.isConfirmed) {
		emit('close-other-tabs', tabId);
	}
}
</script>

<template>
	<div v-if="openTabs.length > 0" class="terminal-tabs" :class="{ 'favorites-drawer-open': favoritesDrawerOpen }">
		<div class="terminal-tabs-header">
			<div class="terminal-tabs-container">
				<button
					v-for="tab in openTabs"
					:key="tab.id"
					@click="emit('switch-tab', tab.id)"
					@contextmenu.prevent="handleTabContextMenu($event, tab.id)"
					:class="['terminal-tab', { 'active': isTabActive(tab.id) }]"
					:title="tab.label"
				>
					<span class="terminal-tab-label">{{ tab.label }}</span>
					<button
						v-if="tab.closable"
						@click.stop="emit('close-tab', tab.id)"
						class="terminal-tab-close"
						title="Close tab"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
							<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</button>
			</div>
			<!-- Issues/Notifications Actions -->
			<div class="terminal-nav-actions">
				<!-- Issues Counter -->
				<button
					v-if="issuesCounter"
					@click="emit('toggle-issues')"
					class="terminal-issues-counter"
					:class="`terminal-issues-counter-${issuesCounter.color}`"
					:title="`${issuesCounter.count} open issues${issuesCounter.critical > 0 ? `, ${issuesCounter.critical} critical` : ''}${issuesCounter.high > 0 ? `, ${issuesCounter.high} high priority` : ''}`"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
					</svg>
					<span class="terminal-issues-counter-badge">{{ issuesCounter.count }}</span>
				</button>
				<!-- Notifications Placeholder -->
				<button
					class="terminal-notifications-btn"
					title="Notifications (Coming Soon)"
					disabled
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
					</svg>
				</button>
			</div>
		</div>
	</div>
</template>

<style scoped>
/* Tab Bar */
.terminal-tabs {
	background: var(--terminal-bg-secondary, #252526);
	border-bottom: none; /* Remove border, tabs will handle their own borders */
	padding-top: 8px; /* Space for resize handle */
	padding-bottom: 0; /* Remove bottom padding */
	transition: margin-top 0.3s ease;
	position: relative;
	z-index: 10002; /* Above favorites tray */
	margin-bottom: 0; /* Remove margin to make tabs look connected */
}

.terminal-tabs-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 0; /* Remove any default padding */
	margin: 0; /* Remove any default margin */
}

.terminal-tabs-container {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: flex-end; /* Align tabs to bottom of container */
	gap: 4px;
	padding: 8px 0 0 0; /* Only top padding for resize handle, no side padding */
	overflow-x: auto;
	overflow-y: hidden;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #3e3e42) var(--terminal-bg-secondary, #252526);
}

.terminal-nav-actions {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px 0 12px; /* Match tabs container top padding, no bottom padding */
}

.terminal-tabs-container::-webkit-scrollbar {
	height: 6px;
}

.terminal-tabs-container::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #252526);
}

.terminal-tabs-container::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 3px;
}

.terminal-tabs-container::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 14px;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-bottom: 1px solid var(--terminal-border, #3e3e42); /* Keep bottom border for inactive tabs */
	border-radius: 6px 6px 0 0;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	white-space: nowrap;
	position: relative;
	min-width: 80px;
	flex-shrink: 0;
	max-width: 200px;
	margin-bottom: 0; /* Remove any margin */
}

.terminal-tab:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
}

.terminal-tab.active {
	background: var(--terminal-bg, #1e1e1e);
	border-color: var(--terminal-primary, #0e639c);
	border-bottom-color: var(--terminal-bg, #1e1e1e);
	border-bottom-width: 1px;
	color: var(--terminal-text, #ffffff);
	font-weight: 500;
	z-index: 1;
	margin-bottom: -1px; /* Connect to content below */
}

.terminal-tab.active::after {
	content: '';
	position: absolute;
	bottom: -1px;
	left: 0;
	right: 0;
	height: 2px;
	background: var(--terminal-primary, #0e639c);
}

.terminal-tab-label {
	flex: 1;
	user-select: none;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-tab-close {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 2px;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	border-radius: 2px;
	transition: all 0.2s;
	width: 16px;
	height: 16px;
	flex-shrink: 0;
}

.terminal-tab-close:hover {
	background: var(--terminal-border, #3e3e42);
	color: var(--terminal-text, #ffffff);
}

.terminal-tab-close svg {
	width: 12px !important;
	height: 12px !important;
	max-width: 12px !important;
	max-height: 12px !important;
}

/* Issues Counter */
.terminal-issues-counter {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
	position: relative;
}

.terminal-issues-counter:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
	transform: translateY(-1px);
}

.terminal-issues-counter svg {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
}

.terminal-issues-counter-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	line-height: 1;
}

.terminal-issues-counter-red .terminal-issues-counter-badge {
	background: #dc2626;
	color: #ffffff;
}

.terminal-issues-counter-orange .terminal-issues-counter-badge {
	background: #ea580c;
	color: #ffffff;
}

.terminal-issues-counter-yellow .terminal-issues-counter-badge {
	background: #ca8a04;
	color: #ffffff;
}

.terminal-issues-counter-blue .terminal-issues-counter-badge {
	background: #2563eb;
	color: #ffffff;
}

.terminal-issues-counter-red svg {
	color: #dc2626;
}

.terminal-issues-counter-orange svg {
	color: #ea580c;
}

.terminal-issues-counter-yellow svg {
	color: #ca8a04;
}

.terminal-issues-counter-blue svg {
	color: #2563eb;
}

/* Notifications Button */
.terminal-notifications-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	padding: 0;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text-secondary, #858585);
	cursor: not-allowed;
	opacity: 0.5;
	transition: all 0.2s;
}

.terminal-notifications-btn:disabled {
	cursor: not-allowed;
}

.terminal-notifications-btn svg {
	width: 20px;
	height: 20px;
}
</style>

