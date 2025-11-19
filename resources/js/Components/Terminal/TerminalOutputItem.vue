<script setup>
import { ref } from 'vue';
import Swal from '../../utils/swalConfig';
import JsonViewer from '../JsonViewer.vue';
import TerminalOutputAi from './TerminalOutputAi.vue';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const props = defineProps({
	item: {
		type: Object,
		required: true,
	},
	index: {
		type: Number,
		required: true,
	},
	outputHistory: {
		type: Array,
		required: true,
	},
});

const emit = defineEmits(['copy', 'export', 'insert-command', 'execute-command', 'create-issue']);

const showExportMenu = ref(false);

// Handle insert command event
function handleInsertCommand(code) {
	emit('insert-command', code);
}

// Handle execute command event
function handleExecuteCommand(code) {
	emit('execute-command', code);
}

// Create issue from error
function createIssueFromError() {
	const errorOutput = props.item.output?.formatted || props.item.output || props.item.raw || 'Terminal error';
	const command = props.item.command || '';
	
	// Build title
	let title = 'Terminal Error';
	if (command) {
		const commandPreview = command.length > 50 ? command.substring(0, 50) + '...' : command;
		title = `Terminal Error: ${commandPreview}`;
	}
	
	// Build description
	let description = errorOutput;
	if (command) {
		description = `Command:\n${command}\n\nError:\n${errorOutput}`;
	}
	
	// Build source data
	const sourceData = {
		command: command,
		output: errorOutput,
		error: errorOutput,
		command_log_id: props.item.command_log_id || null,
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: 'high',
		source_type: 'terminal',
		source_id: props.item.command_log_id ? `command_log_${props.item.command_log_id}` : null,
		source_data: sourceData,
	});
}

// Copy single output item to clipboard
async function copyItemToClipboard() {
	let text = '';
	
	if (props.item.type === 'command') {
		text = props.item.output;
	} else if (props.item.type === 'json' || props.item.type === 'object') {
		const data = props.item.output?.formatted || props.item.output;
		if (typeof data === 'string') {
			text = data;
		} else {
			text = JSON.stringify(data, null, 2);
		}
	} else {
		const output = props.item.output?.formatted || props.item.output || props.item.raw;
		text = typeof output === 'string' ? output : String(output);
	}
	
	try {
		await navigator.clipboard.writeText(text);
		Swal.fire({
			toast: true,
			icon: 'success',
			title: 'Copied to clipboard',
			position: 'bottom-end',
			showConfirmButton: false,
			timer: 2000,
		});
	} catch (err) {
		// Fallback
		const textArea = document.createElement('textarea');
		textArea.value = text;
		textArea.style.position = 'fixed';
		textArea.style.opacity = '0';
		document.body.appendChild(textArea);
		textArea.select();
		try {
			document.execCommand('copy');
			Swal.fire({
				toast: true,
				icon: 'success',
				title: 'Copied to clipboard',
				position: 'bottom-end',
				showConfirmButton: false,
				timer: 2000,
			});
		} catch (e) {
			Swal.fire({
				toast: true,
				icon: 'error',
				title: 'Failed to copy to clipboard',
				position: 'bottom-end',
				showConfirmButton: false,
				timer: 2000,
			});
		}
		document.body.removeChild(textArea);
	}
}

