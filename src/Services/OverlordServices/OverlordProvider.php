<?php

namespace Spiderwisp\LaravelOverlord\Services\OverlordServices;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Spiderwisp\LaravelOverlord\Enums\AiErrorCode;

class OverlordProvider implements LLMProviderInterface
{
	use FormatsContextTrait;

	protected $apiKey;
	protected $apiUrl;
	protected $encryptionKey;

	public function __construct()
	{
		$this->apiKey = trim(config('laravel-overlord.ai.api_key', ''));
		$this->apiUrl = rtrim(config('laravel-overlord.ai.api_url', ''), '/');
		$this->encryptionKey = trim(config('laravel-overlord.ai.encryption_key', '')) ?: $this->apiKey;

		// Log API key status (without exposing the actual key)
		if (empty($this->apiKey)) {
			Log::warning('OverlordProvider: API key is empty or not set', [
				'config_value' => config('laravel-overlord.ai.api_key'),
				'env_set' => !empty(env('LARAVEL_OVERLORD_API_KEY')),
			]);
		} else {
			Log::debug('OverlordProvider: API key loaded', [
				'key_length' => strlen($this->apiKey),
				'key_preview' => substr($this->apiKey, 0, 8) . '...',
				'has_whitespace' => $this->apiKey !== trim($this->apiKey),
			]);
		}
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
			Log::warning('OverlordProvider: Not available', [
				'api_key_set' => !empty($this->apiKey),
				'api_url_set' => !empty($this->apiUrl),
			]);
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

			// Calculate payload size and context summary for logging
			$payloadJson = json_encode($payload);
			$payloadSize = strlen($payloadJson);
			$contextSummary = [
				'message_length' => strlen($message),
				'conversation_history_count' => count($conversationHistory),
				'has_context' => !empty($formattedContext),
				'has_analysis_data' => $analysisData !== null,
			];
			if (!empty($formattedContext)) {
				$contextSummary['context_summary'] = $formattedContext['summary'] ?? [];
				$contextSummary['codebase_length'] = strlen($formattedContext['codebase'] ?? '');
				$contextSummary['database_length'] = strlen($formattedContext['database'] ?? '');
				$contextSummary['logs_length'] = strlen($formattedContext['logs'] ?? '');
			}
			if ($analysisData !== null) {
				$contextSummary['analysis_data_files'] = count($analysisData['files'] ?? []);
			}

			// Encrypt payload
			$encryptedPayload = $this->encryptPayload($payload);

			// Ensure API key is trimmed (should already be trimmed in constructor, but double-check)
			$trimmedApiKey = trim($this->apiKey);

			// Generate HMAC signature using the trimmed key
			$timestamp = time();
			$nonce = bin2hex(random_bytes(16));
			$signature = $this->generateSignature($encryptedPayload, $timestamp, $nonce, $trimmedApiKey);

			// Build API endpoint
			$endpoint = $this->apiUrl . '/ai/chat';

			// Log request details (without exposing sensitive data)
			Log::debug('OverlordProvider: Making API request', [
				'endpoint' => $endpoint,
				'api_key_length' => strlen($trimmedApiKey),
				'api_key_preview' => substr($trimmedApiKey, 0, 8) . '...',
				'api_key_ends_with' => substr($trimmedApiKey, -4),
				'has_api_key' => !empty($trimmedApiKey),
			]);

			// Make API request
			$response = Http::timeout(60)
				->withHeaders([
					'Authorization' => 'Bearer ' . $trimmedApiKey,
					'Content-Type' => 'application/json',
					'X-Request-Signature' => $signature,
					'X-Request-Timestamp' => $timestamp,
					'X-Request-Nonce' => $nonce,
				])
				->post($endpoint, [
					'payload' => $encryptedPayload,
				]);

			if ($response->successful()) {
				$data = $response->json();
				$statusCode = $response->status();

				// Validate response structure
				if (!is_array($data)) {
					Log::error('OverlordProvider: API returned non-array response', [
						'status_code' => $statusCode,
						'response_type' => gettype($data),
						'response_preview' => is_string($data) ? substr($data, 0, 200) : json_encode($data),
					]);
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
						'model' => $data['model'] ?? $model,
					];
				} else {
					$responseBody = $response->body();
					$responseHeaders = $response->headers();

					$hasSuccessField = isset($data['success']);
					$successValue = $data['success'] ?? null;

					Log::error('OverlordProvider: API returned success:false or missing success field', [
						'status_code' => $statusCode,
						'has_success_field' => $hasSuccessField,
						'success_value' => $successValue,
						'response_data' => $data,
						'response_keys' => is_array($data) ? array_keys($data) : [],
						'has_error_field' => isset($data['error']),
						'has_code_field' => isset($data['code']),
						'has_message_field' => isset($data['message']),
						'error_value' => $data['error'] ?? null,
						'code_value' => $data['code'] ?? null,
						'message_value' => $data['message'] ?? null,
						'response_body_preview' => strlen($responseBody) > 500 ? substr($responseBody, 0, 500) . '...' : $responseBody,
						'response_headers' => $responseHeaders,
						'request_endpoint' => $endpoint,
						'request_payload_size' => $payloadSize,
					]);

					$errorMessage = $data['error'] ?? $data['message'] ?? $data['error_message'] ?? $data['detail'] ?? 'Unknown error';
					$errorCode = $data['code'] ?? 'API_ERROR';

					if (!$hasSuccessField) {
						Log::warning('OverlordProvider: API response missing success field, treating as error', [
							'response_keys' => array_keys($data),
						]);
					}

					$isRateLimit = $statusCode === 429 ||
						stripos($errorMessage, 'rate limit') !== false ||
						stripos($errorMessage, 'too many requests') !== false ||
						stripos($errorMessage, 'quota exceeded') !== false ||
						$errorCode === 'RATE_LIMIT_EXCEEDED' ||
						$errorCode === 'QUOTA_EXCEEDED';

					if ($isRateLimit) {
						Log::warning('OverlordProvider: Rate limit/quota detected', [
							'error' => $errorMessage,
							'code' => $errorCode,
							'status_code' => $statusCode,
						]);
					}

					return [
						'success' => false,
						'error' => $errorMessage,
						'code' => $errorCode,
					];
				}
			}

