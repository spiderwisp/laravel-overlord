<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spiderwisp\LaravelOverlord\Jobs\ScanCodebaseJob;
use Spiderwisp\LaravelOverlord\Models\ScanIssue;
use Spiderwisp\LaravelOverlord\Models\ScanHistory;
use Spiderwisp\LaravelOverlord\Services\RealAiService;

class ScanController extends Controller
{
	/**
	 * Validate scanId format to prevent injection attacks
	 * 
	 * @param string $scanId
	 * @return bool
	 */
	protected function validateScanId(string $scanId): bool
	{
		return preg_match('/^[a-zA-Z0-9_-]+$/', $scanId) === 1;
	}

	/**
	 * Get file tree for selection
	 */
	public function fileTree(Request $request)
	{
		try {
			$appPath = base_path('app');
			$tree = $this->buildFileTree($appPath);

			return response()->json([
				'success' => true,
				'result' => $tree,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get file tree', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get file tree: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Build file tree structure
	 */
	protected function buildFileTree(string $basePath, string $relativePath = ''): array
	{
		$tree = [];
		$fullPath = $relativePath ? $basePath . '/' . $relativePath : $basePath;

		if (!is_dir($fullPath)) {
			return $tree;
		}

		$items = scandir($fullPath);
		$items = array_filter($items, function ($item) {
			return $item !== '.' && $item !== '..';
		});

		foreach ($items as $item) {
			$itemPath = $relativePath ? $relativePath . '/' . $item : $item;
			$itemFullPath = $basePath . '/' . $itemPath;

			// Skip vendor, cache, and test directories
			if (
				strpos($itemPath, 'vendor') !== false ||
				strpos($itemPath, 'cache') !== false ||
				strpos($itemPath, 'tests') !== false
			) {
				continue;
			}

			if (is_dir($itemFullPath)) {
				$children = $this->buildFileTree($basePath, $itemPath);
				$phpFiles = $this->countPhpFiles($itemFullPath);

				if ($phpFiles > 0 || count($children) > 0) {
					$tree[] = [
						'name' => $item,
						'path' => $itemPath,
						'type' => 'directory',
						'file_count' => $phpFiles,
						'children' => $children,
					];
				}
			} elseif (is_file($itemFullPath) && pathinfo($itemFullPath, PATHINFO_EXTENSION) === 'php') {
				// Skip test files
				if (strpos($item, 'Test.php') !== false) {
					continue;
				}

				$tree[] = [
					'name' => $item,
					'path' => $itemPath,
					'type' => 'file',
					'size' => filesize($itemFullPath),
				];
			}
		}

		// Sort: directories first, then files, both alphabetically
		usort($tree, function ($a, $b) {
			if ($a['type'] !== $b['type']) {
				return $a['type'] === 'directory' ? -1 : 1;
			}
			return strcmp($a['name'], $b['name']);
		});

		return $tree;
	}

	/**
	 * Count PHP files in directory recursively
	 */
	protected function countPhpFiles(string $path): int
	{
		$count = 0;
		if (!is_dir($path)) {
			return $count;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				if (
					strpos($file->getPathname(), '/vendor/') === false &&
					strpos($file->getPathname(), '/cache/') === false &&
					strpos($file->getPathname(), '/tests/') === false &&
					strpos($file->getFilename(), 'Test.php') === false
				) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * Start a new codebase scan
	 */
	public function start(Request $request)
	{
		try {
			$userId = auth()->id();
			$clearExisting = $request->input('clear_existing', false);
			$scanMode = $request->input('mode', 'full'); // 'full' or 'selective'
			$paths = $request->input('paths', []); // Array of selected paths

			// Clear existing issues if requested
			if ($clearExisting && $userId) {
				ScanIssue::where('user_id', $userId)->delete();
			}

			// Generate unique scan ID
			$scanId = 'scan_' . Str::random(16) . '_' . time();

			// Create scan history record (user_id can be null if not authenticated)
			$scanHistory = ScanHistory::create([
				'scan_id' => $scanId,
				'user_id' => $userId, // null if not authenticated
				'status' => 'scanning',
				'scan_mode' => $scanMode,
				'selected_paths' => $paths,
				'started_at' => now(),
			]);

			// Initialize scan state as "scanning" so frontend can start polling immediately
			Cache::put("overlord_scan_{$scanId}", [
				'scan_id' => $scanId,
				'user_id' => $userId,
				'status' => 'scanning',
				'progress' => 0,
				'total_files' => 0,
				'processed_files' => 0,
				'total_batches' => 0,
				'processed_batches' => 0,
				'created_at' => now()->toIso8601String(),
				'started_at' => now()->toIso8601String(),
				'scan_mode' => $scanMode,
				'selected_paths' => $paths,
			], now()->addHours(2));

			// Return response immediately, then run job in background
			$response = response()->json([
				'success' => true,
				'result' => [
					'scan_id' => $scanId,
					'status' => 'scanning', // Return scanning so frontend knows to poll
				],
			]);

			// If fastcgi_finish_request is available (PHP-FPM), use it to send response and continue
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();

				try {
					// Now run the job - response already sent to client
					$job = new ScanCodebaseJob($scanId, $userId, $scanMode, $paths);
					$job->handle(app(RealAiService::class));
				} catch (\Exception $e) {
					Log::error('Scan job execution failed', [
						'scan_id' => $scanId,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);

					Cache::put("overlord_scan_{$scanId}", [
						'status' => 'failed',
						'error' => 'Job execution failed: ' . $e->getMessage(),
					], now()->addHours(2));
				}
			} else {
				// For non-FPM environments, run job in background process
				$job = new ScanCodebaseJob($scanId, $userId, $scanMode, $paths);
				$aiService = app(RealAiService::class);

				if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
					// SECURITY: scanId is generated server-side using Str::random() and time(), not user input
					// Additional validation to ensure scanId only contains safe characters
					if (!preg_match('/^[a-zA-Z0-9_-]+$/', $scanId)) {
						throw new \InvalidArgumentException('Invalid scan ID format');
					}

					// Create a temporary script to run the job
					$frameworkDir = storage_path('framework');
					if (!is_dir($frameworkDir)) {
						mkdir($frameworkDir, 0755, true);
					}

					// SECURITY: All paths are constructed from storage_path() (safe) and validated scanId
					// No user input is directly used in file paths
					// Properly encode paths for PHP script - use var_export for safe serialization
					$pathsPhp = var_export($paths ?: [], true);
					$userIdValue = $userId ?: 'null';
					$script = $frameworkDir . '/scan_' . $scanId . '.php';
					$logFile = storage_path('logs/scan_' . $scanId . '.log');

					// Additional validation: ensure paths are within storage directory
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
    \$paths = {$pathsPhp};
    if (!is_array(\$paths)) {
        \$paths = [];
    }
    \$userId = {$userIdValue};
    
    \$job = new Spiderwisp\LaravelOverlord\Jobs\ScanCodebaseJob('{$scanId}', \$userId, '{$scanMode}', \$paths);
    \$job->handle(\$app->make(Spiderwisp\LaravelOverlord\Services\RealAiService::class));
} catch (\\Exception \$e) {
    // Log errors only (not debug info)
    if (file_exists('{$logFile}')) {
        file_put_contents('{$logFile}', "Error: " . \$e->getMessage() . "\\n", FILE_APPEND);
    }
    throw \$e;
}

@unlink(__FILE__); // Clean up script after execution
PHP;
					file_put_contents($script, $scriptContent);

					// Run in background and redirect output to log file
					// SECURITY: All arguments are sanitized with escapeshellarg() to prevent command injection
					// PHP_BINARY is a PHP constant (safe), script and logFile are validated above
					$phpPath = PHP_BINARY;
					$command = sprintf(
						'%s %s >> %s 2>&1 &',
						escapeshellarg($phpPath),
						escapeshellarg($script),
						escapeshellarg($logFile)
					);
					exec($command);
				} else {
					// Fallback: run directly (will block but at least it works)
					try {
						$job->handle($aiService);
					} catch (\Exception $e) {
						Log::error('Scan job execution failed', [
							'scan_id' => $scanId,
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]);

						Cache::put("overlord_scan_{$scanId}", [
							'status' => 'failed',
							'error' => 'Job execution failed: ' . $e->getMessage(),
						], now()->addHours(2));
					}
				}
			}

			return $response;
		} catch (\Exception $e) {
			Log::error('Failed to start scan', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to start scan: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan status
	 */
	public function status(Request $request, string $scanId)
	{
		try {
			// SECURITY: Validate scanId format to prevent cache key injection
			if (!$this->validateScanId($scanId)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid scan ID format',
				], 400);
			}

			$key = "overlord_scan_{$scanId}";
			$state = Cache::get($key);

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
			Log::error('Failed to get scan status', [
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
		try {
			// SECURITY: Validate scanId format to prevent cache key injection
			if (!$this->validateScanId($scanId)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid scan ID format',
				], 400);
			}

			// Check if scan exists
			$stateKey = "overlord_scan_{$scanId}";
			$state = Cache::get($stateKey);

			if (!$state) {
				return response()->json([
					'success' => false,
					'error' => 'Scan not found',
				], 404);
			}

			// Get results
			$resultsKey = "overlord_scan_{$scanId}_results";
			$results = Cache::get($resultsKey);

			if (!$results) {
				// If scan is still in progress, return partial results
				if ($state['status'] !== 'completed' && $state['status'] !== 'failed') {
					return response()->json([
						'success' => true,
						'result' => [
							'status' => $state['status'],
							'progress' => $state['progress'] ?? 0,
							'message' => 'Scan is still in progress',
						],
					]);
				}

				return response()->json([
					'success' => false,
					'error' => 'Results not available',
				], 404);
			}

			return response()->json([
				'success' => true,
				'result' => array_merge($results, [
					'scan_id' => $scanId,
					'status' => $state['status'] ?? 'completed',
				]),
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get scan results', [
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
	 * Get scan issues from database
	 */
	public function issues(Request $request)
	{
		try {
			$userId = auth()->id();
			$scanId = $request->input('scan_id');
			$resolved = $request->input('resolved'); // null, true, or false

			// SECURITY: Validate scanId format if provided
			if ($scanId && !$this->validateScanId($scanId)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid scan ID format',
				], 400);
			}

			$query = ScanIssue::query();

			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				// If no user, only return issues with null user_id
				$query->whereNull('user_id');
			}

			if ($scanId) {
				$query->where('scan_id', $scanId);
			}

			if ($resolved !== null) {
				$query->where('resolved', $resolved === true || $resolved === 'true' || $resolved === 1);
			}

			// PERFORMANCE: Add limit to prevent unlimited result sets
			$limit = min((int) $request->input('limit', 100), 1000);
			$offset = (int) $request->input('offset', 0);
			$total = $query->count();

			$issues = $query->orderBy('created_at', 'desc')
				->offset($offset)
				->limit($limit)
				->get();

			return response()->json([
				'success' => true,
				'result' => $issues,
				'count' => $issues->count(),
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan issues: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Check if user has existing scan issues
	 */
	public function hasExistingIssues(Request $request)
	{
		try {
			$userId = auth()->id();
			$query = ScanIssue::where('resolved', false);

			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}

			$count = $query->count();

			return response()->json([
				'success' => true,
				'result' => [
					'has_issues' => $count > 0,
					'unresolved_count' => $count,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to check existing scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to check existing issues: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Mark scan issue as resolved
	 */
	public function resolveIssue(Request $request, int $issueId)
	{
		try {
			$userId = auth()->id();

			$query = ScanIssue::where('id', $issueId);
			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}
			$issue = $query->first();

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			$issue->markAsResolved($userId);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to resolve scan issue', [
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
	 * Mark scan issue as unresolved
	 */
	public function unresolveIssue(Request $request, int $issueId)
	{
		try {
			$userId = auth()->id();

			$query = ScanIssue::where('id', $issueId);
			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}
			$issue = $query->first();

			if (!$issue) {
				return response()->json([
					'success' => false,
					'error' => 'Issue not found',
				], 404);
			}

			$issue->markAsUnresolved();

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to unresolve scan issue', [
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
	 * Clear old scan issues
	 */
	public function clearIssues(Request $request)
	{
		try {
			$userId = auth()->id();
			$resolvedOnly = $request->input('resolved_only', false);

			// Delete all issues for this user
			$query = ScanIssue::query();
			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}

			if ($resolvedOnly) {
				$query->where('resolved', true);
			}

			$deletedIssues = $query->delete();

			// Also delete scan history records for this user (since they have no issues now)
			if ($userId) {
				$deletedHistory = ScanHistory::where('user_id', $userId)->delete();
			} else {
				$deletedHistory = ScanHistory::whereNull('user_id')->delete();
			}

			// Cleared scan issues and history

			return response()->json([
				'success' => true,
				'message' => 'All issues and scan history cleared',
				'result' => [
					'deleted_count' => $deletedIssues,
					'deleted_issues' => $deletedIssues,
					'deleted_history' => $deletedHistory,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to clear scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to clear issues: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan history list
	 */
	public function history(Request $request)
	{
		try {
			$userId = auth()->id();
			$limit = $request->input('limit', 50);
			$status = $request->input('status'); // optional filter

			$query = ScanHistory::query();
			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}

			if ($status) {
				$query->where('status', $status);
			}

			$scans = $query->orderBy('created_at', 'desc')
				->limit($limit)
				->get();

			return response()->json([
				'success' => true,
				'result' => $scans,
				'count' => $scans->count(),
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get scan history', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan history: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get scan history details
	 */
	public function historyDetails(Request $request, string $scanId)
	{
		try {
			// SECURITY: Validate scanId format to prevent injection
			if (!$this->validateScanId($scanId)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid scan ID format',
				], 400);
			}

			$userId = auth()->id();

			$scanQuery = ScanHistory::where('scan_id', $scanId);
			if ($userId) {
				$scanQuery->where('user_id', $userId);
			} else {
				$scanQuery->whereNull('user_id');
			}
			$scan = $scanQuery->first();

			if (!$scan) {
				return response()->json([
					'success' => false,
					'error' => 'Scan not found',
				], 404);
			}

			// Get issues for this scan
			$issuesQuery = ScanIssue::where('scan_id', $scanId);
			if ($userId) {
				$issuesQuery->where('user_id', $userId);
			} else {
				$issuesQuery->whereNull('user_id');
			}

			// SECURITY: Add limit to prevent unlimited result sets
			// For scan details, we allow more items but still limit
			$limit = min((int) $request->input('limit', 500), 2000); // Max 2000 items for scan details
			$issues = $issuesQuery->orderBy('severity', 'desc')
				->orderBy('file_path')
				->orderBy('line')
				->limit($limit)
				->get();

			return response()->json([
				'success' => true,
				'result' => [
					'scan' => $scan,
					'issues' => $issues,
					'issues_count' => $issues->count(),
					'limit' => $limit,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get scan history details', [
				'scan_id' => $scanId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan details: ' . $e->getMessage(),
			], 500);
		}
	}
}