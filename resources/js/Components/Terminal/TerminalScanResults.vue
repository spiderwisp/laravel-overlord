<template>
	<div v-if="visible" class="terminal-scan-results">
		<div v-if="!props.scanId" class="terminal-scan-no-scan">
			<p>No scan selected. Please start a scan from the Scan Config pane.</p>
		</div>
		
		<div v-else-if="loading" class="terminal-scan-loading">
			<div class="terminal-scan-progress">
				<div class="terminal-scan-progress-bar" :style="{ width: progress + '%' }"></div>
			</div>
			<p>Scanning codebase... {{ progress }}%</p>
			<p v-if="statusMessage" class="terminal-scan-status" :class="getStatusAnimationClass(statusMessage)">
				<span class="status-indicator" v-if="statusMessage.toLowerCase().includes('thinking')">🧠</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('parsing')">📄</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('reading')">📂</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('analyzing')">🔍</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('compiling')">⚙️</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('saving')">💾</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('organizing') || statusMessage.toLowerCase().includes('discovering')">🔎</span>
				<span class="status-indicator" v-else-if="statusMessage.toLowerCase().includes('handling')">⚡</span>
				{{ statusMessage }}
			</p>
		</div>

		<div v-else-if="error" class="terminal-scan-error">
			<p class="error-message" v-html="formatErrorMessage(error)"></p>
		</div>

		<div v-else-if="results && results.summary" class="terminal-scan-content">
			<!-- Actions Bar -->
			<div class="terminal-scan-actions">
				<button
					@click="clearOldIssues"
					class="terminal-btn terminal-btn-secondary terminal-btn-sm"
					title="Clear old scan issues"
				>
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
					</svg>
					Clear Old Issues
				</button>
			</div>
			
			<!-- Summary Section -->
			<div class="terminal-scan-summary">
				<h3>Scan Summary</h3>
				<div class="terminal-scan-stats">
					<div class="stat-item">
						<span class="stat-label">Total Files:</span>
						<span class="stat-value">{{ results.summary?.total_files || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Files with Issues:</span>
						<span class="stat-value">{{ results.summary?.files_with_issues || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Total Issues:</span>
						<span class="stat-value">{{ results.summary?.total_issues || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Critical:</span>
						<span class="stat-value critical">{{ results.summary?.by_severity?.critical || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">High:</span>
						<span class="stat-value high">{{ results.summary?.by_severity?.high || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Medium:</span>
						<span class="stat-value medium">{{ results.summary?.by_severity?.medium || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Low:</span>
						<span class="stat-value low">{{ results.summary?.by_severity?.low || 0 }}</span>
					</div>
				</div>
			</div>

			<!-- Files List -->
			<div class="terminal-scan-files">
				<h3>Files Analyzed</h3>
				<div v-if="filteredFiles.length === 0" class="no-results">
					<p>No issues found in scanned files.</p>
				</div>
				<div v-else class="files-list">
					<div
						v-for="file in filteredFiles"
						:key="file.file"
						class="file-item"
						:class="{ 'has-issues': file.issues && file.issues.length > 0, 'has-errors': file.has_errors }"
					>
						<div class="file-header" @click="toggleFile(file.file)">
							<span class="file-name">{{ file.file }}</span>
							<span v-if="file.issues && file.issues.length > 0" class="issue-count">
								{{ file.issues.length }} issue{{ file.issues.length !== 1 ? 's' : '' }}
							</span>
							<span v-if="file.has_errors" class="error-badge">Error</span>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="expand-icon"
								:class="{ expanded: expandedFiles.includes(file.file) }"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
							</svg>
						</div>
						<div v-if="expandedFiles.includes(file.file)" class="file-details">
							<div v-if="file.has_errors" class="error-section">
								<p class="error-text">Error analyzing this file</p>
							</div>
							<div v-if="file.issues && file.issues.length > 0" class="issues-section">
								<template v-if="groupIssuesByCategory(file.issues)">
									<div
										v-for="(categoryIssues, category) in groupIssuesByCategory(file.issues)"
										:key="category"
										class="issue-category-group"
									>
										<div
											class="issue-category-header"
											:style="{ borderLeftColor: categoryStyles[category]?.color || '#6b7280' }"
											@click="toggleCategory(file.file, category)"
										>
											<span class="category-icon">{{ categoryStyles[category]?.icon || '📝' }}</span>
											<span class="category-name">{{ categoryNames[category] || category }}</span>
											<span class="category-count">({{ categoryIssues.length }})</span>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="category-expand-icon"
												:class="{ expanded: isCategoryExpanded(file.file, category) }"
												fill="none"
												viewBox="0 0 24 24"
												stroke="currentColor"
											>
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
											</svg>
										</div>
										<div
											v-if="isCategoryExpanded(file.file, category)"
											class="issue-category-content"
										>
											<div
												v-for="(issue, index) in categoryIssues"
												:key="index"
												class="issue-item"
												:class="['severity-' + (issue.severity || 'medium'), { 'resolved': getIssueResolvedStatus(issue, file.file) }]"
											>
												<div class="issue-header">
													<span class="issue-type">{{ issue.type || 'general' }}</span>
													<span v-if="issue.line" class="issue-line">Line {{ issue.line }}</span>
													<span class="issue-severity" :class="'severity-' + (issue.severity || 'medium')">
														{{ issue.severity || 'medium' }}
													</span>
													<span v-if="getIssueResolvedStatus(issue, file.file)" class="resolved-badge">
														Resolved
													</span>
													<div class="issue-actions">
														<button
															v-if="!getIssueResolvedStatus(issue, file.file)"
															@click.stop="resolveIssue(issue, file.file)"
															class="issue-action-btn issue-resolve-btn"
															title="Mark as resolved"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
															</svg>
														</button>
														<button
															v-else
															@click.stop="unresolveIssue(issue, file.file)"
															class="issue-action-btn issue-unresolve-btn"
															title="Mark as unresolved"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
															</svg>
														</button>
														<button
															@click.stop="createIssueFromScan(issue, file.file)"
															class="issue-action-btn issue-create-btn"
															title="Create Issue"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
															</svg>
														</button>
													</div>
												</div>
												<div class="issue-message" v-html="formatIssueMessage(issue.message)"></div>
												<div v-if="issue.code_snippet" class="issue-code-snippet">
													<div class="code-snippet-header">
														<span class="code-snippet-label">Code at line {{ issue.line || 'N/A' }}:</span>
													</div>
													<pre class="issue-code-block"><code class="hljs language-php" v-html="formatCodeSnippet(issue.code_snippet, 'php')"></code></pre>
												</div>
											</div>
										</div>
									</div>
								</template>
								<template v-else>
									<div
										v-for="(issue, index) in file.issues"
										:key="index"
										class="issue-item"
										:class="['severity-' + (issue.severity || 'medium'), { 'resolved': getIssueResolvedStatus(issue, file.file) }]"
									>
										<div class="issue-header">
											<span class="issue-type">{{ issue.type || 'general' }}</span>
											<span v-if="issue.line" class="issue-line">Line {{ issue.line }}</span>
											<span class="issue-severity" :class="'severity-' + (issue.severity || 'medium')">
												{{ issue.severity || 'medium' }}
											</span>
											<span v-if="getIssueResolvedStatus(issue, file.file)" class="resolved-badge">
												Resolved
											</span>
											<div class="issue-actions">
												<button
													v-if="!getIssueResolvedStatus(issue, file.file)"
													@click.stop="resolveIssue(issue, file.file)"
													class="issue-action-btn issue-resolve-btn"
													title="Mark as resolved"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
														<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
													</svg>
												</button>
												<button
													v-else
													@click.stop="unresolveIssue(issue, file.file)"
													class="issue-action-btn issue-unresolve-btn"
													title="Mark as unresolved"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
														<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
													</svg>
												</button>
												<button
													@click.stop="createIssueFromScan(issue, file.file)"
													class="issue-action-btn issue-create-btn"
													title="Create Issue"
												>
													<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
														<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
													</svg>
												</button>
											</div>
										</div>
										<div class="issue-message" v-html="formatIssueMessage(issue.message)"></div>
										<div v-if="issue.code_snippet" class="issue-code-snippet">
											<div class="code-snippet-header">
												<span class="code-snippet-label">Code at line {{ issue.line || 'N/A' }}:</span>
											</div>
											<pre class="issue-code-block"><code class="hljs language-php" v-html="formatCodeSnippet(issue.code_snippet, 'php')"></code></pre>
										</div>
									</div>
								</template>
							</div>
							<div v-else-if="!file.has_errors" class="no-issues">
								<p>No issues found in this file.</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import Swal from '../../utils/swalConfig';
import { useOverlordApi } from '../useOverlordApi';
import { highlightCode } from '../../utils/syntaxHighlight.js';

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
	scanId: {
		type: String,
		default: null,
	},
});

const emit = defineEmits(['close', 'create-issue', 'issues-cleared']);

const api = useOverlordApi();

const loading = ref(false);
const error = ref(null);
const results = ref(null);
const progress = ref(0);
const statusMessage = ref('');
const expandedFiles = ref([]);
const pollingInterval = ref(null);
const databaseIssues = ref([]);
const loadingIssues = ref(false);

const filteredFiles = computed(() => {
	if (!results.value || !results.value.files) {
		return [];
	}
	return results.value.files;
});

// Group issues by category for a file
function groupIssuesByCategory(issues) {
	if (!issues || issues.length === 0) {
		return null;
	}
	
	const grouped = {};
	const uncategorized = [];
	
	issues.forEach(issue => {
		const category = issue.category || 'uncategorized';
		if (category === 'uncategorized' || !category) {
			uncategorized.push(issue);
		} else {
			if (!grouped[category]) {
				grouped[category] = [];
			}
			grouped[category].push(issue);
		}
	});
	
	// Add uncategorized at the end if any
	if (uncategorized.length > 0) {
		grouped.uncategorized = uncategorized;
	}
	
	return Object.keys(grouped).length > 0 ? grouped : null;
}

// Category display names
const categoryNames = {
	security: 'Security Vulnerabilities',
	quality: 'Code Quality Issues',
	performance: 'Performance Improvements',
	best_practices: 'Best Practices Violations',
	bug: 'Bugs and Errors',
	uncategorized: 'Other Issues',
};

// Category icons/colors
const categoryStyles = {
	security: { icon: '🔒', color: '#ef4444' },
	quality: { icon: '📊', color: '#3b82f6' },
	performance: { icon: '⚡', color: '#f59e0b' },
	best_practices: { icon: '✅', color: '#10b981' },
	bug: { icon: '🐛', color: '#ef4444' },
	uncategorized: { icon: '📝', color: '#6b7280' },
};

const expandedCategories = ref({});

function getStatusAnimationClass(message) {
	if (!message) return '';
	const msg = message.toLowerCase();
	if (msg.includes('thinking')) return 'status-thinking';
	if (msg.includes('parsing')) return 'status-parsing';
	if (msg.includes('reading')) return 'status-reading';
	if (msg.includes('analyzing')) return 'status-analyzing';
	if (msg.includes('compiling')) return 'status-compiling';
	if (msg.includes('saving')) return 'status-saving';
	if (msg.includes('organizing') || msg.includes('discovering')) return 'status-discovering';
	if (msg.includes('handling')) return 'status-handling';
	return 'status-default';
}

function formatErrorMessage(errorText) {
	if (!errorText) return '';
	
	// Check if message contains laravel-overlord.com/signin as plain text (not already a link)
	const hasPlainLink = errorText.includes('laravel-overlord.com/signin') && !errorText.includes('<a');
	
	if (hasPlainLink) {
		// Convert plain text URL to clickable link
		return errorText.replace(
			/laravel-overlord\.com\/signin/g,
			'<a href="https://laravel-overlord.com/signin" target="_blank" rel="noopener noreferrer" class="error-link">laravel-overlord.com/signin</a>'
		);
	}
	
	// If already has HTML link or no link, escape HTML to prevent XSS (but preserve existing links)
	// Check if it already contains HTML
	if (errorText.includes('<a') || errorText.includes('<')) {
		// Already has HTML, return as-is (trusted from backend)
		return errorText;
	}
	
	// Plain text, escape HTML
	const div = document.createElement('div');
	div.textContent = errorText;
	return div.innerHTML;
}

function formatIssueMessage(message) {
	if (!message || typeof message !== 'string') {
		return '';
	}
	
	// If already HTML, return as-is (trusted from backend)
	if (message.includes('<') && message.includes('>')) {
		return message;
	}
	
	let formatted = message;
	
	// Escape HTML first to prevent XSS
	const div = document.createElement('div');
	div.textContent = formatted;
	formatted = div.innerHTML;
	
	// Code blocks (```language\ncode\n```)
	formatted = formatted.replace(
		/```(\w+)?\s*\n([\s\S]*?)```/g,
		(match, lang, code) => {
			const language = lang || 'text';
			const trimmedCode = code.trim();
			const highlighted = highlightCode(trimmedCode, language);
			return `<pre class="issue-code-block"><code class="hljs language-${language}">${highlighted}</code></pre>`;
		}
	);
	
	// Inline code (`code`)
	formatted = formatted.replace(
		/`([^`]+)`/g,
		'<code class="issue-inline-code">$1</code>'
	);
	
	// Headings (## Heading or ### Heading)
	formatted = formatted.replace(/^###\s+(.+)$/gm, '<h3 class="issue-heading issue-heading-h3">$1</h3>');
	formatted = formatted.replace(/^##\s+(.+)$/gm, '<h2 class="issue-heading issue-heading-h2">$1</h2>');
	formatted = formatted.replace(/^#\s+(.+)$/gm, '<h1 class="issue-heading issue-heading-h1">$1</h1>');
	
	// Bold (**text** or __text__)
	formatted = formatted.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
	formatted = formatted.replace(/__([^_]+)__/g, '<strong>$1</strong>');
	
	// Italic (*text* or _text_)
	formatted = formatted.replace(/\*([^*]+)\*/g, '<em>$1</em>');
	formatted = formatted.replace(/_([^_]+)_/g, '<em>$1</em>');
	
	// Unordered lists (- item or * item)
	formatted = formatted.replace(/^[-*]\s+(.+)$/gm, '<li class="issue-list-item">$1</li>');
	// Wrap consecutive list items in <ul>
	formatted = formatted.replace(/(<li class="issue-list-item">.*<\/li>\n?)+/g, (match) => {
		return '<ul class="issue-list">' + match + '</ul>';
	});
	
	// Ordered lists (1. item)
	formatted = formatted.replace(/^\d+\.\s+(.+)$/gm, '<li class="issue-list-item">$1</li>');
	// Wrap consecutive numbered list items in <ol>
	formatted = formatted.replace(/(<li class="issue-list-item">.*<\/li>\n?)+/g, (match) => {
		// Check if previous replacement already wrapped it
		if (!match.includes('<ul') && !match.includes('<ol')) {
			return '<ol class="issue-list issue-list-ordered">' + match + '</ol>';
		}
		return match;
	});
	
	// Line breaks (double newline = paragraph break, single = <br>)
	formatted = formatted.replace(/\n\n+/g, '</p><p class="issue-paragraph">');
	formatted = formatted.replace(/\n/g, '<br>');
	
	// Wrap in paragraph if not already wrapped
	if (!formatted.startsWith('<')) {
		formatted = '<p class="issue-paragraph">' + formatted + '</p>';
	} else if (!formatted.includes('<p')) {
		formatted = '<p class="issue-paragraph">' + formatted + '</p>';
	}
	
	return formatted;
}

function formatCodeSnippet(code, language = 'php') {
	if (!code) return '';
	return highlightCode(code, language);
}

function toggleFile(filePath) {
	const index = expandedFiles.value.indexOf(filePath);
	if (index > -1) {
		expandedFiles.value.splice(index, 1);
	} else {
		expandedFiles.value.push(filePath);
		// Auto-expand all categories when file is expanded
		const file = filteredFiles.value.find(f => f.file === filePath);
		if (file && file.issues) {
			const grouped = groupIssuesByCategory(file.issues);
			if (grouped) {
				Object.keys(grouped).forEach(category => {
					const key = `${filePath}:${category}`;
					expandedCategories.value[key] = true;
				});
			}
		}
	}
}

function toggleCategory(filePath, category) {
	const key = `${filePath}:${category}`;
	expandedCategories.value[key] = !expandedCategories.value[key];
}

function isCategoryExpanded(filePath, category) {
	const key = `${filePath}:${category}`;
	return expandedCategories.value[key] !== false; // Default to expanded
}

function createIssueFromScan(issue, filePath) {
	// Map severity to priority
	const severityToPriority = {
		'critical': 'high',
		'high': 'high',
		'medium': 'medium',
		'low': 'low',
	};
	
	const priority = severityToPriority[issue.severity?.toLowerCase()] || 'medium';
	
	// Build title
	let title = `${issue.type || 'Issue'} in ${filePath}`;
	if (issue.line) {
		title += ` (Line ${issue.line})`;
	}
	
	// Build description
	let description = `**File:** ${filePath}\n`;
	if (issue.line) {
		description += `**Line:** ${issue.line}\n`;
	}
	description += `**Type:** ${issue.type || 'general'}\n`;
	description += `**Severity:** ${issue.severity || 'medium'}\n\n`;
	description += `**Issue:**\n${issue.message}`;
	
	// Build source data
	const sourceData = {
		file: filePath,
		line: issue.line || null,
		type: issue.type || 'general',
		severity: issue.severity || 'medium',
		message: issue.message,
		scan_id: props.scanId,
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: priority,
		source_type: 'codebase_scan',
		source_id: props.scanId ? `scan_${props.scanId}_${filePath}_${issue.line || 'general'}` : null,
		source_data: sourceData,
	});
}

async function loadResults() {
	if (!props.scanId) {
		return;
	}

	let retries = 3;
	let lastError = null;

	while (retries > 0) {
		try {
			const response = await axios.get(api.scan.results(props.scanId));
			if (response.data && response.data.success) {
				const result = response.data.result;
				
				// Validate that we have proper results structure
				if (result && (result.summary || result.issues)) {
					results.value = result;
					loading.value = false;
					error.value = null;
					
					// Load issues from database
					await loadDatabaseIssues();
					return; // Success, exit retry loop
				} else {
					// Results structure is invalid - might be in progress
					lastError = 'Results not yet available';
				}
			} else {
				lastError = response.data.error || 'Failed to load results';
			}
		} catch (err) {
			console.error('Failed to load scan results:', err);
			lastError = err.response?.data?.error || 'Failed to load scan results';
			
			// If it's a 404, don't retry
			if (err.response?.status === 404) {
				break;
			}
		}
		
		retries--;
		if (retries > 0) {
			// Wait before retrying (exponential backoff)
			await new Promise(resolve => setTimeout(resolve, 1000 * (4 - retries)));
		}
	}

	// All retries failed
	error.value = lastError;
	loading.value = false;
}

async function loadDatabaseIssues() {
	if (loadingIssues.value) return;
	
	loadingIssues.value = true;
	try {
		const response = await axios.get(api.scan.issues({ scan_id: props.scanId }));
		if (response.data && response.data.success) {
			databaseIssues.value = response.data.result || [];
		}
	} catch (err) {
		console.error('Failed to load database issues:', err);
	} finally {
		loadingIssues.value = false;
	}
}

function getIssueResolvedStatus(issue, filePath) {
	// Check if this issue is resolved in the database
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.file_path === filePath &&
		dbIssue.line === (issue.line || null) &&
		dbIssue.message === issue.message &&
		(dbIssue.resolved === true || dbIssue.status === 'resolved')
	);
	return !!dbIssue;
}

function getDatabaseIssueId(issue, filePath) {
	// Find the database issue ID for this issue
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.file_path === filePath &&
		dbIssue.line === (issue.line || null) &&
		dbIssue.message === issue.message
	);
	return dbIssue?.id || null;
}

async function resolveIssue(issue, filePath) {
	const issueId = getDatabaseIssueId(issue, filePath);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.scan.resolveIssue(issueId));
		if (response.data && response.data.success) {
			// Reload database issues to update status
			await loadDatabaseIssues();
		} else {
			console.error('Failed to resolve issue:', response.data?.error);
		}
	} catch (err) {
		console.error('Failed to resolve issue:', err);
	}
}

async function unresolveIssue(issue, filePath) {
	const issueId = getDatabaseIssueId(issue, filePath);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.scan.unresolveIssue(issueId));
		if (response.data && response.data.success) {
			// Reload database issues to update status
			await loadDatabaseIssues();
		} else {
			console.error('Failed to unresolve issue:', response.data?.error);
		}
	} catch (err) {
		console.error('Failed to unresolve issue:', err);
	}
}

async function clearOldIssues() {
	const result = await Swal.fire({
		icon: 'warning',
		title: 'Clear Old Issues?',
		html: 'This will delete all scan issues from the database. This action cannot be undone.',
		showCancelButton: true,
		confirmButtonText: 'Clear All',
		cancelButtonText: 'Cancel',
		confirmButtonColor: '#dc3545',
		cancelButtonColor: '#6c757d',
	});
	
	if (result.isConfirmed) {
		try {
			const response = await axios.delete(api.scan.clearIssues());
			if (response.data && response.data.success) {
				Swal.fire({
					icon: 'success',
					title: 'Issues Cleared',
					text: `Deleted ${response.data.result.deleted_count} issue(s)`,
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 3000,
				});
				
				// Reload database issues
				await loadDatabaseIssues();
				
				// Emit event to refresh scan history if it's open
				// The parent component will handle refreshing the history component
				emit('issues-cleared');
			} else {
				Swal.fire({
					icon: 'error',
					title: 'Failed to clear issues',
					text: response.data?.error || 'Unknown error',
					toast: true,
					position: 'top-end',
					showConfirmButton: false,
					timer: 3000,
				});
			}
		} catch (err) {
			console.error('Failed to clear issues:', err);
			Swal.fire({
				icon: 'error',
				title: 'Failed to clear issues',
				text: err.response?.data?.error || 'Unknown error',
				toast: true,
				position: 'top-end',
				showConfirmButton: false,
				timer: 3000,
			});
		}
	}
}

async function checkStatus() {
	if (!props.scanId) {
		loading.value = false;
		return Promise.resolve();
	}

	try {
		const response = await axios.get(api.scan.status(props.scanId));
		if (response.data && response.data.success) {
			const status = response.data.result;
			progress.value = status.progress || 0;
			
			// Show status message based on what's available
			if (status.status === 'queued') {
				statusMessage.value = 'Scan queued, waiting to start...';
				loading.value = true;
				// Clear any old results when scan is queued
				results.value = null;
			} else if (status.status === 'discovering') {
				statusMessage.value = status.message || 'Discovering files...';
				loading.value = true;
				// Clear any old results when discovering
				results.value = null;
			} else if (status.status === 'scanning') {
				loading.value = true;
				// Clear any old results when scanning
				results.value = null;
				// Use message if available, otherwise build from status
				if (status.message) {
					statusMessage.value = status.message;
				} else if (status.processed_files && status.total_files) {
					statusMessage.value = `Processing ${status.processed_files} of ${status.total_files} files`;
				} else if (status.processed_batches && status.total_batches) {
					statusMessage.value = `Processing batch ${status.processed_batches} of ${status.total_batches}`;
				} else if (status.total_files) {
					statusMessage.value = `Found ${status.total_files} files to scan...`;
				} else {
					statusMessage.value = 'Discovering files...';
				}
			} else if (status.status === 'completed') {
				stopPolling();
				loading.value = true; // Keep loading while fetching results
				// Don't clear results immediately - only clear if we successfully load new ones
				// This prevents the flash of empty state
				error.value = null;
				try {
					await loadResults();
					// loadResults() will set results.value when successful
				} catch (err) {
					console.error('Failed to load results after completion:', err);
					// Only clear results on error
					results.value = null;
					error.value = 'Failed to load scan results';
					loading.value = false;
				}
			} else if (status.status === 'failed') {
				loading.value = false;
				stopPolling();
				error.value = status.error || 'Scan failed';
				// Clear results on failure
				results.value = null;
			} else {
				// Unknown status - keep loading and clear results
				loading.value = true;
				results.value = null;
			}
		} else {
			console.error('Failed to get scan status:', response.data);
			error.value = response.data?.error || 'Failed to get scan status';
			loading.value = false;
			results.value = null;
		}
	} catch (err) {
		console.error('Failed to check scan status:', err);
		// Handle 404 - scan doesn't exist
		if (err.response?.status === 404) {
			error.value = 'Scan not found. The scan may have been deleted or never existed.';
			loading.value = false;
			results.value = null;
			stopPolling();
			return; // Exit early, don't continue
		}
		// Handle other errors - only continue polling for temporary network issues
		// Don't set error for temporary network issues, just log and continue polling
		// But ensure we're showing loading state
		if (!loading.value) {
			loading.value = true;
		}
	}
}

function startPolling() {
	// Clear any existing interval
	if (pollingInterval.value) {
		clearInterval(pollingInterval.value);
	}
	
	// Start polling every 2.5 seconds
	pollingInterval.value = setInterval(() => {
		if (!props.visible || !props.scanId) {
			stopPolling();
			return;
		}
		// Continue polling even if loading is false, in case scan completed quickly
		checkStatus();
	}, 2500);
}

function stopPolling() {
	if (pollingInterval.value) {
		clearInterval(pollingInterval.value);
		pollingInterval.value = null;
	}
}

watch(() => props.scanId, (newScanId, oldScanId) => {
	if (newScanId && newScanId !== oldScanId) {
		// Stop any existing polling first
		stopPolling();
		
		// IMMEDIATELY reset all state to ensure UI shows loading, not old results
		loading.value = true;
		error.value = null; // Clear any previous errors when switching to a new scan
		results.value = null; // Clear old results immediately
		progress.value = 0;
		statusMessage.value = 'Initializing scan...';
		expandedFiles.value = [];
		
		// Check status immediately (this will update loading/progress based on actual status)
		// Use then() to check if we should start polling after status check
		checkStatus().then(() => {
			// Only start polling if visible and no error was set
			if (props.visible && !error.value) {
				startPolling();
			}
		}).catch(() => {
			// If checkStatus throws, don't start polling
			// Error is already handled in checkStatus
		});
	} else if (!newScanId) {
		// If scanId is cleared, reset everything
		stopPolling();
		loading.value = false;
		error.value = null;
		results.value = null;
		progress.value = 0;
		statusMessage.value = '';
		expandedFiles.value = [];
	}
}, { immediate: true });

watch(() => props.visible, (newVisible) => {
	if (newVisible && props.scanId) {
		// If we don't have results and no error, ensure we're in loading state
		if (!results.value && !error.value) {
			loading.value = true;
			error.value = null;
		}
		// Only check status and start polling if we don't have an error
		// (e.g., if we got a 404, don't keep trying)
		if (!error.value) {
			checkStatus().then(() => {
				// Only start polling if no error was set
				if (!error.value) {
					startPolling();
				}
			}).catch(() => {
				// Error is already handled in checkStatus
			});
		}
	} else {
		// Stop polling when tab is hidden
		stopPolling();
	}
});

onMounted(() => {
	if (props.visible && props.scanId && loading.value) {
		startPolling();
	}
});

onUnmounted(() => {
	stopPolling();
});
</script>

<style scoped>
.terminal-scan-results {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	padding: 1rem;
	overflow-y: auto;
	z-index: 10002;
	pointer-events: auto;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-scan-results::-webkit-scrollbar {
	width: 10px;
}

.terminal-scan-results::-webkit-scrollbar-track {
	background: var(--terminal-bg, #ffffff);
	border-radius: 5px;
}

.terminal-scan-results::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-scan-results::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #d0d0d0);
}

.terminal-scan-no-scan {
	text-align: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-scan-loading {
	text-align: center;
	padding: 2rem;
}

.terminal-scan-progress {
	width: 100%;
	height: 20px;
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 1rem;
}

.terminal-scan-progress-bar {
	height: 100%;
	background-color: var(--terminal-primary, #0e639c);
	transition: width 0.3s ease;
}

.terminal-scan-status {
	margin-top: 0.5rem;
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.9rem;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
}

.status-indicator {
	display: inline-block;
	font-size: 1.1rem;
	line-height: 1;
}

/* Animation keyframes */
@keyframes pulse {
	0%, 100% {
		opacity: 1;
		transform: scale(1);
	}
	50% {
		opacity: 0.6;
		transform: scale(0.95);
	}
}

@keyframes bounce {
	0%, 100% {
		transform: translateY(0);
	}
	50% {
		transform: translateY(-4px);
	}
}

@keyframes rotate {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

@keyframes shake {
	0%, 100% {
		transform: translateX(0);
	}
	25% {
		transform: translateX(-3px);
	}
	75% {
		transform: translateX(3px);
	}
}

@keyframes glow {
	0%, 100% {
		opacity: 1;
		filter: brightness(1);
	}
	50% {
		opacity: 0.8;
		filter: brightness(1.3);
	}
}

/* Status-specific animations */
.status-thinking .status-indicator {
	animation: pulse 1.5s ease-in-out infinite;
}

.status-parsing .status-indicator {
	animation: bounce 1s ease-in-out infinite;
}

.status-reading .status-indicator {
	animation: shake 1s ease-in-out infinite;
}

.status-analyzing .status-indicator {
	animation: rotate 2s linear infinite;
}

.status-compiling .status-indicator {
	animation: pulse 1s ease-in-out infinite;
}

.status-saving .status-indicator {
	animation: glow 1.2s ease-in-out infinite;
}

.status-discovering .status-indicator {
	animation: bounce 1.3s ease-in-out infinite;
}

.status-handling .status-indicator {
	animation: pulse 0.8s ease-in-out infinite;
}

.status-default .status-indicator {
	animation: pulse 2s ease-in-out infinite;
}

.terminal-scan-error {
	padding: 2rem;
	text-align: center;
}

.error-message {
	color: var(--terminal-error, #ef4444);
}

.error-message :deep(.error-link) {
	color: var(--terminal-text, #333333);
	text-decoration: underline;
	transition: color 0.2s ease;
	font-weight: 500;
}

.error-message :deep(.error-link:hover) {
	color: var(--terminal-text-secondary, #858585);
}

.terminal-scan-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

.terminal-scan-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.terminal-scan-summary {
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	padding: 1.5rem;
}

.terminal-scan-summary h3 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--terminal-text, #333333);
}

.terminal-scan-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1rem;
}

.stat-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.stat-label {
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
}

.stat-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--terminal-text, #333333);
}

.stat-value.critical {
	color: #ef4444;
}

.stat-value.high {
	color: #f59e0b;
}

.stat-value.medium {
	color: #3b82f6;
}

.stat-value.low {
	color: #10b981;
}

.terminal-scan-files h3 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--terminal-text, #333333);
}

.no-results {
	padding: 2rem;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}

.files-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.file-item {
	background-color: var(--terminal-bg-tertiary, #f9f9f9);
	border-radius: 6px;
	border: 1px solid var(--terminal-border, #e5e5e5);
	overflow: hidden;
}

.file-item.has-issues {
	border-color: var(--terminal-warning, #f59e0b);
}

.file-item.has-errors {
	border-color: var(--terminal-error, #ef4444);
}

.file-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 1rem;
	cursor: pointer;
	user-select: none;
	transition: background-color 0.2s;
}

.file-header:hover {
	background-color: var(--terminal-bg-secondary, #e8e8e8);
}

.file-name {
	flex: 1;
	color: var(--terminal-text, #333333);
	font-family: 'Courier New', monospace;
	font-size: 0.9rem;
}

.issue-count {
	background-color: #f59e0b;
	color: #000;
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
}

.error-badge {
	background-color: #ef4444;
	color: #fff;
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
}

.expand-icon {
	width: 20px;
	height: 20px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s;
}

.expand-icon.expanded {
	transform: rotate(180deg);
}

.file-details {
	padding: 0 1rem 1rem 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
	margin-top: 0.5rem;
	padding-top: 1rem;
}

.error-section {
	margin-bottom: 1rem;
}

.error-text {
	color: #ef4444;
}

.issues-section {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.issue-category-group {
	margin-bottom: 1rem;
}

.issue-category-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem;
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	border-left: 4px solid;
	border-radius: 4px;
	cursor: pointer;
	transition: background-color 0.2s ease;
	margin-bottom: 0.5rem;
}

.issue-category-header:hover {
	background-color: var(--terminal-bg-secondary, #e8e8e8);
}

.category-icon {
	font-size: 1.1rem;
}

.category-name {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--terminal-text, #333333);
	flex: 1;
}

.category-count {
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.85rem;
}

.category-expand-icon {
	width: 16px;
	height: 16px;
	transition: transform 0.2s ease;
	color: var(--terminal-text-secondary, #858585);
}

.category-expand-icon.expanded {
	transform: rotate(180deg);
}

.issue-category-content {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding-left: 1rem;
}

.issue-item {
	background-color: var(--terminal-bg, #ffffff);
	border-left: 3px solid;
	border-radius: 4px;
	padding: 0.75rem;
}

.issue-item.severity-critical {
	border-left-color: #ef4444;
}

.issue-item.severity-high {
	border-left-color: #f59e0b;
}

.issue-item.severity-medium {
	border-left-color: #3b82f6;
}

.issue-item.severity-low {
	border-left-color: #10b981;
}

.issue-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
	flex-wrap: wrap;
	width: 100%;
}

.issue-type {
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	text-transform: uppercase;
	color: var(--terminal-text, #333333);
}

.issue-line {
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.875rem;
	font-family: 'Courier New', monospace;
}

.issue-severity {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
	text-transform: uppercase;
}

.issue-severity.severity-critical {
	background-color: #ef4444;
	color: #fff;
}

.issue-severity.severity-high {
	background-color: #f59e0b;
	color: #000;
}

.issue-severity.severity-medium {
	background-color: #3b82f6;
	color: #fff;
}

.issue-severity.severity-low {
	background-color: #10b981;
	color: #000;
}

.issue-message {
	color: var(--terminal-text, #333333);
	font-size: 0.9rem;
	line-height: 1.6;
}

.issue-message .issue-paragraph {
	margin: 0.5rem 0;
}

.issue-message .issue-paragraph:first-child {
	margin-top: 0;
}

.issue-message .issue-paragraph:last-child {
	margin-bottom: 0;
}

.issue-message .issue-heading {
	font-weight: 600;
	margin: 1rem 0 0.5rem 0;
	color: var(--terminal-text, #333333);
}

.issue-message .issue-heading-h1 {
	font-size: 1.25rem;
}

.issue-message .issue-heading-h2 {
	font-size: 1.1rem;
}

.issue-message .issue-heading-h3 {
	font-size: 1rem;
}

.issue-message .issue-list {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
	list-style-type: disc;
}

.issue-message .issue-list-ordered {
	list-style-type: decimal;
}

.issue-message .issue-list-item {
	margin: 0.25rem 0;
	line-height: 1.5;
}

.issue-message .issue-code-block {
	background-color: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	padding: 0.75rem;
	margin: 0.75rem 0;
	overflow-x: auto;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 0.85rem;
	line-height: 1.4;
}

.issue-message .issue-code-block code {
	background: transparent;
	padding: 0;
	border: none;
	display: block;
	white-space: pre;
}

.issue-message .issue-inline-code {
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 3px;
	padding: 0.15rem 0.35rem;
	font-family: 'Courier New', 'Monaco', 'Menlo', monospace;
	font-size: 0.85em;
	color: var(--terminal-text, #333333);
}

.issue-message strong {
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.issue-message em {
	font-style: italic;
	color: var(--terminal-text-secondary, #858585);
}

.issue-code-snippet {
	margin-top: 1rem;
}

.code-snippet-header {
	margin-bottom: 0.5rem;
}

.code-snippet-label {
	font-size: 0.85rem;
	color: var(--terminal-text-secondary, #858585);
	font-weight: 500;
}

.issue-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-left: auto;
}

.issue-action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0.4rem;
	background-color: var(--terminal-bg-tertiary, #f0f0f0);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
	width: 28px;
	height: 28px;
}

.issue-action-btn:hover {
	background-color: var(--terminal-bg-secondary, #e8e8e8);
	border-color: var(--terminal-border-hover, #d0d0d0);
	color: var(--terminal-text, #333333);
}

.issue-action-btn svg {
	width: 16px;
	height: 16px;
}

.issue-resolve-btn {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-success, #10b981) 40%, transparent);
	color: var(--terminal-success, #10b981);
}

.issue-resolve-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-success, #10b981) 60%, transparent);
}

.issue-unresolve-btn {
	background-color: color-mix(in srgb, var(--terminal-error, #ef4444) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-error, #ef4444) 40%, transparent);
	color: var(--terminal-error, #ef4444);
}

.issue-unresolve-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-error, #ef4444) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-error, #ef4444) 60%, transparent);
}

.issue-create-btn {
	background-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 20%, transparent);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 40%, transparent);
	color: var(--terminal-primary, #0e639c);
}

.issue-create-btn:hover {
	background-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 30%, transparent);
	border-color: color-mix(in srgb, var(--terminal-primary, #0e639c) 60%, transparent);
}

.resolved-badge {
	background-color: color-mix(in srgb, var(--terminal-success, #10b981) 20%, transparent);
	color: var(--terminal-success, #10b981);
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: bold;
}

.issue-item.resolved {
	opacity: 0.6;
}

.issue-item.resolved .issue-message {
	text-decoration: line-through;
}

.no-issues {
	padding: 1rem;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
}
</style>

