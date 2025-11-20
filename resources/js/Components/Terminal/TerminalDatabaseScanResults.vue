<template>
	<div v-if="visible" class="terminal-database-scan-results">
		<div v-if="loading" class="terminal-scan-loading">
			<div class="terminal-scan-progress">
				<div class="terminal-scan-progress-bar" :style="{ width: progress + '%' }"></div>
			</div>
			<p>Scanning database... {{ progress }}%</p>
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
						<span class="stat-label">Total Tables:</span>
						<span class="stat-value">{{ results.summary?.total_tables || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Total Issues:</span>
						<span class="stat-value">{{ results.summary?.total_issues || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Critical:</span>
						<span class="stat-value critical">{{ results.summary?.issues_by_severity?.critical || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">High:</span>
						<span class="stat-value high">{{ results.summary?.issues_by_severity?.high || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Medium:</span>
						<span class="stat-value medium">{{ results.summary?.issues_by_severity?.medium || 0 }}</span>
					</div>
					<div class="stat-item">
						<span class="stat-label">Low:</span>
						<span class="stat-value low">{{ results.summary?.issues_by_severity?.low || 0 }}</span>
					</div>
				</div>
			</div>

			<!-- Issues List (grouped by table) -->
			<div class="terminal-scan-files">
				<h3>Issues by Table</h3>
				<div v-if="groupedIssues.length === 0" class="no-results">
					<p>No issues found in scanned tables.</p>
				</div>
				<div v-else class="files-list">
					<div
						v-for="tableGroup in groupedIssues"
						:key="tableGroup.table"
						class="file-item"
						:class="{ 'has-issues': tableGroup.issues && tableGroup.issues.length > 0 }"
					>
						<div class="file-header" @click="toggleTable(tableGroup.table)">
							<span class="file-name">{{ tableGroup.table }}</span>
							<span v-if="tableGroup.issues && tableGroup.issues.length > 0" class="issue-count">
								{{ tableGroup.issues.length }} issue{{ tableGroup.issues.length !== 1 ? 's' : '' }}
							</span>
							<svg
								xmlns="http://www.w3.org/2000/svg"
								class="expand-icon"
								:class="{ expanded: expandedTables.includes(tableGroup.table) }"
								fill="none"
								viewBox="0 0 24 24"
								stroke="currentColor"
							>
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
							</svg>
						</div>
						<div v-if="expandedTables.includes(tableGroup.table)" class="file-details">
							<div v-if="tableGroup.issues && tableGroup.issues.length > 0" class="issues-section">
								<template v-if="groupIssuesByCategory(tableGroup.issues)">
									<div
										v-for="(categoryIssues, category) in groupIssuesByCategory(tableGroup.issues)"
										:key="category"
										class="issue-category-group"
									>
										<div
											class="issue-category-header"
											:style="{ borderLeftColor: categoryStyles[category]?.color || '#6b7280' }"
											@click="toggleCategory(tableGroup.table, category)"
										>
											<span class="category-icon">{{ categoryStyles[category]?.icon || '📝' }}</span>
											<span class="category-name">{{ categoryNames[category] || category }}</span>
											<span class="category-count">({{ categoryIssues.length }})</span>
											<svg
												xmlns="http://www.w3.org/2000/svg"
												class="category-expand-icon"
												:class="{ expanded: isCategoryExpanded(tableGroup.table, category) }"
												fill="none"
												viewBox="0 0 24 24"
												stroke="currentColor"
											>
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
											</svg>
										</div>
										<div
											v-if="isCategoryExpanded(tableGroup.table, category)"
											class="issue-category-content"
										>
											<div
												v-for="(issue, index) in categoryIssues"
												:key="index"
												class="issue-item"
												:class="['severity-' + (issue.severity || 'medium'), { 'resolved': getIssueResolvedStatus(issue) }]"
											>
												<div class="issue-header">
													<span class="issue-type">{{ issue.issue_type || 'general' }}</span>
													<span class="issue-severity" :class="'severity-' + (issue.severity || 'medium')">
														{{ issue.severity || 'medium' }}
													</span>
													<span v-if="getIssueResolvedStatus(issue)" class="resolved-badge">
														Resolved
													</span>
													<div class="issue-actions">
														<button
															v-if="!getIssueResolvedStatus(issue)"
															@click.stop="resolveIssue(issue)"
															class="issue-action-btn issue-resolve-btn"
															title="Mark as resolved"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
															</svg>
														</button>
														<button
															v-else
															@click.stop="unresolveIssue(issue)"
															class="issue-action-btn issue-unresolve-btn"
															title="Mark as unresolved"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
															</svg>
														</button>
														<button
															@click.stop="createIssueFromScan(issue)"
															class="issue-action-btn issue-create-btn"
															title="Create Issue"
														>
															<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
																<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
															</svg>
														</button>
													</div>
												</div>
												<div class="issue-message">
													<div class="issue-title"><strong>{{ issue.title }}</strong></div>
													<div class="issue-description" v-html="formatIssueMessage(issue.description)"></div>
													<div v-if="issue.suggestion" class="issue-suggestion">
														<strong>Suggestion:</strong> <span v-html="formatIssueMessage(issue.suggestion)"></span>
													</div>
													<div v-if="issue.location && Object.keys(issue.location).length > 0" class="issue-location">
														<strong>Location:</strong> {{ formatLocation(issue.location) }}
													</div>
												</div>
											</div>
										</div>
									</div>
								</template>
								<template v-else>
									<div
										v-for="(issue, index) in tableGroup.issues"
										:key="index"
										class="issue-item"
										:class="['severity-' + (issue.severity || 'medium'), { 'resolved': getIssueResolvedStatus(issue) }]"
									>
									<div class="issue-header">
										<span class="issue-type">{{ issue.issue_type || 'general' }}</span>
										<span class="issue-severity" :class="'severity-' + (issue.severity || 'medium')">
											{{ issue.severity || 'medium' }}
										</span>
										<span v-if="getIssueResolvedStatus(issue)" class="resolved-badge">
											Resolved
										</span>
										<div class="issue-actions">
											<button
												v-if="!getIssueResolvedStatus(issue)"
												@click.stop="resolveIssue(issue)"
												class="issue-action-btn issue-resolve-btn"
												title="Mark as resolved"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
												</svg>
											</button>
											<button
												v-else
												@click.stop="unresolveIssue(issue)"
												class="issue-action-btn issue-unresolve-btn"
												title="Mark as unresolved"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
												</svg>
											</button>
											<button
												@click.stop="createIssueFromScan(issue)"
												class="issue-action-btn issue-create-btn"
												title="Create Issue"
											>
												<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
												</svg>
											</button>
										</div>
									</div>
									<div class="issue-message">
										<div class="issue-title"><strong>{{ issue.title }}</strong></div>
										<div class="issue-description" v-html="formatIssueMessage(issue.description)"></div>
										<div v-if="issue.suggestion" class="issue-suggestion">
											<strong>Suggestion:</strong> <span v-html="formatIssueMessage(issue.suggestion)"></span>
										</div>
										<div v-if="issue.location && Object.keys(issue.location).length > 0" class="issue-location">
											<strong>Location:</strong> {{ formatLocation(issue.location) }}
										</div>
									</div>
								</div>
								</template>
							</div>
							<div v-else class="no-issues">
								<p>No issues found in this table.</p>
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
const expandedTables = ref([]);
const pollingInterval = ref(null);
const databaseIssues = ref([]);
const loadingIssues = ref(false);
const statusCheckTimeout = ref(null);
const lastStatusCheck = ref(0);

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
	
	// Protect column/table names with underscores by temporarily replacing them
	// This prevents them from being interpreted as markdown formatting
	const protectedNames = [];
	// Match identifiers with underscores (like created_at, user_id, personal_access_tokens, etc.)
	// Pattern must match: word characters and underscores, must have at least one underscore
	// Match both quoted and unquoted names, and handle various contexts
	const protectedPatterns = [
		// Quoted names: 'created_at', "created_at", `created_at`
		/(['"`])([a-z_][a-z0-9_]*(?:_[a-z0-9_]+)+)\1/gi,
		// Unquoted names at word boundaries: created_at, user_id
		/\b([a-z_][a-z0-9_]*(?:_[a-z0-9_]+)+)\b/gi,
	];
	
	// Process quoted names first (pattern has 2 groups: quote and name)
	formatted = formatted.replace(/(['"`])([a-z_][a-z0-9_]*(?:_[a-z0-9_]+)+)\1/gi, (match, quote, name) => {
		// name is group 2 for quoted pattern
		if (name && typeof name === 'string' && name.includes('_') && /^[a-z0-9_]+$/i.test(name)) {
			const placeholder = `{{PROTECTED_COLUMN_${protectedNames.length}}}`;
			protectedNames.push(name);
			return quote + placeholder + quote;
		}
		return match;
	});
	
	// Then process unquoted names (pattern has 1 group: name)
	formatted = formatted.replace(/\b([a-z_][a-z0-9_]*(?:_[a-z0-9_]+)+)\b/gi, (match, name) => {
		// name is group 1 for unquoted pattern
		// Skip if it's already a placeholder from previous step
		if (match.startsWith('{{PROTECTED_COLUMN_') && match.endsWith('}}')) {
			return match;
		}
		if (name && typeof name === 'string' && name.includes('_') && /^[a-z0-9_]+$/i.test(name)) {
			const placeholder = `{{PROTECTED_COLUMN_${protectedNames.length}}}`;
			protectedNames.push(name);
			return placeholder;
		}
		return match;
	});
	
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
	// Note: We process **text** first, then __text__ but only when clearly markdown (not part of identifiers)
	formatted = formatted.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
	// For double underscores, only match when clearly markdown formatting (surrounded by word boundaries)
	// Skip placeholders like {{PROTECTED_COLUMN_0}}
	formatted = formatted.replace(/\b__([^_\n{}]+)__\b/g, (match, text) => {
		// Don't match if it looks like a placeholder
		if (match.includes('PROTECTED_COLUMN') || match.includes('{{')) {
			return match;
		}
		return '<strong>' + text + '</strong>';
	});
	
	// Italic (*text* only) - disabled underscore-based italics to prevent column names like created_at from being converted
	// This prevents issues where column names with underscores get incorrectly formatted as italics
	formatted = formatted.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
	// Note: We intentionally don't support _text_ for italics to avoid conflicts with column names
	
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
	
	// Restore protected column/table names (after all markdown processing)
	// The placeholders are already HTML-escaped, so we need to search for the escaped version
	protectedNames.forEach((name, index) => {
		const placeholder = `{{PROTECTED_COLUMN_${index}}}`;
		// HTML escape the placeholder to match what's in the formatted text
		const div = document.createElement('div');
		div.textContent = placeholder;
		const escapedPlaceholder = div.innerHTML;
		// HTML escape the name to preserve underscores and prevent XSS
		div.textContent = name;
		const escapedName = div.innerHTML;
		// Replace escaped placeholder with the escaped name
		formatted = formatted.replace(new RegExp(escapedPlaceholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), escapedName);
	});
	
	return formatted;
}

// Group issues by category for a table
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

const groupedIssues = computed(() => {
	if (!results.value || !results.value.issues) {
		return [];
	}
	
	// Group issues by table
	const grouped = {};
	results.value.issues.forEach(issue => {
		const table = issue.table || 'unknown';
		if (!grouped[table]) {
			grouped[table] = {
				table: table,
				issues: [],
			};
		}
		grouped[table].issues.push(issue);
	});
	
	return Object.values(grouped);
});

function formatLocation(location) {
	if (!location || typeof location !== 'object') {
		return '';
	}
	return Object.entries(location)
		.map(([key, value]) => `${key}: ${value}`)
		.join(', ');
}

function toggleTable(tableName) {
	const index = expandedTables.value.indexOf(tableName);
	if (index > -1) {
		expandedTables.value.splice(index, 1);
	} else {
		expandedTables.value.push(tableName);
		// Auto-expand all categories when table is expanded
		const tableGroup = groupedIssues.value.find(t => t.table === tableName);
		if (tableGroup && tableGroup.issues) {
			const grouped = groupIssuesByCategory(tableGroup.issues);
			if (grouped) {
				Object.keys(grouped).forEach(category => {
					const key = `${tableName}:${category}`;
					expandedCategories.value[key] = true;
				});
			}
		}
	}
}

function toggleCategory(tableName, category) {
	const key = `${tableName}:${category}`;
	expandedCategories.value[key] = !expandedCategories.value[key];
}

function isCategoryExpanded(tableName, category) {
	const key = `${tableName}:${category}`;
	return expandedCategories.value[key] !== false; // Default to expanded
}

function createIssueFromScan(issue) {
	// Map severity to priority
	const severityToPriority = {
		'critical': 'high',
		'high': 'high',
		'medium': 'medium',
		'low': 'low',
	};
	
	const priority = severityToPriority[issue.severity?.toLowerCase()] || 'medium';
	
	// Build title
	let title = `${issue.title || 'Database Issue'} in ${issue.table || 'unknown table'}`;
	
	// Build description
	let description = `**Table:** ${issue.table || 'unknown'}\n`;
	description += `**Type:** ${issue.issue_type || 'general'}\n`;
	description += `**Severity:** ${issue.severity || 'medium'}\n`;
	if (issue.location && Object.keys(issue.location).length > 0) {
		description += `**Location:** ${formatLocation(issue.location)}\n`;
	}
	description += `\n**Issue:**\n${issue.description || ''}`;
	if (issue.suggestion) {
		description += `\n\n**Suggestion:**\n${issue.suggestion}`;
	}
	
	// Build source data
	const sourceData = {
		table: issue.table || 'unknown',
		issue_type: issue.issue_type || 'general',
		severity: issue.severity || 'medium',
		description: issue.description || '',
		location: issue.location || {},
		suggestion: issue.suggestion || '',
		scan_id: props.scanId,
	};
	
	// Emit event to create issue
	emit('create-issue', {
		title: title,
		description: description,
		priority: priority,
		source_type: 'database_scan',
		source_id: props.scanId ? `db_scan_${props.scanId}_${issue.table}_${issue.issue_type}` : null,
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
			const response = await axios.get(api.databaseScan.results(props.scanId));
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
			console.error('Failed to load database scan results:', err);
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
		const response = await axios.get(api.databaseScan.issues({ scan_id: props.scanId }));
		if (response.data && response.data.success) {
			databaseIssues.value = response.data.result || [];
		}
	} catch (err) {
		console.error('Failed to load database issues:', err);
	} finally {
		loadingIssues.value = false;
	}
}

function getIssueResolvedStatus(issue) {
	// Check if this issue is resolved in the database
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.table_name === (issue.table || 'unknown') &&
		dbIssue.title === (issue.title || '') &&
		dbIssue.description === (issue.description || '') &&
		(dbIssue.resolved === true)
	);
	return !!dbIssue;
}

function getDatabaseIssueId(issue) {
	// Find the database issue ID for this issue
	const dbIssue = databaseIssues.value.find(dbIssue => 
		dbIssue.table_name === (issue.table || 'unknown') &&
		dbIssue.title === (issue.title || '') &&
		dbIssue.description === (issue.description || '')
	);
	return dbIssue?.id || null;
}

async function resolveIssue(issue) {
	const issueId = getDatabaseIssueId(issue);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.databaseScan.resolveIssue(issueId));
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

async function unresolveIssue(issue) {
	const issueId = getDatabaseIssueId(issue);
	if (!issueId) {
		return;
	}
	
	try {
		const response = await axios.post(api.databaseScan.unresolveIssue(issueId));
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
		html: 'This will delete all database scan issues from the database. This action cannot be undone.',
		showCancelButton: true,
		confirmButtonText: 'Clear All',
		cancelButtonText: 'Cancel',
	});
	
	if (result.isConfirmed) {
		try {
			const response = await axios.delete(api.databaseScan.clearIssues());
			if (response.data && response.data.success) {
				Swal.fire({
					icon: 'success',
					title: 'Issues Cleared',
					text: 'All database scan issues have been deleted',
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
		return;
	}

	// Debounce: don't check more than once per second
	const now = Date.now();
	if (now - lastStatusCheck.value < 1000) {
		return;
	}
	lastStatusCheck.value = now;

	try {
		const response = await axios.get(api.databaseScan.status(props.scanId));
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
				statusMessage.value = status.message || 'Discovering tables...';
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
				} else if (status.processed_tables && status.total_tables) {
					statusMessage.value = `Processing ${status.processed_tables} of ${status.total_tables} tables`;
				} else if (status.processed_batches && status.total_batches) {
					statusMessage.value = `Processing batch ${status.processed_batches} of ${status.total_batches}`;
				} else if (status.total_tables) {
					statusMessage.value = `Found ${status.total_tables} tables to scan...`;
				} else {
					statusMessage.value = 'Discovering tables...';
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
		// Don't set error on network issues, just log and continue polling
		if (err.response?.status === 404) {
			error.value = 'Scan not found';
			loading.value = false;
			results.value = null;
			stopPolling();
		}
		// Otherwise, continue polling in case it's a temporary network issue
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
	
	// Reset debounce timer
	lastStatusCheck.value = 0;
	
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
		// Clear any pending timeouts
		if (statusCheckTimeout.value) {
			clearTimeout(statusCheckTimeout.value);
			statusCheckTimeout.value = null;
		}
		
		// Stop any existing polling first
		stopPolling();
		
		// IMMEDIATELY reset all state to ensure UI shows loading, not old results
		loading.value = true;
		error.value = null;
		results.value = null; // Clear old results immediately
		progress.value = 0;
		statusMessage.value = 'Initializing scan...';
		expandedTables.value = [];
		databaseIssues.value = [];
		lastStatusCheck.value = 0;
		
		// Check status immediately (this will update loading/progress based on actual status)
		checkStatus();
		
		// Start polling if visible
		if (props.visible) {
			startPolling();
		}
	} else if (!newScanId) {
		// If scanId is cleared, reset everything
		stopPolling();
		loading.value = false;
		error.value = null;
		results.value = null;
		progress.value = 0;
		statusMessage.value = '';
		expandedTables.value = [];
		databaseIssues.value = [];
	}
});

watch(() => props.visible, (visible) => {
	if (visible && props.scanId) {
		// If we don't have results, ensure we're in loading state
		if (!results.value) {
			loading.value = true;
			error.value = null;
		}
		checkStatus();
		startPolling();
	} else {
		stopPolling();
	}
});

onMounted(() => {
	if (props.visible && props.scanId) {
		checkStatus();
		startPolling();
	}
});

onUnmounted(() => {
	stopPolling();
	if (statusCheckTimeout.value) {
		clearTimeout(statusCheckTimeout.value);
		statusCheckTimeout.value = null;
	}
});
</script>

<style scoped>
/* Reuse styles from TerminalScanResults.vue */
.terminal-database-scan-results {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #333333);
	z-index: 10002;
	pointer-events: auto;
	overflow-y: auto;
	scrollbar-width: thin;
	scrollbar-color: var(--terminal-border, #e5e5e5) var(--terminal-bg, #ffffff);
}

.terminal-database-scan-results::-webkit-scrollbar {
	width: 10px;
}

.terminal-database-scan-results::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 5px;
}

.terminal-database-scan-results::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #e5e5e5);
	border-radius: 5px;
	border: 2px solid var(--terminal-bg, #ffffff);
}

.terminal-database-scan-results::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-scan-loading,
.terminal-scan-error,
.terminal-scan-content {
	padding: 1.5rem;
}

.terminal-scan-loading {
	text-align: center;
}

.terminal-scan-progress {
	width: 100%;
	height: 8px;
	background: var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	overflow: hidden;
	margin-bottom: 1rem;
}

.terminal-scan-progress-bar {
	height: 100%;
	background: var(--terminal-primary, #0e639c);
	transition: width 0.3s;
}

.terminal-scan-status {
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.875rem;
	margin-top: 0.5rem;
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
	color: var(--terminal-error, #f44336);
	text-align: center;
}

.error-message {
	color: var(--terminal-error, #f44336);
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

.terminal-scan-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-bottom: 1.5rem;
}

.terminal-scan-summary {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	padding: 1.5rem;
	margin-bottom: 1.5rem;
}

.terminal-scan-summary h3 {
	margin: 0 0 1rem 0;
	font-size: 1.125rem;
	font-weight: 600;
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
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.stat-value.critical {
	color: #f44336;
}

.stat-value.high {
	color: #ff9800;
}

.stat-value.medium {
	color: #ffc107;
}

.stat-value.low {
	color: #4caf50;
}

.terminal-scan-files {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 8px;
	padding: 1.5rem;
}

.terminal-scan-files h3 {
	margin: 0 0 1rem 0;
	font-size: 1.125rem;
	font-weight: 600;
	color: var(--terminal-text, #333333);
}

.no-results {
	text-align: center;
	padding: 2rem;
	color: var(--terminal-text-secondary, #858585);
}

.files-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.file-item {
	background: var(--terminal-bg-tertiary, #f9f9f9);
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	overflow: hidden;
}

.file-item.has-issues {
	border-color: color-mix(in srgb, var(--terminal-warning, #ffc107) 30%, transparent);
}

.file-header {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	cursor: pointer;
	transition: background 0.2s;
}

.file-header:hover {
	background: var(--terminal-bg-secondary, #e8e8e8);
}

.file-name {
	flex: 1;
	font-family: 'Courier New', monospace;
	color: var(--terminal-text, #333333);
	font-weight: 500;
}

.issue-count {
	padding: 0.25rem 0.75rem;
	background: color-mix(in srgb, var(--terminal-warning, #ffc107) 20%, transparent);
	border-radius: 12px;
	font-size: 0.875rem;
	color: var(--terminal-warning, #ffc107);
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
	padding: 1rem;
	border-top: 1px solid var(--terminal-border, #e5e5e5);
}

.issues-section {
	display: flex;
	flex-direction: column;
	gap: 1rem;
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
	padding: 1rem;
	background: var(--terminal-bg, #1e1e1e);
	border-left: 4px solid var(--terminal-warning, #ffc107);
	border-radius: 4px;
}

.issue-item.severity-critical {
	border-left-color: #f44336;
}

.issue-item.severity-high {
	border-left-color: #ff9800;
}

.issue-item.severity-medium {
	border-left-color: #ffc107;
}

.issue-item.severity-low {
	border-left-color: #4caf50;
}

.issue-item.resolved {
	opacity: 0.6;
}

.issue-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
	flex-wrap: wrap;
}

.issue-type {
	padding: 0.25rem 0.5rem;
	background: color-mix(in srgb, var(--terminal-primary) 20%, transparent);
	border-radius: 4px;
	font-size: 0.75rem;
	color: var(--terminal-primary);
	text-transform: uppercase;
}

.issue-severity {
	padding: 0.25rem 0.5rem;
	border-radius: 4px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.issue-severity.severity-critical {
	background: rgba(244, 67, 54, 0.2);
	color: #f44336;
}

.issue-severity.severity-high {
	background: rgba(255, 152, 0, 0.2);
	color: #ff9800;
}

.issue-severity.severity-medium {
	background: rgba(255, 193, 7, 0.2);
	color: #ffc107;
}

.issue-severity.severity-low {
	background: rgba(76, 175, 80, 0.2);
	color: #4caf50;
}

.resolved-badge {
	padding: 0.25rem 0.5rem;
	background: rgba(76, 175, 80, 0.2);
	border-radius: 4px;
	font-size: 0.75rem;
	color: #4caf50;
}

.issue-actions {
	display: flex;
	gap: 0.5rem;
	margin-left: auto;
}

.issue-action-btn {
	padding: 0.25rem;
	background: transparent;
	border: 1px solid var(--terminal-border, #e5e5e5);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
}

.issue-action-btn:hover {
	background: var(--terminal-bg-tertiary, #f0f0f0);
	border-color: var(--terminal-border-hover, #d0d0d0);
	color: var(--terminal-text, #333333);
}

.issue-action-btn svg {
	width: 16px;
	height: 16px;
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

.issue-title {
	margin-bottom: 0.5rem;
	color: var(--terminal-text, #333333);
}

.issue-description {
	margin-bottom: 0.5rem;
}

.issue-suggestion {
	margin-top: 0.5rem;
	padding: 0.75rem;
	background: color-mix(in srgb, var(--terminal-primary, #0e639c) 10%, transparent);
	border-radius: 4px;
	border-left: 3px solid var(--terminal-primary, #0e639c);
}

.issue-location {
	margin-top: 0.5rem;
	font-size: 0.875rem;
	color: var(--terminal-text-secondary, #858585);
	font-family: 'Courier New', monospace;
}

.no-issues {
	text-align: center;
	padding: 1rem;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-btn {
	padding: 0.5rem 1rem;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	cursor: pointer;
	font-size: 0.875rem;
	transition: all 0.2s;
	display: inline-flex;
	align-items: center;
	gap: 0.5rem;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
}

.terminal-btn:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #e8e8e8);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-btn-secondary {
	background: var(--terminal-bg-secondary, #f5f5f5);
	border-color: var(--terminal-border, #e5e5e5);
}

.terminal-btn-sm {
	padding: 0.375rem 0.75rem;
	font-size: 0.8125rem;
}

.terminal-btn svg {
	width: 16px;
	height: 16px;
}
</style>

