<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	hideInput: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['insert-command', 'execute-command', 'close', 'create-issue']);

// State
const messages = ref([]);
const inputMessage = ref('');
const isLoading = ref(false);
const isSending = ref(false);
const selectedModel = ref(null);
const availableModels = ref([]);
const aiStatus = ref(null);
const apiKeyStatus = ref(null);
const conversationHistory = ref([]);
const chatContainerRef = ref(null);
const inputRef = ref(null);
const dismissedQuotaMessages = ref(new Set());
const showApiKeyTooltip = ref(false);

// Load API key status
async function loadApiKeyStatus() {
	try {
		const response = await axios.get(api.ai.apiKeyStatus());
		if (response.data.success) {
			apiKeyStatus.value = response.data;
			
			// If API key is not configured, show message
			if (!response.data.is_configured) {
				messages.value.push({
					id: Date.now() + Math.random(),
					role: 'system',
					content: `⚠️ API key is not configured. Please get an API key from the SaaS dashboard.\n\n[Get API Key](${response.data.get_api_key_url})`,
					timestamp: new Date(),
					isSystem: true,
				});
			}
		}
	} catch (error) {
		console.error('Failed to load API key status:', error);
	}
}

// Load AI status and models
async function loadAiStatus() {
	try {
		const response = await axios.get(api.ai.status());
		if (response.data.success) {
			aiStatus.value = response.data;
			if (response.data.available) {
				loadModels();
			}
		}
	} catch (error) {
		console.error('Failed to load AI status:', error);
		aiStatus.value = {
			enabled: false,
			available: false,
			error: 'AI service is not available',
		};
	}
}

// Load available models from SaaS (for display only - model is SaaS-controlled)
async function loadModels() {
	try {
		const response = await axios.get(api.ai.models());
		if (response.data.success && response.data.available) {
			availableModels.value = response.data.models || [];
			// Display current model (read-only, from SaaS)
			if (response.data.default_model) {
				selectedModel.value = response.data.default_model;
			}
		}
	} catch (error) {
		console.error('Failed to load models:', error);
	}
}

