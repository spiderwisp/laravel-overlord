<?php

namespace Spiderwisp\LaravelOverlord\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spiderwisp\LaravelOverlord\Models\DatabaseScanIssue;
use Spiderwisp\LaravelOverlord\Models\DatabaseScanHistory;
use Spiderwisp\LaravelOverlord\Services\RealAiService;
use Spiderwisp\LaravelOverlord\Services\DatabaseSchemaService;
use Spiderwisp\LaravelOverlord\Services\DatabaseSchemaValidator;
use Spiderwisp\LaravelOverlord\Enums\AiErrorCode;

class ScanDatabaseJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected string $scanId;
	protected ?int $userId;
	protected string $scanType; // 'schema' or 'data'
	protected string $scanMode; // 'full' or 'selective'
	protected array $selectedTables;
	protected int $sampleSize;
	protected int $maxBatchSize = 5; // Number of tables per batch
	protected int $maxBatchSizeBytes = 50000; // Max bytes per batch (~12.5k tokens)
	protected int $maxTokensEstimate = 10000; // Max tokens per request

	/**
	 * Create a new job instance.
	 */
	public function __construct(
		string $scanId,
		?int $userId,
		string $scanType = 'schema',
		string $scanMode = 'full',
		array $selectedTables = [],
		int $sampleSize = 100
	) {
		$this->scanId = $scanId;
		$this->userId = $userId;
		$this->scanType = $scanType;
		$this->scanMode = $scanMode;
		$this->selectedTables = is_array($selectedTables) ? $selectedTables : [];
		$this->sampleSize = $sampleSize;
	}

	/**
	 * Execute the job.
	 */
	public function handle(RealAiService $aiService, DatabaseSchemaService $schemaService): void
	{
		try {
			// Update scan state to show we're discovering tables
			$this->updateScanState([
				'status' => 'discovering',
				'progress' => 0,
				'total_tables' => 0,
				'processed_tables' => 0,
				'total_batches' => 0,
				'processed_batches' => 0,
				'started_at' => now()->toIso8601String(),
				'message' => 'Discovering database tables...',
			]);

			// Discover tables
			try {
				$tables = $this->discoverTables($schemaService);
				$totalTables = count($tables);
			} catch (\Exception $e) {
				$this->updateScanState([
					'status' => 'failed',
					'progress' => 0,
					'error' => 'Failed to discover tables: ' . $e->getMessage(),
				]);

				$this->updateScanHistory([
					'status' => 'failed',
					'error' => 'Failed to discover tables: ' . $e->getMessage(),
					'completed_at' => now(),
				]);

				return;
			}

			if ($totalTables === 0) {
				$errorMessage = $this->scanMode === 'selective'
					? 'No tables found in selected list'
					: 'No tables found in database';

				$this->updateScanState([
					'status' => 'completed',
					'progress' => 100,
					'total_tables' => 0,
					'error' => $errorMessage,
				]);

				$this->updateScanHistory([
					'status' => 'completed',
					'total_tables' => 0,
					'error' => $errorMessage,
					'completed_at' => now(),
				]);

				return;
			}

			// Update with total tables found
			$this->updateScanState([
				'total_tables' => $totalTables,
				'status' => 'scanning',
				'progress' => 0,
				'message' => "Found {$totalTables} tables, organizing into batches...",
			]);

			// Update scan history
			$this->updateScanHistory([
				'total_tables' => $totalTables,
			]);

			// Create batches based on scan type
			if ($this->scanType === 'schema') {
				$batches = $this->createSchemaBatches($tables, $schemaService);
			} else {
				$batches = $this->createDataBatches($tables, $schemaService);
			}

			$totalBatches = count($batches);

			$this->updateScanState([
				'total_batches' => $totalBatches,
				'status' => 'scanning',
				'progress' => 0,
				'message' => "Organized into {$totalBatches} batches, starting analysis...",
			]);

			$allResults = [];
			$processedBatches = 0;

			// Process each batch
			foreach ($batches as $batchIndex => $batch) {
				try {
					// Update status before processing batch
					$this->updateScanState([
						'message' => "Analyzing table batch " . ($batchIndex + 1) . " of {$totalBatches}...",
					]);

					if ($this->scanType === 'schema') {
						$batchResult = $this->analyzeSchemaBatch($batch, $aiService);
					} else {
						$batchResult = $this->analyzeDataBatch($batch, $aiService);
					}

					$allResults = array_merge($allResults, $batchResult);
					$processedBatches++;

					// Update progress after each batch
					$progress = (int) (($processedBatches / $totalBatches) * 100);
					$processedTablesCount = min($processedBatches * $this->maxBatchSize, $totalTables);

					$this->updateScanState([
						'processed_batches' => $processedBatches,
						'processed_tables' => $processedTablesCount,
						'progress' => $progress,
						'status' => 'scanning',
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
						'tables' => array_column($batch, 'name'),
						'analysis' => 'Failed to analyze: ' . $errorMessage,
						'error' => true,
					];

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
				'message' => 'Compiling database scan results...',
			]);
			$compiledResults = $this->compileResults($allResults);

			// Store results in cache (1 hour TTL)
			Cache::put("overlord_database_scan_{$this->scanId}_results", $compiledResults, now()->addHour());

			// Save issues to database
			$this->updateScanState([
				'message' => 'Saving database issues...',
			]);
			$savedCount = $this->saveIssuesToDatabase($compiledResults);

			// Mark as completed
			$this->updateScanState([
				'status' => 'completed',
				'progress' => 100,
				'processed_tables' => $totalTables,
				'processed_batches' => $processedBatches,
				'completed_at' => now()->toIso8601String(),
				'total_issues_found' => $compiledResults['summary']['total_issues'] ?? 0,
				'issues_saved' => $savedCount,
			]);

			// Update scan history
			$this->updateScanHistory([
				'status' => 'completed',
				'processed_tables' => $totalTables,
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
	 * Discover tables from database
	 */
	protected function discoverTables(DatabaseSchemaService $schemaService): array
	{
		$allTables = $schemaService->getTables();

		if ($this->scanMode === 'selective' && !empty($this->selectedTables)) {
			// Filter to only selected tables
			$filtered = array_filter($allTables, function ($table) {
				return in_array($table, $this->selectedTables);
			});
			return array_values($filtered);
		}

		return $allTables;
	}

	/**
	 * Create batches for schema analysis
	 */
	protected function createSchemaBatches(array $tables, DatabaseSchemaService $schemaService): array
	{
		$batches = [];
		$currentBatch = [];
		$currentBatchSize = 0;

		foreach ($tables as $tableName) {
			try {
				$schema = $schemaService->getTableSchema($tableName);
				if (!$schema) {
					continue;
				}

				$schemaJson = json_encode($schema);
				$schemaSize = strlen($schemaJson);

				// Estimate tokens: roughly 1 token per 4 characters
				$tokenEstimate = (int) ($schemaSize / 4);

				// Check if adding this table would exceed limits
				if (
					count($currentBatch) >= $this->maxBatchSize ||
					($currentBatchSize + $schemaSize) > $this->maxBatchSizeBytes ||
					$tokenEstimate > $this->maxTokensEstimate
				) {
					// Start a new batch
					if (!empty($currentBatch)) {
						$batches[] = $currentBatch;
					}
					$currentBatch = [];
					$currentBatchSize = 0;
				}

				$currentBatch[] = [
					'name' => $tableName,
					'schema' => $schema,
				];
				$currentBatchSize += $schemaSize;
			} catch (\Exception $e) {
				// Continue with next table if schema fetch fails
			}
		}

		// Add the last batch if it has tables
		if (!empty($currentBatch)) {
			$batches[] = $currentBatch;
		}

		return $batches;
	}

	/**
	 * Create batches for data analysis
	 */
	protected function createDataBatches(array $tables, DatabaseSchemaService $schemaService): array
	{
		$batches = [];
		$currentBatch = [];
		$currentBatchSize = 0;

		foreach ($tables as $tableName) {
			try {
				$data = $schemaService->getTableData($tableName, $this->sampleSize);
				if (empty($data)) {
					continue;
				}

				$tableData = [
					'name' => $tableName,
					'data' => $data,
				];

				$dataJson = json_encode($tableData);
				$dataSize = strlen($dataJson);

				// Estimate tokens: roughly 1 token per 4 characters
				$tokenEstimate = (int) ($dataSize / 4);

				// Check if adding this table would exceed limits
				if (
					count($currentBatch) >= $this->maxBatchSize ||
					($currentBatchSize + $dataSize) > $this->maxBatchSizeBytes ||
					$tokenEstimate > $this->maxTokensEstimate
				) {
					// Start a new batch
					if (!empty($currentBatch)) {
						$batches[] = $currentBatch;
					}
					$currentBatch = [];
					$currentBatchSize = 0;
				}

				$currentBatch[] = $tableData;
				$currentBatchSize += $dataSize;
			} catch (\Exception $e) {
				// Continue with next table if data fetch fails
			}
		}

		// Add the last batch if it has tables
		if (!empty($currentBatch)) {
			$batches[] = $currentBatch;
		}

		return $batches;
	}

	/**
	 * Analyze a batch of schema using AI
	 */
	protected function analyzeSchemaBatch(array $batch, RealAiService $aiService): array
	{
		$results = [];

		// Build prompt for AI
		$prompt = $this->buildSchemaAnalysisPrompt($batch);

		// Estimate prompt tokens
		$promptTokens = (int) (strlen($prompt) / 4);

		// If prompt is too large, split the batch further
		if ($promptTokens > $this->maxTokensEstimate) {
			// Split in half and process separately
			$midPoint = (int) (count($batch) / 2);
			$firstHalf = array_slice($batch, 0, $midPoint);
			$secondHalf = array_slice($batch, $midPoint);

			$firstResults = $this->analyzeSchemaBatchContents($firstHalf, $aiService);
			$secondResults = $this->analyzeSchemaBatchContents($secondHalf, $aiService);

			return array_merge($firstResults, $secondResults);
		}

		return $this->analyzeSchemaBatchContents($batch, $aiService);
	}

	/**
	 * Analyze schema batch contents with retry logic
	 */
	protected function analyzeSchemaBatchContents(array $batch, RealAiService $aiService): array
	{
		$results = [];
		$maxRetries = 2;
		$retryCount = 0;

		while ($retryCount <= $maxRetries) {
			try {
				$prompt = $this->buildSchemaAnalysisPrompt($batch);

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

				// Pass schema data in analysis_data so SaaS instructions can reference it
				$analysisData = [
					'schemas' => array_map(fn($tableData) => $tableData['schema'], $batch),
				];

				$aiResponse = $aiService->chat($prompt, [], null, null, 'database_scan', $analysisData);

				// Update status when parsing response
				$this->updateScanState([
					'message' => 'Parsing AI response...',
				]);

				if ($aiResponse['success'] && isset($aiResponse['message'])) {
					// Parse AI response and associate with tables
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
							'analysis' => $aiResponse['message'],
							'raw_response' => $aiResponse['message'],
						];
					}
					return $results;
				} else {
					$error = $aiResponse['error'] ?? '';

					// Check if it's a rate limit error using Enum values
					$errorCode = $aiResponse['code'] ?? null;
					$isRateLimit = $errorCode === AiErrorCode::RATE_LIMIT_EXCEEDED->value ||
						$errorCode === AiErrorCode::QUOTA_EXCEEDED->value ||
						stripos($error, 'rate limit') !== false ||
						stripos($error, '429') !== false ||
						stripos($error, 'too many requests') !== false ||
						stripos($error, 'quota exceeded') !== false;

					if ($isRateLimit) {
						// Throw exception with the error message from SaaS (which should already be formatted)
						throw new \Exception('RATE_LIMIT_EXCEEDED: ' . $error);
					}

					if (
						stripos($error, '413') !== false ||
						stripos($error, 'too large') !== false ||
						stripos($error, 'Request too large') !== false
					) {

						if ($retryCount < $maxRetries && count($batch) > 1) {
							$this->updateScanState([
								'message' => 'Batch too large, splitting for better analysis...',
							]);

							$midPoint = (int) (count($batch) / 2);
							$firstHalf = array_slice($batch, 0, $midPoint);
							$secondHalf = array_slice($batch, $midPoint);

							$firstResults = $this->analyzeSchemaBatchContents($firstHalf, $aiService);
							$secondResults = $this->analyzeSchemaBatchContents($secondHalf, $aiService);

							return array_merge($firstResults, $secondResults);
						}
					}

					// Record failure
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
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

				if ($retryCount >= $maxRetries) {
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
							'analysis' => 'Failed to analyze: ' . $errorMessage,
							'error' => true,
						];
					}
					return $results;
				}

				$retryCount++;
			}
		}

		return $results;
	}

	/**
	 * Analyze a batch of data using AI
	 */
	protected function analyzeDataBatch(array $batch, RealAiService $aiService): array
	{
		$results = [];

		// Build prompt for AI
		$prompt = $this->buildDataAnalysisPrompt($batch);

		// Estimate prompt tokens
		$promptTokens = (int) (strlen($prompt) / 4);

		// If prompt is too large, split the batch further
		if ($promptTokens > $this->maxTokensEstimate) {
			// Split in half and process separately
			$midPoint = (int) (count($batch) / 2);
			$firstHalf = array_slice($batch, 0, $midPoint);
			$secondHalf = array_slice($batch, $midPoint);

			$firstResults = $this->analyzeDataBatchContents($firstHalf, $aiService);
			$secondResults = $this->analyzeDataBatchContents($secondHalf, $aiService);

			return array_merge($firstResults, $secondResults);
		}

		return $this->analyzeDataBatchContents($batch, $aiService);
	}

	/**
	 * Analyze data batch contents with retry logic
	 */
	protected function analyzeDataBatchContents(array $batch, RealAiService $aiService): array
	{
		$results = [];
		$maxRetries = 2;
		$retryCount = 0;

		while ($retryCount <= $maxRetries) {
			try {
				$prompt = $this->buildDataAnalysisPrompt($batch);

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

				// Pass data samples in analysis_data so SaaS instructions can reference it
				$analysisData = [
					'data_samples' => $batch,
				];

				$aiResponse = $aiService->chat($prompt, [], null, null, 'database_scan', $analysisData);

				// Update status when parsing response
				$this->updateScanState([
					'message' => 'Parsing AI response...',
				]);

				if ($aiResponse['success'] && isset($aiResponse['message'])) {
					// Parse AI response and associate with tables
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
							'analysis' => $aiResponse['message'],
							'raw_response' => $aiResponse['message'],
						];
					}
					return $results;
				} else {
					$error = $aiResponse['error'] ?? '';

					// Check if it's a rate limit error using Enum values
					$errorCode = $aiResponse['code'] ?? null;
					$isRateLimit = $errorCode === AiErrorCode::RATE_LIMIT_EXCEEDED->value ||
						$errorCode === AiErrorCode::QUOTA_EXCEEDED->value ||
						stripos($error, 'rate limit') !== false ||
						stripos($error, '429') !== false ||
						stripos($error, 'too many requests') !== false ||
						stripos($error, 'quota exceeded') !== false;

					if ($isRateLimit) {
						// Throw exception with the error message from SaaS (which should already be formatted)
						throw new \Exception('RATE_LIMIT_EXCEEDED: ' . $error);
					}

					if (
						stripos($error, '413') !== false ||
						stripos($error, 'too large') !== false ||
						stripos($error, 'Request too large') !== false
					) {

						if ($retryCount < $maxRetries && count($batch) > 1) {
							$this->updateScanState([
								'message' => 'Batch too large, splitting for better analysis...',
							]);

							$midPoint = (int) (count($batch) / 2);
							$firstHalf = array_slice($batch, 0, $midPoint);
							$secondHalf = array_slice($batch, $midPoint);

							$firstResults = $this->analyzeDataBatchContents($firstHalf, $aiService);
							$secondResults = $this->analyzeDataBatchContents($secondHalf, $aiService);

							return array_merge($firstResults, $secondResults);
						}
					}

					// Record failure
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
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

				if ($retryCount >= $maxRetries) {
					foreach ($batch as $tableData) {
						$results[] = [
							'table' => $tableData['name'],
							'analysis' => 'Failed to analyze: ' . $errorMessage,
							'error' => true,
						];
					}
					return $results;
				}

				$retryCount++;
			}
		}

		return $results;
	}

	/**
	 * Build prompt for schema analysis
	 * Note: Instructions are now handled by SaaS-side instruction sets.
	 * This method just formats the schema data for the AI.
	 */
	protected function buildSchemaAnalysisPrompt(array $batch): string
	{
		$schemas = [];
		foreach ($batch as $tableData) {
			$schemas[] = $tableData['schema'];
		}

		$schemasJson = json_encode($schemas, JSON_PRETTY_PRINT);

		// Simple message - all instructions come from SaaS instruction sets
		return "Analyze the following database schema(s) for issues:\n\n{$schemasJson}";
	}

	/**
	 * Build prompt for data analysis
	 */
	protected function buildDataAnalysisPrompt(array $batch): string
	{
		$dataJson = json_encode($batch, JSON_PRETTY_PRINT);

		return "Analyze the following database data sample(s) for inconsistencies and issues. Look for:
- Null values where they shouldn't be
- Data format/validation issues
- Duplicate records
- Referential integrity issues (orphaned records)
- Any other data-related issues

Database Data Sample(s):
{$dataJson}

**IMPORTANT: Return ONLY a JSON array of issues. Do NOT include markdown code blocks, explanatory text, or any other content.**

**CATEGORY SELECTION GUIDELINES (CRITICAL):**
You must correctly categorize each issue:

**performance** - Use for:
- Missing indexes on frequently queried columns (ALWAYS use \"performance\" for missing indexes, NOT \"security\")
- Query performance issues, slow queries, bottlenecks
- Optimization opportunities, full table scans
Example: \"Missing index on frequently queried user_id\" → category: \"performance\"

**security** - Use for:
- SQL injection vulnerabilities, authentication/authorization issues
- Exposed sensitive data (passwords, API keys, PII), encryption issues
- Access control problems, data breach risks
Example: \"Password stored in plain text\" → category: \"security\"

**quality** - Use for:
- Data type mismatches, missing constraints, validation issues
- Nullability concerns, naming convention issues
Example: \"Column should not allow NULL values\" → category: \"quality\"

**best_practices** - Use for:
- Missing foreign key relationships, normalization/denormalization issues
- Convention violations, recommended patterns
Example: \"Missing foreign key constraint on user_id\" → category: \"best_practices\"

**bug** - Use for:
- Orphaned records, broken relationships, data integrity issues
- Invalid references
Example: \"Orphaned records in orders table\" → category: \"bug\"

**IMPORTANT:** Missing indexes are ALWAYS \"performance\" issues, NOT \"security\" issues.

Return format (JSON array):
[
{
  \"table\": \"table_name\",
  \"issue_type\": \"data\",
  \"severity\": \"low|medium|high|critical\",
  \"category\": \"security|quality|performance|best_practices|bug\",
  \"title\": \"Brief title\",
  \"description\": \"Detailed description with markdown formatting (use **bold**, `code`, ```code blocks```, - lists)\",
  \"location\": {\"column\": \"column_name\", \"row_id\": \"id\", etc.},
  \"suggestion\": \"How to fix this issue\"
  }
]

Each issue must have: table, issue_type, severity, category, title, description, location (object), suggestion.
- issue_type must be \"data\"
- severity must be one of: \"low\", \"medium\", \"high\", \"critical\"
- category must be one of: \"security\", \"quality\", \"performance\", \"best_practices\", \"bug\"
- description can use markdown: **bold**, `inline code`, ```code blocks```, - lists, ## headings
Return an empty array [] if no issues are found.";
	}

	/**
	 * Compile results from all batches
	 */
	protected function compileResults(array $allResults): array
	{
		$compiled = [
			'summary' => [
				'total_tables' => 0,
				'total_issues' => 0,
				'issues_by_severity' => [
					'critical' => 0,
					'high' => 0,
					'medium' => 0,
					'low' => 0,
				],
			],
			'issues' => [],
		];

		$tablesProcessed = [];
		$seenIssues = []; // Track seen issues to prevent duplicates
		$totalResults = count($allResults);
		$processedResults = 0;
		$duplicateCount = 0;

		foreach ($allResults as $resultIndex => $result) {
			$processedResults++;

			if (isset($result['table'])) {
				$tablesProcessed[] = $result['table'];
			}

			// Skip parsing if this result indicates an error
			if (isset($result['error']) && $result['error'] === true) {
				continue;
			}

			// Try to parse JSON from AI response
			$analysis = $result['analysis'] ?? '';

			// Skip if analysis is an error message
			if (
				stripos($analysis, 'AI analysis failed:') === 0 ||
				stripos($analysis, 'Failed to analyze:') === 0
			) {
				continue;
			}

			// Skip if analysis is empty
			if (empty(trim($analysis))) {
				continue;
			}

			// Parse issues with error handling to prevent one bad result from hanging
			// Add timeout protection for very large analysis responses
			try {
				$analysisLength = strlen($analysis);
				// Skip if analysis is extremely large (likely malformed)
				if ($analysisLength > 500000) { // 500KB limit
					continue;
				}
				$issues = $this->parseIssuesFromAnalysis($analysis, $result['table'] ?? 'unknown');
			} catch (\Exception $e) {
				// Continue with next result instead of failing entire compilation
				continue;
			}

			foreach ($issues as $issue) {
				// Create a unique key for this issue to detect duplicates
				$issueKey = $this->getIssueUniqueKey($issue);

				// Skip if we've already seen this issue
				if (isset($seenIssues[$issueKey])) {
					$duplicateCount++;
					continue;
				}

				// Mark as seen and add to compiled results
				$seenIssues[$issueKey] = true;
				$compiled['issues'][] = $issue;
				$compiled['summary']['total_issues']++;
				$severity = $issue['severity'] ?? 'medium';
				if (isset($compiled['summary']['issues_by_severity'][$severity])) {
					$compiled['summary']['issues_by_severity'][$severity]++;
				}
			}
		}

		$compiled['summary']['total_tables'] = count(array_unique($tablesProcessed));

		return $compiled;
	}

	/**
	 * Generate a unique key for an issue to detect duplicates
	 */
	protected function getIssueUniqueKey(array $issue): string
	{
		// Use table, title, description, and location to create a unique key
		$table = $issue['table'] ?? 'unknown';
		$title = $issue['title'] ?? '';
		$description = $issue['description'] ?? '';

		// Sort location array keys manually for consistent JSON encoding
		$location = '';
		if (isset($issue['location']) && is_array($issue['location'])) {
			ksort($issue['location']);
			$location = json_encode($issue['location']);
		}

		// Create a hash of the key components
		$keyString = $table . '|' . $title . '|' . $description . '|' . $location;
		return md5($keyString);
	}

	/**
	 * Parse issues from AI analysis response
	 */
	protected function parseIssuesFromAnalysis(string $analysis, string $tableName): array
	{
		$issues = [];
		$rawResponse = $analysis;
		$analysisLength = strlen($analysis);

		// Try to extract JSON from the response using multiple methods
		$jsonString = $this->extractJsonFromText($analysis);

		if ($jsonString) {
			$parsed = json_decode($jsonString, true);
			$jsonError = json_last_error();

			if ($jsonError === JSON_ERROR_NONE && $parsed !== null) {
				// Handle array of issues
				if (is_array($parsed)) {
					// Check if it's a numeric array (JSON array) or associative array (JSON object)
					$isNumericArray = !empty($parsed) && array_keys($parsed) === range(0, count($parsed) - 1);

					if ($isNumericArray && isset($parsed[0]) && is_array($parsed[0])) {
						// Check if it's a nested structure with "name" and "problems" (wrong format)
						if (isset($parsed[0]['name']) && isset($parsed[0]['problems']) && is_array($parsed[0]['problems'])) {
							// Flatten nested structure: [{name: "table", problems: [...]}] -> [...]
							$flattenedIssues = [];
							foreach ($parsed as $tableGroup) {
								$groupTableName = $tableGroup['name'] ?? $tableName;
								$tableProblems = $tableGroup['problems'] ?? [];

								foreach ($tableProblems as $problem) {
									if (is_array($problem)) {
										// Ensure each issue has the correct table name
										$problem['table'] = $groupTableName;
										// Fix field names if needed
										if (isset($problem['issuetype'])) {
											$problem['issue_type'] = $problem['issuetype'];
											unset($problem['issuetype']);
										}
										$flattenedIssues[] = $problem;
									}
								}
							}

							// Filter false positives using validator
							$validator = app(DatabaseSchemaValidator::class);
							$filteredIssues = [];

							// Group issues by table for efficient validation
							$issuesByTable = [];
							foreach ($flattenedIssues as $issue) {
								$issueTable = $issue['table'] ?? $tableName;
								if (!isset($issuesByTable[$issueTable])) {
									$issuesByTable[$issueTable] = [];
								}
								$issuesByTable[$issueTable][] = $issue;
							}

							// Validate and filter issues per table
							foreach ($issuesByTable as $issueTable => $tableIssues) {
								$tableFiltered = $validator->filterFalsePositives($tableIssues, $issueTable);
								$filteredIssues = array_merge($filteredIssues, $tableFiltered);
							}

							foreach ($filteredIssues as $issue) {
								if (is_array($issue)) {
									$issueTable = $issue['table'] ?? $tableName;
									$normalized = $this->normalizeIssueStructure($issue, $issueTable);
									if ($normalized) {
										$issues[] = $normalized;
									}
								}
							}
						} else {
							// It's a flat array of issues (correct format)
							// Filter false positives using validator
							$validator = app(DatabaseSchemaValidator::class);
							$filteredIssues = $validator->filterFalsePositives($parsed, $tableName);

							foreach ($filteredIssues as $issue) {
								if (is_array($issue)) {
									$normalized = $this->normalizeIssueStructure($issue, $tableName);
									if ($normalized) {
										$issues[] = $normalized;
									}
								}
							}
						}
					} else {
						// Single issue object (associative array), wrap in array
						// Filter false positives using validator
						$validator = app(DatabaseSchemaValidator::class);
						$filteredIssues = $validator->filterFalsePositives([$parsed], $tableName);

						if (!empty($filteredIssues)) {
							$normalized = $this->normalizeIssueStructure($filteredIssues[0], $tableName);
							if ($normalized) {
								$issues[] = $normalized;
							}
						}
					}
				}
			}
		}

		// If no structured issues found, check if it's an error message, code block, or markdown text
		// Don't create fallback issues for these cases
		if (empty($issues) && !empty(trim($analysis))) {
			// Check if this is an error message (not a valid analysis)
			$isError = stripos($analysis, 'AI analysis failed:') === 0 ||
				stripos($analysis, 'Failed to analyze:') === 0 ||
				stripos($analysis, 'Unknown error') !== false;

			if ($isError) {
				// Return empty array - don't create an issue for errors
				return [];
			}

			// Check if the response contains markdown text before JSON (AI didn't follow instructions)
			$hasMarkdownBeforeJson = preg_match('/^(Based on|Here are|The following|Analysis Result|Missing Indexes|Potential|To address|Here is|The provided)/i', trim($analysis)) ||
				preg_match('/Based on the provided database schema/i', $analysis) ||
				preg_match('/The provided database schema\(s\)/i', $analysis) ||
				preg_match('/Here is the analysis/i', $analysis) ||
				preg_match('/Here is an updated version/i', $analysis) ||
				stripos($analysis, 'the following issues have been identified') !== false ||
				stripos($analysis, 'Missing Indexes:') !== false ||
				stripos($analysis, 'Potential Data Integrity Issues:') !== false ||
				stripos($analysis, 'The analysis identified') !== false ||
				stripos($analysis, 'do not contain any critical issues') !== false ||
				stripos($analysis, 'However, there are some suggestions') !== false ||
				stripos($analysis, 'Consider adding') !== false;

			if ($hasMarkdownBeforeJson) {
				// Try one more time to extract JSON from the markdown text (with size limit to prevent hangs)
				$analysisLength = strlen($analysis);
				if ($analysisLength > 200000) { // 200KB limit for retry
					return [];
				}

				$retryJson = $this->extractJsonFromText($analysis);
				if ($retryJson) {
					$retryParsed = json_decode($retryJson, true);
					if (json_last_error() === JSON_ERROR_NONE && is_array($retryParsed) && !empty($retryParsed)) {
						// Process the retried JSON
						$validator = app(DatabaseSchemaValidator::class);
						$filteredIssues = $validator->filterFalsePositives($retryParsed, $tableName);
						foreach ($filteredIssues as $issue) {
							if (is_array($issue)) {
								$normalized = $this->normalizeIssueStructure($issue, $tableName);
								if ($normalized) {
									$issues[] = $normalized;
								}
							}
						}
						return $issues;
					}
				}
				// Return empty array - don't create an issue for markdown responses
				return [];
			}

			// Check if the analysis contains code blocks (PHP, JavaScript, etc.)
			// This indicates the AI returned example code instead of analysis
			$hasCodeBlock = preg_match('/```(?:php|javascript|js|json|sql|code)?\s*[\s\S]*?```/', $analysis) ||
				preg_match('/<\?(?:php)?[\s\S]*?\?>/', $analysis) ||
				preg_match('/use\s+[A-Z][\w\\\\]+;/', $analysis) || // PHP use statements
				preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=/', $analysis) || // PHP variables
				preg_match('/function\s+\w+\s*\(/', $analysis) || // Function definitions
				preg_match('/foreach\s*\(/', $analysis) || // PHP foreach
				preg_match('/DB::(table|select|insert|update|delete)/', $analysis); // Laravel DB calls

			if ($hasCodeBlock) {
				// Return empty array - don't create an issue for code examples
				return [];
			}

			// Check if the analysis looks like raw code (starts with PHP keywords or code patterns)
			$trimmedAnalysis = trim($analysis);
			$startsWithCode = preg_match('/^(use\s+|<\?php|function\s+|class\s+|namespace\s+|return\s+|if\s*\(|foreach\s*\(|DB::)/i', $trimmedAnalysis);

			if ($startsWithCode) {
				// Return empty array - don't create an issue for code
				return [];
			}

			// Only create fallback for non-error, non-code, non-markdown analysis that couldn't be parsed
			// If we get here, it means the response doesn't match any of our skip patterns
			// But we should still be cautious - if it's long markdown text, skip it
			$isLongMarkdown = strlen($analysis) > 500 && (
				substr_count($analysis, "\n") > 10 ||
				preg_match('/Add (a|an) (unique index|foreign key|index)/i', $analysis) ||
				preg_match('/Consider adding/i', $analysis)
			);

			if ($isLongMarkdown) {
				return [];
			}

			$issues[] = [
				'table' => $tableName,
				'issue_type' => $this->scanType,
				'severity' => 'medium',
				'title' => 'Analysis Result',
				'description' => $analysis,
				'location' => [],
				'suggestion' => '',
			];
		}

		return $issues;
	}

	/**
	 * Extract JSON from text, handling multiple formats
	 * Filters out code blocks and focuses on JSON content
	 */
	protected function extractJsonFromText(string $text): ?string
	{
		// Limit text size to prevent regex performance issues (100KB max)
		$maxTextSize = 100 * 1024; // 100KB
		if (strlen($text) > $maxTextSize) {
			$text = substr($text, 0, $maxTextSize);
		}

		// First, try to remove code blocks that might interfere
		// Remove PHP code blocks (```php ... ```)
		$text = preg_replace('/```php\s*[\s\S]*?```/i', '', $text);
		// Remove schema JSON code blocks (they contain "name", "columns", "indexes" - not issue data)
		// But keep potential issue JSON blocks for now
		$text = preg_replace_callback('/```(?:json)?\s*(\[[\s\S]*?\]|{[\s\S]*?})\s*```/i', function ($matches) {
			$content = $matches[1];
			$decoded = json_decode($content, true);
			// If it looks like schema data (has "name", "columns", "indexes"), remove it
			if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) {
				$firstItem = $decoded[0];
				if (isset($firstItem['name']) || isset($firstItem['columns']) || isset($firstItem['indexes'])) {
					return ''; // Remove schema JSON blocks
				}
			}
			// Keep other JSON blocks (they might be issues)
			return $matches[0];
		}, $text);
		// Remove other generic code blocks that don't contain JSON
		$text = preg_replace('/```(?!json)[a-z]*\s*[\s\S]*?```/i', '', $text);

		// Method 1: Try to extract from markdown code blocks (```json ... ``` or ``` ... ```)
		// But skip schema JSON blocks - we want the issues array, not the schema
		// Look for JSON arrays that contain issue objects (have "table", "issue_type", etc.)
		if (preg_match_all('/```(?:json)?\s*(\[[\s\S]*?\]|{[\s\S]*?})\s*```/', $text, $allMatches, PREG_SET_ORDER)) {
			foreach ($allMatches as $matches) {
				$candidate = trim($matches[1]);
				// Check size before processing
				if (strlen($candidate) > 50000) {
					continue;
				}
				// Validate it's proper JSON
				$decoded = json_decode($candidate, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
					// Check if this looks like an issues array (has objects with "table" or "issue_type")
					// Skip if it looks like schema data (has "name", "columns", "indexes" keys)
					$isSchemaData = false;
					if (isset($decoded[0]) && is_array($decoded[0])) {
						$firstItem = $decoded[0];
						if (isset($firstItem['name']) || isset($firstItem['columns']) || isset($firstItem['indexes'])) {
							$isSchemaData = true;
						}
					}
					// If it's not schema data and looks like issues, return it
					if (!$isSchemaData && (empty($decoded) || (isset($decoded[0]) && (isset($decoded[0]['table']) || isset($decoded[0]['issue_type']))))) {
						return $candidate;
					}
				}
			}
		}

		// Method 2: Try to find JSON array pattern [ ... ] with proper bracket matching
		// First, try to find JSON that appears after common phrases like "Here is the JSON" or "JSON array"
		$jsonPhrases = [
			'/here is the json array[:\s]*/i',
			'/json array[:\s]*/i',
			'/here is the json[:\s]*/i',
			'/the json array[:\s]*/i',
		];

		$searchStart = 0;
		foreach ($jsonPhrases as $pattern) {
			if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
				$searchStart = $matches[0][1] + strlen($matches[0][0]);
				break;
			}
		}

		// Also try searching from the end backwards (JSON is often at the end after markdown)
		$lastBracket = strrpos($text, ']');
		if ($lastBracket !== false && $lastBracket > strlen($text) * 0.5) {
			// JSON is likely near the end, search backwards from there
			$searchStart = max($searchStart, $lastBracket - 50000); // Search up to 50KB before the last ]
		}

		// Find the first [ after the search start (or from beginning if no phrase found)
		$firstBracket = strpos($text, '[', $searchStart);
		if ($firstBracket !== false) {
			$depth = 0;
			$inString = false;
			$escapeNext = false;
			$start = $firstBracket;
			$textLength = strlen($text);
			$maxIterations = min($textLength, 200000); // Limit to 200KB max
			$iterations = 0;

			for ($i = $start; $i < $textLength && $iterations < $maxIterations; $i++) {
				$iterations++;
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

				if (!$inString) {
					if ($char === '[') {
						$depth++;
					} elseif ($char === ']') {
						$depth--;
						if ($depth === 0) {
							// Found matching closing bracket
							$candidate = substr($text, $start, $i - $start + 1);
							// Check size before processing
							if (strlen($candidate) > 50000) {
								break;
							}
							// Validate it's proper JSON by trying to decode
							$decoded = json_decode($candidate, true);
							if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
								// Check if this looks like an issues array (not schema data)
								$isSchemaData = false;
								if (isset($decoded[0]) && is_array($decoded[0])) {
									$firstItem = $decoded[0];
									if (isset($firstItem['name']) || isset($firstItem['columns']) || isset($firstItem['indexes'])) {
										$isSchemaData = true;
									}
								}
								// If it's not schema data and looks like issues (or is empty), return it
								if (!$isSchemaData && (empty($decoded) || (isset($decoded[0]) && (isset($decoded[0]['table']) || isset($decoded[0]['issue_type']))))) {
									// Make sure it doesn't look like code (check for PHP patterns)
									if (!preg_match('/use\s+[A-Z]|<\?php|\$[a-zA-Z_]\s*=/', $candidate)) {
										return trim($candidate);
									}
								}
							}
							// Continue searching for the next array if this one was schema data
							$currentPos = $i + 1;
							break;
						}
					}
				}
			}
		}

		// Method 3: Try to find JSON object pattern { ... } (might be single issue)
		// Use non-greedy to prevent catastrophic backtracking
		if (preg_match('/\{[\s\S]*?\}/', $text, $matches)) {
			$candidate = trim($matches[0]);
			// Check size before processing
			if (strlen($candidate) > 50000) {
				return null;
			}
			// Validate it's proper JSON
			json_decode($candidate);
			if (json_last_error() === JSON_ERROR_NONE) {
				// Make sure it doesn't look like code
				if (!preg_match('/use\s+[A-Z]|<\?php|\$[a-zA-Z_]\s*=/', $candidate)) {
					return $candidate;
				}
			}
		}

		// Method 4: Try to find JSON array starting with [{
		// Use non-greedy to prevent catastrophic backtracking
		if (preg_match('/\[\s*\{[\s\S]*?\}\s*\]/', $text, $matches)) {
			$candidate = trim($matches[0]);
			// Check size before processing
			if (strlen($candidate) > 50000) {
				return null;
			}
			json_decode($candidate);
			if (json_last_error() === JSON_ERROR_NONE && !preg_match('/use\s+[A-Z]|<\?php|\$[a-zA-Z_]\s*=/', $candidate)) {
				return $candidate;
			}
		}

		// Method 5: Try to extract JSON from mixed text by finding balanced brackets
		$startPos = strpos($text, '[');
		if ($startPos !== false) {
			$depth = 0;
			$endPos = $startPos;
			$textLength = strlen($text);
			$maxIterations = min($textLength - $startPos, 200000); // Limit to 200KB max
			$iterations = 0;
			for ($i = $startPos; $i < $textLength && $iterations < $maxIterations; $i++) {
				$iterations++;
				if ($text[$i] === '[') {
					$depth++;
				} elseif ($text[$i] === ']') {
					$depth--;
					if ($depth === 0) {
						$endPos = $i + 1;
						break;
					}
				}
			}
			if ($depth === 0 && $endPos > $startPos) {
				$candidate = substr($text, $startPos, $endPos - $startPos);
				json_decode($candidate);
				if (json_last_error() === JSON_ERROR_NONE) {
					// Make sure it doesn't look like code
					if (!preg_match('/use\s+[A-Z]|<\?php|\$[a-zA-Z_]\s*=/', $candidate)) {
						return $candidate;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Normalize issue structure to ensure consistent format
	 */
	protected function normalizeIssueStructure(array $issue, string $defaultTable): ?array
	{
		// Validate required fields
		if (!isset($issue['title']) && !isset($issue['description'])) {
			return null;
		}

		// Map issue_type to valid enum values
		$issueType = $this->mapIssueTypeToEnum($issue['issue_type'] ?? $this->scanType);

		// Normalize category
		$category = $issue['category'] ?? null;
		$originalCategory = $category;

		if ($category) {
			$category = strtolower($category);
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

		// Validate category is one of the valid values
		$validCategories = ['security', 'quality', 'performance', 'best_practices', 'bug'];
		$isValidCategory = in_array($category, $validCategories);

		// If category is missing or invalid, try to detect from content
		if (!$isValidCategory || $category === null) {
			$detectedCategory = $this->detectCategoryFromContent($issue);
			if ($detectedCategory !== null) {
				$category = $detectedCategory;
			} else {
				// Default to 'quality' if cannot detect
				$category = 'quality';
			}
		} else {
			// Even if category is valid, check if it seems incorrect based on content
			// This helps catch cases like "missing index" being labeled as "security"
			$detectedCategory = $this->detectCategoryFromContent($issue);
			if ($detectedCategory !== null && $detectedCategory !== $category) {
				// Check if detected category is more specific/accurate
				// Performance issues are often mislabeled as security
				if ($detectedCategory === 'performance' && $category === 'security') {
					$category = $detectedCategory;
				}
				// Security issues should not be changed to other categories
				// But other misclassifications can be corrected
				elseif ($category !== 'security' && $detectedCategory !== 'security') {
					$category = $detectedCategory;
				}
			}
		}

		return [
			'table' => $issue['table'] ?? $defaultTable,
			'issue_type' => $issueType,
			'severity' => $this->normalizeSeverity($issue['severity'] ?? 'medium'),
			'category' => $category,
			'title' => $issue['title'] ?? 'Untitled Issue',
			'description' => $issue['description'] ?? '',
			'location' => is_array($issue['location'] ?? null) ? $issue['location'] : [],
			'suggestion' => $issue['suggestion'] ?? '',
		];
	}

	/**
	 * Normalize severity to valid values
	 */
	protected function normalizeSeverity(string $severity): string
	{
		$normalized = strtolower(trim($severity));
		$validSeverities = ['low', 'medium', 'high', 'critical'];

		if (in_array($normalized, $validSeverities)) {
			return $normalized;
		}

		// Map common variations
		$mapping = [
			'low' => 'low',
			'minor' => 'low',
			'info' => 'low',
			'medium' => 'medium',
			'moderate' => 'medium',
			'normal' => 'medium',
			'high' => 'high',
			'major' => 'high',
			'important' => 'high',
			'critical' => 'critical',
			'severe' => 'critical',
			'urgent' => 'critical',
		];

		return $mapping[$normalized] ?? 'medium';
	}

	/**
	 * Detect category from issue content (title, description, suggestion)
	 * Uses keyword matching to intelligently categorize issues
	 * 
	 * @param array $issue The issue array with title, description, suggestion
	 * @return string|null Detected category or null if cannot determine
	 */
	protected function detectCategoryFromContent(array $issue): ?string
	{
		// Combine all text fields for analysis
		$text = strtolower(
			($issue['title'] ?? '') . ' ' .
			($issue['description'] ?? '') . ' ' .
			($issue['suggestion'] ?? '')
		);

		// Performance keywords (highest priority - check first to avoid false positives)
		$performanceKeywords = [
			'index',
			'missing index',
			'frequently queried',
			'query performance',
			'slow query',
			'bottleneck',
			'optimization',
			'optimize',
			'performance',
			'query speed',
			'execution time',
			'full table scan',
			'sequential scan',
			'missing indexes',
			'add index',
			'create index',
			'index missing',
			'no index',
			'without index',
			'unindexed',
			'indexed column'
		];

		foreach ($performanceKeywords as $keyword) {
			if (stripos($text, $keyword) !== false) {
				return 'performance';
			}
		}

		// Security keywords
		$securityKeywords = [
			'sql injection',
			'injection',
			'xss',
			'cross-site scripting',
			'csrf',
			'cross-site request forgery',
			'authentication',
			'authorization',
			'password',
			'encryption',
			'sensitive data',
			'pii',
			'personal information',
			'data breach',
			'unauthorized access',
			'privilege escalation',
			'vulnerability',
			'security risk',
			'exposed',
			'unencrypted',
			'plain text password',
			'weak password',
			'password hash',
			'credential',
			'api key',
			'secret'
		];

		foreach ($securityKeywords as $keyword) {
			if (stripos($text, $keyword) !== false) {
				return 'security';
			}
		}

		// Bug keywords
		$bugKeywords = [
			'orphaned',
			'broken',
			'missing foreign key',
			'invalid reference',
			'incorrect',
			'wrong',
			'invalid',
			'data corruption',
			'integrity',
			'broken relationship',
			'dangling',
			'null reference',
			'broken link'
		];

		foreach ($bugKeywords as $keyword) {
			if (stripos($text, $keyword) !== false) {
				return 'bug';
			}
		}

		// Best practices keywords
		$bestPracticesKeywords = [
			'foreign key',
			'relationship',
			'normalization',
			'denormalization',
			'convention',
			'naming convention',
			'standard',
			'best practice',
			'recommended',
			'should have',
			'good practice',
			'follow convention'
		];

		foreach ($bestPracticesKeywords as $keyword) {
			if (stripos($text, $keyword) !== false) {
				return 'best_practices';
			}
		}

		// Quality keywords (catch-all for data quality issues)
		$qualityKeywords = [
			'data type',
			'constraint',
			'validation',
			'nullability',
			'nullable',
			'not null',
			'default value',
			'data quality',
			'inconsistent',
			'naming',
			'column name',
			'table name'
		];

		foreach ($qualityKeywords as $keyword) {
			if (stripos($text, $keyword) !== false) {
				return 'quality';
			}
		}

		// Default to null if cannot determine
		return null;
	}

	/**
	 * Save issues to database
	 */
	protected function saveIssuesToDatabase(array $compiledResults): int
	{
		$savedCount = 0;
		$scanHistory = DatabaseScanHistory::where('scan_id', $this->scanId)->first();

		if (!$scanHistory) {
			return 0;
		}

		foreach ($compiledResults['issues'] ?? [] as $issue) {
			try {
				// Map issue_type to valid enum values
				$issueType = $this->mapIssueTypeToEnum($issue['issue_type'] ?? $this->scanType);

				DatabaseScanIssue::create([
					'scan_history_id' => $scanHistory->id,
					'user_id' => $this->userId,
					'table_name' => $issue['table'] ?? 'unknown',
					'issue_type' => $issueType,
					'severity' => $issue['severity'] ?? 'medium',
					'title' => $issue['title'] ?? 'Untitled Issue',
					'description' => $issue['description'] ?? '',
					'location' => $issue['location'] ?? [],
					'suggestion' => $issue['suggestion'] ?? '',
				]);
				$savedCount++;
			} catch (\Exception $e) {
				// Continue with next issue if save fails
			}
		}

		return $savedCount;
	}

	/**
	 * Map issue_type to valid enum values
	 * Valid enum values are: 'schema', 'data'
	 */
	protected function mapIssueTypeToEnum(string $issueType): string
	{
		// Normalize to lowercase for comparison
		$normalized = strtolower(trim($issueType));

		// If already a valid enum value, return it
		if (in_array($normalized, ['schema', 'data'])) {
			return $normalized;
		}

		// Map common issue types to valid enum values
		$mapping = [
			'referential integrity' => 'data',
			'referential' => 'data',
			'integrity' => 'data',
			'foreign key' => 'data',
			'constraint' => 'schema',
			'index' => 'schema',
			'column' => 'schema',
			'table' => 'schema',
			'data quality' => 'data',
			'data consistency' => 'data',
			'data validation' => 'data',
		];

		// Check if we have a mapping
		if (isset($mapping[$normalized])) {
			return $mapping[$normalized];
		}

		// Default: if it contains 'data' or 'integrity', map to 'data', otherwise 'schema'
		if (stripos($normalized, 'data') !== false || stripos($normalized, 'integrity') !== false) {
			return 'data';
		}

		return 'schema';
	}

	/**
	 * Update scan state in cache
	 */
	protected function updateScanState(array $data): void
	{
		$cacheKey = "overlord_database_scan_{$this->scanId}_state";
		$currentState = Cache::get($cacheKey, []);
		$updatedState = array_merge($currentState, $data);
		Cache::put($cacheKey, $updatedState, now()->addHours(2));
	}

	/**
	 * Update scan history in database
	 */
	protected function updateScanHistory(array $data): void
	{
		try {
			$scanHistory = DatabaseScanHistory::where('scan_id', $this->scanId)->first();
			if ($scanHistory) {
				$scanHistory->update($data);
			}
		} catch (\Exception $e) {
			// Silently fail if update fails
		}
	}
}