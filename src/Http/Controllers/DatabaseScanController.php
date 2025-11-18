<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spiderwisp\LaravelOverlord\Jobs\ScanDatabaseJob;
use Spiderwisp\LaravelOverlord\Models\DatabaseScanIssue;
use Spiderwisp\LaravelOverlord\Models\DatabaseScanHistory;
use Spiderwisp\LaravelOverlord\Services\DatabaseSchemaService;

class DatabaseScanController extends Controller
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
	 * Get list of all database tables
	 */
	public function tables(Request $request)
	{
		try {
			$schemaService = new DatabaseSchemaService();
			$tables = $schemaService->getTables();

			return response()->json([
				'success' => true,
				'result' => $tables,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get tables', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get tables: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Start a new database scan
	 */
	public function start(Request $request)
	{
		try {
			$userId = auth()->id();
			$clearExisting = $request->input('clear_existing', false);
			$scanType = $request->input('type', 'schema'); // 'schema' or 'data'
			$scanMode = $request->input('mode', 'full'); // 'full' or 'selective'
			$tables = $request->input('tables', []); // Array of selected table names
			$sampleSize = (int) $request->input('sample_size', 100);

			// Clear existing issues if requested
			if ($clearExisting && $userId) {
				DatabaseScanIssue::where('user_id', $userId)->delete();
			}

			// Generate unique scan ID
			$scanId = 'db_scan_' . Str::random(16) . '_' . time();

			// Create scan history record
			$scanHistory = DatabaseScanHistory::create([
				'scan_id' => $scanId,
				'user_id' => $userId,
				'status' => 'scanning',
				'scan_type' => $scanType,
				'scan_mode' => $scanMode,
				'selected_tables' => $tables,
				'sample_size' => $sampleSize,
				'started_at' => now(),
			]);

			// Initialize scan state
			Cache::put("overlord_database_scan_{$scanId}_state", [
				'scan_id' => $scanId,
				'user_id' => $userId,
				'status' => 'scanning',
				'progress' => 0,
				'total_tables' => 0,
				'processed_tables' => 0,
				'total_batches' => 0,
				'processed_batches' => 0,
				'created_at' => now()->toIso8601String(),
				'started_at' => now()->toIso8601String(),
				'scan_type' => $scanType,
				'scan_mode' => $scanMode,
				'selected_tables' => $tables,
				'sample_size' => $sampleSize,
			], now()->addHours(2));

			// Return response immediately, then run job in background
			$response = response()->json([
				'success' => true,
				'result' => [
					'scan_id' => $scanId,
					'status' => 'scanning',
				],
			]);

			// If fastcgi_finish_request is available (PHP-FPM), use it to send response and continue
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();

				// Run job directly
				try {
					$job = new ScanDatabaseJob($scanId, $userId, $scanType, $scanMode, $tables, $sampleSize);
					$job->handle(
						app(\Spiderwisp\LaravelOverlord\Services\RealAiService::class),
						app(DatabaseSchemaService::class)
					);
				} catch (\Exception $e) {
					Log::error('Database scan job execution failed', [
						'scan_id' => $scanId,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);

					$errorMessage = $e->getMessage();
					
					// Check if this is a rate limit error - preserve the original error message
					$isRateLimit = stripos($errorMessage, 'RATE_LIMIT_EXCEEDED') !== false ||
						stripos($errorMessage, 'QUOTA_EXCEEDED') !== false ||
						stripos($errorMessage, 'rate limit') !== false ||
						stripos($errorMessage, 'quota exceeded') !== false;
					
					if ($isRateLimit) {
						// Remove the RATE_LIMIT_EXCEEDED prefix if present, keep the actual error message
						$cleanError = str_replace('RATE_LIMIT_EXCEEDED: ', '', $errorMessage);
						$errorMessage = $cleanError;
					} else {
						// Only use generic message for non-rate-limit errors
						$errorMessage = 'Job execution failed: ' . $e->getMessage();
					}

					Cache::put("overlord_database_scan_{$scanId}_state", [
						'status' => 'failed',
						'error' => $errorMessage,
					], now()->addHours(2));
				}
			} else {
				// Use exec() to run job in background
				// SECURITY: scanId is generated server-side using Str::random() and time(), not user input
				// Additional validation to ensure scanId only contains safe characters
				if (!preg_match('/^[a-zA-Z0-9_-]+$/', $scanId)) {
					throw new \InvalidArgumentException('Invalid scan ID format');
				}

				$frameworkDir = storage_path('framework');
				if (!is_dir($frameworkDir)) {
					mkdir($frameworkDir, 0755, true);
				}

				// SECURITY: All paths are constructed from storage_path() (safe) and validated scanId
				// No user input is directly used in file paths
				$scriptPath = $frameworkDir . '/run_database_scan_' . $scanId . '.php';
				$logPath = storage_path('logs/scan_' . $scanId . '.log');

				// Additional validation: ensure paths are within storage directory
				$realFrameworkDir = realpath($frameworkDir);
				$realScriptPath = realpath(dirname($scriptPath));
				if ($realScriptPath === false || !str_starts_with($realScriptPath, $realFrameworkDir)) {
					throw new \RuntimeException('Invalid script path detected');
				}
				$scriptContent = "<?php\n";
				$scriptContent .= "error_reporting(E_ALL);\n";
				$scriptContent .= "ini_set('display_errors', 0);\n";
				$scriptContent .= "ini_set('log_errors', 1);\n";
				$scriptContent .= "ini_set('error_log', " . var_export($logPath, true) . ");\n";
				$scriptContent .= "\n";
				$scriptContent .= "try {\n";
				$scriptContent .= "    require __DIR__ . '/../../vendor/autoload.php';\n";
				$scriptContent .= "    \$app = require_once __DIR__ . '/../../bootstrap/app.php';\n";
				$scriptContent .= "    \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();\n";
				$scriptContent .= "    \n";
				$scriptContent .= "    \$userId = " . var_export($userId, true) . ";\n";
				$scriptContent .= "    \$scanType = " . var_export($scanType, true) . ";\n";
				$scriptContent .= "    \$scanMode = " . var_export($scanMode, true) . ";\n";
				$scriptContent .= "    \$tables = " . var_export($tables ?: [], true) . ";\n";
				$scriptContent .= "    \$sampleSize = " . var_export($sampleSize, true) . ";\n";
				$scriptContent .= "    \$scanId = " . var_export($scanId, true) . ";\n";
				$scriptContent .= "    \n";
				$scriptContent .= "    \$job = new \\Spiderwisp\\LaravelOverlord\\Jobs\\ScanDatabaseJob(\$scanId, \$userId, \$scanType, \$scanMode, \$tables, \$sampleSize);\n";
				$scriptContent .= "    \$job->handle(\n";
				$scriptContent .= "        app(\\Spiderwisp\\LaravelOverlord\\Services\\RealAiService::class),\n";
				$scriptContent .= "        app(\\Spiderwisp\\LaravelOverlord\\Services\\DatabaseSchemaService::class)\n";
				$scriptContent .= "    );\n";
				$scriptContent .= "} catch (\\Exception \$e) {\n";
				$scriptContent .= "    // Log errors only (not debug info)\n";
				$scriptContent .= "    if (file_exists(" . var_export($logPath, true) . ")) {\n";
				$scriptContent .= "        file_put_contents(" . var_export($logPath, true) . ", 'Error: ' . \$e->getMessage() . PHP_EOL, FILE_APPEND);\n";
				$scriptContent .= "    }\n";
				$scriptContent .= "    throw \$e;\n";
				$scriptContent .= "}\n";
				$scriptContent .= "unlink(__FILE__);\n";

				file_put_contents($scriptPath, $scriptContent);

				// Execute in background (non-blocking)
				// SECURITY: All arguments are sanitized with escapeshellarg() to prevent command injection
				// PHP_BINARY is a PHP constant (safe), scriptPath and logPath are validated above
				$phpPath = PHP_BINARY;
				if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
					// Windows: Use popen with proper escaping
					$escapedPhpPath = escapeshellarg($phpPath);
					$escapedScriptPath = escapeshellarg($scriptPath);
					pclose(popen("start /B {$escapedPhpPath} {$escapedScriptPath}", "r"));
				} else {
					// Unix/Linux: Use exec with all arguments properly escaped
					$command = sprintf(
						'%s %s >> %s 2>&1 &',
						escapeshellarg($phpPath),
						escapeshellarg($scriptPath),
						escapeshellarg($logPath)
					);
					exec($command);
				}
			}

			return $response;
		} catch (\Exception $e) {
			Log::error('Failed to start database scan', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to start database scan: ' . $e->getMessage(),
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

			$key = "overlord_database_scan_{$scanId}_state";
			$state = Cache::get($key);

			// If not in cache, try to load from database
			if (!$state) {
				$scanHistory = DatabaseScanHistory::where('scan_id', $scanId)->first();
				if (!$scanHistory) {
					return response()->json([
						'success' => false,
						'error' => 'Scan not found',
					], 404);
				}

				// Reconstruct state from database
				$state = [
					'scan_id' => $scanId,
					'user_id' => $scanHistory->user_id,
					'status' => $scanHistory->status,
					'progress' => $scanHistory->status === 'completed' ? 100 : ($scanHistory->status === 'failed' ? 0 : 0),
					'total_tables' => $scanHistory->total_tables ?? 0,
					'processed_tables' => $scanHistory->processed_tables ?? 0,
					'total_batches' => $scanHistory->total_batches ?? 0,
					'processed_batches' => $scanHistory->processed_batches ?? 0,
					'scan_type' => $scanHistory->scan_type,
					'scan_mode' => $scanHistory->scan_mode,
					'selected_tables' => $scanHistory->selected_tables ?? [],
					'sample_size' => $scanHistory->sample_size ?? 100,
					'created_at' => $scanHistory->created_at?->toIso8601String(),
					'started_at' => $scanHistory->started_at?->toIso8601String(),
					'completed_at' => $scanHistory->completed_at?->toIso8601String(),
					'total_issues_found' => $scanHistory->total_issues_found ?? 0,
					'issues_saved' => $scanHistory->issues_saved ?? 0,
					'error' => $scanHistory->error,
				];

				// Re-cache for faster future access
				Cache::put($key, $state, now()->addHours(2));
			}

			return response()->json([
				'success' => true,
				'result' => $state,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get database scan status', [
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

			// Check if scan exists in cache first
			$stateKey = "overlord_database_scan_{$scanId}_state";
			$state = Cache::get($stateKey);

			// If not in cache, try to load from database
			if (!$state) {
				$scanHistory = DatabaseScanHistory::where('scan_id', $scanId)->first();
				if (!$scanHistory) {
					return response()->json([
						'success' => false,
						'error' => 'Scan not found',
					], 404);
				}

				// Reconstruct state from database
				$state = [
					'scan_id' => $scanId,
					'status' => $scanHistory->status,
					'progress' => $scanHistory->status === 'completed' ? 100 : 0,
					'scan_type' => $scanHistory->scan_type,
					'scan_mode' => $scanHistory->scan_mode,
				];
			}

			// Get results from cache
			$resultsKey = "overlord_database_scan_{$scanId}_results";
			$results = Cache::get($resultsKey);

			// If cache is empty but scan is completed, load from database
			if (!$results && ($state['status'] === 'completed' || $state['status'] === 'failed')) {
				$scanHistory = DatabaseScanHistory::where('scan_id', $scanId)->first();
				if ($scanHistory) {
					// PERFORMANCE: Eager load relationships to prevent N+1 queries
					$issues = $scanHistory->issues()->with(['user', 'resolvedBy'])->get();

					// Reconstruct results format from database
					$results = [
						'summary' => [
							'total_tables' => $scanHistory->total_tables ?? 0,
							'total_issues' => $scanHistory->total_issues_found ?? $issues->count(),
							'issues_by_severity' => [
								'critical' => $issues->where('severity', 'critical')->count(),
								'high' => $issues->where('severity', 'high')->count(),
								'medium' => $issues->where('severity', 'medium')->count(),
								'low' => $issues->where('severity', 'low')->count(),
							],
						],
						'issues' => $issues->map(function ($issue) {
							return [
								'table' => $issue->table_name,
								'issue_type' => $issue->issue_type,
								'severity' => $issue->severity,
								'title' => $issue->title,
								'description' => $issue->description,
								'location' => $issue->location,
								'suggestion' => $issue->suggestion,
							];
						})->toArray(),
					];

					// Optionally re-cache the results for faster future access
					Cache::put($resultsKey, $results, now()->addHour());
				}
			}

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
			Log::error('Failed to get database scan results', [
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
			$limit = (int) $request->input('limit', 20);
			$status = $request->input('status'); // Optional filter

			$query = DatabaseScanHistory::query();

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
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get database scan history', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan history: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get specific scan history details
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

			$scanHistory = DatabaseScanHistory::where('scan_id', $scanId);

			if ($userId) {
				$scanHistory->where('user_id', $userId);
			} else {
				$scanHistory->whereNull('user_id');
			}

			$scanHistory = $scanHistory->first();

			if (!$scanHistory) {
				return response()->json([
					'success' => false,
					'error' => 'Scan not found',
				], 404);
			}

			// PERFORMANCE: Eager load relationships to prevent N+1 queries
			$issues = $scanHistory->issues()->with(['user', 'resolvedBy'])->get();

			return response()->json([
				'success' => true,
				'result' => [
					'scan' => $scanHistory,
					'issues' => $issues,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get database scan history details', [
				'scan_id' => $scanId,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan history details: ' . $e->getMessage(),
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
			$scanHistoryId = $request->input('scan_history_id');
			$scanId = $request->input('scan_id');
			$resolved = $request->input('resolved'); // null, true, or false

			$query = DatabaseScanIssue::query();

			if ($userId) {
				$query->where('user_id', $userId);
			} else {
				$query->whereNull('user_id');
			}

			if ($scanHistoryId) {
				$query->where('scan_history_id', $scanHistoryId);
			} elseif ($scanId) {
				// Look up scan history by scan_id
				$scanHistory = DatabaseScanHistory::where('scan_id', $scanId)->first();
				if ($scanHistory) {
					$query->where('scan_history_id', $scanHistory->id);
				} else {
					// Return empty result if scan not found
					return response()->json([
						'success' => true,
						'result' => [],
						'count' => 0,
					]);
				}
			}

			if ($resolved !== null) {
				$query->where('resolved', $resolved === true || $resolved === 'true' || $resolved === 1);
			}

			// SECURITY: Add pagination to prevent unlimited result sets
			$limit = min((int) $request->input('limit', 100), 1000); // Max 1000 items
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
			Log::error('Failed to get database scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get scan issues: ' . $e->getMessage(),
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
			$issue = DatabaseScanIssue::findOrFail($issueId);

			// Check ownership
			if ($userId && $issue->user_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized',
				], 403);
			}

			$issue->markAsResolved($userId);

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to resolve database scan issue', [
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
			$userId = auth()->id();
			$issue = DatabaseScanIssue::findOrFail($issueId);

			// Check ownership
			if ($userId && $issue->user_id !== $userId) {
				return response()->json([
					'success' => false,
					'error' => 'Unauthorized',
				], 403);
			}

			$issue->markAsUnresolved();

			return response()->json([
				'success' => true,
				'result' => $issue,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to unresolve database scan issue', [
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
	 * Clear all issues
	 */
	public function clearIssues(Request $request)
	{
		try {
			$userId = auth()->id();

			// Delete all issues for this user
			if ($userId) {
				$deletedIssues = DatabaseScanIssue::where('user_id', $userId)->delete();

				// Also delete scan history records for this user (since they have no issues now)
				$deletedHistory = DatabaseScanHistory::where('user_id', $userId)->delete();
			} else {
				$deletedIssues = DatabaseScanIssue::whereNull('user_id')->delete();

				// Also delete scan history records with no user_id
				$deletedHistory = DatabaseScanHistory::whereNull('user_id')->delete();
			}

			// Cleared database scan issues and history

			return response()->json([
				'success' => true,
				'message' => 'All issues and scan history cleared',
				'result' => [
					'deleted_issues' => $deletedIssues,
					'deleted_history' => $deletedHistory,
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to clear database scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to clear issues: ' . $e->getMessage(),
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
			$query = DatabaseScanIssue::where('resolved', false);

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
			Log::error('Failed to check existing database scan issues', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to check existing issues: ' . $e->getMessage(),
			], 500);
		}
	}
}