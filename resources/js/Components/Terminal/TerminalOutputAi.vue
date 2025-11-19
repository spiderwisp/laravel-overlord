<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import Swal from '../../utils/swalConfig';
import { useOverlordApi } from '../useOverlordApi.js';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const props = defineProps({
	outputItem: {
		type: Object,
		required: true,
	},
	outputHistory: {
		type: Array,
		required: true,
	},
	currentIndex: {
		type: Number,
		required: true,
	},
});

const api = useOverlordApi();

const emit = defineEmits(['insert-command', 'execute-command', 'create-issue']);

const isExpanded = ref(false);
const question = ref('');
const isAsking = ref(false);
const aiResponse = ref(null);
const error = ref(null);
const conversationHistory = ref([]);
const conversationMessages = ref([]);

// Get the output text for context
const outputText = computed(() => {
	if (props.outputItem.type === 'command') {
		return props.outputItem.output;
	} else if (props.outputItem.type === 'json' || props.outputItem.type === 'object') {
		const data = props.outputItem.output?.formatted || props.outputItem.output;
		return typeof data === 'string' ? data : JSON.stringify(data, null, 2);
	} else {
		const output = props.outputItem.output?.formatted || props.outputItem.output || props.outputItem.raw;
		return typeof output === 'string' ? output : String(output);
	}
});

// Find the command that generated this output
const relatedCommand = computed(() => {
	if (!props.outputHistory || props.currentIndex === undefined) {
		return null;
	}
	
	// Look backwards from the current index to find the most recent command
	for (let i = props.currentIndex - 1; i >= 0; i--) {
		const item = props.outputHistory[i];
		if (item && item.type === 'command') {
			return item.output;
		}
	}
	
	return null;
});

// Toggle expansion
function toggleExpanded() {
	isExpanded.value = !isExpanded.value;
	if (!isExpanded.value) {
		// Reset when collapsing
		question.value = '';
		aiResponse.value = null;
		error.value = null;
		conversationHistory.value = [];
		conversationMessages.value = [];
	}
}

// Ask AI about the output
async function askAboutOutput() {
	if (!question.value.trim() || isAsking.value) {
		return;
	}

	const userQuestion = question.value.trim();
	question.value = '';
	
	// Add user message to conversation
	conversationMessages.value.push({
		role: 'user',
		content: userQuestion,
		timestamp: new Date(),
	});
	
	// Add to conversation history
	conversationHistory.value.push({
		role: 'user',
		content: userQuestion,
	});

	isAsking.value = true;
	error.value = null;
	aiResponse.value = null;

	try {
		// Build the message with context about the command and output (only on first message)
		let contextMessage = '';
		
		if (conversationHistory.value.length === 1) {
			// First message - include command and output context
			if (relatedCommand.value) {
				contextMessage = `I ran this command in the terminal:\n\n\`\`\`php\n${relatedCommand.value}\n\`\`\`\n\nAnd got this output:\n\n${outputText.value}\n\nMy question about this output: ${userQuestion}`;
			} else {
				contextMessage = `I just ran a command in the terminal and got this output:\n\n${outputText.value}\n\nMy question about this output: ${userQuestion}`;
			}
		} else {
			// Follow-up message - just use the question
			contextMessage = userQuestion;
		}

		// Build request payload - only include conversation_history if it has items
		const requestPayload = {
			message: contextMessage,
		};
		
		// Only include conversation_history if it has items
		const history = conversationHistory.value.slice(-10);
		if (history.length > 0) {
			requestPayload.conversation_history = history;
		}
		
		const response = await axios.post(api.url('ai/chat'), requestPayload);

		if (response.data && response.data.success) {
			const aiMessage = response.data.result?.message || response.data.message || 'No response from AI';
			aiResponse.value = aiMessage;
			
			// Add AI response to conversation
			conversationMessages.value.push({
				role: 'assistant',
				content: aiMessage,
				timestamp: new Date(),
			});
			
			// Add to conversation history
			conversationHistory.value.push({
				role: 'assistant',
				content: aiMessage,
			});
		} else {
			error.value = response.data?.error || response.data?.errors?.[0] || 'Failed to get AI response';
		}
	} catch (err) {
		error.value = err.response?.data?.error || err.response?.data?.errors?.[0] || err.message || 'Failed to communicate with AI';
		console.error('AI query error:', err);
	} finally {
		isAsking.value = false;
	}
}

