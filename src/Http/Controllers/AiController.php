<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spiderwisp\LaravelOverlord\Services\RealAiService;
use Spiderwisp\LaravelOverlord\Services\AiService;
use Spiderwisp\LaravelOverlord\Models\Setting;

class AiController extends Controller
{
	protected $aiService;

	public function __construct()
	{
		$realAiService = app(RealAiService::class);
		$fallbackAiService = app(AiService::class);
		$realAiService->setFallbackService($fallbackAiService);
		$this->aiService = $realAiService;
	}

	/**
	 * Send a chat message to the AI
	 */
	public function chat(Request $request)
	{
		try {
			$request->validate([
				'message' => 'required|string|max:5000',
				'conversation_history' => 'nullable|array',
				'conversation_history.*.role' => 'required|string|in:user,assistant',
				'conversation_history.*.content' => 'required|string',
				'log_context' => 'nullable|array',
				'log_context.file' => 'nullable|string',
				'log_context.line_number' => 'nullable|integer|min:1',
				'log_context.error_line' => 'nullable|string',
				'log_context.parsed' => 'nullable|array',
				'log_context.surrounding_lines' => 'nullable|array',
			]);

			if (!$this->aiService->isEnabled()) {
				return response()->json([
					'success' => false,
					'error' => 'AI features are disabled',
				], 403);
			}

			$message = $request->input('message');
			$conversationHistory = $request->input('conversation_history', []);
			$logContext = $request->input('log_context');

			$result = $this->aiService->chat($message, $conversationHistory, null, $logContext);

			if ($result['success']) {
				$response = [
					'success' => true,
					'result' => (object) [
						'message' => $result['message'] ?? '',
					],
					'message' => $result['message'] ?? '',
					'model' => $result['model'] ?? null,
				];

				// Include quota/fallback flags if present
				if (isset($result['quota_exceeded'])) {
					$response['quota_exceeded'] = $result['quota_exceeded'];
				}
				if (isset($result['using_fallback'])) {
					$response['using_fallback'] = $result['using_fallback'];
				}

				return response()->json($response);
			}

			return response()->json([
				'success' => false,
				'error' => $result['error'] ?? 'Unknown error',
				'code' => $result['code'] ?? null,
				'errors' => [$result['error'] ?? 'Unknown error'],
			], 500);
		} catch (\Illuminate\Validation\ValidationException $e) {
			\Log::error('AI chat validation error', [
				'errors' => $e->errors(),
				'request_data' => $request->all(),
			]);
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Throwable $e) {
			\Log::error('AI chat error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
				'message_preview' => substr($request->input('message', ''), 0, 100),
			]);

			return response()->json([
				'success' => false,
				'error' => 'AI service error: ' . $e->getMessage(),
				'errors' => ['AI service error: ' . $e->getMessage()],
			], 500);
		}
	}

	/**
	 * Get AI status and configuration
	 */
	public function getStatus()
	{
		$isEnabled = $this->aiService->isEnabled();
		$isAvailable = $isEnabled ? $this->aiService->isAvailable() : false;
		$defaultModel = $this->aiService->getDefaultModel();
		$baseUrl = $this->aiService->getBaseUrl();

		return response()->json([
			'success' => true,
			'enabled' => $isEnabled,
			'available' => $isAvailable,
			'default_model' => $defaultModel,
			'base_url' => $baseUrl,
		]);
	}

	/**
	 * Get API key status and settings
	 */
	public function getApiKeyStatus()
	{
		$apiKey = config('laravel-overlord.ai.api_key');
		$apiUrl = config('laravel-overlord.ai.api_url');
		$hasDbKey = false;
		$dbKey = null;

		// Check if key is from database
		try {
			$hasDbKey = Setting::has('ai_api_key');
			if ($hasDbKey) {
				$dbKey = Setting::get('ai_api_key');
			}
		} catch (\Exception $e) {
			// Table might not exist yet
		}

		$isConfigured = !empty($apiKey) && !empty($apiUrl);
		
		// Determine source: if config value doesn't match database value, it's from env
		// If they match or database is empty, check if database has a value
		$source = null;
		if (!empty($apiKey)) {
			if ($hasDbKey && $dbKey === $apiKey) {
				$source = 'database';
			} else {
				// If config has value but doesn't match database, it's from env
				$source = 'env';
			}
		}

		$getApiKeyUrl = 'https://laravel-overlord.com';

		$settings = null;

		if ($isConfigured) {
			try {
				$trimmedKey = trim($apiKey);
				$response = \Illuminate\Support\Facades\Http::timeout(10)
					->withHeaders([
						'Authorization' => 'Bearer ' . $trimmedKey,
						'Accept' => 'application/json',
					])
					->get(rtrim($apiUrl, '/') . '/ai/settings');

				if ($response->successful()) {
					$settings = $response->json();
				} else {
					// If settings endpoint fails, the key might be invalid
					\Log::warning('API key validation failed', [
						'status_code' => $response->status(),
						'response' => $response->json(),
					]);
				}
			} catch (\Exception $e) {
				\Log::warning('Failed to fetch settings', ['error' => $e->getMessage()]);
			}
		}

		// Return masked API key for display (show last 4 characters)
		$maskedKey = null;
		if (!empty($apiKey)) {
			$keyLength = strlen($apiKey);
			if ($keyLength > 4) {
				$maskedKey = str_repeat('*', $keyLength - 4) . substr($apiKey, -4);
			} else {
				$maskedKey = str_repeat('*', $keyLength);
			}
		}

		return response()->json([
			'success' => true,
			'is_configured' => $isConfigured,
			'has_api_key' => !empty($apiKey),
			'has_api_url' => !empty($apiUrl),
			'source' => $source, // 'env', 'database', or null
			'masked_key' => $maskedKey,
			'get_api_key_url' => $getApiKeyUrl,
			'settings' => $settings,
			'message' => $isConfigured
				? 'API key is configured'
				: 'API key is not configured. Please configure your API key at laravel-overlord.com.',
		]);
	}

	/**
	 * Get API key setting (masked)
	 */
	public function getApiKeySetting()
	{
		try {
			$apiKey = config('laravel-overlord.ai.api_key');
			$hasDbKey = false;
			$dbKey = null;

			// Check if key is from database
			try {
				$hasDbKey = Setting::has('ai_api_key');
				if ($hasDbKey) {
					$dbKey = Setting::get('ai_api_key');
				}
			} catch (\Exception $e) {
				// Table might not exist yet
			}

			// Determine source: if config value doesn't match database value, it's from env
			$source = null;
			if (!empty($apiKey)) {
				if ($hasDbKey && $dbKey === $apiKey) {
					$source = 'database';
				} else {
					// If config has value but doesn't match database, it's from env
					$source = 'env';
				}
			}

			// Return masked API key
			$maskedKey = null;
			if (!empty($apiKey)) {
				$keyLength = strlen($apiKey);
				if ($keyLength > 4) {
					$maskedKey = str_repeat('*', $keyLength - 4) . substr($apiKey, -4);
				} else {
					$maskedKey = str_repeat('*', $keyLength);
				}
			}

			return response()->json([
				'success' => true,
				'has_api_key' => !empty($apiKey),
				'source' => $source,
				'masked_key' => $maskedKey,
				'is_from_env' => $source === 'env',
				'message' => !empty($apiKey)
					? 'API key is configured'
					: 'API key is not configured. Get your API key from laravel-overlord.com.',
			]);
		} catch (\Exception $e) {
			\Log::error('Failed to get API key setting', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'error' => 'Failed to retrieve API key setting',
			], 500);
		}
	}

	/**
	 * Update API key setting
	 */
	public function updateApiKeySetting(Request $request)
	{
		try {
			$request->validate([
				'api_key' => 'required|string|min:10',
			]);

			$apiKey = trim($request->input('api_key'));
			$configKey = config('laravel-overlord.ai.api_key');
			$dbKey = null;

			// Check if key exists in database
			try {
				if (Setting::has('ai_api_key')) {
					$dbKey = Setting::get('ai_api_key');
				}
			} catch (\Exception $e) {
				// Table might not exist yet
			}

			// If config value exists and doesn't match database value, it's from env
			// Don't allow database override if env is set
			if (!empty($configKey) && $configKey !== $dbKey) {
				return response()->json([
					'success' => false,
					'error' => 'API key is set in environment variables and cannot be overridden via settings. Remove LARAVEL_OVERLORD_API_KEY from your .env file to use database settings.',
				], 400);
			}

			// Save to database
			try {
				Setting::set('ai_api_key', $apiKey, 'AI API key for Laravel Overlord');
				
				// Clear config cache so new value is picked up
				\Artisan::call('config:clear');

				// Return masked key
				$keyLength = strlen($apiKey);
				$maskedKey = $keyLength > 4 
					? str_repeat('*', $keyLength - 4) . substr($apiKey, -4)
					: str_repeat('*', $keyLength);

				return response()->json([
					'success' => true,
					'message' => 'API key updated successfully',
					'masked_key' => $maskedKey,
					'source' => 'database',
				]);
			} catch (\Exception $e) {
				\Log::error('Failed to save API key setting', ['error' => $e->getMessage()]);
				return response()->json([
					'success' => false,
					'error' => 'Failed to save API key. Please ensure the database migration has been run.',
				], 500);
			}
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			\Log::error('Failed to update API key setting', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'error' => 'Failed to update API key setting',
			], 500);
		}
	}

	/**
	 * Delete API key setting (remove from database)
	 */
	public function deleteApiKeySetting()
	{
		try {
			$configKey = config('laravel-overlord.ai.api_key');
			$dbKey = null;

			// Check if key exists in database
			try {
				if (Setting::has('ai_api_key')) {
					$dbKey = Setting::get('ai_api_key');
				}
			} catch (\Exception $e) {
				// Table might not exist yet
			}

			// If config value exists and doesn't match database value, it's from env
			// Don't allow deletion if env is set
			if (!empty($configKey) && $configKey !== $dbKey) {
				return response()->json([
					'success' => false,
					'error' => 'API key is set in environment variables. Remove LARAVEL_OVERLORD_API_KEY from your .env file to manage it via settings.',
				], 400);
			}

			// Delete from database
			try {
				Setting::remove('ai_api_key');
				
				// Clear config cache
				\Artisan::call('config:clear');

				return response()->json([
					'success' => true,
					'message' => 'API key removed successfully',
				]);
			} catch (\Exception $e) {
				\Log::error('Failed to delete API key setting', ['error' => $e->getMessage()]);
				return response()->json([
					'success' => false,
					'error' => 'Failed to remove API key',
				], 500);
			}
		} catch (\Exception $e) {
			\Log::error('Failed to delete API key setting', ['error' => $e->getMessage()]);
			return response()->json([
				'success' => false,
				'error' => 'Failed to delete API key setting',
			], 500);
		}
	}
}