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

		// Step 3: Publish assets
		$this->info('🎨 Publishing assets...');
		$this->call('vendor:publish', [
			'--tag' => 'laravel-overlord-assets',
			'--force' => $force,
		]);
		$this->info('   ✓ Vue components published to resources/js/vendor/laravel-overlord/');
		$this->newLine();

		// Step 4: Check Node.js/npm
		$this->checkNodeJs();

		// Step 5: Check Vue 3
		$this->checkVue3();

		// Step 6: Run migrations
		$this->handleMigrations();

		// Step 7: Display next steps
		$this->displayNextSteps();

		$this->newLine();
		$this->info('✅ Laravel Overlord installation complete!');
		$this->newLine();

		return Command::SUCCESS;
	}

	/**
	 * Check if Node.js and npm are installed.
	 *
	 * @return void
	 */
	protected function checkNodeJs()
	{
		$this->info('🔍 Checking prerequisites...');

		$nodeVersion = $this->getCommandVersion('node');
		$npmVersion = $this->getCommandVersion('npm');

		if ($nodeVersion && $npmVersion) {
			$this->info("   ✓ Node.js {$nodeVersion} found");
			$this->info("   ✓ npm {$npmVersion} found");
		} else {
			$this->warn('   ⚠ Node.js and/or npm not found');
			$this->displayNodeJsInstructions();
		}

		$this->newLine();
	}

	/**
	 * Check if Vue 3 is installed in package.json.
	 *
	 * @return void
	 */
	protected function checkVue3()
	{
		$packageJsonPath = base_path('package.json');

		if (!file_exists($packageJsonPath)) {
			$this->warn('   ⚠ package.json not found');
			$this->line('   → Create package.json and install Vue 3: npm install vue@^3.3.4');
			$this->newLine();
			return;
		}

		$packageJson = json_decode(file_get_contents($packageJsonPath), true);

		if (!$packageJson) {
			$this->warn('   ⚠ Could not parse package.json');
			$this->newLine();
			return;
		}

		$hasVue3 = false;
		$vueVersion = null;

		// Check dependencies
		if (isset($packageJson['dependencies']['vue'])) {
			$vueVersion = $packageJson['dependencies']['vue'];
			if (preg_match('/\^?3\./', $vueVersion)) {
				$hasVue3 = true;
			}
		}

		// Check devDependencies
		if (!$hasVue3 && isset($packageJson['devDependencies']['vue'])) {
			$vueVersion = $packageJson['devDependencies']['vue'];
			if (preg_match('/\^?3\./', $vueVersion)) {
				$hasVue3 = true;
			}
		}

		if ($hasVue3) {
			$this->info("   ✓ Vue 3 found ({$vueVersion})");
		} else {
			$this->warn('   ⚠ Vue 3 not found in package.json');
			$this->line('   → Install Vue 3: npm install vue@^3.3.4 --save');
		}

		$this->newLine();
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
			'Add the DeveloperTerminal component to your layout',
			'Build frontend assets: npm run build (or npm run dev)',
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
	 * Get version of a command if available.
	 *
	 * @param string $command
	 * @return string|null
	 */
	protected function getCommandVersion($command)
	{
		$version = @shell_exec("{$command} -v 2>&1");

		if ($version && strpos($version, 'not found') === false) {
			return trim($version);
		}

		return null;
	}

	/**
	 * Display Node.js installation instructions based on OS.
	 *
	 * @return void
	 */
	protected function displayNodeJsInstructions()
	{
		$this->newLine();
		$this->line('   Installation instructions:');
		$this->newLine();

		$os = PHP_OS_FAMILY;

		if ($os === 'Linux') {
			$this->line('   Ubuntu/Debian:');
			$this->line('     curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -');
			$this->line('     sudo apt-get install -y nodejs');
			$this->newLine();
			$this->line('   Or visit: https://nodejs.org/');
		} elseif ($os === 'Darwin') {
			$this->line('   macOS (using Homebrew):');
			$this->line('     brew install node');
			$this->newLine();
			$this->line('   Or visit: https://nodejs.org/');
		} else {
			$this->line('   Visit: https://nodejs.org/ to download and install Node.js');
		}
	}
}