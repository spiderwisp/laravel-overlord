import { ref, watch, onMounted } from 'vue';

// Available font families
export const fontFamilies = {
	system: {
		name: 'System',
		description: 'System default font',
		value: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
	},
	monospace: {
		name: 'Monospace',
		description: 'Monospace font for code',
		value: '"Consolas", "Monaco", "Menlo", "Courier New", monospace',
	},
	sans: {
		name: 'Sans Serif',
		description: 'Clean sans-serif font',
		value: '"Inter", "Roboto", "Helvetica Neue", Arial, sans-serif',
	},
	serif: {
		name: 'Serif',
		description: 'Traditional serif font',
		value: '"Georgia", "Times New Roman", serif',
	},
	'code-friendly': {
		name: 'Code Friendly',
		description: 'Optimized for code readability',
		value: '"Fira Code", "JetBrains Mono", "Consolas", monospace',
	},
};

// Font size configuration
const fontSizeMin = 10;
const fontSizeMax = 24;
const fontSizeDefault = 14; // Increased from 13 for better readability

// Current font state
const fontSize = ref(fontSizeDefault);
const fontFamily = ref('monospace');
const lineHeight = ref(1.6);
const fontRoot = ref(null);

// Initialize font root element
export function initFontRoot(element) {
	fontRoot.value = element;
	loadFontPreferences();
}

// Load font preferences from localStorage
function loadFontPreferences() {
	// Load font size
	const savedSize = localStorage.getItem('terminal_font_size');
	if (savedSize) {
		const parsed = parseInt(savedSize, 10);
		if (!isNaN(parsed) && parsed >= fontSizeMin && parsed <= fontSizeMax) {
			fontSize.value = parsed;
		}
	}

	// Load font family
	const savedFamily = localStorage.getItem('terminal_font_family');
	if (savedFamily && fontFamilies[savedFamily]) {
		fontFamily.value = savedFamily;
	}

	// Load line height
	const savedLineHeight = localStorage.getItem('terminal_line_height');
	if (savedLineHeight) {
		const parsed = parseFloat(savedLineHeight);
		if (!isNaN(parsed) && parsed >= 1.2 && parsed <= 2.5) {
			lineHeight.value = parsed;
		}
	}

	applyFontSettings();
}

// Apply font settings to root element
function applyFontSettings() {
	if (!fontRoot.value) {
		return;
	}

	// Apply font size as base size (all children will inherit)
	fontRoot.value.style.setProperty('--terminal-font-size-base', `${fontSize.value}px`);
	
	// Calculate derived sizes
	fontRoot.value.style.setProperty('--terminal-font-size-xs', `${Math.max(8, fontSize.value - 4)}px`);
	fontRoot.value.style.setProperty('--terminal-font-size-sm', `${Math.max(10, fontSize.value - 2)}px`);
	fontRoot.value.style.setProperty('--terminal-font-size-md', `${fontSize.value}px`);
	fontRoot.value.style.setProperty('--terminal-font-size-lg', `${fontSize.value + 2}px`);
	fontRoot.value.style.setProperty('--terminal-font-size-xl', `${fontSize.value + 4}px`);
	fontRoot.value.style.setProperty('--terminal-font-size-2xl', `${fontSize.value + 6}px`);

	// Apply font family (all children will inherit)
	const familyConfig = fontFamilies[fontFamily.value] || fontFamilies.monospace;
	fontRoot.value.style.setProperty('--terminal-font-family', familyConfig.value);

	// Apply line height (all children will inherit)
	fontRoot.value.style.setProperty('--terminal-line-height', lineHeight.value.toString());
}

// Set font size
export function setFontSize(size) {
	const newSize = Math.max(fontSizeMin, Math.min(fontSizeMax, size));
	fontSize.value = newSize;
	localStorage.setItem('terminal_font_size', newSize.toString());
	applyFontSettings();
}

// Adjust font size
export function adjustFontSize(delta) {
	setFontSize(fontSize.value + delta);
}

// Set font family
export function setFontFamily(family) {
	if (!fontFamilies[family]) {
		return;
	}
	
	fontFamily.value = family;
	localStorage.setItem('terminal_font_family', family);
	applyFontSettings();
}

// Set line height
export function setLineHeight(height) {
	const newHeight = Math.max(1.2, Math.min(2.5, height));
	lineHeight.value = newHeight;
	localStorage.setItem('terminal_line_height', newHeight.toString());
	applyFontSettings();
}

// Get current font size
export function getFontSize() {
	return fontSize.value;
}

// Get current font family
export function getFontFamily() {
	return fontFamily.value;
}

// Get current line height
export function getLineHeight() {
	return lineHeight.value;
}

// Composable for Vue components
export function useTerminalFont() {
	onMounted(() => {
		loadFontPreferences();
	});

	watch([fontSize, fontFamily, lineHeight], () => {
		applyFontSettings();
	});

	return {
		fontSize,
		fontFamily,
		lineHeight,
		fontFamilies,
		fontSizeMin,
		fontSizeMax,
		setFontSize,
		adjustFontSize,
		setFontFamily,
		setLineHeight,
		getFontSize,
		getFontFamily,
		getLineHeight,
	};
}

