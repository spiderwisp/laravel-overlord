<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LogsController extends Controller
{
	/**
	 * List available log files
	 */
	public function listLogs()
	{
		try {
			$logs = [];

			// Laravel logs
			$laravelLogs = $this->getLaravelLogs();
			if (!empty($laravelLogs)) {
				$logs['laravel'] = $laravelLogs;
			}

			// PHP logs
			$phpLogs = $this->getPhpLogs();
			if (!empty($phpLogs)) {
				$logs['php'] = $phpLogs;
			}

			// Nginx logs
			$nginxLogs = $this->getNginxLogs();
			if (!empty($nginxLogs)) {
				$logs['nginx'] = $nginxLogs;
			}

			// System logs
			$systemLogs = $this->getSystemLogs();
			if (!empty($systemLogs)) {
				$logs['system'] = $systemLogs;
			}

			return response()->json([
				'success' => true,
				'result' => $logs,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to list logs', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to list logs: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get surrounding lines around a specific line number
	 */
	public function getSurroundingLines(Request $request)
	{
		try {
			$request->validate([
				'file' => 'required|string',
				'line_number' => 'required|integer|min:1',
				'context_lines' => 'nullable|integer|min:1|max:50',
			]);

			$filePath = $request->input('file');
			$lineNumber = (int) $request->input('line_number');
			$contextLines = (int) ($request->input('context_lines', 5));

			// Validate file path
			if (!$this->isValidLogPath($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid log file path',
				], 400);
			}

			if (!file_exists($filePath) || !is_readable($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Log file not found or not readable',
				], 404);
			}

			$surroundingLines = $this->readSurroundingLines($filePath, $lineNumber, $contextLines);

			return response()->json([
				'success' => true,
				'result' => $surroundingLines,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to get surrounding lines', [
				'error' => $e->getMessage(),
				'file' => $request->input('file'),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to read surrounding lines: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get log file content
	 */
	public function getLogContent(Request $request)
	{
		try {
			$request->validate([
				'file' => 'required|string',
				'lines' => 'nullable|integer|min:1|max:10000',
				'offset' => 'nullable|integer|min:0',
				'search' => 'nullable|string|max:500',
			]);

			$filePath = $request->input('file');
			$lines = $request->input('lines', 500);
			$offset = $request->input('offset', 0);
			$search = $request->input('search');

			// Validate and sanitize file path
			if (!$this->isValidLogPath($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid log file path',
				], 400);
			}

			if (!file_exists($filePath) || !is_readable($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Log file not found or not readable',
				], 404);
			}

			$content = $this->readLogFile($filePath, $lines, $offset, $search);

			return response()->json([
				'success' => true,
				'result' => $content,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to get log content', [
				'error' => $e->getMessage(),
				'file' => $request->input('file'),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to read log file: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Search logs
	 */
	public function searchLogs(Request $request)
	{
		try {
			$request->validate([
				'file' => 'required|string',
				'query' => 'nullable|string|max:500',
				'level' => 'nullable|string|in:ERROR,WARNING,INFO,DEBUG,CRITICAL,ALERT,EMERGENCY',
				'date_from' => 'nullable|date',
				'date_to' => 'nullable|date|after_or_equal:date_from',
			]);

			$filePath = $request->input('file');
			$query = $request->input('query');
			$level = $request->input('level');
			$dateFrom = $request->input('date_from');
			$dateTo = $request->input('date_to');

			// Validate file path
			if (!$this->isValidLogPath($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid log file path',
				], 400);
			}

			if (!file_exists($filePath) || !is_readable($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Log file not found or not readable',
				], 404);
			}

			$results = $this->searchLogFile($filePath, $query, $level, $dateFrom, $dateTo);

			return response()->json([
				'success' => true,
				'result' => $results,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to search logs', [
				'error' => $e->getMessage(),
				'file' => $request->input('file'),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to search logs: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get log file statistics
	 */
	public function getLogStats(Request $request)
	{
		try {
			$request->validate([
				'file' => 'required|string',
			]);

			$filePath = $request->input('file');

			// Validate file path
			if (!$this->isValidLogPath($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid log file path',
				], 400);
			}

			if (!file_exists($filePath) || !is_readable($filePath)) {
				return response()->json([
					'success' => false,
					'error' => 'Log file not found or not readable',
				], 404);
			}

			$stats = $this->getLogFileStats($filePath);

			return response()->json([
				'success' => true,
				'result' => $stats,
			]);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'success' => false,
				'error' => 'Validation failed',
				'errors' => $e->errors(),
			], 422);
		} catch (\Exception $e) {
			Log::error('Failed to get log stats', [
				'error' => $e->getMessage(),
				'file' => $request->input('file'),
			]);

			return response()->json([
				'success' => false,
				'error' => 'Failed to get log stats: ' . $e->getMessage(),
			], 500);
		}
	}

	/**
	 * Get Laravel log files
	 */
	protected function getLaravelLogs(): array
	{
		$logs = [];
		$logPath = storage_path('logs');

		if (!is_dir($logPath) || !is_readable($logPath)) {
			return $logs;
		}

		try {
			$files = File::files($logPath);

			foreach ($files as $file) {
				$fileName = $file->getFilename();

				// Include laravel.log and laravel-*.log files
				// Also include any .log files in the logs directory
				if (
					preg_match('/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/', $fileName) ||
					(preg_match('/\.log$/', $fileName) && $fileName !== '.gitignore')
				) {
					$filePath = $file->getPathname();

					// Skip .gitignore and other hidden files
					if (strpos($fileName, '.') === 0 && $fileName !== '.log') {
						continue;
					}

					$logs[] = [
						'name' => $fileName,
						'path' => $filePath,
						'type' => 'laravel',
						'size' => filesize($filePath),
						'last_modified' => date('Y-m-d H:i:s', filemtime($filePath)),
						'readable' => is_readable($filePath),
					];
				}
			}

			// Sort by last modified (newest first)
			usort($logs, function ($a, $b) {
				return strtotime($b['last_modified']) - strtotime($a['last_modified']);
			});
		} catch (\Exception $e) {
			Log::warning('Failed to scan Laravel logs', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
		}

		return $logs;
	}

	/**
	 * Get PHP log files
	 */
	protected function getPhpLogs(): array
	{
		$logs = [];
		$possiblePaths = [
			ini_get('error_log'),
			'/var/log/php-fpm/error.log',
			'/var/log/php8.2-fpm.log',
			'/var/log/php8.1-fpm.log',
			'/var/log/php8.0-fpm.log',
			'/var/log/php/error.log',
			'/var/log/php-fpm.log',
			'/var/log/supervisor/php8.2-fpm-stderr.log',
			'/var/log/supervisor/php8.2-fpm-stdout.log',
			'/var/log/supervisor/php8.1-fpm-stderr.log',
			'/var/log/supervisor/php8.1-fpm-stdout.log',
			'/var/log/supervisor/php8.0-fpm-stderr.log',
			'/var/log/supervisor/php8.0-fpm-stdout.log',
		];

		foreach ($possiblePaths as $path) {
			if (empty($path)) {
				continue;
			}

			if (file_exists($path) && is_readable($path)) {
				$logs[] = [
					'name' => basename($path),
					'path' => $path,
					'type' => 'php',
					'size' => filesize($path),
					'last_modified' => date('Y-m-d H:i:s', filemtime($path)),
					'readable' => true,
				];
			}
		}

		return $logs;
	}

	/**
	 * Get Nginx log files
	 */
	protected function getNginxLogs(): array
	{
		$logs = [];

		// Check standard nginx log directory
		$nginxLogPath = '/var/log/nginx';
		if (is_dir($nginxLogPath) && is_readable($nginxLogPath)) {
			try {
				$commonLogs = ['access.log', 'error.log'];

				foreach ($commonLogs as $logName) {
					$logPath = $nginxLogPath . '/' . $logName;
					if (file_exists($logPath) && is_readable($logPath)) {
						$logs[] = [
							'name' => $logName,
							'path' => $logPath,
							'type' => 'nginx',
							'size' => filesize($logPath),
							'last_modified' => date('Y-m-d H:i:s', filemtime($logPath)),
							'readable' => true,
						];
					}
				}
			} catch (\Exception $e) {
				Log::warning('Failed to scan Nginx logs', ['error' => $e->getMessage()]);
			}
		}

		// Check supervisor logs (Laravel Sail uses supervisor)
		$supervisorLogPath = '/var/log/supervisor';
		if (is_dir($supervisorLogPath) && is_readable($supervisorLogPath)) {
			try {
				$supervisorLogs = [
					'nginx-stderr.log',
					'nginx-stdout.log',
					'nginx-error.log',
					'nginx-access.log',
				];

				foreach ($supervisorLogs as $logName) {
					$logPath = $supervisorLogPath . '/' . $logName;
					if (file_exists($logPath) && is_readable($logPath)) {
						$logs[] = [
							'name' => $logName,
							'path' => $logPath,
							'type' => 'nginx',
							'size' => filesize($logPath),
							'last_modified' => date('Y-m-d H:i:s', filemtime($logPath)),
							'readable' => true,
						];
					}
				}
			} catch (\Exception $e) {
				// Silently continue
			}
		}

		return $logs;
	}

	/**
	 * Get system log files
	 */
	protected function getSystemLogs(): array
	{
		$logs = [];
		$systemLogs = [
			'/var/log/syslog',
			'/var/log/messages',
			'/var/log/supervisor/supervisord.log',
			'/var/log/dpkg.log',
			'/var/log/alternatives.log',
		];

		foreach ($systemLogs as $logPath) {
			if (file_exists($logPath) && is_readable($logPath)) {
				$logs[] = [
					'name' => basename($logPath),
					'path' => $logPath,
					'type' => 'system',
					'size' => filesize($logPath),
					'last_modified' => date('Y-m-d H:i:s', filemtime($logPath)),
					'readable' => true,
				];
			}
		}

		// Also check for supervisor logs directory
		$supervisorLogPath = '/var/log/supervisor';
		if (is_dir($supervisorLogPath) && is_readable($supervisorLogPath)) {
			try {
				$files = File::files($supervisorLogPath);
				foreach ($files as $file) {
					$fileName = $file->getFilename();
					$filePath = $file->getPathname();

					// Include supervisor logs that aren't already categorized
					if (
						preg_match('/\.log$/', $fileName) &&
						!preg_match('/(nginx|php|fpm)/i', $fileName)
					) {
						$logs[] = [
							'name' => $fileName,
							'path' => $filePath,
							'type' => 'system',
							'size' => filesize($filePath),
							'last_modified' => date('Y-m-d H:i:s', filemtime($filePath)),
							'readable' => true,
						];
					}
				}
			} catch (\Exception $e) {
				// Silently continue
			}
		}

		return $logs;
	}

	/**
	 * Validate log file path (prevent directory traversal)
	 */
	protected function isValidLogPath(string $path): bool
	{
		// Resolve real path to prevent directory traversal
		$realPath = realpath($path);
		if ($realPath === false) {
			return false;
		}

		// Whitelist allowed directories
		$allowedDirs = [
			storage_path('logs'),
			'/var/log/nginx',
			'/var/log/php-fpm',
			'/var/log/php',
			'/var/log/supervisor',
			'/var/log',
		];

		foreach ($allowedDirs as $allowedDir) {
			if (strpos($realPath, realpath($allowedDir)) === 0) {
				return true;
			}
		}

		// Also allow if it's the exact path from ini_get('error_log')
		$phpErrorLog = ini_get('error_log');
		if (!empty($phpErrorLog) && realpath($phpErrorLog) === $realPath) {
			return true;
		}

		return false;
	}

	/**
	 * Read log file efficiently (tail from end)
	 */
	protected function readLogFile(string $filePath, int $lines = 500, int $offset = 0, ?string $search = null): array
	{
		$fileSize = filesize($filePath);
		if ($fileSize === 0) {
			return [
				'lines' => [],
				'total_lines' => 0,
				'has_more' => false,
				'offset' => 0,
			];
		}

		// For large files, read from end
		$handle = fopen($filePath, 'r');
		if (!$handle) {
			throw new \Exception('Failed to open log file');
		}

		// Count total lines first (approximate for large files)
		$totalLines = $this->countLines($filePath);

		// Read lines from end
		$allLines = [];
		$buffer = '';
		$chunkSize = 8192; // 8KB chunks
		$position = $fileSize;
		$linesRead = 0;
		$targetLines = $lines + $offset;

		// Read backwards from end
		while ($position > 0 && $linesRead < $targetLines) {
			$readSize = min($chunkSize, $position);
			$position -= $readSize;
			fseek($handle, $position);

			$chunk = fread($handle, $readSize);
			$buffer = $chunk . $buffer;

			// Count newlines in buffer
			$newlineCount = substr_count($buffer, "\n");
			if ($newlineCount >= $targetLines) {
				// Extract the last N lines
				$linesArray = explode("\n", $buffer);
				$allLines = array_slice($linesArray, -$targetLines);
				$linesRead = count($allLines);
				break;
			}
		}

		// If we didn't get enough lines, read from beginning
		if ($linesRead < $targetLines && $position == 0) {
			fseek($handle, 0);
			$content = fread($handle, $fileSize);
			$allLines = explode("\n", $content);
			$linesRead = count($allLines);
		}

		fclose($handle);

		// Apply offset and limit
		$startIndex = max(0, $linesRead - $lines - $offset);
		$endIndex = $linesRead - $offset;
		$selectedLines = array_slice($allLines, $startIndex, $endIndex - $startIndex);

		// Apply search filter if provided
		if ($search !== null && $search !== '') {
			$selectedLines = array_filter($selectedLines, function ($line) use ($search) {
				return stripos($line, $search) !== false;
			});
			$selectedLines = array_values($selectedLines);
		}

		// Parse and format lines
		$formattedLines = [];
		$lineNumber = $startIndex + 1;
		foreach ($selectedLines as $line) {
			$parsed = $this->parseLogLine($line, $filePath);
			$formattedLines[] = [
				'line_number' => $lineNumber++,
				'content' => $line,
				'parsed' => $parsed,
			];
		}

		return [
			'lines' => $formattedLines,
			'total_lines' => $totalLines,
			'has_more' => ($startIndex > 0),
			'offset' => $offset,
			'file_size' => $fileSize,
		];
	}

	/**
	 * Count lines in file (efficient for large files)
	 */
	protected function countLines(string $filePath): int
	{
		$file = new \SplFileObject($filePath, 'r');
		$file->seek(PHP_INT_MAX);
		return $file->key() + 1;
	}

	/**
	 * Read surrounding lines around a specific line number
	 */
	protected function readSurroundingLines(string $filePath, int $lineNumber, int $contextLines = 5): array
	{
		$totalLines = $this->countLines($filePath);

		// Calculate line range
		$startLine = max(1, $lineNumber - $contextLines);
		$endLine = min($totalLines, $lineNumber + $contextLines);

		$lines = [];
		$file = new \SplFileObject($filePath, 'r');

		// Skip to start line
		$file->seek($startLine - 1);

		// Read lines in range
		for ($i = $startLine; $i <= $endLine; $i++) {
			$line = $file->current();
			if ($line === false) {
				break;
			}

			$parsed = $this->parseLogLine($line, $filePath);
			$lines[] = [
				'line_number' => $i,
				'content' => rtrim($line),
				'parsed' => $parsed,
				'is_target' => ($i === $lineNumber),
			];

			$file->next();
		}

		return [
			'lines' => $lines,
			'target_line' => $lineNumber,
			'start_line' => $startLine,
			'end_line' => $endLine,
			'total_lines' => $totalLines,
		];
	}

	/**
	 * Parse log line based on log type
	 */
	protected function parseLogLine(string $line, string $filePath): array
	{
		$parsed = [
			'level' => null,
			'timestamp' => null,
			'message' => $line,
			'context' => null,
		];

		// Detect log type from file path
		$logType = $this->detectLogType($filePath);

		switch ($logType) {
			case 'laravel':
				$parsed = $this->parseLaravelLogLine($line);
				break;
			case 'php':
				$parsed = $this->parsePhpLogLine($line);
				break;
			case 'nginx':
				$parsed = $this->parseNginxLogLine($line, $filePath);
				break;
			case 'system':
				$parsed = $this->parseSystemLogLine($line);
				break;
		}

		return $parsed;
	}

	/**
	 * Parse Laravel log line
	 */
	protected function parseLaravelLogLine(string $line): array
	{
		$parsed = [
			'level' => null,
			'timestamp' => null,
			'message' => $line,
			'context' => null,
			'channel' => null,
			'exception_class' => null,
			'exception_message' => null,
			'file' => null,
			'line' => null,
			'stack_trace' => null,
			'related_classes' => [],
		];

		// Laravel format: [2025-01-09 12:34:56] local.ERROR: Error message {"context":"data"}
		if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\w+)\.(\w+):\s+(.+)$/', $line, $matches)) {
			$parsed['timestamp'] = $matches[1];
			$parsed['channel'] = $matches[2];
			$parsed['level'] = strtoupper($matches[3]);
			$message = $matches[4];

			// Try to extract JSON context
			if (preg_match('/^(.+?)\s+(\{.+?\})$/', $message, $msgMatches)) {
				$parsed['message'] = $msgMatches[1];
				$contextJson = $msgMatches[2];
				try {
					$parsed['context'] = json_decode($contextJson, true);
				} catch (\Exception $e) {
					$parsed['context'] = null;
				}
			} else {
				$parsed['message'] = $message;
			}

			// Extract exception class name (e.g., Illuminate\Database\QueryException)
			if (preg_match('/([A-Za-z0-9_\\\\]+Exception):\s*(.+?)(?:\s+in\s+|\s+at\s+|$)/', $parsed['message'], $excMatches)) {
				$parsed['exception_class'] = $excMatches[1];
				$parsed['exception_message'] = trim($excMatches[2]);
			}

			// Extract file path and line number from message or context
			if (preg_match('/in\s+([^\s]+)\s+on\s+line\s+(\d+)/i', $parsed['message'], $fileMatches)) {
				$parsed['file'] = $fileMatches[1];
				$parsed['line'] = (int) $fileMatches[2];
			} elseif (isset($parsed['context']['exception']['file'])) {
				$parsed['file'] = $parsed['context']['exception']['file'];
				$parsed['line'] = $parsed['context']['exception']['line'] ?? null;
			}

			// Extract related class names (models, controllers) from error message
			// Look for patterns like App\Models\User, App\Http\Controllers\...
			if (preg_match_all('/(App\\\\[A-Za-z0-9_\\\\]+)/', $parsed['message'], $classMatches)) {
				$parsed['related_classes'] = array_unique($classMatches[1]);
			}

			// Extract stack trace if available in context
			if (isset($parsed['context']['exception']['trace'])) {
				$parsed['stack_trace'] = $parsed['context']['exception']['trace'];
			} elseif (isset($parsed['context']['trace'])) {
				$parsed['stack_trace'] = $parsed['context']['trace'];
			}
		}

		return $parsed;
	}

	/**
	 * Parse PHP error log line
	 */
	protected function parsePhpLogLine(string $line): array
	{
		$parsed = [
			'level' => null,
			'timestamp' => null,
			'message' => $line,
			'file' => null,
			'line' => null,
			'exception_class' => null,
			'exception_message' => null,
			'related_classes' => [],
		];

		// PHP format: [09-Jan-2025 12:34:56 UTC] PHP Warning: message in /path/to/file.php on line 123
		if (preg_match('/^\[([^\]]+)\]\s+PHP\s+(\w+):\s+(.+?)(?:\s+in\s+(.+?)\s+on\s+line\s+(\d+))?$/', $line, $matches)) {
			$parsed['timestamp'] = $matches[1];
			$parsed['level'] = strtoupper($matches[2]);
			$parsed['message'] = $matches[3];
			if (isset($matches[4])) {
				$parsed['file'] = $matches[4];
			}
			if (isset($matches[5])) {
				$parsed['line'] = (int) $matches[5];
			}

			// Extract exception class if present
			if (preg_match('/([A-Za-z0-9_\\\\]+Exception):\s*(.+?)(?:\s+in\s+|\s+at\s+|$)/', $parsed['message'], $excMatches)) {
				$parsed['exception_class'] = $excMatches[1];
				$parsed['exception_message'] = trim($excMatches[2]);
			}

			// Extract related class names
			if (preg_match_all('/(App\\\\[A-Za-z0-9_\\\\]+)/', $parsed['message'], $classMatches)) {
				$parsed['related_classes'] = array_unique($classMatches[1]);
			}
		}

		return $parsed;
	}

	/**
	 * Parse Nginx log line
	 */
	protected function parseNginxLogLine(string $line, string $filePath): array
	{
		$parsed = [
			'level' => null,
			'timestamp' => null,
			'message' => $line,
		];

		$fileName = basename($filePath);

		if ($fileName === 'error.log') {
			// Nginx error log format varies, try common patterns
			// 2025/01/09 12:34:56 [error] 123#0: *456 message
			if (preg_match('/^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(.+)$/', $line, $matches)) {
				$parsed['timestamp'] = $matches[1];
				$parsed['level'] = strtoupper($matches[2]);
				$parsed['message'] = $matches[3];
			}
		} elseif ($fileName === 'access.log') {
			// Nginx access log - just mark as INFO level
			$parsed['level'] = 'INFO';
			$parsed['message'] = $line;
		}

		return $parsed;
	}

	/**
	 * Parse system log line
	 */
	protected function parseSystemLogLine(string $line): array
	{
		$parsed = [
			'level' => null,
			'timestamp' => null,
			'message' => $line,
		];

		// System log format varies, try common patterns
		// Jan  9 12:34:56 hostname service: message
		if (preg_match('/^(\w+\s+\d+\s+\d{2}:\d{2}:\d{2})\s+\S+\s+(\S+):\s+(.+)$/', $line, $matches)) {
			$parsed['timestamp'] = $matches[1];
			$parsed['message'] = $matches[3];
			// Try to detect level from message
			if (stripos($line, 'error') !== false || stripos($line, 'err') !== false) {
				$parsed['level'] = 'ERROR';
			} elseif (stripos($line, 'warn') !== false) {
				$parsed['level'] = 'WARNING';
			} else {
				$parsed['level'] = 'INFO';
			}
		}

		return $parsed;
	}

	/**
	 * Detect log type from file path
	 */
	protected function detectLogType(string $filePath): string
	{
		if (strpos($filePath, storage_path('logs')) !== false) {
			return 'laravel';
		}

		if (strpos($filePath, '/var/log/nginx') !== false) {
			return 'nginx';
		}

		if (strpos($filePath, '/var/log/php') !== false || strpos($filePath, 'php') !== false) {
			return 'php';
		}

		if (strpos($filePath, '/var/log/syslog') !== false || strpos($filePath, '/var/log/messages') !== false) {
			return 'system';
		}

		return 'unknown';
	}

	/**
	 * Search log file with filters
	 */
	protected function searchLogFile(string $filePath, ?string $query, ?string $level, ?string $dateFrom, ?string $dateTo): array
	{
		$results = [];
		$handle = fopen($filePath, 'r');

		if (!$handle) {
			throw new \Exception('Failed to open log file');
		}

		$lineNumber = 0;
		while (($line = fgets($handle)) !== false) {
			$lineNumber++;
			$line = rtrim($line);

			if (empty($line)) {
				continue;
			}

			$parsed = $this->parseLogLine($line, $filePath);

			// Apply filters
			$matches = true;

			// Text search
			if ($query !== null && $query !== '') {
				if (stripos($line, $query) === false) {
					$matches = false;
				}
			}

			// Level filter
			if ($matches && $level !== null && $parsed['level'] !== $level) {
				$matches = false;
			}

			// Date filter
			if ($matches && ($dateFrom !== null || $dateTo !== null)) {
				$timestamp = $parsed['timestamp'];
				if ($timestamp) {
					try {
						$date = $this->parseTimestamp($timestamp, $filePath);
						if ($dateFrom !== null && $date < strtotime($dateFrom)) {
							$matches = false;
						}
						if ($dateTo !== null && $date > strtotime($dateTo . ' 23:59:59')) {
							$matches = false;
						}
					} catch (\Exception $e) {
						// If we can't parse date, include it
					}
				}
			}

			if ($matches) {
				$results[] = [
					'line_number' => $lineNumber,
					'content' => $line,
					'parsed' => $parsed,
				];
			}
		}

		fclose($handle);

		return [
			'results' => $results,
			'count' => count($results),
		];
	}

	/**
	 * Parse timestamp from various formats
	 */
	protected function parseTimestamp(string $timestamp, string $filePath): ?int
	{
		// Try Laravel format: 2025-01-09 12:34:56
		if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/', $timestamp)) {
			return strtotime($timestamp);
		}

		// Try PHP format: 09-Jan-2025 12:34:56 UTC
		if (preg_match('/^(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $timestamp, $matches)) {
			return strtotime($matches[1]);
		}

		// Try Nginx format: 2025/01/09 12:34:56
		if (preg_match('/^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2})$/', $timestamp)) {
			return strtotime(str_replace('/', '-', $timestamp));
		}

		return null;
	}

	/**
	 * Get log file statistics
	 */
	protected function getLogFileStats(string $filePath): array
	{
		$stats = [
			'size' => filesize($filePath),
			'last_modified' => date('Y-m-d H:i:s', filemtime($filePath)),
			'total_lines' => 0,
			'error_count' => 0,
			'warning_count' => 0,
			'info_count' => 0,
			'debug_count' => 0,
		];

		// Count lines and levels (sample first 10000 lines for performance)
		$handle = fopen($filePath, 'r');
		if ($handle) {
			$lineCount = 0;
			$sampleSize = 10000;

			while (($line = fgets($handle)) !== false && $lineCount < $sampleSize) {
				$lineCount++;
				$parsed = $this->parseLogLine(rtrim($line), $filePath);

				if ($parsed['level']) {
					switch ($parsed['level']) {
						case 'ERROR':
						case 'CRITICAL':
						case 'ALERT':
						case 'EMERGENCY':
							$stats['error_count']++;
							break;
						case 'WARNING':
							$stats['warning_count']++;
							break;
						case 'INFO':
							$stats['info_count']++;
							break;
						case 'DEBUG':
							$stats['debug_count']++;
							break;
					}
				}
			}

			// Get total line count
			$stats['total_lines'] = $this->countLines($filePath);

			// If we sampled, extrapolate counts
			if ($lineCount < $stats['total_lines'] && $lineCount > 0) {
				$ratio = $stats['total_lines'] / $lineCount;
				$stats['error_count'] = (int) ($stats['error_count'] * $ratio);
				$stats['warning_count'] = (int) ($stats['warning_count'] * $ratio);
				$stats['info_count'] = (int) ($stats['info_count'] * $ratio);
				$stats['debug_count'] = (int) ($stats['debug_count'] * $ratio);
			}

			fclose($handle);
		}

		return $stats;
	}
}