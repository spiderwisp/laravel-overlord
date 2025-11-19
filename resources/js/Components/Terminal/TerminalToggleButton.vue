<script setup>
const emit = defineEmits(['click']);
</script>

<template>
	<button
		@click="emit('click')"
		class="terminal-toggle-btn"
		title="Open Laravel Overlord"
	>
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
		</svg>
		<span>DEV</span>
	</button>
</template>

<style scoped>
/* Toggle Button */
.terminal-toggle-btn {
	position: fixed;
	bottom: 20px;
	left: 20px;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 20px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 8px;
	cursor: pointer;
	box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	transition: all 0.2s;
	z-index: 10000;
	font-weight: 500;
}

.terminal-toggle-btn:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	transform: translateY(-2px);
	box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-toggle-btn svg {
	width: 24px !important;
	height: 24px !important;
	max-width: 24px !important;
	max-height: 24px !important;
}
</style>

