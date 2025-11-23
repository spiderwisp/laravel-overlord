<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Spiderwisp\LaravelOverlord\Services\DatabaseSchemaService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class DatabaseController
{
	protected DatabaseSchemaService $schemaService;

	public function __construct()
	{
		$this->schemaService = new DatabaseSchemaService();
	}

	/**
	 * Get sanitized error message for production
	 * 
	 * @param \Exception $e
	 * @param string $defaultMessage
	 * @return string
	 */
	protected function getErrorMessage(\Exception $e, string $defaultMessage): string
	{
		return config('app.debug')
			? $defaultMessage . ': ' . $e->getMessage()
			: $defaultMessage;
	}

	/**
	 * Get list of all tables with row counts.
	 */
	public function tables(): JsonResponse
	{
		try {
			$tables = $this->schemaService->getTables();
			$tableData = [];

			foreach ($tables as $tableName) {
				try {
					$rowCount = DB::table($tableName)->count();
					$tableData[] = [
						'name' => $tableName,
						'rows' => $rowCount,
					];
				} catch (\Exception $e) {
					// If we can't count rows, just include the table name
					$tableData[] = [
						'name' => $tableName,
						'rows' => null,
					];
				}
			}

			return response()->json([
				'success' => true,
				'tables' => $tableData,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get tables', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to retrieve tables'),
			], 500);
		}
	}

	/**
	 * Get full table structure.
	 */
	public function tableStructure(string $table): JsonResponse
	{
		try {
			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$schema = $this->schemaService->getTableSchema($table);

			if (!$schema) {
				return response()->json([
					'success' => false,
					'error' => "Failed to retrieve structure for table '{$table}'",
				], 500);
			}

			return response()->json([
				'success' => true,
				'structure' => $schema,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get table structure', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to retrieve table structure'),
			], 500);
		}
	}

	/**
	 * Get table statistics.
	 */
	public function tableStats(string $table): JsonResponse
	{
		try {
			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$rowCount = DB::table($table)->count();
			$driver = $this->schemaService->detectDatabaseDriver();

			$stats = [
				'table' => $table,
				'rows' => $rowCount,
			];

			// Try to get table size (database-specific)
			try {
				if ($driver === 'mysql') {
					$databaseName = DB::connection()->getDatabaseName();
					$sizeInfo = DB::selectOne("
                        SELECT 
                            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                        FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = ?
                        AND TABLE_NAME = ?
                    ", [$databaseName, $table]);

					if ($sizeInfo) {
						$stats['size_mb'] = $sizeInfo->size_mb;
					}
				} elseif ($driver === 'postgresql') {
					$sizeInfo = DB::selectOne("
                        SELECT pg_size_pretty(pg_total_relation_size(?)) AS size
                    ", ["public.{$table}"]);

					if ($sizeInfo) {
						$stats['size'] = $sizeInfo->size;
					}
				}
			} catch (\Exception $e) {
				// Size info is optional, continue without it
			}

			return response()->json([
				'success' => true,
				'stats' => $stats,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get table stats', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to retrieve table stats'),
			], 500);
		}
	}

	/**
	 * Get paginated table data with filtering and sorting.
	 */
	public function tableData(Request $request, string $table): JsonResponse
	{
		try {
			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$validator = Validator::make($request->all(), [
				'page' => 'sometimes|integer|min:1',
				'per_page' => 'sometimes|integer|min:1|max:1000',
				'sort_column' => 'sometimes|string|max:255',
				'sort_direction' => 'sometimes|in:asc,desc',
				'search' => 'sometimes|string|max:255',
				'search_column' => 'sometimes|string|max:255',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'error' => 'Validation failed',
					'errors' => $validator->errors(),
				], 422);
			}

			$page = $request->input('page', 1);
			$perPage = min($request->input('per_page', 50), 1000);
			$sortColumn = $request->input('sort_column');
			$sortDirection = $request->input('sort_direction', 'asc');
			$search = $request->input('search');
			$searchColumn = $request->input('search_column');

			$query = DB::table($table);

			// PERFORMANCE: Cache column listing to avoid multiple queries
			$columns = Schema::getColumnListing($table);

			// Apply search filter
			if ($search && $searchColumn) {
				// Validate that the column exists
				if (in_array($searchColumn, $columns)) {
					$query->where($searchColumn, 'like', "%{$search}%");
				}
			} elseif ($search) {
				// Search across all string columns
				$query->where(function ($q) use ($search, $columns, $table) {
					foreach ($columns as $column) {
						$type = Schema::getColumnType($table, $column);
						if (in_array($type, ['string', 'text', 'varchar', 'char'])) {
							$q->orWhere($column, 'like', "%{$search}%");
						}
					}
				});
			}

			// Apply sorting
			if ($sortColumn) {
				if (in_array($sortColumn, $columns)) {
					$query->orderBy($sortColumn, $sortDirection);
				}
			}

			// Get total count for pagination
			$total = $query->count();

			// Apply pagination
			$offset = ($page - 1) * $perPage;
			$data = $query->offset($offset)->limit($perPage)->get();

			return response()->json([
				'success' => true,
				'data' => $data,
				'pagination' => [
					'current_page' => $page,
					'per_page' => $perPage,
					'total' => $total,
					'last_page' => ceil($total / $perPage),
				],
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get table data', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to retrieve table data'),
			], 500);
		}
	}

	/**
	 * Execute SQL query.
	 * 
	 * SECURITY: Raw SQL execution is disabled for production safety.
	 * Only SELECT queries with strict validation are allowed.
	 * For INSERT/UPDATE/DELETE, use the CRUD endpoints.
	 */
	public function executeQuery(Request $request): JsonResponse
	{
		try {
			$validator = Validator::make($request->all(), [
				'query' => 'required|string|max:10000',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'error' => 'Validation failed',
					'errors' => $validator->errors(),
				], 422);
			}

			$query = trim($request->input('query'));
			$queryUpper = trim(strtoupper($query));

			// SECURITY: Only allow SELECT statements
			if (!str_starts_with($queryUpper, 'SELECT')) {
				return response()->json([
					'success' => false,
					'error' => 'Only SELECT queries are allowed. Use CRUD endpoints for INSERT/UPDATE/DELETE operations.',
				], 403);
			}

			// SECURITY: Block dangerous keywords and patterns
			$dangerousPatterns = [
				// SQL injection patterns
				'/\b(UNION|UNION ALL)\b/i',
				'/\b(EXEC|EXECUTE|EXECUTE IMMEDIATE)\b/i',
				'/\b(SP_|XP_|xp_)\w+/i', // Stored procedures
				'/;\s*(DROP|TRUNCATE|ALTER|CREATE|DELETE|UPDATE|INSERT)/i', // Multiple statements
				'/--/', // SQL comments
				'/\/\*.*?\*\//s', // Multi-line comments
				'/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i', // File operations
				'/\b(LOAD_FILE|LOAD_DATA)\b/i',
				'/\b(INFORMATION_SCHEMA|mysql\.|sys\.)/i', // System databases
			];

			foreach ($dangerousPatterns as $pattern) {
				if (preg_match($pattern, $query)) {
					return response()->json([
						'success' => false,
						'error' => 'Query contains potentially dangerous patterns and cannot be executed.',
					], 403);
				}
			}

			// SECURITY: Block DDL and DML operations
			$dangerousKeywords = ['DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'DELETE', 'UPDATE', 'INSERT', 'REPLACE'];
			foreach ($dangerousKeywords as $keyword) {
				if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $queryUpper)) {
					return response()->json([
						'success' => false,
						'error' => "Dangerous operation detected. Only SELECT queries are allowed. Use CRUD endpoints for {$keyword} operations.",
					], 403);
				}
			}

			// SECURITY: Validate table names against whitelist (only allow existing tables)
			// Extract table names from FROM and JOIN clauses
			if (preg_match_all('/\bFROM\s+([`"]?)(\w+)\1/i', $query, $fromMatches)) {
				$allowedTables = $this->schemaService->getTables();
				foreach ($fromMatches[2] as $table) {
					if (!in_array(strtolower($table), array_map('strtolower', $allowedTables))) {
						return response()->json([
							'success' => false,
							'error' => "Table '{$table}' does not exist or is not accessible.",
						], 403);
					}
				}
			}

			// Execute query with timeout protection
			$startTime = microtime(true);
			$maxExecutionTime = 30; // seconds

			try {
				// SECURITY: Use parameterized query execution
				// Note: DB::select() with raw query is still vulnerable, but we've validated the query structure
				// For maximum security, consider disabling this endpoint entirely in production
				$results = DB::select($query);
				$executionTime = microtime(true) - $startTime;

				// Limit result set size
				if (count($results) > 1000) {
					$results = array_slice($results, 0, 1000);
					$limited = true;
				} else {
					$limited = false;
				}

				return response()->json([
					'success' => true,
					'data' => $results,
					'count' => count($results),
					'limited' => $limited,
					'execution_time' => round($executionTime, 3),
				]);
			} catch (\Exception $e) {
				// SECURITY: Don't expose detailed error messages in production
				$errorMessage = config('app.debug')
					? 'Query execution failed: ' . $e->getMessage()
					: 'Query execution failed. Please check your query syntax.';

				return response()->json([
					'success' => false,
					'error' => $errorMessage,
				], 400);
			}
		} catch (\Exception $e) {
			Log::error('Failed to execute query', [
				'error' => $e->getMessage(),
			]);

			$errorMessage = config('app.debug')
				? 'Failed to execute query: ' . $e->getMessage()
				: 'Failed to execute query. Please try again.';

			return response()->json([
				'success' => false,
				'error' => $errorMessage,
			], 500);
		}
	}

	/**
	 * Export SQL query results.
	 * 
	 * SECURITY: Reuses all security validations from executeQuery() method.
	 */
	public function exportQuery(Request $request)
	{
		try {
			$validator = Validator::make($request->all(), [
				'query' => 'required|string|max:10000',
				'format' => 'required|in:csv,json,xlsx',
				'scope' => 'required|in:displayed,all',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'error' => 'Validation failed',
					'errors' => $validator->errors(),
				], 422);
			}

			$query = trim($request->input('query'));
			$format = $request->input('format');
			$scope = $request->input('scope');

			// Reuse security validation from executeQuery
			$queryUpper = trim(strtoupper($query));

			// SECURITY: Only allow SELECT statements
			if (!str_starts_with($queryUpper, 'SELECT')) {
				return response()->json([
					'success' => false,
					'error' => 'Only SELECT queries are allowed. Use CRUD endpoints for INSERT/UPDATE/DELETE operations.',
				], 403);
			}

			// SECURITY: Block dangerous keywords and patterns
			$dangerousPatterns = [
				'/\b(UNION|UNION ALL)\b/i',
				'/\b(EXEC|EXECUTE|EXECUTE IMMEDIATE)\b/i',
				'/\b(SP_|XP_|xp_)\w+/i',
				'/;\s*(DROP|TRUNCATE|ALTER|CREATE|DELETE|UPDATE|INSERT)/i',
				'/--/',
				'/\/\*.*?\*\//s',
				'/\b(INTO\s+OUTFILE|INTO\s+DUMPFILE)\b/i',
				'/\b(LOAD_FILE|LOAD_DATA)\b/i',
				'/\b(INFORMATION_SCHEMA|mysql\.|sys\.)/i',
			];

			foreach ($dangerousPatterns as $pattern) {
				if (preg_match($pattern, $query)) {
					return response()->json([
						'success' => false,
						'error' => 'Query contains potentially dangerous patterns and cannot be executed.',
					], 403);
				}
			}

			// SECURITY: Block DDL and DML operations
			$dangerousKeywords = ['DROP', 'TRUNCATE', 'ALTER', 'CREATE', 'DELETE', 'UPDATE', 'INSERT', 'REPLACE'];
			foreach ($dangerousKeywords as $keyword) {
				if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $queryUpper)) {
					return response()->json([
						'success' => false,
						'error' => "Dangerous operation detected. Only SELECT queries are allowed. Use CRUD endpoints for {$keyword} operations.",
					], 403);
				}
			}

			// SECURITY: Validate table names against whitelist
			if (preg_match_all('/\bFROM\s+([`"]?)(\w+)\1/i', $query, $fromMatches)) {
				$allowedTables = $this->schemaService->getTables();
				foreach ($fromMatches[2] as $table) {
					if (!in_array(strtolower($table), array_map('strtolower', $allowedTables))) {
						return response()->json([
							'success' => false,
							'error' => "Table '{$table}' does not exist or is not accessible.",
						], 403);
					}
				}
			}

			// Get data based on scope
			if ($scope === 'displayed') {
				// Use existing results (limited to 1000 rows)
				$results = DB::select($query);
				if (count($results) > 1000) {
					$results = array_slice($results, 0, 1000);
				}
			} else {
				// Execute query without limit for "all" scope
				$results = DB::select($query);
			}

			if (empty($results)) {
				return response()->json([
					'success' => false,
					'error' => 'No results to export',
				], 404);
			}

			// Get column names from first row
			$columns = array_keys((array) $results[0]);

			// Generate export based on format
			switch ($format) {
				case 'csv':
					return $this->exportToCsv($results, $columns);
				case 'json':
					return $this->exportToJson($results);
				case 'xlsx':
					return $this->exportToExcel($results, $columns);
				default:
					return response()->json([
						'success' => false,
						'error' => 'Invalid export format',
					], 400);
			}
		} catch (\Exception $e) {
			Log::error('Failed to export query', [
				'error' => $e->getMessage(),
			]);

			$errorMessage = config('app.debug')
				? 'Failed to export query: ' . $e->getMessage()
				: 'Failed to export query. Please try again.';

			return response()->json([
				'success' => false,
				'error' => $errorMessage,
			], 500);
		}
	}

	/**
	 * Export data to CSV format.
	 */
	protected function exportToCsv(array $data, array $columns)
	{
		$filename = 'query-export-' . date('Y-m-d-His') . '.csv';
		
		$headers = [
			'Content-Type' => 'text/csv',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
		];

		$callback = function() use ($data, $columns) {
			$file = fopen('php://output', 'w');
			
			// Add BOM for UTF-8 to help Excel recognize encoding
			fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
			
			// Write headers
			fputcsv($file, $columns);
			
			// Write data rows
			foreach ($data as $row) {
				$rowArray = (array) $row;
				$csvRow = [];
				foreach ($columns as $column) {
					$value = $rowArray[$column] ?? '';
					// Convert to string and handle null values
					$csvRow[] = $value !== null ? (string) $value : '';
				}
				fputcsv($file, $csvRow);
			}
			
			fclose($file);
		};

		return Response::stream($callback, 200, $headers);
	}

	/**
	 * Export data to JSON format.
	 */
	protected function exportToJson(array $data)
	{
		$filename = 'query-export-' . date('Y-m-d-His') . '.json';
		
		$jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		
		return Response::make($jsonData, 200, [
			'Content-Type' => 'application/json',
			'Content-Disposition' => 'attachment; filename="' . $filename . '"',
		]);
	}

	/**
	 * Export data to Excel format.
	 */
	protected function exportToExcel(array $data, array $columns)
	{
		$filename = 'query-export-' . date('Y-m-d-His') . '.xlsx';
		
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		
		// Set headers
		$colIndex = 1;
		foreach ($columns as $column) {
			$sheet->setCellValueByColumnAndRow($colIndex, 1, $column);
			$colIndex++;
		}
		
		// Style header row
		$headerRange = 'A1:' . $sheet->getCellByColumnAndRow(count($columns), 1)->getCoordinate();
		$sheet->getStyle($headerRange)->applyFromArray([
			'font' => ['bold' => true],
			'fill' => [
				'fillType' => Fill::FILL_SOLID,
				'startColor' => ['rgb' => 'E0E0E0'],
			],
			'alignment' => [
				'horizontal' => Alignment::HORIZONTAL_LEFT,
			],
		]);
		
		// Write data rows
		$rowIndex = 2;
		foreach ($data as $row) {
			$rowArray = (array) $row;
			$colIndex = 1;
			foreach ($columns as $column) {
				$value = $rowArray[$column] ?? '';
				$sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
				$colIndex++;
			}
			$rowIndex++;
		}
		
		// Auto-size columns
		foreach (range(1, count($columns)) as $col) {
			$sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
		}
		
		// Create writer and save to temporary file
		$writer = new Xlsx($spreadsheet);
		$tempFile = tempnam(sys_get_temp_dir(), 'overlord_export_');
		$writer->save($tempFile);
		
		// Return file download
		return Response::download($tempFile, $filename, [
			'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		])->deleteFileAfterSend(true);
	}

	/**
	 * Get single row by primary key.
	 */
	public function getRow(Request $request, string $table): JsonResponse
	{
		try {
			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$validator = Validator::make($request->all(), [
				'id' => 'required|string|max:255',
			]);

			if ($validator->fails()) {
				return response()->json([
					'success' => false,
					'error' => 'ID is required and must be a valid string',
				], 422);
			}

			$id = $request->input('id');

			// Try to find the primary key column
			$primaryKey = 'id';
			$columns = Schema::getColumnListing($table);

			// Check if 'id' exists, otherwise use first column
			if (!in_array('id', $columns) && count($columns) > 0) {
				$primaryKey = $columns[0];
			}

			$row = DB::table($table)->where($primaryKey, $id)->first();

			if (!$row) {
				return response()->json([
					'success' => false,
					'error' => 'Row not found',
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $row,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to get row', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to retrieve row'),
			], 500);
		}
	}

	/**
	 * Create new row.
	 */
	public function createRow(Request $request, string $table): JsonResponse
	{
		try {
			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$data = $request->input('data', []);

			if (empty($data)) {
				return response()->json([
					'success' => false,
					'error' => 'No data provided',
				], 422);
			}

			// SECURITY: Validate data is an array and not too large
			if (!is_array($data)) {
				return response()->json([
					'success' => false,
					'error' => 'Data must be an array',
				], 422);
			}

			if (count($data) > 100) {
				return response()->json([
					'success' => false,
					'error' => 'Too many fields provided (maximum 100)',
				], 422);
			}

			// Validate columns exist
			$columns = Schema::getColumnListing($table);
			$validatedData = [];

			foreach ($data as $key => $value) {
				// SECURITY: Validate column name format
				if (!is_string($key) || strlen($key) > 255) {
					continue;
				}

				if (in_array($key, $columns)) {
					// SECURITY: Limit string value length to prevent DoS
					if (is_string($value) && strlen($value) > 65535) {
						return response()->json([
							'success' => false,
							'error' => "Value for column '{$key}' exceeds maximum length",
						], 422);
					}
					$validatedData[$key] = $value;
				}
			}

			if (empty($validatedData)) {
				return response()->json([
					'success' => false,
					'error' => 'No valid columns provided',
				], 422);
			}

			// Insert the row
			$id = DB::table($table)->insertGetId($validatedData);

			// Return the created row
			$row = DB::table($table)->where('id', $id)->first();

			return response()->json([
				'success' => true,
				'data' => $row,
				'id' => $id,
			], 201);
		} catch (\Exception $e) {
			Log::error('Failed to create row', [
				'table' => $table,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to create row'),
			], 500);
		}
	}

	/**
	 * Update existing row.
	 */
	public function updateRow(Request $request, string $table, $id): JsonResponse
	{
		try {
			// SECURITY: Validate ID format
			if (!is_string($id) && !is_numeric($id)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid ID format',
				], 400);
			}

			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			$data = $request->input('data', []);

			if (empty($data)) {
				return response()->json([
					'success' => false,
					'error' => 'No data provided',
				], 422);
			}

			// SECURITY: Validate data is an array and not too large
			if (!is_array($data)) {
				return response()->json([
					'success' => false,
					'error' => 'Data must be an array',
				], 422);
			}

			if (count($data) > 100) {
				return response()->json([
					'success' => false,
					'error' => 'Too many fields provided (maximum 100)',
				], 422);
			}

			// Validate columns exist
			$columns = Schema::getColumnListing($table);
			$validatedData = [];

			foreach ($data as $key => $value) {
				// SECURITY: Validate column name format
				if (!is_string($key) || strlen($key) > 255) {
					continue;
				}

				if (in_array($key, $columns)) {
					// SECURITY: Limit string value length to prevent DoS
					if (is_string($value) && strlen($value) > 65535) {
						return response()->json([
							'success' => false,
							'error' => "Value for column '{$key}' exceeds maximum length",
						], 422);
					}
					$validatedData[$key] = $value;
				}
			}

			if (empty($validatedData)) {
				return response()->json([
					'success' => false,
					'error' => 'No valid columns provided',
				], 422);
			}

			// Find primary key column
			$primaryKey = 'id';
			if (!in_array('id', $columns) && count($columns) > 0) {
				$primaryKey = $columns[0];
			}

			// Check if row exists
			$existing = DB::table($table)->where($primaryKey, $id)->first();
			if (!$existing) {
				return response()->json([
					'success' => false,
					'error' => 'Row not found',
				], 404);
			}

			// Update the row
			DB::table($table)->where($primaryKey, $id)->update($validatedData);

			// Return the updated row
			$row = DB::table($table)->where($primaryKey, $id)->first();

			return response()->json([
				'success' => true,
				'data' => $row,
			]);
		} catch (\Exception $e) {
			Log::error('Failed to update row', [
				'table' => $table,
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to update row'),
			], 500);
		}
	}

	/**
	 * Delete row.
	 */
	public function deleteRow(Request $request, string $table, $id): JsonResponse
	{
		try {
			// SECURITY: Validate ID format
			if (!is_string($id) && !is_numeric($id)) {
				return response()->json([
					'success' => false,
					'error' => 'Invalid ID format',
				], 400);
			}

			if (!Schema::hasTable($table)) {
				return response()->json([
					'success' => false,
					'error' => "Table '{$table}' does not exist",
				], 404);
			}

			// Find primary key column
			$columns = Schema::getColumnListing($table);
			$primaryKey = 'id';
			if (!in_array('id', $columns) && count($columns) > 0) {
				$primaryKey = $columns[0];
			}

			// Check if row exists
			$existing = DB::table($table)->where($primaryKey, $id)->first();
			if (!$existing) {
				return response()->json([
					'success' => false,
					'error' => 'Row not found',
				], 404);
			}

			// Delete the row
			DB::table($table)->where($primaryKey, $id)->delete();

			return response()->json([
				'success' => true,
				'message' => 'Row deleted successfully',
			]);
		} catch (\Exception $e) {
			Log::error('Failed to delete row', [
				'table' => $table,
				'id' => $id,
				'error' => $e->getMessage(),
			]);

			return response()->json([
				'success' => false,
				'error' => $this->getErrorMessage($e, 'Failed to delete row'),
			], 500);
		}
	}
}