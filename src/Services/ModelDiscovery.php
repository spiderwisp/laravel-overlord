<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Cache;

class ModelDiscovery
{
	/**
	 * Get all model classes from the configured models path
	 * Results are cached to avoid filesystem scanning on every request
	 */
	public function getModelClasses(): array
	{
		$modelsPath = config('laravel-overlord.models_path', app_path('Models'));
		$cacheKey = 'overlord_model_discovery';

		// Generate a cache key based on the models directory modification time
		// This ensures cache invalidation when models are added/removed
		$cacheTag = $this->getModelsDirectoryTag($modelsPath);
		$cacheKeyWithTag = "{$cacheKey}_{$cacheTag}";

		return Cache::remember($cacheKeyWithTag, now()->addHours(24), function () use ($modelsPath) {
			$models = [];

			if (is_dir($modelsPath)) {
				$files = glob($modelsPath . '/*.php');
				foreach ($files as $file) {
					$className = basename($file, '.php');

					// Try to determine the full class name from the namespace
					// First, try to extract namespace from file
					$fullClassName = $this->getFullClassNameFromFile($file, $className);

					if (!$fullClassName) {
						// Fallback: try common namespace patterns
						$fullClassName = "App\\Models\\{$className}";
					}

					// Check if class exists and is actually a model
					if (class_exists($fullClassName)) {
						$reflection = new \ReflectionClass($fullClassName);
						if (
							$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class) ||
							$fullClassName === \Illuminate\Database\Eloquent\Model::class
						) {
							$models[$className] = $fullClassName;
						}
					}
				}
			}

			return $models;
		});
	}

	/**
	 * Generate a cache tag based on the models directory modification time
	 * This allows cache invalidation when models change
	 */
	private function getModelsDirectoryTag(string $modelsPath): string
	{
		if (!is_dir($modelsPath)) {
			return 'empty';
		}

		// First check directory modification time (faster)
		$dirMtime = filemtime($modelsPath);

		// Also check the most recent modification time of any PHP file in the directory
		// This catches cases where files are modified but directory mtime doesn't change
		$files = glob($modelsPath . '/*.php');
		if (empty($files)) {
			return md5($dirMtime . $modelsPath);
		}

		$maxMtime = $dirMtime;
		foreach ($files as $file) {
			$mtime = @filemtime($file);
			if ($mtime && $mtime > $maxMtime) {
				$maxMtime = $mtime;
			}
		}

		return md5($maxMtime . $modelsPath);
	}

	/**
	 * Extract full class name from PHP file
	 */
	private function getFullClassNameFromFile(string $file, string $className): ?string
	{
		$content = file_get_contents($file);

		// Extract namespace
		if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
			$namespace = trim($matches[1]);
			return $namespace . '\\' . $className;
		}

		return null;
	}
}