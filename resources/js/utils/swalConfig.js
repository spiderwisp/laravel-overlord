import Swal from 'sweetalert2';

// Get CSS variable value from terminal wrapper
function getCSSVariable(variableName) {
	if (typeof document === 'undefined') {
		return null;
	}
	
	// Try to find the terminal wrapper
	const wrapper = document.querySelector('.developer-terminal-wrapper');
	if (!wrapper) {
		// Fallback to document root
		const value = getComputedStyle(document.documentElement).getPropertyValue(variableName).trim();
		return value || null;
	}
	
	const value = getComputedStyle(wrapper).getPropertyValue(variableName).trim();
	return value || null;
}

// Get current theme name
function getCurrentTheme() {
	if (typeof document === 'undefined') {
		return 'dark';
	}
	
	const wrapper = document.querySelector('.developer-terminal-wrapper');
	if (!wrapper) {
		return 'dark';
	}
	
	return wrapper.getAttribute('data-terminal-theme') || 'dark';
}

// Check if current theme is light
function isLightTheme() {
	const theme = getCurrentTheme();
	return theme === 'light' || theme === 'high-contrast-light';
}

// Get theme-aware config
function getThemeConfig() {
	const isLight = isLightTheme();
	
	// Get CSS variables with fallbacks
	const bg = getCSSVariable('--terminal-bg') || (isLight ? '#ffffff' : '#1e1e1e');
	const bgSecondary = getCSSVariable('--terminal-bg-secondary') || (isLight ? '#f5f5f5' : '#252526');
	const bgTertiary = getCSSVariable('--terminal-bg-tertiary') || (isLight ? '#e5e5e5' : '#2d2d30');
	const border = getCSSVariable('--terminal-border') || (isLight ? '#d1d5db' : '#3e3e42');
	const borderHover = getCSSVariable('--terminal-border-hover') || (isLight ? '#9ca3af' : '#4e4e52');
	const text = getCSSVariable('--terminal-text') || (isLight ? '#1f2937' : '#d4d4d4');
	const textSecondary = getCSSVariable('--terminal-text-secondary') || (isLight ? '#4b5563' : '#858585');
	const primary = getCSSVariable('--terminal-primary') || (isLight ? '#2563eb' : '#0e639c');
	const primaryHover = getCSSVariable('--terminal-primary-hover') || (isLight ? '#1d4ed8' : '#1177bb');
	const error = getCSSVariable('--terminal-error') || (isLight ? '#dc2626' : '#f48771');
	const backdrop = isLight ? 'rgba(0, 0, 0, 0.3)' : 'rgba(0, 0, 0, 0.7)';
	
	return {
		colorScheme: isLight ? 'light' : 'dark',
		background: bg,
		backdrop: backdrop,
		confirmButtonColor: primary,
		cancelButtonColor: border,
		denyButtonColor: error,
		inputBackgroundColor: bg,
		inputBorderColor: border,
		inputColor: text,
		popup: bgSecondary,
		titleColor: text,
		textColor: text,
		iconColor: textSecondary,
		closeButtonColor: textSecondary,
		validationMessageColor: error,
		validationMessageBackground: bgSecondary,
		buttonsStyling: true,
		customClass: {
			popup: 'swal2-terminal-popup',
			title: 'swal2-terminal-title',
			htmlContainer: 'swal2-terminal-content',
			confirmButton: 'swal2-terminal-confirm',
			cancelButton: 'swal2-terminal-cancel',
			denyButton: 'swal2-terminal-deny',
			input: 'swal2-terminal-input',
			validationMessage: 'swal2-terminal-validation',
		},
	};
}

// Create a function that returns theme-aware Swal instance
function getSwalInstance() {
	return Swal.mixin(getThemeConfig());
}

// Create default instance (will be updated when theme changes)
let SwalThemed = getSwalInstance();