// Send message to AI
async function sendMessage() {
	if (!inputMessage.value.trim() || isSending.value) {
		return;
	}
	
	// Check if API key is configured
	if (apiKeyStatus.value && !apiKeyStatus.value.is_configured) {
		messages.value.push({
			id: Date.now() + Math.random(),
			role: 'assistant',
			content: `⚠️ API key is not configured. Please get an API key from the SaaS dashboard.\n\n[Get API Key](${apiKeyStatus.value.get_api_key_url || '#'})`,
			timestamp: new Date(),
			isError: true,
		});
		return;
	}

	const userMessage = inputMessage.value.trim();
	inputMessage.value = '';
	
	// Add user message to chat
	messages.value.push({
		id: Date.now() + Math.random(),
		role: 'user',
		content: userMessage,
		timestamp: new Date(),
	});

	// Add to conversation history
	conversationHistory.value.push({
		role: 'user',
		content: userMessage,
	});

	isSending.value = true;
	scrollToBottom();

	try {
		const response = await axios.post(api.ai.chat(), {
			message: userMessage,
			// Model is SaaS-controlled - do not send it
			conversation_history: conversationHistory.value.slice(-10), // Last 10 messages
		});

		if (response.data.success) {
			let aiMessage = response.data.message;
			
			// Check if quota was exceeded and system is using fallback
			const quotaExceeded = response.data.quota_exceeded || response.data.using_fallback;
			
			// If quota exceeded, enhance the message with actionable links
			if (quotaExceeded && apiKeyStatus.value) {
				const apiKeyUrl = apiKeyStatus.value.get_api_key_url || '#';
				// Extract the base URL for the dashboard (remove /api-keys from the end)
				const baseUrl = apiKeyUrl.replace(/\/api-keys.*$/, '') || apiKeyUrl;
				const billingUrl = `${baseUrl}/billing`;
				
				if (!apiKeyStatus.value.is_configured) {
					// User doesn't have API key configured
					aiMessage = aiMessage.replace(
						'**Note:** If you don\'t have an API key configured, you\'ll need to get one from your SaaS dashboard first.',
						`**Note:** You don't have an API key configured. [Get your API key here](${apiKeyUrl}) to start using the AI service.`
					);
				} else {
					// User has API key but quota exceeded - they need to subscribe
					aiMessage = aiMessage.replace(
						'- Subscribe to a higher tier plan to increase your monthly quota',
						`- [Subscribe to a higher tier plan](${billingUrl}) to increase your monthly quota`
					);
				}
			}
			
			// Add AI response to chat
			const messageId = Date.now() + Math.random();
			messages.value.push({
				id: messageId,
				role: 'assistant',
				content: aiMessage,
				timestamp: new Date(),
				quotaExceeded: quotaExceeded,
			});

			// Add to conversation history
			conversationHistory.value.push({
				role: 'assistant',
				content: aiMessage,
			});
		} else {
			// Handle specific error codes
			const errorCode = response.data.code;
			let errorMessage = response.data.error || 'Unknown error';
			
			if (errorCode === 'INVALID_API_KEY') {
				errorMessage = `Invalid API key. Please check your API key configuration.\n\n[Get API Key](${apiKeyStatus.value?.get_api_key_url || '#'})`;
			} else if (errorCode === 'QUOTA_EXCEEDED') {
				errorMessage = 'Monthly quota exceeded. Please upgrade your plan.';
			} else if (errorCode === 'DECRYPTION_ERROR' || errorCode === 'INVALID_SIGNATURE') {
				errorMessage = 'Security validation failed. Please check your configuration.';
			}
			
			// Add error message
			messages.value.push({
				id: Date.now() + Math.random(),
				role: 'assistant',
				content: `Error: ${errorMessage}`,
				timestamp: new Date(),
				isError: true,
			});
		}
	} catch (error) {
		console.error('Failed to send message:', error);
		const errorCode = error.response?.data?.code;
		let errorMessage = error.response?.data?.error || error.message || 'Failed to communicate with AI';
		
		if (errorCode === 'INVALID_API_KEY') {
			errorMessage = `Invalid API key. Please check your API key configuration.\n\n[Get API Key](${apiKeyStatus.value?.get_api_key_url || '#'})`;
		} else if (errorCode === 'QUOTA_EXCEEDED') {
			errorMessage = 'Monthly quota exceeded. Please upgrade your plan.';
		}
		
		messages.value.push({
			id: Date.now() + Math.random(),
			role: 'assistant',
			content: `Error: ${errorMessage}`,
			timestamp: new Date(),
			isError: true,
		});
	} finally {
		isSending.value = false;
		await nextTick();
		scrollToBottom();
		focusInput();
	}
}

// Extract code blocks from message
function extractCodeBlocks(content) {
	const codeBlockRegex = /```(\w+)?\n([\s\S]*?)```/g;
	const blocks = [];
	let match;
	
	while ((match = codeBlockRegex.exec(content)) !== null) {
		blocks.push({
			language: match[1] || 'text',
			code: match[2].trim(),
		});
	}
	
	return blocks;
}

// Insert code into terminal
function insertCode(code) {
	emit('insert-command', code);
}

// Execute code directly
function executeCode(code) {
	emit('execute-command', code);
}

// Create issue from AI response
function createIssueFromAi(segment, messageIndex) {
	const message = messages.value[messageIndex];
	const question = inputMessage.value || 'AI suggestion';
	const response = message?.content || '';
	const code = segment?.code || '';
	
	// Build title
	let title = 'AI Suggested Fix';
	if (question) {
		const questionPreview = question.length > 50 ? question.substring(0, 50) + '...' : question;
		title = `AI Suggested Fix: ${questionPreview}`;
	}
	
	// Build description
	let description = response;
	if (code) {
		description += `\n\nSuggested Code:\n\`\`\`\n${code}\n\`\`\``;
	}
	if (question) {
		description = `Question: ${question}\n\n${description}`;
	}
	
	// Build source data
	const sourceData = {
		question: question,
		response: response,
		code: code,
		context: 'ai_chat',
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: 'medium',
		source_type: 'ai',
		source_id: `ai_message_${messageIndex}`,
		source_data: sourceData,
	});
}

