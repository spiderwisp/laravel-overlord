<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	navigateTo: {
		type: Object,
		default: null,
	},
});

const emit = defineEmits(['close', 'insert-command', 'execute-command', 'create-issue']);

// State
const loadingLogs = ref(false);
const loadingContent = ref(false);
const availableLogs = ref({});
const selectedLogFile = ref(null);
const logLines = ref([]);
const searchQuery = ref('');
const logLevelFilter = ref('all');
const autoRefresh = ref(false);
const totalLines = ref(0);
const currentOffset = ref(0);
const hasMore = ref(false);
const fileSize = ref(0);
const logStats = ref(null);
const logViewerRef = ref(null);
const isScrolledToBottom = ref(true);

// AI state
const hoveredLineIndex = ref(null);
const expandedAiLineIndex = ref(null);
const aiQuestion = ref({});
const isAsking = ref({});
const aiResponse = ref({});
const aiError = ref({});
const conversationHistory = ref({});
const conversationMessages = ref({});
const surroundingLines = ref({});

// Load available log files
async function loadLogFiles() {
	if (loadingLogs.value) return;
	
	loadingLogs.value = true;
	try {
		const response = await axios.get(api.logs.list());
		
		if (response.data && response.data.success) {
			availableLogs.value = response.data.result || {};
			
			// Auto-select first available log if none selected
			if (!selectedLogFile.value && Object.keys(availableLogs.value).length > 0) {
				const firstType = Object.keys(availableLogs.value)[0];
				if (availableLogs.value[firstType] && availableLogs.value[firstType].length > 0) {
					selectedLogFile.value = availableLogs.value[firstType][0].path;
				}
			}
		} else {
			availableLogs.value = {};
		}
	} catch (error) {
		console.error('Failed to load log files:', error);
		availableLogs.value = {};
	} finally {
		loadingLogs.value = false;
	}
}

// Load log content
async function loadLogContent(resetOffset = false) {
	if (!selectedLogFile.value || loadingContent.value) return;
	
	if (resetOffset) {
		currentOffset.value = 0;
	}
	
	loadingContent.value = true;
	try {
		const params = {
			file: selectedLogFile.value,
			lines: 500,
			offset: currentOffset.value,
		};
		
		if (searchQuery.value.trim()) {
			params.search = searchQuery.value.trim();
		}
		
		const response = await axios.get(api.logs.content(params));
		if (response.data && response.data.success && response.data.result) {
			const result = response.data.result;
			
			if (resetOffset) {
				logLines.value = result.lines || [];
			} else {
				// Prepend older lines
				logLines.value = [...(result.lines || []), ...logLines.value];
			}
			
			totalLines.value = result.total_lines || 0;
			hasMore.value = result.has_more || false;
			fileSize.value = result.file_size || 0;
			
			// Auto-scroll to bottom if user was at bottom
			if (isScrolledToBottom.value) {
				nextTick(() => {
					scrollToBottom();
				});
			}
		}
	} catch (error) {
		console.error('Failed to load log content:', error);
		logLines.value = [];
	} finally {
		loadingContent.value = false;
	}
}

