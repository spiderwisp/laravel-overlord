<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class DatabaseSchemaService
{
	/**
	 * Detect the database driver.
	 *
	 * @return string
	 */
	public function detectDatabaseDriver(): string
	{
		$connection = DB::connection();
		$driver = $connection->getDriverName();

		return match ($driver) {
			'mysql' => 'mysql',
			'pgsql' => 'postgresql',
			'sqlite' => 'sqlite',
			default => $driver,
		};
	}

	/**
	 * Get all table names from the database.
	 *
	 * @return array
	 */
	public function getTables(): array
	{
		try {
			$driver = $this->detectDatabaseDriver();

			if ($driver === 'sqlite') {
				$tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
				$result = array_map(fn($table) => $table->name, $tables);
				return $result;
			}

			// MySQL and PostgreSQL
			$databaseName = DB::connection()->getDatabaseName();

			// Filter to only get BASE TABLE (exclude views)
			$tables = DB::select(
				"SELECT TABLE_NAME 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = ? 
                 AND TABLE_TYPE = 'BASE TABLE'
                 ORDER BY TABLE_NAME",
				[$databaseName]
			);

			$result = array_map(fn($table) => $table->TABLE_NAME, $tables);
			return $result;
		} catch (\Exception $e) {
			Log::error('Failed to get tables', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			return [];
		}
	}

	/**
	 * Get schema information for a table.
	 *
	 * @param string $tableName
	 * @return array|null
	 */
	public function getTableSchema(string $tableName): ?array
	{
		try {
			if (!Schema::hasTable($tableName)) {
				return null;
			}

			$columns = Schema::getColumnListing($tableName);
			$columnDetails = [];

			foreach ($columns as $column) {
				try {
					$type = Schema::getColumnType($tableName, $column);
					$nullable = true;

					try {
						$driver = $this->detectDatabaseDriver();
						if ($driver === 'sqlite') {
							// SQLite doesn't have information_schema, use pragma
							$pragma = DB::select("PRAGMA table_info({$tableName})");
							foreach ($pragma as $col) {
								if ($col->name === $column) {
									$nullable = $col->notnull == 0;
									break;
								}
							}
						} else {
							$databaseName = DB::connection()->getDatabaseName();
							$columnInfo = DB::selectOne(
								"SELECT IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE 
                                 FROM information_schema.COLUMNS 
                                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
								[$databaseName, $tableName, $column]
							);
							if ($columnInfo) {
								$nullable = $columnInfo->IS_NULLABLE === 'YES';
							}
						}
					} catch (\Exception $e) {
						// If we can't determine, default to nullable
					}

					$columnDetails[$column] = [
						'type' => $type,
						'nullable' => $nullable,
					];
				} catch (\Exception $e) {
					continue;
				}
			}

			$indexes = $this->getTableIndexes($tableName);
			$foreignKeys = $this->getTableForeignKeys($tableName);
			$constraints = $this->getTableConstraints($tableName);

			// Create a list of all indexed columns for easy reference
			$indexedColumns = [];
			foreach ($indexes as $index) {
				foreach ($index['columns'] as $column) {
					if (!in_array($column, $indexedColumns)) {
						$indexedColumns[] = $column;
					}
				}
			}
			// Foreign keys automatically create indexes in MySQL
			foreach ($foreignKeys as $fk) {
				if (!in_array($fk['column'], $indexedColumns)) {
					$indexedColumns[] = $fk['column'];
				}
			}

			// Create list of unindexed columns (columns without indexes)
			$unindexedColumns = [];
			foreach ($columns as $column) {
				if (!in_array($column, $indexedColumns)) {
					$unindexedColumns[] = $column;
				}
			}

			// Identify potentially missing indexes (frequently queried patterns)
			$potentiallyMissingIndexes = $this->identifyPotentiallyMissingIndexes($columns, $indexedColumns, $foreignKeys);

			// Add has_index metadata to each column
			foreach ($columnDetails as $columnName => &$details) {
				$details['has_index'] = in_array($columnName, $indexedColumns);
				$details['is_foreign_key'] = false;
				foreach ($foreignKeys as $fk) {
					if ($fk['column'] === $columnName) {
						$details['is_foreign_key'] = true;
						break;
					}
				}
			}
			unset($details); // Break reference

			return [
				'name' => $tableName,
				'columns' => $columnDetails,
				'indexes' => $indexes,
				'foreign_keys' => $foreignKeys,
				'constraints' => $constraints,
				'indexed_columns' => $indexedColumns, // Quick reference: all columns that have indexes
				'unindexed_columns' => $unindexedColumns, // Explicit list of columns WITHOUT indexes
				'potentially_missing_indexes' => $potentiallyMissingIndexes, // Columns that might need indexes
			];
		} catch (\Exception $e) {
			Log::warning('Failed to get table schema', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Get table indexes.
	 *
	 * @param string $tableName
	 * @return array
	 */
	protected function getTableIndexes(string $tableName): array
	{
		$indexes = [];

		try {
			$driver = $this->detectDatabaseDriver();

			if ($driver === 'sqlite') {
				$indexList = DB::select("PRAGMA index_list({$tableName})");
				foreach ($indexList as $index) {
					if ($index->name === 'sqlite_autoindex_' . $tableName . '_1') {
						continue; // Skip auto-indexes
					}
					$indexInfo = DB::select("PRAGMA index_info({$index->name})");
					$columns = array_map(fn($info) => $info->name, $indexInfo);
					$indexes[] = [
						'name' => $index->name,
						'unique' => $index->unique == 1,
						'columns' => $columns,
					];
				}
			} else {
				$databaseName = DB::connection()->getDatabaseName();
				$indexData = DB::select("
                    SELECT 
                        INDEX_NAME,
                        COLUMN_NAME,
                        NON_UNIQUE,
                        SEQ_IN_INDEX
                    FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                    AND INDEX_NAME != 'PRIMARY'
                    ORDER BY INDEX_NAME, SEQ_IN_INDEX
                ", [$databaseName, $tableName]);

				foreach ($indexData as $index) {
					$indexName = $index->INDEX_NAME;
					if (!isset($indexes[$indexName])) {
						$indexes[$indexName] = [
							'name' => $indexName,
							'unique' => $index->NON_UNIQUE == 0,
							'columns' => [],
						];
					}
					$indexes[$indexName]['columns'][] = $index->COLUMN_NAME;
				}
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get table indexes', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
		}

		return array_values($indexes);
	}

	/**
	 * Get table foreign keys.
	 *
	 * @param string $tableName
	 * @return array
	 */
	protected function getTableForeignKeys(string $tableName): array
	{
		$foreignKeys = [];

		try {
			$driver = $this->detectDatabaseDriver();

			if ($driver === 'sqlite') {
				$fks = DB::select("PRAGMA foreign_key_list({$tableName})");
				foreach ($fks as $fk) {
					$foreignKeys[] = [
						'column' => $fk->from,
						'references_table' => $fk->table,
						'references_column' => $fk->to,
						'constraint_name' => null,
					];
				}
			} else {
				$databaseName = DB::connection()->getDatabaseName();
				$fks = DB::select("
                    SELECT 
                        TABLE_NAME,
                        COLUMN_NAME,
                        REFERENCED_TABLE_NAME,
                        REFERENCED_COLUMN_NAME,
                        CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = ?
                    AND TABLE_NAME = ?
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                ", [$databaseName, $tableName]);

				foreach ($fks as $fk) {
					$foreignKeys[] = [
						'column' => $fk->COLUMN_NAME,
						'references_table' => $fk->REFERENCED_TABLE_NAME,
						'references_column' => $fk->REFERENCED_COLUMN_NAME,
						'constraint_name' => $fk->CONSTRAINT_NAME,
					];
				}
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get foreign keys', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
		}

		return $foreignKeys;
	}

	/**
	 * Identify columns that might need indexes based on common patterns.
	 * These are columns that are frequently queried but don't have indexes.
	 *
	 * @param array $allColumns
	 * @param array $indexedColumns
	 * @param array $foreignKeys
	 * @return array
	 */
	protected function identifyPotentiallyMissingIndexes(array $allColumns, array $indexedColumns, array $foreignKeys): array
	{
		$potentiallyMissing = [];

		// Common patterns for columns that typically need indexes
		$frequentlyQueriedPatterns = [
			'/_id$/',           // Foreign key columns (user_id, order_id, etc.)
			'/^created_at$/',   // Timestamp columns used for filtering
			'/^updated_at$/',   // Timestamp columns
			'/^deleted_at$/',   // Soft delete columns
			'/^status$/',       // Status columns
			'/^type$/',         // Type columns
			'/^email$/',        // Email columns (often used in WHERE clauses)
			'/^name$/',         // Name columns (often searched)
		];

		$fkColumns = array_map(fn($fk) => $fk['column'], $foreignKeys);

		foreach ($allColumns as $column) {
			// Skip if already indexed
			if (in_array($column, $indexedColumns)) {
				continue;
			}

			// Skip if it's a foreign key (they auto-index in MySQL, but check anyway)
			if (in_array($column, $fkColumns)) {
				continue;
			}

			// Check if column matches frequently queried patterns
			foreach ($frequentlyQueriedPatterns as $pattern) {
				if (preg_match($pattern, $column)) {
					$potentiallyMissing[] = [
						'column' => $column,
						'reason' => $this->getIndexReason($column, $pattern),
					];
					break; // Only add once per column
				}
			}
		}

		return $potentiallyMissing;
	}

	/**
	 * Get human-readable reason why a column might need an index.
	 *
	 * @param string $column
	 * @param string $pattern
	 * @return string
	 */
	protected function getIndexReason(string $column, string $pattern): string
	{
		if (preg_match('/_id$/', $pattern)) {
			return 'Foreign key pattern - frequently used in JOINs and WHERE clauses';
		}
		if (preg_match('/^created_at$/', $pattern) || preg_match('/^updated_at$/', $pattern)) {
			return 'Timestamp column - commonly used for filtering and sorting';
		}
		if (preg_match('/^deleted_at$/', $pattern)) {
			return 'Soft delete column - used in WHERE clauses to filter deleted records';
		}
		if (preg_match('/^status$/', $pattern) || preg_match('/^type$/', $pattern)) {
			return 'Categorical column - frequently used in WHERE clauses';
		}
		if (preg_match('/^email$/', $pattern)) {
			return 'Email column - often used for lookups and authentication';
		}
		if (preg_match('/^name$/', $pattern)) {
			return 'Name column - commonly searched and filtered';
		}
		return 'Matches frequently queried column pattern';
	}

	/**
	 * Get table constraints.
	 *
	 * @param string $tableName
	 * @return array
	 */
	protected function getTableConstraints(string $tableName): array
	{
		$constraints = [
			'unique' => [],
			'check' => [],
			'defaults' => [],
		];

		try {
			$driver = $this->detectDatabaseDriver();

			if ($driver === 'sqlite') {
				// SQLite constraints are harder to extract, skip for now
				return $constraints;
			}

			$databaseName = DB::connection()->getDatabaseName();

			// Get unique constraints
			$uniqueConstraints = DB::select("
                SELECT 
                    tc.CONSTRAINT_NAME,
                    kcu.COLUMN_NAME
                FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    AND tc.TABLE_NAME = kcu.TABLE_NAME
                WHERE tc.TABLE_SCHEMA = ?
                AND tc.TABLE_NAME = ?
                AND tc.CONSTRAINT_TYPE = 'UNIQUE'
                ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
            ", [$databaseName, $tableName]);

			foreach ($uniqueConstraints as $constraint) {
				$constraintName = $constraint->CONSTRAINT_NAME;
				if (!isset($constraints['unique'][$constraintName])) {
					$constraints['unique'][$constraintName] = [];
				}
				$constraints['unique'][$constraintName][] = $constraint->COLUMN_NAME;
			}

			// Get check constraints (MySQL 8.0+)
			try {
				$checkConstraints = DB::select("
                    SELECT 
                        CONSTRAINT_NAME,
                        CHECK_CLAUSE
                    FROM information_schema.CHECK_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = ?
                    AND TABLE_NAME = ?
                ", [$databaseName, $tableName]);

				foreach ($checkConstraints as $constraint) {
					$constraints['check'][] = [
						'name' => $constraint->CONSTRAINT_NAME,
						'clause' => $constraint->CHECK_CLAUSE,
					];
				}
			} catch (\Exception $e) {
				// Check constraints might not be supported
			}

			// Get default values
			$defaults = DB::select("
                SELECT 
                    COLUMN_NAME,
                    COLUMN_DEFAULT,
                    EXTRA
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND (COLUMN_DEFAULT IS NOT NULL OR EXTRA LIKE '%auto_increment%')
            ", [$databaseName, $tableName]);

			foreach ($defaults as $default) {
				$constraints['defaults'][$default->COLUMN_NAME] = [
					'default' => $default->COLUMN_DEFAULT,
					'auto_increment' => strpos($default->EXTRA ?? '', 'auto_increment') !== false,
				];
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get table constraints', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
		}

		return $constraints;
	}

	/**
	 * Get sample data from a table.
	 *
	 * @param string $tableName
	 * @param int $limit
	 * @return array
	 */
	public function getTableData(string $tableName, int $limit = 100): array
	{
		try {
			if (!Schema::hasTable($tableName)) {
				return [];
			}

			$data = DB::table($tableName)
				->limit($limit)
				->get()
				->map(function ($row) {
					// Sanitize sensitive fields
					$sanitized = [];
					foreach ((array) $row as $key => $value) {
						$keyLower = strtolower($key);
						if (
							strpos($keyLower, 'password') !== false ||
							strpos($keyLower, 'token') !== false ||
							strpos($keyLower, 'secret') !== false ||
							strpos($keyLower, 'key') !== false
						) {
							$sanitized[$key] = '***REDACTED***';
						} else {
							$sanitized[$key] = $value;
						}
					}
					return $sanitized;
				})
				->toArray();

			return $data;
		} catch (\Exception $e) {
			Log::warning('Failed to get table data', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Get schema information for multiple tables.
	 *
	 * @param array $tableNames
	 * @return array
	 */
	public function getMultipleTableSchemas(array $tableNames): array
	{
		$schemas = [];
		foreach ($tableNames as $tableName) {
			$schema = $this->getTableSchema($tableName);
			if ($schema) {
				$schemas[] = $schema;
			}
		}
		return $schemas;
	}

	/**
	 * Get data from multiple tables.
	 *
	 * @param array $tableNames
	 * @param int $limitPerTable
	 * @return array
	 */
	public function getMultipleTableData(array $tableNames, int $limitPerTable = 100): array
	{
		$data = [];
		foreach ($tableNames as $tableName) {
			$tableData = $this->getTableData($tableName, $limitPerTable);
			if (!empty($tableData)) {
				$data[$tableName] = $tableData;
			}
		}
		return $data;
	}
}