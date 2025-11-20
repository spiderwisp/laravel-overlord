<script setup>
import { ref, computed, nextTick, watch } from 'vue';

const props = defineProps({
	commandInput: {
		type: String,
		required: true,
	},
	inputMode: {
		type: String,
		default: 'tinker',
	},
	isExecuting: {
		type: Boolean,
		default: false,
	},
	isSendingAi: {
		type: Boolean,
		default: false,
	},
	fontSize: {
		type: Number,
		default: 14,
	},
});

const emit = defineEmits(['update:commandInput', 'update:inputMode', 'execute', 'add-to-favorites', 'focus']);

const inputRef = ref(null);

// Computed style for terminal output
const terminalStyle = computed(() => ({
	fontSize: `var(--terminal-font-size-base, ${props.fontSize}px)`,
	fontFamily: 'var(--terminal-font-family)',
	lineHeight: 'var(--terminal-line-height, 1.6)',
}));

// Auto-resize textarea based on content
function autoResizeTextarea() {
	if (inputRef.value && inputRef.value.style) {
		// Reset height to auto to get the correct scrollHeight
		inputRef.value.style.height = 'auto';
		// Set height based on scrollHeight, with min and max constraints
		const newHeight = Math.min(Math.max(inputRef.value.scrollHeight, 40), 200);
		inputRef.value.style.height = `${newHeight}px`;
	}
}

// Focus input field
function focusInput() {
	if (inputRef.value) {
		inputRef.value.focus();
		// Auto-resize when focusing
		nextTick(() => {
			autoResizeTextarea();
		});
	}
}

// Handle input focus
function handleInputFocus() {
	emit('focus');
	// Auto-resize when focusing
	nextTick(() => {
		autoResizeTextarea();
	});
}

// Handle input event
function handleInput(event) {
	emit('update:commandInput', event.target.value);
	autoResizeTextarea();
}

// Handle keyboard input
function handleKeyDown(event) {
	// Enter to execute
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault();
		emit('execute');
		return;
	}

	// Ctrl+L to clear (handled by parent)
	if (event.key === 'l' && event.ctrlKey) {
		event.preventDefault();
		// Let parent handle this
		return;
	}

	// For history navigation, emit the event so parent can handle it
	if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
		// Parent will handle history navigation
		return;
	}
}

// Watch for commandInput changes to auto-resize
watch(() => props.commandInput, () => {
	nextTick(() => {
		if (inputRef.value && inputRef.value.style) {
			autoResizeTextarea();
		}
	});
});

// Expose focus method
defineExpose({
	focus: focusInput,
	autoResize: autoResizeTextarea,
});
</script>

<template>
	<div class="terminal-input-area">
		<!-- Mode Selector (Segmented Control) -->
		<div class="terminal-mode-selector">
			<button 
				class="terminal-mode-btn mode-tinker" 
				:class="{ 'active': inputMode === 'tinker' }"
				@click="emit('update:inputMode', 'tinker'); handleInputFocus()"
				title="Tinker (PHP)"
			>
				<span class="terminal-mode-label">PHP</span>
			</button>
			<button 
				class="terminal-mode-btn mode-shell" 
				:class="{ 'active': inputMode === 'shell' }"
				@click="emit('update:inputMode', 'shell'); handleInputFocus()"
				title="Shell (CMD)"
			>
				<span class="terminal-mode-label">CMD</span>
			</button>
			<button 
				class="terminal-mode-btn mode-ai" 
				:class="{ 'active': inputMode === 'ai' }"
				@click="emit('update:inputMode', 'ai'); handleInputFocus()"
				title="AI Assistant"
			>
				<span class="terminal-mode-label">AI</span>
			</button>
		</div>

		<textarea
			ref="inputRef"
			:value="commandInput"
			@input="handleInput"
			@keydown="handleKeyDown"
			@focus="handleInputFocus"
			:disabled="isExecuting"
			class="terminal-input"
			:class="`mode-${inputMode}`"
			:style="terminalStyle"
			:placeholder="inputMode === 'tinker' ? 'Enter PHP code...' : inputMode === 'shell' ? 'Enter shell command...' : 'Ask AI a question...'"
			autocomplete="off"
			spellcheck="false"
			rows="1"
		/>
		<button
			@click="emit('add-to-favorites')"
			:disabled="!commandInput.trim() || isExecuting"
			class="terminal-btn terminal-btn-secondary terminal-btn-icon"
			title="Add to Favorites"
		>
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
			</svg>
		</button>
		<button
			@click="emit('execute')"
			:disabled="!commandInput.trim() || isExecuting || isSendingAi"
			class="terminal-btn terminal-btn-primary"
		>
			{{ isSendingAi ? 'Sending...' : 'Execute' }}
		</button>
	</div>