// Load log statistics
async function loadLogStats() {
	if (!selectedLogFile.value) return;
	
	try {
		const response = await axios.get(api.logs.stats({ file: selectedLogFile.value }));
		if (response.data && response.data.success && response.data.result) {
			logStats.value = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load log stats:', error);
		logStats.value = null;
	}
}

// Search logs
async function searchLogs() {
	if (!selectedLogFile.value || loadingContent.value) return;
	
	loadingContent.value = true;
	try {
		const params = {
			file: selectedLogFile.value,
		};
		
		if (searchQuery.value.trim()) {
			params.query = searchQuery.value.trim();
		}
		
		if (logLevelFilter.value !== 'all') {
			params.level = logLevelFilter.value;
		}
		
		const response = await axios.get(api.logs.search(params));
		if (response.data && response.data.success && response.data.result) {
			logLines.value = response.data.result.results || [];
			totalLines.value = response.data.result.count || 0;
			hasMore.value = false;
		}
	} catch (error) {
		console.error('Failed to search logs:', error);
		logLines.value = [];
	} finally {
		loadingContent.value = false;
	}
}

// Handle log file selection
function selectLogFile(logPath) {
	selectedLogFile.value = logPath;
	currentOffset.value = 0;
	logLines.value = [];
	searchQuery.value = '';
	logLevelFilter.value = 'all';
	loadLogContent(true);
	loadLogStats();
}

// Handle search
function handleSearch() {
	if (searchQuery.value.trim() || logLevelFilter.value !== 'all') {
		searchLogs();
	} else {
		loadLogContent(true);
	}
}

// Clear filters
function clearFilters() {
	searchQuery.value = '';
	logLevelFilter.value = 'all';
	loadLogContent(true);
}

// Auto-refresh interval
let autoRefreshInterval = null;

function startAutoRefresh() {
	if (autoRefreshInterval) return;
	
	autoRefreshInterval = setInterval(() => {
		if (selectedLogFile.value && !loadingContent.value) {
			// Only refresh if we're at the bottom (showing latest logs)
			if (isScrolledToBottom.value) {
				loadLogContent(false);
			}
		}
	}, 3000); // Refresh every 3 seconds
}

function stopAutoRefresh() {
	if (autoRefreshInterval) {
		clearInterval(autoRefreshInterval);
		autoRefreshInterval = null;
	}
}

// Scroll handling
function handleScroll() {
	if (!logViewerRef.value) return;
	
	const { scrollTop, scrollHeight, clientHeight } = logViewerRef.value;
	isScrolledToBottom.value = scrollHeight - scrollTop - clientHeight < 50;
	
	// Load more when scrolling to top
	if (scrollTop < 100 && hasMore.value && !loadingContent.value) {
		currentOffset.value += 500;
		loadLogContent(false);
	}
}

function scrollToTop() {
	if (logViewerRef.value) {
		logViewerRef.value.scrollTop = 0;
	}
}

function scrollToBottom() {
	if (logViewerRef.value) {
		logViewerRef.value.scrollTo({
			top: logViewerRef.value.scrollHeight,
			behavior: 'smooth',
		});
		isScrolledToBottom.value = true;
	}
}

// Copy functionality
function copyToClipboard(text) {
	navigator.clipboard.writeText(text).then(() => {
		// Could show a toast notification here
	}).catch(err => {
		console.error('Failed to copy:', err);
	});
}

function copyAll() {
	const allText = logLines.value.map(line => line.content).join('\n');
	copyToClipboard(allText);
}

function copySelected() {
	const selected = window.getSelection().toString();
	if (selected) {
		copyToClipboard(selected);
	}
}

// Format log level
function getLogLevelClass(level) {
	if (!level) return '';
	
	const levelUpper = level.toUpperCase();
	if (levelUpper.includes('ERROR') || levelUpper.includes('CRITICAL') || levelUpper.includes('ALERT') || levelUpper.includes('EMERGENCY')) {
		return 'log-level-error';
	} else if (levelUpper.includes('WARNING') || levelUpper.includes('WARN')) {
		return 'log-level-warning';
	} else if (levelUpper.includes('INFO')) {
		return 'log-level-info';
	} else if (levelUpper.includes('DEBUG')) {
		return 'log-level-debug';
	}
	return '';
}

// Format file size
function formatFileSize(bytes) {
	if (bytes === 0) return '0 B';
	const k = 1024;
	const sizes = ['B', 'KB', 'MB', 'GB'];
	const i = Math.floor(Math.log(bytes) / Math.log(k));
	return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// Format number
function formatNumber(num) {
	return new Intl.NumberFormat().format(num || 0);
}

// Escape HTML
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}

// Check if line should show AI icon (show on all lines with a parsed level)
function shouldShowAiIcon(line) {
	if (!line) {
		return false;
	}
	if (!line.parsed) {
		return false;
	}
	const level = line.parsed.level;
	// Show icon if the line has a parsed level (ERROR, WARNING, INFO, DEBUG, etc.)
	return !!level;
}

// Handle line hover
function handleLineHover(index) {
	const line = logLines.value[index];
	if (shouldShowAiIcon(line)) {
		hoveredLineIndex.value = index;
	}
}

function handleLineLeave() {
	hoveredLineIndex.value = null;
}

// Toggle AI panel
function toggleAiPanel(index) {
	if (expandedAiLineIndex.value === index) {
		expandedAiLineIndex.value = null;
		// Reset state for this line
		const key = `${index}`;
		aiQuestion.value[key] = '';
		aiResponse.value[key] = null;
		aiError.value[key] = null;
		conversationHistory.value[key] = [];
		conversationMessages.value[key] = [];
	} else {
		expandedAiLineIndex.value = index;
		// Initialize state for this line
		const key = `${index}`;
		if (!aiQuestion.value[key]) {
			aiQuestion.value[key] = '';
		}
		if (!conversationHistory.value[key]) {
			conversationHistory.value[key] = [];
		}
		if (!conversationMessages.value[key]) {
			conversationMessages.value[key] = [];
		}
		// Load surrounding lines
		loadSurroundingLines(index);
		// Focus input after panel opens
		nextTick(() => {
			const input = document.querySelector(`.terminal-logs-ai-input-${index}`);
			if (input) {
				input.focus();
			}
		});
	}
}

// Load surrounding lines for context
async function loadSurroundingLines(index) {
	const line = logLines.value[index];
	if (!line || !selectedLogFile.value) return;
	
	const key = `${index}`;
	if (surroundingLines.value[key]) {
		return; // Already loaded
	}
	
	try {
		const response = await axios.get(api.logs.surrounding({
			file: selectedLogFile.value,
			line_number: line.line_number,
			context_lines: 5,
		}));
		
		if (response.data && response.data.success) {
			surroundingLines.value[key] = response.data.result;
		}
	} catch (error) {
		console.error('Failed to load surrounding lines:', error);
	}
}

// Debug this error (quick action)
async function debugThisError(index) {
	const line = logLines.value[index];
	if (!line) return;
	
	const errorMessage = line.parsed?.exception_message || line.parsed?.message || line.content;
	const defaultMessage = `Debug this error: ${errorMessage}`;
	
	setAiQuestion(index, defaultMessage);
	await askAiAboutError(index);
}

// Ask AI about error
async function askAiAboutError(index) {
	const key = `${index}`;
	const question = getAiQuestion(index);
	
	if (!question || !question.trim() || getIsAsking(index)) {
		return;
	}
	
	const userQuestion = question.trim();
	setAiQuestion(index, '');
	
	// Add user message to conversation
	if (!conversationMessages.value[key]) {
		conversationMessages.value[key] = [];
	}
	conversationMessages.value[key].push({
		role: 'user',
		content: userQuestion,
		timestamp: new Date(),
	});
	
	// Add to conversation history
	if (!conversationHistory.value[key]) {
		conversationHistory.value[key] = [];
	}
	conversationHistory.value[key].push({
		role: 'user',
		content: userQuestion,
	});
	
	isAsking.value[key] = true;
	aiError.value[key] = null;
	aiResponse.value[key] = null;
	
	try {
		const line = logLines.value[index];
		const surrounding = surroundingLines.value[key];
		
		// Build context message
		let contextMessage = '';
		
		if (conversationHistory.value[key].length === 1) {
			// First message - include error context
			contextMessage = `I found this error in my log file:\n\n`;
			contextMessage += `Log File: ${selectedLogFile.value}\n`;
			contextMessage += `Line Number: ${line.line_number}\n`;
			contextMessage += `Log Level: ${line.parsed?.level || 'UNKNOWN'}\n`;
			if (line.parsed?.timestamp) {
				contextMessage += `Timestamp: ${line.parsed.timestamp}\n`;
			}
			contextMessage += `\nError Line:\n\`\`\`\n${line.content}\n\`\`\`\n`;
			
			// Add parsed error details
			if (line.parsed?.exception_class) {
				contextMessage += `\nException Class: ${line.parsed.exception_class}\n`;
			}
			if (line.parsed?.exception_message) {
				contextMessage += `Exception Message: ${line.parsed.exception_message}\n`;
			}
			if (line.parsed?.file) {
				contextMessage += `File: ${line.parsed.file}\n`;
			}
			if (line.parsed?.line) {
				contextMessage += `Line: ${line.parsed.line}\n`;
			}
			if (line.parsed?.related_classes && line.parsed.related_classes.length > 0) {
				contextMessage += `Related Classes: ${line.parsed.related_classes.join(', ')}\n`;
			}
			
			// Add surrounding lines if available
			if (surrounding && surrounding.lines && surrounding.lines.length > 0) {
				contextMessage += `\nSurrounding Context (${surrounding.start_line}-${surrounding.end_line}):\n\`\`\`\n`;
				surrounding.lines.forEach(surroundingLine => {
					const prefix = surroundingLine.is_target ? '>>> ' : '    ';
					contextMessage += `${prefix}${surroundingLine.line_number}: ${surroundingLine.content}\n`;
				});
				contextMessage += '```\n';
			}
			
			contextMessage += `\nMy question: ${userQuestion}`;
		} else {
			// Follow-up message
			contextMessage = userQuestion;
		}
		
		// Build request payload
		const requestPayload = {
			message: contextMessage,
			log_context: {
				file: selectedLogFile.value,
				line_number: line.line_number,
				error_line: line.content,
				parsed: line.parsed,
				surrounding_lines: surrounding?.lines || [],
			},
		};
		
		// Include conversation history if available
		const history = conversationHistory.value[key].slice(-10);
		if (history.length > 0) {
			requestPayload.conversation_history = history;
		}
		
		const response = await axios.post(api.ai.chat(), requestPayload);
		
		if (response.data && response.data.success) {
			const aiMessage = response.data.result?.message || response.data.message || 'No response from AI';
			aiResponse.value[key] = aiMessage;
			
			// Add AI response to conversation
			conversationMessages.value[key].push({
				role: 'assistant',
				content: aiMessage,
				timestamp: new Date(),
			});
			
			// Add to conversation history
			conversationHistory.value[key].push({
				role: 'assistant',
				content: aiMessage,
			});
		} else {
			aiError.value[key] = response.data?.error || response.data?.errors?.[0] || 'Failed to get AI response';
		}
	} catch (error) {
		console.error('Failed to ask AI about error:', error);
		aiError.value[key] = error.response?.data?.error || error.message || 'Failed to communicate with AI';
	} finally {
		isAsking.value[key] = false;
	}
}

// Parse AI response segments (text and code blocks)
function parseResponseSegments(text) {
	if (!text) return [];
	
	const segments = [];
	const codeBlockRegex = /```(\w+)?[\s\n]*([\s\S]*?)```/g;
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
		let code = match[2].trim();
		
		// Normalize indentation
		if (code) {
			const lines = code.split('\n');
			if (lines.length > 1) {
				// Find minimum indentation (excluding first line)
				let minIndent = Infinity;
				for (let i = 1; i < lines.length; i++) {
					if (lines[i].trim()) {
						const indent = lines[i].match(/^\s*/)[0].length;
						minIndent = Math.min(minIndent, indent);
					}
				}
				
				// Remove common indentation
				if (minIndent > 0 && minIndent < Infinity) {
					for (let i = 1; i < lines.length; i++) {
						lines[i] = lines[i].substring(minIndent);
					}
				}
				code = lines.join('\n');
			}
		}
		
		// Apply syntax highlighting
		const language = match[1] || null;
		const highlightedCode = highlightCode(code, language);
		
		segments.push({
			type: 'code',
			language: language || 'text',
			code: code,
			highlighted: highlightedCode,
		});
		
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
	
	return segments.length > 0 ? segments : [{ type: 'text', content: text }];
}

// Format text content (headings, bold, inline code, paragraphs)
function formatTextContent(text) {
	if (!text) return '';
	
	// Convert headings
	text = text.replace(/^### (.+)$/gm, '<h3 class="terminal-logs-ai-heading">$1</h3>');
	text = text.replace(/^## (.+)$/gm, '<h2 class="terminal-logs-ai-heading">$1</h2>');
	text = text.replace(/^# (.+)$/gm, '<h1 class="terminal-logs-ai-heading">$1</h1>');
	
	// Convert inline code
	text = text.replace(/`([^`]+)`/g, '<code class="terminal-logs-ai-inline-code">$1</code>');
	
	// Convert bold
	text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
	
	// Convert line breaks to paragraphs
	const paragraphs = text.split(/\n\n+/).filter(p => p.trim());
	return paragraphs.map(p => `<p class="terminal-logs-ai-paragraph">${p.trim().replace(/\n/g, '<br>')}</p>`).join('');
}

// Insert code into terminal
function insertCode(code, index) {
	if (!code) {
		return;
	}
	try {
		emit('insert-command', code);
	} catch (error) {
		console.error('TerminalLogs: Error emitting insert-command:', error);
	}
}

// Execute code
function executeCode(code, index) {
	if (!code) {
		return;
	}
	try {
		emit('execute-command', code);
	} catch (error) {
		console.error('TerminalLogs: Error emitting execute-command:', error);
	}
}

// Create issue from log line
function createIssueFromLog(index) {
	const line = logLines.value[index];
	if (!line) return;
	
	const parsed = line.parsed || {};
	const errorMessage = parsed.message || parsed.exception_message || line.content || 'Error in log';
	const exceptionClass = parsed.exception_class || '';
	const file = parsed.file || '';
	const lineNumber = parsed.line || line.line_number || null;
	
	// Build title
	let title = `Error in ${selectedLogFile.value || 'log'}`;
	if (lineNumber) {
		title += ` at line ${lineNumber}`;
	}
	if (exceptionClass) {
		title += `: ${exceptionClass}`;
	}
	
	// Build description
	let description = errorMessage;
	if (exceptionClass) {
		description = `${exceptionClass}: ${errorMessage}`;
	}
	if (file && lineNumber) {
		description += `\n\nFile: ${file}\nLine: ${lineNumber}`;
	}
	if (line.content) {
		description += `\n\nError Line:\n${line.content}`;
	}
	
	// Get surrounding lines if available
	if (surroundingLines.value[`${index}`]) {
		const surrounding = surroundingLines.value[`${index}`];
		if (surrounding.length > 0) {
			description += `\n\nSurrounding Context:\n${surrounding.map(l => l.content).join('\n')}`;
		}
	}
	
	// Build source data
	const sourceData = {
		file: selectedLogFile.value,
		line_number: line.line_number,
		error_message: errorMessage,
		exception_class: exceptionClass,
		file_path: file,
		error_line: line.content,
		parsed: parsed,
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: 'high',
		source_type: 'log',
		source_id: `${selectedLogFile.value}:${line.line_number}`,
		source_data: sourceData,
	});
}

// Helper functions to get reactive state for a specific line
function getAiQuestion(index) {
	const key = `${index}`;
	return aiQuestion.value[key] || '';
}

function setAiQuestion(index, value) {
	const key = `${index}`;
	if (!aiQuestion.value[key]) {
		aiQuestion.value[key] = '';
	}
	aiQuestion.value[key] = value;
}

function getIsAsking(index) {
	const key = `${index}`;
	return isAsking.value[key] || false;
}

function getAiResponse(index) {
	const key = `${index}`;
	return aiResponse.value[key] || null;
}

function getAiError(index) {
	const key = `${index}`;
	return aiError.value[key] || null;
}

function getConversationMessages(index) {
	const key = `${index}`;
	return conversationMessages.value[key] || [];
}

// Watch for visibility changes
watch(() => props.visible, (newValue) => {
	if (newValue) {
		loadLogFiles();
		if (selectedLogFile.value) {
			loadLogContent(true);
			loadLogStats();
		}
	} else {
		stopAutoRefresh();
	}
});

// Watch auto-refresh
watch(autoRefresh, (newValue) => {
	if (newValue) {
		startAutoRefresh();
	} else {
		stopAutoRefresh();
	}
});

// Watch selected log file
watch(selectedLogFile, (newValue) => {
	if (newValue && props.visible) {
		loadLogContent(true);
		loadLogStats();
	}
});

// Watch for navigation requests
watch(() => props.navigateTo, (navData) => {
	if (navData && navData.type === 'log' && navData.data) {
		const { file, line_number } = navData.data;
		if (file) {
			selectLogFile(file);
			nextTick(() => {
				// Scroll to line after content loads
				setTimeout(() => {
					scrollToLine(line_number);
				}, 500);
			});
		}
	}
}, { immediate: true });

// Scroll to specific line number
function scrollToLine(lineNumber) {
	if (!lineNumber || !logViewerRef.value) return;
	
	// Find the line element
	const lineElement = logViewerRef.value.querySelector(`[data-line-number="${lineNumber}"]`);
	if (lineElement) {
		lineElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
		// Highlight the line temporarily
		lineElement.classList.add('terminal-logs-line-highlighted');
		setTimeout(() => {
			lineElement.classList.remove('terminal-logs-line-highlighted');
		}, 2000);
	} else {
		// Line might not be loaded yet, try loading content around that line
		if (selectedLogFile.value) {
			loadLogContentAroundLine(lineNumber);
		}
	}
}

// Load log content around a specific line number
async function loadLogContentAroundLine(lineNumber) {
	if (!selectedLogFile.value || loadingContent.value) return;
	
	loadingContent.value = true;
	try {
		// Calculate offset to load content around the line
		const linesBefore = 250;
		const offset = Math.max(0, lineNumber - linesBefore);
		
		const response = await axios.get(api.logs.content({
			file: selectedLogFile.value,
			lines: 500,
			offset: offset,
		}));
		
		if (response.data && response.data.success && response.data.result) {
			logLines.value = response.data.result.lines || [];
			currentOffset.value = offset;
			totalLines.value = response.data.result.total_lines || 0;
			hasMore.value = response.data.result.has_more || false;
			
			// Scroll to line after content loads
			nextTick(() => {
				scrollToLine(lineNumber);
			});
		}
	} catch (error) {
		console.error('Failed to load log content:', error);
	} finally {
		loadingContent.value = false;
	}
}

onMounted(() => {
	if (props.visible) {
		loadLogFiles();
	}
});

onUnmounted(() => {
	stopAutoRefresh();
});
</script>

<template>
	<div v-if="visible" class="terminal-logs">
		<div class="terminal-logs-header">
			<div class="terminal-logs-title">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
				</svg>
				<span>Logs</span>
			</div>
			<div class="terminal-logs-controls">
				<button
					@click="loadLogFiles"
					class="terminal-btn terminal-btn-secondary"
					:disabled="loadingLogs"
					title="Refresh log list"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
					</svg>
				</button>
				<button
					@click="$emit('close')"
					class="terminal-btn terminal-btn-close"
					title="Close Logs"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>

		<div class="terminal-logs-content">
			<!-- Sidebar: Log file selector -->
			<div class="terminal-logs-sidebar">
				<div class="terminal-logs-sidebar-header">
					<h3>Log Files</h3>
				</div>
				<div class="terminal-logs-sidebar-content">
					<div v-if="loadingLogs" class="terminal-logs-loading">
						<span class="spinner"></span>
						Loading logs...
					</div>
					<div v-else-if="Object.keys(availableLogs).length === 0" class="terminal-logs-empty">
						<p>No log files found</p>
					</div>
					<div v-else class="terminal-logs-file-list">
						<div
							v-for="(logType, typeName) in availableLogs"
							:key="typeName"
							class="terminal-logs-type-group"
						>
							<div class="terminal-logs-type-header">
								{{ typeName.charAt(0).toUpperCase() + typeName.slice(1) }} Logs
							</div>
							<div
								v-for="logFile in logType"
								:key="logFile.path"
								:class="['terminal-logs-file-item', { 'active': selectedLogFile === logFile.path }]"
								@click="selectLogFile(logFile.path)"
							>
								<div class="terminal-logs-file-name">{{ logFile.name }}</div>
								<div class="terminal-logs-file-meta">
									{{ formatFileSize(logFile.size) }} • {{ logFile.last_modified }}
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Main content: Log viewer -->
			<div class="terminal-logs-main">
				<!-- Toolbar -->
				<div class="terminal-logs-toolbar">
					<div class="terminal-logs-toolbar-left">
						<input
							v-model="searchQuery"
							@keyup.enter="handleSearch"
							type="text"
							placeholder="Search logs..."
							class="terminal-logs-search"
						/>
						<select
							v-model="logLevelFilter"
							@change="handleSearch"
							class="terminal-logs-level-filter"
						>
							<option value="all">All Levels</option>
							<option value="ERROR">ERROR</option>
							<option value="WARNING">WARNING</option>
							<option value="INFO">INFO</option>
							<option value="DEBUG">DEBUG</option>
						</select>
						<button
							@click="clearFilters"
							class="terminal-btn terminal-btn-secondary"
							:disabled="!searchQuery && logLevelFilter === 'all'"
							title="Clear filters"
						>
							Clear
						</button>
					</div>
					<div class="terminal-logs-toolbar-right">
						<button
							@click="autoRefresh = !autoRefresh"
							:class="['terminal-btn', 'terminal-btn-secondary', { 'terminal-btn-active': autoRefresh }]"
							title="Auto-refresh"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
							</svg>
							Auto
						</button>
						<button
							@click="loadLogContent(true)"
							class="terminal-btn terminal-btn-secondary"
							:disabled="loadingContent || !selectedLogFile"
							title="Refresh"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
							</svg>
						</button>
						<button
							@click="scrollToTop"
							class="terminal-btn terminal-btn-secondary"
							title="Jump to top"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
							</svg>
						</button>
						<button
							@click="scrollToBottom"
							class="terminal-btn terminal-btn-secondary"
							title="Jump to bottom"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
							</svg>
						</button>
						<button
							@click="copyAll"
							class="terminal-btn terminal-btn-secondary"
							:disabled="logLines.length === 0"
							title="Copy all"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
							</svg>
						</button>
					</div>
				</div>

				<!-- Stats bar -->
				<div v-if="logStats" class="terminal-logs-stats">
					<span>Lines: {{ formatNumber(totalLines) }}</span>
					<span>Size: {{ formatFileSize(fileSize) }}</span>
					<span>Errors: <span class="log-stat-error">{{ formatNumber(logStats.error_count) }}</span></span>
					<span>Warnings: <span class="log-stat-warning">{{ formatNumber(logStats.warning_count) }}</span></span>
					<span>Info: <span class="log-stat-info">{{ formatNumber(logStats.info_count) }}</span></span>
					<span>Debug: <span class="log-stat-debug">{{ formatNumber(logStats.debug_count) }}</span></span>
				</div>

				<!-- Log viewer -->
				<div
					ref="logViewerRef"
					class="terminal-logs-viewer"
					@scroll="handleScroll"
					@copy="copySelected"
				>
					<div v-if="loadingContent && logLines.length === 0" class="terminal-logs-loading">
						<span class="spinner"></span>
						Loading log content...
					</div>
					<div v-else-if="logLines.length === 0" class="terminal-logs-empty">
						<p v-if="searchQuery || logLevelFilter !== 'all'">No log entries match your filters</p>
						<p v-else-if="selectedLogFile">Log file is empty or could not be read</p>
						<p v-else>No log entries found</p>
					</div>
					<div v-else class="terminal-logs-lines">
						<template v-for="(line, index) in logLines" :key="`${line.line_number}-${index}`">
							<div
								:class="['terminal-logs-line', getLogLevelClass(line.parsed?.level)]"
								:data-line-number="line.line_number"
								@mouseenter="handleLineHover(index)"
								@mouseleave="handleLineLeave"
							>
								<span class="terminal-logs-line-number">{{ line.line_number }}</span>
								<span class="terminal-logs-line-content">{{ line.content }}</span>
								<div v-if="hoveredLineIndex === index && shouldShowAiIcon(line)" class="terminal-logs-line-actions">
									<button
										@click.stop="createIssueFromLog(index)"
										class="terminal-logs-action-icon terminal-logs-issue-icon"
										title="Create Issue"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
										</svg>
									</button>
									<button
										@click.stop="toggleAiPanel(index)"
										class="terminal-logs-action-icon terminal-logs-ai-icon"
										title="Ask AI about this error"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
										</svg>
									</button>
								</div>
							</div>
							
							<!-- AI Panel -->
							<div
								v-if="expandedAiLineIndex === index"
								class="terminal-logs-ai-panel"
							>
								<div class="terminal-logs-ai-panel-header">
									<span>Ask AI about this error</span>
									<button
										@click="toggleAiPanel(index)"
										class="terminal-logs-ai-close"
										title="Close"
									>
										<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
											<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
										</svg>
									</button>
								</div>
								
								<!-- Conversation History -->
								<div v-if="getConversationMessages(index).length > 0" class="terminal-logs-ai-conversation">
									<div
										v-for="(msg, msgIndex) in getConversationMessages(index)"
										:key="msgIndex"
										:class="['terminal-logs-ai-message', `terminal-logs-ai-message-${msg.role}`]"
									>
										<div class="terminal-logs-ai-message-role">{{ msg.role === 'user' ? 'You' : 'AI' }}</div>
										<div class="terminal-logs-ai-message-content">
											<template v-if="msg.role === 'assistant'">
												<template v-for="(segment, segIndex) in parseResponseSegments(msg.content)" :key="segIndex">
													<div v-if="segment.type === 'text'" class="terminal-logs-ai-text" v-html="formatTextContent(segment.content)"></div>
													<div v-else-if="segment.type === 'code'" class="terminal-logs-ai-code-block-wrapper">
														<pre class="terminal-logs-ai-code-block"><code :class="`hljs language-${segment.language}`" v-html="segment.highlighted || escapeHtml(segment.code)"></code></pre>
														<div class="terminal-logs-ai-code-block-actions">
															<button @click="insertCode(segment.code, index)" class="terminal-btn terminal-btn-secondary">Insert</button>
															<button @click="executeCode(segment.code, index)" class="terminal-btn terminal-btn-secondary">Execute</button>
														</div>
													</div>
												</template>
											</template>
											<template v-else>
												{{ msg.content }}
											</template>
										</div>
									</div>
								</div>
								
								<!-- Quick Action Button -->
								<div v-if="getConversationMessages(index).length === 0" class="terminal-logs-ai-quick-action">
									<button
										@click="debugThisError(index)"
										:disabled="getIsAsking(index)"
										class="terminal-btn terminal-btn-primary"
									>
										Debug this
									</button>
								</div>
								
								<!-- Input Area -->
								<div class="terminal-logs-ai-input-area">
									<input
										:class="`terminal-logs-ai-input terminal-logs-ai-input-${index}`"
										:value="getAiQuestion(index)"
										@input="setAiQuestion(index, $event.target.value)"
										@keyup.enter="askAiAboutError(index)"
										:disabled="getIsAsking(index)"
										placeholder="Ask AI about this error..."
										type="text"
									/>
									<button
										@click="askAiAboutError(index)"
										:disabled="!getAiQuestion(index)?.trim() || getIsAsking(index)"
										class="terminal-btn terminal-btn-primary"
									>
										<span v-if="getIsAsking(index)" class="spinner"></span>
										<span v-else>Send</span>
									</button>
								</div>
								
								<!-- Error Display -->
								<div v-if="getAiError(index)" class="terminal-logs-ai-error">
									{{ getAiError(index) }}
								</div>
							</div>
						</template>
						<div v-if="hasMore && loadingContent" class="terminal-logs-loading-more">
							<span class="spinner"></span>
							Loading more...
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-logs {
	display: flex;
	flex-direction: column;
	height: 100%;
	background: var(--terminal-bg);
	color: var(--terminal-text);
	overflow: hidden;
}

.terminal-logs-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
}

.terminal-logs-title {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--terminal-text);
	font-weight: 600;
	font-size: var(--terminal-font-size-md, 14px);
}

.terminal-logs-title svg {
	width: 20px;
	height: 20px;
}

.terminal-logs-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-logs-content {
	display: flex;
	flex: 1;
	overflow: hidden;
}

.terminal-logs-sidebar {
	width: 300px;
	min-width: 300px;
	background: var(--terminal-bg-secondary);
	border-right: 1px solid var(--terminal-border);
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-logs-sidebar-header {
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
}

.terminal-logs-sidebar-header h3 {
	color: var(--terminal-text);
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
	margin: 0;
	text-transform: uppercase;
}

.terminal-logs-sidebar-content {
	flex: 1;
	overflow-y: auto;
	padding: 8px;
}

.terminal-logs-file-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.terminal-logs-type-group {
	margin-bottom: 16px;
}

.terminal-logs-type-header {
	padding: 8px 12px;
	color: var(--terminal-text-secondary);
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
	text-transform: uppercase;
	margin-bottom: 4px;
}

.terminal-logs-file-item {
	padding: 8px 12px;
	margin-bottom: 2px;
	border-radius: 4px;
	cursor: pointer;
	transition: background 0.2s;
}

.terminal-logs-file-item:hover {
	background: var(--terminal-bg-tertiary);
}

.terminal-logs-file-item.active {
	background: var(--terminal-bg-tertiary);
	border-left: 3px solid var(--terminal-primary);
}

.terminal-logs-file-name {
	color: var(--terminal-text);
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	margin-bottom: 4px;
}

.terminal-logs-file-meta {
	color: var(--terminal-text-secondary);
	font-size: var(--terminal-font-size-xs, 10px);
}

.terminal-logs-main {
	flex: 1;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.terminal-logs-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
	gap: 12px;
}

.terminal-logs-toolbar-left {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
}

.terminal-logs-toolbar-right {
	display: flex;
	align-items: center;
	gap: 8px;
}

.terminal-logs-search {
	flex: 1;
	max-width: 300px;
	font-size: var(--terminal-font-size-sm, 12px);
	padding: 6px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
}

.terminal-logs-search:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-logs-search::placeholder {
	color: var(--terminal-text-muted);
}

.terminal-logs-level-filter {
	font-size: var(--terminal-font-size-sm, 12px);
	padding: 6px 12px;
	background: var(--terminal-bg);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	color: var(--terminal-text);
	cursor: pointer;
}

.terminal-logs-level-filter:focus {
	outline: none;
	border-color: var(--terminal-primary);
}

.terminal-logs-stats {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--terminal-border);
	background: var(--terminal-bg-secondary);
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary);
}

.terminal-logs-stats span {
	display: flex;
	align-items: center;
	gap: 4px;
}

.log-stat-error {
	color: var(--terminal-error);
}

.log-stat-warning {
	color: var(--terminal-warning);
}

.log-stat-info {
	color: var(--terminal-info);
}

.log-stat-debug {
	color: var(--terminal-text-muted);
}

.terminal-logs-viewer {
	flex: 1;
	overflow-y: auto;
	overflow-x: auto;
	background: var(--terminal-bg);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', 'Courier New', monospace);
	font-size: var(--terminal-font-size-sm, 12px);
	line-height: var(--terminal-line-height, 1.5);
}

.terminal-logs-lines {
	display: flex;
	flex-direction: column;
}

.terminal-logs-line {
	display: flex;
	padding: 2px 16px;
	border-bottom: 1px solid var(--terminal-bg-secondary);
	transition: background 0.1s;
	position: relative; /* Required for absolute positioning of AI icon */
}

.terminal-logs-line:hover {
	background: var(--terminal-bg-secondary);
}

.terminal-logs-line-highlighted {
	background: color-mix(in srgb, var(--terminal-accent) 20%, transparent) !important;
	border-left: 3px solid var(--terminal-accent) !important;
	animation: highlight-pulse 2s ease-in-out;
}

@keyframes highlight-pulse {
	0%, 100% {
		background: color-mix(in srgb, var(--terminal-accent) 20%, transparent);
	}
	50% {
		background: color-mix(in srgb, var(--terminal-accent) 30%, transparent);
	}
}

.terminal-logs-line.log-level-error {
	background: color-mix(in srgb, var(--terminal-error) 10%, transparent);
	border-left: 3px solid var(--terminal-error);
}

.terminal-logs-line.log-level-warning {
	background: color-mix(in srgb, var(--terminal-warning) 10%, transparent);
	border-left: 3px solid var(--terminal-warning);
}

.terminal-logs-line.log-level-info {
	background: color-mix(in srgb, var(--terminal-info) 5%, transparent);
	border-left: 3px solid var(--terminal-info);
}

.terminal-logs-line.log-level-debug {
	background: color-mix(in srgb, var(--terminal-text-muted) 5%, transparent);
	border-left: 3px solid var(--terminal-text-muted);
}

.terminal-logs-line-number {
	min-width: 60px;
	padding-right: 12px;
	color: var(--terminal-text-secondary);
	text-align: right;
	user-select: none;
	font-size: var(--terminal-font-size-xs, 11px);
}

.terminal-logs-line-content {
	flex: 1;
	white-space: pre-wrap;
	word-break: break-word;
	color: var(--terminal-text);
}

.terminal-logs-loading,
.terminal-logs-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px;
	color: var(--terminal-text-secondary);
	gap: 12px;
}

.terminal-logs-loading-more {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 20px;
	color: var(--terminal-text-secondary);
	gap: 12px;
}

.spinner {
	width: 16px;
	height: 16px;
	border: 2px solid var(--terminal-border);
	border-top-color: var(--terminal-primary);
	border-radius: 50%;
	animation: spin 0.8s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.terminal-btn {
	padding: 6px 12px;
	font-size: var(--terminal-font-size-sm, 12px);
	border: 1px solid var(--terminal-border);
	border-radius: 4px;
	background: var(--terminal-bg);
	color: var(--terminal-text);
	cursor: pointer;
	transition: all 0.2s;
	display: flex;
	align-items: center;
	gap: 6px;
}

.terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary);
	border-color: var(--terminal-primary);
}

.terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg);
}

.terminal-btn-active {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: #ffffff;
}

.terminal-btn-close {
	background: transparent;
	border: none;
	padding: 4px;
}

.terminal-btn-close:hover {
	background: color-mix(in srgb, var(--terminal-text) 10%, transparent);
}

.terminal-btn svg {
	width: 16px;
	height: 16px;
}

.terminal-btn-primary {
	background: var(--terminal-primary);
	border-color: var(--terminal-primary);
	color: #ffffff;
}

.terminal-btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover);
	border-color: var(--terminal-primary-hover);
}

/* Action icons on hover */
.terminal-logs-line-actions {
	position: absolute;
	right: 12px;
	top: 50%;
	transform: translateY(-50%);
	display: flex;
	gap: 4px;
	z-index: 10;
}

.terminal-logs-action-icon {
	background: rgba(0, 122, 204, 0.1);
	border: 1px solid #007acc;
	border-radius: 4px;
	padding: 4px 8px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: all 0.2s;
}

.terminal-logs-action-icon:hover {
	background: rgba(0, 122, 204, 0.2);
	border-color: #0098ff;
}

.terminal-logs-action-icon svg {
	width: 16px;
	height: 16px;
	color: #007acc;
}

.terminal-logs-issue-icon {
	background: rgba(244, 135, 113, 0.1);
	border-color: #f48771;
}

.terminal-logs-issue-icon:hover {
	background: rgba(244, 135, 113, 0.2);
	border-color: #ff9d8a;
}

.terminal-logs-issue-icon svg {
	color: #f48771;
}

.terminal-logs-line {
	position: relative;
}

/* AI Panel */
.terminal-logs-ai-panel {
	margin: 0 16px 8px 76px;
	padding: 12px;
	background: #252526;
	border: 1px solid #3e3e42;
	border-radius: 4px;
	border-left: 3px solid #007acc;
	animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
	from {
		opacity: 0;
		transform: translateY(-10px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}

.terminal-logs-ai-panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid #3e3e42;
}

.terminal-logs-ai-panel-header span {
	color: #d4d4d4;
	font-size: 12px;
	font-weight: 600;
}

.terminal-logs-ai-close {
	background: transparent;
	border: none;
	padding: 4px;
	cursor: pointer;
	color: #858585;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: color 0.2s;
}

.terminal-logs-ai-close:hover {
	color: #d4d4d4;
}

.terminal-logs-ai-close svg {
	width: 16px;
	height: 16px;
}

/* Quick Action */
.terminal-logs-ai-quick-action {
	margin-bottom: 12px;
}

/* Conversation */
.terminal-logs-ai-conversation {
	margin-bottom: 12px;
	max-height: 300px;
	overflow-y: auto;
}

.terminal-logs-ai-message {
	margin-bottom: 12px;
	padding: 8px;
	background: #1e1e1e;
	border-radius: 4px;
}

.terminal-logs-ai-message-user {
	border-left: 3px solid #007acc;
}

.terminal-logs-ai-message-assistant {
	border-left: 3px solid #4ec9b0;
}

.terminal-logs-ai-message-role {
	font-size: 10px;
	font-weight: 600;
	color: #858585;
	margin-bottom: 4px;
	text-transform: uppercase;
}

.terminal-logs-ai-message-content {
	color: #d4d4d4;
	font-size: 12px;
	line-height: 1.5;
}

/* AI Text Formatting */
.terminal-logs-ai-text {
	margin-bottom: 8px;
}

.terminal-logs-ai-heading {
	color: #d4d4d4;
	font-weight: 600;
	margin: 12px 0 8px 0;
}

.terminal-logs-ai-heading h1 {
	font-size: 16px;
	margin: 0;
}

.terminal-logs-ai-heading h2 {
	font-size: 14px;
	margin: 0;
}

.terminal-logs-ai-heading h3 {
	font-size: 13px;
	margin: 0;
}

.terminal-logs-ai-paragraph {
	margin: 8px 0;
	color: #d4d4d4;
	line-height: 1.6;
}

.terminal-logs-ai-inline-code {
	background: #1e1e1e;
	border: 1px solid #3e3e42;
	border-radius: 3px;
	padding: 2px 6px;
	font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
	font-size: 11px;
	color: #dcdcaa;
}

/* Code Blocks */
.terminal-logs-ai-code-block-wrapper {
	margin: 12px 0;
}

.terminal-logs-ai-code-block {
	background: #1e1e1e;
	border: 1px solid #3e3e42;
	border-radius: 4px;
	padding: 12px;
	margin: 0;
	overflow-x: auto;
}

.terminal-logs-ai-code-block code {
	background: transparent;
	padding: 0;
	border: none;
	border-radius: 0;
	display: block;
	white-space: pre;
	overflow-x: auto;
	font-size: 12px;
	line-height: 1.5;
	color: #d4d4d4;
}

.terminal-logs-ai-code-block-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

/* Input Area */
.terminal-logs-ai-input-area {
	display: flex;
	gap: 8px;
	margin-bottom: 8px;
}

.terminal-logs-ai-input {
	flex: 1;
	padding: 8px 12px;
	background: #1e1e1e;
	border: 1px solid #3e3e42;
	border-radius: 4px;
	color: #d4d4d4;
	font-size: 12px;
}

.terminal-logs-ai-input:focus {
	outline: none;
	border-color: #007acc;
}

.terminal-logs-ai-input::placeholder {
	color: #6b7280;
}

.terminal-logs-ai-input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Error Display */
.terminal-logs-ai-error {
	padding: 8px;
	background: rgba(244, 135, 113, 0.1);
	border: 1px solid #f48771;
	border-radius: 4px;
	color: #f48771;
	font-size: 12px;
	margin-top: 8px;
}
</style>