// Clear conversation
function clearConversation() {
	messages.value = [];
	conversationHistory.value = [];
}

// Scroll to bottom of chat
function scrollToBottom() {
	if (chatContainerRef.value) {
		nextTick(() => {
			chatContainerRef.value.scrollTop = chatContainerRef.value.scrollHeight;
		});
	}
}

// Focus input field
function focusInput() {
	if (inputRef.value) {
		inputRef.value.focus();
	}
}

// Handle Enter key
function handleKeyDown(event) {
	if (event.key === 'Enter' && !event.shiftKey) {
		event.preventDefault();
		sendMessage();
	}
}

// Check if AI is available
const isAiAvailable = computed(() => {
	return aiStatus.value?.enabled && aiStatus.value?.available;
});

// Watch for visibility changes
watch(() => props.visible, (visible) => {
	if (visible) {
		loadAiStatus();
		nextTick(() => {
			focusInput();
			scrollToBottom();
		});
	}
});

// Expose methods for parent component
function addUserMessage(message) {
	messages.value.push({
		id: Date.now() + Math.random(),
		role: 'user',
		content: message,
		timestamp: new Date(),
	});
	conversationHistory.value.push({
		role: 'user',
		content: message,
	});
	scrollToBottom();
}

function addAssistantMessage(message, isError = false) {
	messages.value.push({
		id: Date.now() + Math.random(),
		role: 'assistant',
		content: message,
		timestamp: new Date(),
		isError: isError,
	});
	conversationHistory.value.push({
		role: 'assistant',
		content: message,
	});
	scrollToBottom();
}

defineExpose({
	addUserMessage,
	addAssistantMessage,
});

onMounted(() => {
	if (props.visible) {
		loadApiKeyStatus();
		loadAiStatus();
	}
});

// Parse message content into segments (text and code blocks)
function parseMessageSegments(content) {
	const segments = [];
	// More flexible regex: allows optional language and handles newlines or spaces after ```
	// Updated to handle code blocks that might not have a language tag
	const codeBlockRegex = /```([\w-]+)?[\s\n]*([\s\S]*?)```/g;
	let lastIndex = 0;
	let match;
	
	while ((match = codeBlockRegex.exec(content)) !== null) {
		// Add text before code block
		if (match.index > lastIndex) {
			const textContent = content.substring(lastIndex, match.index);
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
	if (lastIndex < content.length) {
		const textContent = content.substring(lastIndex);
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
			content: content,
		});
	}
	
	return segments;
}

