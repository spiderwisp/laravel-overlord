<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { useOverlordApi } from '../useOverlordApi';
import Swal from '../../utils/swalConfig';

const api = useOverlordApi();

const props = defineProps({
	visible: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['close']);

// Form state
const title = ref('');
const description = ref('');
const stepsToReproduce = ref('');
const errorMessage = ref('');
const stackTrace = ref('');

// Optional data checkboxes
const includeSystemInfo = ref(true);
const includeEnvironmentInfo = ref(true);
const includeBrowserInfo = ref(true);
const includePackageVersion = ref(true);

// Submission state
const submitting = ref(false);
const submitError = ref(null);

// Validation
const isFormValid = computed(() => {
	return title.value.trim().length > 0 && description.value.trim().length > 0;
});

// Submit bug report
async function submitBugReport() {
	if (!isFormValid.value || submitting.value) return;

	submitError.value = null;
	submitting.value = true;

	try {
		const payload = {
			title: title.value.trim(),
			description: description.value.trim(),
			steps_to_reproduce: stepsToReproduce.value.trim() || null,
			error_message: errorMessage.value.trim() || null,
			stack_trace: stackTrace.value.trim() || null,
			include_system_info: includeSystemInfo.value,
			include_environment_info: includeEnvironmentInfo.value,
			include_browser_info: includeBrowserInfo.value,
			include_package_version: includePackageVersion.value,
		};

		const response = await axios.post(api.bugReport.submit(), payload);

		if (response.data && response.data.success) {
			// Show success message
			await Swal.fire({
				icon: 'success',
				title: 'Bug Report Submitted',
				text: 'Thank you for reporting this bug. We will review it shortly.',
				timer: 3000,
				showConfirmButton: false,
			});

			// Reset form
			resetForm();
		} else {
			throw new Error(response.data?.error || 'Failed to submit bug report');
		}
	} catch (error) {
		console.error('Bug report submission error:', error);
		submitError.value = error.response?.data?.error 
			|| error.message 
			|| 'Failed to submit bug report. Please try again.';

		await Swal.fire({
			icon: 'error',
			title: 'Submission Failed',
			text: submitError.value,
		});
	} finally {
		submitting.value = false;
	}
}

// Reset form
function resetForm() {
	title.value = '';
	description.value = '';
	stepsToReproduce.value = '';
	errorMessage.value = '';
	stackTrace.value = '';
	includeSystemInfo.value = true;
	includeEnvironmentInfo.value = true;
	includeBrowserInfo.value = true;
	includePackageVersion.value = true;
	submitError.value = null;
}
</script>

<template>
	<div v-if="visible" class="terminal-bug-report">
		<div class="bug-report-content">
			<div class="bug-report-header">
				<h2>Report a Bug</h2>
				<p class="subtitle">Help us improve Laravel Overlord by reporting bugs you encounter.</p>
			</div>

			<div class="bug-report-form">
			<div class="form-group">
				<label for="title">
					Title<span class="required">*</span>
				</label>
				<input
					id="title"
					v-model="title"
					type="text"
					class="form-control"
					placeholder="Brief description of the bug"
					:disabled="submitting"
				/>
			</div>

			<div class="form-group">
				<label for="description">
					Description<span class="required">*</span>
				</label>
				<textarea
					id="description"
					v-model="description"
					class="form-control"
					rows="4"
					placeholder="Detailed description of what happened"
					:disabled="submitting"
				></textarea>
			</div>

			<div class="form-group">
				<label for="steps-to-reproduce">
					Steps to Reproduce
				</label>
				<textarea
					id="steps-to-reproduce"
					v-model="stepsToReproduce"
					class="form-control"
					rows="3"
					placeholder="1. First step&#10;2. Second step&#10;3. ..."
					:disabled="submitting"
				></textarea>
			</div>

			<div class="form-group">
				<label for="error-message">
					Error Message (if applicable)
				</label>
				<textarea
					id="error-message"
					v-model="errorMessage"
					class="form-control"
					rows="2"
					placeholder="The error message you received"
					:disabled="submitting"
				></textarea>
			</div>

			<div class="form-group">
				<label for="stack-trace">
					Stack Trace (if applicable)
				</label>
				<textarea
					id="stack-trace"
					v-model="stackTrace"
					class="form-control"
					rows="5"
					placeholder="Paste the full stack trace here"
					:disabled="submitting"
				></textarea>
			</div>

			<div class="form-group">
				<label class="section-label">Include Additional Information</label>
				<div class="checkbox-group">
					<label class="checkbox-label">
						<input
							v-model="includeSystemInfo"
							type="checkbox"
							:disabled="submitting"
						/>
						<span>System Info (PHP version, Laravel version, OS)</span>
					</label>
					<label class="checkbox-label">
						<input
							v-model="includeEnvironmentInfo"
							type="checkbox"
							:disabled="submitting"
						/>
						<span>Environment Info (APP_ENV, APP_DEBUG, database type, cache driver)</span>
					</label>
					<label class="checkbox-label">
						<input
							v-model="includeBrowserInfo"
							type="checkbox"
							:disabled="submitting"
						/>
						<span>Browser Info (User agent, platform)</span>
					</label>
					<label class="checkbox-label">
						<input
							v-model="includePackageVersion"
							type="checkbox"
							:disabled="submitting"
						/>
						<span>Package Version</span>
					</label>
				</div>
			</div>

			<div v-if="submitError" class="error-message">
				{{ submitError }}
			</div>

			<div class="form-actions">
				<button
					type="button"
					class="btn btn-secondary"
					@click="resetForm"
					:disabled="submitting"
				>
					Reset
				</button>
				<button
					type="button"
					class="btn btn-primary"
					:disabled="!isFormValid || submitting"
					@click="submitBugReport"
				>
					<span v-if="submitting">Submitting...</span>
					<span v-else>Submit Bug Report</span>
				</button>
			</div>
		</div>
		</div>
	</div>
</template>

<style scoped>
.terminal-bug-report {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
	background: var(--terminal-bg, #1e1e1e);
	color: var(--terminal-text, #d4d4d4);
}

.bug-report-content {
	flex: 1;
	overflow-y: auto;
	padding: 1.5rem;
	max-width: 800px;
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

.bug-report-header {
	margin-bottom: 2rem;
}

.bug-report-header h2 {
	margin: 0 0 0.5rem 0;
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.bug-report-header .subtitle {
	margin: 0;
	color: var(--terminal-text-secondary, #858585);
	font-size: 0.9rem;
}

.bug-report-form {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label {
	font-weight: 500;
	font-size: 0.9rem;
	color: var(--terminal-text, #d4d4d4);
}

.form-group label .required {
	color: #dc3545;
	margin-left: 2px;
}

.form-group .section-label {
	font-weight: 600;
	margin-bottom: 0.5rem;
	color: var(--terminal-text, #d4d4d4);
}

.form-control {
	padding: 10px 14px;
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	font-size: var(--terminal-font-size-md, 14px);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	transition: border-color 0.2s ease;
	width: 100%;
	box-sizing: border-box;
}

.form-control:focus {
	outline: none;
	border-color: var(--terminal-primary, #0e639c);
	box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.2);
}

.form-control:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.form-control::placeholder {
	color: var(--terminal-text-secondary, #858585);
}

textarea.form-control {
	resize: vertical;
	min-height: 80px;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	line-height: 1.6;
}

.checkbox-group {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 12px;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
}

.checkbox-label {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	cursor: pointer;
	font-weight: normal;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-md, 14px);
	transition: color 0.2s ease;
}

.checkbox-label:hover {
	color: var(--terminal-text, #d4d4d4);
}

.checkbox-label input[type="checkbox"] {
	width: 18px;
	height: 18px;
	cursor: pointer;
	accent-color: var(--terminal-primary, #0e639c);
	flex-shrink: 0;
}

.error-message {
	padding: 12px 14px;
	background: rgba(220, 53, 69, 0.1);
	border: 2px solid #dc3545;
	border-radius: 6px;
	color: #dc3545;
	font-size: var(--terminal-font-size-md, 14px);
}

.form-actions {
	display: flex;
	gap: 1rem;
	justify-content: flex-end;
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--terminal-border, #3e3e42);
}

.btn {
	padding: 8px 16px;
	border: 2px solid transparent;
	border-radius: 6px;
	font-size: var(--terminal-font-size-md, 14px);
	font-weight: 500;
	cursor: pointer;
	transition: all 0.2s ease;
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	display: flex;
	align-items: center;
	gap: 6px;
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
}

.btn:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.btn-primary {
	background: var(--terminal-primary, #0e639c);
	border-color: var(--terminal-primary, #0e639c);
	color: white;
}

.btn-primary:hover:not(:disabled) {
	background: var(--terminal-primary-hover, #1177bb);
	border-color: var(--terminal-primary-hover, #1177bb);
}

.btn-secondary {
	background: var(--terminal-bg-secondary, #252526);
	color: var(--terminal-text, #d4d4d4);
	border-color: var(--terminal-border, #3e3e42);
}

.btn-secondary:hover:not(:disabled) {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
}

/* Scrollbar styling to match terminal theme */
.bug-report-content::-webkit-scrollbar {
	width: 10px;
}

.bug-report-content::-webkit-scrollbar-track {
	background: var(--terminal-bg, #1e1e1e);
}

.bug-report-content::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 5px;
}

.bug-report-content::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-text-secondary, #858585);
}
</style>