// Handle Enter key (Shift+Enter for new line)
function handleKeyDown(event) {
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault();
		askAboutOutput();
	}
}

// Clear response
function clearResponse() {
	aiResponse.value = null;
	error.value = null;
	conversationHistory.value = [];
	conversationMessages.value = [];
	question.value = '';
}

// Extract code blocks from response
function extractCodeBlocks(text) {
	if (!text) {
		return [];
	}
	
	const codeBlockRegex = /```(\w+)?\n([\s\S]*?)```/g;
	const blocks = [];
	let match;
	
	while ((match = codeBlockRegex.exec(text)) !== null) {
		blocks.push({
			language: match[1] || 'text',
			code: match[2].trim(),
		});
	}
	
	return blocks;
}

// Insert code into terminal
function insertCode(code) {
	if (!code) {
		return;
	}
	try {
		emit('insert-command', code);
	} catch (error) {
		console.error('TerminalOutputAi: Error emitting insert-command:', error);
	}
}

// Execute code directly
function executeCode(code) {
	if (!code) {
		return;
	}
	try {
		emit('execute-command', code);
	} catch (error) {
		console.error('TerminalOutputAi: Error emitting execute-command:', error);
	}
}

// Create issue from AI response
function createIssueFromOutputAi(segment) {
	const questionText = question.value || 'AI suggestion';
	const response = aiResponse.value || '';
	const code = segment?.code || '';
	const command = relatedCommand.value || '';
	const output = outputText.value || '';
	
	// Build title
	let title = 'AI Suggested Fix';
	if (questionText) {
		const questionPreview = questionText.length > 50 ? questionText.substring(0, 50) + '...' : questionText;
		title = `AI Suggested Fix: ${questionPreview}`;
	}
	
	// Build description
	let description = response;
	if (code) {
		description += `\n\nSuggested Code:\n\`\`\`\n${code}\n\`\`\``;
	}
	if (questionText) {
		description = `Question: ${questionText}\n\n${description}`;
	}
	if (command) {
		description = `Original Command:\n${command}\n\nOriginal Output:\n${output}\n\n${description}`;
	}
	
	// Build source data
	const sourceData = {
		question: questionText,
		response: response,
		code: code,
		command: command,
		output: output,
		context: 'terminal_output_ai',
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: 'medium',
		source_type: 'ai',
		source_id: `output_ai_${props.currentIndex}`,
		source_data: sourceData,
	});
}

