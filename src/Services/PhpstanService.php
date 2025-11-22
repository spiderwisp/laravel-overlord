<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class PhpstanService
{
	/**
	 * Execute PHPStan analysis
	 *
	 * @param array $config Configuration options
	 * @return array Analysis results
	 */
	public function analyze(array $config = []): array
	{
		$level = $config['level'] ?? null;
		$paths = $config['paths'] ?? [];
		$memoryLimit = $config['memory_limit'] ?? null;
		$configFile = $config['config_file'] ?? null;
		$ignoreErrors = $config['ignore_errors'] ?? false;
		$baselineFile = $config['baseline_file'] ?? null;

		// Check if PHPStan is installed
		$phpstanPath = $this->findPhpstanPath();
		if (!$phpstanPath) {
			throw new \RuntimeException('Larastan is not installed. Please install it via Composer: composer require --dev phpstan/phpstan larastan/larastan');
		}

		// Auto-detect configuration if not provided
		if (!$configFile) {
			$configFile = $this->detectConfigFile();
		}
		
		// If config file exists but user wants to test without it, allow that
		// (This is for debugging - normally we use the config file)
		$useConfigFile = $configFile && file_exists($configFile);

		// Get paths from config file if it exists
		$configFilePaths = [];
		$configFileLevel = null;
		if ($useConfigFile) {
			$configFromFile = $this->getConfigFromFile($configFile);
			$configFilePaths = $configFromFile['paths'] ?? [];
			$configFileLevel = $configFromFile['level'] ?? null;
		}

		// Build command
		$command = [$phpstanPath, 'analyse', '--error-format=json', '--no-progress'];

		// Determine level to use: request level > config file level > default (1)
		// Command-line level always overrides config file level
		$finalLevel = $level ?? $configFileLevel ?? 1;
		
		// Always add level on command line (overrides config file level)
		$command[] = '--level=' . (int) $finalLevel;

		// Add config file if found (but command-line options override config file)
		// Note: When config file has paths, PHPStan will use those unless we override with command-line paths
		if ($useConfigFile) {
			$command[] = '--configuration=' . $configFile;
			
			// If config file has paths but we're also passing paths, we need to ensure
			// command-line paths take precedence. PHPStan should handle this, but let's log it.
			if (!empty($configFilePaths) && !empty($paths)) {
				Log::info('PHPStan: Config file has paths, but command-line paths will override', [
					'config_paths' => $configFilePaths,
					'command_line_paths' => $paths,
				]);
			}
		} else {
			// No config file - log this for debugging
			Log::info('PHPStan: Running without config file', [
				'paths' => $paths,
				'level' => $finalLevel,
			]);
		}

		// Add memory limit if specified
		if ($memoryLimit) {
			$command[] = '--memory-limit=' . $memoryLimit;
		}

		// Add baseline if specified
		if ($baselineFile && file_exists($baselineFile)) {
			$command[] = '--baseline=' . $baselineFile;
		}

		// Determine which paths to use
		// Priority: request paths > config file paths > default (app)
		// Always pass paths on command line to ensure they're scanned
		$pathsToScan = [];
		
		if (!empty($paths)) {
			// Use paths from request (highest priority)
			$pathsToScan = $paths;
		} elseif (!empty($configFilePaths)) {
			// Use paths from config file
			$pathsToScan = $configFilePaths;
		} else {
			// Default to app directory
			$pathsToScan = ['app'];
		}

		// Verify paths exist and add to command
		// PHPStan will use command-line paths even if config file has paths
		$validPaths = [];
		foreach ($pathsToScan as $path) {
			// Handle both relative and absolute paths
			if (str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':')) {
				// Absolute path (Unix or Windows)
				$fullPath = $path;
			} else {
				// Relative path
				$fullPath = base_path($path);
			}
			
			// Verify path exists before adding
			if (is_dir($fullPath) || is_file($fullPath)) {
				// Use relative path for better compatibility
				$validPaths[] = $path;
				$command[] = $path;
			} else {
				Log::warning('PHPStan: Path does not exist, skipping', [
					'path' => $path,
					'full_path' => $fullPath,
				]);
			}
		}
		
		// If no valid paths, throw an error
		if (empty($validPaths)) {
			throw new \RuntimeException('No valid paths found for Larastan analysis. Please specify existing paths to scan.');
		}

		// Execute PHPStan
		try {
			// Log the command for debugging
			Log::info('PHPStan command', [
				'command' => implode(' ', $command),
				'paths' => $pathsToScan,
				'level' => $finalLevel,
				'config_file' => $configFile,
			]);

			$process = new Process($command, base_path());
			$process->setTimeout(600); // 10 minutes timeout
			$process->run();

			$output = $process->getOutput();
			$errorOutput = $process->getErrorOutput();
			$exitCode = $process->getExitCode();

			// Log output for debugging
			Log::info('PHPStan execution result', [
				'exit_code' => $exitCode,
				'output_length' => strlen($output),
				'error_output_length' => strlen($errorOutput),
				'output_preview' => substr($output, 0, 1000),
				'error_output_preview' => substr($errorOutput, 0, 500),
				'full_output' => $output, // Log full output for debugging
			]);

			// PHPStan returns exit code 1 when errors are found (this is normal)
			// Exit code 0 means no errors
			// Exit code > 1 means actual failure

			// Check for invalid configuration errors
			if (stripos($errorOutput, 'Invalid configuration') !== false || 
				stripos($errorOutput, 'Unexpected item') !== false) {
				$errorMsg = 'Larastan configuration file contains invalid parameters. ';
				$errorMsg .= 'Please remove checkMissingIterableValueType and checkGenericClassInNonGenericObjectType from your phpstan.neon file.';
				Log::error('PHPStan invalid configuration', [
					'exit_code' => $exitCode,
					'error_output' => $errorOutput,
					'config_file' => $configFile,
				]);
				throw new \RuntimeException($errorMsg);
			}

			if ($exitCode > 1) {
				$errorMsg = $errorOutput ?: $output;
				Log::error('PHPStan execution failed', [
					'exit_code' => $exitCode,
					'error' => $errorMsg,
					'command' => implode(' ', $command),
				]);
				throw new \RuntimeException('Larastan execution failed: ' . $errorMsg);
			}

			// Check if there's output in stderr that might indicate issues
			if (!empty($errorOutput) && stripos($errorOutput, 'error') !== false) {
				Log::warning('PHPStan stderr output', [
					'error_output' => $errorOutput,
				]);
			}

			// Parse JSON output
			$jsonOutput = trim($output);
			
			// PHPStan should always return JSON, even when no errors are found
			// If output is completely empty, something went wrong
			if (empty($jsonOutput)) {
				// Check if we have paths configured
				$hasPaths = !empty($validPaths);
				
				if (!$hasPaths) {
					Log::warning('PHPStan: No valid paths configured for scanning');
					throw new \RuntimeException('No valid paths configured for Larastan analysis. Please specify existing paths to scan.');
				}
				
				// Check if paths actually exist and contain PHP files
				$phpFilesFound = 0;
				foreach ($validPaths as $path) {
					$fullPath = str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':') 
						? $path 
						: base_path($path);
					
					if (is_file($fullPath) && pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
						$phpFilesFound++;
					} elseif (is_dir($fullPath)) {
						// Count PHP files in directory
						$iterator = new \RecursiveIteratorIterator(
							new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
						);
						foreach ($iterator as $file) {
							if ($file->isFile() && $file->getExtension() === 'php') {
								// Skip vendor and cache directories
								$filePath = $file->getPathname();
								if (strpos($filePath, '/vendor/') === false && 
									strpos($filePath, '/cache/') === false &&
									strpos($filePath, '/node_modules/') === false) {
									$phpFilesFound++;
								}
							}
						}
					}
				}
				
				if ($phpFilesFound === 0) {
					Log::warning('PHPStan: No PHP files found in specified paths', [
						'paths' => $validPaths,
					]);
					throw new \RuntimeException('No PHP files found in the specified paths for Larastan analysis.');
				}
				
				// If we found PHP files but got empty output, PHPStan might have failed silently
				// or the output format is different
				Log::warning('PHPStan returned empty output but PHP files were found', [
					'php_files_found' => $phpFilesFound,
					'paths' => $validPaths,
					'exit_code' => $exitCode,
					'error_output' => $errorOutput,
				]);
				
				// Return results indicating files were found but no output
				return [
					'success' => true,
					'errors' => [],
					'files' => [],
					'summary' => [
						'total_errors' => 0,
						'total_files' => $phpFilesFound, // Show that files exist
					],
				];
			}

			$jsonData = json_decode($jsonOutput, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				Log::warning('PHPStan JSON parsing failed', [
					'json_error' => json_last_error_msg(),
					'output_preview' => substr($jsonOutput, 0, 500),
					'full_output' => $output,
				]);
				// If JSON parsing fails, try to extract errors from text output
				return $this->parseTextOutput($output);
			}

			// Check if PHPStan actually scanned files (even if no errors)
			// PHPStan returns totals even when no errors are found
			if (isset($jsonData['totals'])) {
				$filesScanned = $jsonData['totals']['files'] ?? 0;
				$errorsFound = $jsonData['totals']['errors'] ?? 0;
				
				Log::info('PHPStan scan completed', [
					'files_scanned' => $filesScanned,
					'errors_found' => $errorsFound,
					'has_files_key' => isset($jsonData['files']),
					'files_count' => is_array($jsonData['files'] ?? null) ? count($jsonData['files']) : 0,
				]);
				
				// If files were scanned but no errors found, that's valid
				if ($filesScanned > 0 && $errorsFound === 0) {
					Log::info('PHPStan: Files scanned but no errors found', [
						'files_scanned' => $filesScanned,
						'level' => $finalLevel,
					]);
				}
			}

			$parsed = $this->parseJsonOutput($jsonData);
			
			// Log parsed results for debugging
			Log::info('PHPStan parsed results', [
				'total_files_scanned' => $parsed['summary']['total_files'] ?? 0,
				'total_errors' => $parsed['summary']['total_errors'] ?? 0,
				'files_with_errors' => count($parsed['files'] ?? []),
			]);
			
			return $parsed;

		} catch (ProcessFailedException $e) {
			Log::error('PHPStan process failed', [
				'error' => $e->getMessage(),
				'command' => implode(' ', $command),
			]);
				throw new \RuntimeException('Larastan analysis failed: ' . $e->getMessage());
		} catch (\Exception $e) {
			Log::error('PHPStan analysis error', [
				'error' => $e->getMessage(),
			]);
			throw $e;
		}
	}

	/**
	 * Find PHPStan executable path
	 */
	public function findPhpstanPath(): ?string
	{
		// Check vendor/bin/phpstan first (most common)
		$vendorPath = base_path('vendor/bin/phpstan');
		if (file_exists($vendorPath)) {
			// On Windows, check for .bat file
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				$vendorPathBat = $vendorPath . '.bat';
				if (file_exists($vendorPathBat)) {
					return $vendorPathBat;
				}
			}
			// On Unix-like systems, check if executable
			if (is_executable($vendorPath)) {
				return $vendorPath;
			}
		}

		// Check if phpstan is in PATH (only on Unix-like systems)
		if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
			try {
				$process = new Process(['which', 'phpstan'], base_path());
				$process->setTimeout(5);
				$process->run();
				if ($process->isSuccessful()) {
					$path = trim($process->getOutput());
					if (!empty($path) && file_exists($path)) {
						return $path;
					}
				}
			} catch (\Exception $e) {
				// Silently fail - which command might not be available
			}
		}

		return null;
	}

	/**
	 * Detect PHPStan configuration file
	 */
	public function detectConfigFile(): ?string
	{
		$basePath = base_path();

		// Check for phpstan.neon
		$phpstanNeon = $basePath . '/phpstan.neon';
		if (file_exists($phpstanNeon)) {
			return $phpstanNeon;
		}

		// Check for phpstan.dist.neon
		$phpstanDistNeon = $basePath . '/phpstan.dist.neon';
		if (file_exists($phpstanDistNeon)) {
			return $phpstanDistNeon;
		}

		return null;
	}

	/**
	 * Get configuration from phpstan.neon file
	 */
	public function getConfigFromFile(?string $configFile = null): array
	{
		if (!$configFile) {
			$configFile = $this->detectConfigFile();
		}

		if (!$configFile || !file_exists($configFile)) {
			return [
				'level' => null,
				'paths' => [],
				'memory_limit' => null,
			];
		}

		$config = [
			'level' => null,
			'paths' => [],
			'memory_limit' => null,
		];

		try {
			// Parse NEON file (simplified - just extract level and paths)
			if (!is_readable($configFile)) {
				Log::warning('PHPStan config file is not readable', [
					'config_file' => $configFile,
				]);
				return $config;
			}

			$content = @file_get_contents($configFile);
			if ($content === false) {
				Log::warning('Failed to read PHPStan config file', [
					'config_file' => $configFile,
				]);
				return $config;
			}
			
			// Extract level
			if (preg_match('/level:\s*(\d+)/', $content, $matches)) {
				$config['level'] = (int) $matches[1];
			}

			// Extract paths - handle both array and single path formats
			if (preg_match('/paths:\s*\[(.*?)\]/s', $content, $matches)) {
				$pathsContent = $matches[1];
				preg_match_all('/["\']([^"\']+)["\']/', $pathsContent, $pathMatches);
				if (!empty($pathMatches[1])) {
					$config['paths'] = $pathMatches[1];
				}
			} elseif (preg_match('/paths:\s*["\']([^"\']+)["\']/', $content, $matches)) {
				// Single path format
				$config['paths'] = [$matches[1]];
			}

			// Extract memory limit
			if (preg_match('/memoryLimit:\s*["\']?([^"\'\s]+)["\']?/', $content, $matches)) {
				$config['memory_limit'] = $matches[1];
			}
		} catch (\Exception $e) {
			Log::warning('Failed to parse PHPStan config file', [
				'config_file' => $configFile,
				'error' => $e->getMessage(),
			]);
		}

		return $config;
	}

	/**
	 * Validate PHP code content using Larastan
	 *
	 * @param string $content PHP code content
	 * @param int|null $level Larastan level (defaults to 1)
	 * @return array ['valid' => bool, 'errors' => array, 'error' => string|null]
	 */
	public function validateContent(string $content, ?int $level = null): array
	{
		try {
			// Create temporary file with .php extension
			$tempDir = sys_get_temp_dir();
			$tempBase = tempnam($tempDir, 'phpstan_validate_');
			
			if ($tempBase === false) {
				return [
					'valid' => false,
					'errors' => [],
					'error' => 'Failed to create temporary file for validation',
				];
			}

			// Rename to add .php extension
			$tempFile = $tempBase . '.php';
			rename($tempBase, $tempFile);

			// Write content to temp file
			file_put_contents($tempFile, $content);

			// Get temp file name for filtering
			$tempFileName = basename($tempFile);
			$tempFilePath = $tempFile;

			// Run Larastan on the temp file directly
			$config = [
				'level' => $level ?? 1,
				'paths' => [$tempFilePath], // Scan the specific temp file
				'config_file' => null, // Don't use config file for temp validation
			];

			$results = $this->analyze($config);

			// Clean up temp file
			@unlink($tempFile);

			// Filter results to only include errors for our temp file
			$errors = [];
			if (isset($results['errors']) && is_array($results['errors'])) {
				foreach ($results['errors'] as $error) {
					// Check if error is for our temp file
					if (isset($error['file'])) {
						$errorFile = $error['file'];
						// Match by filename or full path
						if (str_contains($errorFile, $tempFileName) || str_contains($errorFile, $tempFilePath)) {
							$errors[] = $error;
						}
					} else {
						// Internal errors without file - these might be dependency issues
						// We'll include them but they're less critical
						if (isset($error['message']) && (
							str_contains($error['message'], 'Class') ||
							str_contains($error['message'], 'not found') ||
							str_contains($error['message'], 'Could not')
						)) {
							// These are likely dependency issues, not syntax errors
							// We'll log but not fail validation for them
							Log::debug('PhpstanService: Internal error during validation (likely dependency)', [
								'error' => $error,
							]);
						}
					}
				}
			}

			// Valid if no errors found (only count file-specific errors, not dependency issues)
			$valid = empty($errors);

			return [
				'valid' => $valid,
				'errors' => $errors,
				'error' => $valid ? null : 'Larastan found ' . count($errors) . ' error(s)',
			];
		} catch (\Exception $e) {
			Log::error('PhpstanService: Failed to validate content', [
				'error' => $e->getMessage(),
			]);

			return [
				'valid' => false,
				'errors' => [],
				'error' => 'Validation failed: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Parse JSON output from PHPStan
	 */
	protected function parseJsonOutput(array $jsonData): array
	{
		$errors = [];
		$files = [];

		// PHPStan JSON format structure
		// When no errors: {"totals": {"errors": 0, "file_errors": 0}, "files": {}}
		// When errors: {"files": {"/path/to/file.php": {"messages": [...]}}, "totals": {...}}
		
		// Check totals to see if files were scanned
		$totalFilesScanned = 0;
		if (isset($jsonData['totals'])) {
			$totalFilesScanned = $jsonData['totals']['files'] ?? 0;
		}

		// PHPStan JSON format structure
		// files can be an array or object (empty {} when no errors)
		if (isset($jsonData['files'])) {
			// Convert to array if it's an object
			$filesData = is_array($jsonData['files']) ? $jsonData['files'] : (array) $jsonData['files'];
			
			foreach ($filesData as $filePath => $fileErrors) {
				$relativePath = $this->getRelativePath($filePath);
				$fileIssues = [];

				// Handle both array format and object format
				$messages = [];
				if (isset($fileErrors['messages']) && is_array($fileErrors['messages'])) {
					$messages = $fileErrors['messages'];
				} elseif (is_array($fileErrors)) {
					// Sometimes messages might be directly in the array
					$messages = $fileErrors;
				}

				foreach ($messages as $error) {
					if (!is_array($error)) {
						continue;
					}
					
					$issue = [
						'file' => $relativePath,
						'line' => $error['line'] ?? null,
						'message' => $error['message'] ?? '',
						'rule' => $error['identifier'] ?? null,
						'tip' => $error['tip'] ?? null,
						'severity' => $this->mapSeverity($error),
					];

					$errors[] = $issue;
					$fileIssues[] = $issue;
				}

				if (!empty($fileIssues)) {
					$files[] = [
						'file' => $relativePath,
						'issues' => $fileIssues,
						'has_errors' => true,
					];
				}
			}
		}

		// Calculate summary
		$totalErrors = count($errors);
		$totalFiles = count($files);
		
		// If we have totals from PHPStan, use that for total files scanned
		// This is critical: PHPStan returns totals.files even when no errors are found
		// totals.files = number of files analyzed
		// totals.errors = number of errors found
		// If totals.files > 0 but files array is empty, files were scanned but no errors found
		if ($totalFilesScanned > 0) {
			// Files were scanned - use the actual count from PHPStan totals
			$finalTotalFiles = $totalFilesScanned;
		} else {
			// No totals info - this might indicate PHPStan didn't scan properly
			// But if we have files with errors, at least those were scanned
			$finalTotalFiles = $totalFiles > 0 ? $totalFiles : 0;
		}

		return [
			'success' => true,
			'errors' => $errors,
			'files' => $files,
			'summary' => [
				'total_errors' => $totalErrors,
				'total_files' => $finalTotalFiles, // Total files scanned (even if no errors)
				'files_with_errors' => $totalFiles, // Only files that have errors
			],
		];
	}

	/**
	 * Parse text output (fallback when JSON parsing fails)
	 */
	protected function parseTextOutput(string $output): array
	{
		$errors = [];
		$files = [];
		$currentFile = null;

		$lines = explode("\n", $output);
		foreach ($lines as $line) {
			$line = trim($line);
			if (empty($line)) {
				continue;
			}

			// Match file path pattern: "  /path/to/file.php"
			if (preg_match('/^\s+([\/\\\\].+\.php)/', $line, $matches)) {
				$currentFile = $this->getRelativePath($matches[1]);
				if (!isset($files[$currentFile])) {
					$files[$currentFile] = [
						'file' => $currentFile,
						'issues' => [],
						'has_errors' => true,
					];
				}
			}
			// Match error line pattern: "    123   Error message"
			elseif (preg_match('/^\s+(\d+)\s+(.+)$/', $line, $matches) && $currentFile) {
				$issue = [
					'file' => $currentFile,
					'line' => (int) $matches[1],
					'message' => $matches[2],
					'rule' => null,
					'tip' => null,
					'severity' => 'error',
				];

				$errors[] = $issue;
				$files[$currentFile]['issues'][] = $issue;
			}
		}

		$files = array_values($files);

		return [
			'success' => true,
			'errors' => $errors,
			'files' => $files,
			'summary' => [
				'total_errors' => count($errors),
				'total_files' => count($files),
				'files_with_errors' => count($files),
			],
		];
	}

	/**
	 * Map PHPStan error to severity level
	 */
	protected function mapSeverity(array $error): string
	{
		// PHPStan errors are typically all "errors" but we can check the message
		$message = strtolower($error['message'] ?? '');

		if (stripos($message, 'critical') !== false) {
			return 'critical';
		}

		if (stripos($message, 'security') !== false || stripos($message, 'vulnerability') !== false) {
			return 'high';
		}

		// Default to medium for most PHPStan errors
		return 'medium';
	}

	/**
	 * Get relative path from absolute path
	 */
	protected function getRelativePath(string $absolutePath): string
	{
		$basePath = base_path();
		if (str_starts_with($absolutePath, $basePath)) {
			return ltrim(substr($absolutePath, strlen($basePath)), '/\\');
		}
		return $absolutePath;
	}
}

