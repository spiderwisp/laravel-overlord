import { ref } from 'vue';
import axios from 'axios';
import { useOverlordApi } from './useOverlordApi';

export function useTerminalAi(api, { ensureTabOpen }) {
	const aiConversationHistory = ref([]);
	const selectedAiModel = ref(null);
	const isSendingAi = ref(false);

	// Load AI status and models
	async function loadAiStatus() {
		try {
			const response = await axios.get(api.ai.status());
			if (response.data.success && response.data.available) {
				// Load models
				try {
					const modelsResponse = await axios.get(api.ai.models());
					if (modelsResponse.data.success && modelsResponse.data.available) {
						const models = modelsResponse.data.models || [];
						if (models.length > 0 && !selectedAiModel.value) {
							selectedAiModel.value = modelsResponse.data.default_model || models[0].name;
						}
					}
				} catch (error) {
					console.error('Failed to load AI models:', error);
				}
			}
		} catch (error) {
			console.error('Failed to load AI status:', error);
		}
	}

	// Send message to AI
	async function sendAiMessage(message, aiRef) {
		if (!message.trim() || isSendingAi.value) {
			return;
		}

		const userMessage = message.trim();
		
		// Ensure AI tab is open first
		if (ensureTabOpen) {
			ensureTabOpen('ai');
		}
		
		// Get the actual component instance from the ref
		// aiRef is a ref object, so we need to access .value
		let aiComponent = null;
		if (aiRef && typeof aiRef === 'object' && 'value' in aiRef) {
			aiComponent = aiRef.value;
		}
		
		// Wait a bit for the component to mount and ref to sync if needed
		if (!aiComponent && ensureTabOpen) {
			// Try multiple times with increasing delays
			for (let attempt = 0; attempt < 5; attempt++) {
				await new Promise(resolve => setTimeout(resolve, 100 * (attempt + 1)));
				
				// Try again after waiting
				if (aiRef && typeof aiRef === 'object' && 'value' in aiRef) {
					aiComponent = aiRef.value;
					if (aiComponent && typeof aiComponent.addUserMessage === 'function') {
						break;
					}
				}
			}
		}
		
		// Add user message to conversation history
		aiConversationHistory.value.push({
			role: 'user',
			content: userMessage,
		});
		
		// Notify AI component to add user message
		if (aiComponent && typeof aiComponent.addUserMessage === 'function') {
			aiComponent.addUserMessage(userMessage);
		}

		isSendingAi.value = true;

		try {
			const response = await axios.post(api.ai.chat(), {
				message: userMessage,
				model: selectedAiModel.value,
				conversation_history: aiConversationHistory.value.slice(-10), // Last 10 messages
			});

			if (response.data.success) {
				// Try both response.data.message and response.data.result.message
				const aiMessage = response.data.result?.message || response.data.message || '';
				
				// Add AI response to conversation history
				aiConversationHistory.value.push({
					role: 'assistant',
					content: aiMessage,
				});

				// Get the component instance again (in case ref was updated)
				let aiComponent = null;
				if (aiRef && typeof aiRef === 'object' && 'value' in aiRef) {
					aiComponent = aiRef.value;
				}

				// Notify AI component to add assistant message
				if (aiComponent && typeof aiComponent.addAssistantMessage === 'function') {
					aiComponent.addAssistantMessage(aiMessage);
				}
			} else {
				const errorMsg = `Error: ${response.data.error || 'Unknown error'}`;
				console.error('useTerminalAi: API returned error', {
					error: response.data.error,
					code: response.data.code,
				});
				
				// Get the component instance
				let aiComponent = null;
				if (aiRef && typeof aiRef === 'object' && 'value' in aiRef) {
					aiComponent = aiRef.value;
				}
				
				if (aiComponent && typeof aiComponent.addAssistantMessage === 'function') {
					aiComponent.addAssistantMessage(errorMsg, true);
				}
			}
		} catch (error) {
			console.error('useTerminalAi: Failed to send AI message', error);
			
			// Handle different error scenarios
			let errorMsg = 'Failed to communicate with AI';
			const errorCode = error.response?.data?.code;
			
			if (error.response?.data) {
				// API returned an error response
				if (errorCode === 'INVALID_API_KEY') {
					errorMsg = `Invalid API key. Please check your API key configuration.\n\n[Get API Key from laravel-overlord.com](https://laravel-overlord.com)`;
				} else if (errorCode === 'QUOTA_EXCEEDED') {
					errorMsg = 'Monthly quota exceeded. Please upgrade your plan.';
				} else if (errorCode === 'DECRYPTION_ERROR' || errorCode === 'INVALID_SIGNATURE') {
					errorMsg = 'Security validation failed. Please check your configuration.';
				} else {
					errorMsg = error.response.data.error || error.response.data.errors?.[0] || error.message || errorMsg;
				}
			} else {
				// Network or other error
				errorMsg = error.message || errorMsg;
			}
			
			// Get the component instance
			let aiComponent = null;
			if (aiRef && typeof aiRef === 'object' && 'value' in aiRef) {
				aiComponent = aiRef.value;
			}
			
			if (aiComponent && typeof aiComponent.addAssistantMessage === 'function') {
				aiComponent.addAssistantMessage(errorMsg, true);
			}
		} finally {
			isSendingAi.value = false;
		}
	}

	return {
		aiConversationHistory,
		selectedAiModel,
		isSendingAi,
		loadAiStatus,
		sendAiMessage,
	};
}

