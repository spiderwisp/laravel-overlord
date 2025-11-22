<template>
	<div class="file-change-preview">
		<details class="change-details" :open="change.status === 'pending'">
			<summary class="change-header">
				<div class="change-header-content">
					<div class="change-file-path">{{ change.file_path }}</div>
					<div class="change-summary-line">{{ getChangeSummary() }}</div>
				</div>
				<div class="change-header-right">
					<div class="change-status" :class="`status-${change.status}`">
						{{ change.status }}
					</div>
					<div v-if="change.status === 'pending'" class="change-actions-inline">
						<button
							@click.stop="$emit('approve', change.id)"
							class="action-btn action-btn-approve"
							title="Approve"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
							</svg>
						</button>
						<button
							@click.stop="$emit('reject', change.id)"
							class="action-btn action-btn-reject"
							title="Reject"
						>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
								<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
							</svg>
						</button>
					</div>
					<svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
						<path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
					</svg>
				</div>
			</summary>
			
			<div class="change-content">
				<div v-if="change.change_summary" class="change-summary">
					<div class="summary-item">
						<strong>Issue:</strong> {{ change.change_summary.issue_message }}
					</div>
					<div v-if="change.change_summary.issue_line" class="summary-item">
						<strong>Line:</strong> {{ change.change_summary.issue_line }}
					</div>
				</div>

				<div class="change-diff-unified">
					<div class="diff-lines">
						<div
							v-for="(line, index) in unifiedDiff"
							:key="index"
							class="diff-line"
							:class="`diff-line-${line.type}`"
						>
							<span class="diff-line-number">{{ line.oldLine || '' }}</span>
							<span class="diff-line-number">{{ line.newLine || '' }}</span>
							<span class="diff-line-marker">{{ line.marker }}</span>
							<span class="diff-line-content">{{ line.content }}</span>
						</div>
					</div>
				</div>

				<div v-if="change.status === 'rejected' && change.rejection_reason" class="rejection-reason">
					<strong>Rejection Reason:</strong> {{ change.rejection_reason }}
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

function getChangeSummary() {
	const original = props.change.original_content || '';
	const updated = props.change.new_content || '';
	
	if (!original && !updated) {
		return 'Empty file';
	}
	
	if (!original) {
		return `${updated.split('\n').length} lines added`;
	}
	
	if (!updated) {
		return `${original.split('\n').length} lines removed`;
	}
	
	const originalLines = original.split('\n');
	const updatedLines = updated.split('\n');
	const diff = computeUnifiedDiff(originalLines, updatedLines);
	
	const additions = diff.filter(l => l.type === 'add').length;
	const deletions = diff.filter(l => l.type === 'remove').length;
	
	if (additions === 0 && deletions === 0) {
		return 'No changes';
	}
	
	const parts = [];
	if (deletions > 0) parts.push(`${deletions} deletion${deletions !== 1 ? 's' : ''}`);
	if (additions > 0) parts.push(`${additions} addition${additions !== 1 ? 's' : ''}`);
	
	return parts.join(', ');
}

function computeUnifiedDiff(oldLines, newLines) {
	const diff = [];
	let oldIndex = 0;
	let newIndex = 0;
	let oldLineNum = 1;
	let newLineNum = 1;
	
	// Simple line-by-line comparison
	while (oldIndex < oldLines.length || newIndex < newLines.length) {
		const oldLine = oldIndex < oldLines.length ? oldLines[oldIndex] : null;
		const newLine = newIndex < newLines.length ? newLines[newIndex] : null;
		
		if (oldLine === newLine) {
			// Lines match
			diff.push({
				type: 'context',
				content: oldLine,
				oldLine: oldLineNum++,
				newLine: newLineNum++,
				marker: ' ',
			});
			oldIndex++;
			newIndex++;
		} else if (oldLine === null) {
			// Only new line exists (addition)
			diff.push({
				type: 'add',
				content: newLine,
				oldLine: null,
				newLine: newLineNum++,
				marker: '+',
			});
			newIndex++;
		} else if (newLine === null) {
			// Only old line exists (deletion)
			diff.push({
				type: 'remove',
				content: oldLine,
				oldLine: oldLineNum++,
				newLine: null,
				marker: '-',
			});
			oldIndex++;
		} else {
			// Lines differ - show both
			diff.push({
				type: 'remove',
				content: oldLine,
				oldLine: oldLineNum++,
				newLine: null,
				marker: '-',
			});
			diff.push({
				type: 'add',
				content: newLine,
				oldLine: null,
				newLine: newLineNum++,
				marker: '+',
			});
			oldIndex++;
			newIndex++;
		}
	}
	
	return diff;
}

