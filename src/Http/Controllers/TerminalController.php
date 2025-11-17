<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redis;
use Illuminate\Routing\Controller;
use Psy\Shell;
use Psy\Configuration;
use Symfony\Component\Console\Output\BufferedOutput;
use Spiderwisp\LaravelOverlord\Jobs\LogOverlordCommand;
use Spiderwisp\LaravelOverlord\Models\OverlordCommandLog;
use Spiderwisp\LaravelOverlord\Services\ModelDiscovery;
use Spiderwisp\LaravelOverlord\Services\ControllerDiscovery;
use Spiderwisp\LaravelOverlord\Services\ClassDiscovery;
use Spiderwisp\LaravelOverlord\Services\SensitiveDataRedactor;

class TerminalController extends Controller
{
	protected $modelDiscovery;
	protected $controllerDiscovery;
	protected $classDiscovery;

	public function __construct()
	{
		// Lazy-load discovery services only when needed
		$this->modelDiscovery = null;
		$this->controllerDiscovery = null;
		$this->classDiscovery = null;
	}

	/**
	 * Get model discovery service (lazy-loaded)
	 */
	protected function getModelDiscovery(): ModelDiscovery
	{
		if ($this->modelDiscovery === null) {
			$this->modelDiscovery = new ModelDiscovery();
		}
		return $this->modelDiscovery;
	}

	/**
	 * Get controller discovery service (lazy-loaded)
	 */
	protected function getControllerDiscovery(): ControllerDiscovery
	{
		if ($this->controllerDiscovery === null) {
			$this->controllerDiscovery = new ControllerDiscovery();
		}
		return $this->controllerDiscovery;
	}

	/**
	 * Get class discovery service (lazy-loaded)
	 */
	protected function getClassDiscovery(): ClassDiscovery
	{
		if ($this->classDiscovery === null) {
			$this->classDiscovery = new ClassDiscovery();
		}
		return $this->classDiscovery;
	}

	/**
	 * Execute a terminal command
	 */
	public function execute(Request $request)
	{
		// Track execution metrics
		$startTime = microtime(true);
		$startMemory = memory_get_peak_usage(true);
		$userId = Auth::id(); // Keep as int|null for job dispatch

		$cacheUserId = $userId ?? 'guest'; // Use 'guest' string for cache keys
		$outputContent = null;
		$errorMessage = null;
		$success = false;
		$outputType = null;
		$executionTime = null;
		$memoryUsage = null;

		try {
			$request->validate([
				'command' => 'required|string',
			]);

			$userCommand = $request->input('command');

			// Validate command is not a shell command (check BEFORE sanitization)
			if ($this->isShellCommand($userCommand)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => [
						"Shell commands cannot be executed in the terminal. The terminal only executes PHP code.\n\n" .
						"The command you tried to run: `{$userCommand}`\n\n" .
						"This is a shell command and should be run in your system terminal, not in this console.\n\n" .
						"If you need to:\n" .
						"- Install packages: Run `composer require package-name` in your terminal\n" .
						"- Run Artisan commands: Use the 'Artisan Commands' tab in this terminal\n" .
						"- Check package info: Run `composer show package-name` in your terminal\n\n" .
						"This terminal is for executing PHP code only, such as:\n" .
						"- `User::all()`\n" .
						"- `DB::table('users')->get()`\n" .
						"- `\\App\\Models\\Product::where('active', true)->count()`"
					],
					'result' => (object) [],
				], 400);
			}

