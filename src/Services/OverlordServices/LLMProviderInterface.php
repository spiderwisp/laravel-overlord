<?php

namespace Spiderwisp\LaravelOverlord\Services\OverlordServices;

interface LLMProviderInterface
{
	/**
	 * Send a chat message to the LLM
	 *
	 * @param string $message The user's message
	 * @param array $conversationHistory Previous messages in the conversation
	 * @param string $systemPrompt System prompt for the LLM
	 * @param array $context Additional context (codebase, database, logs)
	 * @param array $options Additional options (temperature, max_tokens, etc.)
	 * @param string|null $contextType Context type for specialized AI instructions (e.g., 'codebase_scan', 'database_scan', 'general')
	 * @param array|null $analysisData Structured data for analysis (e.g., for codebase scans)
	 * @return array Response with 'success', 'message', 'tokens_used', etc.
	 */
	public function chat(
		string $message,
		array $conversationHistory = [],
		string $systemPrompt = '',
		array $context = [],
		array $options = [],
		?string $contextType = null,
		?array $analysisData = null
	): array;

	/**
	 * Check if the provider is available and configured
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Get the provider name
	 *
	 * @return string
	 */
	public function getName(): string;

	/**
	 * Get available models for this provider
	 *
	 * @return array
	 */
	public function getAvailableModels(): array;
}