<?php

namespace Spiderwisp\LaravelOverlord\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
	/**
	 * The name and signature of the console command.
	 *
	 * @var string
	 */
	protected $signature = 'overlord:install 
							{--force : Force publish even if files exist}
							{--migrate : Run migrations without prompting}';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Install Laravel Overlord package (publish config, migrations, and assets)';

	/**
	 * Execute the console command.
	 *
	 * @return int
	 */
	public function handle()
	{
		$this->info('🚀 Installing Laravel Overlord...');
		$this->newLine();

		$force = $this->option('force');

		// Step 1: Publish configuration
		$this->info('📝 Publishing configuration...');
		$this->call('vendor:publish', [
			'--tag' => 'laravel-overlord-config',
			'--force' => $force,
		]);
		$this->info('   ✓ Configuration published to config/laravel-overlord.php');
		$this->newLine();

		// Step 2: Publish migrations
		$this->info('📦 Publishing migrations...');
		$this->call('vendor:publish', [
			'--tag' => 'laravel-overlord-migrations',
			'--force' => $force,
		]);
		$this->info('   ✓ Migrations published');
		$this->newLine();

		// Step 3: Publish pre-compiled assets (like Horizon)
		$this->info('🎨 Publishing assets...');
		$this->call('vendor:publish', [
			'--tag' => 'laravel-assets',
			'--force' => $force,
		]);
		$this->info('   ✓ Pre-compiled assets published to public/vendor/laravel-overlord/');
		$this->newLine();

		// Step 4: Run migrations
		$this->handleMigrations();

		// Step 5: Check and update catch-all route if needed
		$this->checkCatchAllRoute();

		// Step 6: Display next steps
		$this->displayNextSteps();

		$this->newLine();
		$this->info('✅ Laravel Overlord installation complete!');
		$this->newLine();

		return Command::SUCCESS;
	}


	/**
	 * Handle database migrations.
	 *
	 * @return void
	 */
	protected function handleMigrations()
	{
		if ($this->option('migrate')) {
			$this->info('🗄️  Running migrations...');
			$this->call('migrate', ['--force' => true]);
			$this->info('   ✓ Migrations completed');
			$this->newLine();
		} elseif ($this->confirm('Would you like to run the migrations now?', true)) {
			$this->info('🗄️  Running migrations...');
			$this->call('migrate', ['--force' => true]);
			$this->info('   ✓ Migrations completed');
			$this->newLine();
		} else {
			$this->line('   → Run migrations later: php artisan migrate');
			$this->newLine();
		}
	}

	/**
	 * Display next steps to the user.
	 *
	 * @return void
	 */
	protected function displayNextSteps()
	{
		$this->info('📋 Next Steps:');
		$this->newLine();

		$steps = [
			'Configure environment variables in your .env file (see below)',
			'Access the terminal at /overlord (or your configured route path)',
		];

		foreach ($steps as $index => $step) {
			$this->line("   " . ($index + 1) . ". {$step}");
		}

		$this->newLine();
		$this->displayEnvironmentVariables();
	}

	/**
	 * Display required and optional environment variables.
	 *
	 * @return void
	 */
	protected function displayEnvironmentVariables()
	{
		$this->info('📝 Environment Variables:');
		$this->newLine();

		$this->line('   <fg=yellow>Required (for queue features):</>');
		$this->line('   Add these to your .env file:');
		$this->newLine();
		$this->line('   <fg=cyan># Redis Configuration (Required for Horizon/Queue features)</>');
		$this->line('   <fg=green>REDIS_HOST</>=<value>  <fg=gray># e.g., "127.0.0.1" or "redis" (Docker service name)</>');
		$this->line('   <fg=green>REDIS_PASSWORD</>=null');
		$this->line('   <fg=green>REDIS_PORT</>=6379');
		$this->line('   <fg=green>REDIS_DB</>=0');
		$this->newLine();

		$this->line('   <fg=yellow>Optional (for AI Assistant):</>');
		$this->line('   Add these to enable AI features:');
		$this->newLine();
		$this->line('   <fg=cyan># AI Assistant Configuration</>');
		$this->line('   <fg=green>LARAVEL_OVERLORD_AI_ENABLED</>=true');
		$this->line('   <fg=green>LARAVEL_OVERLORD_API_KEY</>=your_api_key_here  <fg=gray># Get from laravel-overlord.com</>');
		$this->newLine();
		$this->line('   <fg=gray>After adding environment variables, run:</>');
		$this->line('   <fg=cyan>php artisan config:clear</>');
		$this->newLine();
	}

	/**
	 * Check and update catch-all route in routes/web.php to exclude overlord routes.
	 *
	 * @return void
	 */
	protected function checkCatchAllRoute()
	{
		$webRoutesPath = base_path('routes/web.php');

		if (!file_exists($webRoutesPath)) {
			return; // No web.php, skip
		}

		$content = file_get_contents($webRoutesPath);
		$defaultRoutePath = config('laravel-overlord.default_route_path', 'overlord');
		$routePrefix = config('laravel-overlord.route_prefix', 'admin/overlord');

		// Check if there's a catch-all route pattern
		// Look for Route::get('/{any}', ...) or similar patterns with where() clause
		$hasCatchAll = preg_match('/Route::get\([\'"]\/\{[^}]+\}[\'"]/', $content) ||
			preg_match('/Route::get\([\'"]\/\{any\}[\'"]/', $content) ||
			preg_match('/Route::get\([\'"]\/\{.*\}[\'"]/', $content);

		if (!$hasCatchAll) {
			return; // No catch-all route found, nothing to update
		}

		// Check if overlord routes are already excluded
		$excludesOverlord = preg_match('/\^(?!.*overlord|.*admin\/overlord)/', $content);

		if ($excludesOverlord) {
			$this->info('   ✓ Catch-all route already excludes overlord routes');
			return;
		}

		// Found catch-all route that needs updating
		$this->warn('   ⚠ Catch-all route detected in routes/web.php');
		$this->line("   → Need to exclude '{$defaultRoutePath}' and '{$routePrefix}' from catch-all");
		$this->newLine();

		if ($this->confirm('Would you like me to update it automatically?', true)) {
			$updated = $this->updateCatchAllRoute($webRoutesPath, $defaultRoutePath, $routePrefix, $content);

			if ($updated) {
				$this->info('   ✓ Updated catch-all route in routes/web.php');
			} else {
				$this->warn('   ⚠ Could not automatically update route. Please update manually.');
				$this->displayManualRouteUpdateInstructions($defaultRoutePath, $routePrefix);
			}
		} else {
			$this->displayManualRouteUpdateInstructions($defaultRoutePath, $routePrefix);
		}

		$this->newLine();
	}

	/**
	 * Update the catch-all route to exclude overlord routes.
	 *
	 * @param string $path
	 * @param string $defaultRoute
	 * @param string $routePrefix
	 * @param string $content
	 * @return bool
	 */
	protected function updateCatchAllRoute($path, $defaultRoute, $routePrefix, $content)
	{
		// Pattern to match: ->where('any', '^(?!...).*')
		// We need to find the where clause and update the regex
		$pattern = '/->where\([\'"]any[\'"],\s*[\'"]([^\'"]+)[\'"]\)/';

		$updated = preg_replace_callback($pattern, function ($matches) use ($defaultRoute, $routePrefix) {
			$existingPattern = $matches[1];

			// Extract existing exclusions from pattern like: ^(?!exclusion1|exclusion2).*
			if (preg_match('/\^\(?!([^)]+)\)\.\*/', $existingPattern, $exclusionsMatch)) {
				$existingExclusions = explode('|', $exclusionsMatch[1]);
			} else {
				$existingExclusions = [];
			}

			// Add overlord exclusions
			$newExclusions = array_unique(array_merge($existingExclusions, [$defaultRoute, $routePrefix]));
			$exclusionsStr = implode('|', $newExclusions);

			return "->where('any', '^(?!{$exclusionsStr}).*')";
		}, $content);

		// If no where clause found, try to add one
		if ($updated === $content) {
			// Look for Route::get('/{any}', ...) without where clause
			$pattern2 = '/(Route::get\([\'"]\/\{[^}]+\}[\'"],\s*[^)]+\))(?!\s*->where)/';
			$updated = preg_replace($pattern2, "$1->where('any', '^(?!{$defaultRoute}|{$routePrefix}).*')", $content);
		}

		if ($updated && $updated !== $content) {
			// Create backup
			file_put_contents($path . '.backup', $content);
			return file_put_contents($path, $updated) !== false;
		}

		return false;
	}

	/**
	 * Display manual instructions for updating the catch-all route.
	 *
	 * @param string $defaultRoute
	 * @param string $routePrefix
	 * @return void
	 */
	protected function displayManualRouteUpdateInstructions($defaultRoute, $routePrefix)
	{
		$this->newLine();
		$this->line('   <fg=yellow>Manual Update Required:</>');
		$this->line('   Update your catch-all route in routes/web.php to exclude overlord routes:');
		$this->newLine();
		$this->line('   <fg=cyan>Before:</>');
		$this->line('   <fg=gray>Route::get(\'/{any}\', function () {</>');
		$this->line('   <fg=gray>    return view(\'app\');</>');
		$this->line('   <fg=gray>})->where(\'any\', \'^(?!admin/tinker).*\');</>');
		$this->newLine();
		$this->line('   <fg=cyan>After:</>');
		$this->line('   <fg=gray>Route::get(\'/{any}\', function () {</>');
		$this->line('   <fg=gray>    return view(\'app\');</>');
		$this->line("   <fg=gray>})->where('any', '^(?!admin/tinker|{$defaultRoute}|{$routePrefix}).*');</>");
		$this->newLine();
	}
}