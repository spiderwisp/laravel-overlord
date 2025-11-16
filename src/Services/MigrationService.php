<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MigrationService
{
	protected string $migrationsPath;

	public function __construct()
	{
		$this->migrationsPath = database_path('migrations');
	}

	/**
	 * Get all migrations with their status
	 */
	public function getAllMigrations(): array
	{
		$migrationFiles = $this->getMigrationFiles();
		$runMigrations = $this->getRunMigrations();
		$failedMigrations = $this->getFailedMigrations();

		$migrations = [];

		foreach ($migrationFiles as $file) {
			$fullMigrationName = basename($file, '.php'); // Full name with timestamp
			$migrationName = $this->getMigrationName($file); // Short name for display
			$batch = $runMigrations[$fullMigrationName]['batch'] ?? null;
			$runAt = $runMigrations[$fullMigrationName]['run_at'] ?? null;
			$status = $this->getMigrationStatus($fullMigrationName, $runMigrations, $failedMigrations);

			// Extract timestamp from migration filename (format: YYYY_MM_DD_HHMMSS_migration_name)
			$fileTimestamp = $this->extractTimestampFromFilename($fullMigrationName);

			// Get file metadata
			$fileSize = File::exists($file) ? File::size($file) : null;
			$fileModifiedAt = File::exists($file) ? File::lastModified($file) : null;

			$migrations[] = [
				'name' => $migrationName,
				'full_name' => $fullMigrationName, // Store full name for API calls
				'file' => basename($file),
				'path' => $file,
				'status' => $status,
				'batch' => $batch,
				'run_at' => $runAt,
				'file_timestamp' => $fileTimestamp, // Timestamp from filename
				'file_size' => $fileSize,
				'file_modified_at' => $fileModifiedAt ? date('Y-m-d H:i:s', $fileModifiedAt) : null,
				'tables' => $this->extractTables($file),
			];
		}

		// Sort by migration name (timestamp)
		usort($migrations, function ($a, $b) {
			return strcmp($a['full_name'], $b['full_name']);
		});

		return $migrations;
	}

	/**
	 * Get details for a specific migration
	 */
	public function getMigrationDetails(string $migration): ?array
	{
		$migrationFile = $this->findMigrationFile($migration);

		if (!$migrationFile) {
			return null;
		}

		$runMigrations = $this->getRunMigrations();
		$failedMigrations = $this->getFailedMigrations();
		$fullMigrationName = basename($migrationFile, '.php');
		$migrationName = $this->getMigrationName($migrationFile);

		// Extract timestamp from migration filename
		$fileTimestamp = $this->extractTimestampFromFilename($fullMigrationName);

		// Get file metadata
		$fileSize = File::exists($migrationFile) ? File::size($migrationFile) : null;
		$fileModifiedAt = File::exists($migrationFile) ? File::lastModified($migrationFile) : null;

		$details = [
			'name' => $migrationName,
			'full_name' => $fullMigrationName,
			'file' => basename($migrationFile),
			'path' => $migrationFile,
			'status' => $this->getMigrationStatus($fullMigrationName, $runMigrations, $failedMigrations),
			'batch' => $runMigrations[$fullMigrationName]['batch'] ?? null,
			'run_at' => $runMigrations[$fullMigrationName]['run_at'] ?? null,
			'file_timestamp' => $fileTimestamp,
			'file_size' => $fileSize,
			'file_modified_at' => $fileModifiedAt ? date('Y-m-d H:i:s', $fileModifiedAt) : null,
			'content' => File::get($migrationFile),
			'tables' => $this->extractTables($migrationFile),
			'columns' => $this->extractColumns($migrationFile),
			'foreign_keys' => $this->extractForeignKeys($migrationFile),
			'dependencies' => $this->getDependencies($migrationFile),
		];

		return $details;
	}

	/**
	 * Get all migration files
	 */
	protected function getMigrationFiles(): array
	{
		if (!File::exists($this->migrationsPath)) {
			return [];
		}

		return File::glob($this->migrationsPath . '/*.php');
	}

	/**
	 * Get run migrations from database
	 */
	protected function getRunMigrations(): array
	{
		try {
			if (!DB::getSchemaBuilder()->hasTable('migrations')) {
				return [];
			}

			$migrations = DB::table('migrations')
				->select('migration', 'batch')
				->orderBy('batch')
				->orderBy('migration')
				->get();

			$result = [];
			foreach ($migrations as $migration) {
				$result[$migration->migration] = [
					'batch' => $migration->batch,
					'run_at' => null, // migrations table doesn't store timestamp, but we can infer from batch
				];
			}

			return $result;
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Get failed migrations
	 */
	protected function getFailedMigrations(): array
	{
		try {
			if (!DB::getSchemaBuilder()->hasTable('failed_migrations')) {
				return [];
			}

			$failed = DB::table('failed_migrations')
				->pluck('migration')
				->toArray();

			return array_flip($failed);
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Get migration status
	 */
	protected function getMigrationStatus(string $migrationName, array $runMigrations, array $failedMigrations): string
	{
		if (isset($failedMigrations[$migrationName])) {
			return 'failed';
		}

		if (isset($runMigrations[$migrationName])) {
			return 'run';
		}

		return 'pending';
	}

	/**
	 * Get migration name from file path (short name without timestamp)
	 */
	protected function getMigrationName(string $file): string
	{
		$basename = basename($file, '.php');
		// Remove timestamp prefix (e.g., "2024_01_01_000000_create_users_table" -> "create_users_table")
		return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $basename);
	}

	/**
	 * Get full migration name from file path (with timestamp)
	 */
	protected function getFullMigrationName(string $file): string
	{
		return basename($file, '.php');
	}

	/**
	 * Format migration name for display
	 */
	protected function formatMigrationName(string $name): string
	{
		return Str::title(str_replace('_', ' ', $name));
	}

	/**
	 * Extract timestamp from migration filename
	 * Format: YYYY_MM_DD_HHMMSS_migration_name
	 */
	protected function extractTimestampFromFilename(string $filename): ?string
	{
		// Match the timestamp pattern: YYYY_MM_DD_HHMMSS
		if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})(\d{2})(\d{2})_/', $filename, $matches)) {
			$year = $matches[1];
			$month = $matches[2];
			$day = $matches[3];
			$hour = $matches[4];
			$minute = $matches[5];
			$second = $matches[6];

			// Return formatted timestamp
			return "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";
		}

		return null;
	}

	/**
	 * Find migration file by name
	 */
	protected function findMigrationFile(string $migration): ?string
	{
		$files = $this->getMigrationFiles();

		foreach ($files as $file) {
			$migrationName = $this->getMigrationName($file);
			if ($migrationName === $migration || basename($file) === $migration) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Extract tables affected by migration
	 */
	protected function extractTables(string $file): array
	{
		$content = File::get($file);
		$tables = [];

		// Match Schema::create('table_name', ...)
		if (preg_match_all("/Schema::create\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			$tables = array_merge($tables, $matches[1]);
		}

		// Match Schema::table('table_name', ...)
		if (preg_match_all("/Schema::table\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			$tables = array_merge($tables, $matches[1]);
		}

		// Match Schema::drop('table_name')
		if (preg_match_all("/Schema::drop\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			$tables = array_merge($tables, $matches[1]);
		}

		// Match Schema::dropIfExists('table_name')
		if (preg_match_all("/Schema::dropIfExists\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			$tables = array_merge($tables, $matches[1]);
		}

		return array_unique($tables);
	}

	/**
	 * Extract columns from migration
	 */
	protected function extractColumns(string $file): array
	{
		$content = File::get($file);
		$columns = [];

		// Match $table->type('column_name', ...)
		if (preg_match_all("/\\\$table->(?:string|integer|bigInteger|text|boolean|date|datetime|timestamp|decimal|float|json|jsonb|uuid|char|binary|enum)\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			$columns = array_merge($columns, $matches[1]);
		}

		return array_unique($columns);
	}

	/**
	 * Extract foreign keys from migration
	 */
	protected function extractForeignKeys(string $file): array
	{
		$content = File::get($file);
		$foreignKeys = [];

		// Match $table->foreign('column')->references('id')->on('table')
		if (preg_match_all("/\\\$table->foreign\(['\"]([^'\"]+)['\"]\)->references\(['\"]([^'\"]+)['\"]\)->on\(['\"]([^'\"]+)['\"]/", $content, $matches)) {
			for ($i = 0; $i < count($matches[0]); $i++) {
				$foreignKeys[] = [
					'column' => $matches[1][$i],
					'referenced_column' => $matches[2][$i],
					'referenced_table' => $matches[3][$i],
				];
			}
		}

		return $foreignKeys;
	}

	/**
	 * Get migration dependencies
	 */
	protected function getDependencies(string $file): array
	{
		$tables = $this->extractTables($file);
		$foreignKeys = $this->extractForeignKeys($file);
		$dependencies = [];

		// Find migrations that create tables referenced by foreign keys
		foreach ($foreignKeys as $fk) {
			$referencedTable = $fk['referenced_table'] ?? null;
			if ($referencedTable) {
				$dependencies[] = [
					'type' => 'foreign_key',
					'table' => $referencedTable,
				];
			}
		}

		return $dependencies;
	}

	/**
	 * Get preview of pending migrations that would be run
	 */
	public function getPendingMigrationsPreview(): array
	{
		$migrationFiles = $this->getMigrationFiles();
		$runMigrations = $this->getRunMigrations();
		$failedMigrations = $this->getFailedMigrations();

		$pending = [];
		$tablesToCreate = [];
		$tablesToModify = [];

		foreach ($migrationFiles as $file) {
			$fullMigrationName = basename($file, '.php');
			$migrationName = $this->getMigrationName($file);
			$status = $this->getMigrationStatus($fullMigrationName, $runMigrations, $failedMigrations);

			if ($status === 'pending') {
				$tables = $this->extractTables($file);
				$details = $this->getMigrationDetails($fullMigrationName);

				$pending[] = [
					'name' => $migrationName,
					'full_name' => $fullMigrationName,
					'file' => basename($file),
					'tables' => $tables,
					'columns' => $details['columns'] ?? [],
					'type' => $this->getMigrationType($file),
				];

				// Track tables
				foreach ($tables as $table) {
					if (!in_array($table, $tablesToCreate) && !in_array($table, $tablesToModify)) {
						// Check if table exists
						try {
							if (DB::getSchemaBuilder()->hasTable($table)) {
								$tablesToModify[] = $table;
							} else {
								$tablesToCreate[] = $table;
							}
						} catch (\Exception $e) {
							// If we can't check, assume it's new
							$tablesToCreate[] = $table;
						}
					}
				}
			}
		}

		return [
			'pending_migrations' => $pending,
			'tables_to_create' => array_unique($tablesToCreate),
			'tables_to_modify' => array_unique($tablesToModify),
			'total_pending' => count($pending),
		];
	}

	/**
	 * Get preview of migrations that would be rolled back
	 */
	public function getRollbackPreview(?int $batch = null, ?int $step = null): array
	{
		$runMigrations = $this->getRunMigrations();
		$migrationFiles = $this->getMigrationFiles();

		$toRollback = [];
		$tablesToAffect = [];
		$dataLossWarning = false;

		if ($batch !== null) {
			// Get migrations in specific batch
			foreach ($runMigrations as $fullName => $data) {
				if ($data['batch'] === $batch) {
					$file = $this->findMigrationFile($fullName);
					if ($file) {
						$migrationName = $this->getMigrationName($file);
						$tables = $this->extractTables($file);
						$details = $this->getMigrationDetails($fullName);

						$toRollback[] = [
							'name' => $migrationName,
							'full_name' => $fullName,
							'batch' => $batch,
							'tables' => $tables,
							'columns' => $details['columns'] ?? [],
							'type' => $this->getMigrationType($file),
						];

						foreach ($tables as $table) {
							if (!in_array($table, $tablesToAffect)) {
								$tablesToAffect[] = $table;

								// Check if table has data (potential data loss)
								try {
									$rowCount = DB::table($table)->count();
									if ($rowCount > 0) {
										$dataLossWarning = true;
									}
								} catch (\Exception $e) {
									// Table might not exist or error checking
								}
							}
						}
					}
				}
			}
		} else {
			// Get last batch
			$lastBatch = !empty($runMigrations) ? max(array_column($runMigrations, 'batch')) : null;
			if ($lastBatch) {
				return $this->getRollbackPreview($lastBatch);
			}
		}

		return [
			'migrations_to_rollback' => $toRollback,
			'tables_to_affect' => $tablesToAffect,
			'data_loss_warning' => $dataLossWarning,
			'total_to_rollback' => count($toRollback),
		];
	}

	/**
	 * Get migration type (create, modify, drop)
	 */
	protected function getMigrationType(string $file): string
	{
		$content = File::get($file);
		$contentLower = strtolower($content);

		if (strpos($contentLower, 'schema::create') !== false || strpos($contentLower, '->create') !== false) {
			return 'create';
		} elseif (strpos($contentLower, 'schema::drop') !== false || strpos($contentLower, '->drop') !== false) {
			return 'drop';
		} else {
			return 'modify';
		}
	}



	/**
	 * Generate migration code using AI
	 */
	public function generateMigrationWithAI(array $specs, string $userPrompt = null): string
	{
		try {
			// Build context about existing migrations and database
			$context = $this->buildMigrationContext();

			// Build AI prompt
			$prompt = $this->buildMigrationPrompt($specs, $userPrompt, $context);

			// Get AI service
			$aiService = app(\Spiderwisp\LaravelOverlord\Services\RealAiService::class);

			if (!$aiService->isEnabled()) {
				throw new \Exception('AI service is not enabled. Please configure your API key in the .env file.');
			}

			// Call AI service
			$result = $aiService->chat($prompt, [], null, null, 'migration_generation');

			if (!$result['success']) {
				$error = $result['error'] ?? 'Unknown error occurred';
				throw new \Exception('Failed to generate migration: ' . $error);
			}

			$generatedCode = $result['message'] ?? '';

			if (empty($generatedCode)) {
				throw new \Exception('AI service returned empty code. Please try again or provide more details.');
			}

			// Extract code from markdown code blocks if present
			// Try multiple patterns to handle different code block formats
			$extractedCode = $this->extractCodeFromResponse($generatedCode);

			if (empty($extractedCode)) {
				$extractedCode = $generatedCode;
			}

			// Validate it's valid PHP
			$validationResult = $this->validateMigrationCode($extractedCode);
			if (!$validationResult['valid']) {
				throw new \Exception('Generated code is not valid PHP: ' . ($validationResult['error'] ?? 'Invalid syntax'));
			}

			return $extractedCode;
		} catch (\Exception $e) {
			throw $e;
		}
	}

	/**
	 * Extract code from AI response, handling multiple code block formats
	 */
	protected function extractCodeFromResponse(string $response): string
	{
		$extracted = '';

		// Try to find PHP code blocks first
		if (preg_match('/```(?:php)?\s*\n?(.*?)```/s', $response, $matches)) {
			$extracted = trim($matches[1]);
		}
		// Try to find any code block
		elseif (preg_match('/```\s*\n?(.*?)```/s', $response, $matches)) {
			$extracted = trim($matches[1]);
		}
		// Try to find code between class and final brace (for cases without code blocks)
		elseif (preg_match('/(class\s+\w+\s+extends\s+Migration.*?})/s', $response, $matches)) {
			$extracted = trim($matches[1]);
		}
		// If no code blocks found, try to extract just the class definition
		elseif (preg_match('/(class\s+\w+.*)/s', $response, $matches)) {
			$extracted = trim($matches[1]);
		}

		if (empty($extracted)) {
			return '';
		}

		// Extract just the methods if a full class was provided
		// Look for up() and down() methods
		if (preg_match('/class\s+\w+\s+extends\s+Migration/', $extracted)) {
			// Extract methods from class
			$methods = '';

			// Extract up() method
			if (preg_match('/(public\s+function\s+up\(\)\s*\{.*?\n\s*\})/s', $extracted, $upMatch)) {
				$methods .= $upMatch[1] . "\n\n";
			}

			// Extract down() method
			if (preg_match('/(public\s+function\s+down\(\)\s*\{.*?\n\s*\})/s', $extracted, $downMatch)) {
				$methods .= $downMatch[1];
			}

			if (!empty($methods)) {
				return trim($methods);
			}
		}

		// If it's already just methods, return as-is
		// Check if it starts with a method (not a class)
		if (preg_match('/^\s*(public\s+)?function\s+(up|down)\(\)/', $extracted)) {
			return trim($extracted);
		}

		// If we still have a class, try to extract methods more aggressively
		if (preg_match('/(?:public\s+)?function\s+up\(\)\s*\{.*?\n\s*\}(?:\s*(?:public\s+)?function\s+down\(\)\s*\{.*?\n\s*\})?/s', $extracted, $methodsMatch)) {
			return trim($methodsMatch[0]);
		}

		return trim($extracted);
	}

	/**
	 * Build context for AI migration generation
	 */
	protected function buildMigrationContext(): string
	{
		$context = [];

		// Get existing migrations info
		$migrations = $this->getAllMigrations();
		$context[] = "Existing migrations: " . count($migrations) . " total";

		// Get database tables
		try {
			$tables = DB::select("SHOW TABLES");
			$tableNames = array_map(function ($table) {
				return array_values((array) $table)[0];
			}, $tables);
			$context[] = "Existing database tables: " . implode(', ', array_slice($tableNames, 0, 20));
		} catch (\Exception $e) {
			// Ignore if can't get tables
		}

		return implode("\n", $context);
	}

	/**
	 * Build AI prompt for migration generation
	 */
	protected function buildMigrationPrompt(array $specs, ?string $userPrompt, string $context): string
	{
		$prompt = "You are a Laravel migration expert. Generate a complete, production-ready Laravel migration class.\n\n";
		$prompt .= "REQUIREMENTS:\n\n";

		$migrationType = $specs['type'] ?? 'create';
		$tableName = $specs['table_name'] ?? '';

		// Migration type specific instructions
		if ($migrationType === 'create') {
			$prompt .= "Migration Type: CREATE TABLE\n";
			$prompt .= "Table Name: {$tableName}\n\n";
			$prompt .= "Create a new table with the following structure:\n";
		} elseif ($migrationType === 'modify') {
			$prompt .= "Migration Type: MODIFY EXISTING TABLE\n";
			$prompt .= "Table Name: {$tableName}\n\n";
			$prompt .= "Modify the existing table with the following changes:\n";
		} else {
			$prompt .= "Migration Type: DROP TABLE\n";
			$prompt .= "Table Name: {$tableName}\n\n";
			$prompt .= "Drop the table (ensure down() method recreates it if needed):\n";
		}

		// User prompt takes precedence if provided
		if ($userPrompt && trim($userPrompt)) {
			$prompt .= "\nUser Description:\n{$userPrompt}\n\n";
			$prompt .= "Use the user description as the primary guide, but also incorporate the detailed specifications below if provided.\n\n";
		}

		// Detailed column specifications
		if (isset($specs['columns']) && is_array($specs['columns']) && count($specs['columns']) > 0) {
			$prompt .= "COLUMNS:\n";
			foreach ($specs['columns'] as $column) {
				if (empty($column['name'])) {
					continue;
				}

				$columnDesc = "  - {$column['name']} ({$column['type']}";

				// Add length if specified
				if (isset($column['length']) && $column['length']) {
					$columnDesc .= ", length: {$column['length']}";
				}

				// Add nullable
				if (isset($column['nullable']) && $column['nullable']) {
					$columnDesc .= ", nullable";
				} else {
					$columnDesc .= ", not null";
				}

				// Add default value
				if (isset($column['default']) && $column['default'] !== null && $column['default'] !== '') {
					$default = $column['default'];
					// Handle boolean defaults
					if (in_array(strtolower($default), ['true', 'false'])) {
						$default = strtolower($default) === 'true' ? 'true' : 'false';
					}
					$columnDesc .= ", default: {$default}";
				}

				// Add unique constraint
				if (isset($column['unique']) && $column['unique']) {
					$columnDesc .= ", unique";
				}

				// Add index
				if (isset($column['index']) && $column['index']) {
					$columnDesc .= ", indexed";
				}

				$columnDesc .= ")";
				$prompt .= $columnDesc . "\n";
			}
			$prompt .= "\n";
		}

		// Indexes
		if (isset($specs['indexes']) && is_array($specs['indexes']) && count($specs['indexes']) > 0) {
			$prompt .= "INDEXES:\n";
			foreach ($specs['indexes'] as $index) {
				if (empty($index['name']) || empty($index['columns'])) {
					continue;
				}
				$unique = isset($index['unique']) && $index['unique'] ? 'unique ' : '';
				$prompt .= "  - {$unique}index '{$index['name']}' on columns: " . implode(', ', $index['columns']) . "\n";
			}
			$prompt .= "\n";
		}

		// Foreign keys
		if (isset($specs['foreign_keys']) && is_array($specs['foreign_keys']) && count($specs['foreign_keys']) > 0) {
			$prompt .= "FOREIGN KEYS:\n";
			foreach ($specs['foreign_keys'] as $fk) {
				if (empty($fk['column']) || empty($fk['referenced_table'])) {
					continue;
				}
				$onDelete = $fk['onDelete'] ?? 'cascade';
				$onUpdate = $fk['onUpdate'] ?? 'cascade';
				$referencedColumn = $fk['referenced_column'] ?? 'id';
				$prompt .= "  - {$fk['column']} -> {$fk['referenced_table']}.{$referencedColumn} (onDelete: {$onDelete}, onUpdate: {$onUpdate})\n";
			}
			$prompt .= "\n";
		}

		// Context about existing migrations and database
		if ($context) {
			$prompt .= "DATABASE CONTEXT:\n{$context}\n\n";
		}

		// Output format instructions
		$prompt .= "OUTPUT FORMAT:\n";
		$prompt .= "Generate ONLY the up() and down() methods for the migration.\n";
		$prompt .= "- Do NOT include the class definition, <?php tag, namespace, or use statements\n";
		$prompt .= "- Do NOT include the opening or closing braces for the class\n";
		$prompt .= "- Start directly with the up() method (with 'public function up()')\n";
		$prompt .= "- Include both up() and down() methods\n";
		$prompt .= "- Use proper Laravel Schema builder methods (Schema::create, Schema::table, Schema::dropIfExists, etc.)\n";
		$prompt .= "- Follow Laravel naming conventions\n";
		$prompt .= "- Include timestamps() if creating a new table (unless explicitly excluded)\n";
		$prompt .= "- Ensure the down() method properly reverses the up() method\n";
		$prompt .= "- Indent methods with 4 spaces\n";
		$prompt .= "- Example format:\n";
		$prompt .= "    public function up()\n";
		$prompt .= "    {\n";
		$prompt .= "        Schema::create('table_name', function (Blueprint \$table) {\n";
		$prompt .= "            \$table->id();\n";
		$prompt .= "            // ... columns ...\n";
		$prompt .= "        });\n";
		$prompt .= "    }\n\n";
		$prompt .= "    public function down()\n";
		$prompt .= "    {\n";
		$prompt .= "        Schema::dropIfExists('table_name');\n";
		$prompt .= "    }\n";

		return $prompt;
	}

	/**
	 * Validate generated migration code
	 */
	protected function validateMigrationCode(string $code): array
	{
		$result = ['valid' => false, 'error' => null];

		// We expect only methods, not class definitions
		// Check for up() method
		$hasUp = strpos($code, 'up()') !== false ||
			strpos($code, 'function up()') !== false ||
			strpos($code, 'public function up()') !== false;

		if (!$hasUp) {
			$result['error'] = 'Code does not contain an up() method';
			return $result;
		}

		// Check for down() method
		$hasDown = strpos($code, 'down()') !== false ||
			strpos($code, 'function down()') !== false ||
			strpos($code, 'public function down()') !== false;

		if (!$hasDown) {
			$result['error'] = 'Code does not contain a down() method';
			return $result;
		}

		// Try to parse as PHP (syntax check)
		// Wrap the methods in a proper class structure for validation
		$tempFile = tempnam(sys_get_temp_dir(), 'migration_');
		if ($tempFile === false) {
			$result['error'] = 'Could not create temporary file for validation';
			return $result;
		}

		// Create a temporary class structure for syntax validation
		$validationCode = "<?php\n\n";
		$validationCode .= "class TempMigration {\n";
		$validationCode .= $code . "\n";
		$validationCode .= "}\n";

		file_put_contents($tempFile, $validationCode);

		// Use PHP's syntax check
		$output = [];
		$returnVar = 0;
		exec("php -l {$tempFile} 2>&1", $output, $returnVar);
		unlink($tempFile);

		if ($returnVar !== 0) {
			$error = implode("\n", $output);
			$result['error'] = 'PHP syntax error: ' . $error;
			return $result;
		}

		$result['valid'] = true;
		return $result;
	}

	/**
	 * Create migration file
	 */
	public function createMigrationFile(string $migrationName, string $code): string
	{
		// Generate timestamp prefix
		$timestamp = Carbon::now()->format('Y_m_d_His');

		// Sanitize migration name
		$sanitizedName = preg_replace('/[^a-z0-9_]/i', '_', $migrationName);
		$sanitizedName = preg_replace('/_+/', '_', $sanitizedName);
		$sanitizedName = trim($sanitizedName, '_');

		// Build filename
		$filename = "{$timestamp}_{$sanitizedName}.php";
		$filepath = $this->migrationsPath . '/' . $filename;

		// Check if file already exists
		if (File::exists($filepath)) {
			throw new \Exception("Migration file already exists: {$filename}");
		}

		// Clean up the code - remove any class definitions, php tags, etc.
		$cleanCode = $this->cleanMigrationCode($code);

		// Build full migration file content
		$fullCode = "<?php\n\n";
		$fullCode .= "use Illuminate\\Database\\Migrations\\Migration;\n";
		$fullCode .= "use Illuminate\\Database\\Schema\\Blueprint;\n";
		$fullCode .= "use Illuminate\\Support\\Facades\\Schema;\n\n";
		$fullCode .= "return new class extends Migration\n";
		$fullCode .= "{\n";
		$fullCode .= "    /**\n";
		$fullCode .= "     * Run the migrations.\n";
		$fullCode .= "     *\n";
		$fullCode .= "     * @return void\n";
		$fullCode .= "     */\n";

		// Normalize indentation - strip all existing indentation and rebuild from scratch
		// indentLevel: 0 = method declaration, 1 = inside method, 2+ = nested blocks
		$lines = explode("\n", $cleanCode);
		$normalizedLines = [];
		$indentLevel = 0;

		foreach ($lines as $lineIndex => $line) {
			$trimmed = trim($line);

			// Empty lines
			if (empty($trimmed)) {
				$normalizedLines[] = '';
				continue;
			}

			// Remove any semicolons after closing braces in the trimmed line
			$trimmed = preg_replace('/\}\s*;/', '}', $trimmed);

			// Method declaration - always 4 spaces (indentLevel 0)
			if (preg_match('/^\s*(?:public\s+)?function\s+(up|down)\(\)/', $trimmed, $matches)) {
				$methodName = $matches[1];
				$normalizedLines[] = '    public function ' . $methodName . '()';
				$indentLevel = 1; // Next: inside method (expecting opening brace)
			}
			// Opening brace
			elseif (preg_match('/^\s*\{/', $trimmed)) {
				if ($indentLevel == 0) {
					// Method opening brace - 4 spaces
					$normalizedLines[] = '    {';
					$indentLevel = 1; // Inside method
				} elseif ($indentLevel == 1) {
					// This is the opening brace for a method (after method declaration)
					// It should be at 4 spaces and we stay at indentLevel 1
					$normalizedLines[] = '    {';
					// Keep indentLevel at 1 (inside method, not nested)
				} else {
					// Nested opening brace - current indent level
					$normalizedLines[] = str_repeat('    ', $indentLevel) . '{';
					$indentLevel++;
				}
			}
			// Closing brace (semicolon already removed above)
			elseif (preg_match('/^\s*\}$/', $trimmed)) {
				if ($indentLevel == 1) {
					// Method closing brace - 4 spaces, NO semicolon
					$normalizedLines[] = '    }';
					$indentLevel = 0; // Back to method level
				} elseif ($indentLevel > 1) {
					// Nested closing brace
					$indentLevel--;
					$normalizedLines[] = str_repeat('    ', $indentLevel) . '}';
				} else {
					// This shouldn't happen, but handle it gracefully
					$normalizedLines[] = '    }';
					$indentLevel = 0;
				}
			}
			// Regular code - apply proper indentation based on current level
			else {
				// Check if this line contains a closing brace (might be inline)
				$hasClosingBrace = strpos($trimmed, '}') !== false;
				$hasOpeningBrace = strpos($trimmed, '{') !== false;

				// Count braces in the line to adjust indent level AFTER this line
				$openBraces = substr_count($trimmed, '{');
				$closeBraces = substr_count($trimmed, '}');

				// If this line ends with a closing brace and we're at indentLevel 1, 
				// it might be the method closing brace on the same line as code
				if ($hasClosingBrace && $indentLevel == 1 && $closeBraces > $openBraces) {
					// Split the line: code part and closing brace
					$bracePos = strrpos($trimmed, '}');
					if ($bracePos !== false) {
						$codePart = trim(substr($trimmed, 0, $bracePos));
						if (!empty($codePart)) {
							// Output the code part with proper indentation
							$normalizedLines[] = str_repeat('    ', $indentLevel) . $codePart;
						}
						// Output the closing brace as a separate line
						$normalizedLines[] = '    }';
						$indentLevel = 0; // Back to method level
						continue;
					}
				}

				// Apply current indent level (indentLevel 1 = 8 spaces, 2 = 12 spaces, etc.)
				$normalizedLines[] = str_repeat('    ', $indentLevel) . $trimmed;

				// Update indent level for next line
				$indentLevel += $openBraces - $closeBraces;
				if ($indentLevel < 0) {
					$indentLevel = 0;
				}
			}
		}

		// Ensure we close any open methods (safety check)
		// This handles cases where the closing brace line was lost or not processed
		if ($indentLevel > 0) {
			// If we're still inside a method, add the closing brace
			// We need to close methods (indentLevel 1) and any nested blocks (indentLevel > 1)
			while ($indentLevel > 0) {
				$indentLevel--;
				if ($indentLevel == 0) {
					// Method closing brace - 4 spaces
					$normalizedLines[] = '    }';
					break;
				} else {
					// Nested closing brace
					$normalizedLines[] = str_repeat('    ', $indentLevel) . '}';
				}
			}
		}

		$fullCode .= implode("\n", $normalizedLines) . "\n";
		$fullCode .= "};\n";

		// Write file
		File::put($filepath, $fullCode);

		return $filepath;
	}

	/**
	 * Clean migration code - remove class definitions, php tags, etc.
	 */
	protected function cleanMigrationCode(string $code): string
	{
		$code = trim($code);

		// Remove <?php tag if present
		$code = preg_replace('/^<\?php\s*/i', '', $code);

		// Remove namespace and use statements (entire lines)
		$lines = explode("\n", $code);
		$cleanedLines = [];
		foreach ($lines as $line) {
			$trimmed = trim($line);
			// Skip namespace and use statements
			if (preg_match('/^(namespace|use)\s+/', $trimmed)) {
				continue;
			}
			$cleanedLines[] = $line;
		}
		$code = implode("\n", $cleanedLines);

		// Remove class definition if present (multiline)
		$code = preg_replace('/^(?:return\s+)?new\s+class\s+extends\s+Migration\s*\{?\s*\n?/i', '', $code);
		$code = preg_replace('/^class\s+\w+\s+extends\s+Migration\s*\{?\s*\n?/i', '', $code);

		// Remove docblocks that are for the class (not methods) - only the first one
		$code = preg_replace('/\/\*\*.*?\*\/\s*\n?/s', '', $code, 1);

		// Remove semicolons after closing braces anywhere in the code
		// This handles cases like "};" at the end of a method or nested block
		$code = preg_replace('/\}\s*;/', '}', $code);

		// Remove closing braces and semicolons at the very end (class-level only)
		$code = preg_replace('/\s*\};?\s*$/', '', $code);
		$code = preg_replace('/\s*\}\s*$/', '', $code);

		// Remove any remaining class-level opening braces at the start
		$code = preg_replace('/^\s*\{?\s*\n?/', '', $code);

		return trim($code);
	}
}