			// Handle API errors (non-200 status codes)
			$errorData = $response->json();
			$errorMessage = $errorData['error'] ?? $errorData['message'] ?? $errorData['error_message'] ?? $errorData['detail'] ?? 'Unknown error';
			$statusCode = $response->status();
			$responseBody = $response->body();
			$responseHeaders = $response->headers();

			Log::error('OverlordProvider: API HTTP error', [
				'status_code' => $statusCode,
				'error_data' => $errorData,
				'error_message' => $errorMessage,
				'response_keys' => is_array($errorData) ? array_keys($errorData) : [],
				'response_body' => strlen($responseBody) > 1000 ? substr($responseBody, 0, 1000) . '...' : $responseBody,
				'response_headers' => $responseHeaders,
				'request_endpoint' => $endpoint,
				'request_payload_size' => $payloadSize ?? 0,
			]);

			// Map common error codes
			$errorCode = $errorData['code'] ?? 'API_ERROR';

			// Check if error code matches Enum values - if so, pass through error message as-is
			$recognizedCodes = [
				AiErrorCode::QUOTA_EXCEEDED->value,
				AiErrorCode::RATE_LIMIT_EXCEEDED->value,
			];

			if (in_array($errorCode, $recognizedCodes)) {
				// Pass through the error message
				return [
					'success' => false,
					'error' => $errorMessage,
					'code' => $errorCode,
				];
			}

			// For unrecognized codes, generate custom messages
			if ($errorCode === 'INVALID_API_KEY' || $statusCode === 401) {
				return [
					'success' => false,
					'error' => 'Invalid API key. Please check your API key configuration.',
					'code' => AiErrorCode::INVALID_API_KEY->value,
				];
			} elseif ($errorCode === 'INVALID_SIGNATURE' || $errorCode === 'DECRYPTION_ERROR') {
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
			Log::error('OverlordProvider: Exception', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

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
	 * Encrypt payload using Laravel Crypt
	 */
	protected function encryptPayload(array $payload): string
	{
		try {
			// Use the encryption key to encrypt the payload
			// If encryption key is the same as API key, use it directly
			// Otherwise, we need to set up Laravel's encryption key
			$payloadJson = json_encode($payload);

			// Use a simple encryption approach with the encryption key
			// In production, you might want to use Laravel's Crypt facade properly
			// For now, we'll use a hash-based approach for obfuscation
			if (!empty($this->encryptionKey)) {
				// Use openssl_encrypt for actual encryption
				$cipher = 'AES-256-CBC';
				$ivLength = openssl_cipher_iv_length($cipher);
				$iv = openssl_random_pseudo_bytes($ivLength);
				$encrypted = openssl_encrypt(
					$payloadJson,
					$cipher,
					hash('sha256', $this->encryptionKey, true),
					0,
					$iv
				);

				return base64_encode($iv . $encrypted);
			}

			// Fallback: just base64 encode (not secure, but works for development)
			return base64_encode($payloadJson);
		} catch (\Exception $e) {
			Log::error('Failed to encrypt payload', ['error' => $e->getMessage()]);
			// Fallback to base64
			return base64_encode(json_encode($payload));
		}
	}

	/**
	 * Generate HMAC signature for request validation
	 */
	protected function generateSignature(string $encryptedPayload, int $timestamp, string $nonce, ?string $apiKey = null): string
	{
		$key = $apiKey ?? $this->apiKey;
		$data = $encryptedPayload . $timestamp . $nonce;
		return hash_hmac('sha256', $data, $key);
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
	 */
	public function getAvailableModels(): array
	{
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