<script setup>
const props = defineProps({
	show: {
		type: Boolean,
		default: false,
	},
	topFavorites: {
		type: Array,
		default: () => [],
	},
	getFavoriteTypeColor: {
		type: Function,
		required: true,
	},
	getFavoriteTypeLabel: {
		type: Function,
		required: true,
	},
});

const emit = defineEmits(['insert-favorite', 'execute-favorite', 'toggle-favorites']);
</script>

<template>
	<div 
		class="terminal-favorites-tray"
		:class="{ 'drawer-open': show }"
	>
		<div class="terminal-favorites-tray-shelf">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
				<path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
			</svg>
			<span class="terminal-favorites-tray-label">Favorites</span>
		</div>
		<transition name="favorites-tray">
			<div 
				v-if="show" 
				class="terminal-favorites-tray-content"
			>
				<div class="terminal-favorites-tray-header">
					<h3>Quick Access</h3>
					<button @click="emit('toggle-favorites')" class="terminal-favorites-tray-view-all">
						View All
					</button>
				</div>
				<div v-if="topFavorites.length > 0" class="terminal-favorites-tray-list">
					<div
						v-for="favorite in topFavorites"
						:key="favorite.id"
						class="terminal-favorites-tray-item"
					>
						<div class="terminal-favorites-tray-item-info">
							<span class="terminal-favorites-tray-item-name">{{ favorite.name }}</span>
							<span 
								class="terminal-favorites-tray-item-type"
								:style="{ color: getFavoriteTypeColor(favorite.type) }"
							>
								{{ getFavoriteTypeLabel(favorite.type) }}
							</span>
						</div>
						<div class="terminal-favorites-tray-item-actions">
							<button
								@click="emit('insert-favorite', favorite)"
								class="terminal-favorites-tray-action-btn"
								title="Insert"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0110.5 3h6a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0116.5 21h-6a2.25 2.25 0 01-2.25-2.25V15m-3 0l3-3m0 0l3 3m-3-3H15" />
								</svg>
							</button>
							<button
								@click="emit('execute-favorite', favorite)"
								class="terminal-favorites-tray-action-btn"
								title="Execute"
							>
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
									<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z" />
								</svg>
							</button>
						</div>
					</div>
				</div>
				<div v-else class="terminal-favorites-tray-empty">
					<p>No favorites yet. Add commands to favorites for quick access.</p>
				</div>
			</div>
		</transition>
	</div>
</template>

<style scoped>
/* Favorites Tray (Top-Aligned Full-Width Drawer) */
.terminal-favorites-tray {
	position: absolute;
	top: 0;
	left: 50%;
	transform: translateX(-50%);
	z-index: 10003; /* High enough to be visible */
	pointer-events: none;
	width: 200px;
	overflow: visible; /* Allow content to break out */
}

.terminal-favorites-tray-shelf {
	pointer-events: auto;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	height: 24px; /* More visible, easier to hover */
	padding: 0 12px;
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-top: none;
	border-radius: 0 0 4px 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s ease;
	width: 100%;
	position: absolute;
	top: 0;
	left: 0;
	z-index: 10003; /* Above tabs (10002) and tray content */
}

.terminal-favorites-tray-shelf:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-shelf:hover .terminal-favorites-tray-label {
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-shelf svg {
	width: 16px;
	height: 16px;
	transition: all 0.2s;
	flex-shrink: 0;
	opacity: 0.8;
}

.terminal-favorites-tray-shelf:hover svg {
	color: var(--terminal-primary, #0e639c);
	opacity: 1;
}

.terminal-favorites-tray-label {
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
	opacity: 0.9;
}

.terminal-favorites-tray-content {
	pointer-events: auto;
	position: absolute;
	top: 0; /* Extend to top of page */
	left: calc(-50vw + 110px + 50%); /* Position from left edge of main content */
	width: calc(100vw - 220px); /* Full width minus sidebar */
	background: var(--terminal-bg-secondary, #252526);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-top: none;
	border-radius: 0 0 8px 8px;
	padding: 16px;
	padding-top: 40px; /* Space for the shelf button */
	max-height: 500px;
	overflow-y: auto;
	overflow-x: hidden;
	box-shadow: 0 4px 12px var(--terminal-shadow, rgba(0, 0, 0, 0.3));
	z-index: 9999; /* Below shelf (10003) and tabs (10002) but above content */
	margin-top: 0;
}

.terminal-favorites-tray-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--terminal-border, #3e3e42);
}

.terminal-favorites-tray-header h3 {
	margin: 0;
	font-size: var(--terminal-font-size-base, 14px);
	font-weight: 600;
	color: var(--terminal-text, #d4d4d4);
}

.terminal-favorites-tray-view-all {
	padding: 4px 12px;
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-primary, #0e639c);
	font-size: var(--terminal-font-size-sm, 12px);
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-tray-view-all:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
}

.terminal-favorites-tray-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 12px;
}

.terminal-favorites-tray-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	background: var(--terminal-bg, #1e1e1e);
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 6px;
	transition: all 0.2s;
}

.terminal-favorites-tray-item:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-border-hover, #4e4e52);
}

.terminal-favorites-tray-item-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
	min-width: 0;
}

.terminal-favorites-tray-item-name {
	font-size: var(--terminal-font-size-sm, 12px);
	font-weight: 500;
	color: var(--terminal-text, #d4d4d4);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.terminal-favorites-tray-item-type {
	font-size: 10px;
	opacity: 0.7;
}

.terminal-favorites-tray-item-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.terminal-favorites-tray-action-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	padding: 0;
	background: transparent;
	border: 1px solid var(--terminal-border, #3e3e42);
	border-radius: 4px;
	color: var(--terminal-text-secondary, #858585);
	cursor: pointer;
	transition: all 0.2s;
}

.terminal-favorites-tray-action-btn:hover {
	background: var(--terminal-bg-tertiary, #2d2d30);
	border-color: var(--terminal-primary, #0e639c);
	color: var(--terminal-primary, #0e639c);
}

.terminal-favorites-tray-action-btn svg {
	width: 14px;
	height: 14px;
}

.terminal-favorites-tray-empty {
	padding: 24px;
	text-align: center;
	color: var(--terminal-text-secondary, #858585);
	font-size: var(--terminal-font-size-sm, 12px);
}

/* Favorites Tray Transitions */
.favorites-tray-enter-active,
.favorites-tray-leave-active {
	transition: all 0.3s ease;
}

.favorites-tray-enter-from,
.favorites-tray-leave-to {
	opacity: 0;
	transform: translateY(-10px);
}
</style>