const unifiedDiff = computed(() => {
	const original = props.change.original_content || '';
	const updated = props.change.new_content || '';
	
	if (!original && !updated) {
		return [];
	}
	
	const originalLines = original ? original.split('\n') : [];
	const updatedLines = updated ? updated.split('\n') : [];
	
	return computeUnifiedDiff(originalLines, updatedLines);
});
</script>

<style scoped>
.file-change-preview {
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 3px;
	background: var(--terminal-bg-secondary, #252526);
	overflow: hidden;
}

.change-details {
	width: 100%;
}

.change-details summary {
	list-style: none;
	cursor: pointer;
	user-select: none;
}

.change-details summary::-webkit-details-marker {
	display: none;
}

.change-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 8px;
	gap: 8px;
	transition: background-color 0.2s ease;
}

.change-header:hover {
	background: rgba(255, 255, 255, 0.03);
}

.change-header-content {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.change-file-path {
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	font-size: 11px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.change-summary-line {
	font-size: 10px;
	color: var(--terminal-text-secondary, #858585);
}

.change-header-right {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.change-status {
	padding: 2px 6px;
	border-radius: 2px;
	font-size: 9px;
	font-weight: 600;
	text-transform: uppercase;
	white-space: nowrap;
}

.chevron-icon {
	width: 12px;
	height: 12px;
	color: var(--terminal-text-secondary, #858585);
	transition: transform 0.2s ease;
	flex-shrink: 0;
}

.change-details[open] .chevron-icon {
	transform: rotate(180deg);
}

.change-actions-inline {
	display: flex;
	gap: 4px;
}

.action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	padding: 0;
	border: none;
	border-radius: 3px;
	cursor: pointer;
	transition: all 0.2s ease;
	background: transparent;
}

.action-btn svg {
	width: 14px;
	height: 14px;
}

.action-btn-approve {
	color: #10b981;
}

.action-btn-approve:hover {
	background: rgba(16, 185, 129, 0.15);
}

.action-btn-reject {
	color: #ef4444;
}

.action-btn-reject:hover {
	background: rgba(239, 68, 68, 0.15);
}

.change-content {
	padding: 6px 8px 8px 8px;
	border-top: 1px solid rgba(255, 255, 255, 0.05);
	animation: slideDown 0.2s ease;
}

@keyframes slideDown {
	from {
		opacity: 0;
		transform: translateY(-4px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
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

.change-summary {
	margin-bottom: 6px;
	padding: 6px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 2px;
	font-size: 10px;
}

.summary-item {
	margin-bottom: 2px;
}

.summary-item:last-child {
	margin-bottom: 0;
}

.change-diff-unified {
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 2px;
	overflow: hidden;
	margin-bottom: 6px;
	background: var(--terminal-bg, #1e1e1e);
	max-height: 300px;
	overflow-y: auto;
}

.diff-lines {
	font-family: 'Courier New', monospace;
	font-size: 10px;
	line-height: 1.5;
}

.diff-line {
	display: flex;
	align-items: flex-start;
	padding: 2px 6px;
	border-left: 2px solid transparent;
}

.diff-line:hover {
	background: rgba(255, 255, 255, 0.02);
}

.diff-line-number {
	display: inline-block;
	width: 40px;
	text-align: right;
	padding-right: 8px;
	color: var(--terminal-text-secondary, #858585);
	font-size: 9px;
	flex-shrink: 0;
	user-select: none;
}

.diff-line-marker {
	display: inline-block;
	width: 12px;
	text-align: center;
	font-weight: 600;
	flex-shrink: 0;
	user-select: none;
}

.diff-line-content {
	flex: 1;
	min-width: 0;
	white-space: pre;
	overflow-x: auto;
}

.diff-line-context {
	color: var(--terminal-text, #d4d4d4);
}

.diff-line-add {
	background: rgba(16, 185, 129, 0.08);
	border-left-color: #10b981;
	color: #10b981;
}

.diff-line-remove {
	background: rgba(239, 68, 68, 0.08);
	border-left-color: #ef4444;
	color: #ef4444;
}

.rejection-reason {
	margin-top: 6px;
	padding: 6px;
	background: rgba(239, 68, 68, 0.1);
	border-left: 2px solid #ef4444;
	border-radius: 2px;
	font-size: 10px;
	color: var(--terminal-text, #d4d4d4);
	line-height: 1.4;
}
</style>

