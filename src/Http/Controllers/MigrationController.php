<?php

namespace Spiderwisp\LaravelOverlord\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Spiderwisp\LaravelOverlord\Services\MigrationService;

class MigrationController extends Controller
{
	protected MigrationService $migrationService;

	public function __construct()
	{
		$this->migrationService = new MigrationService();
	}

	/**
	 * Get list of all migrations with status
	 */
	public function index(Request $request)
	{
		try {
			$migrations = $this->migrationService->getAllMigrations();

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'migrations' => $migrations,
				],
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to get migrations', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get migrations: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get details for a specific migration
	 */
	public function show(Request $request, string $migration)
	{
		try {
			$details = $this->migrationService->getMigrationDetails($migration);

			if (!$details) {
				return response()->json([
					'success' => false,
					'status_code' => 'ERROR',
					'errors' => ['Migration not found: ' . $migration],
					'result' => (object) [],
				], 404);
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => $details,
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to get migration details', [
				'error' => $e->getMessage(),
				'migration' => $migration,
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get migration details: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Run migrations
	 */
	public function run(Request $request)
	{
		try {
			$request->validate([
				'step' => 'sometimes|integer|min:1',
				'pretend' => 'sometimes|boolean',
				'migration' => 'sometimes|string',
			]);

			$output = new BufferedOutput();
			$options = [];

			if ($request->has('step')) {
				$options['--step'] = $request->input('step');
			}

			if ($request->input('pretend', false)) {
				$options['--pretend'] = true;
			}

			// If a specific migration is requested, run only that one (if it's the next pending)
			if ($request->has('migration') && $request->input('migration')) {
				$migrationName = $request->input('migration');

				// Get all pending migrations
				$allMigrations = $this->migrationService->getAllMigrations();
				$pendingMigrations = array_filter($allMigrations, fn($m) => $m['status'] === 'pending');
				$pendingMigrations = array_values($pendingMigrations);

				if (empty($pendingMigrations)) {
					return response()->json([
						'success' => false,
						'status_code' => 'ERROR',
						'errors' => ['No pending migrations found'],
						'result' => (object) [],
					], 404);
				}

				// Check if the requested migration is the first pending one
				$firstPending = $pendingMigrations[0];
				$isTargetFirst = ($firstPending['full_name'] === $migrationName || $firstPending['name'] === $migrationName);

				if (!$isTargetFirst) {
					// User is trying to run a migration that's not the next one
					// Check if the requested migration exists in pending list
					$targetFound = false;
					foreach ($pendingMigrations as $migration) {
						if ($migration['full_name'] === $migrationName || $migration['name'] === $migrationName) {
							$targetFound = true;
							break;
						}
					}

					if ($targetFound) {
						return response()->json([
							'success' => false,
							'status_code' => 'ERROR',
							'errors' => ['Cannot run this migration: migrations must run in order. Please run "' . $firstPending['name'] . '" first.'],
							'result' => (object) [],
						], 400);
					} else {
						return response()->json([
							'success' => false,
							'status_code' => 'ERROR',
							'errors' => ['Migration not found or already run: ' . $migrationName],
							'result' => (object) [],
						], 404);
					}
				}

				// It's the first pending migration, so use --step=1 to run only this one
				$options['--step'] = 1;
			}

			$exitCode = Artisan::call('migrate', $options, $output);
			$outputContent = $output->fetch();

			return response()->json([
				'success' => $exitCode === 0,
				'status_code' => $exitCode === 0 ? 'SUCCESS' : 'ERROR',
				'errors' => $exitCode !== 0 ? ['Migration failed'] : [],
				'result' => (object) [
					'output' => $outputContent,
					'exitCode' => $exitCode,
				],
			], $exitCode === 0 ? 200 : 400);
		} catch (\Throwable $e) {
			Log::error('Failed to run migrations', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to run migrations: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Rollback migrations
	 */
	public function rollback(Request $request)
	{
		try {
			$request->validate([
				'step' => 'sometimes|integer|min:1',
				'batch' => 'sometimes|integer|min:1',
			]);

			// Get migrations that will be rolled back BEFORE rolling back
			$rolledBackMigrations = [];
			$targetBatch = null;

			if ($request->has('batch')) {
				$targetBatch = $request->input('batch');
				// Get migrations in this batch
				$rolledBackMigrations = DB::table('migrations')
					->where('batch', $targetBatch)
					->pluck('migration')
					->toArray();
			} else {
				// Get the highest batch number (last batch)
				$lastBatch = DB::table('migrations')
					->max('batch');

				if ($lastBatch) {
					$targetBatch = $lastBatch;
					$rolledBackMigrations = DB::table('migrations')
						->where('batch', $lastBatch)
						->pluck('migration')
						->toArray();
				}
			}

			$output = new BufferedOutput();
			$options = [];

			if ($request->has('step')) {
				$options['--step'] = $request->input('step');
			}

			if ($request->has('batch')) {
				// Rollback specific batch
				$batch = $request->input('batch');
				$exitCode = Artisan::call('migrate:rollback', array_merge($options, ['--batch' => $batch]), $output);
			} else {
				// Rollback last batch by default (or last step if --step is specified)
				$exitCode = Artisan::call('migrate:rollback', $options, $output);
			}

			$outputContent = $output->fetch();

			// Check if the error is about a missing table
			$isTableNotFoundError = false;
			$tableName = null;
			if ($exitCode !== 0 && !empty($outputContent)) {
				// Check for "Table ... doesn't exist" errors in output
				// MySQL error format: "Base table or view not found: 1146 Table 'database.table_name' doesn't exist"
				if (preg_match("/Table ['\"]?[^'\"]*\.?([^'\"]+)['\"]? doesn't exist/i", $outputContent, $matches)) {
					$isTableNotFoundError = true;
					$tableName = $matches[1] ?? null;
				} elseif (preg_match("/Base table or view not found.*Table ['\"]?[^'\"]*\.?([^'\"]+)['\"]?/i", $outputContent, $matches)) {
					$isTableNotFoundError = true;
					$tableName = $matches[1] ?? null;
				}
			}


			// Build error message
			$errorMessage = 'Rollback failed';
			if ($exitCode !== 0 && !empty($outputContent)) {
				// Always show the actual error output first
				$errorMessage = 'Rollback failed: ' . trim($outputContent);

				// If it's a table not found error, add additional context
				if ($isTableNotFoundError && $tableName) {
					$errorMessage .= "\n\nNote: The error indicates the table '{$tableName}' was not found during rollback. This may happen if:";
					$errorMessage .= "\n- A later migration dropped the table, but an earlier migration is trying to modify it";
					$errorMessage .= "\n- The migration order needs to be fixed";
					$errorMessage .= "\n- The table was manually dropped";
				}
			}

			return response()->json([
				'success' => $exitCode === 0,
				'status_code' => $exitCode === 0 ? 'SUCCESS' : 'ERROR',
				'errors' => $exitCode !== 0 ? [$errorMessage] : [],
				'result' => (object) [
					'output' => $outputContent,
					'exitCode' => $exitCode,
					'rolled_back_migrations' => $rolledBackMigrations,
					'batch' => $targetBatch,
					'table_not_found_error' => $isTableNotFoundError,
					'missing_table' => $tableName,
				],
			], $exitCode === 0 ? 200 : 400);
		} catch (\Throwable $e) {
			Log::error('Failed to rollback migrations', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			// Check if it's a table not found error
			$isTableNotFoundError = false;
			$tableName = null;
			$errorMessage = $e->getMessage();

			if (preg_match("/Base table or view not found.*Table ['\"]([^'\"]+)['\"]/i", $e->getMessage(), $matches)) {
				$isTableNotFoundError = true;
				$tableName = $matches[1] ?? null;
				$errorMessage = "Rollback failed: The table '{$tableName}' does not exist. This may happen if a later migration dropped the table, but an earlier migration is trying to modify it. You may need to manually fix the migration order or skip this rollback.";
			}

			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => [$errorMessage],
				'result' => (object) [
					'table_not_found_error' => $isTableNotFoundError,
					'missing_table' => $tableName,
				],
			], 400);
		}
	}

	/**
	 * Preview pending migrations that would be run
	 */
	public function previewRun(Request $request)
	{
		try {
			$migrationName = $request->input('migration');

			// Get all pending migrations
			$allMigrations = $this->migrationService->getAllMigrations();
			$pendingMigrations = array_filter($allMigrations, fn($m) => $m['status'] === 'pending');
			$pendingMigrations = array_values($pendingMigrations);

			// If a specific migration is requested, filter to show only that one and any before it
			if ($migrationName) {
				$targetIndex = null;
				foreach ($pendingMigrations as $index => $migration) {
					if ($migration['full_name'] === $migrationName || $migration['name'] === $migrationName) {
						$targetIndex = $index;
						break;
					}
				}

				if ($targetIndex !== null) {
					// Only show migrations up to and including the target
					$pendingMigrations = array_slice($pendingMigrations, 0, $targetIndex + 1);
				} else {
					// Migration not found or already run, show all pending
				}
			}

			// Build preview with filtered migrations
			$preview = [
				'pending_migrations' => array_map(function ($m) {
					return [
						'name' => $m['name'],
						'full_name' => $m['full_name'],
						'tables' => $m['tables'] ?? [],
					];
				}, $pendingMigrations),
				'total_pending' => count($pendingMigrations),
				'tables_to_create' => [],
				'tables_to_modify' => [],
			];

			// Extract tables
			foreach ($pendingMigrations as $migration) {
				if (isset($migration['tables']) && is_array($migration['tables'])) {
					foreach ($migration['tables'] as $table) {
						// Simple heuristic: if migration name contains "create", it's creating a table
						if (stripos($migration['name'], 'create') !== false && !in_array($table, $preview['tables_to_create'])) {
							$preview['tables_to_create'][] = $table;
						} elseif (!in_array($table, $preview['tables_to_modify'])) {
							$preview['tables_to_modify'][] = $table;
						}
					}
				}
			}

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => $preview,
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to get run preview', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get run preview: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Preview migrations that would be rolled back
	 */
	public function previewRollback(Request $request)
	{
		try {
			$request->validate([
				'batch' => 'sometimes|integer|min:1',
				'step' => 'sometimes|integer|min:1',
			]);

			$batch = $request->has('batch') ? $request->input('batch') : null;
			$step = $request->has('step') ? $request->input('step') : null;

			$preview = $this->migrationService->getRollbackPreview($batch, $step);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => $preview,
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to get rollback preview', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get rollback preview: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Get migration status
	 */
	public function status(Request $request)
	{
		try {
			$output = new BufferedOutput();
			Artisan::call('migrate:status', [], $output);
			$outputContent = $output->fetch();

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'output' => $outputContent,
				],
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to get migration status', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to get migration status: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}


	/**
	 * Generate migration code using AI
	 */
	public function generate(Request $request)
	{
		try {
			$request->validate([
				'specs' => 'required|array',
				'specs.type' => 'required|string|in:create,modify,drop',
				'specs.table_name' => 'required|string|max:255',
				'specs.columns' => 'sometimes|array',
				'specs.indexes' => 'sometimes|array',
				'specs.foreign_keys' => 'sometimes|array',
				'user_prompt' => 'sometimes|string|max:2000',
			]);

			$specs = $request->input('specs');
			$userPrompt = $request->input('user_prompt');

			$code = $this->migrationService->generateMigrationWithAI($specs, $userPrompt);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'code' => $code,
				],
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to generate migration', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to generate migration: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}

	/**
	 * Create and save migration file
	 */
	public function create(Request $request)
	{
		try {
			$request->validate([
				'migration_name' => 'required|string|max:255',
				'code' => 'required|string',
			]);

			$migrationName = $request->input('migration_name');
			$code = $request->input('code');

			$filepath = $this->migrationService->createMigrationFile($migrationName, $code);
			$filename = basename($filepath);

			return response()->json([
				'success' => true,
				'status_code' => 'SUCCESS',
				'errors' => [],
				'result' => (object) [
					'filepath' => $filepath,
					'filename' => $filename,
				],
			], 200);
		} catch (\Throwable $e) {
			Log::error('Failed to create migration file', [
				'error' => $e->getMessage(),
			]);
			return response()->json([
				'success' => false,
				'status_code' => 'ERROR',
				'errors' => ['Failed to create migration file: ' . $e->getMessage()],
				'result' => (object) [],
			], 400);
		}
	}
}