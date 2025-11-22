<template>
	<div class="file-change-preview">
		<div class="change-header">
			<div class="change-file-path">{{ change.file_path }}</div>
			<div class="change-status" :class="`status-${change.status}`">
				{{ change.status }}
			</div>
		</div>
		
		<div v-if="change.change_summary" class="change-summary">
			<div class="summary-item">
				<strong>Issue:</strong> {{ change.change_summary.issue_message }}
			</div>
			<div v-if="change.change_summary.issue_line" class="summary-item">
				<strong>Line:</strong> {{ change.change_summary.issue_line }}
			</div>
		</div>

		<div class="change-diff">
			<div class="diff-header">
				<span class="diff-label diff-old">Original</span>
				<span class="diff-label diff-new">New</span>
			</div>
			<div class="diff-content">
				<div class="diff-side diff-old">
					<pre>{{ change.original_content || '(empty)' }}</pre>
				</div>
				<div class="diff-side diff-new">
					<pre>{{ change.new_content || '(empty)' }}</pre>
				</div>
			</div>
		</div>

		<div v-if="change.status === 'pending'" class="change-actions">
			<button
				@click="$emit('approve', change.id)"
				class="terminal-btn terminal-btn-primary terminal-btn-sm"
			>
				Approve
			</button>
			<button
				@click="$emit('reject', change.id)"
				class="terminal-btn terminal-btn-danger terminal-btn-sm"
			>
				Reject
			</button>
		</div>

		<div v-if="change.status === 'rejected' && change.rejection_reason" class="rejection-reason">
			<strong>Rejection Reason:</strong> {{ change.rejection_reason }}
		</div>
	</div>
</template>

<script setup>
const props = defineProps({
	change: {
		type: Object,
		required: true,
	},
});

defineEmits(['approve', 'reject']);
</script>

<style scoped>
.file-change-preview {
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	padding: 12px;
	background: var(--terminal-bg-secondary, #252526);
}

.change-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.change-file-path {
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-sm, 12px);
}

.change-status {
	padding: 2px 8px;
	border-radius: 4px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
	text-transform: uppercase;
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
	margin-bottom: 12px;
	padding: 8px;
	background: var(--terminal-bg, #1e1e1e);
	border-radius: 4px;
	font-size: var(--terminal-font-size-sm, 12px);
}

.summary-item {
	margin-bottom: 4px;
}

.summary-item:last-child {
	margin-bottom: 0;
}

.change-diff {
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	overflow: hidden;
	margin-bottom: 12px;
}

.diff-header {
	display: flex;
	background: var(--terminal-bg-tertiary, #3e3e42);
	padding: 4px 8px;
	font-size: var(--terminal-font-size-xs, 11px);
	font-weight: 600;
}

.diff-label {
	flex: 1;
	padding: 4px;
}

.diff-old {
	color: #ef4444;
}

.diff-new {
	color: #10b981;
}

.diff-content {
	display: flex;
	max-height: 300px;
	overflow: auto;
}

.diff-side {
	flex: 1;
	padding: 8px;
	background: var(--terminal-bg, #1e1e1e);
}

.diff-side.diff-old {
	border-right: 1px solid var(--terminal-border, #3e3e42);
}

.diff-side pre {
	margin: 0;
	font-size: var(--terminal-font-size-xs, 11px);
	color: var(--terminal-text, #d4d4d4);
	white-space: pre-wrap;
	word-wrap: break-word;
}

.change-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
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