// Parse response into segments (text and code blocks)
function parseResponseSegments(text) {
	if (!text) return [];
	
	const segments = [];
	const codeBlockRegex = /```([\w-]+)?[\s\n]*([\s\S]*?)```/g;
	let lastIndex = 0;
	let match;
	
	while ((match = codeBlockRegex.exec(text)) !== null) {
		// Add text before code block
		if (match.index > lastIndex) {
			const textContent = text.substring(lastIndex, match.index);
			if (textContent.trim()) {
				segments.push({
					type: 'text',
					content: textContent,
				});
			}
		}
		
		// Add code block
		let code = match[2];
		// Remove leading/trailing whitespace and normalize indentation
		if (code) {
			// Decode HTML entities that might have been introduced (e.g., -&gt; should be ->)
			const textarea = document.createElement('textarea');
			textarea.innerHTML = code;
			code = textarea.value;
			
			// Remove all leading/trailing whitespace from the entire block
			code = code.trim();
			
			// Split into lines and process each line
			const lines = code.split('\n');
			const processedLines = [];
			
			// Find first non-empty line index
			const firstNonEmptyIndex = lines.findIndex(line => line.trim().length > 0);
			
			if (firstNonEmptyIndex >= 0) {
				// Process each line
				for (let i = 0; i < lines.length; i++) {
					const line = lines[i];
					
					if (i === firstNonEmptyIndex) {
						// First non-empty line: remove ALL leading whitespace
						processedLines.push(line.replace(/^\s+/, ''));
					} else if (line.trim().length > 0) {
						// Other non-empty lines: find minimum indent from remaining lines
						const remainingNonEmpty = lines.slice(firstNonEmptyIndex + 1).filter(l => l.trim().length > 0);
						if (remainingNonEmpty.length > 0) {
							const indentations = remainingNonEmpty.map(l => {
								const m = l.match(/^(\s*)/);
								return m ? m[1].length : 0;
							});
							const minIndent = Math.min(...indentations);
							
							// Remove minIndent from this line
							if (line.length >= minIndent && line.substring(0, minIndent).match(/^\s*$/)) {
								processedLines.push(line.substring(minIndent));
							} else {
								processedLines.push(line);
							}
						} else {
							processedLines.push(line);
						}
					} else {
						// Empty line: keep as is
						processedLines.push(line);
					}
				}
				
				code = processedLines.join('\n');
			}
			
			// ABSOLUTE FINAL CHECK: Remove any leading whitespace from the very first character
			const firstChar = code.trimStart();
			if (code !== firstChar) {
				code = firstChar;
			}
			
			// One more pass: ensure first line has no leading whitespace
			const finalLines = code.split('\n');
			const firstNonEmpty = finalLines.find(l => l.trim().length > 0);
			if (firstNonEmpty && firstNonEmpty.match(/^\s/)) {
				const idx = finalLines.findIndex(l => l.trim().length > 0);
				if (idx >= 0) {
					finalLines[idx] = finalLines[idx].trimStart();
					code = finalLines.join('\n');
				}
			}
			
			// Apply syntax highlighting
			const language = match[1] || null;
			const highlightedCode = highlightCode(code, language);
			
			segments.push({
				type: 'code',
				language: language || 'text',
				code: code, // Keep original for copy/execute
				highlighted: highlightedCode, // Highlighted version for display
			});
		}
		
		lastIndex = codeBlockRegex.lastIndex;
	}
	
	// Add remaining text
	if (lastIndex < text.length) {
		const textContent = text.substring(lastIndex);
		if (textContent.trim()) {
			segments.push({
				type: 'text',
				content: textContent,
			});
		}
	}
	
	// If no code blocks found, return entire content as text
	if (segments.length === 0) {
		segments.push({
			type: 'text',
			content: text,
		});
	}
	
	return segments;
}

