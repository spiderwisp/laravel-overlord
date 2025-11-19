import { ref, nextTick } from 'vue';
import axios from 'axios';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalCommands(api, { ensureTabOpen, scrollToBottom, focusInput, inputRef }) {
	const commandInput = ref('');
	const inputMode = ref('tinker'); // 'tinker', 'shell', 'ai'
	const commandHistory = ref([]);
	const historyIndex = ref(-1);
	const outputHistory = ref([]);
	const isExecuting = ref(false);

	// Add output to history
	function addOutputToHistory(type, output, raw = null) {
		outputHistory.value.push({
			type,
			output,
			raw: raw || output,
			timestamp: new Date(),
		});
	}

	// Clear terminal
	async function clearTerminal() {
		outputHistory.value = [];
		await nextTick();
		if (scrollToBottom) scrollToBottom();
	}

	// Clear session
	async function clearSession() {
		try {
			await axios.delete(api.url('session'));
			addOutputToHistory('text', 'Session cleared');
		} catch (error) {
			addOutputToHistory('error', {
				formatted: 'Failed to clear session: ' + (error.response?.data?.errors?.[0] || error.message),
				raw: 'Failed to clear session: ' + (error.response?.data?.errors?.[0] || error.message),
			});
		}
	}

	// Execute shell command
	async function executeShellCommand(shellCmd) {
		if (!shellCmd.trim() || isExecuting.value) {
			return;
		}

		// Switch to terminal tab to show output
		if (ensureTabOpen) ensureTabOpen('terminal');
		
		// Add to history if not duplicate of last command
		const fullCommand = '/shell ' + shellCmd;
		const trimmedCommand = fullCommand.trim();
		if (commandHistory.value.length === 0 || commandHistory.value[commandHistory.value.length - 1] !== trimmedCommand) {
			commandHistory.value.push(trimmedCommand);
		}
		historyIndex.value = -1;

		// Add command to output
		addOutputToHistory('command', fullCommand);

		isExecuting.value = true;

		try {
			const response = await axios.post(api.shell.execute(), {
				command: shellCmd,
			}, {
				timeout: 65000, // 65 seconds (slightly more than backend timeout)
			});

			if (response.data.success) {
				const result = response.data.result;
				addOutputToHistory(result.type || 'text', result.output, result.raw);
			} else {
				addOutputToHistory('error', {
					formatted: response.data.errors?.[0] || 'Unknown error',
					raw: response.data.errors?.[0] || 'Unknown error',
				});
			}
		} catch (error) {
			let errorMessage = 'Shell command execution failed';
			
			// Handle different error response formats
			if (error.response) {
				const errorData = error.response.data;
				
				if (errorData?.errors && Array.isArray(errorData.errors) && errorData.errors.length > 0) {
					errorMessage = errorData.errors[0];
				} else if (errorData?.error) {
					errorMessage = errorData.error;
				} else if (errorData?.message) {
					errorMessage = errorData.message;
				} else if (typeof errorData === 'string') {
					errorMessage = errorData;
				} else if (error.response.status === 500) {
					errorMessage = `Server error (500): ${error.response.statusText || 'Internal server error'}. Please check the server logs.`;
				} else {
					errorMessage = `Request failed (${error.response.status}): ${error.response.statusText || 'Unknown error'}`;
				}
			} else if (error.request) {
				errorMessage = 'No response from server. Please check your connection and try again.';
			} else if (error.code === 'ECONNABORTED') {
				errorMessage = 'Command execution timeout. The command may be taking too long to execute.';
			} else {
				errorMessage = error.message || 'Shell command execution failed';
			}
			
			addOutputToHistory('error', {
				formatted: errorMessage,
				raw: errorMessage,
			});
		} finally {
			isExecuting.value = false;
			await nextTick();
			if (scrollToBottom) scrollToBottom();
			// Refocus input after command execution
			if (focusInput) focusInput();
		}
	}

	// Execute command
	async function executeCommand() {
		if (!commandInput.value.trim() || isExecuting.value) {
			return;
		}

		// Preserve the full command including line breaks for execution
		let command = commandInput.value.trim();
		
		// Check for mode overrides via prefixes
		if (command.startsWith('/shell')) {
			inputMode.value = 'shell';
			command = command.substring(6).trim();
		} else if (command.startsWith('/ai')) {
			inputMode.value = 'ai';
			command = command.substring(3).trim();
		} else if (command.startsWith('/tinker')) {
			inputMode.value = 'tinker';
			command = command.substring(7).trim();
		}

		if (!command) {
			// Just changing mode
			commandInput.value = '';
			return;
		}

		// Handle based on input mode
		if (inputMode.value === 'shell') {
			await executeShellCommand(command);
			return;
		}
		
		// For AI mode, return early - let parent handle it
		if (inputMode.value === 'ai') {
			return { mode: 'ai', command };
		}
		
		// Regular command execution (Tinker)
		// Switch to terminal tab to show output
		if (ensureTabOpen) ensureTabOpen('terminal');
		
		// Add to history if not duplicate of last command (trimmed for history comparison)
		const trimmedCommand = command.trim();
		if (commandHistory.value.length === 0 || commandHistory.value[commandHistory.value.length - 1] !== trimmedCommand) {
			commandHistory.value.push(trimmedCommand);
		}
		historyIndex.value = -1;

		// Add command to output (preserve line breaks for multi-line commands)
		addOutputToHistory('command', command);

		// Clear input
		commandInput.value = '';
		// Reset textarea height
		if (inputRef?.value) {
			inputRef.value.style.height = 'auto';
		}
		isExecuting.value = true;

		try {
			const response = await axios.post(api.url('execute'), {
				command: command,
			}, {
				timeout: 300000, // 5 minutes for long-running commands
			});

			if (response.data.success) {
				const result = response.data.result;
				addOutputToHistory(result.type, result.output, result.raw);
			} else {
				addOutputToHistory('error', {
					formatted: response.data.errors?.[0] || 'Unknown error',
					raw: response.data.errors?.[0] || 'Unknown error',
				});
			}
		} catch (error) {
			let errorMessage = 'Execution failed';
			
			// Handle different error response formats
			if (error.response) {
				// Server responded with error status
				const errorData = error.response.data;
				
				if (errorData?.errors && Array.isArray(errorData.errors) && errorData.errors.length > 0) {
					// Use the first error message from the errors array
					errorMessage = errorData.errors[0];
				} else if (errorData?.error) {
					// Single error message
					errorMessage = errorData.error;
				} else if (errorData?.message) {
					// Generic message field
					errorMessage = errorData.message;
				} else if (typeof errorData === 'string') {
					// Error data is a string
					errorMessage = errorData;
				} else if (error.response.status === 500) {
					// 500 error - should not happen but handle gracefully
					errorMessage = `Server error (500): ${error.response.statusText || 'Internal server error'}. Please check the server logs.`;
				} else {
					// Other HTTP errors
					errorMessage = `Request failed (${error.response.status}): ${error.response.statusText || 'Unknown error'}`;
				}
			} else if (error.request) {
				// Request was made but no response received
				errorMessage = 'No response from server. Please check your connection and try again.';
			} else {
				// Error setting up the request
				errorMessage = error.message || 'Execution failed';
			}
			
			addOutputToHistory('error', {
				formatted: errorMessage,
				raw: errorMessage,
			});
		} finally {
			isExecuting.value = false;
			await nextTick();
			if (scrollToBottom) scrollToBottom();
			// Refocus input after command execution
			if (focusInput) focusInput();
		}
	}

	// Handle keyboard input for history navigation
	function handleKeyDown(event) {
		// Up arrow for history
		if (event.key === 'ArrowUp') {
			event.preventDefault();
			if (commandHistory.value.length > 0) {
				if (historyIndex.value === -1) {
					historyIndex.value = commandHistory.value.length - 1;
				} else if (historyIndex.value > 0) {
					historyIndex.value--;
				}
				if (historyIndex.value >= 0) {
					commandInput.value = commandHistory.value[historyIndex.value];
				}
			}
			return true; // Indicate handled
		}

		// Down arrow for history
		if (event.key === 'ArrowDown') {
			event.preventDefault();
			if (historyIndex.value >= 0) {
				if (historyIndex.value < commandHistory.value.length - 1) {
					historyIndex.value++;
					commandInput.value = commandHistory.value[historyIndex.value];
				} else {
					historyIndex.value = -1;
					commandInput.value = '';
				}
			}
			return true; // Indicate handled
		}
		return false; // Not handled
	}

	// Use command from history
	async function useCommandFromHistory(log) {
		// Set command in input
		commandInput.value = log.command;
		
		// Switch to terminal tab to show output
		if (ensureTabOpen) ensureTabOpen('terminal');
		
		// Show the output from this log entry
		outputHistory.value = [];
		
		// Add the command
		addOutputToHistory('command', log.command);
		
		// Add the output or error
		if (log.success && log.output) {
			// Try to parse output type
			let outputType = log.output_type || 'text';
			let outputData = log.output;
			
			// Try to parse as JSON if it's a json type
			if (outputType === 'json' || outputType === 'object') {
				try {
					outputData = JSON.parse(log.output);
					// Format for JsonViewer
					addOutputToHistory(outputType, outputData);
				} catch (e) {
					// If parsing fails, treat as text
					addOutputToHistory('text', log.output);
				}
			} else {
				addOutputToHistory(outputType, log.output);
			}
		} else if (!log.success && log.error) {
			addOutputToHistory('error', {
				formatted: log.error,
				raw: log.error,
			});
		}
		
		// Switch to terminal tab to show output
		if (ensureTabOpen) ensureTabOpen('terminal');
		
		// Scroll to show the output
		await nextTick();
		if (scrollToBottom) scrollToBottom();
		if (focusInput) focusInput();
	}

	return {
		commandInput,
		inputMode,
		commandHistory,
		historyIndex,
		outputHistory,
		isExecuting,
		addOutput: addOutputToHistory,
		clearTerminal,
		clearSession,
		executeCommand,
		executeShellCommand,
		handleKeyDown,
		useCommandFromHistory,
	};
}

