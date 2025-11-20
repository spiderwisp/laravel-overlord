<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BugReportService
{
	protected $apiUrl;
	protected $encryptionKey;
	protected $apiKey;

	public function __construct()
	{
		$this->apiUrl = rtrim(config('laravel-overlord.bug_report.api_url', 'https://laravel-overlord.com/api/bug-reports'), '/');
		$this->encryptionKey = trim(config('laravel-overlord.ai.encryption_key', '')) 
			?: trim(config('laravel-overlord.ai.api_key', ''));
		$this->apiKey = trim(config('laravel-overlord.ai.api_key', ''));
	}

	/**
	 * Collect system information
	 */
	protected function collectSystemInfo(): array
	{
		return [
			'php_version' => PHP_VERSION,
			'laravel_version' => app()->version(),
			'package_version' => $this->getPackageVersion(),
			'os' => PHP_OS,
			'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
		];
	}

	/**
	 * Collect environment information
	 */
	protected function collectEnvironmentInfo(): array
	{
		$dbType = 'unknown';
		try {
			$connection = DB::connection();
			$dbType = $connection->getDriverName();
		} catch (\Exception $e) {
			// Ignore database errors
		}

		return [
			'app_env' => config('app.env', 'unknown'),
			'app_debug' => config('app.debug', false),
			'database_type' => $dbType,
			'cache_driver' => config('cache.default', 'unknown'),
		];
	}

	/**
	 * Collect browser information from request
	 */
	protected function collectBrowserInfo($request): array
	{
		return [
			'user_agent' => $request->userAgent() ?? 'Unknown',
			'platform' => $this->detectPlatform($request->userAgent()),
		];
	}

	/**
	 * Detect platform from user agent
	 */
	protected function detectPlatform(?string $userAgent): string
	{
		if (!$userAgent) {
			return 'Unknown';
		}

		$userAgent = strtolower($userAgent);
		
		if (strpos($userAgent, 'windows') !== false) {
			return 'Windows';
		} elseif (strpos($userAgent, 'mac') !== false) {
			return 'macOS';
		} elseif (strpos($userAgent, 'linux') !== false) {
			return 'Linux';
		} elseif (strpos($userAgent, 'android') !== false) {
			return 'Android';
		} elseif (strpos($userAgent, 'ios') !== false || strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) {
			return 'iOS';
		}

		return 'Unknown';
	}

	/**
	 * Get package version from composer.lock or composer.json
	 */
	protected function getPackageVersion(): string
	{
		try {
			// Try composer.lock first (more accurate)
			$lockPath = __DIR__ . '/../../composer.lock';
			if (file_exists($lockPath)) {
				$lock = json_decode(file_get_contents($lockPath), true);
				$packageName = 'spiderwisp/laravel-overlord';
				if (isset($lock['packages'])) {
					foreach ($lock['packages'] as $package) {
						if (isset($package['name']) && $package['name'] === $packageName) {
							return $package['version'] ?? '1.0.0';
						}
					}
				}
			}

			// Fallback to composer.json
			$composerPath = __DIR__ . '/../../composer.json';
			if (file_exists($composerPath)) {
				$composer = json_decode(file_get_contents($composerPath), true);
				return $composer['version'] ?? '1.0.0';
			}
		} catch (\Exception $e) {
			// Ignore errors
		}

		return '1.0.0';
	}

	/**
	 * Build bug report payload
	 */
	public function buildPayload(array $data, $request, array $includeOptions = []): array
	{
		$payload = [
			'title' => $data['title'] ?? '',
			'description' => $data['description'] ?? '',
			'steps_to_reproduce' => $data['steps_to_reproduce'] ?? null,
			'error_message' => $data['error_message'] ?? null,
			'stack_trace' => $data['stack_trace'] ?? null,
			'submitted_at' => now()->toIso8601String(),
		];

		// Add optional data based on user preferences
		if (!empty($includeOptions['include_system_info'])) {
			$payload['system_info'] = $this->collectSystemInfo();
		}

		if (!empty($includeOptions['include_environment_info'])) {
			$payload['environment_info'] = $this->collectEnvironmentInfo();
		}

		if (!empty($includeOptions['include_browser_info'])) {
			$payload['browser_info'] = $this->collectBrowserInfo($request);
		}

		if (!empty($includeOptions['include_package_version'])) {
			$payload['package_version'] = $this->getPackageVersion();
		}

		return $payload;
	}

	/**
	 * Encrypt payload using AES-256-CBC
	 */
	protected function encryptPayload(array $payload): string
	{
		try {
			$payloadJson = json_encode($payload);

			if (!empty($this->encryptionKey)) {
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
			Log::warning('Bug report encryption key not set, using base64 encoding only');
			return base64_encode($payloadJson);
		} catch (\Exception $e) {
			Log::error('Failed to encrypt bug report payload', ['error' => $e->getMessage()]);
			throw new \Exception('Failed to encrypt bug report payload');
		}
	}

	/**
	 * Submit bug report to external API
	 */
	public function submit(array $data, $request, array $includeOptions = []): array
	{
		try {
			// Build payload
			$payload = $this->buildPayload($data, $request, $includeOptions);

			// Encrypt payload
			$encryptedPayload = $this->encryptPayload($payload);

			// Prepare request data
			$requestData = [
				'encrypted_payload' => $encryptedPayload,
			];

			// Add API key if available (sent separately, not encrypted)
			if (!empty($this->apiKey)) {
				$requestData['api_key'] = $this->apiKey;
			}

			// Send HTTP request
			$response = Http::timeout(30)
				->post($this->apiUrl, $requestData);

			if ($response->successful()) {
				return [
					'success' => true,
					'message' => 'Bug report submitted successfully',
					'data' => $response->json(),
				];
			}

			// Handle API errors
			$errorMessage = $response->json()['message'] ?? 'Failed to submit bug report';
			Log::error('Bug report API error', [
				'status' => $response->status(),
				'response' => $response->body(),
			]);

			return [
				'success' => false,
				'error' => $errorMessage,
			];
		} catch (\Exception $e) {
			Log::error('Failed to submit bug report', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return [
				'success' => false,
				'error' => 'Failed to submit bug report: ' . $e->getMessage(),
			];
		}
	}
}

