<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
	data: {
		type: [Object, Array, String, Number, Boolean, null],
		required: true,
	},
	depth: {
		type: Number,
		default: 0,
	},
	maxDepth: {
		type: Number,
		default: 10,
	},
});

const isExpanded = ref(props.depth < 2); // Expand first two levels by default
const isObject = computed(() => typeof props.data === 'object' && props.data !== null && !Array.isArray(props.data));
const isArray = computed(() => Array.isArray(props.data));
const isCollapsible = computed(() => (isObject.value || isArray.value) && props.depth < props.maxDepth);

function toggle() {
	if (isCollapsible.value) {
		isExpanded.value = !isExpanded.value;
	}
}

function getType(value) {
	if (value === null) return 'null';
	if (Array.isArray(value)) return 'array';
	if (typeof value === 'object') return 'object';
	return typeof value;
}

function formatValue(value) {
	if (value === null) return 'null';
	if (typeof value === 'string') return `"${value}"`;
	if (typeof value === 'number' || typeof value === 'boolean') return String(value);
	return JSON.stringify(value);
}

function getKeys() {
	if (isObject.value) {
		return Object.keys(props.data);
	}
	if (isArray.value) {
		return props.data.map((_, i) => i);
	}
	return [];
}
</script>

<template>
	<div class="json-viewer">
		<div v-if="isCollapsible" class="json-item">
			<span class="json-toggle" @click="toggle">
				<span class="json-icon">{{ isExpanded ? '▼' : '▶' }}</span>
			</span>
			<span class="json-key" v-if="props.depth > 0">
				<slot name="key"></slot>
			</span>
			<span class="json-type-badge" :class="isArray ? 'array' : 'object'">
				{{ isArray ? 'Array' : 'Object' }}
			</span>
			<span class="json-count" v-if="isExpanded">
				({{ isArray ? props.data.length : Object.keys(props.data).length }})
			</span>
		</div>

		<div v-else-if="props.depth > 0" class="json-item json-leaf">
			<span class="json-key"><slot name="key"></slot></span>
			<span class="json-separator">:</span>
			<span class="json-value" :class="`json-${getType(props.data)}`">
				{{ formatValue(props.data) }}
			</span>
		</div>

		<div v-else class="json-item json-leaf">
			<span class="json-value" :class="`json-${getType(props.data)}`">
				{{ formatValue(props.data) }}
			</span>
		</div>

		<div v-if="isCollapsible && isExpanded" class="json-children" :style="{ marginLeft: '20px' }">
			<template v-for="(key, index) in getKeys()" :key="key">
				<JsonViewer
					:data="props.data[key]"
					:depth="props.depth + 1"
					:maxDepth="props.maxDepth"
				>
					<template #key>
						<span class="json-key-name">{{ isArray ? `[${key}]` : `"${key}"` }}</span>
					</template>
				</JsonViewer>
			</template>
		</div>
	</div>
</template>

<style scoped>
.json-viewer {
	font-family: 'Courier New', monospace;
	font-size: 13px;
	line-height: 1.6;
}

.json-item {
	display: flex;
	align-items: baseline;
	margin: 2px 0;
}

.json-toggle {
	cursor: pointer;
	user-select: none;
	margin-right: 4px;
	color: #666;
	font-size: 10px;
	width: 12px;
	display: inline-block;
}

.json-icon {
	display: inline-block;
}

.json-key {
	color: #881391;
	margin-right: 6px;
	font-weight: 500;
}

.json-key-name {
	color: #881391;
}

.json-separator {
	color: #666;
	margin: 0 4px;
}

.json-value {
	color: #1a1aa6;
}

.json-string {
	color: #0b7500;
}

.json-number {
	color: #1a1aa6;
}

.json-boolean {
	color: #0033b3;
	font-weight: 500;
}

.json-null {
	color: #808080;
	font-style: italic;
}

.json-type-badge {
	display: inline-block;
	padding: 2px 6px;
	margin-left: 6px;
	font-size: 10px;
	border-radius: 3px;
	background: #e0e0e0;
	color: #333;
	font-weight: 500;
}

.json-type-badge.array {
	background: #e3f2fd;
	color: #1976d2;
}

.json-type-badge.object {
	background: #f3e5f5;
	color: #7b1fa2;
}

.json-count {
	color: #666;
	font-size: 11px;
	margin-left: 6px;
}

.json-children {
	border-left: 1px solid #e0e0e0;
	padding-left: 8px;
	margin-left: 8px;
}

.json-leaf {
	margin-left: 16px;
}
</style>

