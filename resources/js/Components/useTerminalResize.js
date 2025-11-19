import { ref, computed, onUnmounted } from 'vue';

export function useTerminalResize() {
	const terminalHeight = ref(60); // Default height in vh
	const terminalHeightMin = 30; // Minimum height in vh
	const terminalHeightMax = 100; // Maximum height in vh (allows full page height)
	const isResizing = ref(false);
	const resizeStartY = ref(0);
	const resizeStartHeight = ref(0);

	// Computed style for terminal drawer height
	const terminalDrawerStyle = computed(() => ({
		height: `${terminalHeight.value}vh`,
	}));

	// Load terminal height from localStorage
	function loadTerminalHeight() {
		const saved = localStorage.getItem('developer_terminal_height');
		if (saved) {
			const parsed = parseFloat(saved);
			if (!isNaN(parsed) && parsed >= terminalHeightMin && parsed <= terminalHeightMax) {
				terminalHeight.value = parsed;
			}
		}
	}

	// Save terminal height to localStorage
	function saveTerminalHeight(height) {
		localStorage.setItem('developer_terminal_height', height.toString());
		terminalHeight.value = height;
	}

	// Handle resize
	function handleResize(event) {
		if (!isResizing.value) return;
		
		const clientY = event.clientY || event.touches[0].clientY;
		const deltaY = resizeStartY.value - clientY; // Negative because we're dragging up
		const deltaVh = (deltaY / window.innerHeight) * 100;
		const newHeight = Math.max(terminalHeightMin, Math.min(terminalHeightMax, resizeStartHeight.value + deltaVh));
		
		terminalHeight.value = newHeight;
	}

	// Stop resizing
	function stopResize() {
		if (isResizing.value) {
			saveTerminalHeight(terminalHeight.value);
			isResizing.value = false;
			document.removeEventListener('mousemove', handleResize);
			document.removeEventListener('mouseup', stopResize);
			document.removeEventListener('touchmove', handleResize);
			document.removeEventListener('touchend', stopResize);
		}
	}

	// Start resizing
	function startResize(event) {
		isResizing.value = true;
		resizeStartY.value = event.clientY || event.touches[0].clientY;
		resizeStartHeight.value = terminalHeight.value;
		document.addEventListener('mousemove', handleResize);
		document.addEventListener('mouseup', stopResize);
		document.addEventListener('touchmove', handleResize);
		document.addEventListener('touchend', stopResize);
		event.preventDefault();
	}

	// Cleanup on unmount
	onUnmounted(() => {
		stopResize();
	});

	return {
		terminalHeight,
		terminalHeightMin,
		terminalHeightMax,
		isResizing,
		terminalDrawerStyle,
		loadTerminalHeight,
		saveTerminalHeight,
		startResize,
		handleResize,
		stopResize,
	};
}

