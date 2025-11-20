<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Spiderwisp\LaravelOverlord\Services\OverlordServices\LLMProviderInterface;
use Illuminate\Support\Facades\Log;

class RealAiService
{
	protected $provider;
	protected $contextGatherer;
	protected $enabled;
	protected $defaultModel;
	protected $fallbackService;

	public function __construct(
		ContextGatherer $contextGatherer = null
	) {
		$this->enabled = config('laravel-overlord.ai.enabled', true);
		$this->contextGatherer = $contextGatherer ?? new ContextGatherer();
		$this->provider = new \Spiderwisp\LaravelOverlord\Services\OverlordServices\OverlordProvider();
	}

	/**
	 * Set fallback service (called by service container)
	 */
	public function setFallbackService(AiService $fallbackService): void
	{
		$this->fallbackService = $fallbackService;
	}

	/**
	 * Filter shell commands from code blocks in AI responses
	 */
	protected function filterShellCommandsFromCodeBlocks(string $message): string
	{
		// Pattern to match code blocks
		$pattern = '/```(\w+)?\s*\n?([\s\S]*?)```/';

		return preg_replace_callback($pattern, function ($matches) {
			$language = $matches[1] ?? '';
			$code = trim($matches[2]);

			// Only filter if language is not explicitly PHP or if it's empty/missing
			// If language is explicitly 'php', we trust it's PHP code
			if (!empty($language) && strtolower($language) === 'php') {
				// Still check for obvious shell commands even in PHP blocks
				$firstLine = trim(explode("\n", $code)[0]);

				// Common shell commands that should NEVER be in PHP code blocks
				$shellCommands = [
					'composer ',
					'php artisan ',
					'php artisan ',
					'npm ',
					'yarn ',
					'git ',
					'docker ',
					'sudo ',
					'apt-get ',
					'apt ',
				];

				foreach ($shellCommands as $cmd) {
					if (stripos($firstLine, $cmd) === 0) {
						// This is definitely a shell command, remove it
						return "⚠️ **Shell commands cannot be executed in code blocks.**\n\n" .
							"The following shell command was detected and removed:\n" .
							"`{$code}`\n\n" .
							"If you need to run this command, do so in your system terminal, not in this console.";
					}
				}
			} else {
				// No language specified or not PHP - check if it looks like a shell command
				$firstLine = trim(explode("\n", $code)[0]);

				// Pattern: starts with a word followed by space (likely a shell command)
				// But exclude PHP keywords and common PHP patterns
				if (preg_match('/^(composer|php artisan|php |npm |yarn |git |docker |sudo |apt-get |apt |cd |ls |mkdir |rm |cp |mv |cat |grep |find |chmod |chown)\s+/i', $firstLine)) {
					return "⚠️ **Shell commands cannot be executed in code blocks.**\n\n" .
						"The following shell command was detected and removed:\n" .
						"`{$code}`\n\n" .
						"If you need to run this command, do so in your system terminal, not in this console.";
				}
			}

			// Return original code block if it's valid
			return $matches[0];
		}, $message);
	}

	/**
	 * Check if AI is enabled
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * Check if AI is available
	 */
	public function isAvailable(): bool
	{
		if (!$this->enabled) {
			return false;
		}

		if ($this->provider === null) {
			return false;
		}

		return $this->provider instanceof LLMProviderInterface && $this->provider->isAvailable();
	}

	/**
	 * Get list of available models
	 */
	public function getAvailableModels(): array
	{
		if (!$this->isEnabled()) {
			return [];
		}

		if ($this->isAvailable() && $this->provider instanceof LLMProviderInterface) {
			return $this->provider->getAvailableModels();
		}

		return [];
	}