			// Validate command is not empty after sanitization
			if (empty($userCommand)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command cannot be empty'],
					'result' => (object) [],
				], 400);
			}

			// Comprehensive command sanitization for AI-generated code
			try {
				$userCommand = $this->sanitizeCommand($userCommand);
			} catch (\Throwable $e) {
				\Log::error('Command sanitization error', [
					'original_command' => $request->input('command'),
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);
				// If sanitization fails, use original command (better than failing completely)
				$userCommand = trim($request->input('command'));
			}

			// Validate command is still not empty after sanitization
			if (empty(trim($userCommand))) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command is empty after sanitization'],
					'result' => (object) [],
				], 400);
			}

			$cacheKey = "overlord_session_{$cacheUserId}";

			// Performance timing
			$timings = [];
			$timings['start'] = microtime(true);

			// Handle help command (skip logging and execution)
			if (strtolower($userCommand) === 'help' || strtolower($userCommand) === '?') {
				$helpContent = $this->getHelpContent();
				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'output' => $helpContent,
						'type' => 'help',
						'raw' => strip_tags($helpContent),
					],
				], 200);
			}

			// Get or create shell instance (this already sets up model aliases)
			$timings['before_shell'] = microtime(true);
			try {
				$shell = $this->getOrCreateShell($cacheKey);
			} catch (\Throwable $e) {
				\Log::error('Failed to create shell', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);
				$errorMessage = $this->formatErrorMessage($e, 'Failed to create shell');
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => [$errorMessage],
					'result' => (object) [],
				], 400);
			}
			$timings['after_shell'] = microtime(true);

			// Create output buffer
			$output = new BufferedOutput();
			$shell->setOutput($output);

			// Get cached variables to restore
			$cached = Cache::get($cacheKey);
			$restoreCode = '';
			$hasCachedVariables = false;
			if ($cached && is_array($cached) && isset($cached['scope_variables']) && !empty($cached['scope_variables'])) {
				$hasCachedVariables = true;
				$restoreCommands = [];
				foreach ($cached['scope_variables'] as $key => $value) {
					try {
						$serialized = serialize($value);
						$encoded = base64_encode($serialized);
						$restoreCommands[] = "\${$key} = unserialize(base64_decode('{$encoded}'));";
					} catch (\Throwable $e) {
						continue;
					}
				}
				if (!empty($restoreCommands)) {
					$restoreCode = implode(' ', $restoreCommands) . ' ';
				}
			}

			// Only prepend bootstrap code if we need model aliases (for commands that might use models)
			// For simple commands like print(), we can skip the bootstrap code entirely
			$timings['before_bootstrap'] = microtime(true);
			$bootstrapCode = '';
			$needsBootstrap = $hasCachedVariables || $this->commandNeedsBootstrap($userCommand);
			if ($needsBootstrap) {
				try {
					$bootstrapCode = $this->getBootstrapCode();
				} catch (\Throwable $e) {
					\Log::error('Failed to get bootstrap code', [
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);
					$errorMessage = $this->formatErrorMessage($e, 'Failed to get bootstrap code');
					return response()->json([
						'success' => false,
						'status_code' => 'ERROR',
						'errors' => [$errorMessage],
						'result' => (object) [],
					], 400);
				}
			}
			$timings['after_bootstrap'] = microtime(true);

			// Build command: restore variables, then bootstrap code, then user command
			// Ensure proper separation between bootstrap code and user command
			$command = $restoreCode;
			if (!empty($bootstrapCode)) {
				$command .= rtrim($bootstrapCode, '; ') . '; ';
			}
			$command .= $userCommand;

			// Execute the command with bootstrap code
			ob_start();

			// Suppress Nightwatch connection warnings
			$originalErrorHandler = set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$originalErrorHandler) {
				if (
					strpos($errstr, 'stream_socket_client(): Unable to connect') !== false ||
					strpos($errstr, 'SocketStreamFactory') !== false
				) {
					return true;
				}

				if ($originalErrorHandler) {
					return call_user_func($originalErrorHandler, $errno, $errstr, $errfile, $errline);
				}
				return false;
			}, E_WARNING);

			try {
				// Only adjust limits if needed - avoid unnecessary ini_set calls for simple commands
				$isSimpleCommand = !$needsBootstrap && !$hasCachedVariables;
				if (!$isSimpleCommand) {
					@set_time_limit(300);
					$originalMemoryLimit = ini_get('memory_limit');
					@ini_set('memory_limit', '512M');
					$originalMaxExecutionTime = ini_get('max_execution_time');
					@ini_set('max_execution_time', '300');
				}

				$timings['before_execute'] = microtime(true);
				$result = $shell->execute($command);
				$timings['after_execute'] = microtime(true);

				if (!$isSimpleCommand) {
					@ini_set('memory_limit', $originalMemoryLimit ?? '128M');
					@ini_set('max_execution_time', $originalMaxExecutionTime ?? '30');
				}
			} catch (\ParseError $e) {
				\Log::error('PsySH parse error', [
					'command' => $userCommand,
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]);
				$errorMessage = $this->formatErrorMessage($e, 'Parse error', $userCommand);
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => [$errorMessage],
					'result' => (object) [],
				], 400);
			} catch (\Error $e) {
				\Log::error('PsySH fatal error', [
					'command' => $userCommand,
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]);
				$errorMessage = $this->formatErrorMessage($e, 'Fatal error', $userCommand);
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => [$errorMessage],
					'result' => (object) [],
				], 400);
			} catch (\Throwable $e) {
				\Log::error('PsySH execution error', [
					'command' => $userCommand,
					'error' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				]);
				$errorMessage = $this->formatErrorMessage($e, 'Execution error', $userCommand);
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => [$errorMessage],
					'result' => (object) [],
				], 400);
			} finally {
				if ($originalErrorHandler !== null) {
					restore_error_handler();
				}
				$stdoutOutput = ob_get_clean();
			}

			// Get output content from PsySH
			$outputContent = $output->fetch();

			// Filter out Nightwatch connection warnings
			$outputContent = preg_replace(
				'/<warning>\s*WARNING\s*<\/warning>.*?stream_socket_client\(\): Unable to connect to tcp:\/\/127\.0\.0\.1:2407.*?\n/m',
				'',
				$outputContent
			);
			$outputContent = preg_replace(
				'/stream_socket_client\(\): Unable to connect to tcp:\/\/127\.0\.0\.1:2407.*?SocketStreamFactory\.php.*?\n/m',
				'',
				$outputContent
			);
			$outputContent = preg_replace(
				'/.*?Unable to connect to tcp:\/\/127\.0\.0\.1:2407.*?\n/m',
				'',
				$outputContent
			);

			$outputContent = trim($outputContent);

			// Prioritize stdout output - if we have stdout, use it and ignore result formatting
			$hasStdoutOutput = !empty(trim($stdoutOutput));
			if ($hasStdoutOutput) {
				if (!empty($outputContent)) {
					$outputContent = trim($stdoutOutput) . "\n" . $outputContent;
				} else {
					$outputContent = trim($stdoutOutput);
				}
			}

			// Only format result if we don't have stdout output (echo, print, etc. output to stdout)
			if (!$hasStdoutOutput) {
				// Handle boolean results
				if ($outputContent === '1' && $result === true) {
					$outputContent = "Command completed successfully (chunk operation finished)";
				} elseif ($outputContent === '0' && $result === false) {
					$outputContent = "Command stopped early (chunk callback returned false)";
				} elseif (empty($outputContent) && $result !== null) {
					try {
						if (is_object($result) || is_array($result)) {
							$outputContent = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
							if (json_last_error() !== JSON_ERROR_NONE) {
								$outputContent = (string) $result;
							}
						} elseif (is_bool($result)) {
							$outputContent = $result ? "Command completed successfully (returned true)" : "Command returned false";
						} else {
							$outputContent = (string) $result;
						}
					} catch (\Throwable $e) {
						$outputContent = (string) $result;
					}
				} elseif (empty($outputContent) && $result === null) {
					$outputContent = "Command completed successfully (no output)";
				}
			}

			// Extract and store shell variables (only if we need to preserve state)
			// Skip expensive extraction for simple commands that don't create variables
			$timings['before_extract'] = microtime(true);
			$scopeVariables = [];
			$shouldExtractVariables = $hasCachedVariables || $this->commandMayCreateVariables($userCommand);
			if ($shouldExtractVariables) {
				try {
					$scopeVariables = $this->extractShellVariables($shell);
				} catch (\Throwable $e) {
					\Log::error('Failed to extract shell variables', [
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString(),
					]);
					// Don't fail the entire request if variable extraction fails
					$scopeVariables = [];
				}
			}
			$timings['after_extract'] = microtime(true);

			try {
				Cache::put($cacheKey, [
					'scope_variables' => $scopeVariables,
				], now()->addMinutes(30));
			} catch (\Throwable $e) {
				\Log::error('Failed to cache variables', [
					'error' => $e->getMessage(),
				]);
				// Don't fail the entire request if caching fails
			}

			// Detect output type
			try {
				$outputType = $this->detectOutputType($outputContent, $result);
			} catch (\Throwable $e) {
				\Log::error('Failed to detect output type', [
					'error' => $e->getMessage(),
				]);
				$outputType = 'text';
			}

			// Check for errors
			$hasError = false;
			if (!empty($outputContent)) {
				$errorPatterns = [
					'/<warning>/i',
					'/^\s*Error\s+/mi',
					'/^\s*Warning\s+/mi',
					'/^\s*Fatal error:/mi',
					'/^\s*Parse error:/mi',
					'/^\s*Notice:/mi',
					'/PHP (Fatal|Parse|Warning|Notice)/i',
					'/WARNING\s+/i',
				];

				foreach ($errorPatterns as $pattern) {
					if (preg_match($pattern, $outputContent)) {
						$hasError = true;
						break;
					}
				}
			}

			// Format output
			try {
				$formattedOutput = $this->formatOutput($outputContent, $result, $outputType);
			} catch (\Throwable $e) {
				\Log::error('Failed to format output', [
					'error' => $e->getMessage(),
				]);
				$formattedOutput = [
					'formatted' => $outputContent,
					'raw' => $outputContent,
				];
			}

			// Calculate execution metrics
			$executionTime = (microtime(true) - $startTime) * 1000;
			$memoryUsage = memory_get_peak_usage(true) - $startMemory;
			$success = !$hasError;

			// Log performance timings for debugging
			$timings['end'] = microtime(true);
			$perfLog = [];
			if (isset($timings['after_shell'])) {
				$perfLog['shell_creation'] = round(($timings['after_shell'] - $timings['before_shell']) * 1000, 2) . 'ms';
			}
			if (isset($timings['after_bootstrap'])) {
				$perfLog['bootstrap'] = round(($timings['after_bootstrap'] - $timings['before_bootstrap']) * 1000, 2) . 'ms';
			}
			if (isset($timings['after_execute'])) {
				$perfLog['command_execution'] = round(($timings['after_execute'] - $timings['before_execute']) * 1000, 2) . 'ms';
			}
			if (isset($timings['after_extract'])) {
				$perfLog['variable_extraction'] = round(($timings['after_extract'] - $timings['before_extract']) * 1000, 2) . 'ms';
			}
			$perfLog['total'] = round($executionTime, 2) . 'ms';

			if ($hasError) {
				$outputType = 'error';
				$errorMessage = $outputContent;
			}

			// Log command to database (direct insert for reliability)
			try {
				// Redact sensitive data from command
				$redactedCommand = SensitiveDataRedactor::redact($userCommand ?? '');

				// Truncate and redact output (max 10KB)
				$redactedOutput = null;
				if (!$hasError && $outputContent !== null) {
					$redactedOutput = SensitiveDataRedactor::redact($outputContent);
					if (mb_strlen($redactedOutput) > 10240) {
						$redactedOutput = mb_substr($redactedOutput, 0, 10240) . '... [TRUNCATED]';
					}
				}

				// Redact sensitive data from error
				$redactedError = null;
				if ($hasError && $errorMessage !== null) {
					$redactedError = SensitiveDataRedactor::redact($errorMessage);
				}

				OverlordCommandLog::create([
					'user_id' => $userId,
					'command' => $redactedCommand,
					'output' => $redactedOutput,
					'error' => $redactedError,
					'execution_time' => $executionTime,
					'memory_usage' => $memoryUsage,
					'success' => $success,
					'output_type' => $outputType,
					'ip_address' => $request->ip(),
				]);
			} catch (\Throwable $logError) {
				// Don't fail the request if logging fails
				\Log::warning('Failed to log command', [
					'error' => $logError->getMessage(),
					'command' => substr($userCommand ?? '', 0, 100),
				]);
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'output' => $formattedOutput,
					'type' => $outputType,
					'raw' => $outputContent,
				],
			], 200);
		} catch (\Throwable $e) {
			$executionTime = (microtime(true) - $startTime) * 1000;
			$memoryUsage = memory_get_peak_usage(true) - $startMemory;
			$success = false;

			\Log::error('Terminal execution error', [
				'command' => $userCommand ?? 'unknown',
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString(),
			]);

			// Log command to database (direct insert for reliability)
			try {
				// Redact sensitive data from command
				$redactedCommand = SensitiveDataRedactor::redact($userCommand ?? '');

				// Redact sensitive data from error
				$redactedError = $errorMessage !== null
					? SensitiveDataRedactor::redact($errorMessage)
					: null;

				OverlordCommandLog::create([
					'user_id' => $userId,
					'command' => $redactedCommand,
					'output' => null,
					'error' => $redactedError,
					'execution_time' => $executionTime,
					'memory_usage' => $memoryUsage,
					'success' => $success,
					'output_type' => 'error',
					'ip_address' => $request->ip(),
				]);
			} catch (\Throwable $logError) {
				// Don't fail the request if logging fails
				\Log::warning('Failed to log command', [
					'error' => $logError->getMessage(),
					'command' => substr($userCommand ?? '', 0, 100),
				]);
			}

			$errorMessage = $this->formatErrorMessage($e, 'Execution error', $userCommand ?? null);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => [$errorMessage],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get command history for the current user
	 */
	public function history(Request $request)
	{
		try {
			// Check if table exists before querying
			if (!Schema::hasTable('overlord_command_logs')) {
				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'logs' => [],
						'pagination' => [
							'current_page' => 1,
							'last_page' => 1,
							'per_page' => 20,
							'total' => 0,
						],
					],
				], 200);
			}

			$userId = Auth::id();
			$cacheUserId = $userId ?? 'guest';
			$perPage = $request->input('per_page', 20);
			$page = $request->input('page', 1);
			$successFilter = $request->input('success');

			$query = OverlordCommandLog::where('user_id', $userId)
				->orderBy('created_at', 'desc');

			if ($successFilter !== null) {
				$query->where('success', filter_var($successFilter, FILTER_VALIDATE_BOOLEAN));
			}

			$logs = $query->paginate($perPage, ['*'], 'page', $page);

			// Load user data
			$logItems = $logs->items();
			$userIds = array_filter(array_unique(array_map(function ($log) {
				return $log->user_id;
			}, $logItems)));

			$userModel = config('laravel-overlord.user_model', \App\Models\User::class);
			$users = [];
			if (!empty($userIds)) {
				$users = $userModel::whereIn('id', $userIds)
					->select('id', 'email')
					->get()
					->keyBy('id');
			}

			$transformedLogs = [];
			foreach ($logItems as $log) {
				$user = null;
				if ($log->user_id && isset($users[$log->user_id])) {
					$userData = $users[$log->user_id];
					$user = [
						'id' => $userData->id,
						'email' => $userData->email,
					];
				}

				$transformedLogs[] = [
					'id' => $log->id,
					'user_id' => $log->user_id,
					'command' => $log->command,
					'output' => $log->output,
					'error' => $log->error,
					'execution_time' => $log->execution_time ? (float) $log->execution_time : null,
					'memory_usage' => $log->memory_usage ? (int) $log->memory_usage : null,
					'success' => (bool) $log->success,
					'output_type' => $log->output_type,
					'ip_address' => $log->ip_address,
					'created_at' => $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : null,
					'updated_at' => $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : null,
					'user' => $user,
				];
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'logs' => $transformedLogs,
					'pagination' => [
						'current_page' => $logs->currentPage(),
						'last_page' => $logs->lastPage(),
						'per_page' => $logs->perPage(),
						'total' => $logs->total(),
					],
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Terminal history error', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to fetch history: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Clear the terminal session
	 */
	public function clearSession(Request $request)
	{
		try {
			$userId = Auth::id();
			$cacheUserId = $userId ?? 'guest';
			$cacheKey = "overlord_session_{$cacheUserId}";
			Cache::forget($cacheKey);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) ['message' => 'Session cleared'],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Terminal session clear error', [
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to clear session: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get or create a PsySH shell instance
	 * Optimized for performance - minimal configuration
	 */
	private function getOrCreateShell(string $cacheKey): Shell
	{
		// Use minimal configuration for fastest shell creation
		// Skip history file setup to avoid filesystem I/O and reflection overhead
		$config = new Configuration([
			'useReadline' => false,
			'usePager' => false,
			'historySize' => 0, // Disable history to save memory and I/O
		]);

		// CRITICAL: Set config directory to a writable location BEFORE creating Shell
		// This prevents PsySH from trying to create ~/.config/psysh which fails in Docker
		// We use Laravel's storage directory which is always writable
		try {
			// Use storage/app/.psysh which is always writable in Laravel
			$configDir = storage_path('app/.psysh');

			// Ensure directory exists
			if (!is_dir($configDir)) {
				@mkdir($configDir, 0755, true);
			}

			// Set config directory to writable storage location
			// This prevents PsySH from defaulting to ~/.config/psysh which may not be writable
			if (is_dir($configDir) && is_writable($configDir)) {
				$config->setConfigDir($configDir);
				// Set history file to a location in the writable config dir
				// Even though historySize is 0, PsySH still needs a history file path
				$config->setHistoryFile($configDir . '/history');
			} else {
				// Fallback: Use a temporary directory if storage isn't writable
				// This should rarely happen, but provides a safety net
				$tempDir = sys_get_temp_dir() . '/psysh_' . get_current_user();
				if (!is_dir($tempDir)) {
					@mkdir($tempDir, 0755, true);
				}
				if (is_dir($tempDir) && is_writable($tempDir)) {
					$config->setConfigDir($tempDir);
					$config->setHistoryFile($tempDir . '/history');
				}
			}
		} catch (\Exception $e) {
			// If all else fails, use storage path anyway and let PsySH handle errors
			// This is better than letting it default to ~/.config/psysh
			$fallbackDir = storage_path('app/.psysh');
			@mkdir($fallbackDir, 0755, true);
			$config->setConfigDir($fallbackDir);
			$config->setHistoryFile($fallbackDir . '/history');
		}

		// Create shell after all configuration is set
		// This ensures PsySH doesn't try to auto-create directories during construction
		$shell = new Shell($config);
		// Note: Model aliases are set up via bootstrap code, not here
		// This avoids calling getModelClasses() on every shell creation

		return $shell;
	}

	/**
	 * Sanitize command input to handle various formats from AI responses, copy/paste, etc.
	 * Removes: PsySH prompts, code block markers, markdown formatting, extra whitespace, PHP tags
	 */
	private function sanitizeCommand(?string $command): string
	{
		// Handle null or non-string input
		if ($command === null) {
			return '';
		}

		// Remove leading/trailing whitespace
		$command = trim($command);

		if (empty($command)) {
			return $command;
		}

		try {
			// Remove PHP opening/closing tags (<?php, <?, ?&gt;)
			// Terminal doesn't need these and they can cause issues
			$command = @preg_replace('/^<\?php\s*/i', '', $command) ?? $command;
			$command = @preg_replace('/^<\?\s*/', '', $command) ?? $command;
			$command = @preg_replace('/\?>\s*$/', '', $command) ?? $command;

			// Remove PsySH prompt characters from start of each line (>>>, >>, >)
			$command = @preg_replace('/^>>>\s*/m', '', $command) ?? $command;
			$command = @preg_replace('/^>>\s*/m', '', $command) ?? $command;
			$command = @preg_replace('/^>\s*/m', '', $command) ?? $command;

			// Remove code block markers (```php, ```, etc.) - handle various positions
			// Opening markers at start of string or line
			$command = @preg_replace('/^```[\w]*\s*\n?/m', '', $command) ?? $command;
			$command = @preg_replace('/\n```[\w]*\s*\n?/m', "\n", $command) ?? $command;
			// Closing markers at end of string or line
			$command = @preg_replace('/\n?```\s*$/m', '', $command) ?? $command;
			$command = @preg_replace('/\n?```\s*\n/m', "\n", $command) ?? $command;
			// Inline code block markers
			$command = @preg_replace('/```[\w]*\s*\n/', '', $command) ?? $command;
			$command = @preg_replace('/\n\s*```/', '', $command) ?? $command;

			// Remove markdown inline code markers (single backticks) that wrap entire command
			// But be careful not to remove legitimate backticks in strings
			if (@preg_match('/^`(.+)`$/s', $command, $matches)) {
				$command = $matches[1];
			}

			// Remove common AI response prefixes/suffixes
			$command = @preg_replace('/^(Here\'s|Here is|You can|Try this|Use this|Run this|Execute this|Code:|Example:)[:\s]*/i', '', $command) ?? $command;
			$command = @preg_replace('/\s*(This will|This code|The above|Note that).*$/is', '', $command) ?? $command;

			// Remove explanatory text patterns that AI might add
			$lines = explode("\n", $command);
			$cleanedLines = [];

			foreach ($lines as $line) {
				$trimmed = trim($line);

				// Skip empty lines (but preserve structure)
				if (empty($trimmed)) {
					$cleanedLines[] = $line;
					continue;
				}

				// Skip obvious explanation/metadata lines (but be conservative)
				if (@preg_match('/^(Note:|Tip:|Remember:|Important:|Warning:|Example output:|Output:|Result:)/i', $trimmed)) {
					continue;
				}

				// Skip lines that are clearly explanations (not code)
				if (
					@preg_match('/^(This|That|It|They|You|We|I)\s+(will|can|should|might|may|does|do|is|are)/i', $trimmed) &&
					!@preg_match('/[=;{}()\[\]]/', $trimmed)
				) {
					continue;
				}

				// Keep the line
				$cleanedLines[] = $line;
			}

			$command = implode("\n", $cleanedLines);

			// Remove HTML entities that might have been encoded
			$command = @html_entity_decode($command, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: $command;

			// Remove extra blank lines (more than 2 consecutive)
			$command = @preg_replace('/\n{3,}/', "\n\n", $command) ?? $command;

			// Clean up whitespace at end of each line (but preserve indentation)
			$lines = explode("\n", $command);
			$cleanedLines = [];
			foreach ($lines as $line) {
				// Preserve leading whitespace (indentation) but trim trailing
				$cleanedLines[] = rtrim($line);
			}
			$command = implode("\n", $cleanedLines);

			// Remove leading/trailing blank lines
			$command = @preg_replace('/^\n+/', '', $command) ?? $command;
			$command = @preg_replace('/\n+$/', '', $command) ?? $command;
		} catch (\Throwable $e) {
			// If any sanitization step fails, log and return original (trimmed)
			\Log::warning('Command sanitization step failed', [
				'error' => $e->getMessage(),
				'command_preview' => substr($command, 0, 100),
			]);
		}

		// Final trim
		$command = trim($command);

		return $command;
	}

	/**
	 * Get bootstrap code for model aliases
	 */
	private function getBootstrapCode(): string
	{
		$cacheUserId = Auth::id() ?? 'guest';
		$cacheKey = "overlord_bootstrap_" . $cacheUserId;
		$cached = Cache::get($cacheKey);

		if ($cached) {
			return $cached;
		}

		$models = $this->getModelDiscovery()->getModelClasses();
		$bootstrapCode = [];
		foreach ($models as $shortName => $fullName) {
			$bootstrapCode[] = "if (!class_exists('{$shortName}', false)) { class_alias('{$fullName}', '{$shortName}'); }";
		}

		$bootstrap = implode('; ', $bootstrapCode) . '; ';
		Cache::put($cacheKey, $bootstrap, now()->addMinutes(30));

		return $bootstrap;
	}

	/**
	 * Get help content for the terminal
	 */
	private function getHelpContent(): string
	{
		$models = $this->getModelDiscovery()->getModelClasses();
		$modelNames = array_keys($models);
		sort($modelNames);

		$helpView = config('laravel-overlord.help_view', 'laravel-overlord::help');
		return view($helpView, ['models' => $modelNames])->render();
	}

	/**
	 * Display the terminal interface (default route)
	 */
	public function index()
	{
		return view('laravel-overlord::terminal');
	}

	/**
	 * Get help content via GET request (public endpoint)
	 */
	public function getHelp(Request $request)
	{
		$helpContent = $this->getHelpContent();
		return response()->json([
			'success' => true,
			'status_code' => 'SUCCESS',
			'errors' => [],
			'result' => (object) [
				'output' => $helpContent,
				'type' => 'help',
				'raw' => strip_tags($helpContent),
			],
		], 200);
	}

	/**
	 * Check if a command likely needs bootstrap code (model aliases)
	 * Simple commands like print(), echo, etc. don't need it
	 */
	private function commandNeedsBootstrap(string $command): bool
	{
		$command = trim($command);

		// Simple commands that don't need models
		$simpleCommands = [
			'print',
			'echo',
			'var_dump',
			'print_r',
			'dd',
			'dump',
			'exit',
			'quit',
			'help',
			'?'
		];

		foreach ($simpleCommands as $simple) {
			if (
				stripos($command, $simple . '(') === 0 ||
				stripos($command, $simple . ' ') === 0 ||
				$command === $simple
			) {
				return false;
			}
		}

		// If command contains model-like patterns (capitalized words), likely needs bootstrap
		if (
			preg_match('/\b[A-Z][a-zA-Z0-9_]*::/', $command) ||
			preg_match('/\b[A-Z][a-zA-Z0-9_]*\(/', $command)
		) {
			return true;
		}

		// Default to including bootstrap for safety, but optimize for simple cases
		return false;
	}

	/**
	 * Check if a command might create variables that need to be preserved
	 */
	private function commandMayCreateVariables(string $command): bool
	{
		$command = trim($command);

		// Commands that definitely don't create variables
		$noVariableCommands = [
			'print',
			'echo',
			'var_dump',
			'print_r',
			'dd',
			'dump',
			'exit',
			'quit',
			'help',
			'?'
		];

		foreach ($noVariableCommands as $simple) {
			if (
				stripos($command, $simple . '(') === 0 ||
				stripos($command, $simple . ' ') === 0 ||
				$command === $simple
			) {
				return false;
			}
		}

		// If command contains variable assignment patterns, it might create variables
		if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=/', $command)) {
			return true;
		}

		// Default to false for simple commands
		return false;
	}

	/**
	 * Extract variables from shell using reflection
	 */
	private function extractShellVariables(Shell $shell): array
	{
		$scopeVariables = [];

		try {
			$reflection = new \ReflectionClass($shell);
			$possibleProperties = ['scope', 'context', 'variables', 'state'];

			foreach ($possibleProperties as $propName) {
				try {
					if ($reflection->hasProperty($propName)) {
						$property = $reflection->getProperty($propName);
						$property->setAccessible(true);
						$scope = $property->getValue($shell);

						if ($scope && is_object($scope)) {
							if (method_exists($scope, 'getAll')) {
								$vars = $scope->getAll();
								if (is_array($vars)) {
									foreach ($vars as $key => $value) {
										try {
											serialize($value);
											$scopeVariables[$key] = $value;
										} catch (\Throwable $e) {
											continue;
										}
									}
									break;
								}
							}
						}
					}
				} catch (\Throwable $e) {
					continue;
				}
			}

			if (empty($scopeVariables)) {
				try {
					$tempOutput = new BufferedOutput();
					$originalOutput = $shell->getOutput();
					$shell->setOutput($tempOutput);

					$extractCode = 'array_filter(get_defined_vars(), function($k) { return !in_array($k, ["GLOBALS", "_SERVER", "_GET", "_POST", "_FILES", "_COOKIE", "_SESSION", "_ENV", "_REQUEST"]) && !str_starts_with($k, "_"); }, ARRAY_FILTER_USE_KEY);';
					$vars = $shell->execute($extractCode);

					if (is_array($vars)) {
						foreach ($vars as $key => $value) {
							try {
								serialize($value);
								$scopeVariables[$key] = $value;
							} catch (\Throwable $e) {
								continue;
							}
						}
					}

					$shell->setOutput($originalOutput);
				} catch (\Throwable $e) {
					// Extraction failed
				}
			}
		} catch (\Throwable $e) {
			\Log::warning('Failed to extract shell variables', [
				'error' => $e->getMessage(),
			]);
		}

		return $scopeVariables;
	}

	/**
	 * Detect output type
	 */
	private function detectOutputType(string $output, $result): string
	{
		if (is_string($output) && !empty(trim($output))) {
			$trimmed = trim($output);
			if (
				($trimmed[0] === '{' && substr($trimmed, -1) === '}') ||
				($trimmed[0] === '[' && substr($trimmed, -1) === ']')
			) {
				json_decode($trimmed);
				if (json_last_error() === JSON_ERROR_NONE) {
					return 'json';
				}
			}
		}

		if (is_array($result) || is_object($result)) {
			return 'object';
		}

		if (
			strpos(strtolower($output), 'error') !== false ||
			strpos(strtolower($output), 'exception') !== false
		) {
			return 'error';
		}

		return 'text';
	}

	/**
	 * Check if a command is a shell command (not PHP code)
	 */
	private function isShellCommand(string $command): bool
	{
		$command = trim($command);
		if (empty($command)) {
			return false;
		}

		// Common shell command patterns
		$shellCommands = [
			'composer ',
			'php artisan ',
			'php ',
			'npm ',
			'yarn ',
			'git ',
			'docker ',
			'docker-compose ',
			'sudo ',
			'apt-get ',
			'apt ',
			'yum ',
			'brew ',
			'curl ',
			'wget ',
			'ls ',
			'cd ',
			'mkdir ',
			'rm ',
			'cp ',
			'mv ',
			'cat ',
			'grep ',
			'find ',
			'chmod ',
			'chown ',
		];

		// Check if command starts with a shell command
		foreach ($shellCommands as $shellCmd) {
			if (stripos($command, $shellCmd) === 0) {
				return true;
			}
		}

		// Check for commands that are just a single word (likely shell commands)
		// But allow PHP function calls and class names
		if (
			preg_match('/^[a-z][a-z0-9_-]+(\s|$)/i', $command) &&
			!preg_match('/^[A-Z][a-zA-Z0-9_\\\\]*::/', $command) && // Not a static method call
			!preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*/', $command) && // Not a variable
			!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\(/', $command)
		) { // Not a function call
			// Check if it's a known shell command
			$firstWord = explode(' ', $command)[0];
			if (in_array(strtolower($firstWord), ['composer', 'php', 'npm', 'yarn', 'git', 'docker', 'sudo', 'apt-get', 'apt', 'yum', 'brew'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format error message with helpful hints
	 */
	private function formatErrorMessage(\Throwable $e, string $prefix = 'Error', ?string $userCommand = null): string
	{
		$message = $e->getMessage();
		$hint = '';

		// Handle parse errors specially
		if ($e instanceof \ParseError) {
			// Try to extract more useful information from parse errors
			if (preg_match('/Syntax error, unexpected (.+?) on line (\d+)/', $message, $matches)) {
				$unexpected = $matches[1];
				$lineNum = $matches[2];

				// Common parse error hints
				if ($unexpected === 'T_CLASS') {
					$hint = " Hint: Check for missing semicolons, unclosed brackets, or using reserved class names (like 'Migration' which is a Laravel class).";
				} elseif ($unexpected === 'T_VARIABLE') {
					$hint = " Hint: Check for missing operators or unexpected variable usage.";
				} elseif ($unexpected === 'T_STRING') {
					$hint = " Hint: Check for missing quotes, operators, or syntax errors.";
				}

				// If we have the user command, try to show the problematic line
				if ($userCommand && is_numeric($lineNum)) {
					$lines = explode("\n", $userCommand);
					$userLineNum = (int) $lineNum;
					// Account for bootstrap code lines (rough estimate)
					// Bootstrap code is typically 1-2 lines, so adjust
					if ($userLineNum > 0 && isset($lines[$userLineNum - 1])) {
						$problematicLine = trim($lines[$userLineNum - 1]);
						if (!empty($problematicLine)) {
							$hint .= " Problematic line: " . $problematicLine;
						}
					}
				}
			}
		}

		// Detect common mistakes and provide helpful hints
		if (preg_match('/Call to undefined method (.+?)::(.+?)\(\)/', $message, $matches)) {
			$className = $matches[1];
			$methodName = $matches[2];

			// Check if it's a static call to an instance method
			if (preg_match('/::' . preg_quote($methodName, '/') . '\(\)/', $message)) {
				// Common instance methods that are often called statically
				$instanceMethods = ['toSql', 'get', 'first', 'find', 'where', 'all', 'create', 'update', 'delete'];
				if (in_array($methodName, $instanceMethods)) {
					$hint = " Hint: {$methodName}() is not a static method. Use {$className}::query()->{$methodName}() instead.";
				}
			}
		} elseif (preg_match('/Non-static method (.+?)::(.+?)\(\) should not be called statically/', $message, $matches)) {
			$className = $matches[1];
			$methodName = $matches[2];
			$hint = " Hint: {$methodName}() is not a static method. Use {$className}::query()->{$methodName}() instead.";
		} elseif (preg_match('/toSql\(\)/', $message)) {
			$hint = " Hint: toSql() is not a static method. Use Model::query()->toSql() instead.";
		} elseif (preg_match('/Class.*Migration.*not found/', $message) || (strpos($message, 'Migration') !== false && strpos($message, 'class') !== false)) {
			$hint = " Hint: 'Migration' is a reserved Laravel class name. Use DB::table('migrations')->get() instead of creating a Migration model.";
		}

		// Build error message with file and line if available
		$errorMsg = $prefix . ': ' . $message;
		if ($e->getFile() && $e->getLine()) {
			$file = basename($e->getFile());
			// Don't show internal PsySH files in user-facing errors
			if (strpos($file, 'ParseErrorException') === false && strpos($file, 'Shell.php') === false) {
				$errorMsg .= " (in {$file} on line {$e->getLine()})";
			}
		}

		return $errorMsg . $hint;
	}

	/**
	 * Format output based on type
	 */
	private function formatOutput(string $output, $result, string $type): array
	{
		switch ($type) {
			case 'json':
				$decoded = json_decode($output, true);
				return [
					'formatted' => $decoded,
					'raw' => $output,
				];

			case 'object':
				try {
					if ($result instanceof \Illuminate\Support\Collection) {
						$result = $result->toArray();
					}

					$itemCount = 0;
					if (is_array($result)) {
						$itemCount = count($result);
						if ($itemCount > 0 && is_array(reset($result))) {
							$itemCount = array_sum(array_map('count', $result));
						}
					}

					if ($itemCount > 10000) {
						$summary = [];
						if (is_array($result)) {
							foreach ($result as $key => $value) {
								$count = is_array($value) || ($value instanceof \Countable) ? count($value) : 1;
								$summary[$key] = [
									'_count' => $count,
									'_preview' => is_array($value) ? array_slice($value, 0, 3, true) : $value,
								];
							}
						}
						return [
							'formatted' => $summary,
							'raw' => json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
							'is_summary' => true,
							'total_items' => $itemCount,
						];
					}

					$json = @json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 512);

					if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
						return [
							'formatted' => 'Result too large or complex to display (JSON encoding failed)',
							'raw' => 'Result too large or complex to display',
							'type_hint' => is_object($result) ? get_class($result) : gettype($result),
							'item_count' => $itemCount,
						];
					}

					$decoded = json_decode($json, true);
					if (json_last_error() !== JSON_ERROR_NONE) {
						return [
							'formatted' => $json,
							'raw' => $json,
						];
					}

					return [
						'formatted' => $decoded,
						'raw' => $json,
					];
				} catch (\Throwable $e) {
					return [
						'formatted' => 'Error formatting result: ' . $e->getMessage(),
						'raw' => 'Error formatting result: ' . $e->getMessage(),
						'type_hint' => is_object($result) ? get_class($result) : gettype($result),
					];
				}

			case 'error':
				return [
					'formatted' => $output,
					'raw' => $output,
					'isError' => true,
				];

			default:
				return [
					'formatted' => $output,
					'raw' => $output,
				];
		}
	}

	/**
	 * Get model relationships for diagram visualization
	 */
	public function getModelRelationships(Request $request)
	{
		try {
			$modelsPath = config('laravel-overlord.models_path', app_path('Models'));
			$models = [];
			$relationships = [];

			$files = glob($modelsPath . '/*.php');

			foreach ($files as $file) {
				$className = $this->getFullClassNameFromFile($file, basename($file, '.php'));

				if (!$className) {
					// Fallback to common pattern
					$className = 'App\\Models\\' . basename($file, '.php');
				}

				if (
					strpos($className, 'Pivot') !== false ||
					strpos($className, 'Abstract') !== false ||
					!class_exists($className)
				) {
					continue;
				}

				try {
					$reflection = new \ReflectionClass($className);

					if (
						!$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class) ||
						$reflection->isAbstract()
					) {
						continue;
					}

					$modelName = class_basename($className);
					$models[] = $modelName;

					$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
					$sourceLines = file($reflection->getFileName());

					foreach ($methods as $method) {
						$methodName = $method->getName();

						if (
							$method->isStatic() ||
							$method->getNumberOfParameters() > 0 ||
							strpos($methodName, '__') === 0
						) {
							continue;
						}

						$nonRelationshipPatterns = [
							'/^(get|set|is|has|can|should|will|must)[A-Z]/',
							'/^(calculate|generate|parse|format|validate|sanitize)/i',
							'/^(create|update|delete|save|find|query)/i',
							'/^(refresh|touch|sync|attach|detach|syncWithoutDetaching)/i',
						];

						$isNonRelationship = false;
						foreach ($nonRelationshipPatterns as $pattern) {
							if (preg_match($pattern, $methodName)) {
								$isNonRelationship = true;
								break;
							}
						}

						if ($isNonRelationship) {
							continue;
						}

						try {
							$startLine = $method->getStartLine();
							$endLine = $method->getEndLine();

							if (!$startLine || !$endLine || !$sourceLines) {
								continue;
							}

							$methodBody = implode('', array_slice($sourceLines, $startLine - 1, $endLine - $startLine + 1));

							$patterns = [
								'/return\s+\$this->(hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany|morphOne|morphToMany|morphedByMany|hasManyThrough)\(\s*([A-Z][a-zA-Z0-9_\\\\]+)::class/',
								'/return\s+\$this->(hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany|morphOne|morphToMany|morphedByMany|hasManyThrough)\(\s*[\'"]((?:App\\\\Models\\\\|\\\\App\\\\Models\\\\)?[A-Z][a-zA-Z0-9_]+)[\'"]/',
								'/return\s+\$this->(hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany|morphOne|morphToMany|morphedByMany|hasManyThrough)\(\s*function\s*\([^)]*\)\s*use\s*\([^)]*\)\s*\{[^}]*return\s+([A-Z][a-zA-Z0-9_\\\\]+)::class/',
							];

							$found = false;
							foreach ($patterns as $pattern) {
								if (preg_match($pattern, $methodBody, $matches)) {
									$relationType = $matches[1];
									$relatedModelClass = $matches[2] ?? '';

									$relatedModelClass = str_replace(['App\\Models\\', 'App\\\\Models\\\\', '\\'], '', $relatedModelClass);
									$relatedModel = class_basename($relatedModelClass);

									if (empty($relatedModel) || $relatedModel === $modelName) {
										continue;
									}

									if (in_array($relationType, ['belongsToMany', 'morphToMany', 'morphedByMany'])) {
										if (preg_match('/[\'"]([a-z_]+)[\'"]\s*[,\)]/', $methodBody, $pivotMatches)) {
											$possiblePivot = $pivotMatches[1];
											$pivotModelClass = $this->getPivotModelClass($possiblePivot);
											if ($pivotModelClass) {
												$pivotModel = class_basename($pivotModelClass);
												$relationships[] = [
													'from' => $modelName,
													'to' => $pivotModel,
													'type' => 'pivot',
													'method' => $method->getName() . '_pivot',
												];
												$relationships[] = [
													'from' => $pivotModel,
													'to' => $relatedModel,
													'type' => 'pivot',
													'method' => 'belongs_to',
												];
											}
										}
									}

									$relationships[] = [
										'from' => $modelName,
										'to' => $relatedModel,
										'type' => $relationType,
										'method' => $method->getName(),
									];
									$found = true;
									break;
								}
							}

							if (!$found) {
								try {
									$instance = $reflection->newInstanceWithoutConstructor();
									$returnValue = $method->invoke($instance);

									if ($returnValue instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
										$relatedModel = class_basename(get_class($returnValue->getRelated()));
										$relationType = $this->getRelationType($returnValue);

										if (
											$returnValue instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany ||
											$returnValue instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany ||
											$returnValue instanceof \Illuminate\Database\Eloquent\Relations\MorphedByMany
										) {
											try {
												$pivotTable = $returnValue->getTable();
												$pivotModelClass = $this->getPivotModelClass($pivotTable);
												if ($pivotModelClass) {
													$pivotModel = class_basename($pivotModelClass);
													$relationships[] = [
														'from' => $modelName,
														'to' => $pivotModel,
														'type' => 'pivot',
														'method' => $method->getName() . '_pivot',
													];
													$relationships[] = [
														'from' => $pivotModel,
														'to' => $relatedModel,
														'type' => 'pivot',
														'method' => 'belongs_to',
													];
												}
											} catch (\Throwable $e) {
												// Continue
											}
										}

										$relationships[] = [
											'from' => $modelName,
											'to' => $relatedModel,
											'type' => $relationType,
											'method' => $method->getName(),
										];
									}
								} catch (\Throwable $e) {
									continue;
								}
							}
						} catch (\Throwable $e) {
							continue;
						}
					}
				} catch (\Throwable $e) {
					continue;
				}
			}

			$models = array_unique($models);
			sort($models);

			$uniqueRelationships = [];
			foreach ($relationships as $rel) {
				$key = $rel['from'] . '|' . $rel['to'] . '|' . $rel['method'];
				if (!isset($uniqueRelationships[$key])) {
					$uniqueRelationships[$key] = $rel;
				}
			}
			$relationships = array_values($uniqueRelationships);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'models' => $models,
					'relationships' => $relationships,
				],
			], 200);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to analyze models: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get the type of relationship
	 */
	private function getRelationType($relation)
	{
		$class = get_class($relation);

		if (strpos($class, 'HasOne') !== false) {
			return 'hasOne';
		} elseif (strpos($class, 'HasMany') !== false) {
			return 'hasMany';
		} elseif (strpos($class, 'BelongsTo') !== false) {
			return 'belongsTo';
		} elseif (strpos($class, 'BelongsToMany') !== false) {
			return 'belongsToMany';
		} elseif (strpos($class, 'MorphTo') !== false) {
			return 'morphTo';
		} elseif (strpos($class, 'MorphMany') !== false) {
			return 'morphMany';
		} elseif (strpos($class, 'MorphOne') !== false) {
			return 'morphOne';
		} elseif (strpos($class, 'MorphToMany') !== false) {
			return 'morphToMany';
		} elseif (strpos($class, 'MorphedByMany') !== false) {
			return 'morphedByMany';
		} elseif (strpos($class, 'HasManyThrough') !== false) {
			return 'hasManyThrough';
		}

		return 'unknown';
	}

	/**
	 * Try to find a pivot model class for a given table name
	 */
	private function getPivotModelClass($tableName)
	{
		$modelName = str_replace('_', '', ucwords($tableName, '_'));
		$modelName = rtrim($modelName, 's');
		$modelName = ucfirst($modelName);

		$modelsPath = config('laravel-overlord.models_path', app_path('Models'));
		$possibleClasses = [];

		// Try to extract namespace from a sample file
		$files = glob($modelsPath . '/*.php');
		if (!empty($files)) {
			$sampleFile = $files[0];
			$namespace = $this->extractNamespaceFromFile($sampleFile);
			if ($namespace) {
				$possibleClasses[] = $namespace . '\\' . $modelName;
			}
		}

		// Fallback to common pattern
		$possibleClasses[] = 'App\\Models\\' . $modelName;
		$possibleClasses[] = 'App\\Models\\' . ucfirst($modelName);

		foreach ($possibleClasses as $className) {
			if (class_exists($className)) {
				$reflection = new \ReflectionClass($className);
				if ($reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
					return $className;
				}
			}
		}

		return null;
	}

	/**
	 * Extract namespace from PHP file
	 */
	private function extractNamespaceFromFile(string $filePath): ?string
	{
		$content = file_get_contents($filePath);
		if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
			return trim($matches[1]);
		}
		return null;
	}

	/**
	 * Extract full class name from PHP file
	 */
	private function getFullClassNameFromFile(string $filePath, string $className): ?string
	{
		$content = file_get_contents($filePath);
		if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
			$namespace = trim($matches[1]);
			return $namespace . '\\' . $className;
		}
		return null;
	}

	/**
	 * Get all controllers and their methods
	 */
	public function getControllers(Request $request)
	{
		try {
			$controllers = $this->getControllerDiscovery()->getControllers();

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'controllers' => $controllers,
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to analyze controllers', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to analyze controllers: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get all classes and their detailed information
	 */
	public function getClasses(Request $request)
	{
		try {
			$classes = $this->getClassDiscovery()->getClasses();

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'classes' => $classes,
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to analyze classes', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to analyze classes: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get model fields/columns for a given model
	 */
	public function getModelFields(Request $request)
	{
		try {
			$modelName = $request->input('model');

			if (!$modelName) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Model name is required'],
					'result' => (object) [],
				], 400);
			}

			// Get model class
			$modelsPath = config('laravel-overlord.models_path', app_path('Models'));
			$files = glob($modelsPath . '/*.php');
			$fullClassName = null;

			foreach ($files as $file) {
				$className = $this->getFullClassNameFromFile($file, basename($file, '.php'));

				if (!$className) {
					$className = 'App\\Models\\' . basename($file, '.php');
				}

				if (class_exists($className)) {
					$reflection = new \ReflectionClass($className);
					if (
						$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class) &&
						class_basename($className) === $modelName
					) {
						$fullClassName = $className;
						break;
					}
				}
			}

			if (!$fullClassName) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Model not found: ' . $modelName],
					'result' => (object) [],
				], 404);
			}

			// Get model instance to get table name
			$model = new $fullClassName();
			$tableName = $model->getTable();

			// Get columns from database schema
			$columns = Schema::getColumnListing($tableName);

			// Get column details (type, nullable, etc.)
			$columnDetails = [];
			$connection = $model->getConnection();
			$schemaBuilder = $connection->getSchemaBuilder();

			foreach ($columns as $column) {
				try {
					$columnType = $schemaBuilder->getColumnType($tableName, $column);
					$columnDetails[] = [
						'name' => $column,
						'type' => $columnType,
					];
				} catch (\Throwable $e) {
					// If we can't get type, just include the column name
					$columnDetails[] = [
						'name' => $column,
						'type' => 'unknown',
					];
				}
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'model' => $modelName,
					'table' => $tableName,
					'fields' => $columnDetails,
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to get model fields', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get model fields: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get all registered Artisan commands
	 */
	public function getCommands(Request $request)
	{
		try {
			$kernel = app(\Illuminate\Contracts\Console\Kernel::class);
			$kernel->bootstrap();
			$allCommands = $kernel->all();

			$commands = [];

			foreach ($allCommands as $commandName => $command) {
				if ($command->isHidden()) {
					continue;
				}

				try {
					$signature = $command->getName();
					$description = $command->getDescription();
					$class = get_class($command);

					$parsedSignature = $this->parseCommandSignature($command->getDefinition());
					$category = $this->categorizeCommand($signature, $class);

					$commands[] = [
						'name' => $signature,
						'description' => $description ?: 'No description',
						'class' => $class,
						'category' => $category,
						'arguments' => $parsedSignature['arguments'],
						'options' => $parsedSignature['options'],
					];
				} catch (\Throwable $e) {
					\Log::warning('Failed to parse command: ' . $commandName, [
						'error' => $e->getMessage(),
					]);
					continue;
				}
			}

			usort($commands, function ($a, $b) {
				if ($a['category'] !== $b['category']) {
					return strcmp($a['category'], $b['category']);
				}
				return strcmp($a['name'], $b['name']);
			});

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'commands' => $commands,
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to get Artisan commands', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get commands: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Execute an Artisan command
	 */
	public function executeArtisanCommand(Request $request)
	{
		try {
			$request->validate([
				'command' => 'required|string',
				'arguments' => 'sometimes|array',
				'options' => 'sometimes|array',
			]);

			$commandName = $request->input('command');
			$arguments = $request->input('arguments', []);
			$options = $request->input('options', []);

			$allCommands = Artisan::all();
			if (!isset($allCommands[$commandName])) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command not found: ' . $commandName],
					'result' => (object) [],
				], 400);
			}

			$parameters = array_merge($arguments, $options);
			$output = new BufferedOutput();
			$startTime = microtime(true);

			try {
				$exitCode = Artisan::call($commandName, $parameters, $output);
			} catch (\Symfony\Component\Console\Exception\CommandNotFoundException $e) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command not found: ' . $commandName],
					'result' => (object) [],
				], 400);
			} catch (\Symfony\Component\Console\Exception\RuntimeException $e) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command execution error: ' . $e->getMessage()],
					'result' => (object) [],
				], 400);
			} catch (\Throwable $e) {
				\Log::error('Artisan command execution failed', [
					'command' => $commandName,
					'error' => $e->getMessage(),
				]);
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command execution failed: ' . $e->getMessage() . ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')'],
					'result' => (object) [],
				], 400);
			}

			$executionTime = microtime(true) - $startTime;
			$outputContent = $output->fetch();
			$success = $exitCode === 0;

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'success' => $success,
					'exitCode' => $exitCode,
					'output' => $outputContent,
					'executionTime' => round($executionTime, 3),
				],
			], 200);
		} catch (\Illuminate\Validation\ValidationException $e) {
			$errors = $e->errors();
			$errorMessage = 'Validation failed: ' . implode(', ', array_map(function ($messages) {
				return implode(', ', $messages);
			}, $errors));
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => [$errorMessage],
				'result' => (object) [],
			], 400);
		} catch (\Throwable $e) {
			\Log::error('Failed to execute Artisan command', [
				'command' => $request->input('command'),
				'error' => $e->getMessage(),
			]);

			$errorMessage = $e->getMessage();
			if (config('app.debug')) {
				$errorMessage .= ' (in ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
			}

			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to execute command: ' . $errorMessage],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Parse command signature to extract arguments and options
	 */
	private function parseCommandSignature($definition)
	{
		$arguments = [];
		$options = [];

		foreach ($definition->getArguments() as $argument) {
			$reflection = new \ReflectionClass($argument);
			$modeProperty = $reflection->getProperty('mode');
			$modeProperty->setAccessible(true);
			$mode = $modeProperty->getValue($argument);

			$isRequired = ($mode & \Symfony\Component\Console\Input\InputArgument::REQUIRED) === \Symfony\Component\Console\Input\InputArgument::REQUIRED;

			$arguments[] = [
				'name' => $argument->getName(),
				'description' => $argument->getDescription(),
				'required' => $isRequired,
				'default' => $argument->getDefault(),
				'isArray' => $argument->isArray(),
			];
		}

		foreach ($definition->getOptions() as $option) {
			$options[] = [
				'name' => $option->getName(),
				'shortcut' => $option->getShortcut(),
				'description' => $option->getDescription(),
				'acceptValue' => $option->acceptValue(),
				'isValueRequired' => $option->isValueRequired(),
				'isValueOptional' => $option->isValueOptional(),
				'default' => $option->getDefault(),
				'isArray' => $option->isArray(),
			];
		}

		return [
			'arguments' => $arguments,
			'options' => $options,
		];
	}

	/**
	 * Categorize a command based on its name and class
	 */
	private function categorizeCommand($signature, $class)
	{
		if (strpos($class, 'Illuminate\\') === 0 || strpos($class, 'Laravel\\') === 0) {
			return 'Laravel';
		}

		if (strpos($signature, 'audit:') === 0) {
			return 'Audit';
		}
		if (strpos($signature, 'migrate:') === 0) {
			return 'Migrate';
		}
		if (strpos($signature, 'make:') === 0) {
			return 'Generator';
		}
		if (strpos($signature, 'db:') === 0) {
			return 'Database';
		}
		if (strpos($signature, 'cache:') === 0) {
			return 'Cache';
		}
		if (strpos($signature, 'queue:') === 0) {
			return 'Queue';
		}
		if (strpos($signature, 'route:') === 0) {
			return 'Route';
		}
		if (strpos($signature, 'config:') === 0) {
			return 'Config';
		}
		if (strpos($signature, 'view:') === 0) {
			return 'View';
		}
		if (strpos($signature, 'schedule:') === 0) {
			return 'Schedule';
		}

		if (strpos($class, 'App\\Console\\Commands') === 0) {
			return 'Custom';
		}

		return 'Other';
	}

	/**
	 * Check if Horizon is installed
	 */
	public function checkHorizon(Request $request)
	{
		try {
			$installed = class_exists('Laravel\Horizon\Horizon');

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'installed' => $installed,
				],
			], 200);
		} catch (\Throwable $e) {
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to check Horizon: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get Horizon statistics
	 */
	public function getHorizonStats(Request $request)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			// Get Horizon's Redis connection and prefix
			$horizonConnection = config('horizon.use', 'default');
			// Get the actual prefix from config - it's dynamic based on app name
			$horizonPrefix = config('horizon.prefix');
			if (empty($horizonPrefix)) {
				// Fallback to default pattern if not set
				$appName = config('app.name', 'laravel');
				$horizonPrefix = \Illuminate\Support\Str::slug($appName, '_') . '_horizon:';
			}

			// Initialize default stats
			$stats = [
				'wait' => 0,
				'process' => 0,
				'jobsPerMinute' => 0,
				'recentJobsFailed' => 0,
				'queues' => [],
				'pendingJobs' => 0,
				'completedJobs' => 0,
				'totalFailedJobs' => 0,
				'workers' => 0,
				'processes' => [],
				'throughput' => [
					'perSecond' => 0,
					'perHour' => 0,
				],
				'successRate' => 0,
				'totalJobsProcessed' => 0,
				'averageJobDuration' => 0,
			];

			// Get basic stats from Redis
			try {
				// Get Redis connection config
				$redisConfig = config("database.redis.{$horizonConnection}");
				if (!$redisConfig) {
					$redisConfig = config('database.redis.default');
				}

				// Create a direct Redis connection without Laravel's prefix
				// Horizon stores keys without the database prefix
				$redis = new \Redis();
				$redis->connect(
					$redisConfig['host'] ?? '127.0.0.1',
					$redisConfig['port'] ?? 6379
				);

				if (isset($redisConfig['password']) && $redisConfig['password']) {
					$redis->auth($redisConfig['password']);
				}

				if (isset($redisConfig['database'])) {
					$redis->select($redisConfig['database'] ?? 0);
				}

				// Get wait time
				$waitKey = $horizonPrefix . 'wait';
				$wait = $redis->get($waitKey);
				$stats['wait'] = $wait ? (float) $wait : 0;

				// Get process time
				$processKey = $horizonPrefix . 'process';
				$process = $redis->get($processKey);
				$stats['process'] = $process ? (float) $process : 0;

				// Get jobs per minute
				$jobsPerMinuteKey = $horizonPrefix . 'jobs_per_minute';
				$jobsPerMinute = $redis->get($jobsPerMinuteKey);
				$stats['jobsPerMinute'] = $jobsPerMinute ? (int) $jobsPerMinute : 0;

				// Get recent jobs failed
				$recentJobsFailedKey = $horizonPrefix . 'recent_jobs_failed';
				$recentJobsFailed = $redis->get($recentJobsFailedKey);
				$stats['recentJobsFailed'] = $recentJobsFailed ? (int) $recentJobsFailed : 0;

				// Calculate throughput
				$stats['throughput']['perSecond'] = $stats['jobsPerMinute'] > 0 ? round($stats['jobsPerMinute'] / 60, 2) : 0;
				$stats['throughput']['perHour'] = $stats['jobsPerMinute'] * 60;

				// Get pending jobs count (from queue sizes)
				$pendingJobs = 0;
				$queuePattern = $horizonPrefix . 'queues:*';
				$queueKeys = $redis->keys($queuePattern);
				$queueKeys = is_array($queueKeys) ? $queueKeys : [];

				// Get queue statistics and calculate pending jobs
				$queues = [];
				foreach ($queueKeys as $queueKey) {
					$queueName = str_replace($horizonPrefix . 'queues:', '', $queueKey);
					$queueData = $redis->hgetall($queueKey);

					if (!empty($queueData) && is_array($queueData)) {
						$queueJobs = isset($queueData['jobs']) ? (int) $queueData['jobs'] : 0;
						$pendingJobs += $queueJobs;

						$queues[$queueName] = [
							'jobs' => $queueJobs,
							'wait' => isset($queueData['wait']) ? (float) $queueData['wait'] : 0,
							'process' => isset($queueData['process']) ? (float) $queueData['process'] : 0,
						];
					}
				}

				$stats['queues'] = $queues;
				$stats['pendingJobs'] = $pendingJobs;

				// Get completed jobs (from recent_jobs key - it's a sorted set, not a list)
				try {
					$recentJobsKey = $horizonPrefix . 'recent_jobs';
					$recentJobIds = $redis->zrange($recentJobsKey, 0, -1);
					$stats['completedJobs'] = is_array($recentJobIds) ? count($recentJobIds) : 0;
				} catch (\Throwable $e) {
					// Continue if we can't get recent jobs
				}

				// Get total failed jobs count (failed_jobs is also a sorted set)
				try {
					$failedJobsKey = $horizonPrefix . 'failed_jobs';
					$failedJobIds = $redis->zrange($failedJobsKey, 0, -1);
					$stats['totalFailedJobs'] = is_array($failedJobIds) ? count($failedJobIds) : 0;
				} catch (\Throwable $e) {
					// Continue if we can't get failed jobs
				}

				// Try to get workers/processes information
				try {
					$processPattern = $horizonPrefix . 'process:*';
					$processKeys = $redis->keys($processPattern);
					$processKeys = is_array($processKeys) ? $processKeys : [];
					$stats['workers'] = count($processKeys);

					// Get process details
					$processes = [];
					foreach ($processKeys as $processKey) {
						$processData = $redis->hgetall($processKey);
						if (!empty($processData) && is_array($processData)) {
							$processes[] = [
								'name' => $processData['name'] ?? 'Unknown',
								'status' => $processData['status'] ?? 'unknown',
								'pid' => $processData['pid'] ?? null,
							];
						}
					}
					$stats['processes'] = $processes;
				} catch (\Throwable $e) {
					// Continue if we can't get process info
				}

				// Calculate success rate
				$totalProcessed = $stats['completedJobs'] + $stats['totalFailedJobs'];
				if ($totalProcessed > 0) {
					$stats['successRate'] = round(($stats['completedJobs'] / $totalProcessed) * 100, 2);
				}
				$stats['totalJobsProcessed'] = $totalProcessed;

				// Calculate average job duration (process time)
				$stats['averageJobDuration'] = $stats['process'];

			} catch (\Throwable $e) {
				\Log::warning('Failed to get some Horizon stats from Redis', [
					'error' => $e->getMessage(),
				]);
			}

			// Try to use Horizon::stats() if available
			if (method_exists('Laravel\Horizon\Horizon', 'stats')) {
				try {
					$horizonStats = \Laravel\Horizon\Horizon::stats();
					if (is_array($horizonStats) || is_object($horizonStats)) {
						$stats = array_merge($stats, (array) $horizonStats);
					}
				} catch (\Throwable $e) {
					// Continue with Redis-based stats
				}
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) $stats,
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to get Horizon stats', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Return empty stats instead of error if Horizon is installed
			// This allows the UI to still load even if stats can't be retrieved
			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'wait' => 0,
					'process' => 0,
					'jobsPerMinute' => 0,
					'recentJobsFailed' => 0,
					'queues' => [],
					'pendingJobs' => 0,
					'completedJobs' => 0,
					'totalFailedJobs' => 0,
					'workers' => 0,
					'processes' => [],
					'throughput' => [
						'perSecond' => 0,
						'perHour' => 0,
					],
					'successRate' => 0,
					'totalJobsProcessed' => 0,
					'averageJobDuration' => 0,
				],
			], 200);
		}
	}

	/**
	 * Get Horizon jobs by type
	 */
	public function getHorizonJobs(Request $request)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			$type = $request->input('type', 'pending'); // pending, completed, silenced, failed
			$queue = $request->input('queue', null);
			$search = $request->input('search', '');
			$page = (int) $request->input('page', 1);
			$perPage = (int) $request->input('per_page', 50);

			// Get Horizon's Redis connection and prefix
			$horizonConnection = config('horizon.use', 'default');
			// Get the actual prefix from config - it's dynamic based on app name
			$horizonPrefix = config('horizon.prefix');
			if (empty($horizonPrefix)) {
				// Fallback to default pattern if not set
				$appName = config('app.name', 'laravel');
				$horizonPrefix = \Illuminate\Support\Str::slug($appName, '_') . '_horizon:';
			}

			$jobs = [];
			$keys = [];

			try {
				// Get Redis connection config
				$redisConfig = config("database.redis.{$horizonConnection}");
				if (!$redisConfig) {
					$redisConfig = config('database.redis.default');
				}

				// Create a direct Redis connection without Laravel's prefix
				// Horizon stores keys without the database prefix
				$redis = new \Redis();
				$redis->connect(
					$redisConfig['host'] ?? '127.0.0.1',
					$redisConfig['port'] ?? 6379
				);

				if (isset($redisConfig['password']) && $redisConfig['password']) {
					$redis->auth($redisConfig['password']);
				}

				if (isset($redisConfig['database'])) {
					$redis->select($redisConfig['database'] ?? 0);
				}
			} catch (\Throwable $e) {
				\Log::error('Failed to connect to Redis for Horizon', [
					'error' => $e->getMessage(),
					'connection' => $horizonConnection,
				]);
				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'jobs' => [],
						'total' => 0,
						'page' => $page,
						'per_page' => $perPage,
						'total_pages' => 0,
					],
				], 200);
			}

			// Get jobs based on type
			switch ($type) {
				case 'failed':
					// Failed jobs are in a sorted set
					$failedJobsKey = $horizonPrefix . 'failed_jobs';
					$jobIds = $redis->zrange($failedJobsKey, 0, -1);
					$jobIds = is_array($jobIds) ? $jobIds : [];

					// Try to find job data for each failed job ID
					foreach ($jobIds as $jobId) {
						// Try multiple key patterns that Horizon might use for failed jobs
						$possibleKeys = [
							$horizonPrefix . $jobId,  // Standard format
							$horizonPrefix . "failed:{$jobId}",  // Failed prefix
							$horizonPrefix . "job:{$jobId}",  // Job prefix
						];

						$found = false;
						foreach ($possibleKeys as $possibleKey) {
							if ($redis->exists($possibleKey)) {
								$keys[] = $possibleKey;
								$found = true;
								break;
							}
						}

						if (!$found) {
							// If job data doesn't exist, create a synthetic job entry
							// This happens when Horizon has cleaned up the job data but kept the ID in failed_jobs
							if (config('app.debug')) {
								\Log::debug('Failed job data not found, will create synthetic entry', [
									'job_id' => $jobId,
								]);
							}
							// Store a marker so we can create synthetic data later
							$keys[] = $horizonPrefix . 'synthetic:failed:' . $jobId;
						}
					}

					// Also check for failed:* pattern (some Horizon versions might use this)
					$pattern = $horizonPrefix . 'failed:*';
					$allKeys = $redis->keys($pattern);
					if (is_array($allKeys)) {
						$keys = array_merge($keys, $allKeys);
						$keys = array_unique($keys); // Remove duplicates
					}
					break;
				case 'completed':
					// Completed jobs: Horizon stores job IDs in recent_jobs sorted set
					// But the actual job data might be stored with different key patterns
					// or might have been cleaned up by Horizon's TTL
					// We need to exclude jobs that are in failed_jobs or pending_jobs
					$recentJobsKey = $horizonPrefix . 'recent_jobs';
					$failedJobsKey = $horizonPrefix . 'failed_jobs';
					$pendingJobsKey = $horizonPrefix . 'pending_jobs';
					$jobIds = $redis->zrange($recentJobsKey, 0, -1);
					$jobIds = is_array($jobIds) ? $jobIds : [];

					// Try to find job data for each ID
					foreach ($jobIds as $jobId) {
						// Skip jobs that are in failed_jobs (they should be in failed tab)
						// Don't skip if they're in pending_jobs - jobs can be in both during transition
						// We'll check the actual job data later to determine if it's truly completed
						if ($redis->zscore($failedJobsKey, $jobId) !== false) {
							if (config('app.debug')) {
								\Log::debug('Skipping completed job that is in failed_jobs', ['job_id' => $jobId]);
							}
							continue;
						}

						// Try multiple key patterns that Horizon might use
						$possibleKeys = [
							$horizonPrefix . $jobId,  // Standard format
							$horizonPrefix . "completed:{$jobId}",  // Completed prefix
							$horizonPrefix . "job:{$jobId}",  // Job prefix
							$horizonPrefix . "recent:{$jobId}",  // Recent prefix
						];

						$found = false;
						foreach ($possibleKeys as $possibleKey) {
							if ($redis->exists($possibleKey)) {
								$keys[] = $possibleKey;
								$found = true;
								break;
							}
						}

						if (!$found) {
							// If job data doesn't exist, create a minimal job entry from the ID
							// This happens when Horizon has cleaned up the job data but kept the ID in recent_jobs
							// We'll create a synthetic job entry with just the ID and status
							if (config('app.debug')) {
								\Log::debug('Completed job data not found, will create synthetic entry', [
									'job_id' => $jobId,
								]);
							}
							// Store a marker so we can create synthetic data later
							$keys[] = $horizonPrefix . 'synthetic:' . $jobId;
						}
					}

					// Also check for completed:* pattern (some Horizon versions might use this)
					$pattern = $horizonPrefix . 'completed:*';
					$allKeys = $redis->keys($pattern);
					if (is_array($allKeys)) {
						$keys = array_merge($keys, $allKeys);
						$keys = array_unique($keys); // Remove duplicates
					}
					break;
				case 'silenced':
					// Silenced jobs are in a sorted set
					$silencedJobsKey = $horizonPrefix . 'silenced_jobs';
					$jobIds = $redis->zrange($silencedJobsKey, 0, -1);
					$jobIds = is_array($jobIds) ? $jobIds : [];
					foreach ($jobIds as $jobId) {
						$keys[] = $horizonPrefix . $jobId;
					}
					// Also check for silenced:* pattern
					$pattern = $horizonPrefix . 'silenced:*';
					$allKeys = $redis->keys($pattern);
					if (is_array($allKeys)) {
						$keys = array_merge($keys, $allKeys);
					}
					break;
				case 'pending':
				default:
					// Pending jobs are in the pending_jobs sorted set
					// But we need to exclude jobs that are also in failed_jobs
					$pendingJobsKey = $horizonPrefix . 'pending_jobs';
					$failedJobsKey = $horizonPrefix . 'failed_jobs';
					try {
						$jobIds = $redis->zrange($pendingJobsKey, 0, -1);
						$jobIds = is_array($jobIds) ? $jobIds : [];
						if (config('app.debug')) {
							\Log::debug('Pending jobs from sorted set', [
								'key' => $pendingJobsKey,
								'job_ids' => $jobIds,
								'count' => count($jobIds),
							]);
						}
						foreach ($jobIds as $jobId) {
							// Skip jobs that are in the failed_jobs sorted set
							// But we'll also check the job data later to be sure
							if ($redis->zscore($failedJobsKey, $jobId) !== false) {
								if (config('app.debug')) {
									\Log::debug('Skipping pending job that is also in failed_jobs', ['job_id' => $jobId]);
								}
								continue;
							}

							$jobKey = $horizonPrefix . $jobId;
							if ($redis->exists($jobKey)) {
								$keys[] = $jobKey;
							} else {
								if (config('app.debug')) {
									\Log::debug('Job key not found', ['key' => $jobKey]);
								}
							}
						}
					} catch (\Throwable $e) {
						\Log::error('Error getting pending jobs', [
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString(),
						]);
					}

					// Also check the actual Laravel queue for jobs that haven't been picked up by Horizon yet
					$queueConnection = config('queue.connections.redis.queue', 'default');
					$queuePrefix = config('queue.connections.redis.prefix', '');

					// Get all queue names to check
					$queuesToCheck = [];
					if ($queue) {
						$queuesToCheck[] = $queue;
					} else {
						// Get all queues from Horizon config or default
						$environments = config('horizon.environments', []);
						foreach ($environments as $env => $config) {
							if (isset($config['supervisor-1']['queue'])) {
								$queuesToCheck = array_merge($queuesToCheck, (array) $config['supervisor-1']['queue']);
							}
						}
						if (empty($queuesToCheck)) {
							$queuesToCheck = [$queueConnection];
						}
						$queuesToCheck = array_unique($queuesToCheck);
					}

					// Check each queue for pending jobs
					foreach ($queuesToCheck as $queueName) {
						// Laravel queue key format: {prefix}queues:{queueName}
						$laravelQueueKey = ($queuePrefix ? $queuePrefix . ':' : '') . 'queues:' . $queueName;

						try {
							// Get job IDs from the queue (sorted set)
							$queueJobIds = $redis->zrange($laravelQueueKey, 0, -1);
							if (is_array($queueJobIds) && !empty($queueJobIds)) {
								foreach ($queueJobIds as $jobId) {
									// Try to find the job in Horizon's storage
									$horizonJobKey = $horizonPrefix . $jobId;
									if ($redis->exists($horizonJobKey)) {
										if (!in_array($horizonJobKey, $keys)) {
											$keys[] = $horizonJobKey;
										}
									}
								}
							}
						} catch (\Throwable $e) {
							// Ignore errors for queue key access
							if (config('app.debug')) {
								\Log::debug('Error accessing queue key', [
									'key' => $laravelQueueKey,
									'error' => $e->getMessage(),
								]);
							}
						}
					}
					break;
			}

			// Log for debugging
			if (config('app.debug')) {
				\Log::debug('Horizon jobs query', [
					'type' => $type,
					'prefix' => $horizonPrefix,
					'keys_found' => count($keys),
					'sample_keys' => array_slice($keys, 0, 5),
				]);
			}

			// Fetch job data
			foreach ($keys as $key) {
				try {
					// Handle synthetic keys (for completed/failed jobs where data was cleaned up)
					if (strpos($key, ':synthetic:') !== false) {
						// Check if it's a failed job synthetic entry
						if (strpos($key, ':synthetic:failed:') !== false) {
							$jobId = str_replace($horizonPrefix . 'synthetic:failed:', '', $key);
							// Get timestamp from failed_jobs sorted set (score is the timestamp)
							$failedJobsKey = $horizonPrefix . 'failed_jobs';
							$score = $redis->zscore($failedJobsKey, $jobId);
							$timestamp = $score ? (int) $score : time();

							$job = [
								'id' => $jobId,
								'uuid' => $jobId,
								'status' => 'failed',
								'displayName' => 'Failed Job',
								'name' => 'Failed Job',
								'created_at' => $timestamp,
								'failed_at' => $timestamp,
								'queue' => 'unknown',
								'payload' => [
									'displayName' => 'Job data has been cleaned up by Horizon',
									'uuid' => $jobId,
									'note' => 'The full job data is no longer available in Redis. This job was failed but its data has been removed by Horizon\'s cleanup process.',
								],
								'exception' => [
									'message' => 'Job data has been cleaned up by Horizon',
									'note' => 'The exception details are no longer available.',
								],
							];
						} else {
							// Completed job synthetic entry
							$jobId = str_replace($horizonPrefix . 'synthetic:', '', $key);
							// Get timestamp from recent_jobs sorted set (score is the timestamp)
							$recentJobsKey = $horizonPrefix . 'recent_jobs';
							$score = $redis->zscore($recentJobsKey, $jobId);
							$timestamp = $score ? (int) $score : time();

							$job = [
								'id' => $jobId,
								'uuid' => $jobId,
								'status' => 'completed',
								'displayName' => 'Completed Job',
								'name' => 'Completed Job',
								'created_at' => $timestamp,
								'completed_at' => $timestamp,
								'queue' => 'unknown',
								'payload' => [
									'displayName' => 'Job data has been cleaned up by Horizon',
									'uuid' => $jobId,
								],
							];
						}

						$jobs[] = $job;
						continue;
					}

					// Check if key exists and is a hash
					if (!$redis->exists($key)) {
						if (config('app.debug')) {
							\Log::debug('Job key does not exist', ['key' => $key]);
						}
						continue;
					}

					$jobData = $redis->hgetall($key);
					if (empty($jobData) || !is_array($jobData)) {
						if (config('app.debug')) {
							\Log::debug('Job data is empty or not an array', [
								'key' => $key,
								'type' => $redis->type($key),
							]);
						}
						continue;
					}

					// Decode job data
					$job = [];
					foreach ($jobData as $field => $value) {
						if (in_array($field, ['payload', 'exception', 'tags']) && is_string($value)) {
							$decoded = json_decode($value, true);
							$job[$field] = $decoded !== null ? $decoded : $value;
						} else {
							$job[$field] = $value;
						}
					}

					// Get job ID for filtering
					$jobId = $job['id'] ?? $job['uuid'] ?? null;
					if ($jobId) {
						// Check which sorted sets this job belongs to
						$failedJobsKey = $horizonPrefix . 'failed_jobs';
						$pendingJobsKey = $horizonPrefix . 'pending_jobs';
						$recentJobsKey = $horizonPrefix . 'recent_jobs';

						$isInFailed = $redis->zscore($failedJobsKey, $jobId) !== false;
						$isInPending = $redis->zscore($pendingJobsKey, $jobId) !== false;
						$isInRecent = $redis->zscore($recentJobsKey, $jobId) !== false;

						// Also check job data for status indicators
						$hasException = isset($job['exception']) && !empty($job['exception']);
						$jobStatus = $job['status'] ?? null;
						$hasFailedAt = isset($job['failed_at']) && !empty($job['failed_at']);
						$hasCompletedAt = isset($job['completed_at']) && !empty($job['completed_at']);

						// Determine actual job status
						// Priority: failed_jobs > exception/failed_at in data > completed_at > pending_jobs > recent_jobs
						$actualStatus = null;
						if ($isInFailed || $hasException || $hasFailedAt || $jobStatus === 'failed') {
							$actualStatus = 'failed';
						} elseif ($hasCompletedAt && !$isInPending && !$isInFailed) {
							$actualStatus = 'completed';
						} elseif ($isInPending && !$isInFailed && !$hasException && !$hasFailedAt) {
							$actualStatus = 'pending';
						} elseif ($isInRecent && !$isInFailed && !$hasException) {
							// If in recent_jobs and not failed, it's completed (even if also in pending, it's transitioning)
							$actualStatus = 'completed';
						} elseif ($jobStatus) {
							$actualStatus = $jobStatus;
						} else {
							// Fallback: use sorted set membership
							if ($isInFailed) {
								$actualStatus = 'failed';
							} elseif ($isInPending) {
								$actualStatus = 'pending';
							} elseif ($isInRecent) {
								$actualStatus = 'completed';
							}
						}

						// Set status in job data
						$job['status'] = $actualStatus;

						// Filter jobs based on the requested type
						// Only include jobs that actually belong to the requested category
						if ($type === 'failed' && $actualStatus !== 'failed') {
							// Skip this job - it's not actually failed
							continue;
						} elseif ($type === 'pending' && $actualStatus !== 'pending') {
							// Skip this job - it's not pending
							continue;
						} elseif ($type === 'completed' && $actualStatus !== 'completed') {
							// Skip this job - it's not completed
							continue;
						}
					} else {
						// If we can't determine status, use the type from the request
						// This handles synthetic jobs and edge cases
						if ($type === 'failed' || $type === 'pending' || $type === 'completed') {
							$job['status'] = $type;
						}
					}

					// Apply search filter
					if ($search) {
						$matches = false;
						if (isset($job['displayName']) && is_string($job['displayName']) && stripos($job['displayName'], $search) !== false) {
							$matches = true;
						}
						if (isset($job['queue']) && is_string($job['queue']) && stripos($job['queue'], $search) !== false) {
							$matches = true;
						}
						if (isset($job['tags']) && is_array($job['tags'])) {
							foreach ($job['tags'] as $tag) {
								if (is_string($tag) && stripos($tag, $search) !== false) {
									$matches = true;
									break;
								}
							}
						}
						if (!$matches) {
							continue;
						}
					}

					// Apply queue filter
					if ($queue && isset($job['queue']) && $job['queue'] !== $queue) {
						continue;
					}

					$jobs[] = $job;
				} catch (\Throwable $e) {
					// Skip this job and continue
					continue;
				}
			}

			// Sort by created_at descending
			usort($jobs, function ($a, $b) {
				$timeA = isset($a['created_at']) ? (is_numeric($a['created_at']) ? $a['created_at'] : 0) : 0;
				$timeB = isset($b['created_at']) ? (is_numeric($b['created_at']) ? $b['created_at'] : 0) : 0;
				return $timeB <=> $timeA;
			});

			// Paginate
			$total = count($jobs);
			$offset = ($page - 1) * $perPage;
			$jobs = array_slice($jobs, $offset, $perPage);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'jobs' => $jobs,
					'total' => $total,
					'page' => $page,
					'per_page' => $perPage,
					'total_pages' => ceil($total / $perPage),
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to get Horizon jobs', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
				'type' => $request->input('type'),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get Horizon jobs: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get detailed information for a specific Horizon job
	 */
	public function getHorizonJobDetails(Request $request, $id)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			// Get Horizon's Redis connection and prefix
			$horizonConnection = config('horizon.use', 'default');
			// Get the actual prefix from config - it's dynamic based on app name
			$horizonPrefix = config('horizon.prefix');
			if (empty($horizonPrefix)) {
				// Fallback to default pattern if not set
				$appName = config('app.name', 'laravel');
				$horizonPrefix = \Illuminate\Support\Str::slug($appName, '_') . '_horizon:';
			}

			// Get Redis connection config
			$redisConfig = config("database.redis.{$horizonConnection}");
			if (!$redisConfig) {
				$redisConfig = config('database.redis.default');
			}

			// Create a direct Redis connection without Laravel's prefix
			// Horizon stores keys without the database prefix
			$redis = new \Redis();
			$redis->connect(
				$redisConfig['host'] ?? '127.0.0.1',
				$redisConfig['port'] ?? 6379
			);

			if (isset($redisConfig['password']) && $redisConfig['password']) {
				$redis->auth($redisConfig['password']);
			}

			if (isset($redisConfig['database'])) {
				$redis->select($redisConfig['database'] ?? 0);
			}

			// Try different key patterns - Horizon stores jobs as {prefix}{job-id}
			$keyPatterns = [
				$horizonPrefix . $id,
				$horizonPrefix . "job:{$id}",
				$horizonPrefix . "failed:{$id}",
				$horizonPrefix . "completed:{$id}",
				$horizonPrefix . "silenced:{$id}",
				$horizonPrefix . "recent:{$id}",
			];

			$jobData = null;
			foreach ($keyPatterns as $key) {
				if ($redis->exists($key)) {
					$data = $redis->hgetall($key);
					if (!empty($data) && is_array($data)) {
						$jobData = $data;
						break;
					}
				}
			}

			// If job data not found, check if it's in recent_jobs (completed but cleaned up)
			if (!$jobData) {
				$recentJobsKey = $horizonPrefix . 'recent_jobs';
				$recentScore = $redis->zscore($recentJobsKey, $id);

				// Also check pending_jobs and failed_jobs
				$pendingJobsKey = $horizonPrefix . 'pending_jobs';
				$pendingScore = $redis->zscore($pendingJobsKey, $id);

				$failedJobsKey = $horizonPrefix . 'failed_jobs';
				$failedScore = $redis->zscore($failedJobsKey, $id);

				// If found in any of these sorted sets, create synthetic job data
				// Priority: failed > completed (recent_jobs) > pending
				// This ensures completed jobs show correctly even if they're also in pending_jobs
				if ($recentScore !== false || $pendingScore !== false || $failedScore !== false) {
					$timestamp = $recentScore ? (int) $recentScore : ($pendingScore ? (int) $pendingScore : ($failedScore ? (int) $failedScore : time()));
					// Determine status: failed takes priority, then completed (recent_jobs), then pending
					$status = $failedScore !== false ? 'failed' : ($recentScore !== false ? 'completed' : 'pending');

					$jobData = [
						'id' => $id,
						'uuid' => $id,
						'status' => $status,
						'displayName' => ucfirst($status) . ' Job',
						'name' => ucfirst($status) . ' Job',
						'created_at' => $timestamp,
						'queue' => 'unknown',
						'payload' => json_encode([
							'displayName' => 'Job data has been cleaned up by Horizon',
							'uuid' => $id,
							'note' => 'The full job data is no longer available in Redis. This job was ' . $status . ' but its data has been removed by Horizon\'s cleanup process.',
						]),
					];

					if ($status === 'completed') {
						$jobData['completed_at'] = $timestamp;
					}

					if ($status === 'failed') {
						$jobData['failed_at'] = $timestamp;
						$jobData['exception'] = json_encode([
							'message' => 'Job data has been cleaned up by Horizon',
							'note' => 'The exception details are no longer available.',
						]);
					}
				}
			}

			if (!$jobData) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job not found'],
					'result' => (object) [],
				], 404);
			}

			// Decode job data
			$job = [];
			foreach ($jobData as $field => $value) {
				if (in_array($field, ['payload', 'exception', 'tags']) && is_string($value)) {
					$decoded = json_decode($value, true);
					$job[$field] = $decoded !== null ? $decoded : $value;
				} else {
					$job[$field] = $value;
				}
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) $job,
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to get Horizon job details', [
				'error' => $e->getMessage(),
				'job_id' => $id,
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get job details: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Retry a failed Horizon job
	 */
	public function retryHorizonJob(Request $request, $id)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			// Get Horizon's Redis connection and prefix
			$horizonConnection = config('horizon.use', 'default');
			$horizonPrefix = config('horizon.prefix');
			if (empty($horizonPrefix)) {
				$appName = config('app.name', 'laravel');
				$horizonPrefix = \Illuminate\Support\Str::slug($appName, '_') . '_horizon:';
			}

			// Get Redis connection
			$redisConfig = config("database.redis.{$horizonConnection}");
			if (!$redisConfig) {
				$redisConfig = config('database.redis.default');
			}

			$redis = new \Redis();
			$redis->connect(
				$redisConfig['host'] ?? '127.0.0.1',
				$redisConfig['port'] ?? 6379
			);

			if (isset($redisConfig['password']) && $redisConfig['password']) {
				$redis->auth($redisConfig['password']);
			}

			if (isset($redisConfig['database'])) {
				$redis->select($redisConfig['database'] ?? 0);
			}

			// Try to find the job in failed_jobs
			$failedJobsKey = $horizonPrefix . 'failed_jobs';
			$score = $redis->zscore($failedJobsKey, $id);

			if ($score === false) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job not found in failed jobs'],
					'result' => (object) [],
				], 404);
			}

			// Try to get the job data
			$keyPatterns = [
				$horizonPrefix . $id,
				$horizonPrefix . "failed:{$id}",
				$horizonPrefix . "job:{$id}",
			];

			$jobData = null;
			foreach ($keyPatterns as $key) {
				if ($redis->exists($key)) {
					$data = $redis->hgetall($key);
					if (!empty($data) && is_array($data)) {
						$jobData = $data;
						break;
					}
				}
			}

			if (!$jobData) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job data not found. Cannot retry a job without its data.'],
					'result' => (object) [],
				], 404);
			}

			// Decode payload
			$payload = isset($jobData['payload']) ? json_decode($jobData['payload'], true) : null;
			if (!$payload) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Invalid job payload'],
					'result' => (object) [],
				], 400);
			}

			// Get job class and data
			$jobClass = $payload['displayName'] ?? $payload['job'] ?? null;
			$jobDataArray = $payload['data'] ?? [];

			if (!$jobClass) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job class not found in payload'],
					'result' => (object) [],
				], 400);
			}

			// Check if class exists
			if (!class_exists($jobClass)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ["Job class '{$jobClass}' not found"],
					'result' => (object) [],
				], 404);
			}

			// Create job instance and dispatch
			try {
				$reflection = new \ReflectionClass($jobClass);
				$jobInstance = $reflection->newInstanceArgs($jobDataArray);

				// Get queue from job data or use default
				$queue = $jobData['queue'] ?? $payload['queue'] ?? 'default';

				// Dispatch the job
				dispatch($jobInstance)->onQueue($queue);

				\Log::info('Retried Horizon job', [
					'job_id' => $id,
					'job_class' => $jobClass,
					'queue' => $queue,
				]);

				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'message' => 'Job retried successfully',
						'job_id' => $id,
					],
				], 200);
			} catch (\Throwable $e) {
				\Log::error('Failed to retry job', [
					'job_id' => $id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);

				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Failed to retry job: ' . $e->getMessage()],
					'result' => (object) [],
				], 400);
			}
		} catch (\Throwable $e) {
			\Log::error('Failed to retry Horizon job', [
				'error' => $e->getMessage(),
				'job_id' => $id,
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to retry job: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Delete a Horizon job
	 */
	public function deleteHorizonJob(Request $request, $id)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			// Get Horizon's Redis connection and prefix
			$horizonConnection = config('horizon.use', 'default');
			$horizonPrefix = config('horizon.prefix');
			if (empty($horizonPrefix)) {
				$appName = config('app.name', 'laravel');
				$horizonPrefix = \Illuminate\Support\Str::slug($appName, '_') . '_horizon:';
			}

			// Get Redis connection
			$redisConfig = config("database.redis.{$horizonConnection}");
			if (!$redisConfig) {
				$redisConfig = config('database.redis.default');
			}

			$redis = new \Redis();
			$redis->connect(
				$redisConfig['host'] ?? '127.0.0.1',
				$redisConfig['port'] ?? 6379
			);

			if (isset($redisConfig['password']) && $redisConfig['password']) {
				$redis->auth($redisConfig['password']);
			}

			if (isset($redisConfig['database'])) {
				$redis->select($redisConfig['database'] ?? 0);
			}

			$deleted = false;

			// Try to delete from various sorted sets and keys
			$sortedSets = [
				$horizonPrefix . 'failed_jobs',
				$horizonPrefix . 'pending_jobs',
				$horizonPrefix . 'recent_jobs',
				$horizonPrefix . 'silenced_jobs',
			];

			foreach ($sortedSets as $setKey) {
				if ($redis->zrem($setKey, $id)) {
					$deleted = true;
				}
			}

			// Try to delete job data keys
			$keyPatterns = [
				$horizonPrefix . $id,
				$horizonPrefix . "failed:{$id}",
				$horizonPrefix . "completed:{$id}",
				$horizonPrefix . "job:{$id}",
				$horizonPrefix . "recent:{$id}",
				$horizonPrefix . "silenced:{$id}",
			];

			foreach ($keyPatterns as $key) {
				if ($redis->exists($key)) {
					$redis->del($key);
					$deleted = true;
				}
			}

			if (!$deleted) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job not found'],
					'result' => (object) [],
				], 404);
			}

			\Log::info('Deleted Horizon job', ['job_id' => $id]);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'message' => 'Job deleted successfully',
					'job_id' => $id,
				],
			], 200);
		} catch (\Throwable $e) {
			\Log::error('Failed to delete Horizon job', [
				'error' => $e->getMessage(),
				'job_id' => $id,
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to delete job: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Execute a Horizon job immediately (re-dispatch)
	 */
	public function executeHorizonJob(Request $request, $id)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			// Get job details first
			$jobDetails = $this->getHorizonJobDetails($request, $id);
			$jobDetailsData = json_decode($jobDetails->getContent(), true);

			if (!$jobDetailsData['success'] || !isset($jobDetailsData['result'])) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job not found'],
					'result' => (object) [],
				], 404);
			}

			$job = $jobDetailsData['result'];
			$payload = is_array($job->payload ?? null) ? $job->payload : (isset($job->payload) ? json_decode($job->payload, true) : null);

			if (!$payload) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job payload not available'],
					'result' => (object) [],
				], 400);
			}

			// Get job class and data
			$jobClass = $payload['displayName'] ?? $payload['job'] ?? null;
			$jobDataArray = $payload['data'] ?? [];

			if (!$jobClass) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job class not found in payload'],
					'result' => (object) [],
				], 400);
			}

			// Check if class exists
			if (!class_exists($jobClass)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ["Job class '{$jobClass}' not found"],
					'result' => (object) [],
				], 404);
			}

			// Create job instance and dispatch
			try {
				$reflection = new \ReflectionClass($jobClass);
				$jobInstance = $reflection->newInstanceArgs($jobDataArray);

				// Get queue from job data or use default
				$queue = $job->queue ?? $payload['queue'] ?? 'default';

				// Dispatch the job
				dispatch($jobInstance)->onQueue($queue);

				\Log::info('Executed Horizon job', [
					'job_id' => $id,
					'job_class' => $jobClass,
					'queue' => $queue,
				]);

				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'message' => 'Job executed successfully',
						'job_id' => $id,
					],
				], 200);
			} catch (\Throwable $e) {
				\Log::error('Failed to execute job', [
					'job_id' => $id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);

				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Failed to execute job: ' . $e->getMessage()],
					'result' => (object) [],
				], 400);
			}
		} catch (\Throwable $e) {
			\Log::error('Failed to execute Horizon job', [
				'error' => $e->getMessage(),
				'job_id' => $id,
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to execute job: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Create a new Horizon job
	 */
	public function createHorizonJob(Request $request)
	{
		try {
			if (!class_exists('Laravel\Horizon\Horizon')) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Horizon is not installed'],
					'result' => (object) [],
				], 404);
			}

			$jobClass = $request->input('job_class');
			$jobData = $request->input('job_data', []);
			$queue = $request->input('queue', 'default');

			if (!$jobClass) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Job class is required'],
					'result' => (object) [],
				], 400);
			}

			// Check if class exists
			if (!class_exists($jobClass)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ["Job class '{$jobClass}' not found"],
					'result' => (object) [],
				], 404);
			}

			// Check if class implements ShouldQueue
			$reflection = new \ReflectionClass($jobClass);
			if (!$reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ["Job class '{$jobClass}' does not implement ShouldQueue"],
					'result' => (object) [],
				], 400);
			}

			// Create job instance
			try {
				$jobInstance = $reflection->newInstanceArgs(is_array($jobData) ? $jobData : []);

				// Dispatch the job to the queue
				// Note: Job ID is only available after the job is pushed to the queue
				// We'll dispatch it and let Horizon assign the ID
				dispatch($jobInstance)->onQueue($queue);

				\Log::info('Created Horizon job', [
					'job_class' => $jobClass,
					'queue' => $queue,
				]);

				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'message' => 'Job created and dispatched successfully. Check the pending jobs list to see the job ID.',
						'job_class' => $jobClass,
						'queue' => $queue,
					],
				], 200);
			} catch (\Throwable $e) {
				\Log::error('Failed to create job', [
					'job_class' => $jobClass,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]);

				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Failed to create job: ' . $e->getMessage()],
					'result' => (object) [],
				], 400);
			}
		} catch (\Throwable $e) {
			\Log::error('Failed to create Horizon job', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to create job: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Execute shell command
	 */
	public function executeShellCommand(Request $request)
	{
		try {
			$request->validate([
				'command' => 'required|string',
			]);

			$userCommand = trim($request->input('command'));

			if (empty($userCommand)) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Command cannot be empty'],
					'result' => (object) [],
				], 400);
			}

			$startTime = microtime(true);
			$userId = auth()->id();

			// Get or initialize working directory for this user
			$cwdKey = 'overlord_shell_cwd_' . ($userId ?? 'guest');
			$currentDir = Cache::get($cwdKey, base_path());

			// Ensure current directory exists and is within project root
			if (!is_dir($currentDir) || !str_starts_with(realpath($currentDir), realpath(base_path()))) {
				$currentDir = base_path();
				Cache::put($cwdKey, $currentDir, now()->addHours(24));
			}

			// Handle cd command specially
			if (preg_match('/^\s*cd\s+(.+)$/', $userCommand, $matches)) {
				$targetPath = trim($matches[1], " \t\n\r\0\x0B\"'");

				// Handle ~ expansion
				if (str_starts_with($targetPath, '~')) {
					// Use env() helper instead of direct $_SERVER access for better security
					$homeDir = env('HOME') ?: (getenv('HOME') ?: '/');
					$targetPath = $homeDir . substr($targetPath, 1);
				}

				// Resolve relative paths
				if (!str_starts_with($targetPath, '/')) {
					$targetPath = $currentDir . '/' . $targetPath;
				}

				// Normalize path
				$targetPath = realpath($targetPath);

				// Validate path is within project root (security)
				if ($targetPath === false || !str_starts_with($targetPath, realpath(base_path()))) {
					return response()->json([
						'success' => false,
						'status_code' => 'ERROR',
						'errors' => ['Invalid directory: Cannot access directory outside project root'],
						'result' => (object) [],
					], 400);
				}

				// Update working directory
				Cache::put($cwdKey, $targetPath, now()->addHours(24));

				return response()->json([
					'success' => true,
					'status_code' => 'SUCCESS',
					'errors' => [],
					'result' => (object) [
						'type' => 'text',
						'output' => '',
						'raw' => '',
						'exit_code' => 0,
						'execution_time' => round((microtime(true) - $startTime) * 1000, 2),
						'current_directory' => $targetPath,
					],
				], 200);
			}

			// Detect shell
			$shell = getenv('SHELL') ?: '/bin/bash';
			if (!file_exists($shell)) {
				// Fallback to common shells
				$possibleShells = ['/bin/bash', '/bin/sh', '/usr/bin/bash', '/usr/bin/sh'];
				foreach ($possibleShells as $possibleShell) {
					if (file_exists($possibleShell)) {
						$shell = $possibleShell;
						break;
					}
				}
			}

			// Execute command using proc_open for better control
			$descriptorspec = [
				0 => ['pipe', 'r'], // stdin
				1 => ['pipe', 'w'], // stdout
				2 => ['pipe', 'w'], // stderr
			];

			$process = proc_open(
				escapeshellcmd($shell) . ' -c ' . escapeshellarg($userCommand),
				$descriptorspec,
				$pipes,
				$currentDir,
				[
					// Use env() helper instead of direct $_SERVER access for better security
					'HOME' => env('HOME') ?: (getenv('HOME') ?: '/'),
					'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
				]
			);

			if (!is_resource($process)) {
				throw new \RuntimeException('Failed to start shell process');
			}

			// Close stdin
			fclose($pipes[0]);

			// Set timeout (60 seconds)
			$timeout = 60;
			$startTimeProcess = time();

			// Read output
			$stdout = '';
			$stderr = '';
			$stdoutDone = false;
			$stderrDone = false;

			while (true) {
				$read = [$pipes[1], $pipes[2]];
				$write = null;
				$except = null;

				// Check timeout
				if (time() - $startTimeProcess > $timeout) {
					proc_terminate($process);
					throw new \RuntimeException('Command execution timeout (60 seconds)');
				}

				$changed = stream_select($read, $write, $except, 1);

				if ($changed === false) {
					break;
				}

				if ($changed === 0) {
					continue;
				}

				foreach ($read as $stream) {
					if ($stream === $pipes[1]) {
						$data = fread($stream, 8192);
						if ($data === false || strlen($data) === 0) {
							$stdoutDone = true;
						} else {
							$stdout .= $data;
						}
					} elseif ($stream === $pipes[2]) {
						$data = fread($stream, 8192);
						if ($data === false || strlen($data) === 0) {
							$stderrDone = true;
						} else {
							$stderr .= $data;
						}
					}
				}

				if ($stdoutDone && $stderrDone) {
					break;
				}
			}

			// Close pipes
			fclose($pipes[1]);
			fclose($pipes[2]);

			// Get exit code
			// Note: exitcode is only available after process has terminated
			$exitCode = -1;
			$status = proc_get_status($process);

			// Wait for process to finish and get exit code
			while ($status['running']) {
				usleep(100000); // 100ms
				$status = proc_get_status($process);
			}

			$exitCode = $status['exitcode'] ?? -1;

			// Close process
			proc_close($process);

			$executionTime = round((microtime(true) - $startTime) * 1000, 2);

			// Combine output
			$output = $stdout;
			if (!empty($stderr)) {
				$output .= ($output ? "\n" : '') . $stderr;
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'type' => $exitCode === 0 ? 'text' : 'error',
					'output' => $output,
					'raw' => $output,
					'exit_code' => $exitCode,
					'execution_time' => $executionTime,
					'current_directory' => $currentDir,
				],
			], 200);

		} catch (\Throwable $e) {
			\Log::error('Failed to execute shell command', [
				'error' => $e->getMessage(),
				'command' => $request->input('command'),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to execute command: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}
}