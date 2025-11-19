<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
	modelValue: {
		type: [String, Number],
		required: true,
	},
	options: {
		type: Array,
		required: true,
		validator: (value) => {
			return value.every(opt => opt.value !== undefined && opt.label !== undefined);
		},
	},
	placeholder: {
		type: String,
		default: 'Select an option',
	},
	disabled: {
		type: Boolean,
		default: false,
	},
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const selectedIndex = ref(-1);
const dropdownRef = ref(null);
const buttonRef = ref(null);

// Find selected option
const selectedOption = computed(() => {
	return props.options.find(opt => opt.value === props.modelValue) || null;
});

// Display text
const displayText = computed(() => {
	if (selectedOption.value) {
		return selectedOption.value.label;
	}
	return props.placeholder;
});

// Handle option selection
function selectOption(option) {
	if (props.disabled) return;
	
	emit('update:modelValue', option.value);
	emit('change', option.value);
	isOpen.value = false;
	selectedIndex.value = -1;
}

// Toggle dropdown
function toggleDropdown() {
	if (props.disabled) return;
	isOpen.value = !isOpen.value;
	if (isOpen.value) {
		// Find and highlight current selection
		const currentIndex = props.options.findIndex(opt => opt.value === props.modelValue);
		selectedIndex.value = currentIndex >= 0 ? currentIndex : 0;
	}
}

// Close dropdown
function closeDropdown() {
	isOpen.value = false;
	selectedIndex.value = -1;
}

// Handle keyboard navigation
function handleKeyDown(event) {
	if (props.disabled) return;

	if (event.key === 'Escape') {
		closeDropdown();
		buttonRef.value?.focus();
		return;
	}

	if (!isOpen.value) {
		if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault();
			toggleDropdown();
		}
		return;
	}

	event.preventDefault();

	switch (event.key) {
		case 'ArrowDown':
			selectedIndex.value = Math.min(selectedIndex.value + 1, props.options.length - 1);
			scrollToSelected();
			break;
		case 'ArrowUp':
			selectedIndex.value = Math.max(selectedIndex.value - 1, 0);
			scrollToSelected();
			break;
		case 'Enter':
		case ' ':
			if (selectedIndex.value >= 0 && selectedIndex.value < props.options.length) {
				selectOption(props.options[selectedIndex.value]);
			}
			break;
		case 'Home':
			selectedIndex.value = 0;
			scrollToSelected();
			break;
		case 'End':
			selectedIndex.value = props.options.length - 1;
			scrollToSelected();
			break;
	}
}

// Scroll to selected option in dropdown
function scrollToSelected() {
	if (!dropdownRef.value) return;
	
	const options = dropdownRef.value.querySelectorAll('.terminal-dropdown-option');
	if (options[selectedIndex.value]) {
		options[selectedIndex.value].scrollIntoView({ block: 'nearest' });
	}
}

// Handle click outside
function handleClickOutside(event) {
	if (
		dropdownRef.value &&
		!dropdownRef.value.contains(event.target) &&
		buttonRef.value &&
		!buttonRef.value.contains(event.target)
	) {
		closeDropdown();
	}
}

// Watch for external value changes
watch(() => props.modelValue, () => {
	if (!isOpen.value) {
		selectedIndex.value = -1;
	}
});

onMounted(() => {
	document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
	document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
	<div class="terminal-dropdown" :class="{ 'terminal-dropdown-open': isOpen, 'terminal-dropdown-disabled': disabled }">
		<button
			ref="buttonRef"
			type="button"
			class="terminal-dropdown-button"
			:disabled="disabled"
			@click="toggleDropdown"
			@keydown="handleKeyDown"
			:aria-expanded="isOpen"
			:aria-haspopup="true"
		>
			<span class="terminal-dropdown-button-text">{{ displayText }}</span>
			<svg
				class="terminal-dropdown-button-icon"
				:class="{ 'terminal-dropdown-button-icon-open': isOpen }"
				xmlns="http://www.w3.org/2000/svg"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
			>
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
			</svg>
		</button>

		<Transition name="dropdown">
			<div
				v-if="isOpen"
				ref="dropdownRef"
				class="terminal-dropdown-menu"
				role="listbox"
			>
				<button
					v-for="(option, index) in options"
					:key="option.value"
					type="button"
					class="terminal-dropdown-option"
					:class="{
						'terminal-dropdown-option-selected': option.value === modelValue,
						'terminal-dropdown-option-highlighted': index === selectedIndex,
					}"
					:role="option.value === modelValue ? 'option aria-selected' : 'option'"
					:aria-selected="option.value === modelValue"
					@click="selectOption(option)"
					@mouseenter="selectedIndex = index"
				>
					<div class="terminal-dropdown-option-content">
						<span class="terminal-dropdown-option-label">{{ option.label }}</span>
						<span v-if="option.description" class="terminal-dropdown-option-description">
							{{ option.description }}
						</span>
					</div>
					<svg
						v-if="option.value === modelValue"
						class="terminal-dropdown-option-check"
						xmlns="http://www.w3.org/2000/svg"
						fill="none"
						viewBox="0 0 24 24"
						stroke="currentColor"
					>
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
					</svg>
				</button>
			</div>
		</Transition>
	</div>
</template>

<style scoped>
.terminal-dropdown {
	position: relative;
	width: 100%;
}

.terminal-dropdown-button {
	width: 100%;
	padding: 10px 12px;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-md, 14px);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	cursor: pointer;
	transition: all 0.2s ease;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-weight: 500;
	text-align: left;
}

