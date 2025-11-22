<?php

namespace Spiderwisp\LaravelOverlord\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spiderwisp\LaravelOverlord\Models\ScanIssue;
use Spiderwisp\LaravelOverlord\Models\ScanHistory;
use Spiderwisp\LaravelOverlord\Services\PhpstanService;

class ScanPhpstanJob implements ShouldQueue
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	protected string $scanId;
	protected ?int $userId;
	protected array $config;

	/**
	 * Create a new job instance.
	 */
	public function __construct(string $scanId, ?int $userId, array $config = [])
	{
		$this->scanId = $scanId;
		$this->userId = $userId;
		$this->config = $config;
	}

	/**
	 * Execute the job.
	 */
	public function handle(PhpstanService $phpstanService): void
	{
		try {
			// Update scan state to show we're starting
			$this->updateScanState([
				'status' => 'scanning',
				'progress' => 0,
				'message' => 'Starting Larastan analysis...',
				'started_at' => now()->toIso8601String(),
			]);

			// Get or create scan history record (may already exist from controller)
			$scanHistory = ScanHistory::firstOrCreate(
				['scan_id' => $this->scanId],
				[
					'user_id' => $this->userId,
					'status' => 'scanning',
					'scan_mode' => 'phpstan',
					'selected_paths' => $this->config['paths'] ?? [],
					'started_at' => now(),
				]
			);
			
			// Update status if record already existed
			if ($scanHistory->wasRecentlyCreated === false) {
				$scanHistory->update([
					'status' => 'scanning',
					'started_at' => now(),
				]);
			}

			// Update scan state
			$this->updateScanState([
				'progress' => 10,
				'message' => 'Running Larastan analysis...',
			]);

			// Execute PHPStan
			$results = $phpstanService->analyze($this->config);

			// Update scan state
			$this->updateScanState([
				'progress' => 80,
				'message' => 'Processing results...',
			]);

			// Convert results to standardized format
			$compiledResults = $this->compileResults($results);

			// Store results in cache
			Cache::put("overlord_phpstan_{$this->scanId}_results", $compiledResults, now()->addHour());

			// Save issues to database
			$this->updateScanState([
				'progress' => 90,
				'message' => 'Saving issues to database...',
			]);
			$savedCount = $this->saveIssuesToDatabase($compiledResults);

			// Mark as completed
			$this->updateScanState([
				'status' => 'completed',
				'progress' => 100,
				'completed_at' => now()->toIso8601String(),
				'total_issues_found' => $compiledResults['summary']['total_issues'] ?? 0,
				'issues_saved' => $savedCount,
			]);

			// Update scan history
			$this->updateScanHistory([
				'status' => 'completed',
				'total_files' => $compiledResults['summary']['total_files'] ?? 0,
				'total_issues_found' => $compiledResults['summary']['total_issues'] ?? 0,
				'issues_saved' => $savedCount,
				'completed_at' => now(),
			]);

		} catch (\Exception $e) {
			$errorMessage = $e->getMessage();

			$this->updateScanState([
				'status' => 'failed',
				'error' => $errorMessage,
				'failed_at' => now()->toIso8601String(),
			]);

			// Update scan history
			$this->updateScanHistory([
				'status' => 'failed',
				'error' => $errorMessage,
				'completed_at' => now(),
			]);

			Log::error('PHPStan scan job failed', [
				'scan_id' => $this->scanId,
				'error' => $errorMessage,
				'trace' => $e->getTraceAsString(),
			]);
		}
	}

	/**
	 * Compile results into standardized format
	 */
	protected function compileResults(array $results): array
	{
		$files = $results['files'] ?? [];
		$summary = $results['summary'] ?? [];
		$totalIssues = 0;
		$filesWithIssues = 0;
		$bySeverity = [
			'critical' => 0,
			'high' => 0,
			'medium' => 0,
			'low' => 0,
		];

		foreach ($files as $file) {
			$fileIssues = $file['issues'] ?? [];
			if (!empty($fileIssues)) {
				$filesWithIssues++;
				$totalIssues += count($fileIssues);

				foreach ($fileIssues as $issue) {
					$severity = $issue['severity'] ?? 'medium';
					$bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
				}
			}
		}

		// Use total_files from PHPStan summary if available (includes all scanned files)
		// Otherwise fall back to count of files with errors
		$totalFilesScanned = $summary['total_files'] ?? count($files);

		return [
			'summary' => [
				'total_files' => $totalFilesScanned, // All files scanned
				'files_with_issues' => $filesWithIssues, // Only files with errors
				'total_issues' => $totalIssues,
				'by_severity' => $bySeverity,
			],
			'files' => $files,
		];
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
							'type' => 'phpstan',
							'severity' => $issue['severity'] ?? 'medium',
							'message' => $issue['message'] ?? '',
							'raw_data' => $issue,
						]);
						$savedCount++;
					} catch (\Exception $e) {
						Log::warning('Failed to save PHPStan issue to database', [
							'scan_id' => $this->scanId,
							'file' => $fileData['file'] ?? 'unknown',
							'error' => $e->getMessage(),
						]);
					}
				}
			}
		} catch (\Exception $e) {
			Log::error('Failed to save PHPStan issues to database', [
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
		$cacheKey = "overlord_phpstan_{$this->scanId}";
		$currentState = Cache::get($cacheKey, []);

		$newState = array_merge($currentState, $updates);
		Cache::put($cacheKey, $newState, now()->addHours(2));
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

