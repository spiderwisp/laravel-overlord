<?php

namespace Spiderwisp\LaravelOverlord\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spiderwisp\LaravelOverlord\Models\ScanIssue;
use Spiderwisp\LaravelOverlord\Models\ScanHistory;
use Spiderwisp\LaravelOverlord\Services\RealAiService;
use Spiderwisp\LaravelOverlord\Enums\AiErrorCode;

class ScanCodebaseJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected string $scanId;
	protected ?int $userId;
	protected string $scanMode;
	protected array $selectedPaths;
	protected int $maxBatchSize = 1; // Process one file at a time for better accuracy
	protected int $maxBatchSizeBytes = 30000; // Reduced to 30KB (~7500 tokens)
	protected int $maxTokensEstimate = 10000; // Max tokens per request (safety margin below 12000 limit)

	/**
	 * Create a new job instance.
	 */
	public function __construct(string $scanId, ?int $userId, string $scanMode = 'full', array $selectedPaths = [])
	{
		$this->scanId = $scanId;
		$this->userId = $userId;
		$this->scanMode = $scanMode;
		$this->selectedPaths = is_array($selectedPaths) ? $selectedPaths : [];
	}

	/**
	 * Execute the job.
	 */
	public function handle(RealAiService $aiService): void
	{
		try {
			// Update scan state to show we're discovering files
			$this->updateScanState([
				'status' => 'discovering',
				'progress' => 0,
				'total_files' => 0,
				'processed_files' => 0,
				'total_batches' => 0,
				'processed_batches' => 0,
				'started_at' => now()->toIso8601String(),
				'message' => $this->scanMode === 'selective'
					? 'Scanning directories for PHP files...'
					: 'Scanning directories for PHP files...',
			]);

			// Discover PHP files in app directory (with optional filtering)
			try {
				$files = $this->discoverFiles();
				$totalFiles = count($files);
			} catch (\Exception $e) {
				$this->updateScanState([
					'status' => 'failed',
					'progress' => 0,
					'error' => 'Failed to discover files: ' . $e->getMessage(),
				]);

				$this->updateScanHistory([
					'status' => 'failed',
					'error' => 'Failed to discover files: ' . $e->getMessage(),
					'completed_at' => now(),
				]);

				return;
			}

			if ($totalFiles === 0) {
				$errorMessage = $this->scanMode === 'selective'
					? 'No PHP files found in selected paths'
					: 'No PHP files found in app directory';

				$this->updateScanState([
					'status' => 'completed',
					'progress' => 100,
					'total_files' => 0,
					'error' => $errorMessage,
				]);

				$this->updateScanHistory([
					'status' => 'completed',
					'total_files' => 0,
					'error' => $errorMessage,
					'completed_at' => now(),
				]);

				return;
			}

			// Update with total files found (progress still 0, but shows we're working)
			$this->updateScanState([
				'total_files' => $totalFiles,
				'status' => 'scanning',
				'progress' => 0,
				'message' => "Found {$totalFiles} files, organizing into batches...",
			]);

			// Update scan history
			$this->updateScanHistory([
				'total_files' => $totalFiles,
			]);

			// Batch files
			$batches = $this->createBatches($files);
			$totalBatches = count($batches);

			$this->updateScanState([
				'total_batches' => $totalBatches,
				'status' => 'scanning',
				'progress' => 0, // Still 0% but we have batches ready
				'message' => "Organized into {$totalBatches} batches, starting analysis...",
			]);

			$allResults = [];
			$processedBatches = 0;

			// Process each batch
			foreach ($batches as $batchIndex => $batch) {
				try {
					// Update status before processing batch
					$this->updateScanState([
						'message' => "Analyzing batch " . ($batchIndex + 1) . " of {$totalBatches}...",
					]);

					// Add a small delay between batches to avoid rate limiting
					// Skip delay for first batch
					if ($batchIndex > 0) {
						usleep(500000); // 0.5 second delay between batches
					}

					$batchResult = $this->analyzeBatch($batch, $aiService);
					$allResults = array_merge($allResults, $batchResult);
					$processedBatches++;

					// Update progress after each batch
					$progress = (int) (($processedBatches / $totalBatches) * 100);
					$processedFilesCount = min($processedBatches * $this->maxBatchSize, $totalFiles);

					$this->updateScanState([
						'processed_batches' => $processedBatches,
						'processed_files' => $processedFilesCount,
						'progress' => $progress,
						'status' => 'scanning', // Ensure status stays as scanning
					]);
				} catch (\Exception $e) {
					$errorMessage = $e->getMessage();

					// Check if this is a rate limit error - if so, fail the entire scan immediately
					$isRateLimit = stripos($errorMessage, 'RATE_LIMIT_EXCEEDED') !== false ||
						stripos($errorMessage, AiErrorCode::RATE_LIMIT_EXCEEDED->value) !== false ||
						stripos($errorMessage, AiErrorCode::QUOTA_EXCEEDED->value) !== false ||
						stripos($errorMessage, 'rate limit') !== false ||
						stripos($errorMessage, 'quota exceeded') !== false;

					if ($isRateLimit) {
						// Extract error message from exception (remove prefix if present)
						$cleanError = str_replace('RATE_LIMIT_EXCEEDED: ', '', $errorMessage);

						// Use the error message directly from SaaS (already formatted)
						// Mark scan as failed with rate limit error
						$this->updateScanState([
							'status' => 'failed',
							'error' => $cleanError,
							'rate_limit_exceeded' => true,
							'failed_at' => now()->toIso8601String(),
						]);

						// Update scan history
						$this->updateScanHistory([
							'status' => 'failed',
							'error' => $cleanError,
							'completed_at' => now(),
						]);

						// Stop processing - don't continue with other batches
						return;
					}

					// Continue with next batch even if one fails (for non-rate-limit errors)
					$allResults[] = [
						'files' => array_column($batch, 'path'),
						'error' => 'Failed to analyze: ' . $errorMessage,
					];

					// Still update progress even on error
					$processedBatches++;
					$progress = (int) (($processedBatches / $totalBatches) * 100);
					$this->updateScanState([
						'processed_batches' => $processedBatches,
						'progress' => $progress,
						'status' => 'scanning',
					]);
				}
			}

			// Compile final results
			$this->updateScanState([
				'message' => 'Compiling scan results...',
			]);
			$compiledResults = $this->compileResults($allResults);

			// Store results in cache (1 hour TTL)
			Cache::put("overlord_scan_{$this->scanId}_results", $compiledResults, now()->addHour());

			// Save issues to database
			$this->updateScanState([
				'message' => 'Saving issues to database...',
			]);
			$savedCount = $this->saveIssuesToDatabase($compiledResults);

			// Mark as completed
			$this->updateScanState([
				'status' => 'completed',
				'progress' => 100,
				'processed_files' => $totalFiles,
				'processed_batches' => $processedBatches,
				'completed_at' => now()->toIso8601String(),
				'total_issues_found' => $compiledResults['summary']['total_issues'] ?? 0,
				'issues_saved' => $savedCount,
			]);

			// Update scan history
			$this->updateScanHistory([
				'status' => 'completed',
				'processed_files' => $totalFiles,
				'processed_batches' => $processedBatches,
				'total_batches' => $processedBatches,
				'total_issues_found' => $compiledResults['summary']['total_issues'] ?? 0,
				'issues_saved' => $savedCount,
				'completed_at' => now(),
			]);

		} catch (\Exception $e) {
			$this->updateScanState([
				'status' => 'failed',
				'error' => $e->getMessage(),
				'failed_at' => now()->toIso8601String(),
			]);

			// Update scan history
			$this->updateScanHistory([
				'status' => 'failed',
				'error' => $e->getMessage(),
				'completed_at' => now(),
			]);
		}
	}

	/**
	 * Discover PHP files in app directory
	 */
	protected function discoverFiles(): array
	{
		$appPath = base_path('app');
		$files = [];

		if (!is_dir($appPath)) {
			return $files;
		}

		// If selective mode with paths, only scan selected paths
		if ($this->scanMode === 'selective' && !empty($this->selectedPaths)) {

			foreach ($this->selectedPaths as $selectedPath) {
				try {
					// Normalize path separators (handle Windows/Unix differences)
					$selectedPath = str_replace('\\', '/', $selectedPath);

					// Remove leading 'app/' if present
					if (strpos($selectedPath, 'app/') === 0) {
						$selectedPath = substr($selectedPath, 4);
					}

					$fullPath = $selectedPath;
					// If path doesn't start with base_path, assume it's relative to app/
					if (strpos($fullPath, base_path()) !== 0) {
						$fullPath = base_path('app/' . ltrim($selectedPath, '/'));
					}

					// Normalize path separators for the full path
					$fullPath = str_replace('\\', '/', $fullPath);

					if (is_file($fullPath) && pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
						// Skip test files
						if (strpos(basename($fullPath), 'Test.php') !== false) {
							continue;
						}

						// Skip files larger than 50KB
						$fileSize = filesize($fullPath);
						if ($fileSize > 50000) {
							continue;
						}

						$files[] = [
							'path' => $fullPath,
							'relative_path' => str_replace(base_path() . '/', '', $fullPath),
							'size' => $fileSize,
						];
					} elseif (is_dir($fullPath)) {
						// Recursively scan directory
						$iterator = new \RecursiveIteratorIterator(
							new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
						);

						foreach ($iterator as $file) {
							if ($file->isFile() && $file->getExtension() === 'php') {
								$filePath = $file->getPathname();

								// Normalize path separators
								$filePath = str_replace('\\', '/', $filePath);

								// Skip vendor, cache, and test files
								if (
									strpos($filePath, '/vendor/') !== false ||
									strpos($filePath, '/cache/') !== false ||
									strpos($filePath, '/tests/') !== false ||
									strpos($filePath, '/Test.php') !== false
								) {
									continue;
								}

								// Skip files larger than 50KB
								$fileSize = $file->getSize();
								if ($fileSize > 50000) {
									continue;
								}

								$files[] = [
									'path' => $filePath,
									'relative_path' => str_replace(base_path() . '/', '', $filePath),
									'size' => $fileSize,
								];
							}
						}
					} else {
						Log::warning('Selected path not found or not a file/directory', [
							'selected_path' => $selectedPath,
							'resolved_path' => $fullPath,
						]);
					}
				} catch (\Exception $e) {
					Log::error('Error processing selected path', [
						'selected_path' => $selectedPath,
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);
				}
			}

		} else {
			// Full scan mode - scan entire app directory
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($appPath, \RecursiveDirectoryIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if ($file->isFile() && $file->getExtension() === 'php') {
					$filePath = $file->getPathname();

					// Skip vendor, cache, and test files
					if (
						strpos($filePath, '/vendor/') !== false ||
						strpos($filePath, '/cache/') !== false ||
						strpos($filePath, '/tests/') !== false ||
						strpos($filePath, '/Test.php') !== false
					) {
						continue;
					}

					$files[] = [
						'path' => $filePath,
						'relative_path' => str_replace(base_path() . '/', '', $filePath),
						'size' => $file->getSize(),
					];
				}
			}
		}

		return $files;
	}

	/**
	 * Create batches from files
	 */
	protected function createBatches(array $files): array
	{
		$batches = [];
		$currentBatch = [];
		$currentBatchSize = 0;
		$currentTokenEstimate = 0;

		foreach ($files as $file) {
			// Estimate tokens: roughly 1 token per 4 characters
			$fileTokenEstimate = (int) ($file['size'] / 4);

			// Check if adding this file would exceed limits
			if (
				count($currentBatch) >= $this->maxBatchSize ||
				($currentBatchSize + $file['size']) > $this->maxBatchSizeBytes ||
				($currentTokenEstimate + $fileTokenEstimate) > $this->maxTokensEstimate
			) {
				// Start a new batch
				if (!empty($currentBatch)) {
					$batches[] = $currentBatch;
				}
				$currentBatch = [];
				$currentBatchSize = 0;
				$currentTokenEstimate = 0;
			}

			$currentBatch[] = $file;
			$currentBatchSize += $file['size'];
			$currentTokenEstimate += $fileTokenEstimate;
		}

		// Add the last batch if it has files
		if (!empty($currentBatch)) {
			$batches[] = $currentBatch;
		}

		return $batches;
	}

	/**
	 * Analyze a batch of files using AI
	 */
	protected function analyzeBatch(array $batch, RealAiService $aiService): array
	{
		$results = [];

		// Update status for reading files
		$this->updateScanState([
			'message' => 'Reading files in batch...',
		]);

		// Read file contents
		$fileContents = [];
		foreach ($batch as $file) {
			try {
				// Skip files larger than 50KB individually
				if ($file['size'] > 50000) {
					Log::warning('Skipping large file', [
						'file' => $file['path'],
						'size' => $file['size'],
					]);
					$results[] = [
						'file' => $file['relative_path'],
						'analysis' => 'File too large to analyze (>50KB)',
						'error' => true,
					];
					continue;
				}

				$content = file_get_contents($file['path']);
				if ($content !== false) {
					$fileContents[] = [
						'path' => $file['relative_path'],
						'content' => $content,
					];
				}
			} catch (\Exception $e) {
				Log::warning('Failed to read file', [
					'file' => $file['path'],
					'error' => $e->getMessage(),
				]);
			}
		}

		if (empty($fileContents)) {
			return $results;
		}

		// Build structured data for AI analysis (not a formatted prompt)
		$analysisData = $this->buildAnalysisData($fileContents);

		// Estimate data size (rough: 1 token per 4 chars)
		$dataJson = json_encode($analysisData);
		$dataTokens = (int) (strlen($dataJson) / 4);

		// If data is too large, split the batch further
		if ($dataTokens > $this->maxTokensEstimate) {
			Log::warning('Batch too large, splitting further', [
				'estimated_tokens' => $dataTokens,
				'files' => count($fileContents),
			]);

			$this->updateScanState([
				'message' => 'Batch too large, splitting for better analysis...',
			]);

			// Split in half and process separately
			$midPoint = (int) (count($fileContents) / 2);
			$firstHalf = array_slice($fileContents, 0, $midPoint);
			$secondHalf = array_slice($fileContents, $midPoint);

			$firstResults = $this->analyzeBatchContents($firstHalf, $aiService);
			$secondResults = $this->analyzeBatchContents($secondHalf, $aiService);

			return array_merge($firstResults, $secondResults);
		}

		return $this->analyzeBatchContents($fileContents, $aiService);
	}

	/**
	 * Analyze batch contents with retry logic for errors
	 */
	protected function analyzeBatchContents(array $fileContents, RealAiService $aiService): array
	{
		$results = [];
		$maxRetries = 3; // Increased retries for transient errors
		$retryCount = 0;

		while ($retryCount <= $maxRetries) {
			try {
				// Build structured data for AI analysis
				$analysisData = $this->buildAnalysisData($fileContents);

				// Create a simple message - SAAS will build the full prompt from instructions + data
				$message = "Analyze the provided code files for bugs, errors, security issues, code quality problems, and potential improvements.";

				// Update status before AI call
				if ($retryCount === 0) {
					$this->updateScanState([
						'message' => 'Thinking...',
					]);
				} else {
					$this->updateScanState([
						'message' => 'Handling error, retrying...',
					]);
				}

				// Send to AI service with context_type and structured data
				// The data will be passed through to SAAS which will build the prompt
				$aiResponse = $aiService->chat($message, [], null, null, 'codebase_scan', $analysisData);

				// Update status when parsing response
				$this->updateScanState([
					'message' => 'Parsing AI response...',
				]);

				if ($aiResponse['success'] && isset($aiResponse['message'])) {
					// Parse AI response and associate with files
					foreach ($fileContents as $fileData) {
						$results[] = [
							'file' => $fileData['path'],
							'analysis' => $aiResponse['message'],
							'raw_response' => $aiResponse['message'],
						];
					}
					return $results; // Success, return results
				} else {
					// AI failed - get error message
					$error = $aiResponse['error'] ?? 'Unknown error';

					// Check if it's a size-related error that we can fix by splitting
					$isSizeError = stripos($error, '413') !== false ||
						stripos($error, 'too large') !== false ||
						stripos($error, 'Request too large') !== false ||
						stripos($error, 'TPM') !== false;

					if ($isSizeError && $retryCount < $maxRetries && count($fileContents) > 1) {
						// Split batch and retry
						$this->updateScanState([
							'message' => 'Batch too large, splitting for better analysis...',
						]);

						// Batch too large, splitting and retrying

						$midPoint = (int) (count($fileContents) / 2);
						$firstHalf = array_slice($fileContents, 0, $midPoint);
						$secondHalf = array_slice($fileContents, $midPoint);

						$firstResults = $this->analyzeBatchContents($firstHalf, $aiService);
						$secondResults = $this->analyzeBatchContents($secondHalf, $aiService);

						return array_merge($firstResults, $secondResults);
					}

					// Check if it's a rate limit error using Enum values
					$errorCode = $aiResponse['code'] ?? null;
					$isRateLimit = $errorCode === AiErrorCode::RATE_LIMIT_EXCEEDED->value ||
						$errorCode === AiErrorCode::QUOTA_EXCEEDED->value ||
						stripos($error, 'rate limit') !== false ||
						stripos($error, '429') !== false ||
						stripos($error, 'too many requests') !== false ||
						stripos($error, 'quota exceeded') !== false;

					// For rate limits, throw exception to stop scan immediately (don't retry)
					if ($isRateLimit) {
						Log::error('AI analysis rate limited - stopping scan', [
							'error' => $error,
							'code' => $errorCode,
							'files' => count($fileContents),
						]);

						// Throw exception with the error message from SaaS (which should already be formatted)
						throw new \Exception('RATE_LIMIT_EXCEEDED: ' . $error);
					}

					// For other errors (transient failures), retry with delay
					if ($retryCount < $maxRetries) {
						$delaySeconds = ($retryCount + 1) * 2; // Exponential backoff: 2s, 4s, 6s
						Log::warning('AI analysis failed, retrying after delay', [
							'error' => $error,
							'code' => $aiResponse['code'] ?? null,
							'retry' => $retryCount + 1,
							'max_retries' => $maxRetries,
							'delay_seconds' => $delaySeconds,
							'files' => count($fileContents),
						]);

						// Wait before retrying (exponential backoff)
						sleep($delaySeconds);
						$retryCount++;
						continue; // Retry the same batch
					}

					// All retries exhausted - record failure
					Log::error('AI analysis failed after all retries', [
						'error' => $error,
						'retry_count' => $retryCount,
						'files' => count($fileContents),
					]);

					foreach ($fileContents as $fileData) {
						$results[] = [
							'file' => $fileData['path'],
							'analysis' => 'AI analysis failed: ' . $error,
							'error' => true,
						];
					}
					return $results;
				}
			} catch (\Exception $e) {
				$errorMessage = $e->getMessage();

				// Check if it's a rate limit error - re-throw immediately (don't retry)
				$isRateLimit = stripos($errorMessage, 'RATE_LIMIT_EXCEEDED') !== false ||
					stripos($errorMessage, AiErrorCode::RATE_LIMIT_EXCEEDED->value) !== false ||
					stripos($errorMessage, AiErrorCode::QUOTA_EXCEEDED->value) !== false ||
					stripos($errorMessage, 'rate limit') !== false ||
					stripos($errorMessage, 'quota exceeded') !== false;

				if ($isRateLimit) {
					// Re-throw rate limit exceptions immediately - don't retry
					throw $e;
				}

				// Check if it's a size error that we can fix by splitting
				$isSizeError = stripos($errorMessage, '413') !== false ||
					stripos($errorMessage, 'too large') !== false ||
					stripos($errorMessage, 'Request too large') !== false ||
					stripos($errorMessage, 'TPM') !== false;

				if ($isSizeError && $retryCount < $maxRetries && count($fileContents) > 1) {
					// Split batch and retry

					$midPoint = (int) (count($fileContents) / 2);
					$firstHalf = array_slice($fileContents, 0, $midPoint);
					$secondHalf = array_slice($fileContents, $midPoint);

					$firstResults = $this->analyzeBatchContents($firstHalf, $aiService);
					$secondResults = $this->analyzeBatchContents($secondHalf, $aiService);

					return array_merge($firstResults, $secondResults);
				}

				// For other exceptions, retry with delay
				if ($retryCount < $maxRetries) {
					$delaySeconds = ($retryCount + 1) * 2; // Exponential backoff
					Log::warning('AI analysis exception, retrying after delay', [
						'error' => $errorMessage,
						'retry' => $retryCount + 1,
						'max_retries' => $maxRetries,
						'delay_seconds' => $delaySeconds,
						'files' => count($fileContents),
					]);

					sleep($delaySeconds);
					$retryCount++;
					continue; // Retry the same batch
				}

				// All retries exhausted
				Log::error('AI analysis exception after all retries', [
					'error' => $errorMessage,
					'retry_count' => $retryCount,
					'files' => count($fileContents),
				]);

				foreach ($fileContents as $fileData) {
					$results[] = [
						'file' => $fileData['path'],
						'analysis' => 'AI analysis error: ' . $errorMessage,
						'error' => true,
					];
				}
				return $results;
			}
		}

		// Should never reach here, but return empty results if we do
		return $results;
	}

	/**
	 * Format code with line numbers for AI analysis
	 */
	protected function formatCodeWithLineNumbers(string $content): string
	{
		$lines = explode("\n", $content);
		$formatted = [];

		foreach ($lines as $index => $line) {
			$lineNumber = $index + 1;
			$formatted[] = "{$lineNumber}| {$line}";
		}

		return implode("\n", $formatted);
	}

	/**
	 * Build structured analysis data for AI (not a formatted prompt)
	 * SAAS will use this data with instructions to build the actual prompt
	 */
	protected function buildAnalysisData(array $fileContents): array
	{
		$files = [];

		foreach ($fileContents as $fileData) {
			$files[] = [
				'path' => $fileData['path'],
				'content' => $fileData['content'],
				'content_with_line_numbers' => $this->formatCodeWithLineNumbers($fileData['content']),
			];
		}

		return [
			'files' => $files,
			'requirements' => [
				'output_format' => 'json',
				'fields' => [
					'line' => 'Line number where the issue is found (must match line number format in code)',
					'file_path' => 'The file path where the issue was found',
					'type' => 'Issue type: security_vulnerability, bug, code_quality, performance, best_practice',
					'severity' => 'Issue severity: critical, high, medium, low',
					'category' => 'Category: security, quality, performance, best_practices, bug',
					'message' => 'Detailed description of the issue (supports markdown)',
					'code_snippet' => 'The actual code at the problematic line(s) exactly as it appears in the file',
				],
				'categories' => [
					'security' => 'Security vulnerabilities, injection risks, authentication issues',
					'quality' => 'Code quality issues, maintainability, complexity',
					'performance' => 'Performance bottlenecks, inefficient code',
					'best_practices' => 'PSR standards, Laravel conventions, best practices',
					'bug' => 'Actual bugs, errors, incorrect logic',
				],
				'accuracy_requirements' => [
					'line_numbers_must_be_accurate' => true,
					'code_snippets_must_match_actual_code' => true,
					'only_report_real_actionable_issues' => true,
					'verify_code_exists_at_line_number' => true,
				],
			],
		];
	}

	/**
	 * Compile results into structured format
	 */
	protected function compileResults(array $allResults): array
	{
		$compiled = [
			'files' => [],
			'summary' => [
				'total_files' => 0,
				'files_with_issues' => 0,
				'total_issues' => 0,
				'by_severity' => [
					'critical' => 0,
					'high' => 0,
					'medium' => 0,
					'low' => 0,
				],
			],
		];

		$filesProcessed = [];

		foreach ($allResults as $result) {
			if (!isset($result['file'])) {
				continue;
			}

			$filePath = $result['file'];

			// Skip parsing if this result indicates an error
			if (isset($result['error']) && $result['error'] === true) {
				Log::warning('Skipping failed AI analysis result', [
					'file' => $filePath,
					'error_message' => $result['analysis'] ?? 'Unknown error',
				]);
				continue;
			}

			// Skip if analysis is an error message
			$analysis = $result['analysis'] ?? '';
			if (
				stripos($analysis, 'AI analysis failed:') === 0 ||
				stripos($analysis, 'AI analysis error:') === 0 ||
				stripos($analysis, 'Failed to analyze:') === 0
			) {
				Log::warning('Skipping error analysis result', [
					'file' => $filePath,
					'analysis_preview' => substr($analysis, 0, 100),
				]);
				continue;
			}

			// Parse issues from analysis
			$issues = $this->parseIssuesFromAnalysis($analysis, $filePath);

			if (!isset($filesProcessed[$filePath])) {
				$filesProcessed[$filePath] = [
					'file' => $filePath,
					'issues' => [],
					'has_errors' => false,
				];
			}

			$filesProcessed[$filePath]['issues'] = array_merge(
				$filesProcessed[$filePath]['issues'],
				$issues
			);
		}

		// Convert to array and calculate summary
		foreach ($filesProcessed as $fileData) {
			$compiled['files'][] = $fileData;
			$compiled['summary']['total_files']++;

			if (count($fileData['issues']) > 0 || $fileData['has_errors']) {
				$compiled['summary']['files_with_issues']++;
			}

			foreach ($fileData['issues'] as $issue) {
				$compiled['summary']['total_issues']++;
				$severity = strtolower($issue['severity'] ?? 'medium');
				if (isset($compiled['summary']['by_severity'][$severity])) {
					$compiled['summary']['by_severity'][$severity]++;
				} else {
					$compiled['summary']['by_severity']['medium']++;
				}
			}
		}

		return $compiled;
	}

	/**
	 * Extract balanced JSON from text (handles nested brackets/braces)
	 */
	protected function extractBalancedJson(string $text, string $startChar): ?string
	{
		$endChar = $startChar === '[' ? ']' : '}';
		$startPos = strpos($text, $startChar);

		if ($startPos === false) {
			return null;
		}

		$depth = 0;
		$inString = false;
		$escapeNext = false;
		$length = strlen($text);

		for ($i = $startPos; $i < $length; $i++) {
			$char = $text[$i];

			if ($escapeNext) {
				$escapeNext = false;
				continue;
			}

			if ($char === '\\') {
				$escapeNext = true;
				continue;
			}

			if ($char === '"' && !$escapeNext) {
				$inString = !$inString;
				continue;
			}

			if ($inString) {
				continue;
			}

			if ($char === $startChar) {
				$depth++;
			} elseif ($char === $endChar) {
				$depth--;
				if ($depth === 0) {
					// Found balanced JSON
					$jsonCandidate = substr($text, $startPos, $i - $startPos + 1);
					// Validate it's proper JSON
					json_decode($jsonCandidate);
					if (json_last_error() === JSON_ERROR_NONE) {
						return trim($jsonCandidate);
					}
					// If not valid, try to find the next occurrence
					$nextStart = strpos($text, $startChar, $i + 1);
					if ($nextStart !== false) {
						return $this->extractBalancedJson(substr($text, $nextStart), $startChar);
					}
					return null;
				}
			}
		}

		return null;
	}

	/**
	 * Normalize field names from AI response and correct line numbers
	 * 
	 * @param array $item The issue item from AI response
	 * @param string|null $filePath Optional file path to search for correct line numbers
	 * @return array Normalized issue with corrected line numbers
	 */
	protected function normalizeIssueFields(array $item, ?string $filePath = null): array
	{
		// Handle field name variations
		$normalized = $item;

		// Handle code_snippet variations - ensure it's always a string
		$codeSnippet = null;
		if (isset($item['code_snippet'])) {
			$codeSnippet = $item['code_snippet'];
		} elseif (isset($item['codesnippet'])) {
			$codeSnippet = $item['codesnippet'];
		} elseif (isset($item['codeSnippet'])) {
			$codeSnippet = $item['codeSnippet'];
		} elseif (isset($item['code'])) {
			$codeSnippet = $item['code'];
		}

		if ($codeSnippet !== null) {
			// Convert array to string if needed
			if (is_array($codeSnippet)) {
				// Flatten nested arrays and convert all elements to strings
				$flattened = [];
				array_walk_recursive($codeSnippet, function ($value) use (&$flattened) {
					$flattened[] = (string) $value;
				});
				$normalized['code_snippet'] = implode("\n", $flattened);
			} else {
				$normalized['code_snippet'] = (string) $codeSnippet;
			}
		}

		// Correct line number if we have code snippet and file path
		if (!empty($normalized['code_snippet']) && $filePath !== null) {
			$reportedLine = isset($normalized['line']) ? (int) $normalized['line'] : null;
			$correctedLine = null;

			// First, try to extract line number from code snippet prefix
			$snippetLine = $this->extractLineNumberFromSnippet($normalized['code_snippet']);

			if ($snippetLine !== null) {
				// Use line number from snippet if it exists
				$correctedLine = $snippetLine;

				// If reported line differs from snippet line, use the snippet line
				if ($reportedLine !== null && $reportedLine !== $snippetLine) {
					// Line number corrected from code snippet
				}
			} elseif ($reportedLine !== null) {
				// If no line number in snippet, try to find it in the file
				$foundLine = $this->findLineNumberInFile($filePath, $normalized['code_snippet'], $reportedLine);

				if ($foundLine !== null && $foundLine !== $reportedLine) {
					$correctedLine = $foundLine;
				} else {
					// Keep reported line if we can't find it or it matches
					$correctedLine = $reportedLine;
				}
			}

			// Update the line number if we corrected it
			if ($correctedLine !== null) {
				$normalized['line'] = $correctedLine;
			}
		}

		// Handle message/description variations
		if (isset($item['description']) && !isset($item['message'])) {
			$normalized['message'] = $item['description'];
		}

		return $normalized;
	}

	/**
	 * Ensure code_snippet is always a string (not an array)
	 */
	protected function normalizeCodeSnippet($codeSnippet): ?string
	{
		if ($codeSnippet === null) {
			return null;
		}

		if (is_array($codeSnippet)) {
			// Flatten nested arrays and convert all elements to strings
			$flattened = [];
			array_walk_recursive($codeSnippet, function ($value) use (&$flattened) {
				$flattened[] = (string) $value;
			});
			return implode("\n", $flattened);
		}

		return (string) $codeSnippet;
	}

	/**
	 * Extract line number from code snippet if it contains "LINE|" prefix
	 * 
	 * @param string $codeSnippet The code snippet that may contain line number prefix
	 * @return int|null The extracted line number, or null if not found
	 */
	protected function extractLineNumberFromSnippet(string $codeSnippet): ?int
	{
		// Check if snippet starts with "LINE|" format (e.g., "16| return $user->watchlists()->create($data);")
		if (preg_match('/^(\d+)\|\s*/', trim($codeSnippet), $matches)) {
			$lineNumber = (int) $matches[1];
			if ($lineNumber > 0) {
				return $lineNumber;
			}
		}

		return null;
	}

	/**
	 * Find the line number in a file where a code snippet appears
	 * 
	 * @param string $filePath The path to the file to search
	 * @param string $codeSnippet The code snippet to search for
	 * @param int|null $hintLine Optional hint line number to search near first (within ±5 lines)
	 * @return int|null The line number where found, or null if not found
	 */
	protected function findLineNumberInFile(string $filePath, string $codeSnippet, ?int $hintLine = null): ?int
	{
		// Get the actual file path (resolve relative path)
		$fullPath = $filePath;
		if (strpos($fullPath, base_path()) !== 0) {
			$fullPath = base_path($filePath);
		}

		// Check if file exists
		if (!file_exists($fullPath)) {
			return null;
		}

		// Read file content
		$fileContent = file_get_contents($fullPath);
		if ($fileContent === false) {
			return null;
		}

		$fileLines = explode("\n", $fileContent);
		$totalLines = count($fileLines);

		// Clean the code snippet - remove line number prefixes and trim
		$codeSnippetClean = preg_replace('/^\d+\|\s*/m', '', $codeSnippet);
		$codeSnippetClean = trim($codeSnippetClean);

		// If snippet is empty after cleaning, return null
		if (empty($codeSnippetClean)) {
			return null;
		}

		// If we have a hint line, search near it first (within ±5 lines)
		if ($hintLine !== null && $hintLine > 0 && $hintLine <= $totalLines) {
			$startLine = max(0, $hintLine - 6); // Start 6 lines before (to account for 0-index)
			$endLine = min($totalLines - 1, $hintLine + 4); // End 4 lines after

			for ($i = $startLine; $i <= $endLine; $i++) {
				$lineContent = trim($fileLines[$i]);

				// Check exact match
				if ($lineContent === $codeSnippetClean) {
					return $i + 1; // Return 1-based line number
				}

				// Check if line contains the snippet (for partial matches)
				if (strpos($lineContent, $codeSnippetClean) !== false) {
					return $i + 1;
				}

				// Check if snippet contains the line (for multi-line snippets)
				if (strpos($codeSnippetClean, $lineContent) !== false) {
					return $i + 1;
				}
			}
		}

		// Search entire file if hint didn't work or no hint provided
		for ($i = 0; $i < $totalLines; $i++) {
			$lineContent = trim($fileLines[$i]);

			// Check exact match
			if ($lineContent === $codeSnippetClean) {
				return $i + 1; // Return 1-based line number
			}

			// Check if line contains the snippet (for partial matches)
			if (strpos($lineContent, $codeSnippetClean) !== false) {
				return $i + 1;
			}

			// Check if snippet contains the line (for multi-line snippets)
			if (strpos($codeSnippetClean, $lineContent) !== false) {
				return $i + 1;
			}
		}

		// Not found
		return null;
	}

	/**
	 * Validate and correct an issue's line number and code snippet to match the actual file
	 * 
	 * @param array $issue The issue to validate
	 * @param string $filePath The file path to validate against
	 * @return array|null Returns corrected issue array if valid, null if should be rejected
	 */
	protected function validateIssue(array $issue, string $filePath): ?array
	{
		$correctedIssue = $issue;

		// If no line number, skip validation (but still allow the issue)
		if (!isset($issue['line']) || $issue['line'] === null) {
			return $correctedIssue;
		}

		$lineNumber = (int) $issue['line'];

		// Get the actual file path (resolve relative path)
		$fullPath = $filePath;
		if (strpos($fullPath, base_path()) !== 0) {
			$fullPath = base_path($filePath);
		}

		// Check if file exists
		if (!file_exists($fullPath)) {
			Log::warning('Cannot validate issue - file not found', [
				'file' => $filePath,
				'full_path' => $fullPath,
				'line' => $lineNumber,
			]);
			return $correctedIssue; // Don't filter out if we can't validate
		}

		// Read file content
		$fileContent = file_get_contents($fullPath);
		if ($fileContent === false) {
			Log::warning('Cannot validate issue - cannot read file', [
				'file' => $filePath,
				'line' => $lineNumber,
			]);
			return $correctedIssue; // Don't filter out if we can't validate
		}

		$fileLines = explode("\n", $fileContent);
		$totalLines = count($fileLines);

		// Validate line number is within bounds
		if ($lineNumber < 1 || $lineNumber > $totalLines) {
			// Try to find the code snippet in the file if we have one
			if (isset($issue['code_snippet']) && !empty($issue['code_snippet'])) {
				$foundLine = $this->findLineNumberInFile($filePath, $issue['code_snippet']);
				if ($foundLine !== null) {
					$correctedIssue['line'] = $foundLine;
					return $correctedIssue;
				}
			}

			Log::warning('Issue validation failed - line number out of bounds and cannot find code snippet', [
				'file' => $filePath,
				'line' => $lineNumber,
				'total_lines' => $totalLines,
			]);
			return null; // Reject if we can't find it
		}

		// If code snippet is provided, validate it matches
		if (isset($issue['code_snippet']) && !empty($issue['code_snippet'])) {
			// Handle case where code_snippet might be an array (convert to string)
			$codeSnippetRaw = $issue['code_snippet'];
			if (is_array($codeSnippetRaw)) {
				$codeSnippet = trim(implode("\n", $codeSnippetRaw));
			} else {
				$codeSnippet = trim((string) $codeSnippetRaw);
			}

			$actualLine = trim($fileLines[$lineNumber - 1]);

			// Check if code snippet matches the actual line (allowing for some flexibility)
			// Remove line number prefixes if present (from formatted code)
			$codeSnippetClean = preg_replace('/^\d+\|\s*/', '', $codeSnippet);
			$codeSnippetClean = trim($codeSnippetClean);

			// Check exact match first
			if ($codeSnippetClean === $actualLine) {
				return $correctedIssue;
			}

			// Check if snippet contains the actual line (for multi-line snippets)
			if (strpos($codeSnippetClean, $actualLine) !== false) {
				return $correctedIssue;
			}

			// Check if actual line contains the snippet (for partial matches)
			if (strpos($actualLine, $codeSnippetClean) !== false) {
				return $correctedIssue;
			}

			// Check nearby lines (within 2 lines) for context
			$startLine = max(0, $lineNumber - 3);
			$endLine = min($totalLines - 1, $lineNumber + 1);
			$contextLines = array_slice($fileLines, $startLine, $endLine - $startLine + 1);
			$contextText = implode("\n", $contextLines);

			if (strpos($contextText, $codeSnippetClean) !== false) {
				return $correctedIssue;
			}

			// Try to find the code snippet elsewhere in the file
			$foundLine = $this->findLineNumberInFile($filePath, $codeSnippet, $lineNumber);
			if ($foundLine !== null) {
				$correctedIssue['line'] = $foundLine;
				return $correctedIssue;
			}

			Log::warning('Issue validation failed - code snippet does not match and cannot be found in file', [
				'file' => $filePath,
				'line' => $lineNumber,
				'expected_snippet' => $codeSnippetClean,
				'actual_line' => $actualLine,
			]);
			return null; // Reject if we can't find it
		}

		// If no code snippet, just validate line number (which we already did)
		return $correctedIssue;
	}

	/**
	 * Parse issues from AI analysis text
	 */
	protected function parseIssuesFromAnalysis(string $analysis, string $filePath): array
	{
		$issues = [];

		// Try multiple strategies to extract JSON
		$jsonString = null;
		$extractionMethod = null;

		// Strategy 1: Extract from markdown code blocks (```json ... ``` or ``` ... ```)
		if (preg_match('/```(?:json)?\s*(\[[\s\S]*?\])\s*```/', $analysis, $matches)) {
			$jsonString = trim($matches[1]);
			$extractionMethod = 'markdown_array';
		} elseif (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $analysis, $matches)) {
			// Single object in markdown, keep as-is (will be handled later)
			$jsonString = trim($matches[1]);
			$extractionMethod = 'markdown_object';
		}

		// Strategy 2: Extract JSON array from plain text (balanced brackets)
		// Use a function to find balanced brackets/braces
		if (!$jsonString) {
			$jsonString = $this->extractBalancedJson($analysis, '[');
			if ($jsonString) {
				$extractionMethod = 'balanced_array';
			}
		}

		// Strategy 3: Extract JSON object from plain text (balanced braces)
		if (!$jsonString) {
			$jsonString = $this->extractBalancedJson($analysis, '{');
			if ($jsonString) {
				$extractionMethod = 'balanced_object';
			}
		}

		// Strategy 4: Check if entire response is JSON (starts with [ or {)
		if (!$jsonString) {
			$trimmed = trim($analysis);
			if (
				(strpos($trimmed, '[') === 0 && substr($trimmed, -1) === ']') ||
				(strpos($trimmed, '{') === 0 && substr($trimmed, -1) === '}')
			) {
				$jsonString = $trimmed;
				$extractionMethod = 'direct';
			}
		}

		// If we extracted a single object from markdown, we'll handle it as a single issue
		$isSingleObject = ($extractionMethod === 'markdown_object' || $extractionMethod === 'plain_object' || $extractionMethod === 'balanced_object');

		// Check if this looks like JSON
		$isJsonLike = ($jsonString !== null || strpos(trim($analysis), '{') === 0 || strpos(trim($analysis), '[') === 0);

		// Use extracted JSON or fall back to trimmed analysis
		$jsonString = $jsonString ?? trim($analysis);
		$jsonParsedSuccessfully = false;

		if ($isJsonLike) {
			// Try to parse as JSON first
			$jsonData = json_decode($jsonString, true);
			$jsonError = json_last_error();

			if ($jsonError === JSON_ERROR_NONE && $jsonData !== null) {
				$jsonParsedSuccessfully = true;

				// Handle both array of issues and single issue object
				if (is_array($jsonData)) {
					// Check if it's an empty array
					if (empty($jsonData)) {
						return [];
					}

					// Check if it's a numeric array (JSON array) or associative array (JSON object)
					// A numeric array will have keys [0, 1, 2, ...], an object will have string keys
					$keys = array_keys($jsonData);
					$isNumericArray = !empty($keys) && $keys === range(0, count($jsonData) - 1);

					if ($isNumericArray) {
						// It's an array of issues
						foreach ($jsonData as $item) {
							if (is_array($item) && (isset($item['message']) || isset($item['description']) || isset($item['type']))) {
								// Normalize field names and correct line numbers
								$item = $this->normalizeIssueFields($item, $filePath);

								// Normalize category values
								$category = $item['category'] ?? null;
								if ($category) {
									$category = strtolower($category);
									// Map variations to standard categories
									$categoryMap = [
										'security' => 'security',
										'quality' => 'quality',
										'code_quality' => 'quality',
										'performance' => 'performance',
										'best_practices' => 'best_practices',
										'best_practice' => 'best_practices',
										'bug' => 'bug',
										'bugs' => 'bug',
									];
									$category = $categoryMap[$category] ?? $category;
								}

								$issue = [
									'line' => isset($item['line']) ? (int) $item['line'] : null,
									'message' => $item['message'] ?? $item['description'] ?? json_encode($item),
									'severity' => $this->normalizeSeverity($item['severity'] ?? $this->detectSeverity($item['message'] ?? '')),
									'type' => $item['type'] ?? $this->detectIssueType($item['message'] ?? ''),
									'category' => $category,
									'code_snippet' => $this->normalizeCodeSnippet($item['code_snippet'] ?? null),
								];

								// Validate and correct issue before adding
								$correctedIssue = $this->validateIssue($issue, $filePath);
								if ($correctedIssue !== null) {
									$issues[] = $correctedIssue;
								}
							}
						}
					} else {
						// Single issue object (associative array), treat as single issue
						// Check if it looks like an issue object (has message, description, or type)
						if (isset($jsonData['message']) || isset($jsonData['description']) || isset($jsonData['type'])) {
							// Normalize field names and correct line numbers
							$item = $this->normalizeIssueFields($jsonData, $filePath);

							// Normalize category values
							$category = $item['category'] ?? null;
							if ($category) {
								$category = strtolower($category);
								// Map variations to standard categories
								$categoryMap = [
									'security' => 'security',
									'quality' => 'quality',
									'code_quality' => 'quality',
									'performance' => 'performance',
									'best_practices' => 'best_practices',
									'best_practice' => 'best_practices',
									'bug' => 'bug',
									'bugs' => 'bug',
								];
								$category = $categoryMap[$category] ?? $category;
							}

							$issue = [
								'line' => isset($item['line']) ? (int) $item['line'] : null,
								'message' => $item['message'] ?? $item['description'] ?? json_encode($item),
								'severity' => $this->normalizeSeverity($item['severity'] ?? $this->detectSeverity($item['message'] ?? '')),
								'type' => $item['type'] ?? $this->detectIssueType($item['message'] ?? ''),
								'category' => $category,
								'code_snippet' => $this->normalizeCodeSnippet($item['code_snippet'] ?? null),
							];

							// Validate and correct issue before adding
							$correctedIssue = $this->validateIssue($issue, $filePath);
							if ($correctedIssue !== null) {
								$issues[] = $correctedIssue;
							}
						} else {
							Log::warning('JSON object does not look like an issue', [
								'file' => $filePath,
								'keys' => array_keys($jsonData),
							]);
						}
					}
				}

				if (!empty($issues)) {
					return $issues;
				}
			} else {
				Log::warning('Failed to parse JSON', [
					'file' => $filePath,
					'json_error' => json_last_error_msg(),
					'extraction_method' => $extractionMethod,
					'json_preview' => substr($jsonString, 0, 300),
				]);

				// JSON-like but not valid - try to extract JSON from text with balanced extraction
				$retryJsonString = $this->extractBalancedJson($analysis, '[');
				if (!$retryJsonString) {
					$retryJsonString = $this->extractBalancedJson($analysis, '{');
				}

				if ($retryJsonString) {
					$jsonData = json_decode($retryJsonString, true);
					if (json_last_error() === JSON_ERROR_NONE && $jsonData !== null && is_array($jsonData)) {
						$jsonParsedSuccessfully = true;
						// Handle both array and single object
						if (empty($jsonData)) {
							return [];
						}

						$keys = array_keys($jsonData);
						$isNumericArray = $keys === range(0, count($jsonData) - 1);

						if ($isNumericArray) {
							// Array of issues
							foreach ($jsonData as $item) {
								if (is_array($item) && (isset($item['message']) || isset($item['description']) || isset($item['type']))) {
									// Normalize field names and correct line numbers
									$item = $this->normalizeIssueFields($item, $filePath);

									$category = isset($item['category']) ? strtolower($item['category']) : null;
									$issue = [
										'line' => isset($item['line']) ? (int) $item['line'] : null,
										'message' => $item['message'] ?? $item['description'] ?? json_encode($item),
										'severity' => $this->normalizeSeverity($item['severity'] ?? $this->detectSeverity($item['message'] ?? '')),
										'type' => $item['type'] ?? $this->detectIssueType($item['message'] ?? ''),
										'category' => $category,
										'code_snippet' => $this->normalizeCodeSnippet($item['code_snippet'] ?? null),
									];

									// Validate and correct issue before adding
									$correctedIssue = $this->validateIssue($issue, $filePath);
									if ($correctedIssue !== null) {
										$issues[] = $correctedIssue;
									}
								}
							}
						} else {
							// Single issue object - check if it looks like an issue
							if (isset($jsonData['message']) || isset($jsonData['description']) || isset($jsonData['type'])) {
								// Normalize field names and correct line numbers
								$item = $this->normalizeIssueFields($jsonData, $filePath);

								$category = isset($item['category']) ? strtolower($item['category']) : null;
								$issue = [
									'line' => isset($item['line']) ? (int) $item['line'] : null,
									'message' => $item['message'] ?? $item['description'] ?? json_encode($item),
									'severity' => $this->normalizeSeverity($item['severity'] ?? $this->detectSeverity($item['message'] ?? '')),
									'type' => $item['type'] ?? $this->detectIssueType($item['message'] ?? ''),
									'category' => $category,
									'code_snippet' => $this->normalizeCodeSnippet($item['code_snippet'] ?? null),
								];

								// Validate and correct issue before adding
								$correctedIssue = $this->validateIssue($issue, $filePath);
								if ($correctedIssue !== null) {
									$issues[] = $correctedIssue;
								}
							}
						}

						if (!empty($issues)) {
							return $issues;
						}
					}
				}
				// JSON-like but not valid - handle as odd format
				Log::warning('Could not extract valid JSON from analysis', [
					'file' => $filePath,
					'extraction_method' => $extractionMethod,
					'analysis_preview' => substr($analysis, 0, 500),
				]);
				$this->updateScanState([
					'message' => 'Handling odd response format...',
				]);
			}
		}

		// Try to extract structured information from AI response
		// Look for patterns like "Line X:", "Issue:", "Error:", etc.
		$lines = explode("\n", $analysis);
		$currentIssue = null;

		foreach ($lines as $line) {
			$line = trim($line);

			// Look for line number references
			if (preg_match('/line\s+(\d+)/i', $line, $matches)) {
				$lineNumber = (int) $matches[1];

				if ($currentIssue) {
					$issues[] = $currentIssue;
				}

				$currentIssue = [
					'line' => $lineNumber,
					'message' => $line,
					'severity' => $this->detectSeverity($line),
					'type' => $this->detectIssueType($line),
					'category' => $this->detectCategory($line),
					'code_snippet' => null,
				];
			} elseif ($currentIssue) {
				// Continue building current issue
				$currentIssue['message'] .= "\n" . $line;
			} elseif (
				!empty($line) && (
					stripos($line, 'bug') !== false ||
					stripos($line, 'error') !== false ||
					stripos($line, 'security') !== false ||
					stripos($line, 'issue') !== false ||
					stripos($line, 'problem') !== false
				)
			) {
				// Start a new issue without line number
				if ($currentIssue) {
					$issues[] = $currentIssue;
				}

				$currentIssue = [
					'line' => null,
					'message' => $line,
					'severity' => $this->detectSeverity($line),
					'type' => $this->detectIssueType($line),
					'category' => $this->detectCategory($line),
					'code_snippet' => null,
				];
			}
		}

		if ($currentIssue) {
			$issues[] = $currentIssue;
		}

		// If no structured issues found, create one summary issue
		// Only do this if we truly couldn't parse anything (not if we got empty array from JSON)
		if (empty($issues) && !empty(trim($analysis)) && !$jsonParsedSuccessfully) {
			// Check if the analysis looks like it might be JSON that we failed to parse
			$looksLikeJson = (strpos(trim($analysis), '[') === 0 || strpos(trim($analysis), '{') === 0);

			if ($looksLikeJson) {
				Log::warning('Failed to parse JSON response from AI', [
					'file' => $filePath,
					'analysis_preview' => substr($analysis, 0, 300),
					'analysis_length' => strlen($analysis),
				]);
				// Don't create a fallback issue for unparseable JSON - this indicates an AI response format issue
				// Return empty array instead of showing raw JSON to the user
				return [];
			}

			// For non-JSON text, create a summary issue
			$issues[] = [
				'line' => null,
				'message' => $analysis,
				'severity' => 'medium',
				'type' => 'analysis',
				'category' => null,
				'code_snippet' => null,
			];
		}

		return $issues;
	}

	/**
	 * Normalize severity value
	 */
	protected function normalizeSeverity(?string $severity): string
	{
		if (empty($severity)) {
			return 'medium';
		}

		$severity = strtolower(trim($severity));
		$validSeverities = ['critical', 'high', 'medium', 'low'];

		if (in_array($severity, $validSeverities)) {
			return $severity;
		}

		// Map common variations
		$severityMap = [
			'critical' => 'critical',
			'high' => 'high',
			'medium' => 'medium',
			'low' => 'low',
			'info' => 'low',
			'warning' => 'medium',
			'error' => 'high',
		];

		return $severityMap[$severity] ?? 'medium';
	}

	/**
	 * Detect category from issue text
	 */
	protected function detectCategory(string $text): ?string
	{
		$textLower = strtolower($text);

		if (
			stripos($textLower, 'security') !== false ||
			stripos($textLower, 'vulnerability') !== false ||
			stripos($textLower, 'injection') !== false ||
			stripos($textLower, 'xss') !== false ||
			stripos($textLower, 'csrf') !== false ||
			stripos($textLower, 'authentication') !== false
		) {
			return 'security';
		}

		if (
			stripos($textLower, 'performance') !== false ||
			stripos($textLower, 'slow') !== false ||
			stripos($textLower, 'optimization') !== false ||
			stripos($textLower, 'efficient') !== false
		) {
			return 'performance';
		}

		if (
			stripos($textLower, 'best practice') !== false ||
			stripos($textLower, 'psr') !== false ||
			stripos($textLower, 'convention') !== false ||
			stripos($textLower, 'standard') !== false
		) {
			return 'best_practices';
		}

		if (
			stripos($textLower, 'quality') !== false ||
			stripos($textLower, 'maintainability') !== false ||
			stripos($textLower, 'complexity') !== false ||
			stripos($textLower, 'readability') !== false
		) {
			return 'quality';
		}

		if (
			stripos($textLower, 'bug') !== false ||
			stripos($textLower, 'error') !== false ||
			stripos($textLower, 'incorrect') !== false ||
			stripos($textLower, 'wrong') !== false
		) {
			return 'bug';
		}

		return null;
	}

	/**
	 * Detect severity from issue text
	 */
	protected function detectSeverity(string $text): string
	{
		$textLower = strtolower($text);

		if (
			stripos($textLower, 'critical') !== false ||
			stripos($textLower, 'security') !== false ||
			stripos($textLower, 'vulnerability') !== false
		) {
			return 'critical';
		}

		if (
			stripos($textLower, 'high') !== false ||
			stripos($textLower, 'error') !== false ||
			stripos($textLower, 'bug') !== false
		) {
			return 'high';
		}

		if (
			stripos($textLower, 'low') !== false ||
			stripos($textLower, 'minor') !== false ||
			stripos($textLower, 'suggestion') !== false
		) {
			return 'low';
		}

		return 'medium';
	}

	/**
	 * Detect issue type from text
	 */
	protected function detectIssueType(string $text): string
	{
		$textLower = strtolower($text);

		if (stripos($textLower, 'security') !== false || stripos($textLower, 'vulnerability') !== false) {
			return 'security';
		}

		if (stripos($textLower, 'performance') !== false) {
			return 'performance';
		}

		if (stripos($textLower, 'bug') !== false || stripos($textLower, 'error') !== false) {
			return 'bug';
		}

		if (stripos($textLower, 'quality') !== false || stripos($textLower, 'best practice') !== false) {
			return 'quality';
		}

		return 'general';
	}

	/**
	 * Save issues to database
	 */
	protected function saveIssuesToDatabase(array $compiledResults): int
	{
		$savedCount = 0;

		if (!isset($compiledResults['files']) || empty($compiledResults['files'])) {
			return $savedCount;
		}

		try {
			foreach ($compiledResults['files'] as $fileData) {
				if (!isset($fileData['issues']) || empty($fileData['issues'])) {
					continue;
				}

				foreach ($fileData['issues'] as $issue) {
					try {
						ScanIssue::create([
							'scan_id' => $this->scanId,
							'user_id' => $this->userId,
							'file_path' => $fileData['file'] ?? '',
							'line' => $issue['line'] ?? null,
							'type' => $issue['type'] ?? 'general',
							'severity' => $issue['severity'] ?? 'medium',
							'message' => $issue['message'] ?? '',
							'raw_data' => $issue,
						]);
						$savedCount++;
					} catch (\Exception $e) {
						Log::warning('Failed to save scan issue to database', [
							'scan_id' => $this->scanId,
							'file' => $fileData['file'] ?? 'unknown',
							'error' => $e->getMessage(),
						]);
						// Continue with next issue
					}
				}
			}
		} catch (\Exception $e) {
			Log::error('Failed to save scan issues to database', [
				'scan_id' => $this->scanId,
				'error' => $e->getMessage(),
			]);
		}

		return $savedCount;
	}

	/**
	 * Update scan state in cache
	 */
	protected function updateScanState(array $updates): void
	{
		$key = "overlord_scan_{$this->scanId}";
		$current = Cache::get($key, []);
		$updated = array_merge($current, $updates);
		Cache::put($key, $updated, now()->addHours(2));
	}

	/**
	 * Update scan history in database
	 */
	protected function updateScanHistory(array $updates): void
	{
		try {
			$scanHistory = ScanHistory::where('scan_id', $this->scanId)->first();
			if ($scanHistory) {
				$scanHistory->update($updates);
			}
		} catch (\Exception $e) {
			Log::warning('Failed to update scan history', [
				'scan_id' => $this->scanId,
				'error' => $e->getMessage(),
			]);
		}
	}
}