// Format text content (headings, inline code, bold, line breaks, paragraphs)
function formatTextContent(content) {
	if (!content) return '';
	
	// Escape HTML first
	let formatted = content
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
	
	// Markdown headings (###, ##, #)
	formatted = formatted.replace(/^###\s+(.+)$/gm, '<h3 class="terminal-ai-heading">$1</h3>');
	formatted = formatted.replace(/^##\s+(.+)$/gm, '<h2 class="terminal-ai-heading">$1</h2>');
	formatted = formatted.replace(/^#\s+(.+)$/gm, '<h1 class="terminal-ai-heading">$1</h1>');
	
	// Inline code
	formatted = formatted.replace(/`([^`]+)`/g, '<code class="terminal-ai-inline-code">$1</code>');
	
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
		return '<p class="terminal-ai-paragraph">' + withBreaks + '</p>';
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

// Dismiss quota exceeded message
function dismissQuotaMessage(messageId) {
	if (messageId) {
		dismissedQuotaMessages.value.add(messageId);
	}
}
</script>

<template>
	<div v-if="visible" class="terminal-ai-view">
		<div class="terminal-ai-header">
			<div class="terminal-ai-header-left">
				<h3 class="terminal-ai-title">AI Assistant</h3>
				<span v-if="!isAiAvailable" class="terminal-ai-status-badge terminal-ai-status-unavailable">
					Unavailable
				</span>
				<span v-else class="terminal-ai-status-badge terminal-ai-status-available">
					Ready
				</span>
			</div>
			<div class="terminal-ai-header-right">
				<!-- API Key Alert Icon -->
				<div
					v-if="apiKeyStatus && !apiKeyStatus.is_configured"
					class="terminal-ai-api-key-alert"
					@mouseenter="showApiKeyTooltip = true"
					@mouseleave="showApiKeyTooltip = false"
					@click="showApiKeyTooltip = !showApiKeyTooltip"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="terminal-ai-alert-icon">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
					</svg>
					<div v-if="showApiKeyTooltip" class="terminal-ai-tooltip">
						<div class="terminal-ai-tooltip-content">
							<p><strong>API Key Required</strong></p>
							<p>Get your API key from <a href="https://laravel-overlord.com" target="_blank" rel="noopener noreferrer">laravel-overlord.com</a> to enable AI features.</p>
						</div>
					</div>
				</div>
				
				<!-- Current Model (Read-only, from SaaS) -->
				<div
					v-if="isAiAvailable && selectedModel"
					class="terminal-ai-model-display"
					title="Model is controlled by SaaS based on your plan"
				>
					Model: {{ selectedModel }}
				</div>
				
				<!-- Clear Conversation -->
				<button
					@click="clearConversation"
					class="terminal-btn terminal-btn-secondary terminal-btn-icon"
					:disabled="messages.length === 0 || isSending"
					title="Clear conversation"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
					</svg>
				</button>
			</div>
		</div>

		<!-- Chat Messages -->
		<div ref="chatContainerRef" class="terminal-ai-chat">
			<div v-if="messages.length === 0" class="terminal-ai-empty">
				<div class="terminal-ai-empty-icon">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
					</svg>
				</div>
				<p class="terminal-ai-empty-title">Ask me anything about Laravel, PHP, or terminal commands</p>
				<p class="terminal-ai-empty-hint">I can help you write code, explain commands, and suggest solutions.</p>
			</div>

			<div v-else class="terminal-ai-messages">
				<div
					v-for="(message, index) in messages"
					:key="message.id || index"
					v-show="!dismissedQuotaMessages.has(message.id)"
					class="terminal-ai-message"
					:class="{
						'terminal-ai-message-user': message.role === 'user',
						'terminal-ai-message-assistant': message.role === 'assistant',
						'terminal-ai-message-error': message.isError,
						'terminal-ai-message-quota-exceeded': message.quotaExceeded,
					}"
				>
					<div class="terminal-ai-message-content">
						<!-- Dismiss button for quota exceeded messages -->
						<button
							v-if="message.quotaExceeded"
							@click="dismissQuotaMessage(message.id)"
							class="terminal-ai-message-dismiss"
							title="Dismiss this message"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
						<div v-if="message.role === 'user'" class="terminal-ai-message-text">
							{{ message.content }}
						</div>
						<div v-else class="terminal-ai-message-text">
							<template v-for="(segment, segmentIndex) in parseMessageSegments(message.content)" :key="segmentIndex">
								<!-- Text segment -->
								<div v-if="segment.type === 'text'" v-html="formatTextContent(segment.content)"></div>
								
								<!-- Code block segment with buttons below -->
								<div v-else-if="segment.type === 'code'" class="terminal-ai-code-block-wrapper">
									<pre class="terminal-ai-code-block">
										<code :class="`hljs language-${segment.language}`" v-html="segment.highlighted || escapeHtml(segment.code)"></code>
									</pre>
									<div class="terminal-ai-code-block-actions">
										<button
											@click="insertCode(segment.code)"
											class="terminal-ai-action-btn terminal-ai-action-insert"
											title="Insert into terminal"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
											</svg>
											Insert
										</button>
										<button
											@click="executeCode(segment.code)"
											class="terminal-ai-action-btn terminal-ai-action-execute"
											title="Execute directly"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
											</svg>
											Execute
										</button>
										<button
											@click="createIssueFromAi(segment, index)"
											class="terminal-ai-action-btn terminal-ai-action-issue"
											title="Create Issue"
										>
											<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
											</svg>
											Create Issue
										</button>
									</div>
								</div>
							</template>
						</div>
					</div>
				</div>

				<!-- Loading indicator -->
				<div v-if="isSending" class="terminal-ai-message terminal-ai-message-assistant">
					<div class="terminal-ai-message-content">
						<div class="terminal-ai-typing">
							<span></span>
							<span></span>
							<span></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Input Area (hidden when hideInput is true) -->
		<div v-if="!hideInput" class="terminal-ai-input-area">
			<div class="terminal-ai-input-wrapper">
				<textarea
					ref="inputRef"
					v-model="inputMessage"
					@keydown="handleKeyDown"
					:disabled="!isAiAvailable || isSending"
					class="terminal-ai-input"
					placeholder="Ask a question or request code..."
					rows="1"
				></textarea>
				<button
					@click="sendMessage"
					:disabled="!inputMessage.trim() || !isAiAvailable || isSending"
					class="terminal-btn terminal-btn-primary terminal-ai-send-btn"
				>
					<svg v-if="!isSending" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
					</svg>
					<span v-else class="spinner-small"></span>
				</button>
			</div>
			<div v-if="!isAiAvailable" class="terminal-ai-error">
				<p>AI is not available. {{ aiStatus?.error || 'AI features are disabled in configuration.' }}</p>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-ai-view {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
}

.terminal-ai-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	background: var(--terminal-bg-secondary);
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-ai-header-left {
	display: flex;
	align-items: center;
	gap: 12px;
}

.terminal-ai-title {
	font-size: 14px;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	margin: 0;
}

.terminal-ai-status-badge {
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 600;
	text-transform: uppercase;
}

.terminal-ai-status-available {
	background: var(--terminal-success, #10b981);
	color: white;
}

.terminal-ai-status-unavailable {
	background: var(--terminal-error, #f48771);
	color: white;
}

.terminal-ai-header-right {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-ai-model-display {
	color: var(--terminal-text-muted);
	font-size: 12px;
	padding: 4px 8px;
	opacity: 0.7;
}

.terminal-ai-chat {
	flex: 1;
	overflow-y: auto;
	padding: 16px;
	display: flex;
	flex-direction: column;
}

.terminal-ai-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	text-align: center;
	padding: 32px;
}

.terminal-ai-empty-icon {
	color: var(--terminal-text-secondary);
	margin-bottom: 16px;
}

.terminal-ai-empty-icon svg {
	width: 48px !important;
	height: 48px !important;
	max-width: 48px !important;
	max-height: 48px !important;
}

.terminal-ai-empty-title {
	font-size: 16px;
	font-weight: 500;
	color: var(--terminal-text, #d4d4d4);
	margin: 0 0 8px 0;
}

.terminal-ai-empty-hint {
	font-size: 12px;
	color: var(--terminal-text-secondary);
	margin: 0;
}

.terminal-ai-messages {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.terminal-ai-message {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.terminal-ai-message-user {
	align-items: flex-end;
}

.terminal-ai-message-assistant {
	align-items: flex-start;
}

.terminal-ai-message-content {
	max-width: 80%;
	padding: 12px 16px;
	border-radius: 8px;
}

.terminal-ai-message-user .terminal-ai-message-content {
	background: var(--terminal-primary);
	color: white;
}

.terminal-ai-message-assistant .terminal-ai-message-content {
	background: var(--terminal-bg-tertiary);
	color: var(--terminal-text);
}

.terminal-ai-message-error .terminal-ai-message-content {
	background: var(--terminal-error);
	color: white;
}

.terminal-ai-message-quota-exceeded .terminal-ai-message-content {
	background: var(--terminal-warning, #dcdcaa);
	border-left: 4px solid var(--terminal-warning, #dcdcaa);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-ai-message-quota-exceeded .terminal-ai-message-content {
	position: relative;
}

.terminal-ai-message-quota-exceeded .terminal-ai-message-content::before {
	content: "⚠️ ";
	font-size: 16px;
	margin-right: 4px;
}

.terminal-ai-message-dismiss {
	position: absolute;
	top: 8px;
	right: 8px;
	background: color-mix(in srgb, var(--terminal-text) 20%, transparent);
	border: none;
	border-radius: 4px;
	width: 24px;
	height: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--terminal-text);
	padding: 0;
	transition: background 0.2s;
}

.terminal-ai-message-dismiss:hover {
	background: color-mix(in srgb, var(--terminal-text) 30%, transparent);
}

.terminal-ai-message-dismiss svg {
	width: 16px;
	height: 16px;
}

.terminal-ai-message-text {
	font-size: 13px;
	line-height: 1.5;
	word-wrap: break-word;
}

/* API Key Alert Icon */
.terminal-ai-api-key-alert {
	position: relative;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
}

.terminal-ai-alert-icon {
	width: 20px;
	height: 20px;
	color: var(--terminal-warning);
	transition: color 0.2s;
}

.terminal-ai-api-key-alert:hover .terminal-ai-alert-icon {
	color: var(--terminal-warning);
	opacity: 0.8;
}

.terminal-ai-tooltip {
	position: absolute;
	top: 100%;
	right: 0;
	margin-top: 8px;
	z-index: 1000;
	min-width: 250px;
}

.terminal-ai-tooltip-content {
	background: var(--terminal-bg-secondary);
	border: 1px solid var(--terminal-border);
	border-radius: 8px;
	padding: 12px;
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	color: var(--terminal-text);
}

.terminal-ai-tooltip-content p {
	margin: 0 0 8px 0;
	font-size: 13px;
	line-height: 1.5;
}

.terminal-ai-tooltip-content p:last-child {
	margin-bottom: 0;
}

.terminal-ai-tooltip-content a {
	color: var(--terminal-primary);
	text-decoration: underline;
}

.terminal-ai-tooltip-content a:hover {
	color: var(--terminal-primary-hover);
}

.terminal-ai-code-block-wrapper {
	margin: 20px 0 12px 0;
	display: flex;
	flex-direction: column;
}

.terminal-ai-code-block {
	background: var(--terminal-code-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	padding: 12px;
	overflow-x: auto;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 12px;
	margin: 0;
	text-indent: 0;
}

.terminal-ai-code-block code {
	display: block;
	text-indent: 0;
	padding-left: 0;
	margin-left: 0;
	color: var(--terminal-text);
	white-space: pre;
	background: transparent;
	overflow-x: auto;
}

.terminal-ai-code-block-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
	margin-bottom: 20px;
	width: 100%;
}

.terminal-ai-inline-code {
	background: var(--terminal-bg-tertiary);
	border: 1px solid var(--terminal-border);
	border-radius: 3px;
	padding: 2px 6px;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 12px;
	color: var(--terminal-accent);
}

.terminal-ai-message-text :deep(.terminal-ai-heading) {
	color: var(--terminal-accent);
	font-weight: 600;
	margin: 16px 0 8px 0;
	line-height: 1.4;
}

.terminal-ai-message-text :deep(.terminal-ai-heading:first-child) {
	margin-top: 0;
}

.terminal-ai-message-text :deep(h1.terminal-ai-heading) {
	font-size: 18px;
}

.terminal-ai-message-text :deep(h2.terminal-ai-heading) {
	font-size: 16px;
}

.terminal-ai-message-text :deep(h3.terminal-ai-heading) {
	font-size: 14px;
}

.terminal-ai-message-text :deep(.terminal-ai-paragraph) {
	margin: 16px 0 12px 0;
	line-height: 1.6;
	color: var(--terminal-text);
}

.terminal-ai-message-text :deep(.terminal-ai-paragraph:first-child) {
	margin-top: 8px;
}

.terminal-ai-message-text :deep(.terminal-ai-paragraph:last-child) {
	margin-bottom: 0;
}

.terminal-ai-message-text :deep(strong) {
	color: var(--terminal-accent);
	font-weight: 600;
}

.terminal-ai-message-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.terminal-ai-action-btn {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	font-size: 11px;
	border-radius: 4px;
	cursor: pointer;
	border: none;
	transition: all 0.2s;
}

.terminal-ai-action-insert {
	background: #3e3e42;
	color: var(--terminal-text);
}

.terminal-ai-action-insert:hover {
	background: #464647;
}

.terminal-ai-action-execute {
	background: var(--terminal-primary, #0e639c);
	color: white;
}

.terminal-ai-action-execute:hover {
	background: var(--terminal-primary-hover, #1177bb);
}

.terminal-ai-action-issue {
	background: rgba(244, 135, 113, 0.1);
	border: 1px solid #f48771;
	color: #f48771;
}

.terminal-ai-action-issue:hover {
	background: rgba(244, 135, 113, 0.2);
	border-color: #ff9d8a;
}

.terminal-ai-typing {
	display: flex;
	gap: 4px;
	padding: 8px 0;
}

.terminal-ai-typing span {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #858585;
	animation: typing 1.4s infinite;
}

.terminal-ai-typing span:nth-child(2) {
	animation-delay: 0.2s;
}

.terminal-ai-typing span:nth-child(3) {
	animation-delay: 0.4s;
}

@keyframes typing {
	0%, 60%, 100% {
		opacity: 0.3;
		transform: translateY(0);
	}
	30% {
		opacity: 1;
		transform: translateY(-8px);
	}
}

.terminal-ai-input-area {
	padding: 12px 16px;
	background: var(--terminal-bg-secondary, #252526);
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-ai-input-wrapper {
	display: flex;
	gap: 8px;
	align-items: center;
}

.terminal-ai-input {
	flex: 1;
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	padding: 8px 12px;
	font-family: inherit;
	font-size: 13px;
	resize: none;
	min-height: 40px;
	max-height: 120px;
	outline: none;
}

.terminal-ai-input:focus {
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-ai-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-ai-send-btn {
	min-width: 40px;
	min-height: 40px;
	height: 40px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.terminal-ai-error {
	margin-top: 8px;
	padding: 8px 12px;
	background: var(--terminal-error, #f48771);
	border-radius: 4px;
	font-size: 12px;
}

.terminal-ai-error p {
	margin: 4px 0;
	color: white;
}

.terminal-ai-error-hint {
	font-size: 11px;
	opacity: 0.9;
}

.terminal-ai-error-hint code {
	background: var(--terminal-shadow-light, rgba(0, 0, 0, 0.2));
	padding: 2px 6px;
	border-radius: 3px;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
}

.spinner-small {
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border, #3e3e42);
	border-top-color: var(--terminal-primary, #0e639c);
	border-radius: 50%;
	animation: spin 0.6s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

/* Custom Scrollbar Styling */
.terminal-ai-chat::-webkit-scrollbar,
.terminal-ai-code-block::-webkit-scrollbar {
	width: 10px;
}

.terminal-ai-chat::-webkit-scrollbar-track,
.terminal-ai-code-block::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
}

.terminal-ai-chat::-webkit-scrollbar-thumb,
.terminal-ai-code-block::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
}

.terminal-ai-chat::-webkit-scrollbar-thumb:hover,
.terminal-ai-code-block::-webkit-scrollbar-thumb:hover {
	background: #4a4a4a;
}

/* Firefox scrollbar styling */
.terminal-ai-chat,
.terminal-ai-code-block {
	scrollbar-width: thin;
	scrollbar-color: #424242 #1e1e1e;
}
</style>

