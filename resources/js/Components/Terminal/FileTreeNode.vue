<template>
	<div class="file-tree-node">
		<div
			class="file-tree-item"
			:class="{ 'selected': isSelected, 'expanded': isExpanded }"
			@click="handleClick"
		>
			<span class="file-tree-toggle" v-if="hasChildren" @click.stop="toggleExpand">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="expand-icon">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
				</svg>
			</span>
			<span v-else class="file-tree-spacer"></span>
			
			<span class="file-tree-checkbox" @click.stop="handleCheckboxClick">
				<input
					type="checkbox"
					:checked="isSelected"
					@click.stop
					@change.stop="handleCheckboxChange"
				/>
			</span>
			
			<span class="file-tree-icon">
				<svg v-if="node.type === 'directory'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
				</svg>
				<svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
				</svg>
			</span>
			
			<span class="file-tree-name">{{ node.name }}</span>
			
			<span v-if="node.type === 'directory' && node.file_count" class="file-tree-count">
				{{ node.file_count }} file{{ node.file_count !== 1 ? 's' : '' }}
			</span>
		</div>
		
		<div v-if="hasChildren && isExpanded" class="file-tree-children">
			<FileTreeNode
				v-for="child in node.children"
				:key="child.path"
				:node="child"
				:selected-paths="selectedPaths"
				:expanded-paths="expandedPaths"
				@toggle="$emit('toggle', $event)"
				@expand="$emit('expand', $event)"
			/>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
	node: {
		type: Object,
		required: true,
	},
	selectedPaths: {
		type: Array,
		default: () => [],
	},
	expandedPaths: {
		type: Set,
		default: () => new Set(),
	},
});

const emit = defineEmits(['toggle', 'expand']);

const isSelected = computed(() => props.selectedPaths.includes(props.node.path));
const hasChildren = computed(() => props.node.children && props.node.children.length > 0);
const isExpanded = computed(() => props.expandedPaths.has(props.node.path));

function toggleSelection() {
	emit('toggle', props.node.path);
}

function handleCheckboxClick(event) {
	event.stopPropagation();
	toggleSelection();
}

function handleCheckboxChange(event) {
	event.stopPropagation();
	toggleSelection();
}

function toggleExpand() {
	emit('expand', props.node.path);
}

function handleClick() {
	if (props.node.type === 'directory') {
		toggleExpand();
	}
}
</script>

<style scoped>
.file-tree-node {
	user-select: none;
}

.file-tree-item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem;
	padding-left: calc(0.5rem + var(--depth, 0) * 1.5rem);
	cursor: pointer;
	border-radius: 4px;
	transition: background-color 0.2s;
}

.file-tree-item:hover {
	background: rgba(255, 255, 255, 0.05);
}

.file-tree-item.selected {
	background: color-mix(in srgb, var(--terminal-primary) 20%, transparent);
}

.file-tree-toggle {
	width: 16px;
	height: 16px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.file-tree-spacer {
	width: 16px;
	flex-shrink: 0;
}

.expand-icon {
	width: 12px;
	height: 12px;
	color: rgba(255, 255, 255, 0.6);
	transition: transform 0.2s;
}

.file-tree-item.expanded .expand-icon {
	transform: rotate(90deg);
}

.file-tree-checkbox {
	display: flex;
	align-items: center;
	flex-shrink: 0;
}

.file-tree-checkbox input[type="checkbox"] {
	cursor: pointer;
	width: 16px;
	height: 16px;
}

.file-tree-icon {
	width: 18px;
	height: 18px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	color: rgba(255, 255, 255, 0.7);
}

.file-tree-icon svg {
	width: 18px;
	height: 18px;
}

.file-tree-name {
	flex: 1;
	font-size: 0.875rem;
	color: rgba(255, 255, 255, 0.9);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-tree-count {
	font-size: 0.75rem;
	color: rgba(255, 255, 255, 0.5);
	padding: 0.25rem 0.5rem;
	background: rgba(255, 255, 255, 0.05);
	border-radius: 4px;
}

.file-tree-children {
	margin-left: 1rem;
	border-left: 1px solid rgba(255, 255, 255, 0.1);
	padding-left: 0.5rem;
}
</style>

