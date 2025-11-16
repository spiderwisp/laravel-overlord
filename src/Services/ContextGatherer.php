<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ContextGatherer
{
	protected $modelDiscovery;
	protected $controllerDiscovery;
	protected $classDiscovery;
	protected $codebaseContextEnabled;
	protected $databaseContextEnabled;
	protected $logContextEnabled;
	protected $maxCodebaseFiles;
	protected $maxDatabaseTables;
	protected $maxLogEntries;
	protected $contextCacheTtl;

	public function __construct(
		ModelDiscovery $modelDiscovery = null,
		ControllerDiscovery $controllerDiscovery = null,
		ClassDiscovery $classDiscovery = null
	) {
		$this->modelDiscovery = $modelDiscovery;
		$this->controllerDiscovery = $controllerDiscovery;
		$this->classDiscovery = $classDiscovery;
		$this->codebaseContextEnabled = config('laravel-overlord.ai.codebase_context_enabled', true);
		$this->databaseContextEnabled = config('laravel-overlord.ai.database_context_enabled', true);
		$this->logContextEnabled = config('laravel-overlord.ai.log_context_enabled', true);
		// Enforce maximum limits to prevent large API payloads (server-side will enforce stricter limits)
		$this->maxCodebaseFiles = min(config('laravel-overlord.ai.max_codebase_files', 5), 20);
		$this->maxDatabaseTables = min(config('laravel-overlord.ai.max_database_tables', 3), 10);
		$this->maxLogEntries = min(config('laravel-overlord.ai.max_log_entries', 10), 50);
		// Enforce minimum cache TTL to prevent excessive API calls
		$this->contextCacheTtl = max(config('laravel-overlord.ai.context_cache_ttl', 3600), 300);
	}

	/**
	 * Gather all relevant context for a user query
	 */
	public function gatherContext(string $query, ?array $logContext = null): array
	{
		$context = [
			'codebase' => '',
			'database' => '',
			'logs' => '',
		];

		if ($this->codebaseContextEnabled) {
			$context['codebase'] = $this->gatherCodebaseContext($query, $logContext);
		}

		if ($this->databaseContextEnabled) {
			$context['database'] = $this->gatherDatabaseContext($query);
		}

		if ($this->logContextEnabled) {
			$context['logs'] = $this->gatherLogContext($query, $logContext);
		}

		return $context;
	}

	/**
	 * Gather codebase context based on query
	 * Always provides comprehensive overview regardless of query
	 */
	protected function gatherCodebaseContext(string $query, ?array $logContext = null): string
	{
		$cacheKey = 'context_codebase_comprehensive';

		return Cache::remember($cacheKey, now()->addSeconds($this->contextCacheTtl), function () use ($query, $logContext) {
			$context = [];

			// Always include comprehensive codebase overview
			$context[] = $this->getComprehensiveCodebaseOverview();

			// Also include query-specific context if relevant
			$queryLower = strtolower($query);
			$mentionedModels = $this->extractMentionedModels($query);
			$mentionedControllers = $this->extractMentionedControllers($query);

			// If log context is provided, extract related classes from error
			if ($logContext && isset($logContext['parsed']['related_classes']) && !empty($logContext['parsed']['related_classes'])) {
				$relatedClasses = $logContext['parsed']['related_classes'];
				foreach ($relatedClasses as $className) {
					// Try to find as model
					$modelInfo = $this->getModelInfo($className);
					if ($modelInfo) {
						if (!in_array($className, $mentionedModels)) {
							$mentionedModels[] = $className;
						}
					}
					// Try to find as controller
					$controllerInfo = $this->getControllerInfo($className);
					if ($controllerInfo) {
						if (!in_array($className, $mentionedControllers)) {
							$mentionedControllers[] = $className;
						}
					}
				}
			}

			// Add detailed info for mentioned models
			if (!empty($mentionedModels)) {
				$detailedModels = [];
				foreach (array_slice($mentionedModels, 0, 5) as $modelName) {
					$modelInfo = $this->getModelInfo($modelName);
					if ($modelInfo) {
						$detailedModels[] = $this->formatModelInfo($modelInfo);
					}
				}
				if (!empty($detailedModels)) {
					$context[] = "## Query-Specific Model Details\n\n" . implode("\n\n", $detailedModels);
				}
			}

			// Add detailed info for mentioned controllers
			if (!empty($mentionedControllers)) {
				$detailedControllers = [];
				foreach (array_slice($mentionedControllers, 0, 5) as $controllerName) {
					$controllerInfo = $this->getControllerInfo($controllerName);
					if ($controllerInfo) {
						$detailedControllers[] = $this->formatControllerInfo($controllerInfo);
					}
				}
				if (!empty($detailedControllers)) {
					$context[] = "## Query-Specific Controller Details\n\n" . implode("\n\n", $detailedControllers);
				}
			}

			return implode("\n\n", $context);
		});
	}

	/**
	 * Get comprehensive codebase overview (all models, controllers, traits)
	 */
	protected function getComprehensiveCodebaseOverview(): string
	{
		$sections = [];

		// Get all models
		$models = $this->getAvailableModels();
		if (!empty($models)) {
			$modelsList = [];
			foreach ($models as $modelName => $fullClassName) {
				try {
					$modelInfo = $this->extractModelInfo($fullClassName);
					if ($modelInfo) {
						$modelsList[] = $this->formatModelInfoSummary($modelInfo);
					}
				} catch (\Exception $e) {
					// Skip models that can't be analyzed
					continue;
				}
			}
			if (!empty($modelsList)) {
				$sections[] = "## Available Models\n\n" . implode("\n", $modelsList);
			}
		}

		// Get all controllers
		$controllers = $this->getAvailableControllers();
		if (!empty($controllers)) {
			$controllersList = [];
			foreach ($controllers as $controller) {
				$controllersList[] = $this->formatControllerInfoSummary($controller);
			}
			if (!empty($controllersList)) {
				$sections[] = "## Available Controllers\n\n" . implode("\n", $controllersList);
			}
		}

		// Get all traits
		$traits = $this->getAvailableTraits();
		if (!empty($traits)) {
			$traitsList = [];
			foreach ($traits as $trait) {
				$traitsList[] = $this->formatTraitInfoSummary($trait);
			}
			if (!empty($traitsList)) {
				$sections[] = "## Available Traits\n\n" . implode("\n", $traitsList);
			}
		}

		// Get route information
		$routeInfo = $this->getRouteInformation();
		if (!empty($routeInfo)) {
			$sections[] = "## Available Routes\n\n" . $routeInfo;
		}

		return implode("\n\n", $sections);
	}

	/**
	 * Get route information
	 */
	protected function getRouteInformation(): string
	{
		try {
			$routes = \Illuminate\Support\Facades\Route::getRoutes();
			$routeList = [];

			foreach ($routes as $route) {
				$methods = $route->methods();
				$uri = $route->uri();
				$name = $route->getName();
				$action = $route->getAction();

				$routeInfo = "- ";

				// Add methods
				if (!empty($methods) && !in_array('HEAD', $methods)) {
					$methodsStr = implode('|', array_filter($methods, function ($m) {
						return $m !== 'HEAD'; }));
					$routeInfo .= "**{$methodsStr}** ";
				}

				// Add URI
				$routeInfo .= "`{$uri}`";

				// Add route name if available
				if ($name) {
					$routeInfo .= " (name: `{$name}`)";
				}

				// Add controller action if available
				if (isset($action['controller'])) {
					$routeInfo .= " → `{$action['controller']}`";
				} elseif (isset($action['uses']) && is_string($action['uses'])) {
					$routeInfo .= " → `{$action['uses']}`";
				}

				$routeList[] = $routeInfo;
			}

			if (empty($routeList)) {
				return '';
			}

			// Limit to first 50 routes to avoid context bloat
			return implode("\n", array_slice($routeList, 0, 50)) . (count($routeList) > 50 ? "\n\n*(Showing first 50 of " . count($routeList) . " routes)*" : '');
		} catch (\Exception $e) {
			Log::warning('Failed to get route information', [
				'error' => $e->getMessage(),
			]);
			return '';
		}
	}

	/**
	 * Format model info as summary (compact version)
	 */
	protected function formatModelInfoSummary(array $modelInfo): string
	{
		$output = "- **{$modelInfo['name']}** (`{$modelInfo['fullName']}`) - Table: `{$modelInfo['table']}`";

		if (!empty($modelInfo['fillable'])) {
			$fillableFields = array_map(function ($field) {
				return "`{$field}`";
			}, $modelInfo['fillable']);
			$fillableList = implode(', ', $fillableFields);
			$output .= " - Fillable: [{$fillableList}]";
		}

		if (!empty($modelInfo['relationships'])) {
			$relCount = count($modelInfo['relationships']);
			$output .= " - Relationships: {$relCount}";
		}

		return $output;
	}

	/**
	 * Format controller info as summary (compact version)
	 */
	protected function formatControllerInfoSummary(array $controllerInfo): string
	{
		$methodCount = count($controllerInfo['methods'] ?? []);
		return "- **{$controllerInfo['name']}** (`{$controllerInfo['fullName']}`) - Methods: {$methodCount}";
	}

	/**
	 * Format trait info as summary (compact version)
	 */
	protected function formatTraitInfoSummary(array $traitInfo): string
	{
		$methodCount = count($traitInfo['methods'] ?? []);
		return "- **{$traitInfo['name']}** (`{$traitInfo['fullName']}`) - Methods: {$methodCount}";
	}

	/**
	 * Get available traits
	 */
	protected function getAvailableTraits(): array
	{
		try {
			if ($this->classDiscovery === null) {
				$this->classDiscovery = new ClassDiscovery();
			}
			$classes = $this->classDiscovery->getClasses();

			// Filter for traits only
			$traits = [];
			foreach ($classes as $class) {
				if (isset($class['isTrait']) && $class['isTrait']) {
					$traits[] = $class;
				}
			}

			return $traits;
		} catch (\Exception $e) {
			Log::warning('Failed to get traits', ['error' => $e->getMessage()]);
			return [];
		}
	}

	/**
	 * Gather database context based on query
	 * Always provides comprehensive database overview
	 */
	protected function gatherDatabaseContext(string $query): string
	{
		$cacheKey = 'context_database_comprehensive';

		return Cache::remember($cacheKey, now()->addSeconds($this->contextCacheTtl), function () use ($query) {
			$context = [];

			// Always include comprehensive database overview
			$context[] = $this->getComprehensiveDatabaseOverview();

			// Also include query-specific table details if mentioned
			$mentionedTables = $this->extractMentionedTables($query);
			if (!empty($mentionedTables)) {
				$detailedTables = [];
				foreach (array_slice($mentionedTables, 0, 5) as $tableName) {
					$tableInfo = $this->getTableInfo($tableName);
					if ($tableInfo) {
						// Add sample data for query-specific tables
						$tableInfo['sample_data'] = $this->getTableSampleData($tableName, 3);
						$detailedTables[] = $this->formatTableInfo($tableInfo);
					}
				}
				if (!empty($detailedTables)) {
					$context[] = "## Query-Specific Table Details\n\n" . implode("\n\n", $detailedTables);
				}
			}

			return implode("\n\n", $context);
		});
	}

	/**
	 * Get comprehensive database overview (all tables, foreign keys, table-to-model mapping)
	 */
	protected function getComprehensiveDatabaseOverview(): string
	{
		$sections = [];

		// Get table-to-model mapping
		$tableModelMapping = $this->getTableModelMapping();
		if (!empty($tableModelMapping)) {
			$sections[] = "## Table-to-Model Mapping\n\n" . $this->formatTableModelMapping($tableModelMapping);
		}

		// Get all foreign key constraints
		$foreignKeys = $this->getAllForeignKeys();
		if (!empty($foreignKeys)) {
			$sections[] = "## Foreign Key Constraints\n\n" . $this->formatForeignKeys($foreignKeys);
		}

		// Get summary of all tables
		$allTables = $this->getAllTablesInfo();
		if (!empty($allTables)) {
			$tablesSummary = [];
			foreach ($allTables as $tableInfo) {
				$tablesSummary[] = $this->formatTableInfoSummary($tableInfo);
			}
			if (!empty($tablesSummary)) {
				$sections[] = "## Database Tables Summary\n\n" . implode("\n", $tablesSummary);
			}
		}

		return implode("\n\n", $sections);
	}

	/**
	 * Get table-to-model mapping (which tables have models, which don't)
	 */
	protected function getTableModelMapping(): array
	{
		$mapping = [
			'tables_with_models' => [],
			'tables_without_models' => [],
		];

		try {
			// Get all tables
			$databaseName = DB::connection()->getDatabaseName();
			$allTables = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$databaseName]);
			$tableNames = array_map(function ($table) {
				return $table->TABLE_NAME;
			}, $allTables);

			// Get all models
			$models = $this->getAvailableModels();
			$modelTables = [];
			foreach ($models as $modelName => $fullClassName) {
				try {
					$modelInfo = $this->extractModelInfo($fullClassName);
					if ($modelInfo && isset($modelInfo['table'])) {
						$modelTables[$modelInfo['table']] = [
							'model' => $modelName,
							'fullName' => $fullClassName,
						];
					}
				} catch (\Exception $e) {
					continue;
				}
			}

			// Categorize tables
			foreach ($tableNames as $tableName) {
				if (isset($modelTables[$tableName])) {
					$mapping['tables_with_models'][$tableName] = $modelTables[$tableName];
				} else {
					$mapping['tables_without_models'][] = $tableName;
				}
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get table-model mapping', ['error' => $e->getMessage()]);
		}

		return $mapping;
	}

	/**
	 * Format table-to-model mapping
	 */
	protected function formatTableModelMapping(array $mapping): string
	{
		$output = [];

		if (!empty($mapping['tables_with_models'])) {
			$output[] = "### Tables with Models:";
			foreach ($mapping['tables_with_models'] as $table => $info) {
				$output[] = "- `{$table}` → `{$info['fullName']}` (use `{$info['model']}::query()`)";
			}
		}

		if (!empty($mapping['tables_without_models'])) {
			$output[] = "\n### Tables without Models (use `DB::table()` instead):";
			foreach ($mapping['tables_without_models'] as $table) {
				$output[] = "- `{$table}` → Use `DB::table('{$table}')` (no model exists)";
			}
		}

		return implode("\n", $output);
	}

	/**
	 * Get all foreign key constraints
	 */
	protected function getAllForeignKeys(): array
	{
		$foreignKeys = [];

		try {
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
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ", [$databaseName]);

			foreach ($fks as $fk) {
				$table = $fk->TABLE_NAME;
				if (!isset($foreignKeys[$table])) {
					$foreignKeys[$table] = [];
				}
				$foreignKeys[$table][] = [
					'column' => $fk->COLUMN_NAME,
					'references_table' => $fk->REFERENCED_TABLE_NAME,
					'references_column' => $fk->REFERENCED_COLUMN_NAME,
					'constraint_name' => $fk->CONSTRAINT_NAME,
				];
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get foreign keys', ['error' => $e->getMessage()]);
		}

		return $foreignKeys;
	}

	/**
	 * Format foreign keys
	 */
	protected function formatForeignKeys(array $foreignKeys): string
	{
		$output = [];

		foreach ($foreignKeys as $table => $fks) {
			$output[] = "### Table: `{$table}`";
			foreach ($fks as $fk) {
				$output[] = "- `{$fk['column']}` → `{$fk['references_table']}.{$fk['references_column']}`";
			}
			$output[] = "";
		}

		return implode("\n", $output);
	}

	/**
	 * Format table info as summary (compact version)
	 */
	protected function formatTableInfoSummary(array $tableInfo): string
	{
		$columnCount = count($tableInfo['columns'] ?? []);
		$output = "- **`{$tableInfo['name']}`** - {$columnCount} columns";

		// Include actual column names (excluding standard Laravel columns unless they're the only ones)
		if (!empty($tableInfo['columns'])) {
			$standardColumns = ['id', 'created_at', 'updated_at'];
			$columnNames = array_keys($tableInfo['columns']);
			$nonStandardColumns = array_diff($columnNames, $standardColumns);

			// If there are non-standard columns, show those; otherwise show all columns
			$columnsToShow = !empty($nonStandardColumns) ? $nonStandardColumns : $columnNames;

			$columnFields = array_map(function ($column) {
				return "`{$column}`";
			}, $columnsToShow);
			$columnList = implode(', ', $columnFields);
			$output .= " - Columns: [{$columnList}]";
		}

		// Check if table has a model
		$models = $this->getAvailableModels();
		$hasModel = false;
		$modelName = null;
		$modelFillable = null;
		foreach ($models as $name => $fullClassName) {
			try {
				$modelInfo = $this->extractModelInfo($fullClassName);
				if ($modelInfo && isset($modelInfo['table']) && $modelInfo['table'] === $tableInfo['name']) {
					$hasModel = true;
					$modelName = $name;
					$modelFillable = $modelInfo['fillable'] ?? null;
					break;
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		if ($hasModel) {
			$output .= " - Model: `{$modelName}`";
			// If model has fillable fields, show them (they're the fields that can be mass-assigned)
			if (!empty($modelFillable)) {
				$fillableFields = array_map(function ($field) {
					return "`{$field}`";
				}, $modelFillable);
				$fillableList = implode(', ', $fillableFields);
				$output .= " - Fillable: [{$fillableList}]";
			}
		} else {
			$output .= " - No model (use `DB::table()`)";
		}

		return $output;
	}

	/**
	 * Gather log context based on query
	 */
	protected function gatherLogContext(string $query, ?array $logContext = null): string
	{
		$cacheKey = 'context_logs_' . md5($query . serialize($logContext));

		return Cache::remember($cacheKey, now()->addSeconds(300), function () use ($query, $logContext) { // Shorter cache for logs
			$context = [];
			$queryLower = strtolower($query);

			// If log context is provided, include detailed error information
			if ($logContext) {
				$errorDetails = [];

				if (isset($logContext['file'])) {
					$errorDetails[] = "**Log File:** " . basename($logContext['file']);
				}

				if (isset($logContext['line_number'])) {
					$errorDetails[] = "**Line Number:** " . $logContext['line_number'];
				}

				if (isset($logContext['parsed'])) {
					$parsed = $logContext['parsed'];

					if (isset($parsed['exception_class'])) {
						$errorDetails[] = "**Exception Class:** `" . $parsed['exception_class'] . "`";
					}

					if (isset($parsed['exception_message'])) {
						$errorDetails[] = "**Exception Message:** " . $parsed['exception_message'];
					}

					if (isset($parsed['file'])) {
						$errorDetails[] = "**Error File:** `" . $parsed['file'] . "`";
					}

					if (isset($parsed['line'])) {
						$errorDetails[] = "**Error Line:** " . $parsed['line'];
					}

					if (isset($parsed['related_classes']) && !empty($parsed['related_classes'])) {
						$errorDetails[] = "**Related Classes:** " . implode(', ', array_map(function ($class) {
							return "`" . $class . "`";
						}, $parsed['related_classes']));
					}
				}

				if (isset($logContext['error_line'])) {
					$errorDetails[] = "\n**Error Line Content:**\n```\n" . $logContext['error_line'] . "\n```";
				}

				if (isset($logContext['surrounding_lines']) && !empty($logContext['surrounding_lines'])) {
					$errorDetails[] = "\n**Surrounding Context:**\n```\n";
					foreach ($logContext['surrounding_lines'] as $surroundingLine) {
						$prefix = isset($surroundingLine['is_target']) && $surroundingLine['is_target'] ? '>>> ' : '    ';
						$errorDetails[] = $prefix . ($surroundingLine['line_number'] ?? '') . ': ' . ($surroundingLine['content'] ?? '');
					}
					$errorDetails[] = "```";
				}

				if (!empty($errorDetails)) {
					$context[] = "## Error Context from Logs\n\n" . implode("\n", $errorDetails);
				}
			}

			// Check if query mentions errors or debugging
			if (
				strpos($queryLower, 'error') !== false ||
				strpos($queryLower, 'debug') !== false ||
				strpos($queryLower, 'log') !== false ||
				strpos($queryLower, 'exception') !== false
			) {

				$logEntries = $this->getRecentLogEntries($this->maxLogEntries);
				if (!empty($logEntries)) {
					$context[] = "## Recent Log Entries\n\n" . implode("\n\n", $logEntries);
				}
			}

			return implode("\n\n", $context);
		});
	}

	/**
	 * Extract mentioned model names from query
	 */
	protected function extractMentionedModels(string $query): array
	{
		$models = [];
		$availableModels = $this->getAvailableModels();

		foreach ($availableModels as $modelName => $fullClassName) {
			$modelNameLower = strtolower($modelName);
			$queryLower = strtolower($query);

			if (preg_match('/\b' . preg_quote($modelNameLower, '/') . '\b/i', $queryLower)) {
				$models[] = $modelName;
			}
		}

		return array_unique($models);
	}

	/**
	 * Extract mentioned controller names from query
	 */
	protected function extractMentionedControllers(string $query): array
	{
		$controllers = [];
		$availableControllers = $this->getAvailableControllers();

		foreach ($availableControllers as $controller) {
			$controllerName = strtolower($controller['name'] ?? '');
			$queryLower = strtolower($query);

			if (preg_match('/\b' . preg_quote($controllerName, '/') . '\b/i', $queryLower)) {
				$controllers[] = $controllerName;
			}
		}

		return array_unique($controllers);
	}

	/**
	 * Extract mentioned table names from query
	 */
	protected function extractMentionedTables(string $query): array
	{
		$tables = [];

		try {
			// Use Laravel-native method to get table names
			$databaseName = DB::connection()->getDatabaseName();
			$allTables = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$databaseName]);
			$tableNames = array_map(function ($table) {
				return $table->TABLE_NAME;
			}, $allTables);
		} catch (\Exception $e) {
			Log::warning('Failed to get table names', ['error' => $e->getMessage()]);
			return [];
		}

		$queryLower = strtolower($query);

		foreach ($tableNames as $table) {
			$tableLower = strtolower($table);
			if (preg_match('/\b' . preg_quote($tableLower, '/') . '\b/i', $queryLower)) {
				$tables[] = $table;
			}
		}

		return array_unique($tables);
	}

	/**
	 * Get model information
	 */
	protected function getModelInfo(string $modelName): ?array
	{
		try {
			$models = $this->getAvailableModels();

			if (isset($models[$modelName])) {
				$fullClassName = $models[$modelName];
				return $this->extractModelInfo($fullClassName);
			}

			// Try case-insensitive match
			foreach ($models as $name => $fullClassName) {
				if (strtolower($name) === strtolower($modelName)) {
					return $this->extractModelInfo($fullClassName);
				}
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get model info', [
				'model' => $modelName,
				'error' => $e->getMessage(),
			]);
		}

		return null;
	}

	/**
	 * Extract detailed model information
	 */
	protected function extractModelInfo(string $fullClassName): ?array
	{
		try {
			if (!class_exists($fullClassName)) {
				return null;
			}

			$reflection = new \ReflectionClass($fullClassName);
			$model = new $fullClassName();

			$casts = $model->getCasts();
			$enumCasts = [];

			// Extract enum values from casts
			foreach ($casts as $attr => $cast) {
				if (is_string($cast) && class_exists($cast)) {
					try {
						$castReflection = new \ReflectionClass($cast);
						if ($castReflection->isEnum()) {
							$enumCases = [];
							if (method_exists($cast, 'cases')) {
								foreach ($cast::cases() as $case) {
									$enumCases[] = $case->name;
								}
							}
							if (!empty($enumCases)) {
								$enumCasts[$attr] = [
									'enum' => $cast,
									'values' => $enumCases,
								];
							}
						}
					} catch (\Exception $e) {
						// Skip if we can't reflect the enum
					}
				}
			}

			return [
				'name' => class_basename($fullClassName),
				'fullName' => $fullClassName,
				'table' => $model->getTable(),
				'fillable' => $model->getFillable(),
				'guarded' => $model->getGuarded(),
				'casts' => $casts,
				'enumCasts' => $enumCasts,
				'relationships' => $this->extractModelRelationships($reflection, $model),
				'scopes' => $this->extractModelScopes($reflection, $model),
				'customMethods' => $this->extractModelCustomMethods($reflection),
				'accessorsMutators' => $this->extractModelAccessorsMutators($reflection),
				'validationRules' => $this->extractModelValidationRules($fullClassName, $reflection),
			];
		} catch (\Exception $e) {
			Log::warning('Failed to extract model info', [
				'class' => $fullClassName,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Extract model scopes
	 */
	protected function extractModelScopes(\ReflectionClass $reflection, $model): array
	{
		$scopes = [];
		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		foreach ($methods as $method) {
			$methodName = $method->getName();

			// Check if method is a scope (starts with 'scope' and has at least one parameter)
			if (strpos($methodName, 'scope') === 0 && strlen($methodName) > 5 && $method->getNumberOfParameters() > 0) {
				$scopeName = lcfirst(substr($methodName, 5)); // Remove 'scope' prefix

				$parameters = [];
				foreach ($method->getParameters() as $param) {
					$paramInfo = '$' . $param->getName();
					if ($param->hasType()) {
						$paramInfo = $param->getType()->getName() . ' ' . $paramInfo;
					}
					if ($param->isDefaultValueAvailable()) {
						$default = $param->getDefaultValue();
						$paramInfo .= ' = ' . var_export($default, true);
					}
					$parameters[] = $paramInfo;
				}

				$scopes[$scopeName] = [
					'method' => $methodName,
					'parameters' => $parameters,
				];
			}
		}

		return $scopes;
	}

	/**
	 * Extract model accessors and mutators
	 */
	protected function extractModelAccessorsMutators(\ReflectionClass $reflection): array
	{
		$accessorsMutators = [
			'accessors' => [],
			'mutators' => [],
		];

		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		foreach ($methods as $method) {
			$methodName = $method->getName();

			// Check for accessor pattern: get{Attribute}Attribute
			if (preg_match('/^get(\w+)Attribute$/', $methodName, $matches)) {
				$attributeName = lcfirst($matches[1]);
				$accessorsMutators['accessors'][$attributeName] = $methodName;
			}

			// Check for mutator pattern: set{Attribute}Attribute
			if (preg_match('/^set(\w+)Attribute$/', $methodName, $matches)) {
				$attributeName = lcfirst($matches[1]);
				$accessorsMutators['mutators'][$attributeName] = $methodName;
			}
		}

		return $accessorsMutators;
	}

	/**
	 * Extract model validation rules
	 */
	protected function extractModelValidationRules(string $fullClassName, \ReflectionClass $reflection): array
	{
		$rules = [];

		try {
			// Check for $rules property
			if ($reflection->hasProperty('rules')) {
				$rulesProperty = $reflection->getProperty('rules');
				$rulesProperty->setAccessible(true);
				$model = new $fullClassName();
				$rulesValue = $rulesProperty->getValue($model);
				if (is_array($rulesValue)) {
					$rules = $rulesValue;
				}
			}

			// Check for rules() method
			if ($reflection->hasMethod('rules')) {
				$rulesMethod = $reflection->getMethod('rules');
				if ($rulesMethod->isPublic() && $rulesMethod->getNumberOfParameters() === 0) {
					try {
						$model = new $fullClassName();
						$rulesValue = $model->rules();
						if (is_array($rulesValue)) {
							$rules = array_merge($rules, $rulesValue);
						}
					} catch (\Exception $e) {
						// Skip if rules() method can't be called
					}
				}
			}
		} catch (\Exception $e) {
			// Skip if we can't extract rules
		}

		return $rules;
	}

	/**
	 * Extract custom model methods (excluding base Eloquent methods, relationships, accessors, mutators, scopes)
	 */
	protected function extractModelCustomMethods(\ReflectionClass $reflection): array
	{
		$customMethods = [];
		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		// Base Eloquent Model methods to exclude
		$baseModelMethods = [
			'getTable',
			'getConnection',
			'getConnectionName',
			'getKeyName',
			'getKeyType',
			'getIncrementing',
			'getKey',
			'getRouteKeyName',
			'getRouteKey',
			'getForeignKey',
			'getMorphClass',
			'getTable',
			'getFillable',
			'getGuarded',
			'getCasts',
			'getDates',
			'getDateFormat',
			'getHidden',
			'getVisible',
			'getAppends',
			'getAttributes',
			'getOriginal',
			'getChanges',
			'getDirty',
			'isDirty',
			'wasChanged',
			'getOriginal',
			'syncOriginal',
			'syncChanges',
			'isClean',
			'getRelations',
			'getRelation',
			'setRelation',
			'unsetRelation',
			'relationLoaded',
			'getRelationships',
			'touchOwners',
			'touch',
			'updateTimestamps',
			'freshTimestamp',
			'freshTimestampString',
			'usesTimestamps',
			'getCreatedAtColumn',
			'getUpdatedAtColumn',
			'getDeletedAtColumn',
			'getQualifiedKeyName',
			'getQualifiedCreatedAtColumn',
			'getQualifiedUpdatedAtColumn',
			'getQualifiedDeletedAtColumn',
			'newQuery',
			'newModelQuery',
			'newEloquentBuilder',
			'newBaseQueryBuilder',
			'onWriteConnection',
			'all',
			'find',
			'findOrFail',
			'findMany',
			'findOrNew',
			'first',
			'firstOrNew',
			'firstOrCreate',
			'updateOrCreate',
			'create',
			'forceCreate',
			'update',
			'save',
			'push',
			'delete',
			'forceDelete',
			'restore',
			'replicate',
			'refresh',
			'replicate',
			'is',
			'isNot',
			'wasRecentlyCreated',
			'usesUniqueIds',
			'newUniqueId',
			'uniqueIds',
			'getKeyForSelectQuery',
			'getKeyForSaveQuery',
			'getForeignKeyFor',
			'getPerPage',
			'setPerPage',
			'getTableName',
			'getTablePrefix',
			'getTableSuffix',
		];

		foreach ($methods as $method) {
			$methodName = $method->getName();

			// Skip magic methods
			if (strpos($methodName, '__') === 0) {
				continue;
			}

			// Skip base model methods
			if (in_array($methodName, $baseModelMethods)) {
				continue;
			}

			// Skip scopes (already handled separately)
			if (strpos($methodName, 'scope') === 0) {
				continue;
			}

			// Skip accessors and mutators (handled separately)
			if (preg_match('/^(get|set)(\w+)Attribute$/', $methodName)) {
				continue;
			}

			// Skip relationships (handled separately) - check return type
			try {
				$returnType = $method->getReturnType();
				if ($returnType && strpos($returnType->getName(), 'Illuminate\\Database\\Eloquent\\Relations') !== false) {
					continue;
				}
			} catch (\Exception $e) {
				// Continue if we can't check return type
			}

			// Skip methods with parameters (likely relationships or complex methods)
			// Actually, let's include them but note the parameters
			$parameters = [];
			foreach ($method->getParameters() as $param) {
				$paramInfo = '$' . $param->getName();
				if ($param->hasType()) {
					$paramInfo = $param->getType()->getName() . ' ' . $paramInfo;
				}
				if ($param->isDefaultValueAvailable()) {
					$default = $param->getDefaultValue();
					$paramInfo .= ' = ' . var_export($default, true);
				}
				$parameters[] = $paramInfo;
			}

			$customMethods[$methodName] = [
				'parameters' => $parameters,
			];
		}

		return $customMethods;
	}

	/**
	 * Extract model relationships
	 */
	protected function extractModelRelationships(\ReflectionClass $reflection, $model): array
	{
		$relationships = [];
		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		foreach ($methods as $method) {
			$methodName = $method->getName();

			if (strpos($methodName, '__') === 0 || $method->getNumberOfParameters() > 0) {
				continue;
			}

			try {
				$returnType = $method->getReturnType();
				if ($returnType && $returnType->getName() !== 'void') {
					$returnTypeName = $returnType->getName();

					if (strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relations') !== false) {
						try {
							$relation = $model->$methodName();
							if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
								$relatedModel = get_class($relation->getRelated());
								$relationType = $this->getRelationshipType($relation);

								$relationshipInfo = [
									'type' => $relationType,
									'related' => class_basename($relatedModel),
								];

								// Extract foreign key information based on relationship type
								if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
									$relationshipInfo['foreign_key'] = $relation->getForeignKeyName();
									$relationshipInfo['owner_key'] = $relation->getOwnerKeyName();
								} elseif (
									$relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne ||
									$relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany
								) {
									$relationshipInfo['foreign_key'] = $relation->getForeignKeyName();
									$relationshipInfo['local_key'] = $relation->getLocalKeyName();
								} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
									$relationshipInfo['pivot_table'] = $relation->getTable();
									$relationshipInfo['foreign_pivot_key'] = $relation->getForeignPivotKeyName();
									$relationshipInfo['related_pivot_key'] = $relation->getRelatedPivotKeyName();
								} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasManyThrough) {
									$relationshipInfo['first_key'] = $relation->getFirstKeyName();
									$relationshipInfo['second_key'] = $relation->getSecondKeyName();
									$relationshipInfo['local_key'] = $relation->getLocalKeyName();
									$relationshipInfo['second_local_key'] = $relation->getSecondLocalKeyName();
								}

								$relationships[$methodName] = $relationshipInfo;
							}
						} catch (\Exception $e) {
							continue;
						}
					}
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		return $relationships;
	}

	/**
	 * Get relationship type
	 */
	protected function getRelationshipType($relation): string
	{
		$class = get_class($relation);

		if (strpos($class, 'HasOne') !== false)
			return 'hasOne';
		if (strpos($class, 'HasMany') !== false)
			return 'hasMany';
		if (strpos($class, 'BelongsTo') !== false)
			return 'belongsTo';
		if (strpos($class, 'BelongsToMany') !== false)
			return 'belongsToMany';
		if (strpos($class, 'MorphTo') !== false)
			return 'morphTo';
		if (strpos($class, 'MorphMany') !== false)
			return 'morphMany';
		if (strpos($class, 'MorphOne') !== false)
			return 'morphOne';
		if (strpos($class, 'HasManyThrough') !== false)
			return 'hasManyThrough';

		return 'unknown';
	}

	/**
	 * Format model information for LLM
	 */
	protected function formatModelInfo(array $modelInfo): string
	{
		$output = "### Model: {$modelInfo['name']}\n\n";
		$output .= "**Table:** `{$modelInfo['table']}`\n\n";

		if (!empty($modelInfo['fillable'])) {
			$output .= "**Fillable attributes:** " . implode(', ', $modelInfo['fillable']) . "\n\n";
		}

		if (!empty($modelInfo['casts'])) {
			$casts = [];
			foreach ($modelInfo['casts'] as $attr => $cast) {
				$castStr = "{$attr}: {$cast}";
				// Add enum values if available
				if (isset($modelInfo['enumCasts'][$attr])) {
					$enumValues = implode(', ', $modelInfo['enumCasts'][$attr]['values']);
					$castStr .= " [values: {$enumValues}]";
				}
				$casts[] = $castStr;
			}
			$output .= "**Casts:** " . implode(', ', $casts) . "\n\n";
		}

		if (!empty($modelInfo['relationships'])) {
			$output .= "**Relationships:**\n";
			foreach ($modelInfo['relationships'] as $method => $rel) {
				$relDesc = "`{$method}()`: {$rel['type']} → {$rel['related']}";

				// Add foreign key information
				if (isset($rel['foreign_key'])) {
					$relDesc .= " (FK: `{$rel['foreign_key']}`";
					if (isset($rel['owner_key'])) {
						$relDesc .= ", owner key: `{$rel['owner_key']}`";
					} elseif (isset($rel['local_key'])) {
						$relDesc .= ", local key: `{$rel['local_key']}`";
					}
					$relDesc .= ")";
				}

				// Add pivot table info for belongsToMany
				if (isset($rel['pivot_table'])) {
					$relDesc .= " (pivot: `{$rel['pivot_table']}`";
					if (isset($rel['foreign_pivot_key'])) {
						$relDesc .= ", FK: `{$rel['foreign_pivot_key']}`, related FK: `{$rel['related_pivot_key']}`";
					}
					$relDesc .= ")";
				}

				// Add through table info for hasManyThrough
				if (isset($rel['first_key'])) {
					$relDesc .= " (through: first key `{$rel['first_key']}`, second key `{$rel['second_key']}`)";
				}

				$output .= "- {$relDesc}\n";
			}
			$output .= "\n";
		}

		if (!empty($modelInfo['scopes'])) {
			$output .= "**Query Scopes:**\n";
			foreach ($modelInfo['scopes'] as $scopeName => $scope) {
				$params = !empty($scope['parameters']) ? '(' . implode(', ', array_slice($scope['parameters'], 1)) . ')' : '()';
				$output .= "- `{$scopeName}()`{$params}\n";
			}
			$output .= "\n";
		}

		if (!empty($modelInfo['customMethods'])) {
			$output .= "**Custom Methods:**\n";
			foreach ($modelInfo['customMethods'] as $methodName => $method) {
				$params = !empty($method['parameters']) ? '(' . implode(', ', $method['parameters']) . ')' : '()';
				$output .= "- `{$methodName}()`{$params}\n";
			}
			$output .= "\n";
		}

		if (!empty($modelInfo['accessorsMutators']['accessors']) || !empty($modelInfo['accessorsMutators']['mutators'])) {
			$output .= "**Accessors & Mutators:**\n";
			if (!empty($modelInfo['accessorsMutators']['accessors'])) {
				$accessors = array_keys($modelInfo['accessorsMutators']['accessors']);
				$output .= "- Accessors: " . implode(', ', array_map(function ($attr) {
					return "`{$attr}`"; }, $accessors)) . "\n";
			}
			if (!empty($modelInfo['accessorsMutators']['mutators'])) {
				$mutators = array_keys($modelInfo['accessorsMutators']['mutators']);
				$output .= "- Mutators: " . implode(', ', array_map(function ($attr) {
					return "`{$attr}`"; }, $mutators)) . "\n";
			}
			$output .= "\n";
		}

		if (!empty($modelInfo['validationRules'])) {
			$output .= "**Validation Rules:**\n";
			foreach ($modelInfo['validationRules'] as $field => $rule) {
				$ruleStr = is_array($rule) ? implode('|', $rule) : $rule;
				$output .= "- `{$field}`: {$ruleStr}\n";
			}
			$output .= "\n";
		}

		return $output;
	}

	/**
	 * Get controller information
	 */
	protected function getControllerInfo(string $controllerName): ?array
	{
		try {
			$controllers = $this->getAvailableControllers();

			foreach ($controllers as $controller) {
				if (strtolower($controller['name']) === strtolower($controllerName)) {
					return $controller;
				}
			}
		} catch (\Exception $e) {
			Log::warning('Failed to get controller info', [
				'controller' => $controllerName,
				'error' => $e->getMessage(),
			]);
		}

		return null;
	}

	/**
	 * Format controller information for LLM
	 */
	protected function formatControllerInfo(array $controllerInfo): string
	{
		$output = "### Controller: {$controllerInfo['name']}\n\n";
		$output .= "**Full class:** `{$controllerInfo['fullName']}`\n\n";

		if (!empty($controllerInfo['methods'])) {
			$output .= "**Methods:** " . implode(', ', $controllerInfo['methods']) . "\n\n";
		}

		return $output;
	}

	/**
	 * Find relevant files based on query
	 */
	protected function findRelevantFiles(string $query): array
	{
		$files = [];
		$queryLower = strtolower($query);

		// Search in models
		try {
			$models = $this->getAvailableModels();
			foreach ($models as $modelName => $fullClassName) {
				if (strpos(strtolower($modelName), $queryLower) !== false) {
					$reflection = new \ReflectionClass($fullClassName);
					$filePath = $reflection->getFileName();
					if ($filePath) {
						$files[] = $filePath;
					}
				}
			}
		} catch (\Exception $e) {
			// Continue
		}

		return array_slice($files, 0, $this->maxCodebaseFiles);
	}

	/**
	 * Extract context from a file
	 */
	protected function extractFileContext(string $filePath, string $query): ?string
	{
		if (!file_exists($filePath) || !is_readable($filePath)) {
			return null;
		}

		try {
			$content = file_get_contents($filePath);
			$fileName = basename($filePath);

			// Extract class name
			$className = null;
			if (preg_match('/class\s+(\w+)/', $content, $matches)) {
				$className = $matches[1];
			}

			// Extract relevant methods (first 5 public methods)
			$methods = [];
			if (preg_match_all('/public\s+function\s+(\w+)\s*\([^)]*\)/', $content, $matches)) {
				$methods = array_slice($matches[1], 0, 5);
			}

			$output = "### File: {$fileName}\n\n";
			if ($className) {
				$output .= "**Class:** `{$className}`\n\n";
			}
			if (!empty($methods)) {
				$output .= "**Methods:** " . implode(', ', $methods) . "\n\n";
			}

			// Include first 30 lines as context
			$lines = explode("\n", $content);
			$relevantLines = array_slice($lines, 0, 30);
			$output .= "**Code snippet:**\n```php\n" . implode("\n", $relevantLines) . "\n```\n";

			return $output;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Get table indexes
	 */
	protected function getTableIndexes(string $tableName): array
	{
		$indexes = [];

		try {
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
		} catch (\Exception $e) {
			Log::warning('Failed to get table indexes', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
		}

		return array_values($indexes);
	}

	/**
	 * Get table constraints
	 */
	protected function getTableConstraints(string $tableName): array
	{
		$constraints = [
			'unique' => [],
			'check' => [],
			'defaults' => [],
		];

		try {
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
				// Check constraints might not be supported in older MySQL versions
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
					'auto_increment' => strpos($default->EXTRA, 'auto_increment') !== false,
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
	 * Get table information
	 */
	protected function getTableInfo(string $tableName): ?array
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
					// Try to get nullable info from information_schema
					$nullable = true; // Default to nullable
					try {
						$columnInfo = DB::selectOne(
							"SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
							[DB::connection()->getDatabaseName(), $tableName, $column]
						);
						if ($columnInfo) {
							$nullable = $columnInfo->IS_NULLABLE === 'YES';
						}
					} catch (\Exception $e) {
						// If we can't determine, default to nullable
					}

					$columnDetails[$column] = [
						'type' => $type,
						'nullable' => $nullable,
					];
				} catch (\Exception $e) {
					// Skip columns we can't get info for
					continue;
				}
			}

			// Get indexes and constraints
			$indexes = $this->getTableIndexes($tableName);
			$constraints = $this->getTableConstraints($tableName);

			$tableInfo = [
				'name' => $tableName,
				'columns' => $columnDetails,
				'indexes' => $indexes,
				'constraints' => $constraints,
			];

			// Only include sample data for query-specific tables (not in comprehensive overview)
			// This will be added when formatTableInfo is called with a flag or when used in query-specific context
			// For now, we'll add it conditionally based on whether it's requested

			return $tableInfo;
		} catch (\Exception $e) {
			Log::warning('Failed to get table info', [
				'table' => $tableName,
				'error' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Get all tables information
	 */
	protected function getAllTablesInfo(): array
	{
		try {
			// Use Laravel-native method to get table names
			$databaseName = DB::connection()->getDatabaseName();
			$allTables = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?", [$databaseName]);
			$tableNames = array_map(function ($table) {
				return $table->TABLE_NAME;
			}, $allTables);

			$tablesInfo = [];

			foreach ($tableNames as $table) {
				$tableInfo = $this->getTableInfo($table);
				if ($tableInfo) {
					$tablesInfo[] = $tableInfo;
				}
			}

			return $tablesInfo;
		} catch (\Exception $e) {
			Log::warning('Failed to get all tables info', [
				'error' => $e->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Get sample data from table (sanitized)
	 */
	protected function getTableSampleData(string $tableName, int $limit = 3): array
	{
		try {
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
			return [];
		}
	}

	/**
	 * Format table information for LLM
	 */
	protected function formatTableInfo(array $tableInfo): string
	{
		$output = "### Table: `{$tableInfo['name']}`\n\n";

		$output .= "**Columns:**\n";
		foreach ($tableInfo['columns'] as $column => $details) {
			$nullable = $details['nullable'] ? 'nullable' : 'not null';
			$columnDesc = "- `{$column}`: {$details['type']} ({$nullable})";

			// Add default value and auto-increment info
			if (isset($tableInfo['constraints']['defaults'][$column])) {
				$defaultInfo = $tableInfo['constraints']['defaults'][$column];
				if ($defaultInfo['auto_increment']) {
					$columnDesc .= " [AUTO_INCREMENT]";
				} elseif ($defaultInfo['default'] !== null) {
					$default = $defaultInfo['default'];
					if (is_string($default)) {
						$default = "'{$default}'";
					}
					$columnDesc .= " [default: {$default}]";
				}
			}

			$output .= $columnDesc . "\n";
		}
		$output .= "\n";

		// Display indexes
		if (!empty($tableInfo['indexes'])) {
			$output .= "**Indexes:**\n";
			foreach ($tableInfo['indexes'] as $index) {
				$indexType = $index['unique'] ? 'UNIQUE' : 'INDEX';
				$columns = implode(', ', array_map(function ($col) {
					return "`{$col}`"; }, $index['columns']));
				$output .= "- `{$index['name']}` ({$indexType}): {$columns}\n";
			}
			$output .= "\n";
		}

		// Display unique constraints
		if (!empty($tableInfo['constraints']['unique'])) {
			$output .= "**Unique Constraints:**\n";
			foreach ($tableInfo['constraints']['unique'] as $constraintName => $columns) {
				$columnList = implode(', ', array_map(function ($col) {
					return "`{$col}`"; }, $columns));
				$output .= "- `{$constraintName}`: {$columnList}\n";
			}
			$output .= "\n";
		}

		// Display check constraints
		if (!empty($tableInfo['constraints']['check'])) {
			$output .= "**Check Constraints:**\n";
			foreach ($tableInfo['constraints']['check'] as $constraint) {
				$output .= "- `{$constraint['name']}`: {$constraint['clause']}\n";
			}
			$output .= "\n";
		}

		// Display sample data (only for query-specific tables)
		if (!empty($tableInfo['sample_data'])) {
			$output .= "**Sample data (first " . count($tableInfo['sample_data']) . " rows):**\n";
			$output .= "```json\n" . json_encode($tableInfo['sample_data'], JSON_PRETTY_PRINT) . "\n```\n\n";
		}

		return $output;
	}

	/**
	 * Get recent log entries
	 */
	protected function getRecentLogEntries(int $limit = 10): array
	{
		$entries = [];

		try {
			$logPath = storage_path('logs/laravel.log');

			if (file_exists($logPath) && is_readable($logPath)) {
				$lines = file($logPath);
				$recentLines = array_slice($lines, -$limit * 20); // Get more lines to filter

				// Filter for error/exception entries
				$errorLines = [];
				foreach ($recentLines as $line) {
					if (
						stripos($line, 'ERROR') !== false ||
						stripos($line, 'Exception') !== false ||
						stripos($line, 'CRITICAL') !== false
					) {
						$errorLines[] = trim($line);
					}
				}

				$entries = array_slice($errorLines, -$limit);
			}
		} catch (\Exception $e) {
			Log::warning('Failed to read log entries', [
				'error' => $e->getMessage(),
			]);
		}

		return $entries;
	}

	/**
	 * Get available models
	 */
	protected function getAvailableModels(): array
	{
		try {
			if ($this->modelDiscovery === null) {
				$this->modelDiscovery = new ModelDiscovery();
			}
			return $this->modelDiscovery->getModelClasses();
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Get available controllers
	 */
	protected function getAvailableControllers(): array
	{
		try {
			if ($this->controllerDiscovery === null) {
				$this->controllerDiscovery = new ControllerDiscovery();
			}
			return $this->controllerDiscovery->getControllers();
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Validate if a model exists and suggest alternatives if not
	 * This is a helper method that can be used to provide better suggestions
	 */
	public function validateModelSuggestion(string $modelName): array
	{
		$result = [
			'exists' => false,
			'model' => null,
			'table' => null,
			'suggestion' => null,
			'message' => null,
		];

		try {
			$models = $this->getAvailableModels();

			// Check if model exists (case-insensitive)
			foreach ($models as $name => $fullClassName) {
				if (strtolower($name) === strtolower($modelName)) {
					$result['exists'] = true;
					$result['model'] = $name;
					$modelInfo = $this->extractModelInfo($fullClassName);
					if ($modelInfo) {
						$result['table'] = $modelInfo['table'];
					}
					return $result;
				}
			}

			// Model doesn't exist - check if table exists
			$tableName = $this->suggestTableName($modelName);
			if ($tableName && Schema::hasTable($tableName)) {
				$result['suggestion'] = "DB::table('{$tableName}')";
				$result['message'] = "Model `{$modelName}` does not exist, but table `{$tableName}` exists. Use `DB::table('{$tableName}')` instead.";
			} else {
				$result['message'] = "Model `{$modelName}` does not exist. Check the available models in the codebase context.";
			}
		} catch (\Exception $e) {
			$result['message'] = "Error validating model: " . $e->getMessage();
		}

		return $result;
	}

	/**
	 * Suggest table name from model name (pluralize, snake_case)
	 */
	protected function suggestTableName(string $modelName): ?string
	{
		// Convert PascalCase to snake_case and pluralize
		$snakeCase = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $modelName));

		// Simple pluralization (can be enhanced)
		if (substr($snakeCase, -1) === 'y') {
			$tableName = substr($snakeCase, 0, -1) . 'ies';
		} elseif (substr($snakeCase, -1) === 's') {
			$tableName = $snakeCase;
		} else {
			$tableName = $snakeCase . 's';
		}

		return $tableName;
	}

	/**
	 * Get valid foreign key values for a given foreign key constraint
	 */
	public function getValidForeignKeyValues(string $tableName, string $columnName, string $referencedTable, string $referencedColumn, int $limit = 10): array
	{
		try {
			$values = DB::table($referencedTable)
				->select($referencedColumn)
				->limit($limit)
				->pluck($referencedColumn)
				->toArray();

			return [
				'valid' => true,
				'values' => $values,
				'count' => count($values),
				'message' => "Valid {$referencedColumn} values from {$referencedTable}",
			];
		} catch (\Exception $e) {
			return [
				'valid' => false,
				'values' => [],
				'count' => 0,
				'message' => "Could not fetch valid values: " . $e->getMessage(),
			];
		}
	}
}