<?php

namespace Spiderwisp\LaravelOverlord;

use Illuminate\Support\ServiceProvider;

class LaravelOverlordServiceProvider extends ServiceProvider
{
	/**
	 * Indicates if loading of the provider is deferred.
	 * When deferred, the provider only loads when actually needed.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Register any application services.
	 */
	public function register(): void
	{
		$this->mergeConfigFrom(
			__DIR__.'/../config/laravel-overlord.php',
			'laravel-overlord'
		);
	}

	/**
	 * Bootstrap any application services.
	 */
	public function boot(): void
	{
		// Publish config (only when publishing, not on every request)
		$this->publishes([
			__DIR__.'/../config/laravel-overlord.php' => config_path('laravel-overlord.php'),
		], 'laravel-overlord-config');

		// Publish migrations
		$this->publishes([
			__DIR__.'/../database/migrations' => database_path('migrations'),
		], 'laravel-overlord-migrations');

		// Publish views
		$this->publishes([
			__DIR__.'/../resources/views' => resource_path('views/vendor/laravel-overlord'),
		], 'laravel-overlord-views');

		// Publish assets (Vue components)
		$this->publishes([
			__DIR__.'/../resources/js' => resource_path('js/vendor/laravel-overlord'),
		], 'laravel-overlord-assets');

		// Load routes - use loadRoutesFrom which is optimized by Laravel
		// Routes are cached when route:cache is run, so this is fast
		$this->loadRoutesFrom(__DIR__.'/../routes/api.php');

		// Load views - this is lightweight and cached by Laravel
		$this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-overlord');

		// Register console commands
		if ($this->app->runningInConsole()) {
			$this->commands([
				\Spiderwisp\LaravelOverlord\Console\InstallCommand::class,
			]);
		}

		// Validate required environment variables if AI is enabled
		$this->validateEnvironmentVariables();
	}

	/**
	 * Validate required environment variables for AI features
	 */
	protected function validateEnvironmentVariables(): void
	{
		// Only validate if AI is enabled
		if (!config('laravel-overlord.ai.enabled', true)) {
			return;
		}

		$missing = [];
		$aiConfig = config('laravel-overlord.ai', []);

		// Check required variables (API URL is internal, only check API key)
		if (empty($aiConfig['api_key'])) {
			$missing[] = 'LARAVEL_OVERLORD_API_KEY';
		}

		// Only log warning in non-console environments to avoid breaking CLI commands
		if (!empty($missing) && !$this->app->runningInConsole()) {
			\Log::warning('Laravel Overlord: Missing required environment variables for AI features', [
				'missing' => $missing,
				'message' => 'AI features may not work correctly. Please set the following environment variables: ' . implode(', ', $missing),
			]);
		}
	}
}
