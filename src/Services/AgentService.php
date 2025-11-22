<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Spiderwisp\LaravelOverlord\Models\AgentSession;
use Spiderwisp\LaravelOverlord\Models\AgentLog;
use Spiderwisp\LaravelOverlord\Models\AgentFileChange;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AgentService
{
	protected PhpstanService $phpstanService;
	protected RealAiService $aiService;
	protected FileEditService $fileEditService;

	public function __construct(
		PhpstanService $phpstanService,
		RealAiService $aiService,
		FileEditService $fileEditService
	) {
		$this->phpstanService = $phpstanService;
		$this->aiService = $aiService;
		$this->fileEditService = $fileEditService;
	}

	/**
	 * Run the agent - main orchestration loop
	 *
	 * @param AgentSession $session
	 * @return void
	 */
	public function runAgent(AgentSession $session): void
	{
		try {
			Log::info('AgentService: Starting agent', ['session_id' => $session->id]);
			
			$session->update(['status' => 'running']);
			$this->addLog($session, 'info', 'Agent started', [
				'larastan_level' => $session->larastan_level,
				'auto_apply' => $session->auto_apply,
			]);

			Log::info('AgentService: Agent status updated to running', ['session_id' => $session->id]);

			while ($session->current_iteration < $session->max_iterations) {
				// Check if session was stopped or paused
				$session->refresh();
				if ($session->status === 'stopped') {
					$this->addLog($session, 'info', 'Agent stopped by user');
					break;
				}

				if ($session->status === 'paused') {
					$this->addLog($session, 'info', 'Agent paused - waiting for resume');
					// Wait for resume
					$waited = 0;
					while ($session->status === 'paused' && $waited < 300) { // Wait up to 5 minutes
						sleep(1);
						$session->refresh();
						$waited++;
					}
					if ($session->status === 'paused') {
						$this->addLog($session, 'warning', 'Agent paused for too long, stopping');
						$session->update(['status' => 'stopped']);
						break;
					}
				}

				$session->increment('current_iteration');
				$iterationNum = $session->current_iteration;
				$this->addLog($session, 'info', "Starting iteration {$iterationNum}");

				Log::info('AgentService: Starting iteration', [
					'session_id' => $session->id,
					'iteration' => $iterationNum,
				]);

				// Run Larastan scan
				$this->addLog($session, 'info', "Running Larastan scan (level {$session->larastan_level})...");
				$scanResult = $this->runLarastanScan($session);
				if (!$scanResult['success']) {
					$this->addLog($session, 'error', 'Larastan scan failed: ' . $scanResult['error']);
					$session->update([
						'status' => 'failed',
						'error_message' => $scanResult['error'],
					]);
					break;
				}

			$issues = $scanResult['issues'] ?? [];
			$totalIssues = count($issues);

			$session->increment('total_scans');
			$session->increment('total_issues_found', $totalIssues);

			Log::info('AgentService: Scan results', [
				'session_id' => $session->id,
				'iteration' => $session->current_iteration,
				'issues_found' => $totalIssues,
			]);

			$this->addLog($session, 'scan_complete', "Larastan scan completed. Found {$totalIssues} issues", [
				'iteration' => $session->current_iteration,
				'issues_count' => $totalIssues,
			]);

				// If no issues found, we're done!
				if ($totalIssues === 0) {
					$this->addLog($session, 'success', 'All Larastan issues resolved!');
					$session->update(['status' => 'completed']);
					break;
				}

				// Analyze and fix issues
				$fixesApplied = 0;
				$issueNum = 0;
				foreach ($issues as $issue) {
					$issueNum++;
					$this->addLog($session, 'info', "Processing issue {$issueNum}/{$totalIssues}: {$issue['file']} (line {$issue['line']})");
					
					Log::info('AgentService: Processing issue', [
						'session_id' => $session->id,
						'iteration' => $session->current_iteration,
						'issue_num' => $issueNum,
						'total_issues' => $totalIssues,
						'file' => $issue['file'] ?? 'unknown',
						'line' => $issue['line'] ?? null,
					]);
					
					$fixResult = $this->processIssue($session, $issue);
					if ($fixResult['success']) {
						$fixesApplied++;
						$this->addLog($session, 'success', "Successfully processed issue {$issueNum}");
					} else {
						$this->addLog($session, 'warning', "Failed to process issue {$issueNum}: " . ($fixResult['error'] ?? 'Unknown error'));
					}
				}

				$session->increment('total_issues_fixed', $fixesApplied);
				$this->addLog($session, 'info', "Applied {$fixesApplied} fixes in this iteration");

				// If no fixes were applied, we might be stuck
				if ($fixesApplied === 0) {
					$this->addLog($session, 'warning', 'No fixes were applied. Agent may be unable to fix remaining issues.');
					// Continue anyway - might be able to fix in next iteration
				}

				// Small delay between iterations
				sleep(1);
			}

			if ($session->current_iteration >= $session->max_iterations) {
				$this->addLog($session, 'warning', 'Reached maximum iterations limit');
				$session->update(['status' => 'completed']);
			}
		} catch (\Exception $e) {
			Log::error('AgentService: Agent execution failed', [
				'session_id' => $session->id,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			$this->addLog($session, 'error', 'Agent execution failed: ' . $e->getMessage());
			$session->update([
				'status' => 'failed',
				'error_message' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Run Larastan scan
	 *
	 * @param AgentSession $session
	 * @return array ['success' => bool, 'issues' => array, 'error' => string|null]
	 */
	public function runLarastanScan(AgentSession $session): array
	{
		try {
			Log::info('AgentService: Starting Larastan scan', [
				'session_id' => $session->id,
				'level' => $session->larastan_level,
			]);

			$config = [
				'level' => $session->larastan_level,
				'paths' => ['app'], // Default to app directory
			];

			$this->addLog($session, 'info', "Executing Larastan with level {$session->larastan_level} on app directory...");
			$results = $this->phpstanService->analyze($config);
			
			Log::info('AgentService: Larastan scan completed', [
				'session_id' => $session->id,
				'has_errors' => isset($results['errors']),
				'error_count' => isset($results['errors']) ? count($results['errors']) : 0,
			]);

			if (!isset($results['errors']) || !is_array($results['errors'])) {
				return [
					'success' => false,
					'issues' => [],
					'error' => 'Invalid Larastan results format',
				];
			}

			// Convert Larastan errors to our issue format
			$issues = [];
			foreach ($results['errors'] as $error) {
				$issues[] = [
					'file' => $error['file'] ?? '',
					'line' => $error['line'] ?? null,
					'message' => $error['message'] ?? '',
					'rule' => $error['rule'] ?? null,
					'tip' => $error['tip'] ?? null,
					'severity' => $error['severity'] ?? 'medium',
				];
			}

			return [
				'success' => true,
				'issues' => $issues,
				'error' => null,
			];
		} catch (\Exception $e) {
			Log::error('AgentService: Larastan scan failed', [
				'session_id' => $session->id,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'issues' => [],
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Process a single issue - analyze and generate fix
	 *
	 * @param AgentSession $session
	 * @param array $issue
	 * @return array ['success' => bool, 'error' => string|null]
	 */
	public function processIssue(AgentSession $session, array $issue): array
	{
		try {
			$filePath = $issue['file'] ?? '';
			$line = $issue['line'] ?? null;
			$message = $issue['message'] ?? '';

			if (empty($filePath)) {
				return ['success' => false, 'error' => 'No file path in issue'];
			}

			// Read the file
			$readResult = $this->fileEditService->readFile($filePath);
			if (!$readResult['success']) {
				$this->addLog($session, 'warning', "Cannot read file {$filePath}: " . $readResult['error']);
				return ['success' => false, 'error' => $readResult['error']];
			}

			$fileContent = $readResult['content'];
			$fileLines = explode("\n", $fileContent);

			// Get context around the issue
			$contextStart = max(0, ($line ?? 1) - 10);
			$contextEnd = min(count($fileLines), ($line ?? 1) + 10);
			$contextLines = array_slice($fileLines, $contextStart, $contextEnd - $contextStart);
			$contextCode = implode("\n", $contextLines);

			// Use AI to analyze and generate fix
			$fixResult = $this->generateFixes($session, $filePath, $fileContent, $issue, $contextCode);
			if (!$fixResult['success']) {
				return $fixResult;
			}

			$newContent = $fixResult['new_content'] ?? null;
			if (!$newContent) {
				return ['success' => false, 'error' => 'No fix generated'];
			}

			// Apply the fix
			return $this->applyFixes($session, $filePath, $fileContent, $newContent, $issue);
		} catch (\Exception $e) {
			Log::error('AgentService: Failed to process issue', [
				'session_id' => $session->id,
				'issue' => $issue,
				'error' => $e->getMessage(),
			]);

			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Use AI to generate fixes for an issue
	 *
	 * @param AgentSession $session
	 * @param string $filePath
	 * @param string $fileContent
	 * @param array $issue
	 * @param string $contextCode
	 * @return array ['success' => bool, 'new_content' => string|null, 'error' => string|null]
	 */
	public function generateFixes(AgentSession $session, string $filePath, string $fileContent, array $issue, string $contextCode): array
	{
		try {
			$line = $issue['line'] ?? null;
			$message = $issue['message'] ?? '';
			$rule = $issue['rule'] ?? null;

			// Build AI prompt
			$prompt = $this->buildFixPrompt($filePath, $fileContent, $issue, $contextCode);

			// Call AI service
			$aiResult = $this->aiService->chat(
				$prompt,
				[],
				null,
				['session_id' => $session->id, 'file_path' => $filePath],
				'agent_fix',
				['issue' => $issue, 'file_path' => $filePath]
			);

			if (!$aiResult['success']) {
				return [
					'success' => false,
					'new_content' => null,
					'error' => $aiResult['error'] ?? 'AI service failed',
				];
			}

			$aiResponse = $aiResult['message'] ?? '';

			// Extract code from AI response
			$newContent = $this->extractFixedCode($aiResponse, $fileContent);

			if (!$newContent) {
				return [
					'success' => false,
					'new_content' => null,
					'error' => 'Could not extract fixed code from AI response',
				];
			}

			return [
				'success' => true,
				'new_content' => $newContent,
				'error' => null,
			];
		} catch (\Exception $e) {
			Log::error('AgentService: Failed to generate fixes', [
				'session_id' => $session->id,
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'new_content' => null,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Apply fixes to a file
	 *
	 * @param AgentSession $session
	 * @param string $filePath
	 * @param string $originalContent
	 * @param string $newContent
	 * @param array $issue
	 * @return array ['success' => bool, 'error' => string|null]
	 */
	public function applyFixes(AgentSession $session, string $filePath, string $originalContent, string $newContent, array $issue): array
	{
		try {
			if ($session->auto_apply) {
				// Auto-apply mode - apply immediately
				$writeResult = $this->fileEditService->writeFile($filePath, $newContent, true);
				if (!$writeResult['success']) {
					$this->addLog($session, 'error', "Failed to apply fix to {$filePath}: " . $writeResult['error']);
					return ['success' => false, 'error' => $writeResult['error']];
				}

				$this->addLog($session, 'fix_applied', "Applied fix to {$filePath}", [
					'file_path' => $filePath,
					'backup_path' => $writeResult['backup_path'],
				]);

				// Create file change record
				AgentFileChange::create([
					'agent_session_id' => $session->id,
					'file_path' => $filePath,
					'original_content' => $originalContent,
					'new_content' => $newContent,
					'status' => 'applied',
					'backup_path' => $writeResult['backup_path'],
					'change_summary' => [
						'issue_message' => $issue['message'] ?? '',
						'issue_line' => $issue['line'] ?? null,
					],
				]);

				return ['success' => true, 'error' => null];
			} else {
				// Review mode - create pending change
				$fileChange = AgentFileChange::create([
					'agent_session_id' => $session->id,
					'file_path' => $filePath,
					'original_content' => $originalContent,
					'new_content' => $newContent,
					'status' => 'pending',
					'change_summary' => [
						'issue_message' => $issue['message'] ?? '',
						'issue_line' => $issue['line'] ?? null,
					],
				]);

				$this->addLog($session, 'fix_generated', "Generated fix for {$filePath} (pending approval)", [
					'file_path' => $filePath,
					'change_id' => $fileChange->id,
				]);

				return ['success' => true, 'error' => null];
			}
		} catch (\Exception $e) {
			Log::error('AgentService: Failed to apply fixes', [
				'session_id' => $session->id,
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Build AI prompt for fix generation
	 *
	 * @param string $filePath
	 * @param string $fileContent
	 * @param array $issue
	 * @param string $contextCode
	 * @return string
	 */
	protected function buildFixPrompt(string $filePath, string $fileContent, array $issue, string $contextCode): string
	{
		$line = $issue['line'] ?? null;
		$message = $issue['message'] ?? '';
		$rule = $issue['rule'] ?? null;
		$tip = $issue['tip'] ?? null;

		$prompt = "You are a code fixing assistant. Fix the following Larastan/PHPStan issue in a PHP file.\n\n";
		$prompt .= "File: {$filePath}\n";
		if ($line) {
			$prompt .= "Line: {$line}\n";
		}
		$prompt .= "Issue: {$message}\n";
		if ($rule) {
			$prompt .= "Rule: {$rule}\n";
		}
		if ($tip) {
			$prompt .= "Tip: {$tip}\n";
		}

		$prompt .= "\nContext around the issue:\n```php\n{$contextCode}\n```\n\n";

		$prompt .= "Full file content:\n```php\n{$fileContent}\n```\n\n";

		$prompt .= "Please provide the COMPLETE fixed file content in a code block. ";
		$prompt .= "Only fix the specific issue mentioned. Do not make other changes. ";
		$prompt .= "Ensure the code is valid PHP and follows Laravel best practices.";

		return $prompt;
	}

	/**
	 * Extract fixed code from AI response
	 *
	 * @param string $aiResponse
	 * @param string $originalContent
	 * @return string|null
	 */
	protected function extractFixedCode(string $aiResponse, string $originalContent): ?string
	{
		// Try to extract code from markdown code blocks
		if (preg_match('/```(?:php)?\s*\n(.*?)\n```/s', $aiResponse, $matches)) {
			return trim($matches[1]);
		}

		// If no code block found, try to use the response as-is (might be just code)
		$trimmed = trim($aiResponse);
		if (strlen($trimmed) > 100 && str_contains($trimmed, '<?php')) {
			return $trimmed;
		}

		// Fallback: return original content (no fix applied)
		return null;
	}

	/**
	 * Add log entry
	 *
	 * @param AgentSession $session
	 * @param string $type
	 * @param string $message
	 * @param array|null $data
	 * @return void
	 */
	protected function addLog(AgentSession $session, string $type, string $message, ?array $data = null): void
	{
		try {
			AgentLog::create([
				'agent_session_id' => $session->id,
				'type' => $type,
				'message' => $message,
				'data' => $data,
			]);

			// Also update cache for real-time updates
			Cache::put("agent_session_{$session->id}_last_log", [
				'type' => $type,
				'message' => $message,
				'data' => $data,
				'timestamp' => now()->toIso8601String(),
			], now()->addMinutes(5));
		} catch (\Exception $e) {
			Log::error('AgentService: Failed to add log', [
				'session_id' => $session->id,
				'error' => $e->getMessage(),
			]);
		}
	}
}

