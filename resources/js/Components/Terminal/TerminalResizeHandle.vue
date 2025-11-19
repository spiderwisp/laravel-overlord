<script setup>
const props = defineProps({
	isResizing: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['start-resize']);
</script>

<template>
	<div
		class="terminal-resize-handle"
		@mousedown="emit('start-resize', $event)"
		@touchstart="emit('start-resize', $event)"
		:class="{ 'terminal-resizing': isResizing }"
		title="Drag to resize terminal"
	>
		<div class="terminal-resize-handle-indicator"></div>
	</div>
</template>

<style scoped>
/* Resize Handle */
.terminal-resize-handle {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	height: 8px;
	cursor: ns-resize;
	z-index: 10001;
	display: flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	transition: background 0.2s;
}

.terminal-resize-handle:hover,
.terminal-resize-handle.terminal-resizing {
	background: var(--terminal-selection, rgba(14, 99, 156, 0.2));
}

.terminal-resize-handle-indicator {
	width: 40px;
	height: 4px;
	background: var(--terminal-primary, #0e639c);
	border-radius: 2px;
	opacity: 0.5;
	transition: opacity 0.2s;
}

.terminal-resize-handle:hover .terminal-resize-handle-indicator,
.terminal-resize-handle.terminal-resizing .terminal-resize-handle-indicator {
	opacity: 1;
}
</style>

