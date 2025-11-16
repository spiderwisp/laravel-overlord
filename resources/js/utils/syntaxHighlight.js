// Load highlight.js from CDN (no npm install required!)
let hljs = null;
let hljsLoading = false;
let hljsLoadPromise = null;

/**
 * Load highlight.js from CDN
 * @returns {Promise} Promise that resolves when hljs is loaded
 */
function loadHighlightJs() {
	// Return existing promise if already loading
	if (hljsLoadPromise) {
		return hljsLoadPromise;
	}

	// Return immediately if already loaded
	if (hljs) {
		return Promise.resolve(hljs);
	}

	// If already loading, return the existing promise
	if (hljsLoading) {
		return hljsLoadPromise;
	}

	hljsLoading = true;
	hljsLoadPromise = new Promise((resolve, reject) => {
		// Check if hljs is already available globally (from CDN)
		if (typeof window !== 'undefined' && window.hljs) {
			hljs = window.hljs;
			hljsLoading = false;
			resolve(hljs);
			return;
		}

		// Load from CDN
		const script = document.createElement('script');
		script.src = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js';
		script.onload = () => {
			if (typeof window !== 'undefined' && window.hljs) {
				hljs = window.hljs;
				hljsLoading = false;
				resolve(hljs);
			} else {
				hljsLoading = false;
				reject(new Error('highlight.js failed to load'));
			}
		};
		script.onerror = () => {
			hljsLoading = false;
			reject(new Error('Failed to load highlight.js from CDN'));
		};
		document.head.appendChild(script);
	});

	return hljsLoadPromise;
}

/**
 * Highlight code with syntax highlighting
 * @param {string} code - The code to highlight
 * @param {string|null} language - Optional language hint (e.g., 'php', 'javascript')
 * @returns {string} - Highlighted HTML string (synchronously if hljs is loaded, otherwise returns escaped code)
 */
export function highlightCode(code, language = null) {
	if (!code || typeof code !== 'string') {
		return '';
	}

	// Check if hljs is available (either from our local variable or window global)
	let currentHljs = hljs;
	if (typeof window !== 'undefined' && window.hljs && !currentHljs) {
		currentHljs = window.hljs;
		hljs = currentHljs; // Cache it for next time
	}

	// If hljs is available, use it synchronously
	if (currentHljs && typeof currentHljs.highlight === 'function') {
		try {
			// If language is provided, use it directly
			if (language && currentHljs.getLanguage(language)) {
				const result = currentHljs.highlight(code, { language });
				return result.value;
			}

			// Otherwise, auto-detect
			const result = currentHljs.highlightAuto(code, [
				'php',
				'javascript',
				'json',
				'sql',
				'bash',
				'xml',
				'html',
				'css',
			]);

			return result.value;
		} catch (error) {
			console.warn('Syntax highlighting error:', error, { code: code.substring(0, 50), language });
			return escapeHtml(code);
		}
	}

	// If not loaded yet, try to load it (async, but return escaped code for now)
	if (typeof window !== 'undefined') {
		loadHighlightJs().catch(err => {
			console.warn('Failed to load highlight.js:', err);
		});
	}

	// Fallback to escaped plain text until hljs loads
	return escapeHtml(code);
}

/**
 * Highlight code asynchronously (waits for hljs to load if needed)
 * @param {string} code - The code to highlight
 * @param {string|null} language - Optional language hint
 * @returns {Promise<string>} - Promise that resolves with highlighted HTML
 */
export async function highlightCodeAsync(code, language = null) {
	if (!code || typeof code !== 'string') {
		return '';
	}

	// Ensure hljs is loaded
	await loadHighlightJs();

	if (!hljs || typeof hljs.highlight !== 'function') {
		return escapeHtml(code);
	}

	try {
		// If language is provided, use it directly
		if (language && hljs.getLanguage(language)) {
			const result = hljs.highlight(code, { language });
			return result.value;
		}

		// Otherwise, auto-detect
		const result = hljs.highlightAuto(code, [
			'php',
			'javascript',
			'json',
			'sql',
			'bash',
			'xml',
			'html',
			'css',
		]);

		return result.value;
	} catch (error) {
		console.warn('Syntax highlighting error:', error, { code: code.substring(0, 50), language });
		return escapeHtml(code);
	}
}

/**
 * Escape HTML entities
 * @param {string} text - Text to escape
 * @returns {string} - Escaped HTML
 */
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}

/**
 * Get detected language from code (async)
 * @param {string} code - The code to analyze
 * @returns {Promise<string|null>} - Promise that resolves with detected language or null
 */
export async function detectLanguage(code) {
	if (!code || typeof code !== 'string') {
		return null;
	}

	// Ensure hljs is loaded
	await loadHighlightJs();

	if (!hljs || typeof hljs.highlightAuto !== 'function') {
		return null;
	}

	try {
		const result = hljs.highlightAuto(code, [
			'php',
			'javascript',
			'json',
			'sql',
			'bash',
			'xml',
			'html',
			'css',
		]);
		return result.language || null;
	} catch (error) {
		return null;
	}
}

