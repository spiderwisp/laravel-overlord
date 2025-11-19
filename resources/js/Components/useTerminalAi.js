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
		
		// Add user message to conversation history
		aiConversationHistory.value.push({
			role: 'user',
			content: userMessage,
		});

		// Ensure AI tab is open
		if (ensureTabOpen) ensureTabOpen('ai');
		
		// Notify AI component to add user message
		if (aiRef && aiRef.addUserMessage) {
			aiRef.addUserMessage(userMessage);
		}

		isSendingAi.value = true;

		try {
			const response = await axios.post(api.ai.chat(), {
				message: userMessage,
				model: selectedAiModel.value,
				conversation_history: aiConversationHistory.value.slice(-10), // Last 10 messages
			});

			if (response.data.success) {
				const aiMessage = response.data.message;
				
				// Add AI response to conversation history
				aiConversationHistory.value.push({
					role: 'assistant',
					content: aiMessage,
				});

				// Notify AI component to add assistant message
				if (aiRef && aiRef.addAssistantMessage) {
					aiRef.addAssistantMessage(aiMessage);
				}
			} else {
				const errorMsg = `Error: ${response.data.error || 'Unknown error'}`;
				if (aiRef && aiRef.addAssistantMessage) {
					aiRef.addAssistantMessage(errorMsg, true);
				}
			}
		} catch (error) {
			console.error('Failed to send AI message:', error);
			const errorMsg = `Error: ${error.response?.data?.error || error.message || 'Failed to communicate with AI'}`;
			if (aiRef && aiRef.addAssistantMessage) {
				aiRef.addAssistantMessage(errorMsg, true);
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