// Format text content (headings, inline code, bold, line breaks, paragraphs)
function formatTextContent(text) {
	if (!text) return '';
	
	// Escape HTML first
	let formatted = text
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
	
	// Markdown headings (###, ##, #)
	formatted = formatted.replace(/^###\s+(.+)$/gm, '<h3 class="terminal-output-ai-heading">$1</h3>');
	formatted = formatted.replace(/^##\s+(.+)$/gm, '<h2 class="terminal-output-ai-heading">$1</h2>');
	formatted = formatted.replace(/^#\s+(.+)$/gm, '<h1 class="terminal-output-ai-heading">$1</h1>');
	
	// Inline code
	formatted = formatted.replace(/`([^`]+)`/g, '<code class="terminal-output-ai-code-inline">$1</code>');
	
	// Bold
	formatted = formatted.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
	
	// Convert double line breaks to paragraph breaks, single line breaks to <br>
	// Split by double line breaks first
	const parts = formatted.split(/\n\n+/);
	const processedParts = parts.map(part => {
		// If part starts with a heading, don't wrap in paragraph
		if (part.trim().match(/^<h[1-3]/)) {
			return part.replace(/\n/g, '');
		}
		// Otherwise, wrap in paragraph and convert single line breaks to <br>
		const withBreaks = part.replace(/\n/g, '<br>');
		return '<p class="terminal-output-ai-paragraph">' + withBreaks + '</p>';
	});
	
	return processedParts.join('');
}

// Normalize indentation by removing common leading whitespace
function normalizeIndentation(code) {
	if (!code) return code;
	
	const lines = code.split('\n');
	if (lines.length === 0) return code;
	
	// Find the minimum indentation (excluding empty lines)
	const nonEmptyLines = lines.filter(line => line.trim().length > 0);
	if (nonEmptyLines.length === 0) return code;
	
	const indentations = nonEmptyLines.map(line => {
		const match = line.match(/^(\s*)/);
		return match ? match[1].length : 0;
	});
	
	const minIndent = Math.min(...indentations);
	
	// Remove the minimum indentation from all lines
	// This ensures at least one line starts at column 0
	return lines.map(line => {
		if (line.trim().length === 0) return line;
		// Remove up to minIndent spaces from the start
		if (line.length >= minIndent) {
			const leading = line.substring(0, minIndent);
			if (leading.match(/^\s*$/)) {
				return line.substring(minIndent);
			}
		}
		return line;
	}).join('\n');
}

// Escape HTML
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}
</script>

<template>
	<div class="terminal-output-ai">
		<!-- Toggle Button -->
		<button
			@click.stop="toggleExpanded"
			class="terminal-output-ai-toggle"
			:class="{ 'expanded': isExpanded }"
			title="Ask AI about this output"
		>
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
			</svg>
			<span>Ask AI</span>
		</button>

		<!-- Expanded Panel -->
		<div v-if="isExpanded" class="terminal-output-ai-panel">
			<div class="terminal-output-ai-header">
				<span class="terminal-output-ai-title">Ask AI about this output</span>
				<button @click="toggleExpanded" class="terminal-output-ai-close" title="Close">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>

			<!-- Question Input -->
			<div class="terminal-output-ai-input-container">
				<textarea
					v-model="question"
					@keydown="handleKeyDown"
					class="terminal-output-ai-input"
					:placeholder="conversationMessages.length > 0 ? 'Continue the conversation... (Press Enter to send, Shift+Enter for new line)' : 'Ask a question about this output... (Press Enter to send, Shift+Enter for new line)'"
					rows="2"
					:disabled="isAsking"
				></textarea>
				<button
					@click="askAboutOutput"
					class="terminal-output-ai-send"
					:disabled="!question.trim() || isAsking"
					title="Send question (Enter)"
				>
					<svg v-if="!isAsking" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
					</svg>
					<span v-else class="terminal-output-ai-spinner"></span>
				</button>
			</div>

			<!-- Conversation Messages -->
			<div v-if="conversationMessages.length > 0" class="terminal-output-ai-conversation">
				<div
					v-for="(message, index) in conversationMessages"
					:key="index"
					class="terminal-output-ai-message"
					:class="{
						'terminal-output-ai-message-user': message.role === 'user',
						'terminal-output-ai-message-assistant': message.role === 'assistant',
					}"
				>
					<div v-if="message.role === 'user'" class="terminal-output-ai-message-user-content">
						{{ message.content }}
					</div>
					<div v-else class="terminal-output-ai-message-assistant-content">
						<template v-for="(segment, segmentIndex) in parseResponseSegments(message.content)" :key="segmentIndex">
							<!-- Text segment -->
							<div v-if="segment.type === 'text'" v-html="formatTextContent(segment.content)"></div>
							
							<!-- Code block segment with buttons below -->
							<div v-else-if="segment.type === 'code'" class="terminal-output-ai-code-block-wrapper">
								<pre class="terminal-output-ai-code-block">
									<code :class="`hljs language-${segment.language}`" v-html="segment.highlighted || escapeHtml(segment.code)"></code>
								</pre>
								<div class="terminal-output-ai-code-block-actions">
									<button
										type="button"
										@click.stop="insertCode(segment.code)"
										@mousedown.stop
										class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-insert"
										title="Insert into terminal"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
										</svg>
										<span>Insert</span>
									</button>
									<button
										type="button"
										@click.stop="executeCode(segment.code)"
										@mousedown.stop
										class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-execute"
										title="Execute directly"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
										</svg>
										<span>Execute</span>
									</button>
									<button
										type="button"
										@click.stop="createIssueFromOutputAi(segment)"
										@mousedown.stop
										class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-issue"
										title="Create Issue"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
										</svg>
										<span>Create Issue</span>
									</button>
								</div>
							</div>
						</template>
					</div>
				</div>
			</div>

			<!-- Legacy AI Response (for backward compatibility, show last response) -->
			<div v-if="aiResponse && conversationMessages.length === 0" class="terminal-output-ai-response">
				<div class="terminal-output-ai-response-header">
					<span class="terminal-output-ai-response-label">AI Response</span>
					<button @click="clearResponse" class="terminal-output-ai-clear" title="Clear response">
						<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
						</svg>
					</button>
				</div>
				<div class="terminal-output-ai-response-content">
					<template v-for="(segment, segmentIndex) in parseResponseSegments(aiResponse)" :key="segmentIndex">
						<!-- Text segment -->
						<div v-if="segment.type === 'text'" v-html="formatTextContent(segment.content)"></div>
						
						<!-- Code block segment with buttons below -->
						<div v-else-if="segment.type === 'code'" class="terminal-output-ai-code-block-wrapper">
							<pre class="terminal-output-ai-code-block">
								<code :class="`hljs language-${segment.language}`" v-html="segment.highlighted || escapeHtml(segment.code)"></code>
							</pre>
							<div class="terminal-output-ai-code-block-actions">
								<button
									type="button"
									@click.stop="insertCode(segment.code)"
									@mousedown.stop
									class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-insert"
									title="Insert into terminal"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
									</svg>
									<span>Insert</span>
								</button>
								<button
									type="button"
									@click.stop="executeCode(segment.code)"
									@mousedown.stop
									class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-execute"
									title="Execute directly"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
									</svg>
									<span>Execute</span>
								</button>
								<button
									type="button"
									@click.stop="createIssueFromOutputAi(segment)"
									@mousedown.stop
									class="terminal-output-ai-code-action-btn terminal-output-ai-code-action-issue"
									title="Create Issue"
								>
									<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
									</svg>
									<span>Create Issue</span>
								</button>
							</div>
						</div>
					</template>
				</div>
			</div>

			<!-- Error -->
			<div v-if="error" class="terminal-output-ai-error">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
				</svg>
				<span>{{ error }}</span>
			</div>
		</div>
	</div>
</template>


<style scoped>
.terminal-output-ai {
	margin-top: 8px;
	margin-left: 0; /* Align with output content */
	width: 100%;
	display: block;
	visibility: visible;
	opacity: 1;
}

.terminal-output-ai-toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: transparent;
	border: 1px solid transparent;
	border-radius: 3px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 11px;
	font-weight: 400;
	cursor: pointer;
	transition: all 0.2s ease;
	opacity: 0.6;
}

.terminal-output-ai-toggle:hover {
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 10%, transparent);
	border-color: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 20%, transparent);
	color: var(--terminal-accent, #4ec9b0);
	opacity: 1;
}

.terminal-output-ai-toggle.expanded {
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 15%, transparent);
	border-color: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 30%, transparent);
	color: var(--terminal-accent, #4ec9b0);
	opacity: 1;
}

.terminal-output-ai-toggle svg {
	width: 12px;
	height: 12px;
	opacity: 0.7;
}

.terminal-output-ai-toggle:hover svg {
	opacity: 1;
}

.terminal-output-ai-panel {
	margin-top: 8px;
	background: color-mix(in srgb, var(--terminal-bg, #ffffff) 95%, transparent);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 6px;
	padding: 12px;
	backdrop-filter: blur(4px);
}

.terminal-output-ai-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.terminal-output-ai-title {
	color: var(--terminal-accent, #4ec9b0);
	font-size: 13px;
	font-weight: 600;
}

.terminal-output-ai-close {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	padding: 0;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: color 0.2s ease;
}

.terminal-output-ai-close:hover {
	color: var(--terminal-text, #333333);
}

.terminal-output-ai-close svg {
	width: 14px;
	height: 14px;
}

.terminal-output-ai-input-container {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.terminal-output-ai-input {
	flex: 1;
	padding: 8px 12px;
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text, #333333);
	font-size: 13px;
	font-family: inherit;
	resize: vertical;
	min-height: 40px;
	transition: border-color 0.2s ease;
}

.terminal-output-ai-input:focus {
	outline: none;
	border-color: var(--terminal-accent, #4ec9b0);
}

.terminal-output-ai-input:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.terminal-output-ai-input::placeholder {
	color: var(--terminal-text-secondary, #858585);
}

.terminal-output-ai-send {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	padding: 0;
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 20%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 40%, transparent);
	border-radius: 4px;
	color: var(--terminal-accent, #4ec9b0);
	cursor: pointer;
	transition: all 0.2s ease;
	flex-shrink: 0;
}

.terminal-output-ai-send:hover:not(:disabled) {
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 60%, transparent);
}

.terminal-output-ai-send:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-output-ai-send svg {
	width: 18px;
	height: 18px;
}

.terminal-output-ai-spinner {
	width: 16px;
	height: 16px;
	border: 2px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 30%, transparent);
	border-top-color: var(--terminal-accent, #4ec9b0);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.terminal-output-ai-response {
	margin-top: 12px;
	padding: 12px;
	background: color-mix(in srgb, var(--terminal-bg-secondary, #f5f5f5) 90%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 20%, transparent);
	border-radius: 4px;
	border-left: 3px solid var(--terminal-accent, #4ec9b0);
}

.terminal-output-ai-response-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.terminal-output-ai-response-label {
	color: var(--terminal-accent, #4ec9b0);
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.terminal-output-ai-clear {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	padding: 0;
	background: transparent;
	border: none;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: color 0.2s ease;
}

.terminal-output-ai-clear:hover {
	color: var(--terminal-text, #333333);
}

.terminal-output-ai-clear svg {
	width: 12px;
	height: 12px;
}

.terminal-output-ai-response-content {
	color: var(--terminal-text, #333333);
	font-size: 13px;
	line-height: 1.6;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-code-block) {
	background: var(--terminal-bg-secondary, #f5f5f5);
	padding: 12px;
	border-radius: 4px;
	border-left: 3px solid var(--terminal-accent, #ce9178);
	margin: 8px 0;
	overflow-x: auto;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-code-block code) {
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 12px;
	color: var(--terminal-text, #333333);
	white-space: pre;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-code-inline) {
	background: color-mix(in srgb, var(--terminal-accent, #ce9178) 20%, transparent);
	padding: 2px 6px;
	border-radius: 3px;
	font-family: 'Courier New', 'Monaco', 'Menlo', 'Consolas', monospace;
	font-size: 12px;
	color: var(--terminal-accent, #ce9178);
}

.terminal-output-ai-response-content :deep(strong) {
	color: var(--terminal-accent, #4ec9b0);
	font-weight: 600;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-heading) {
	color: var(--terminal-accent, #4ec9b0);
	font-weight: 600;
	margin: 16px 0 8px 0;
	line-height: 1.4;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-heading:first-child) {
	margin-top: 0;
}

.terminal-output-ai-response-content :deep(h1.terminal-output-ai-heading) {
	font-size: 18px;
}

.terminal-output-ai-response-content :deep(h2.terminal-output-ai-heading) {
	font-size: 16px;
}

.terminal-output-ai-response-content :deep(h3.terminal-output-ai-heading) {
	font-size: 14px;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-paragraph) {
	margin: 16px 0 12px 0;
	line-height: 1.6;
	color: var(--terminal-text, #333333);
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-paragraph:first-child) {
	margin-top: 8px;
}

.terminal-output-ai-response-content :deep(.terminal-output-ai-paragraph:last-child) {
	margin-bottom: 0;
}

.terminal-output-ai-error {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	background: color-mix(in srgb, var(--terminal-error, #f48771) 10%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-error, #f48771) 30%, transparent);
	border-radius: 4px;
	border-left: 3px solid var(--terminal-error, #f48771);
	color: var(--terminal-error, #f48771);
	font-size: 12px;
}

.terminal-output-ai-error svg {
	width: 16px;
	height: 16px;
	flex-shrink: 0;
}

.terminal-output-ai-code-block-wrapper {
	margin: 20px 0 12px 0;
	display: flex;
	flex-direction: column;
}

.terminal-output-ai-code-block {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	padding: 12px;
	overflow-x: auto;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 12px;
	margin: 0;
	text-indent: 0;
}

.terminal-output-ai-code-block code {
	display: block;
	text-indent: 0;
	padding-left: 0;
	margin-left: 0;
	color: var(--terminal-text, #333333);
	white-space: pre;
	background: transparent;
	overflow-x: auto;
}

.terminal-output-ai-code-block-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
	margin-bottom: 20px;
	width: 100%;
}

.terminal-output-ai-code-action-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px 14px;
	border-radius: 4px;
	font-size: 12px;
	font-weight: 500;
	cursor: pointer;
	border: 1px solid transparent;
	transition: all 0.2s ease;
	white-space: nowrap;
	min-width: 80px;
	position: relative;
	z-index: 1000;
	pointer-events: auto;
	-webkit-user-select: none;
	user-select: none;
}

.terminal-output-ai-code-action-btn:hover {
	transform: translateY(-1px);
	box-shadow: 0 2px 4px var(--terminal-shadow-medium, rgba(0, 0, 0, 0.2));
}

.terminal-output-ai-code-action-btn:active {
	transform: translateY(0);
}

.terminal-output-ai-code-action-btn svg {
	width: 14px;
	height: 14px;
}

.terminal-output-ai-code-action-insert {
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 20%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 40%, transparent);
	color: var(--terminal-accent, #4ec9b0);
}

.terminal-output-ai-code-action-insert:hover {
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 60%, transparent);
	color: var(--terminal-accent, #4ec9b0);
}

.terminal-output-ai-code-action-execute {
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 25%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-primary, #0e639c) 50%, transparent);
	color: var(--terminal-primary, #0e639c);
}

.terminal-output-ai-code-action-execute:hover {
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 35%, transparent);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 70%, transparent);
	color: var(--terminal-primary, #0e639c);
}

.terminal-output-ai-code-action-issue {
	background: color-mix(in srgb, var(--terminal-error, #f48771) 10%, transparent);
	border: 1px solid var(--terminal-error, #f48771);
	color: var(--terminal-error, #f48771);
}

.terminal-output-ai-code-action-issue:hover {
	background: color-mix(in srgb, var(--terminal-error, #f48771) 20%, transparent);
	border-color: var(--terminal-error, #f48771);
}

.terminal-output-ai-conversation {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.terminal-output-ai-message {
	display: flex;
	flex-direction: column;
}

.terminal-output-ai-message-user {
	align-items: flex-end;
}

.terminal-output-ai-message-assistant {
	align-items: flex-start;
}

.terminal-output-ai-message-user-content {
	padding: 8px 12px;
	background: color-mix(in srgb, var(--terminal-accent, #4ec9b0) 15%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 30%, transparent);
	border-radius: 8px;
	color: var(--terminal-text, #333333);
	font-size: 13px;
	max-width: 80%;
	word-wrap: break-word;
}

.terminal-output-ai-message-assistant-content {
	padding: 12px;
	background: color-mix(in srgb, var(--terminal-bg-secondary, #f5f5f5) 90%, transparent);
	border: 1px solid color-mix(in srgb, var(--terminal-accent, #4ec9b0) 20%, transparent);
	border-radius: 8px;
	border-left: 3px solid var(--terminal-accent, #4ec9b0);
	max-width: 90%;
	width: 100%;
}
</style>

