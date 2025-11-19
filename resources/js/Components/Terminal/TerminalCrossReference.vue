<script setup>
import { computed } from 'vue';

const props = defineProps({
	references: {
		type: [Object, Array],
		default: null,
	},
	type: {
		type: String,
		default: 'default',
	},
});

const emit = defineEmits(['navigate']);

// Normalize references to array
const normalizedReferences = computed(() => {
	if (!props.references) return [];
	if (Array.isArray(props.references)) return props.references;
	if (typeof props.references === 'object') {
		// Single reference object
		return [props.references];
	}
	return [];
});

// Get icon for reference type
function getIcon(type) {
	const icons = {
		controller: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
		middleware: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
		model: 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4',
		service: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
		trait: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
		route: 'M13 7l5 5m0 0l-5 5m5-5H6',
		default: 'M13 7l5 5m0 0l-5 5m5-5H6',
	};
	return icons[props.type] || icons.default;
}

// Get label for reference
function getLabel(ref) {
	if (ref.label) return ref.label;
	if (ref.name) return ref.name;
	if (ref.class) return ref.class.split('\\').pop();
	if (ref.full_name) return ref.full_name.split('\\').pop();
	return 'Unknown';
}

// Handle click
function handleClick(ref) {
	emit('navigate', {
		type: props.type,
		identifier: ref.class || ref.name || ref.full_name || ref.identifier,
		method: ref.method,
		...ref,
	});
}
</script>

<template>
	<div v-if="normalizedReferences.length > 0" class="terminal-cross-reference">
		<button
			v-for="(ref, index) in normalizedReferences"
			:key="index"
			@click="handleClick(ref)"
			class="terminal-cross-reference-item"
			:title="`Click to view ${props.type}`"
		>
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
				<path stroke-linecap="round" stroke-linejoin="round" :d="getIcon(props.type)" />
			</svg>
			<span>{{ getLabel(ref) }}</span>
			<span v-if="ref.method" class="terminal-cross-reference-method">@{{ ref.method }}</span>
		</button>
	</div>
</template>

<style scoped>
.terminal-cross-reference {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.terminal-cross-reference-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	font-size: 12px;
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-cross-reference-item:hover {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: var(--terminal-bg);
}

.terminal-cross-reference-item svg {
	width: 14px;
	height: 14px;
	flex-shrink: 0;
}

.terminal-cross-reference-method {
	opacity: 0.7;
	font-size: 11px;
}
</style>