// Inject custom CSS that uses CSS variables for theme support
if (typeof document !== 'undefined') {
	const styleId = 'swal2-terminal-theme-custom';
	if (!document.getElementById(styleId)) {
		const style = document.createElement('style');
		style.id = styleId;
		style.textContent = `
			.swal2-terminal-popup {
				background: var(--terminal-bg-secondary, #252526) !important;
				border: 1px solid var(--terminal-border, #3e3e42) !important;
			}
			.swal2-terminal-title {
				color: var(--terminal-text, #d4d4d4) !important;
			}
		.swal2-terminal-content {
			color: var(--terminal-text, #d4d4d4) !important;
		}
		/* Center content by default, but allow inline styles to override */
		.swal2-terminal-content > div[style*="text-align: center"] {
			text-align: center !important;
		}
		.swal2-terminal-content details {
			text-align: left !important;
		}
		.swal2-terminal-content pre {
			text-align: left !important;
		}
		.swal2-terminal-content summary {
			text-align: center !important;
		}
			.swal2-terminal-confirm {
				background: var(--terminal-primary, #0e639c) !important;
				color: #ffffff !important;
				border: none !important;
			}
			.swal2-terminal-confirm:hover {
				background: var(--terminal-primary-hover, #1177bb) !important;
			}
			.swal2-terminal-cancel {
				background: var(--terminal-border, #3e3e42) !important;
				color: var(--terminal-text, #d4d4d4) !important;
				border: none !important;
			}
			.swal2-terminal-cancel:hover {
				background: var(--terminal-border-hover, #4e4e52) !important;
			}
			.swal2-terminal-deny {
				background: var(--terminal-error, #f48771) !important;
				color: #ffffff !important;
				border: none !important;
			}
			.swal2-terminal-deny:hover {
				opacity: 0.9 !important;
			}
			.swal2-terminal-input {
				background: var(--terminal-bg, #1e1e1e) !important;
				border: 1px solid var(--terminal-border, #3e3e42) !important;
				color: var(--terminal-text, #d4d4d4) !important;
			}
			.swal2-terminal-input:focus {
				border-color: var(--terminal-primary, #0e639c) !important;
				box-shadow: 0 0 0 2px rgba(14, 99, 156, 0.2) !important;
			}
			.swal2-terminal-validation {
				background: var(--terminal-bg-secondary, #252526) !important;
				color: var(--terminal-error, #f48771) !important;
			}
			.swal2-icon.swal2-warning {
				border-color: var(--terminal-warning, #dcdcaa) !important;
				color: var(--terminal-warning, #dcdcaa) !important;
			}
			.swal2-icon.swal2-error {
				border-color: var(--terminal-error, #f48771) !important;
				color: var(--terminal-error, #f48771) !important;
			}
			.swal2-icon.swal2-success {
				border-color: var(--terminal-success, #10b981) !important;
				color: var(--terminal-success, #10b981) !important;
			}
			.swal2-icon.swal2-info {
				border-color: var(--terminal-info, #4ec9b0) !important;
				color: var(--terminal-info, #4ec9b0) !important;
			}
			.swal2-close {
				color: var(--terminal-text-secondary, #858585) !important;
			}
			.swal2-close:hover {
				color: var(--terminal-text, #d4d4d4) !important;
				background: var(--terminal-border, #3e3e42) !important;
			}
			/* Toast-specific styling */
			.swal2-toast.swal2-terminal-popup {
				background: var(--terminal-bg-secondary, #252526) !important;
				border: 1px solid var(--terminal-border, #3e3e42) !important;
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5) !important;
				max-width: 400px !important;
				width: auto !important;
				padding: 1rem !important;
			}
			/* Remove backdrop for toasts */
			.swal2-toast-container {
				pointer-events: none !important;
			}
			.swal2-toast-container .swal2-backdrop-show {
				background: transparent !important;
			}
			.swal2-toast .swal2-title {
				color: var(--terminal-text, #d4d4d4) !important;
				font-size: 1rem !important;
				margin: 0 0 0.5rem 0 !important;
				line-height: 1.4 !important;
			}
			.swal2-toast .swal2-html-container {
				color: var(--terminal-text, #d4d4d4) !important;
				font-size: 0.875rem !important;
				margin: 0 !important;
				line-height: 1.4 !important;
			}
			.swal2-toast .swal2-icon {
				width: 2.5rem !important;
				height: 2.5rem !important;
				margin: 0 0.75rem 0 0 !important;
				flex-shrink: 0 !important;
			}
			.swal2-toast .swal2-icon.swal2-success {
				border-color: var(--terminal-success, #10b981) !important;
				color: var(--terminal-success, #10b981) !important;
			}
			.swal2-toast .swal2-icon.swal2-success .swal2-success-ring {
				border-color: var(--terminal-success, #10b981) !important;
			}
			.swal2-toast .swal2-icon.swal2-success [class^=swal2-success-line] {
				background-color: var(--terminal-success, #10b981) !important;
			}
			.swal2-toast .swal2-icon.swal2-error {
				border-color: var(--terminal-error, #f48771) !important;
				color: var(--terminal-error, #f48771) !important;
			}
			.swal2-toast .swal2-icon.swal2-error [class^=swal2-x-mark-line] {
				background-color: var(--terminal-error, #f48771) !important;
			}
			/* Ensure toast container doesn't create long backdrop */
			body.swal2-toast-shown .swal2-container {
				background: transparent !important;
			}
			body.swal2-toast-shown .swal2-backdrop-show {
				background: transparent !important;
			}
			/* Rate limit link styling */
			.swal2-html-container a {
				color: var(--terminal-primary, #0e639c) !important;
				text-decoration: underline !important;
				transition: color 0.2s ease !important;
				font-weight: 500 !important;
			}
			.swal2-html-container a:hover {
				color: var(--terminal-primary-hover, #1177bb) !important;
			}
			/* Pulsing animation for rate limit messages */
			@keyframes pulse-dot {
				0%, 100% {
					opacity: 1;
					transform: scale(1);
				}
				50% {
					opacity: 0.5;
					transform: scale(0.8);
				}
			}
			.rate-limit-indicator {
				display: inline-block;
				margin: 0 0.5rem;
			}
			.rate-limit-indicator::before {
				content: '⚡';
				display: inline-block;
				animation: pulse-dot 1.5s ease-in-out infinite;
				margin-right: 0.25rem;
			}
			.rate-limit-indicator::after {
				content: '⚡';
				display: inline-block;
				animation: pulse-dot 1.5s ease-in-out infinite 0.5s;
				margin-left: 0.25rem;
			}
		`;
		document.head.appendChild(style);
	}
}

// Function to refresh Swal instance when theme changes
function refreshSwalTheme() {
	SwalThemed = getSwalInstance();
}

// Export the theme-aware version as default
export default SwalThemed;
export { SwalThemed, Swal, getSwalInstance, refreshSwalTheme };
