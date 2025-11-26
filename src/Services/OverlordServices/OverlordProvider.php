<?php

namespace Spiderwisp\LaravelOverlord\Services\OverlordServices;

use Illuminate\Support\Facades\Http;
use Spiderwisp\LaravelOverlord\Enums\AiErrorCode;

class OverlordProvider implements LLMProviderInterface
{
	use FormatsContextTrait;

	protected $apiKey;
	protected $apiUrl;

	public function __construct()
	{
		// Get API key: Config value takes precedence (which comes from ENV if set), then database settings
		$configKey = config('laravel-overlord.ai.api_key', '');
		$dbKey = null;
		
		// Try to get from database settings
		try {
			if (class_exists(\Spiderwisp\LaravelOverlord\Models\Setting::class)) {
				if (\Spiderwisp\LaravelOverlord\Models\Setting::has('ai_api_key')) {
					$dbKey = \Spiderwisp\LaravelOverlord\Models\Setting::get('ai_api_key');
				}
			}
		} catch (\Exception $e) {
			// If database doesn't exist or table doesn't exist yet, ignore
		}
		
		// If config has a value and it doesn't match database, it's from ENV (use config)
		// Otherwise, use database if available, then fall back to config
		if (!empty($configKey) && $configKey !== $dbKey) {
			// Config value is from ENV (takes precedence)
			$this->apiKey = trim($configKey);
		} elseif (!empty($dbKey)) {
			// Use database value
			$this->apiKey = trim($dbKey);
		} else {
			// Fall back to config (might be empty)
			$this->apiKey = trim($configKey);
		}
		
		$this->apiUrl = rtrim(config('laravel-overlord.ai.api_url', ''), '/');
	}

