<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spiderwisp\LaravelOverlord\Services\RealAiService;
use Spiderwisp\LaravelOverlord\Services\AiService;

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
	 * Get list of available AI models
	 */
	public function getModels()
	{
		if (!$this->aiService->isEnabled()) {
			return response()->json([
				'success' => false,
				'error' => 'AI features are disabled',
			], 403);
		}

		$models = $this->aiService->getAvailableModels();
		$isAvailable = $this->aiService->isAvailable();
		$defaultModel = $this->aiService->getDefaultModel();

		return response()->json([
			'success' => true,
			'available' => $isAvailable,
			'models' => $models,
			'default_model' => $defaultModel,
		]);
	}

	/**
	 * Check if a specific model is available
	 */
	public function checkModel(Request $request)
	{
		$request->validate([
			'model' => 'required|string|max:100',
		]);

		if (!$this->aiService->isEnabled()) {
			return response()->json([
				'success' => false,
				'available' => false,
				'error' => 'AI features are disabled',
			], 403);
		}

		$model = $request->input('model');
		$isAvailable = $this->aiService->isModelAvailable($model);

		return response()->json([
			'success' => true,
			'model' => $model,
			'available' => $isAvailable,
		]);
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

		$isConfigured = !empty($apiKey) && !empty($apiUrl);

		$defaultUrl = 'https://laravel-overlord.com';
		$getApiKeyUrl = $apiUrl ? rtrim($apiUrl, '/') . '/api-keys' : rtrim($defaultUrl, '/') . '/api-keys';

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

		return response()->json([
			'success' => true,
			'is_configured' => $isConfigured,
			'has_api_key' => !empty($apiKey),
			'has_api_url' => !empty($apiUrl),
			'get_api_key_url' => $getApiKeyUrl,
			'settings' => $settings,
			'message' => $isConfigured
				? 'API key is configured'
				: 'API key is not configured. Please configure your API key.',
		]);
	}
}