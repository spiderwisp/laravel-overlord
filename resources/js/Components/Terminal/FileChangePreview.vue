<template>
	<div class="file-change-preview">
		<details :open="change.status === 'applied'">
			<summary class="change-summary-header">
				<div class="change-file-path">{{ change.file_path }}</div>
				<div class="change-summary-text">
					({{ diffStats.additions }} additions, {{ diffStats.deletions }} deletions)
				</div>
				<div v-if="change.status === 'pending'" class="change-actions-inline">
					<button @click.stop="$emit('approve', change.id)" class="terminal-btn terminal-btn-primary terminal-btn-xs">Approve</button>
					<button @click.stop="$emit('reject', change.id)" class="terminal-btn terminal-btn-danger terminal-btn-xs">Reject</button>
				</div>
				<div v-if="change.status === 'applied'" class="change-status-badge applied">
					<span class="applied-checkmark">✓</span>
					<span class="applied-text">Applied</span>
				</div>
				<div v-else class="change-status" :class="`status-${change.status}`">
					{{ change.status }}
				</div>
			</summary>
			<div class="change-details-content">
				<div v-if="change.change_summary" class="change-issue-summary">
					<strong>Issue:</strong> {{ change.change_summary.issue_message }}
					<span v-if="change.change_summary.issue_line"> (Line: {{ change.change_summary.issue_line }})</span>
				</div>

				<div class="change-diff unified-diff">
					<pre><code class="hljs language-php" v-html="highlightedDiff"></code></pre>
				</div>

				<div v-if="change.status === 'rejected' && change.rejection_reason" class="rejection-reason">
					<strong>Reason:</strong> {{ change.rejection_reason }}
				</div>
			</div>
		</details>
	</div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
	change: {
		type: Object,
		required: true,
	},
});

defineEmits(['approve', 'reject']);

// Simple diff implementation
function createUnifiedDiff(oldText, newText) {
	const oldLines = (oldText || '').split('\n');
	const newLines = (newText || '').split('\n');
	
	const diff = [];
	let oldIndex = 0;
	let newIndex = 0;
	
	while (oldIndex < oldLines.length || newIndex < newLines.length) {
		if (oldIndex >= oldLines.length) {
			// Only new lines remain
			diff.push(`+${newLines[newIndex]}`);
			newIndex++;
		} else if (newIndex >= newLines.length) {
			// Only old lines remain
			diff.push(`-${oldLines[oldIndex]}`);
			oldIndex++;
		} else if (oldLines[oldIndex] === newLines[newIndex]) {
			// Lines match
			diff.push(` ${oldLines[oldIndex]}`);
			oldIndex++;
			newIndex++;
		} else {
			// Lines differ - try to find matching line ahead
			let foundMatch = false;
			for (let lookAhead = 1; lookAhead <= 3 && newIndex + lookAhead < newLines.length; lookAhead++) {
				if (oldLines[oldIndex] === newLines[newIndex + lookAhead]) {
					// Found match ahead - add new lines
					for (let i = 0; i < lookAhead; i++) {
						diff.push(`+${newLines[newIndex + i]}`);
					}
					newIndex += lookAhead;
					foundMatch = true;
					break;
				}
			}
			
			if (!foundMatch) {
				// No match found - remove old line and add new line
				diff.push(`-${oldLines[oldIndex]}`);
				diff.push(`+${newLines[newIndex]}`);
				oldIndex++;
				newIndex++;
			}
		}
	}
	
	return diff.join('\n');
}

const diffStats = computed(() => {
	const oldLines = (props.change.original_content || '').split('\n');
	const newLines = (props.change.new_content || '').split('\n');
	
	let additions = 0;
	let deletions = 0;
	
	const diff = createUnifiedDiff(props.change.original_content || '', props.change.new_content || '');
	diff.split('\n').forEach(line => {
		if (line.startsWith('+') && !line.startsWith('+++')) additions++;
		if (line.startsWith('-') && !line.startsWith('---')) deletions++;
	});
	
	return { additions, deletions };
});