.terminal-dropdown-button:hover:not(:disabled) {
	border-color: var(--terminal-primary, #0e639c);
	background: var(--terminal-bg-tertiary, #2d2d30);
}

.terminal-dropdown-button:focus {
	outline: 2px solid var(--terminal-primary, #0e639c);
	outline-offset: 2px;
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-dropdown-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.terminal-dropdown-button-text {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-dropdown-button-icon {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
	transition: transform 0.2s ease;
	color: var(--terminal-text-secondary, #858585);
}

.terminal-dropdown-button-icon-open {
	transform: rotate(180deg);
}

.terminal-dropdown-menu {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	right: 0;
	background: var(--terminal-bg-secondary, #252526);
	border: 2px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	box-shadow: 
		0 4px 12px rgba(0, 0, 0, 0.3),
		0 0 0 1px var(--terminal-border, #3e3e42);
	max-height: 300px;
	overflow-y: auto;
	overflow-x: hidden;
	z-index: 10010;
	margin-top: 2px;
}

.terminal-dropdown-menu::-webkit-scrollbar {
	width: 8px;
}

.terminal-dropdown-menu::-webkit-scrollbar-track {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-radius: 4px;
}

.terminal-dropdown-menu::-webkit-scrollbar-thumb {
	background: var(--terminal-border, #3e3e42);
	border-radius: 4px;
}

.terminal-dropdown-menu::-webkit-scrollbar-thumb:hover {
	background: var(--terminal-border-hover, #4e4e52);
}

.terminal-dropdown-option {
	width: 100%;
	padding: 10px 12px;
	background: transparent;
	border: none;
	color: var(--terminal-text, #d4d4d4);
	font-size: var(--terminal-font-size-md, 14px);
	font-family: var(--terminal-font-family, 'Consolas', 'Monaco', monospace);
	cursor: pointer;
	transition: all 0.15s ease;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	text-align: left;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-dropdown-option:last-child {
	border-bottom: none;
}

.terminal-dropdown-option:hover,
.terminal-dropdown-option-highlighted {
	background: var(--terminal-bg-tertiary, #2d2d30);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-dropdown-option-selected {
	background: color-mix(in srgb, var(--terminal-primary) 15%, transparent);
	color: var(--terminal-primary, #0e639c);
	font-weight: 600;
}

.terminal-dropdown-option-selected:hover,
.terminal-dropdown-option-selected.terminal-dropdown-option-highlighted {
	background: color-mix(in srgb, var(--terminal-primary) 25%, transparent);
}

.terminal-dropdown-option-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.terminal-dropdown-option-label {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-dropdown-option-description {
	font-size: var(--terminal-font-size-sm, 12px);
	color: var(--terminal-text-secondary, #858585);
	opacity: 0.8;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-dropdown-option-selected .terminal-dropdown-option-description {
	color: var(--terminal-primary, #0e639c);
	opacity: 0.9;
}

.terminal-dropdown-option-check {
	width: 18px;
	height: 18px;
	flex-shrink: 0;
	color: var(--terminal-primary, #0e639c);
}

/* Transitions */
.dropdown-enter-active,
.dropdown-leave-active {
	transition: all 0.2s ease;
}

.dropdown-enter-from {
	opacity: 0;
	transform: translateY(-8px);
}

.dropdown-leave-to {
	opacity: 0;
	transform: translateY(-8px);
}
</style>