	/**
	 * Send a chat message to the API
	 */
	public function chat(
		string $message,
		array $conversationHistory = [],
		string $systemPrompt = '',
		array $context = [],
		array $options = [],
		?string $contextType = null,
		?array $analysisData = null
	): array {
		if (!$this->isAvailable()) {
			return [
				'success' => false,
				'error' => 'AI service is not configured. Please set LARAVEL_OVERLORD_API_KEY in your .env file.',
			];
		}

		try {
			// Format context if provided
			$formattedContext = [];
			if (!empty($context)) {
				$formattedContext = $this->formatContextForApi($context);
			}

			// Build request payload
			$payload = [
				'message' => $message,
				'conversation_history' => $conversationHistory,
			];

			$requestType = $this->mapContextTypeToRequestType($contextType);
			if ($requestType) {
				$payload['request_type'] = $requestType;
			}

			if ($contextType !== null) {
				$payload['context_type'] = $contextType;
			}

			$payload['data'] = [
				'codebase' => $formattedContext['codebase'] ?? '',
				'database' => $formattedContext['database'] ?? '',
				'logs' => $formattedContext['logs'] ?? '',
			];

			if ($analysisData !== null) {
				$payload['data']['analysis_data'] = $analysisData;
			}

			$trimmedApiKey = trim($this->apiKey);
			$endpoint = $this->apiUrl . '/ai/chat';

			// Make API request
			$response = Http::timeout(60)
				->withHeaders([
					'Authorization' => 'Bearer ' . $trimmedApiKey,
					'Content-Type' => 'application/json',
				])
				->post($endpoint, $payload);

			if ($response->successful()) {
				$data = $response->json();
				$statusCode = $response->status();

				// Validate response structure
				if (!is_array($data)) {
					return [
						'success' => false,
						'error' => 'Invalid response format',
						'code' => 'INVALID_RESPONSE',
					];
				}

				if (isset($data['success']) && $data['success']) {
					return [
						'success' => true,
						'message' => $data['message'] ?? '',
						'tokens_used' => $data['tokens_used'] ?? [
							'prompt' => 0,
							'completion' => 0,
							'total' => 0,
						],
						'model' => $data['model'] ?? null,
					];
				}

				$errorCode = $data['code'] ?? 'API_ERROR';
				$errorMessage = $data['error'] ?? $data['message'] ?? $data['error_message'] ?? $data['detail'] ?? 'Unknown error';

				if ($errorCode === 'QUOTA_EXCEEDED' || $errorCode === AiErrorCode::QUOTA_EXCEEDED->value) {
					$errorMessage = $errorMessage ?: 'Monthly quota exceeded. Please upgrade at laravel-overlord.com to continue.';
				}

				return [
					'success' => false,
					'error' => $errorMessage,
					'code' => $errorCode,
				];
			}

			// Handle API errors (non-200 status codes)
			$errorData = $response->json();
			$statusCode = $response->status();
			$errorCode = $errorData['code'] ?? 'API_ERROR';
			$errorMessage = $errorData['error'] ?? $errorData['message'] ?? $errorData['error_message'] ?? $errorData['detail'] ?? 'Unknown error';

			if ($errorCode === 'QUOTA_EXCEEDED' || $errorCode === AiErrorCode::QUOTA_EXCEEDED->value) {
				$errorMessage = $errorMessage ?: 'Monthly quota exceeded. Please upgrade at laravel-overlord.com to continue.';
			}

			$recognizedCodes = [
				AiErrorCode::QUOTA_EXCEEDED->value,
				AiErrorCode::RATE_LIMIT_EXCEEDED->value,
			];

			if (in_array($errorCode, $recognizedCodes)) {
				return [
					'success' => false,
					'error' => $errorMessage,
					'code' => $errorCode,
				];
			}

			if ($errorCode === 'INVALID_API_KEY' || $statusCode === 401) {
				return [
					'success' => false,
					'error' => 'Invalid API key. Please check your API key configuration.',
					'code' => AiErrorCode::INVALID_API_KEY->value,
				];
			}

			if ($errorCode === 'INVALID_SIGNATURE' || $errorCode === 'DECRYPTION_ERROR') {
				return [
					'success' => false,
					'error' => 'Security validation failed. Please check your configuration.',
					'code' => $errorCode === 'INVALID_SIGNATURE' ? AiErrorCode::INVALID_SIGNATURE->value : AiErrorCode::DECRYPTION_ERROR->value,
				];
			}

			return [
				'success' => false,
				'error' => "API error ({$statusCode}): {$errorMessage}",
				'code' => $errorCode,
			];
		} catch (\Exception $e) {
			return [
				'success' => false,
				'error' => 'Failed to communicate with API: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Format context for API (create summary and full context)
	 */
	protected function formatContextForApi(array $context): array
	{
		// Create summary for logging/analytics
		$summary = [
			'codebase_files' => 0,
			'database_tables' => 0,
			'log_entries' => 0,
		];

		// Count codebase files mentioned
		if (!empty($context['codebase'])) {
			preg_match_all('/### (Model|Controller|File|Trait):/i', $context['codebase'], $matches);
			$summary['codebase_files'] = count($matches[0] ?? []);
		}

		// Count database tables mentioned
		if (!empty($context['database'])) {
			preg_match_all('/### Table: `([^`]+)`/i', $context['database'], $matches);
			$summary['database_tables'] = count($matches[1] ?? []);
		}

		// Count log entries
		if (!empty($context['logs'])) {
			$summary['log_entries'] = substr_count($context['logs'], '##');
		}

		// Return formatted context
		return [
			'summary' => $summary,
			'codebase' => $context['codebase'] ?? '',
			'database' => $context['database'] ?? '',
			'logs' => $context['logs'] ?? '',
		];
	}


	/**
	 * Check if service is available
	 */
	public function isAvailable(): bool
	{
		return !empty($this->apiKey) && !empty($this->apiUrl);
	}

	/**
	 * Get provider name
	 */
	public function getName(): string
	{
		return 'overlord';
	}

	/**
	 * Get available models
	 * Note: Model selection is controlled by the SaaS server, not the client
	 */
	public function getAvailableModels(): array
	{
		// Models are controlled server-side, client doesn't need to know
		return [];
	}

	/**
	 * Map context type to request type
	 */
	protected function mapContextTypeToRequestType(?string $contextType): ?string
	{
		if ($contextType === null) {
			return 'general_chat';
		}

		return match ($contextType) {
			'codebase_scan' => 'codebase_scan',
			'database_scan' => 'database_scan',
			'migration_generation' => 'migration_generation',
			'general' => 'general_chat',
			default => 'general_chat',
		};
	}
}