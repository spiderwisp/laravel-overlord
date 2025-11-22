<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class FileEditService
{
	/**
	 * Directories that should never be edited
	 */
	protected array $protectedDirectories = [
		'vendor',
		'node_modules',
		'.git',
		'storage/framework',
		'storage/logs',
		'bootstrap/cache',
		'public',
	];

	/**
	 * File extensions that are allowed to be edited
	 */
	protected array $allowedExtensions = [
		'php',
		'js',
		'vue',
		'ts',
		'tsx',
		'css',
		'scss',
		'json',
		'xml',
		'yaml',
		'yml',
		'env',
		'blade.php',
	];

	/**
	 * Read file content with validation
	 *
	 * @param string $filePath Relative or absolute file path
	 * @return array ['success' => bool, 'content' => string|null, 'error' => string|null]
	 */
	public function readFile(string $filePath): array
	{
		try {
			$fullPath = $this->resolvePath($filePath);

			if (!$this->validateFile($fullPath)) {
				return [
					'success' => false,
					'content' => null,
					'error' => 'File is not allowed to be edited',
				];
			}

			if (!file_exists($fullPath)) {
				return [
					'success' => false,
					'content' => null,
					'error' => 'File does not exist',
				];
			}

			if (!is_readable($fullPath)) {
				return [
					'success' => false,
					'content' => null,
					'error' => 'File is not readable',
				];
			}

			$content = file_get_contents($fullPath);

			if ($content === false) {
				return [
					'success' => false,
					'content' => null,
					'error' => 'Failed to read file',
				];
			}

			return [
				'success' => true,
				'content' => $content,
				'error' => null,
			];
		} catch (\Exception $e) {
			Log::error('FileEditService: Failed to read file', [
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'content' => null,
				'error' => 'Failed to read file: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Write file with backup and validation
	 *
	 * @param string $filePath Relative or absolute file path
	 * @param string $content New file content
	 * @param bool $createBackup Whether to create a backup
	 * @return array ['success' => bool, 'backup_path' => string|null, 'error' => string|null]
	 */
	public function writeFile(string $filePath, string $content, bool $createBackup = true): array
	{
		try {
			$fullPath = $this->resolvePath($filePath);

			if (!$this->validateFile($fullPath)) {
				return [
					'success' => false,
					'backup_path' => null,
					'error' => 'File is not allowed to be edited',
				];
			}

			// Create backup if requested
			$backupPath = null;
			if ($createBackup && file_exists($fullPath)) {
				$backupResult = $this->createBackup($fullPath);
				if (!$backupResult['success']) {
					return [
						'success' => false,
						'backup_path' => null,
						'error' => 'Failed to create backup: ' . $backupResult['error'],
					];
				}
				$backupPath = $backupResult['backup_path'];
			}

			// Validate syntax for PHP files BEFORE writing
			if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
				$syntaxCheck = $this->validatePhpSyntaxFromContent($content);
				if (!$syntaxCheck['valid']) {
					$errorMsg = 'Invalid PHP syntax: ' . $syntaxCheck['error'];
					if (isset($syntaxCheck['line']) && $syntaxCheck['line'] !== null) {
						$errorMsg .= "\nError on line " . $syntaxCheck['line'];
					}
					if (isset($syntaxCheck['context']) && $syntaxCheck['context']) {
						$errorMsg .= "\n\nContext:\n" . $syntaxCheck['context'];
					}
					return [
						'success' => false,
						'backup_path' => $backupPath,
						'error' => $errorMsg,
						'line' => $syntaxCheck['line'] ?? null,
						'context' => $syntaxCheck['context'] ?? null,
					];
				}
			}

			// Ensure directory exists
			$directory = dirname($fullPath);
			if (!is_dir($directory)) {
				if (!mkdir($directory, 0755, true)) {
					return [
						'success' => false,
						'backup_path' => $backupPath,
						'error' => 'Failed to create directory',
					];
				}
			}

			// Write file
			$result = file_put_contents($fullPath, $content);

			if ($result === false) {
				// Restore backup if write failed
				if ($backupPath && file_exists($backupPath)) {
					$this->restoreBackup($backupPath, $fullPath);
				}

				return [
					'success' => false,
					'backup_path' => $backupPath,
					'error' => 'Failed to write file',
				];
			}

			return [
				'success' => true,
				'backup_path' => $backupPath,
				'error' => null,
			];
		} catch (\Exception $e) {
			Log::error('FileEditService: Failed to write file', [
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'backup_path' => null,
				'error' => 'Failed to write file: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Apply patch to specific lines in a file
	 *
	 * @param string $filePath Relative or absolute file path
	 * @param array $changes Array of ['line' => int, 'old' => string, 'new' => string]
	 * @param bool $createBackup Whether to create a backup
	 * @return array ['success' => bool, 'backup_path' => string|null, 'error' => string|null]
	 */
	public function applyPatch(string $filePath, array $changes, bool $createBackup = true): array
	{
		try {
			$readResult = $this->readFile($filePath);
			if (!$readResult['success']) {
				return $readResult;
			}

			$lines = explode("\n", $readResult['content']);
			$totalLines = count($lines);

			// Sort changes by line number (descending) to avoid line number shifts
			usort($changes, function ($a, $b) {
				return ($b['line'] ?? 0) <=> ($a['line'] ?? 0);
			});

			// Apply changes
			foreach ($changes as $change) {
				$lineNumber = ($change['line'] ?? 1) - 1; // Convert to 0-based index

				if ($lineNumber < 0 || $lineNumber >= $totalLines) {
					continue; // Skip invalid line numbers
				}

				$oldContent = $change['old'] ?? '';
				$newContent = $change['new'] ?? '';

				// Verify the line matches what we expect
				if (isset($lines[$lineNumber]) && trim($lines[$lineNumber]) !== trim($oldContent)) {
					Log::warning('FileEditService: Line content mismatch', [
						'file_path' => $filePath,
						'line' => $lineNumber + 1,
						'expected' => $oldContent,
						'actual' => $lines[$lineNumber],
					]);
				}

				// Apply the change
				$lines[$lineNumber] = $newContent;
			}

			$newContent = implode("\n", $lines);

			return $this->writeFile($filePath, $newContent, $createBackup);
		} catch (\Exception $e) {
			Log::error('FileEditService: Failed to apply patch', [
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'backup_path' => null,
				'error' => 'Failed to apply patch: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Create backup of a file
	 *
	 * @param string $filePath Full path to file
	 * @return array ['success' => bool, 'backup_path' => string|null, 'error' => string|null]
	 */
	public function createBackup(string $filePath): array
	{
		try {
			if (!file_exists($filePath)) {
				return [
					'success' => false,
					'backup_path' => null,
					'error' => 'File does not exist',
				];
			}

			$backupDir = storage_path('app/overlord/backups');
			if (!is_dir($backupDir)) {
				mkdir($backupDir, 0755, true);
			}

			$relativePath = str_replace(base_path() . '/', '', $filePath);
			$backupFileName = str_replace(['/', '\\'], '_', $relativePath) . '_' . time() . '.bak';
			$backupPath = $backupDir . '/' . $backupFileName;

			if (!copy($filePath, $backupPath)) {
				return [
					'success' => false,
					'backup_path' => null,
					'error' => 'Failed to create backup file',
				];
			}

			return [
				'success' => true,
				'backup_path' => $backupPath,
				'error' => null,
			];
		} catch (\Exception $e) {
			Log::error('FileEditService: Failed to create backup', [
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'success' => false,
				'backup_path' => null,
				'error' => 'Failed to create backup: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Restore file from backup
	 *
	 * @param string $backupPath Path to backup file
	 * @param string $targetPath Path to restore to
	 * @return bool Success
	 */
	public function restoreBackup(string $backupPath, string $targetPath): bool
	{
		try {
			if (!file_exists($backupPath)) {
				return false;
			}

			return copy($backupPath, $targetPath);
		} catch (\Exception $e) {
			Log::error('FileEditService: Failed to restore backup', [
				'backup_path' => $backupPath,
				'target_path' => $targetPath,
				'error' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Validate that a file can be edited
	 *
	 * @param string $filePath Full path to file
	 * @return bool
	 */
	public function validateFile(string $filePath): bool
	{
		$relativePath = str_replace(base_path() . '/', '', $filePath);
		$normalizedPath = str_replace('\\', '/', $relativePath);

		// Check if file is in a protected directory
		foreach ($this->protectedDirectories as $protectedDir) {
			if (str_starts_with($normalizedPath, $protectedDir)) {
				return false;
			}
		}

		// Check file extension
		$extension = pathinfo($filePath, PATHINFO_EXTENSION);
		$basename = basename($filePath);

		// Check for allowed extensions
		$isAllowed = false;
		foreach ($this->allowedExtensions as $allowedExt) {
			if ($extension === $allowedExt || $basename === $allowedExt || str_ends_with($basename, '.' . $allowedExt)) {
				$isAllowed = true;
				break;
			}
		}

		if (!$isAllowed) {
			return false;
		}

		// Additional checks
		if (str_contains($normalizedPath, '..')) {
			return false; // Prevent directory traversal
		}

		return true;
	}

	/**
	 * Validate PHP syntax from content string
	 *
	 * @param string $content PHP code content
	 * @return array ['valid' => bool, 'error' => string|null, 'line' => int|null, 'context' => string|null]
	 */
	public function validatePhpSyntaxFromContent(string $content): array
	{
		try {
			$tempFile = tempnam(sys_get_temp_dir(), 'php_syntax_check_');
			if ($tempFile === false) {
				return [
					'valid' => false,
					'error' => 'Failed to create temporary file for syntax check',
					'line' => null,
					'context' => null,
				];
			}
			
			file_put_contents($tempFile, $content);
			$result = $this->validatePhpSyntax($tempFile);
			unlink($tempFile);
			
			return $result;
		} catch (\Exception $e) {
			Log::error('FileEditService: PHP syntax check from content failed', [
				'error' => $e->getMessage(),
			]);
			return [
				'valid' => false,
				'error' => 'Internal error during syntax check: ' . $e->getMessage(),
				'line' => null,
				'context' => null,
			];
		}
	}

	/**
	 * Validate PHP syntax
	 *
	 * @param string $filePath Full path to PHP file
	 * @return array ['valid' => bool, 'error' => string|null, 'line' => int|null, 'context' => string|null]
	 */
	protected function validatePhpSyntax(string $filePath): array
	{
		try {
			$output = [];
			$returnVar = 0;
			exec('php -l ' . escapeshellarg($filePath) . ' 2>&1', $output, $returnVar);

			if ($returnVar !== 0) {
				$error = implode("\n", $output);
				$errorLine = null;
				$errorContext = null;

				// Attempt to extract line number and context from error output
				if (preg_match('/on line (\d+)/', $error, $matches)) {
					$errorLine = (int) $matches[1];
					// Read the file content to get context
					$fileContent = file_get_contents($filePath);
					if ($fileContent !== false) {
						$errorContext = $this->getCodeAroundLine($fileContent, $errorLine, 5);
					}
				}

				return [
					'valid' => false,
					'error' => $error,
					'line' => $errorLine,
					'context' => $errorContext,
				];
			}

			return [
				'valid' => true,
				'error' => null,
				'line' => null,
				'context' => null,
			];
		} catch (\Exception $e) {
			Log::warning('FileEditService: PHP syntax check failed', [
				'file_path' => $filePath,
				'error' => $e->getMessage(),
			]);

			return [
				'valid' => false,
				'error' => 'Internal error during syntax check: ' . $e->getMessage(),
				'line' => null,
				'context' => null,
			];
		}
	}

	/**
	 * Get code around a specific line for context
	 *
	 * @param string $content File content
	 * @param int $lineNumber Line number (1-based)
	 * @param int $contextLines Number of lines before and after
	 * @return string Context code
	 */
	protected function getCodeAroundLine(string $content, int $lineNumber, int $contextLines = 5): string
	{
		$lines = explode("\n", $content);
		$start = max(0, $lineNumber - 1 - $contextLines);
		$end = min(count($lines), $lineNumber - 1 + $contextLines);
		$context = array_slice($lines, $start, $end - $start);
		
		$result = [];
		foreach ($context as $i => $line) {
			$actualLine = $start + $i + 1;
			$marker = ($actualLine === $lineNumber) ? '>>> ' : '    ';
			$result[] = $marker . $actualLine . ': ' . $line;
		}
		
		return implode("\n", $result);
	}

	/**
	 * Resolve relative path to full path
	 *
	 * @param string $filePath Relative or absolute path
	 * @return string Full path
	 */
	protected function resolvePath(string $filePath): string
	{
		if (str_starts_with($filePath, '/') || (strlen($filePath) > 1 && $filePath[1] === ':')) {
			// Absolute path
			return $filePath;
		}

		// Relative path
		return base_path($filePath);
	}
}

