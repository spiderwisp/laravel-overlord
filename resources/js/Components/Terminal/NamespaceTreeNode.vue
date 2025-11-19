<script>
export default {
	name: 'NamespaceTreeNode',
	props: {
		node: {
			type: Object,
			required: true,
		},
		selectedController: {
			type: Object,
			default: null,
		},
		expandedNamespaces: {
			type: Set,
			required: true,
		},
	},
	emits: ['navigate', 'toggle-expansion', 'select-controller'],
	computed: {
		isExpanded() {
			return this.expandedNamespaces.has(this.node.fullPath);
		},
		hasChildren() {
			return this.node.children && this.node.children.length > 0;
		},
		hasControllers() {
			return this.node.controllers && this.node.controllers.length > 0;
		},
	},
	methods: {
		handleClick(e) {
			// Only navigate if clicking on the namespace name or folder icon, not the toggle button
			if (e.target.closest('.terminal-controllers-namespace-toggle')) {
				return; // Let the toggle handle it
			}
			this.$emit('navigate', this.node.fullPath);
		},
		handleToggle(e) {
			e.stopPropagation();
			this.$emit('toggle-expansion', this.node.fullPath);
		},
	},
};
</script>

<template>
	<div class="terminal-controllers-namespace-wrapper">
		<div
			class="terminal-controllers-namespace-item"
			:style="{ paddingLeft: (node.level * 16) + 'px' }"
			@click="handleClick"
		>
			<button
				v-if="hasChildren"
				@click="handleToggle"
				class="terminal-controllers-namespace-toggle"
				:class="{ 'expanded': isExpanded }"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
				</svg>
			</button>
			<svg
				v-else
				class="terminal-controllers-namespace-spacer"
				xmlns="http://www.w3.org/2000/svg"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
			>
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
			</svg>
			<svg class="terminal-controllers-namespace-folder-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
			</svg>
			<span class="terminal-controllers-namespace-name">{{ node.name }}</span>
			<span class="terminal-controllers-namespace-count">
				({{ node.controllers.length }}{{ hasChildren ? '+' : '' }})
			</span>
		</div>
		<!-- Controllers in this namespace -->
		<template v-if="hasControllers">
			<div
				v-for="controller in node.controllers"
				:key="controller.fullName"
				:data-controller-fullname="controller.fullName"
				class="terminal-controllers-item"
				:class="{ 'active': selectedController?.fullName === controller.fullName }"
				:style="{ paddingLeft: ((node.level + 1) * 16) + 'px' }"
				@click.stop="$emit('select-controller', controller)"
			>
				<div class="terminal-controllers-item-header">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
					</svg>
					<span class="terminal-controllers-item-name">{{ controller.name }}</span>
					<span class="terminal-controllers-item-methods-count">{{ controller.methods.length }} methods</span>
				</div>
			</div>
		</template>
		<!-- Child namespaces -->
		<template v-if="hasChildren && isExpanded">
			<NamespaceTreeNode
				v-for="child in node.children"
				:key="child.fullPath"
				:node="child"
				:selected-controller="selectedController"
				:expanded-namespaces="expandedNamespaces"
				@navigate="$emit('navigate', $event)"
				@toggle-expansion="$emit('toggle-expansion', $event)"
				@select-controller="$emit('select-controller', $event)"
			/>
		</template>
	</div>
</template>

<style scoped>
/* Namespace Tree Styles */
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
	transition: background 0.2s;
	color: var(--terminal-text);
	font-size: 12px;
}

.terminal-controllers-namespace-item:hover {
	background: var(--terminal-bg-tertiary);
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

/* Ensure controller item icons are properly sized */
.terminal-controllers-item-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-controllers-item-header svg {
	width: 14px !important;
	height: 14px !important;
	max-width: 14px !important;
	max-height: 14px !important;
	min-width: 14px !important;
	min-height: 14px !important;
	flex-shrink: 0;
}

.terminal-controllers-item-name {
	flex: 1;
	min-width: 0;
}

.terminal-controllers-item-methods-count {
	margin-left: auto;
	flex-shrink: 0;
}

.terminal-controllers-namespace-name {
	flex: 1;
	color: var(--terminal-text);
	font-weight: 500;
}

.terminal-controllers-namespace-count {
	color: var(--terminal-text-muted);
	font-size: 11px;
	font-weight: normal;
}
</style>

