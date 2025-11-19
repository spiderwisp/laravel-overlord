<script setup>
import { ref } from 'vue';
import TerminalOutputItem from './TerminalOutputItem.vue';

const props = defineProps({
	outputHistory: {
		type: Array,
		required: true,
	},
	isExecuting: {
		type: Boolean,
		default: false,
	},
	fontSize: {
		type: Number,
		default: 13,
	},
	showActions: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['insert-command', 'execute-command', 'create-issue', 'clear-output', 'clear-session', 'close-terminal']);

const outputContainerRef = ref(null);

// Handle insert command event
function handleInsertCommand(code) {
	try {
		emit('insert-command', code);
	} catch (error) {
		console.error('TerminalOutput: Error emitting insert-command:', error);
	}
}

// Handle execute command event
function handleExecuteCommand(code) {
	try {
		emit('execute-command', code);
	} catch (error) {
		console.error('TerminalOutput: Error emitting execute-command:', error);
	}
}

// Handle create issue event
function handleCreateIssue(prefillData) {
	try {
		emit('create-issue', prefillData);
	} catch (error) {
		console.error('TerminalOutput: Error emitting create-issue:', error);
	}
}

defineExpose({
	scrollToBottom() {
		if (outputContainerRef.value) {
			outputContainerRef.value.scrollTop = outputContainerRef.value.scrollHeight;
		}
	},
});
</script>

<template>
	<div ref="outputContainerRef" class="terminal-output" :style="{ fontSize: `var(--terminal-font-size-base, ${fontSize}px)`, fontFamily: 'var(--terminal-font-family)', lineHeight: 'var(--terminal-line-height, 1.6)' }">
		<!-- Floating Action Buttons Overlay (only visible when terminal tab is active) -->
		<div v-if="showActions" class="terminal-actions-overlay">
			<!-- Clear Actions -->
			<div class="terminal-clear-actions">
				<button
					@click="$emit('clear-output')"
					class="terminal-btn terminal-btn-secondary terminal-btn-icon"
					title="Clear Output (Ctrl+L)"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
				<button
					@click="$emit('clear-session')"
					class="terminal-btn terminal-btn-secondary terminal-btn-icon"
					title="Clear Session (reset variables)"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
			</div>
			<!-- Close Terminal Button -->
			<button
				@click="$emit('close-terminal')"
				class="terminal-btn terminal-btn-secondary terminal-btn-icon terminal-btn-danger"
				title="Close Terminal"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
				</svg>
			</button>
		</div>

		<TerminalOutputItem
			v-for="(item, index) in outputHistory"
			:key="index"
			:item="item"
			:index="index"
			:output-history="outputHistory"
			@insert-command="handleInsertCommand"
			@execute-command="handleExecuteCommand"
			@create-issue="handleCreateIssue"
		/>

		<!-- Loading Indicator -->
		<div v-if="isExecuting" class="terminal-output-item">
			<div class="terminal-prompt">$</div>
			<div class="terminal-executing">
				<span class="spinner"></span>
				Executing...
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-output {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	background: var(--terminal-bg);
	line-height: var(--terminal-line-height, 1.6);
	min-height: 0;
	position: relative; /* For absolute positioning of overlay */
}

/* Floating Action Buttons Overlay */
.terminal-actions-overlay {
	position: absolute;
	top: 12px;
	right: 12px;
	display: flex;
	align-items: center;
	gap: 8px;
	z-index: 10001; /* Above content but below modals */
	pointer-events: auto;
}

.terminal-actions-overlay .terminal-btn {
	box-shadow: 0 2px 8px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-actions-overlay .terminal-btn:hover {
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.4));
	background: var(--terminal-bg-tertiary, #2d2d30);
}

/* Clear Actions (in overlay) */
.terminal-actions-overlay .terminal-clear-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 0 4px;
	border-left: 1px solid var(--terminal-border, #3e3e42);
	border-right: 1px solid var(--terminal-border, #3e3e42);
	margin: 0 4px;
}

.terminal-output-item {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin-bottom: 12px;
	word-break: break-word;
}

.terminal-prompt {
	color: var(--terminal-prompt);
	font-weight: 600;
	user-select: none;
	flex-shrink: 0;
}

.terminal-executing {
	color: var(--terminal-text-secondary);
	display: flex;
	align-items: center;
	gap: 8px;
}

.spinner {
	width: 12px;
	height: 12px;
	border: 2px solid var(--terminal-border);
	border-top-color: var(--terminal-prompt);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Scrollbar Styling */
.terminal-output {
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border) var(--terminal-bg);
}

.terminal-output::-webkit-scrollbar {
	width: 10px;
}

.terminal-output::-webkit-scrollbar-track {
	background: var(--terminal-bg);
	border-radius: 5px;
}

.terminal-output::-webkit-scrollbar-thumb {
	background: var(--terminal-border);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg);
}

.terminal-output::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover);
}

/* Button Styles (needed for overlay buttons) */
.terminal-btn {
	padding: 0.5rem;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	cursor: pointer;
	font-size: 0.875rem;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-btn-secondary {
	background: var(--terminal-bg-secondary, #252526);
	border-color: var(--terminal-border, #3e3e42);
}

.terminal-btn-icon {
	padding: 0.5rem;
	min-width: 36px;
	min-height: 36px;
}

.terminal-btn-icon svg {
	width: 16px;
	height: 16px;
}

.terminal-btn-danger {
	background: var(--terminal-error, #f48771);
	border-color: var(--terminal-error, #f48771);
	color: #ffffff;
}

.terminal-btn-danger:hover:not(:disabled) {
	background: var(--terminal-error-hover, #ff9d8a);
	border-color: var(--terminal-error-hover, #ff9d8a);
}
</style>