// Format markdown content (code blocks, inline code, line breaks)
function formatMarkdown(content) {
	if (typeof content !== 'string') {
		return content;
	}
	
	// Check for code blocks
	const codeBlockRegex = /```(\w+)?[\s\n]*([\s\S]*?)```/g;
	
	// Convert code blocks to styled blocks with syntax highlighting
	content = content.replace(
		codeBlockRegex,
		(match, lang, code) => {
			const trimmedCode = code.trim();
			const language = lang || null;
			const highlighted = highlightCode(trimmedCode, language);
			return `<pre class="terminal-output-code-block"><code class="hljs language-${language || 'text'}">${highlighted}</code></pre>`;
		}
	);
	
	// Convert inline code
	content = content.replace(
		/`([^`]+)`/g,
		'<code class="terminal-output-inline-code">$1</code>'
	);
	
	// Convert line breaks
	content = content.replace(/\n/g, '<br>');
	
	return content;
}

// Escape HTML
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}

// Export single output item to file
function exportSingleItem(format = 'txt') {
	let content = '';
	let filename = '';
	
	if (format === 'json') {
		// Export as JSON
		const exportData = {
			exportedAt: new Date().toISOString(),
			type: props.item.type,
			output: props.item.output,
			raw: props.item.raw,
			timestamp: props.item.timestamp,
		};
		content = JSON.stringify(exportData, null, 2);
		filename = `terminal-output-item-${Date.now()}.json`;
	} else {
		// Export as text
		if (props.item.type === 'command') {
			content = `$ ${props.item.output}\n`;
		} else if (props.item.type === 'json' || props.item.type === 'object') {
			const data = props.item.output?.formatted || props.item.output;
			if (typeof data === 'string') {
				content = data;
			} else {
				content = JSON.stringify(data, null, 2);
			}
		} else {
			const output = props.item.output?.formatted || props.item.output || props.item.raw;
			content = typeof output === 'string' ? output : String(output);
		}
		filename = `terminal-output-item-${Date.now()}.txt`;
	}
	
	// Create download link
	const blob = new Blob([content], { type: format === 'json' ? 'application/json' : 'text/plain' });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = filename;
	document.body.appendChild(a);
	a.click();
	document.body.removeChild(a);
	URL.revokeObjectURL(url);
	
	Swal.fire({
		toast: true,
		icon: 'success',
		title: `Exported as ${filename}`,
		position: 'bottom-end',
		showConfirmButton: false,
		timer: 2000,
	});
	
	showExportMenu.value = false;
}
</script>

<template>
	<div
		class="terminal-output-item"
		:class="`terminal-output-${item.type}`"
	>
		<div class="terminal-output-item-content">
			<!-- Command Output -->
			<template v-if="item.type === 'command'">
				<div class="terminal-prompt">$</div>
				<div class="terminal-command">{{ item.output }}</div>
			</template>

			<!-- JSON/Object Output -->
			<template v-else-if="item.type === 'json' || item.type === 'object'">
				<JsonViewer :data="item.output?.formatted || item.output" />
			</template>

			<!-- Error Output -->
			<template v-else-if="item.type === 'error'">
				<div class="terminal-error-wrapper">
					<div class="terminal-error">
						{{ item.output?.formatted || item.output || item.raw }}
					</div>
					<button
						@click="createIssueFromError"
						class="terminal-btn terminal-btn-secondary terminal-btn-sm terminal-error-create-issue"
						title="Create Issue"
					>
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 14px; height: 14px; margin-right: 4px;">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
						</svg>
						Create Issue
					</button>
				</div>
			</template>

			<!-- Help Output (HTML) -->
			<template v-else-if="item.type === 'help'">
				<div class="terminal-help" v-html="item.output?.formatted || item.output || item.raw"></div>
			</template>

			<!-- Text Output -->
			<template v-else>
				<div class="terminal-text" v-html="formatMarkdown(item.output?.formatted || item.output || item.raw)"></div>
			</template>
		</div>
		
		<!-- Inline AI Query Component - Show for all output types except commands -->
		<TerminalOutputAi
			v-if="item.type !== 'command'"
			:output-item="item"
			:output-history="outputHistory"
			:current-index="index"
			class="terminal-output-item-ai"
			@insert-command="handleInsertCommand"
			@execute-command="handleExecuteCommand"
		/>
		
		<!-- Inline Actions (Copy & Export) - shown on hover -->
		<div class="terminal-output-item-actions">
			<button
				@click.stop="copyItemToClipboard"
				class="terminal-output-action-btn"
				title="Copy this output"
			>
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
				</svg>
			</button>
			<div class="terminal-output-export-dropdown" @click.stop>
				<button
					@click.stop="showExportMenu = !showExportMenu"
					class="terminal-output-action-btn"
					title="Export this output"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
					</svg>
				</button>
				<div v-if="showExportMenu" class="terminal-output-export-menu">
					<button @click.stop="exportSingleItem('txt')" class="terminal-output-export-menu-item">
						Export as Text
					</button>
					<button @click.stop="exportSingleItem('json')" class="terminal-output-export-menu-item">
						Export as JSON
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-output-item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	margin-bottom: 12px;
	word-break: break-word;
	position: relative;
	padding-right: 60px; /* Space for action buttons */
}

.terminal-output-item-content {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	width: 100%;
}

.terminal-output-item-ai {
	width: 100%;
}

.terminal-output-item:hover .terminal-output-item-actions {
	opacity: 1;
	pointer-events: auto;
}

.terminal-output-item-actions {
	position: absolute;
	right: 0;
	top: 0;
	display: flex;
	gap: 4px;
	align-items: center;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.2s ease;
	background: color-mix(in srgb, var(--terminal-bg) 90%, transparent);
	padding: 4px;
	border-radius: 4px;
	backdrop-filter: blur(4px);
}

.terminal-output-action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	background: transparent;
	border: 1px solid var(--terminal-border);
	border-radius: 3px;
	color: var(--terminal-text-secondary);
	cursor: pointer;
	transition: all 0.2s ease;
}

.terminal-output-action-btn:hover {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
	color: var(--terminal-prompt);
}

.terminal-output-action-btn svg {
	width: 14px;
	height: 14px;
}

.terminal-output-export-dropdown {
	position: relative;
}

.terminal-output-export-menu {
	position: absolute;
	top: 100%;
	right: 0;
	margin-top: 4px;
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	min-width: 140px;
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	z-index: 1000;
}

.terminal-output-export-menu-item {
	display: block;
	width: 100%;
	padding: 8px 12px;
	text-align: left;
	background: transparent;
	border: none;
	color: var(--terminal-text);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: background 0.2s ease;
}

.terminal-output-export-menu-item:hover {
	background: var(--terminal-bg-tertiary);
	color: var(--terminal-prompt);
}

.terminal-output-export-menu-item:first-child {
	border-top-left-radius: 4px;
	border-top-right-radius: 4px;
}

.terminal-output-export-menu-item:last-child {
	border-bottom-left-radius: 4px;
	border-bottom-right-radius: 4px;
}

.terminal-prompt {
	color: var(--terminal-prompt);
	font-weight: 600;
	user-select: none;
	flex-shrink: 0;
}

.terminal-command {
	color: var(--terminal-text);
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-text {
	color: var(--terminal-text);
	white-space: pre-wrap;
	line-height: var(--terminal-line-height, 1.6);
}

.terminal-output-code-block {
	background: var(--terminal-code-bg);
	border: 1px solid var(--terminal-code-border);
	border-radius: 4px;
	padding: 12px;
	margin: 8px 0;
	overflow-x: auto;
	font-family: var(--terminal-font-family, 'Courier New', 'Monaco', 'Menlo', monospace);
	font-size: var(--terminal-font-size-sm, 12px);
}

.terminal-output-code-block code {
	color: var(--terminal-text);
	background: transparent;
	padding: 0;
	border: none;
	border-radius: 0;
	display: block;
	white-space: pre;
	overflow-x: auto;
}

/* Ensure highlight.js styles are applied */
.terminal-output-code-block code.hljs {
	display: block;
	overflow-x: auto;
	padding: 0;
	background: transparent;
}

.terminal-output-code-block code.hljs * {
	color: inherit;
}

.terminal-output-inline-code {
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 3px;
	padding: 2px 6px;
	font-family: var(--terminal-font-family, 'Courier New', 'Monaco', 'Menlo', monospace);
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-prompt);
}

.terminal-error-wrapper {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-error {
	color: var(--terminal-error);
	white-space: pre-wrap;
}

.terminal-error-create-issue {
	align-self: flex-start;
	margin-top: 4px;
}

/* Help Content Styling - Modern Design */
.terminal-help {
	color: var(--terminal-text);
	font-family: var(--terminal-font-family, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif);
	font-size: var(--terminal-font-size-md, 14px);
	line-height: var(--terminal-line-height, 1.6);
	padding: 20px;
}

.terminal-help .help-content {
	padding: 0;
	max-width: 100%;
}

.terminal-help .help-header {
	margin-bottom: 32px;
	padding-bottom: 20px;
	border-bottom: 2px solid #3e3e42;
}

.terminal-help .help-title {
	font-size: 24px;
	font-weight: 700;
	color: #4ec9b0;
	margin-bottom: 8px;
	display: block;
	line-height: 1.3;
}

.terminal-help .help-subtitle {
	font-size: 14px;
	color: #858585;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-section {
	margin-bottom: 40px;
	padding-bottom: 24px;
	border-bottom: 1px solid rgba(62, 62, 66, 0.5);
}

.terminal-help .help-section:last-of-type {
	border-bottom: none;
}

.terminal-help .help-section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 18px;
	font-weight: 600;
	color: #4fc3f7;
	margin-bottom: 12px;
	line-height: 1.4;
}

.terminal-help .help-section-icon {
	font-size: 20px;
}

.terminal-help .help-section-desc {
	color: var(--terminal-text, #d4d4d4);
	margin-bottom: 16px;
	font-size: 14px;
	line-height: 1.5;
}

.terminal-help .help-subsection {
	margin-bottom: 28px;
	margin-left: 0;
	padding-left: 0;
}

.terminal-help .help-subsection-title {
	color: #4ec9b0;
	font-weight: 600;
	font-size: 15px;
	margin-bottom: 12px;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-code-block {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-left: 20px;
	margin-top: 8px;
}

.terminal-help .help-code {
	background: var(--terminal-code-bg, #1e1e1e);
	padding: 12px 16px;
	border-radius: 6px;
	border-left: 4px solid #ce9178;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 13px;
	white-space: pre-wrap;
	word-wrap: break-word;
	display: block;
	line-height: 1.6;
	overflow-x: auto;
	box-shadow: 0 2px 8px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	margin: 0;
	color: var(--terminal-text, #d4d4d4);
}

/* PHP Syntax Highlighting Colors */
.terminal-help .help-code .php-keyword {
	color: #569cd6;
	font-weight: 600;
}

.terminal-help .help-code .php-string {
	color: #ce9178;
}

.terminal-help .help-code .php-number {
	color: #b5cea8;
}

.terminal-help .help-code .php-variable {
	color: #9cdcfe;
}

.terminal-help .help-code .php-class {
	color: var(--terminal-accent);
	font-weight: 500;
}

.terminal-help .help-code .php-method {
	color: var(--terminal-warning);
}

.terminal-help .help-code .php-operator {
	color: var(--terminal-text);
}

.terminal-help .help-code .php-comment {
	color: #6a9955;
	font-style: italic;
}

.terminal-help .help-code-inline {
	background: var(--terminal-code-bg, #1e1e1e);
	padding: 4px 8px;
	border-radius: 4px;
	border-left: 3px solid #ce9178;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 12px;
	display: inline-block;
	margin-left: 8px;
	line-height: 1.4;
}

.terminal-help .help-code-inline .php-keyword {
	color: #569cd6;
	font-weight: 600;
}

.terminal-help .help-code-inline .php-string {
	color: #ce9178;
}

.terminal-help .help-code-inline .php-number {
	color: #b5cea8;
}

.terminal-help .help-code-inline .php-variable {
	color: #9cdcfe;
}

.terminal-help .help-code-inline .php-class {
	color: #4ec9b0;
	font-weight: 500;
}

.terminal-help .help-code-inline .php-method {
	color: #dcdcaa;
}

.terminal-help .help-code-inline .php-operator {
	color: var(--terminal-text, #d4d4d4);
}

.terminal-help .help-code-inline .php-comment {
	color: #6a9955;
	font-style: italic;
}

.terminal-help .help-models-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.terminal-help .help-models-row {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	line-height: 1.8;
}

.terminal-help .help-model {
	color: #9cdcfe;
	font-weight: 500;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 13px;
	padding: 6px 12px;
	background: rgba(156, 220, 254, 0.15);
	border: 1px solid rgba(156, 220, 254, 0.3);
	border-radius: 4px;
	display: inline-block;
	transition: all 0.2s ease;
}

.terminal-help .help-model:hover {
	background: rgba(156, 220, 254, 0.25);
	border-color: rgba(156, 220, 254, 0.5);
}

.terminal-help .help-facade {
	color: #4ec9b0;
	font-weight: 600;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
}

.terminal-help .help-highlight {
	color: #ffd700;
	font-weight: bold;
}

.terminal-help .help-tips-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-tip-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 14px 16px;
	background: rgba(181, 206, 168, 0.1);
	border-radius: 6px;
	border-left: 4px solid #b5cea8;
	line-height: 1.6;
}

.terminal-help .help-tip-icon {
	color: #b5cea8;
	font-weight: bold;
	font-size: 16px;
	flex-shrink: 0;
	margin-top: 2px;
}

.terminal-help .help-tip-text {
	color: #b5cea8;
	font-weight: 500;
	flex: 1;
	font-size: 14px;
	line-height: 1.6;
}

.terminal-help .help-features-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-feature-card {
	padding: 16px;
	background: rgba(79, 195, 247, 0.1);
	border: 1px solid rgba(79, 195, 247, 0.25);
	border-radius: 8px;
	transition: all 0.2s ease;
}

.terminal-help .help-feature-card:hover {
	background: rgba(79, 195, 247, 0.15);
	border-color: rgba(79, 195, 247, 0.5);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(79, 195, 247, 0.2);
}

.terminal-help .help-feature-title {
	color: #4fc3f7;
	font-weight: 600;
	font-size: 14px;
	margin-bottom: 6px;
	line-height: 1.4;
}

.terminal-help .help-feature-desc {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.5;
}

.terminal-help .help-features-list {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin-top: 16px;
}

.terminal-help .help-feature-item {
	padding: 14px 16px;
	background: rgba(79, 195, 247, 0.08);
	border-left: 4px solid #4fc3f7;
	border-radius: 6px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	line-height: 1.5;
}

.terminal-help .help-feature-name {
	color: #4fc3f7;
	font-weight: 600;
	font-size: 14px;
	line-height: 1.4;
}

.terminal-help .help-feature-detail {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.5;
}

.terminal-help .help-notes-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-note-item {
	padding: 14px 16px;
	background: rgba(244, 135, 113, 0.1);
	border-left: 4px solid #f48771;
	border-radius: 6px;
	line-height: 1.6;
}

.terminal-help .help-note-category {
	color: #f48771;
	font-weight: 600;
	font-size: 14px;
	margin-bottom: 8px;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-note-text {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.6;
}

.terminal-help .help-footer {
	margin-top: 40px;
	padding-top: 24px;
	border-top: 2px solid #3e3e42;
	text-align: center;
}

.terminal-help .help-footer-text {
	color: #4ec9b0;
	font-size: 16px;
	font-weight: 600;
	line-height: 1.4;
}
</style>

<!-- Non-scoped styles for v-html help content -->
<style>
/* Help Content Styling - Modern Design (Non-scoped for v-html) */
.terminal-help {
	color: var(--terminal-text);
	font-family: var(--terminal-font-family, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif);
	font-size: var(--terminal-font-size-md, 14px);
	line-height: var(--terminal-line-height, 1.6);
	padding: 20px;
}

.terminal-help .help-content {
	padding: 0;
	max-width: 100%;
}

.terminal-help .help-header {
	margin-bottom: 32px;
	padding-bottom: 20px;
	border-bottom: 2px solid var(--terminal-border);
}

.terminal-help .help-title {
	font-size: 24px;
	font-weight: 700;
	color: #4ec9b0;
	margin-bottom: 8px;
	display: block;
	line-height: 1.3;
}

.terminal-help .help-subtitle {
	font-size: 14px;
	color: #858585;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-section {
	margin-bottom: 40px;
	padding-bottom: 24px;
	border-bottom: 1px solid rgba(62, 62, 66, 0.5);
}

.terminal-help .help-section:last-of-type {
	border-bottom: none;
}

.terminal-help .help-section-header {
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 18px;
	font-weight: 600;
	color: #4fc3f7;
	margin-bottom: 12px;
	line-height: 1.4;
}

.terminal-help .help-section-icon {
	font-size: 20px;
}

.terminal-help .help-section-desc {
	color: var(--terminal-text, #d4d4d4);
	margin-bottom: 16px;
	font-size: 14px;
	line-height: 1.5;
}

.terminal-help .help-subsection {
	margin-bottom: 28px;
	margin-left: 0;
	padding-left: 0;
}

.terminal-help .help-subsection-title {
	color: #4ec9b0;
	font-weight: 600;
	font-size: 15px;
	margin-bottom: 12px;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-code-block {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-left: 20px;
	margin-top: 8px;
}

.terminal-help .help-code {
	background: var(--terminal-code-bg, #1e1e1e);
	padding: 12px 16px;
	border-radius: 6px;
	border-left: 4px solid #ce9178;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 13px;
	white-space: pre-wrap;
	word-wrap: break-word;
	display: block;
	line-height: 1.6;
	overflow-x: auto;
	box-shadow: 0 2px 8px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	margin: 0;
	color: var(--terminal-text, #d4d4d4);
}

/* PHP Syntax Highlighting Colors */
.terminal-help .help-code .php-keyword {
	color: #569cd6;
	font-weight: 600;
}

.terminal-help .help-code .php-string {
	color: #ce9178;
}

.terminal-help .help-code .php-number {
	color: #b5cea8;
}

.terminal-help .help-code .php-variable {
	color: #9cdcfe;
}

.terminal-help .help-code .php-class {
	color: var(--terminal-accent);
	font-weight: 500;
}

.terminal-help .help-code .php-method {
	color: var(--terminal-warning);
}

.terminal-help .help-code .php-operator {
	color: var(--terminal-text);
}

.terminal-help .help-code .php-comment {
	color: #6a9955;
	font-style: italic;
}

.terminal-help .help-code-inline {
	background: var(--terminal-code-bg, #1e1e1e);
	padding: 4px 8px;
	border-radius: 4px;
	border-left: 3px solid #ce9178;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 12px;
	display: inline-block;
	margin-left: 8px;
	line-height: 1.4;
}

.terminal-help .help-code-inline .php-keyword {
	color: #569cd6;
	font-weight: 600;
}

.terminal-help .help-code-inline .php-string {
	color: #ce9178;
}

.terminal-help .help-code-inline .php-number {
	color: #b5cea8;
}

.terminal-help .help-code-inline .php-variable {
	color: #9cdcfe;
}

.terminal-help .help-code-inline .php-class {
	color: #4ec9b0;
	font-weight: 500;
}

.terminal-help .help-code-inline .php-method {
	color: #dcdcaa;
}

.terminal-help .help-code-inline .php-operator {
	color: var(--terminal-text, #d4d4d4);
}

.terminal-help .help-code-inline .php-comment {
	color: #6a9955;
	font-style: italic;
}

.terminal-help .help-models-container {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.terminal-help .help-models-row {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	line-height: 1.8;
}

.terminal-help .help-model {
	color: #9cdcfe;
	font-weight: 500;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 13px;
	padding: 6px 12px;
	background: rgba(156, 220, 254, 0.15);
	border: 1px solid rgba(156, 220, 254, 0.3);
	border-radius: 4px;
	display: inline-block;
	transition: all 0.2s ease;
}

.terminal-help .help-model:hover {
	background: rgba(156, 220, 254, 0.25);
	border-color: rgba(156, 220, 254, 0.5);
}

.terminal-help .help-facade {
	color: #4ec9b0;
	font-weight: 600;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
}

.terminal-help .help-highlight {
	color: #ffd700;
	font-weight: bold;
}

.terminal-help .help-tips-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-tip-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 14px 16px;
	background: rgba(181, 206, 168, 0.1);
	border-radius: 6px;
	border-left: 4px solid #b5cea8;
	line-height: 1.6;
}

.terminal-help .help-tip-icon {
	color: #b5cea8;
	font-weight: bold;
	font-size: 16px;
	flex-shrink: 0;
	margin-top: 2px;
}

.terminal-help .help-tip-text {
	color: #b5cea8;
	font-weight: 500;
	flex: 1;
	font-size: 14px;
	line-height: 1.6;
}

.terminal-help .help-features-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-feature-card {
	padding: 16px;
	background: rgba(79, 195, 247, 0.1);
	border: 1px solid rgba(79, 195, 247, 0.25);
	border-radius: 8px;
	transition: all 0.2s ease;
}

.terminal-help .help-feature-card:hover {
	background: rgba(79, 195, 247, 0.15);
	border-color: rgba(79, 195, 247, 0.5);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(79, 195, 247, 0.2);
}

.terminal-help .help-feature-title {
	color: #4fc3f7;
	font-weight: 600;
	font-size: 14px;
	margin-bottom: 6px;
	line-height: 1.4;
}

.terminal-help .help-feature-desc {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.5;
}

.terminal-help .help-features-list {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin-top: 16px;
}

.terminal-help .help-feature-item {
	padding: 14px 16px;
	background: rgba(79, 195, 247, 0.08);
	border-left: 4px solid #4fc3f7;
	border-radius: 6px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	line-height: 1.5;
}

.terminal-help .help-feature-name {
	color: #4fc3f7;
	font-weight: 600;
	font-size: 14px;
	line-height: 1.4;
}

.terminal-help .help-feature-detail {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.5;
}

.terminal-help .help-notes-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 16px;
}

.terminal-help .help-note-item {
	padding: 14px 16px;
	background: rgba(244, 135, 113, 0.1);
	border-left: 4px solid #f48771;
	border-radius: 6px;
	line-height: 1.6;
}

.terminal-help .help-note-category {
	color: #f48771;
	font-weight: 600;
	font-size: 14px;
	margin-bottom: 8px;
	display: block;
	line-height: 1.4;
}

.terminal-help .help-note-text {
	color: var(--terminal-text, #d4d4d4);
	font-size: 13px;
	line-height: 1.6;
}

.terminal-help .help-footer {
	margin-top: 40px;
	padding-top: 24px;
	border-top: 2px solid #3e3e42;
	text-align: center;
}

.terminal-help .help-footer-text {
	color: #4ec9b0;
	font-size: 16px;
	font-weight: 600;
	line-height: 1.4;
}
</style>

