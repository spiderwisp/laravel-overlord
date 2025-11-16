<?php

namespace Spiderwisp\LaravelOverlord\Services;

class ControllerDiscovery
{
	/**
	 * Get all controllers and their methods
	 */
	public function getControllers(): array
	{
		$controllersPath = config('laravel-overlord.controllers_path', app_path('Http/Controllers'));
		$controllers = [];

		// Get the real path to handle symlinks
		$controllersPath = realpath($controllersPath);

		if (!$controllersPath || !is_dir($controllersPath)) {
			throw new \Exception('Controllers directory not found');
		}

		// Recursively get all controller files
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($controllersPath, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isDir() || $file->getExtension() !== 'php') {
				continue;
			}

			$filePath = $file->getRealPath();

			// Get relative path from controllers directory
			$relativePath = str_replace($controllersPath . DIRECTORY_SEPARATOR, '', $filePath);
			// Normalize path separators to forward slashes first
			$relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
			// Remove .php extension
			$relativePath = preg_replace('/\.php$/', '', $relativePath);
			// Convert to namespace format (backslashes)
			$relativePath = str_replace('/', '\\', $relativePath);

			// Try to extract namespace from file
			$className = $this->getFullClassNameFromFile($filePath, $relativePath);

			if (!$className) {
				// Fallback: try common namespace pattern
				$className = 'App\\Http\\Controllers\\' . $relativePath;
			}

			// Skip if it's the base Controller class
			if (
				strpos($className, 'Controller') === false ||
				(strpos($className, '\\Controller') !== false &&
					strpos($className, '\\Controller\\') === false &&
					$className !== 'App\\Http\\Controllers\\Controller')
			) {
				// This is a heuristic - adjust as needed
			}

			// Check if class exists
			if (!class_exists($className)) {
				continue;
			}

			try {
				$reflection = new \ReflectionClass($className);

				// Skip if abstract
				if ($reflection->isAbstract()) {
					continue;
				}

				// Check if it's a controller by checking if it extends Controller
				$isController = $reflection->isSubclassOf(\Illuminate\Routing\Controller::class);

				// Also check if class name ends with "Controller" as a fallback
				$controllerName = class_basename($className);
				if (!$isController && strpos($controllerName, 'Controller') !== false) {
					$isController = true;
				}

				if (!$isController) {
					continue;
				}

				$namespace = $reflection->getNamespaceName();

				// Get all public methods
				$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
				$controllerMethods = [];

				foreach ($methods as $method) {
					// Skip magic methods and inherited methods
					if (strpos($method->getName(), '__') === 0) {
						continue;
					}

					// Skip if method is from parent class (Controller base class)
					$declaringClass = $method->getDeclaringClass()->getName();
					if (
						$declaringClass === 'Illuminate\Routing\Controller' ||
						(class_exists('App\Http\Controllers\Controller') &&
							$declaringClass === 'App\Http\Controllers\Controller')
					) {
						continue;
					}

					$controllerMethods[] = [
						'name' => $method->getName(),
						'parameters' => array_map(function ($param) {
							return [
								'name' => $param->getName(),
								'type' => $param->getType() ? $param->getType()->getName() : null,
								'hasDefault' => $param->isDefaultValueAvailable(),
							];
						}, $method->getParameters()),
						'docblock' => $this->getDocblock($method),
					];
				}

				$controllers[] = [
					'name' => $controllerName,
					'fullName' => $className,
					'namespace' => $namespace,
					'path' => $relativePath,
					'methods' => $controllerMethods,
				];
			} catch (\Throwable $e) {
				// Skip controllers that can't be analyzed
				continue;
			}
		}

		// Sort by name
		usort($controllers, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});

		return $controllers;
	}

	/**
	 * Extract full class name from PHP file
	 */
	private function getFullClassNameFromFile(string $filePath, string $relativePath): ?string
	{
		$content = file_get_contents($filePath);

		// Extract namespace
		if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
			$namespace = trim($matches[1]);
			$className = class_basename($relativePath);
			return $namespace . '\\' . $className;
		}

		return null;
	}

	/**
	 * Get docblock from reflection
	 */
	private function getDocblock($reflection)
	{
		$docblock = $reflection->getDocComment();
		if ($docblock) {
			// Clean up docblock
			$lines = explode("\n", $docblock);
			$cleaned = [];
			foreach ($lines as $line) {
				$line = trim($line);
				// Remove /** and */
				$line = preg_replace('/^\/\*\*?\s*/', '', $line);
				$line = preg_replace('/\s*\*\/$/', '', $line);
				$line = preg_replace('/^\*\s*/', '', $line);
				if ($line) {
					$cleaned[] = $line;
				}
			}
			return implode("\n", $cleaned);
		}
		return null;
	}
}