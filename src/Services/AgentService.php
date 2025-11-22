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
	 * @return array ['success' => bool, 'new_content' => string|null, 'error' => string|null, 'retries' => int]
	 */
	public function generateFixes(AgentSession $session, string $filePath, string $fileContent, array $issue, string $contextCode): array
	{
		try {
			$maxRetries = 3;
			$retryCount = 0;
			$lastPrompt = $this->buildFixPrompt($filePath, $fileContent, $issue, $contextCode);
			$lastAttempt = null;
			$lastValidationErrors = [];

			while ($retryCount < $maxRetries) {
				$retryCount++;
				
				// Build prompt (use retry prompt if this is a retry)
				$prompt = ($retryCount === 1) 
					? $lastPrompt 
					: $this->buildRetryPrompt($lastPrompt, $lastAttempt ?? '', $lastValidationErrors, $retryCount);

				// Call AI service
				$aiResult = $this->aiService->chat(
					$prompt,
					[],
					null,
					['session_id' => $session->id, 'file_path' => $filePath],
					'agent_fix',
					['issue' => $issue, 'file_path' => $filePath, 'attempt' => $retryCount]
				);

				if (!$aiResult['success']) {
					$this->addLog($session, 'warning', "AI service failed on attempt {$retryCount}: " . ($aiResult['error'] ?? 'Unknown error'));
					if ($retryCount >= $maxRetries) {
						return [
							'success' => false,
							'new_content' => null,
							'error' => $aiResult['error'] ?? 'AI service failed after ' . $maxRetries . ' attempts',
							'retries' => $retryCount,
						];
					}
					continue; // Retry
				}

				$aiResponse = $aiResult['message'] ?? '';
				$lastAttempt = $aiResponse;

				// Extract code from AI response
				$extractedCode = $this->extractFixedCode($aiResponse, $fileContent);

				if (!$extractedCode) {
					$this->addLog($session, 'warning', "Could not extract code from AI response on attempt {$retryCount}");
					if ($retryCount >= $maxRetries) {
						return [
							'success' => false,
							'new_content' => null,
							'error' => 'Could not extract fixed code from AI response after ' . $maxRetries . ' attempts',
							'retries' => $retryCount,
						];
					}
					continue; // Retry
				}

				// Clean extracted code
				$cleanedCode = $this->cleanExtractedCode($extractedCode);

				// Check for placeholder text and attempt repair
				$hasPlaceholder = false;
				$placeholderPatterns = [
					'/\.\.\.\s*(?:the\s+)?rest\s+of\s+(?:the\s+)?code/i',
					'/\.\.\.\s*(?:rest|remaining|remaining\s+code)/i',
					'/\/\/\s*\.\.\./',
					'/\/\*\s*\.\.\.\s*\*\//',
				];
				
				foreach ($placeholderPatterns as $pattern) {
					if (preg_match($pattern, $cleanedCode)) {
						$hasPlaceholder = true;
						break;
					}
				}

				if ($hasPlaceholder) {
					$this->addLog($session, 'info', "Placeholder text detected on attempt {$retryCount}, attempting repair");
					$cleanedCode = $this->repairCodeWithOriginal($cleanedCode, $fileContent);
				}

				// Validate code
				$validationResult = $this->validateCode($filePath, $fileContent, $cleanedCode);

				if ($validationResult['valid']) {
					// Validation passed!
					if ($retryCount > 1) {
						$this->addLog($session, 'success', "Code validation passed on attempt {$retryCount}");
					}
					return [
						'success' => true,
						'new_content' => $cleanedCode,
						'error' => null,
						'retries' => $retryCount,
					];
				}

				// Validation failed - log and retry
				$lastValidationErrors = $validationResult['errors'] ?? [];
				$errorSummary = implode('; ', array_slice($lastValidationErrors, 0, 3));
				$this->addLog($session, 'warning', "Code validation failed on attempt {$retryCount} ({$validationResult['stage']}): {$errorSummary}", [
					'errors' => $lastValidationErrors,
					'stage' => $validationResult['stage'],
				]);

				if ($retryCount >= $maxRetries) {
					// All retries exhausted
					return [
						'success' => false,
						'new_content' => null,
						'error' => 'Code validation failed after ' . $maxRetries . ' attempts. Errors: ' . implode('; ', $lastValidationErrors),
						'retries' => $retryCount,
					];
				}
			}

			// Should not reach here, but just in case
			return [
				'success' => false,
				'new_content' => null,
				'error' => 'Failed to generate valid fix after ' . $maxRetries . ' attempts',
				'retries' => $retryCount,
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
				'retries' => 0,
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
			// Final validation before applying
			$validationResult = $this->validateCode($filePath, $originalContent, $newContent);
			if (!$validationResult['valid']) {
				$errorMsg = 'Final validation failed: ' . implode('; ', $validationResult['errors']);
				$this->addLog($session, 'error', "Final validation failed for {$filePath}: {$errorMsg}", [
					'errors' => $validationResult['errors'],
					'stage' => $validationResult['stage'],
				]);
				return ['success' => false, 'error' => $errorMsg];
			}

			if ($session->auto_apply) {
				// Auto-apply mode - apply immediately
				$writeResult = $this->fileEditService->writeFile($filePath, $newContent, true);
				if (!$writeResult['success']) {
					$errorMsg = 'Failed to apply fix: ' . $writeResult['error'];
					if (isset($writeResult['line']) && $writeResult['line'] !== null) {
						$errorMsg .= ' on line ' . $writeResult['line'];
					}
					if (isset($writeResult['context']) && $writeResult['context']) {
						$errorMsg .= "\nContext:\n" . $writeResult['context'];
					}
					$this->addLog($session, 'error', "Failed to apply fix to {$filePath}: {$errorMsg}");
					return ['success' => false, 'error' => $errorMsg];
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
				// Review mode - create pending change (still validate before storing)
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
	 * Validate code using multiple stages
	 *
	 * @param string $filePath
	 * @param string $originalContent
	 * @param string $newContent
	 * @return array ['valid' => bool, 'errors' => array, 'stage' => string|null]
	 */
	protected function validateCode(string $filePath, string $originalContent, string $newContent): array
	{
		$errors = [];
		
		// Stage 1: Structure validation
		$structureErrors = $this->validateStructure($newContent);
		if (!empty($structureErrors)) {
			$errors = array_merge($errors, $structureErrors);
			return [
				'valid' => false,
				'errors' => $errors,
				'stage' => 'structure',
			];
		}
		
		// Stage 2: PHP syntax validation
		$syntaxCheck = $this->fileEditService->validatePhpSyntaxFromContent($newContent);
		if (!$syntaxCheck['valid']) {
			$errorMsg = 'PHP syntax error: ' . ($syntaxCheck['error'] ?? 'Unknown error');
			if (isset($syntaxCheck['line']) && $syntaxCheck['line'] !== null) {
				$errorMsg .= ' on line ' . $syntaxCheck['line'];
			}
			if (isset($syntaxCheck['context']) && $syntaxCheck['context']) {
				$errorMsg .= "\nContext:\n" . $syntaxCheck['context'];
			}
			$errors[] = $errorMsg;
			return [
				'valid' => false,
				'errors' => $errors,
				'stage' => 'syntax',
			];
		}
		
		// Stage 3: Larastan validation (only check for errors, not warnings)
		try {
			$larastanResult = $this->phpstanService->validateContent($newContent, 1);
			if (!$larastanResult['valid']) {
				$larastanErrors = $larastanResult['errors'] ?? [];
				foreach ($larastanErrors as $error) {
					$errorMsg = 'Larastan error';
					if (isset($error['message'])) {
						$errorMsg .= ': ' . $error['message'];
					}
					if (isset($error['line'])) {
						$errorMsg .= ' on line ' . $error['line'];
					}
					$errors[] = $errorMsg;
				}
				return [
					'valid' => false,
					'errors' => $errors,
					'stage' => 'larastan',
				];
			}
		} catch (\Exception $e) {
			// Larastan validation failed, but don't block on it
			Log::warning('AgentService: Larastan validation failed', [
				'error' => $e->getMessage(),
			]);
		}
		
		return [
			'valid' => true,
			'errors' => [],
			'stage' => null,
		];
	}

	/**
	 * Validate code structure (PHP tag, no placeholders, matching braces)
	 *
	 * @param string $code
	 * @return array Array of error messages
	 */
	protected function validateStructure(string $code): array
	{
		$errors = [];
		
		// Check for <?php tag (for PHP files)
		if (!str_starts_with(trim($code), '<?php') && !str_starts_with(trim($code), '<?=')) {
			// Check if it looks like PHP code
			if (preg_match('/\b(namespace|class|function|use|return|public|private|protected)\b/', $code)) {
				$errors[] = 'Missing <?php tag at the beginning of the file';
			}
		}
		
		// Check for placeholder text
		$placeholderPatterns = [
			'/\.\.\.\s*(?:the\s+)?rest\s+of\s+(?:the\s+)?code/i',
			'/\.\.\.\s*(?:rest|remaining|remaining\s+code)/i',
			'/\/\/\s*\.\.\./',
			'/\/\*\s*\.\.\.\s*\*\//',
		];
		
		foreach ($placeholderPatterns as $pattern) {
			if (preg_match($pattern, $code)) {
				$errors[] = 'Placeholder text detected in code (e.g., "... rest of code")';
				break;
			}
		}
		
		// Check for matching braces (basic check)
		$openBraces = substr_count($code, '{');
		$closeBraces = substr_count($code, '}');
		if ($openBraces !== $closeBraces) {
			$errors[] = "Unmatched braces: {$openBraces} opening, {$closeBraces} closing";
		}
		
		// Check for matching parentheses
		$openParens = substr_count($code, '(');
		$closeParens = substr_count($code, ')');
		if ($openParens !== $closeParens) {
			$errors[] = "Unmatched parentheses: {$openParens} opening, {$closeParens} closing";
		}
		
		return $errors;
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

		$prompt .= "CRITICAL REQUIREMENTS:\n";
		$prompt .= "- Return ONLY complete, valid PHP code in a ```php code block\n";
		$prompt .= "- Do NOT include placeholder text, comments like '... rest of code', or explanations\n";
		$prompt .= "- The code block MUST start with `<?php` and contain the COMPLETE file\n";
		$prompt .= "- Ensure all braces are matched and the code is syntactically valid\n";
		$prompt .= "- Only fix the specific issue mentioned. Do not make other changes.\n";
		$prompt .= "- Ensure the code follows Laravel best practices.";

		return $prompt;
	}

	/**
	 * Build retry prompt with validation errors
	 *
	 * @param string $originalPrompt
	 * @param string $previousAttempt
	 * @param array $validationErrors
	 * @param int $attemptNumber
	 * @return string
	 */
	protected function buildRetryPrompt(string $originalPrompt, string $previousAttempt, array $validationErrors, int $attemptNumber): string
	{
		$prompt = $originalPrompt;
		
		$prompt .= "\n\n--- PREVIOUS ATTEMPT FAILED VALIDATION ---\n";
		$prompt .= "Attempt #{$attemptNumber} had the following validation errors:\n";
		foreach ($validationErrors as $error) {
			$prompt .= "- {$error}\n";
		}
		
		if ($attemptNumber === 2) {
			$prompt .= "\nIMPORTANT: The previous attempt had validation errors. Please ensure:\n";
			$prompt .= "- The code is complete (no placeholders, no '... rest of code')\n";
			$prompt .= "- The code starts with <?php\n";
			$prompt .= "- All braces and parentheses are properly matched\n";
			$prompt .= "- The code is syntactically valid PHP\n";
		} elseif ($attemptNumber >= 3) {
			$prompt .= "\nCRITICAL: This is the final attempt. The code MUST be:\n";
			$prompt .= "- Complete and valid PHP code\n";
			$prompt .= "- No placeholders, no explanations, no comments like '... rest of code'\n";
			$prompt .= "- Must start with <?php\n";
			$prompt .= "- All syntax must be correct\n";
			$prompt .= "- Return ONLY the code block with the complete file\n";
		}
		
		$prompt .= "\nPlease provide the corrected code:";
		
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
		// Try multiple patterns to extract code from markdown code blocks
		$patterns = [
			'/```php\s*\n(.*?)\n```/s',  // ```php ... ```
			'/```\s*php\s*\n(.*?)\n```/s',  // ``` php ... ```
			'/```\s*\n(.*?)\n```/s',  // ``` ... ``` (no language)
			'/```(?:php)?\s*\n(.*?)\n```/s',  // Generic
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $aiResponse, $matches)) {
				$extracted = trim($matches[1]);
				if (!empty($extracted)) {
					return $extracted;
				}
			}
		}

		// If no code block found, try to use the response as-is (might be just code)
		$trimmed = trim($aiResponse);
		if (strlen($trimmed) > 100 && str_contains($trimmed, '<?php')) {
			return $trimmed;
		}

		// Fallback: return null (no fix applied)
		return null;
	}

	/**
	 * Clean extracted code - remove placeholder text, ensure proper structure
	 *
	 * @param string $code
	 * @return string
	 */
	protected function cleanExtractedCode(string $code): string
	{
		$code = trim($code);
		
		// Remove markdown code block markers if still present
		$code = preg_replace('/^```(?:php)?\s*\n?/m', '', $code);
		$code = preg_replace('/\n?```\s*$/m', '', $code);
		
		// Remove explanatory text before <?php tag
		if (str_contains($code, '<?php')) {
			$phpStart = strpos($code, '<?php');
			if ($phpStart > 0) {
				$beforePhp = substr($code, 0, $phpStart);
				$beforePhpTrimmed = trim($beforePhp);
				// If before <?php is not a comment, remove it
				if (!empty($beforePhpTrimmed) && !preg_match('/^(\/\/|\/\*|\*|\#)/m', $beforePhpTrimmed)) {
					$code = substr($code, $phpStart);
				}
			}
		}
		
		// Remove placeholder text patterns
		$placeholderPatterns = [
			'/\.\.\.\s*(?:the\s+)?rest\s+of\s+(?:the\s+)?code/i',
			'/\.\.\.\s*(?:rest|remaining|remaining\s+code)/i',
			'/\/\/\s*\.\.\./',
			'/\/\*\s*\.\.\.\s*\*\//',
			'/\/\/\s*TODO:?\s*\.\.\./i',
			'/\/\/\s*FIXME:?\s*\.\.\./i',
			'/\/\/\s*placeholder/i',
		];
		
		foreach ($placeholderPatterns as $pattern) {
			$code = preg_replace($pattern, '', $code);
		}
		
		// Remove trailing explanatory text after last closing brace
		$lastBrace = strrpos($code, '}');
		if ($lastBrace !== false && $lastBrace < strlen($code) - 10) {
			$afterCode = substr($code, $lastBrace + 1);
			$afterCodeTrimmed = trim($afterCode);
			// If there's substantial text after last brace that's not a comment, remove it
			if (!empty($afterCodeTrimmed) &&
				!str_contains($afterCodeTrimmed, '}') &&
				!str_contains($afterCodeTrimmed, ';') &&
				!preg_match('/^(\/\/|\/\*|\*|\#)/m', $afterCodeTrimmed)) {
				$code = substr($code, 0, $lastBrace + 1);
			}
		}
		
		// Normalize line endings
		$code = str_replace(["\r\n", "\r"], "\n", $code);
		
		// Ensure <?php tag is present for PHP files
		if (!str_starts_with(trim($code), '<?php') && !str_starts_with(trim($code), '<?=')) {
			// Check if it looks like PHP code
			if (preg_match('/\b(namespace|class|function|use|return|public|private|protected)\b/', $code)) {
				$code = "<?php\n\n" . $code;
			}
		}
		
		return trim($code);
	}

	/**
	 * Repair code by merging with original when placeholder text is detected
	 *
	 * @param string $newCode
	 * @param string $originalCode
	 * @return string
	 */
	protected function repairCodeWithOriginal(string $newCode, string $originalCode): string
	{
		// Detect placeholder patterns
		$hasPlaceholder = false;
		$placeholderPatterns = [
			'/\.\.\.\s*(?:the\s+)?rest\s+of\s+(?:the\s+)?code/i',
			'/\.\.\.\s*(?:rest|remaining|remaining\s+code)/i',
			'/\/\/\s*\.\.\./',
			'/\/\*\s*\.\.\.\s*\*\//',
		];
		
		foreach ($placeholderPatterns as $pattern) {
			if (preg_match($pattern, $newCode)) {
				$hasPlaceholder = true;
				break;
			}
		}
		
		if (!$hasPlaceholder) {
			return $newCode; // No placeholder, return as-is
		}
		
		// Try to identify where placeholder appears
		$newLines = explode("\n", $newCode);
		$originalLines = explode("\n", $originalCode);
		
		$repaired = [];
		$originalIndex = 0;
		
		foreach ($newLines as $line) {
			$lineTrimmed = trim($line);
			
			// Check if this line contains placeholder
			$isPlaceholder = false;
			foreach ($placeholderPatterns as $pattern) {
				if (preg_match($pattern, $line)) {
					$isPlaceholder = true;
					break;
				}
			}
			
			if ($isPlaceholder) {
				// Replace placeholder with corresponding original code
				// Try to find matching context in original
				$contextBefore = implode("\n", array_slice($repaired, -3)); // Last 3 lines
				
				// Find similar context in original
				$foundMatch = false;
				for ($i = $originalIndex; $i < count($originalLines); $i++) {
					$originalContext = implode("\n", array_slice($originalLines, max(0, $i - 3), 3));
					if (similar_text($contextBefore, $originalContext) > 50) {
						// Found similar context, use remaining original code
						$remainingOriginal = array_slice($originalLines, $i);
						$repaired = array_merge($repaired, $remainingOriginal);
						$foundMatch = true;
						break;
					}
				}
				
				if (!$foundMatch) {
					// Couldn't find match, just skip placeholder line
					continue;
				}
				
				break; // We've merged the rest, stop processing new lines
			} else {
				$repaired[] = $line;
			}
		}
		
		return implode("\n", $repaired);
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

