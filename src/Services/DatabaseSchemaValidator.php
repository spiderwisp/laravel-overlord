<?php

namespace Spiderwisp\LaravelOverlord\Services;

/**
 * Validates database schema issues to filter out false positives.
 * Performs deterministic checks on verifiable facts before/after AI analysis.
 */
class DatabaseSchemaValidator
{
	protected DatabaseSchemaService $schemaService;
	protected array $schemaCache = []; // Cache schemas per table to avoid repeated queries

	public function __construct(DatabaseSchemaService $schemaService)
	{
		$this->schemaService = $schemaService;
	}

	/**
	 * Validate if a missing index issue is a false positive.
	 * Checks against actual database schema.
	 *
	 * @param array $issue The issue to validate
	 * @param string $tableName The table name
	 * @return bool True if it's a false positive (should be filtered), false if valid
	 */
	public function isFalsePositiveMissingIndex(array $issue, string $tableName): bool
	{
		// Only check issues about missing indexes
		$title = strtolower($issue['title'] ?? '');
		$description = strtolower($issue['description'] ?? '');
		$suggestion = strtolower($issue['suggestion'] ?? '');

		$isAboutMissingIndex =
			stripos($title, 'missing index') !== false ||
			stripos($title, 'no index') !== false ||
			stripos($description, 'missing index') !== false ||
			stripos($description, 'no index') !== false ||
			stripos($description, 'does not have an index') !== false ||
			stripos($description, 'lacks an index') !== false ||
			stripos($suggestion, 'create index') !== false ||
			stripos($suggestion, 'add index') !== false;

		if (!$isAboutMissingIndex) {
			return false; // Not about missing indexes, so not a false positive
		}

		// Extract column name from issue
		$columnName = null;
		if (isset($issue['location']['column'])) {
			$columnName = $issue['location']['column'];
		} else {
			// Try to extract from title/description (e.g., "Missing index on user_id")
			if (preg_match('/\b([a-z_]+_id|[a-z_]+)\b/i', $title, $matches)) {
				$columnName = $matches[1];
			}
		}

		if (!$columnName) {
			return false; // Can't determine column, let it through
		}

		// Check if the column actually has an index in the database
		try {
			// Use cached schema to avoid repeated database queries
			if (!isset($this->schemaCache[$tableName])) {
				$this->schemaCache[$tableName] = $this->schemaService->getTableSchema($tableName);
			}

			$schema = $this->schemaCache[$tableName];

			if (!$schema) {
				return false; // Can't verify, let it through
			}

			// Check indexed_columns array (includes indexes from foreign keys)
			$indexedColumns = $schema['indexed_columns'] ?? [];
			if (in_array($columnName, $indexedColumns)) {
				return true; // It's a false positive - column IS indexed
			}

			// Also check indexes array for composite indexes
			$indexes = $schema['indexes'] ?? [];
			foreach ($indexes as $index) {
				if (in_array($columnName, $index['columns'] ?? [])) {
					return true; // It's a false positive - column IS in an index
				}
			}

			// Check foreign keys (they auto-index in MySQL)
			$foreignKeys = $schema['foreign_keys'] ?? [];
			foreach ($foreignKeys as $fk) {
				if ($fk['column'] === $columnName) {
					return true; // It's a false positive - foreign keys auto-index
				}
			}

			// Check column metadata
			$columns = $schema['columns'] ?? [];
			if (isset($columns[$columnName]['has_index']) && $columns[$columnName]['has_index'] === true) {
				return true; // It's a false positive - metadata confirms index exists
			}

		} catch (\Exception $e) {
			// On error, don't filter it out (safer to show it than hide a real issue)
			return false;
		}

		return false; // Not a false positive - column truly doesn't have an index
	}

	/**
	 * Validate if a JSON column type issue is a false positive.
	 * JSON column types are correct in Laravel/MySQL 5.7+/PostgreSQL.
	 *
	 * @param array $issue The issue to validate
	 * @param string $tableName The table name
	 * @return bool True if it's a false positive (should be filtered), false if valid
	 */
	public function isFalsePositiveJsonColumn(array $issue, string $tableName): bool
	{
		// Check if issue is about JSON column type being wrong
		$title = strtolower($issue['title'] ?? '');
		$description = strtolower($issue['description'] ?? '');

		$isAboutJsonType =
			stripos($title, 'json') !== false ||
			stripos($description, 'json column') !== false ||
			stripos($description, 'json type') !== false ||
			stripos($description, 'json data type') !== false;

		if (!$isAboutJsonType) {
			return false; // Not about JSON type
		}

		// Check if it's complaining about the column type itself (not data issues)
		$isComplainingAboutType =
			stripos($description, 'should not be json') !== false ||
			stripos($description, 'json column type') !== false ||
			stripos($description, 'use a dedicated json') !== false ||
			stripos($description, 'separate table for json') !== false;

		if ($isComplainingAboutType) {
			return true; // It's a false positive - JSON column types are correct
		}

		return false; // Might be a real issue about JSON data, not type
	}

	/**
	 * Filter out false positives from a list of issues.
	 *
	 * @param array $issues Array of issues to filter
	 * @param string $tableName The table name
	 * @return array Filtered issues (false positives removed)
	 */
	public function filterFalsePositives(array $issues, string $tableName): array
	{
		$filtered = [];
		$filteredCount = 0;

		// Pre-load schema for this table to avoid repeated queries
		if (!isset($this->schemaCache[$tableName])) {
			$this->schemaCache[$tableName] = $this->schemaService->getTableSchema($tableName);
		}

		foreach ($issues as $issue) {
			$isFalsePositive = false;

			// Check for missing index false positives
			if ($this->isFalsePositiveMissingIndex($issue, $tableName)) {
				$isFalsePositive = true;
				$filteredCount++;
			}

			// Check for JSON column type false positives
			if (!$isFalsePositive && $this->isFalsePositiveJsonColumn($issue, $tableName)) {
				$isFalsePositive = true;
				$filteredCount++;
			}

			if (!$isFalsePositive) {
				$filtered[] = $issue;
			}
		}

		return $filtered;
	}
}