const highlightedDiff = computed(() => {
	const diff = createUnifiedDiff(props.change.original_content || '', props.change.new_content || '');
	
	// Check if this is PHP code
	const isPhp = (props.change.file_path || '').endsWith('.php');
	
	if (!isPhp) {
		// For non-PHP files, just do basic diff highlighting
		return diff.split('\n').map(line => {
			const escaped = line
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
			
			if (line.startsWith('+') && !line.startsWith('+++')) {
				return `<span class="diff-added">+${escaped}</span>`;
			} else if (line.startsWith('-') && !line.startsWith('---')) {
				return `<span class="diff-removed">-${escaped}</span>`;
			} else if (line.startsWith('@@')) {
				return `<span class="diff-meta">${escaped}</span>`;
			}
			return `<span>${escaped}</span>`;
		}).join('\n');
	}
	
	// For PHP files, apply syntax highlighting line by line
	try {
		const diffLines = diff.split('\n');
		const result = [];
		
		// Highlight each code line individually
		diffLines.forEach((diffLine) => {
			if (diffLine.startsWith('+') && !diffLine.startsWith('+++')) {
				const codeLine = diffLine.substring(1);
				// Highlight just this line
				const highlighted = highlightCode(codeLine, 'php');
				result.push(`<span class="diff-added">+${highlighted}</span>`);
			} else if (diffLine.startsWith('-') && !diffLine.startsWith('---')) {
				const codeLine = diffLine.substring(1);
				// Highlight just this line
				const highlighted = highlightCode(codeLine, 'php');
				result.push(`<span class="diff-removed">-${highlighted}</span>`);
			} else if (diffLine.startsWith('@@')) {
				result.push(`<span class="diff-meta">${escapeHtml(diffLine)}</span>`);
			} else {
				// Context line - highlight if it's not empty
				const codeLine = diffLine.startsWith(' ') ? diffLine.substring(1) : diffLine;
				if (codeLine.trim()) {
					const highlighted = highlightCode(codeLine, 'php');
					result.push(`<span>${highlighted}</span>`);
				} else {
					result.push(`<span>${escapeHtml(diffLine)}</span>`);
				}
			}
		});
		
		return result.join('\n');
	} catch (e) {
		// Fallback to basic highlighting if syntax highlighting fails
		console.warn('Syntax highlighting failed:', e);
		return diff.split('\n').map(line => {
			const escaped = line
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;');
			
			if (line.startsWith('+') && !line.startsWith('+++')) {
				return `<span class="diff-added">+${escaped}</span>`;
			} else if (line.startsWith('-') && !line.startsWith('---')) {
				return `<span class="diff-removed">-${escaped}</span>`;
			} else if (line.startsWith('@@')) {
				return `<span class="diff-meta">${escaped}</span>`;
			}
			return `<span>${escaped}</span>`;
		}).join('\n');
	}
});

function escapeHtml(text) {
	return text
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
}
</script>

<style scoped>
.file-change-preview {
	margin-bottom: 8px;
}

.change-summary-header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 600;
	cursor: pointer;
	user-select: none;
	background: var(--terminal-bg-tertiary, #3e3e42);
	border-radius: 4px;
	list-style: none;
}

.change-summary-header::-webkit-details-marker {
	display: none;
}

.change-summary-header::before {
	content: '▶';
	display: inline-block;
	margin-right: 4px;
	font-size: 8px;
	transition: transform 0.2s;
}

.file-change-preview[open] .change-summary-header::before {
	transform: rotate(90deg);
}

.change-file-path {
	flex-grow: 1;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	color: var(--terminal-text, #d4d4d4);
}

.change-summary-text {
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text-secondary, #858585);
	font-weight: normal;
}

.change-actions-inline {
	display: flex;
	gap: 4px;
}

.change-status {
	padding: 2px 6px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
	text-transform: uppercase;
	flex-shrink: 0;
}

.status-pending {
	background: #f59e0b;
	color: white;
}

.status-approved {
	background: #10b981;
	color: white;
}

.status-rejected {
	background: #ef4444;
	color: white;
}

.status-applied {
	background: #10b981;
	color: white;
}

.change-status-badge.applied {
	background: #10b981;
	color: white;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.applied-checkmark {
	display: inline-block;
	line-height: 1;
}

.applied-text {
	display: inline-block;
	line-height: 1;
}

.change-details-content {
	padding: 8px 12px;
	border-top: 1px solid var(--terminal-border, #3e3e42);
	background: var(--terminal-bg-secondary, #252526);
	border-radius: 0 0 4px 4px;
}

.change-issue-summary {
	font-size: var(--terminal-font-size-xs, 11px);
	margin-bottom: 8px;
	color: var(--terminal-text, #d4d4d4);
}

.unified-diff {
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	overflow: hidden;
	margin-bottom: 8px;
}

.unified-diff pre {
	font-size: var(--terminal-font-size-xs, 11px);
	line-height: 1.4;
	background: var(--terminal-bg, #1e1e1e);
	padding: 8px;
	margin: 0;
	overflow-x: auto;
	white-space: pre;
	font-family: 'Courier New', monospace;
}

.unified-diff pre code {
	background: transparent;
	padding: 0;
	font-family: inherit;
	font-size: inherit;
	line-height: inherit;
	display: block;
}

.unified-diff pre code.hljs {
	background: var(--terminal-bg, #1e1e1e);
}

.diff-added {
	color: #10b981;
}

.diff-removed {
	color: #ef4444;
}

.diff-meta {
	color: #f59e0b;
}

/* Ensure syntax highlighting works with diff markers */
.unified-diff pre code.hljs {
	background: var(--terminal-bg, #1e1e1e);
}

.unified-diff pre code.hljs .diff-added {
	color: #10b981 !important;
	font-weight: 500;
}

.unified-diff pre code.hljs .diff-removed {
	color: #ef4444 !important;
	font-weight: 500;
}

.unified-diff pre code.hljs .diff-meta {
	color: #f59e0b !important;
	font-weight: 500;
}

.rejection-reason {
	margin-top: 8px;
	padding: 8px;
	background: rgba(239, 68, 68, 0.1);
	border-left: 3px solid #ef4444;
	border-radius: 4px;
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text, #d4d4d4);
}
</style>