</template>

<style scoped>
/* Terminal Input Area */
.terminal-input-area {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-top: 1px solid var(--terminal-border, #3e3e42);
	position: relative;
	z-index: 10002;
	flex-shrink: 0;
}

.terminal-input-area .terminal-prompt {
	flex-shrink: 0;
	color: var(--terminal-prompt, #4ec9b0);
	font-weight: 500;
	line-height: 1;
	align-self: center;
}

.terminal-input-area .terminal-btn {
	flex-shrink: 0;
	min-height: 40px;
	align-self: stretch;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-input-area .terminal-ai-toggle {
	min-width: 40px;
	min-height: 40px;
}

.terminal-ai-toggle {
	flex-shrink: 0;
}

.terminal-ai-toggle svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
}

.terminal-input {
	flex: 1;
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
	border: 1px solid var(--terminal-border-hover, #464647);
	border-radius: 4px;
	padding: 8px 12px;
	font-family: var(--terminal-font-family, inherit);
	font-size: var(--terminal-font-size-base, 14px);
	line-height: var(--terminal-line-height, 1.6);
	outline: none;
	resize: none;
	overflow-y: auto;
	min-height: 40px;
	max-height: 200px;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-input:focus {
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-secondary, #2d2d30);
}

.terminal-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Scrollbar styling for textarea */
.terminal-input::-webkit-scrollbar {
	width: 8px;
}

.terminal-input::-webkit-scrollbar-track {
	background: var(--terminal-bg-secondary, #2d2d30);
	border-radius: 4px;
}

.terminal-input::-webkit-scrollbar-thumb {
	background: var(--terminal-border-hover, #464647);
	border-radius: 4px;
}

.terminal-input::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #525252);
}

/* Terminal Buttons */
.terminal-btn {
	padding: 6px 12px;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	min-height: 32px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.terminal-btn-primary {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-tertiary, #3e3e42);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-btn-secondary:hover {
	background: var(--terminal-border-hover, #464647);
}

.terminal-btn-icon {
	padding: 4px 6px;
	min-width: 24px;
}

.terminal-btn svg {
	width: 16px !important;
	height: 16px !important;
	max-width: 16px !important;
	max-height: 16px !important;
	flex-shrink: 0;
}

/* Mode Selector Control */
.terminal-mode-control {
	display: flex;
	align-items: center;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border-radius: 4px;
	padding: 2px;
	border: 1px solid var(--terminal-border-hover, #464647);
	height: 32px;
}

.terminal-mode-selector {
	display: flex;
	align-items: center;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border-radius: 4px;
	padding: 2px;
	border: 1px solid var(--terminal-border-hover, #464647);
	height: 32px;
}

.terminal-mode-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 0 10px;
	background: transparent;
	border: none;
	color: var(--terminal-text-muted, #a0a0a0);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	font-size: 11px;
	font-weight: 600;
	cursor: pointer;
	border-radius: 2px;
	transition: all 0.15s ease;
}

.terminal-mode-btn:hover {
	color: var(--terminal-text, #d4d4d4);
	background: rgba(255, 255, 255, 0.05);
}

.terminal-mode-btn.active {
	color: #fff;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
	font-weight: 600;
}

.terminal-mode-btn.active.mode-tinker {
	background: #4f5d95; /* PHP Blue */
}

.terminal-mode-btn.active.mode-shell {
	background: #42b883; /* Shell Greenish */
	color: #1e1e1e;
}

.terminal-mode-btn.active.mode-ai {
	background: #8e44ad; /* AI Purple */
}

/* Input styling based on mode */
.terminal-input.mode-tinker {
	border-left: 3px solid #4f5d95;
}

.terminal-input.mode-shell {
	border-left: 3px solid #42b883;
}

.terminal-input.mode-ai {
	border-left: 3px solid #8e44ad;
}
</style>

