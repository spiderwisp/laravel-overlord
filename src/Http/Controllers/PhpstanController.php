<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spiderwisp\LaravelOverlord\Jobs\ScanPhpstanJob;
use Spiderwisp\LaravelOverlord\Models\ScanIssue;
use Spiderwisp\LaravelOverlord\Models\ScanHistory;
use Spiderwisp\LaravelOverlord\Services\PhpstanService;

class PhpstanController extends Controller
{
	/**
	 * Validate scanId format to prevent injection attacks
	 */
	protected function validateScanId(string $scanId): bool
	{
		return preg_match('/^[a-zA-Z0-9_-]+$/', $scanId) === 1;
	}

	/**
	 * Get PHPStan configuration (auto-detect with current settings)
	 */
	public function config(Request $request, PhpstanService $phpstanService)
	{
		try {
			$detectedConfig = $phpstanService->getConfigFromFile();
			$configFile = $phpstanService->detectConfigFile();
			$phpstanPath = $phpstanService->findPhpstanPath();

			return response()->json([
				'success' => true,
				'result' => [
					'config_file' => $configFile ? basename($configFile) : null,
					'config_file_path' => $configFile,
					'level' => $detectedConfig['level'],
					'paths' => $detectedConfig['paths'],
					'memory_limit' => $detectedConfig['memory_limit'],
					'phpstan_installed' => $phpstanPath !== null,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get PHPStan config', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get Larastan config: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Start a new PHPStan analysis
	 */
	public function start(Request $request, PhpstanService $phpstanService)
	{
		try {
			$userId = auth()->id();
			$clearExisting = $request->input('clear_existing', false);

			// Get configuration from request or auto-detect
			$detectedConfig = $phpstanService->getConfigFromFile();
			
			// Determine level: request > config file > default (1)
			// PHPStan requires a level to be specified
			$requestLevel = $request->input('level');
			if ($requestLevel !== null && $requestLevel !== '') {
				$level = (int) $requestLevel;
			} elseif (isset($detectedConfig['level']) && $detectedConfig['level'] !== null) {
				$level = (int) $detectedConfig['level'];
			} else {
				$level = 1; // Default level
			}
			
			$config = [
				'level' => $level,
				'paths' => $request->input('paths', $detectedConfig['paths'] ?: ['app']),
				'memory_limit' => $request->input('memory_limit', $detectedConfig['memory_limit']),
				'config_file' => $phpstanService->detectConfigFile(),
				'baseline_file' => $request->input('baseline_file'),
				'ignore_errors' => $request->input('ignore_errors', false),
			];

			// Clear existing issues if requested
			if ($clearExisting && $userId) {
				ScanIssue::where('user_id', $userId)
					->where('type', 'phpstan')
					->delete();
			}

			// Generate unique scan ID (with retry logic to handle collisions)
			$maxRetries = 5;
			$scanId = null;
			$scanHistory = null;
			
			for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
				// Generate scan ID with microseconds and random component to ensure uniqueness
				$microtime = (int)(microtime(true) * 1000000); // Microseconds since epoch
				$scanId = 'phpstan_' . Str::random(16) . '_' . $microtime;
				
				try {
					// Create scan history record
					$scanHistory = ScanHistory::create([
						'scan_id' => $scanId,
						'user_id' => $userId,
						'status' => 'scanning',
						'scan_mode' => 'phpstan',
						'selected_paths' => $config['paths'],
						'started_at' => now(),
					]);
					break; // Success, exit retry loop
				} catch (\Illuminate\Database\QueryException $e) {
					// If it's a duplicate key error, retry
					if ($e->getCode() == 23000 && (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), '1062') !== false)) {
						if ($attempt < $maxRetries - 1) {
							// Wait a bit longer and retry with new ID
							usleep(10000 + ($attempt * 1000)); // 10ms + increasing delay
							continue;
						}
					}
					// Re-throw if it's not a duplicate key error or we've exhausted retries
					throw $e;
				}
			}
			
			// If we couldn't create a unique scan_id after retries, throw an error
			if (!$scanHistory) {
				throw new \RuntimeException('Failed to generate unique scan ID after ' . $maxRetries . ' attempts');
			}

			// Initialize scan state
			Cache::put("overlord_phpstan_{$scanId}", [
				'scan_id' => $scanId,
				'user_id' => $userId,
				'status' => 'scanning',
				'progress' => 0,
				'created_at' => now()->toIso8601String(),
				'started_at' => now()->toIso8601String(),
				'config' => $config,
			], now()->addHours(2));

			// Return response immediately, then run job in background
			$response = response()->json([
				'success' => true,
				'result' => [
					'scan_id' => $scanId,
					'status' => 'scanning',
				],
			]);

			// If fastcgi_finish_request is available (PHP-FPM), use it
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();

				try {
					$job = new ScanPhpstanJob($scanId, $userId, $config);
					$job->handle(app(PhpstanService::class));
				} catch (\Exception $e) {
					Log::error('PHPStan job execution failed', [
						'scan_id' => $scanId,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);

					Cache::put("overlord_phpstan_{$scanId}", [
						'status' => 'failed',
						'error' => 'Job execution failed: ' . $e->getMessage(),
					], now()->addHours(2));
				}
			} else {
				// For non-FPM environments, run job in background process
				$job = new ScanPhpstanJob($scanId, $userId, $config);
				$phpstanService = app(PhpstanService::class);

				if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
					// Validate scanId
					if (!preg_match('/^[a-zA-Z0-9_-]+$/', $scanId)) {
						throw new \InvalidArgumentException('Invalid scan ID format');
					}

					// Create temporary script
					$frameworkDir = storage_path('framework');
					if (!is_dir($frameworkDir)) {
						mkdir($frameworkDir, 0755, true);
					}

					$configPhp = var_export($config, true);
					$userIdValue = $userId ?: 'null';
					$script = $frameworkDir . '/phpstan_' . $scanId . '.php';
					$logFile = storage_path('logs/phpstan_' . $scanId . '.log');

					// Validate paths
					$realFrameworkDir = realpath($frameworkDir);
					$realScriptPath = realpath(dirname($script));
					if ($realScriptPath === false || !str_starts_with($realScriptPath, $realFrameworkDir)) {
						throw new \RuntimeException('Invalid script path detected');
					}

					$scriptContent = <<<PHP
<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '{$logFile}');

require __DIR__ . '/../../vendor/autoload.php';
\$app = require_once __DIR__ . '/../../bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    \$config = {$configPhp};
    \$userId = {$userIdValue};
    
    \$job = new Spiderwisp\LaravelOverlord\Jobs\ScanPhpstanJob('{$scanId}', \$userId, \$config);
    \$job->handle(\$app->make(Spiderwisp\LaravelOverlord\Services\PhpstanService::class));
} catch (\\Exception \$e) {
    if (file_exists('{$logFile}')) {
        file_put_contents('{$logFile}', "Error: " . \$e->getMessage() . "\\n", FILE_APPEND);
    }
    throw \$e;
}

@unlink(__FILE__);
PHP;
					file_put_contents($script, $scriptContent);

					// Run in background
					$phpPath = PHP_BINARY;
					$command = sprintf(
						'%s %s >> %s 2>&1 &',
						escapeshellarg($phpPath),
						escapeshellarg($script),
						escapeshellarg($logFile)
					);
					exec($command);
				} else {
					// Fallback: run directly
					try {
						$job->handle($phpstanService);
					} catch (\Exception $e) {
						Log::error('PHPStan job execution failed', [
							'scan_id' => $scanId,
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]);

						Cache::put("overlord_phpstan_{$scanId}", [
							'status' => 'failed',
							'error' => 'Job execution failed: ' . $e->getMessage(),
						], now()->addHours(2));
					}
				}
			}

			return $response;
		} catch (\Exception $e) {
			Log::error('Failed to start PHPStan scan', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to start Larastan scan: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan status
	 */
	public function status(Request $request, string $scanId)
	{
		if (!$this->validateScanId($scanId)) {
			return response()->json([
				'success' => false,
				'error' => 'Invalid scan ID format',
			], 400);
		}

		try {
			$state = Cache::get("overlord_phpstan_{$scanId}");

			if (!$state) {
				return response()->json([
					'success' => false,
					'error' => 'Scan not found',
				], 404);
			}

			return response()->json([
				'success' => true,
				'result' => $state,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get PHPStan scan status', [
				'scan_id' => $scanId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan status: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan results
	 */
	public function results(Request $request, string $scanId)
	{
		if (!$this->validateScanId($scanId)) {
			return response()->json([
				'success' => false,
				'error' => 'Invalid scan ID format',
			], 400);
		}

		try {
			$results = Cache::get("overlord_phpstan_{$scanId}_results");

			if (!$results) {
				return response()->json([
					'success' => false,
					'error' => 'Results not found. The scan may still be in progress.',
				], 404);
			}

			return response()->json([
				'success' => true,
				'result' => $results,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get PHPStan scan results', [
				'scan_id' => $scanId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan results: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan history
	 */
	public function history(Request $request)
	{
		try {
			$userId = auth()->id();
			$limit = $request->input('limit', 50);

			$history = ScanHistory::where('scan_mode', 'phpstan')
				->when($userId, function ($query) use ($userId) {
					return $query->where('user_id', $userId);
				})
				->orderBy('created_at', 'desc')
				->limit($limit)
				->get();

			return response()->json([
				'success' => true,
				'result' => $history,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get PHPStan scan history', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan history: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get issues for a scan
	 */
	public function issues(Request $request)
	{
		try {
			$scanId = $request->input('scan_id');
			$userId = auth()->id();

			$query = ScanIssue::where('type', 'phpstan')
				->when($scanId, function ($q) use ($scanId) {
					return $q->where('scan_id', $scanId);
				})
				->when($userId, function ($q) use ($userId) {
					return $q->where('user_id', $userId);
				});

			$issues = $query->get();

			return response()->json([
				'success' => true,
				'result' => $issues,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get PHPStan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get issues: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Resolve an issue
	 */
	public function resolveIssue(Request $request, string $issueId)
	{
		try {
			$userId = auth()->id();
			$issue = ScanIssue::where('id', $issueId)
				->where('type', 'phpstan')
				->first();

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			$issue->update([
				'resolved' => true,
				'resolved_by_id' => $userId,
				'resolved_at' => now(),
			]);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to resolve PHPStan issue', [
				'issue_id' => $issueId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to resolve issue: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Unresolve an issue
	 */
	public function unresolveIssue(Request $request, string $issueId)
	{
		try {
			$issue = ScanIssue::where('id', $issueId)
				->where('type', 'phpstan')
				->first();

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			$issue->update([
				'resolved' => false,
				'resolved_by_id' => null,
				'resolved_at' => null,
			]);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to unresolve PHPStan issue', [
				'issue_id' => $issueId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to unresolve issue: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Clear all PHPStan issues
	 */
	public function clearIssues(Request $request)
	{
		try {
			$userId = auth()->id();
			$deletedCount = ScanIssue::where('type', 'phpstan')
				->when($userId, function ($query) use ($userId) {
					return $query->where('user_id', $userId);
				})
				->delete();

			return response()->json([
				'success' => true,
				'result' => [
					'deleted_count' => $deletedCount,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to clear PHPStan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to clear issues: ' . $e->getMessage(),
			], 500);
		}
	}
}

