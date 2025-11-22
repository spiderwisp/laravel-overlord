<?php

namespace Spiderwisp\LaravelOverlord\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Spiderwisp\LaravelOverlord\Services\PhpstanService;

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

		// Step 6: Check PHPStan installation (optional)
		$this->checkPhpstanInstallation();

		// Step 7: Display next steps
		$this->displayNextSteps();

		$this->newLine();
		$this->info('✅ Laravel Overlord installation complete!');
		$this->newLine();

		// Display terminal URL if default route is enabled
		if (config('laravel-overlord.default_route_enabled', true)) {
			$appUrl = config('app.url', 'http://localhost');
			$routePath = config('laravel-overlord.default_route_path', 'overlord');
			$terminalUrl = rtrim($appUrl, '/') . '/' . ltrim($routePath, '/');
			
			$this->info('🌐 Access your terminal at:');
			$this->line('   <fg=cyan>' . $terminalUrl . '</>');
			$this->newLine();
		}

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
		];
		
		// Add terminal access step if default route is enabled
		if (config('laravel-overlord.default_route_enabled', true)) {
			$routePath = config('laravel-overlord.default_route_path', 'overlord');
			$steps[] = "Access the terminal at /{$routePath}";
		} else {
			$steps[] = 'Integrate the terminal component into your own pages (default route is disabled)';
		}

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
		$this->line('   <fg=gray>})->where(\'any\', \'^(?!api).*\');</>');
		$this->newLine();
		$this->line('   <fg=cyan>After:</>');
		$this->line('   <fg=gray>Route::get(\'/{any}\', function () {</>');
		$this->line('   <fg=gray>    return view(\'app\');</>');
		$this->line("   <fg=gray>})->where('any', '^(?!api|{$defaultRoute}|{$routePrefix}).*');</>");
		$this->newLine();
	}

	/**
	 * Check if PHPStan and Larastan are installed and offer to install them if not.
	 *
	 * @return void
	 */
	protected function checkPhpstanInstallation()
	{
		try {
			$phpstanService = app(PhpstanService::class);
			$phpstanPath = $phpstanService->findPhpstanPath();
			$larastanInstalled = $this->isLarastanInstalled();

			if ($phpstanPath && $larastanInstalled) {
				$this->info('   ✓ PHPStan and Larastan are installed');
				$this->line("   → PHPStan found at: {$phpstanPath}");
				$this->newLine();
			} elseif ($phpstanPath && !$larastanInstalled) {
				$this->info('   ✓ PHPStan is installed');
				$this->line("   → Found at: {$phpstanPath}");
				$this->warn('   ⚠ Larastan is not installed (recommended)');
				$this->line('   → Larastan reduces false positives for Laravel magic methods and facades');
				$this->newLine();

				if ($this->confirm('Would you like to install Larastan now?', true)) {
					$this->installLarastan();
				} else {
					$this->line('   → You can install Larastan later with:');
					$this->line('   <fg=cyan>   composer require --dev larastan/larastan</>');
					$this->newLine();
				}
			} elseif (!$phpstanPath) {
				$this->warn('   ⚠ PHPStan is not installed (optional)');
				$this->line('   → PHPStan static analysis is available in the terminal');
				$this->newLine();

				if ($this->confirm('Would you like to install PHPStan and Larastan now?', false)) {
					$this->installPhpstanAndLarastan();
				} else {
					$this->line('   → You can install PHPStan later with:');
					$this->line('   <fg=cyan>   composer require --dev phpstan/phpstan larastan/larastan</>');
					$this->newLine();
				}
			}
		} catch (\Exception $e) {
			// Silently fail - PHPStan check is optional
			$this->line('   → PHPStan check skipped');
			$this->newLine();
		}
	}

	/**
	 * Check if Larastan is installed.
	 *
	 * @return bool
	 */
	protected function isLarastanInstalled(): bool
	{
		$larastanPath = base_path('vendor/larastan/larastan');
		return file_exists($larastanPath) && is_dir($larastanPath);
	}

	/**
	 * Install PHPStan and Larastan together.
	 *
	 * @return void
	 */
	protected function installPhpstanAndLarastan()
	{
		$this->info('   📦 Installing PHPStan and Larastan...');
		
		try {
			$composerCommand = $this->findComposer();
			$commandParts = explode(' ', $composerCommand);
			
			$command = array_merge($commandParts, [
				'require',
				'--dev',
				'phpstan/phpstan',
				'larastan/larastan',
			]);
			
			$process = new Process($command, base_path());
			$process->setTimeout(300); // 5 minutes timeout
			$process->run(function ($type, $buffer) {
				if (Process::ERR === $type) {
					$this->error($buffer);
				} else {
					$this->line($buffer);
				}
			});

			if ($process->isSuccessful()) {
				$this->info('   ✓ PHPStan and Larastan installed successfully');
				$this->createPhpstanConfig();
			} else {
				$this->warn('   ⚠ Failed to install PHPStan/Larastan');
				$this->line('   → You can install it manually later with:');
				$this->line('   <fg=cyan>   composer require --dev phpstan/phpstan larastan/larastan</>');
			}
		} catch (\Exception $e) {
			$this->warn('   ⚠ Failed to install PHPStan/Larastan: ' . $e->getMessage());
			$this->line('   → You can install it manually later with:');
			$this->line('   <fg=cyan>   composer require --dev phpstan/phpstan larastan/larastan</>');
		}
		$this->newLine();
	}

	/**
	 * Install Larastan only (when PHPStan is already installed).
	 *
	 * @return void
	 */
	protected function installLarastan()
	{
		$this->info('   📦 Installing Larastan...');
		
		try {
			$composerCommand = $this->findComposer();
			$commandParts = explode(' ', $composerCommand);
			
			$command = array_merge($commandParts, [
				'require',
				'--dev',
				'larastan/larastan',
			]);
			
			$process = new Process($command, base_path());
			$process->setTimeout(300); // 5 minutes timeout
			$process->run(function ($type, $buffer) {
				if (Process::ERR === $type) {
					$this->error($buffer);
				} else {
					$this->line($buffer);
				}
			});

			if ($process->isSuccessful()) {
				$this->info('   ✓ Larastan installed successfully');
				
				// Update phpstan.neon if it exists, or create it if it doesn't
				$this->createPhpstanConfig();
			} else {
				$this->warn('   ⚠ Failed to install Larastan');
				$this->line('   → You can install it manually later with:');
				$this->line('   <fg=cyan>   composer require --dev larastan/larastan</>');
			}
		} catch (\Exception $e) {
			$this->warn('   ⚠ Failed to install Larastan: ' . $e->getMessage());
			$this->line('   → You can install it manually later with:');
			$this->line('   <fg=cyan>   composer require --dev larastan/larastan</>');
		}
		$this->newLine();
	}

	/**
	 * Find the Composer executable.
	 *
	 * @return string
	 */
	protected function findComposer()
	{
		// Check for composer.phar in project root
		$composerPhar = base_path('composer.phar');
		if (file_exists($composerPhar)) {
			return PHP_BINARY . ' ' . $composerPhar;
		}

		// Check for global composer (only on Unix-like systems)
		if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
			try {
				$process = new Process(['which', 'composer'], base_path());
				$process->setTimeout(5);
				$process->run();
				if ($process->isSuccessful()) {
					$path = trim($process->getOutput());
					if (!empty($path) && file_exists($path)) {
						return $path;
					}
				}
			} catch (\Exception $e) {
				// Silently continue to fallback
			}
		}

		// Fallback to 'composer' command (assumes it's in PATH)
		return 'composer';
	}

	/**
	 * Create or update PHPStan configuration file with Larastan support.
	 *
	 * @return void
	 */
	protected function createPhpstanConfig()
	{
		$configPath = base_path('phpstan.neon');
		$configExists = file_exists($configPath);
		
		// If config exists, check if Larastan is already included
		if ($configExists) {
			$existingContent = file_get_contents($configPath);
			
			// Check for invalid parameters that cause PHPStan to fail
			$hasInvalidParams = false;
			if (strpos($existingContent, 'checkMissingIterableValueType') !== false ||
				strpos($existingContent, 'checkGenericClassInNonGenericObjectType') !== false) {
				$hasInvalidParams = true;
			}
			
			if ($hasInvalidParams) {
				$this->warn('   ⚠ phpstan.neon contains invalid parameters that will cause PHPStan to fail');
				if ($this->confirm('   Would you like to remove the invalid parameters?', true)) {
					$this->fixInvalidPhpstanConfig($configPath, $existingContent);
				} else {
					$this->line('   → Please manually remove these invalid parameters:');
					$this->line('   →   - checkMissingIterableValueType');
					$this->line('   →   - checkGenericClassInNonGenericObjectType');
				}
			}
			
			// Check if Larastan extension is already included
			if (strpos($existingContent, 'larastan/larastan/extension.neon') !== false) {
				$this->line('   → phpstan.neon already includes Larastan configuration');
				return;
			}
			
			// Larastan not included - offer to update
			$this->warn('   ⚠ phpstan.neon exists but doesn\'t include Larastan');
			if ($this->confirm('   Would you like to add Larastan configuration to your existing phpstan.neon?', true)) {
				$this->updatePhpstanConfigWithLarastan($configPath, file_get_contents($configPath));
			} else {
				$this->line('   → You can manually add Larastan by including: vendor/larastan/larastan/extension.neon');
			}
			return;
		}

		// Create new config file
		$configContent = <<<'NEON'
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    # Paths can be specified here or via command line
    # Command-line paths will override these
    paths:
        - app

    # Laravel-specific settings
    # Note: Level can be overridden via command line (--level=X)
    level: 5
    
    # Laravel-specific extensions
    bootstrapFiles:
        - vendor/larastan/larastan/bootstrap.php
    
    # Note: Larastan handles most Laravel magic methods automatically
    # You can add ignoreErrors for specific cases if needed
NEON;

		try {
			file_put_contents($configPath, $configContent);
			$this->info('   ✓ Created phpstan.neon configuration file with Larastan support');
		} catch (\Exception $e) {
			$this->warn('   ⚠ Failed to create phpstan.neon: ' . $e->getMessage());
			$this->line('   → You can create it manually with Larastan configuration');
		}
	}

	/**
	 * Update existing PHPStan config to include Larastan.
	 *
	 * @param string $configPath
	 * @param string $existingContent
	 * @return void
	 */
	protected function updatePhpstanConfigWithLarastan($configPath, $existingContent)
	{
		try {
			// Create backup
			file_put_contents($configPath . '.backup', $existingContent);
			
			// Try to add Larastan include at the top
			$updatedContent = $existingContent;
			
			// Check if there's already an includes section
			if (preg_match('/^includes:/m', $existingContent)) {
				// Add to existing includes
				if (strpos($existingContent, 'vendor/larastan/larastan/extension.neon') === false) {
					$updatedContent = preg_replace(
						'/^(includes:)/m',
						"$1\n    - vendor/larastan/larastan/extension.neon",
						$existingContent,
						1
					);
				}
			} else {
				// Add includes section at the beginning
				$updatedContent = "includes:\n    - vendor/larastan/larastan/extension.neon\n\n" . $existingContent;
			}
			
			// Add bootstrapFiles if parameters section exists and bootstrapFiles doesn't
			if (preg_match('/^parameters:/m', $updatedContent) && 
				strpos($updatedContent, 'bootstrapFiles:') === false) {
				$updatedContent = preg_replace(
					'/^(parameters:)/m',
					"$1\n    bootstrapFiles:\n        - vendor/larastan/larastan/bootstrap.php",
					$updatedContent,
					1
				);
			}
			
			file_put_contents($configPath, $updatedContent);
			$this->info('   ✓ Updated phpstan.neon to include Larastan configuration');
			$this->line('   → Backup saved to phpstan.neon.backup');
		} catch (\Exception $e) {
			$this->warn('   ⚠ Failed to update phpstan.neon: ' . $e->getMessage());
			$this->line('   → You can manually add Larastan configuration');
		}
	}

	/**
	 * Fix invalid parameters in PHPStan config file.
	 *
	 * @param string $configPath
	 * @param string $existingContent
	 * @return void
	 */
	protected function fixInvalidPhpstanConfig($configPath, $existingContent)
	{
		try {
			// Create backup
			file_put_contents($configPath . '.backup', $existingContent);
			
			// Remove invalid parameters
			$fixedContent = $existingContent;
			
			// Remove checkMissingIterableValueType lines
			$fixedContent = preg_replace('/^\s*checkMissingIterableValueType:\s*.*$/m', '', $fixedContent);
			
			// Remove checkGenericClassInNonGenericObjectType lines
			$fixedContent = preg_replace('/^\s*checkGenericClassInNonGenericObjectType:\s*.*$/m', '', $fixedContent);
			
			// Clean up multiple blank lines
			$fixedContent = preg_replace('/\n{3,}/', "\n\n", $fixedContent);
			
			file_put_contents($configPath, $fixedContent);
			$this->info('   ✓ Removed invalid parameters from phpstan.neon');
			$this->line('   → Backup saved to phpstan.neon.backup');
		} catch (\Exception $e) {
			$this->warn('   ⚠ Failed to fix phpstan.neon: ' . $e->getMessage());
			$this->line('   → Please manually remove the invalid parameters');
		}
	}
}