	/**
	 * Check if a specific model is available
	 */
	public function isModelAvailable(string $modelName): bool
	{
		if (!$this->isAvailable()) {
			return false;
		}

		$models = $this->getAvailableModels();
		foreach ($models as $model) {
			if (isset($model['name']) && $model['name'] === $modelName) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the default model
	 */
	public function getDefaultModel(): string
	{
		return '';
	}

	/**
	 * Get the base URL (for display purposes)
	 */
	public function getBaseUrl(): string
	{
		if ($this->provider instanceof LLMProviderInterface) {
			$baseUrl = config('laravel-overlord.ai.base_url');
			if ($baseUrl) {
				return $baseUrl;
			}
			return $this->provider->getName();
		}

		return 'local';
	}

	/**
	 * Send a chat message to the AI
	 * 
	 * @param string $message User's message
	 * @param array $conversationHistory Previous conversation messages
	 * @param string|null $model Model to use (usually null)
	 * @param array|null $logContext Log context for debugging
	 * @param string|null $contextType Context type for specialized instructions (e.g., 'codebase_scan', 'general')
	 * @param array|null $analysisData Structured data for analysis (e.g., for codebase scans)
	 */
	public function chat(string $message, array $conversationHistory = [], ?string $model = null, ?array $logContext = null, ?string $contextType = null, ?array $analysisData = null): array
	{
		if (!$this->isEnabled()) {
			return [
				'success' => false,
				'error' => 'AI features are disabled',
			];
		}

		if ($this->provider === null) {
			return $this->fallbackToOldService($message, $conversationHistory, $model, $logContext);
		}

		if ($this->provider instanceof LLMProviderInterface) {
			try {
				// For codebase scans, skip context gathering - file content is already in the message
				if ($contextType === 'codebase_scan') {
					$context = [
						'codebase' => '',
						'database' => '',
						'logs' => '',
					];
				} else {
					// Gather context from codebase, database, and logs
					$context = $this->contextGatherer->gatherContext($message, $logContext);
				}

				$options = [];
				$systemPrompt = '';

				$result = $this->provider->chat(
					$message,
					$conversationHistory,
					$systemPrompt,
					$context,
					$options,
					$contextType,
					$analysisData
				);

				// Post-process response to remove shell commands from code blocks
				if (isset($result['message']) && !empty($result['message'])) {
					$result['message'] = $this->filterShellCommandsFromCodeBlocks($result['message']);
				}

				if ($result['success']) {
					return $result;
				}

				// Don't fallback for certain error codes that should be shown to the user
				$errorCode = $result['code'] ?? null;
				$shouldNotFallback = in_array($errorCode, [
					'INVALID_API_KEY',
					'QUOTA_EXCEEDED',
					'RATE_LIMIT_EXCEEDED',
				]);

				if ($shouldNotFallback) {
					Log::warning('AI service failed with non-fallback error', [
						'error' => $result['error'] ?? 'Unknown error',
						'error_code' => $errorCode,
					]);
					return $result;
				}

				if ($this->fallbackService) {
					Log::warning('AI service failed, falling back to pattern matching', [
						'error' => $result['error'] ?? 'Unknown error',
						'error_code' => $errorCode,
					]);
					return $this->fallbackToOldService($message, $conversationHistory, $model, $logContext, $errorCode);
				}

				return $result;
			} catch (\Exception $e) {
				Log::error('RealAiService chat failed', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
					'provider' => $this->provider instanceof LLMProviderInterface ? $this->provider->getName() : 'unknown',
				]);

				// Try fallback on exception
				if ($this->fallbackService) {
					return $this->fallbackToOldService($message, $conversationHistory, $model, $logContext, null);
				}

				return [
					'success' => false,
					'error' => 'Failed to generate response: ' . $e->getMessage(),
				];
			}
		}

		// Use fallback service
		Log::warning('RealAiService: Using fallback service', [
			'provider_type' => $this->provider ? get_class($this->provider) : 'null',
		]);
		return $this->fallbackToOldService($message, $conversationHistory, $model, null, null);
	}

	/**
	 * Fallback to old pattern-matching service
	 */
	protected function fallbackToOldService(string $message, array $conversationHistory = [], ?string $model = null, ?array $logContext = null, ?string $errorCode = null): array
	{
		if (!$this->fallbackService) {
			return [
				'success' => false,
				'error' => 'AI service is not available and no fallback is configured',
			];
		}

		try {
			$fallbackResult = $this->fallbackService->chat($message, $conversationHistory, $model, $logContext);

			// If quota was exceeded, add a warning message to the response
			if ($errorCode === 'QUOTA_EXCEEDED' && $fallbackResult['success']) {
				$originalMessage = $fallbackResult['message'] ?? '';
				$warningMessage = "⚠️ **AI Quota Exceeded - Using Basic Fallback**\n\n";
				$warningMessage .= "Your monthly AI request quota has been reached. The system is currently using a basic pattern-matching fallback instead of the full AI service.\n\n";
				$warningMessage .= "**To restore full AI functionality:**\n";
				$warningMessage .= "- Subscribe to a higher tier plan to increase your monthly quota\n";
				$warningMessage .= "- Or wait until your quota resets next month\n\n";
				$warningMessage .= "**Note:** If you don't have an API key configured, you'll need to get one from your SaaS dashboard first.\n\n";
				$warningMessage .= "---\n\n";
				$warningMessage .= "*Response below is from the basic fallback system:*\n\n";
				$warningMessage .= $originalMessage;

				$fallbackResult['message'] = $warningMessage;
				$fallbackResult['quota_exceeded'] = true;
				$fallbackResult['using_fallback'] = true;
			}

			return $fallbackResult;
		} catch (\Exception $e) {
			Log::error('Fallback service also failed', [
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'error' => 'Both LLM and fallback services failed',
			];
		}
	}
}