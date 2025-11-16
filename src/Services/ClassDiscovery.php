<?php

namespace Spiderwisp\LaravelOverlord\Services;

class ClassDiscovery
{
	/**
	 * Get all classes and their detailed information
	 */
	public function getClasses(): array
	{
		$classesPath = config('laravel-overlord.classes_path', app_path());
		$classes = [];

		// Get the real path to handle symlinks
		$classesPath = realpath($classesPath);

		if (!$classesPath || !is_dir($classesPath)) {
			throw new \Exception('Classes directory not found');
		}

		// No directories to exclude - include all classes (Models, Controllers, etc.)
		$excludeDirs = [];

		// Recursively get all PHP files
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($classesPath, \RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if ($file->isDir() || $file->getExtension() !== 'php') {
				continue;
			}

			$filePath = $file->getRealPath();
			$relativePath = str_replace($classesPath . DIRECTORY_SEPARATOR, '', $filePath);
			$relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

			// Skip if in excluded directories
			$shouldExclude = false;
			foreach ($excludeDirs as $excludeDir) {
				if (
					strpos($relativePath, $excludeDir . '/') === 0 ||
					strpos($relativePath, $excludeDir . '\\') === 0 ||
					$relativePath === $excludeDir
				) {
					$shouldExclude = true;
					break;
				}
			}
			if ($shouldExclude) {
				continue;
			}

			// Remove .php extension
			$relativePath = preg_replace('/\.php$/', '', $relativePath);
			// Convert to namespace format (backslashes)
			$relativePath = str_replace('/', '\\', $relativePath);

			// Try to extract namespace from file
			$className = $this->getFullClassNameFromFile($filePath, $relativePath);

			if (!$className) {
				// Fallback: try common namespace pattern
				$className = 'App\\' . $relativePath;
			}

			// Check if class exists
			if (!class_exists($className) && !trait_exists($className) && !interface_exists($className)) {
				continue;
			}

			try {
				$reflection = new \ReflectionClass($className);

				// Skip if it's an interface
				if ($reflection->isInterface()) {
					continue;
				}

				$classNameShort = class_basename($className);
				$namespace = $reflection->getNamespaceName();

				// Determine class type
				$classType = $this->determineClassType($className, $namespace, $relativePath);

				// Get parent class hierarchy
				$parentClass = $reflection->getParentClass();
				$parentChain = [];
				$currentParent = $parentClass;
				while ($currentParent) {
					$parentChain[] = $currentParent->getName();
					$currentParent = $currentParent->getParentClass();
				}

				// Get interfaces
				$interfaces = array_map(function ($interface) {
					return $interface->getName();
				}, $reflection->getInterfaces());

				// Get traits
				$traits = $reflection->getTraitNames();

				// Get properties
				$properties = [];
				foreach ($reflection->getProperties() as $property) {
					if ($property->getDeclaringClass()->getName() !== $className) {
						continue;
					}

					$type = null;
					if ($property->getType()) {
						$type = $property->getType()->getName();
						if ($property->getType()->allowsNull()) {
							$type .= '|null';
						}
					}

					$properties[] = [
						'name' => $property->getName(),
						'visibility' => $this->getPropertyVisibility($property),
						'type' => $type,
						'static' => $property->isStatic(),
						'defaultValue' => $property->hasDefaultValue() ? $this->getDefaultValueString($property->getDefaultValue()) : null,
						'docblock' => $this->getDocblock($property),
					];
				}

				// Get constants
				$constants = [];
				foreach ($reflection->getConstants() as $name => $value) {
					$constants[] = [
						'name' => $name,
						'value' => $this->getConstantValueString($value),
						'type' => gettype($value),
					];
				}

				// Get methods
				$methods = [];
				foreach ($reflection->getMethods() as $method) {
					$declaringClass = $method->getDeclaringClass()->getName();
					if ($declaringClass !== $className && !in_array($declaringClass, $traits)) {
						continue;
					}

					$returnType = null;
					if ($method->getReturnType()) {
						$returnType = $method->getReturnType()->getName();
						if ($method->getReturnType()->allowsNull()) {
							$returnType .= '|null';
						}
					}

					$methods[] = [
						'name' => $method->getName(),
						'visibility' => $this->getMethodVisibility($method),
						'static' => $method->isStatic(),
						'abstract' => $method->isAbstract(),
						'final' => $method->isFinal(),
						'returnType' => $returnType,
						'parameters' => array_map(function ($param) {
							$paramType = null;
							if ($param->getType()) {
								$paramType = $param->getType()->getName();
								if ($param->getType()->allowsNull()) {
									$paramType .= '|null';
								}
							}
							return [
								'name' => $param->getName(),
								'type' => $paramType,
								'hasDefault' => $param->isDefaultValueAvailable(),
								'defaultValue' => $param->isDefaultValueAvailable() ? $this->getDefaultValueString($param->getDefaultValue()) : null,
								'optional' => $param->isOptional(),
							];
						}, $method->getParameters()),
						'docblock' => $this->getDocblock($method),
					];
				}

				// Get dependencies from constructor
				$dependencies = [];
				$constructor = $reflection->getConstructor();
				if ($constructor) {
					foreach ($constructor->getParameters() as $param) {
						if ($param->getType() && !$param->getType()->isBuiltin()) {
							$dependencies[] = $param->getType()->getName();
						}
					}
				}

				// Get class docblock
				$classDocblock = $this->getDocblock($reflection);

				// Get file info
				$fileName = $reflection->getFileName();
				$startLine = $reflection->getStartLine();
				$endLine = $reflection->getEndLine();

				$classes[] = [
					'name' => $classNameShort,
					'fullName' => $className,
					'namespace' => $namespace,
					'type' => $classType,
					'isTrait' => $reflection->isTrait(),
					'isAbstract' => $reflection->isAbstract(),
					'isFinal' => $reflection->isFinal(),
					'parentClass' => $parentClass ? $parentClass->getName() : null,
					'parentChain' => $parentChain,
					'interfaces' => $interfaces,
					'traits' => $traits,
					'properties' => $properties,
					'constants' => $constants,
					'methods' => $methods,
					'dependencies' => $dependencies,
					'docblock' => $classDocblock,
					'filePath' => $fileName ? str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fileName) : null,
					'startLine' => $startLine,
					'endLine' => $endLine,
				];
			} catch (\Throwable $e) {
				// Skip classes that can't be analyzed
				continue;
			}
		}

		// Sort by type and name
		usort($classes, function ($a, $b) {
			$typeCompare = strcmp($a['type'], $b['type']);
			if ($typeCompare !== 0) {
				return $typeCompare;
			}
			return strcmp($a['name'], $b['name']);
		});

		return $classes;
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
	 * Determine class type based on namespace and path
	 */
	private function determineClassType($className, $namespace, $relativePath)
	{
		// Check if it's a Model
		try {
			$reflection = new \ReflectionClass($className);
			if ($reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
				return 'Model';
			}
		} catch (\Throwable $e) {
			// Continue with other checks
		}

		// Check if it's a Controller
		try {
			$reflection = new \ReflectionClass($className);
			if ($reflection->isSubclassOf(\Illuminate\Routing\Controller::class)) {
				return 'Controller';
			}
		} catch (\Throwable $e) {
			// Continue with other checks
		}

		// Check namespace
		if (strpos($namespace, 'App\\Models') !== false) {
			return 'Model';
		}
		if (strpos($namespace, 'App\\Http\\Controllers') !== false) {
			return 'Controller';
		}
		if (strpos($namespace, 'App\\Services') !== false) {
			return 'Service';
		}
		if (strpos($namespace, 'App\\Helpers') !== false) {
			return 'Helper';
		}
		if (strpos($namespace, 'App\\Traits') !== false) {
			return 'Trait';
		}
		if (strpos($namespace, 'App\\Jobs') !== false) {
			return 'Job';
		}
		if (strpos($namespace, 'App\\Mail') !== false) {
			return 'Mail';
		}
		if (strpos($namespace, 'App\\Providers') !== false) {
			return 'Provider';
		}
		if (strpos($namespace, 'App\\Exceptions') !== false) {
			return 'Exception';
		}
		if (strpos($namespace, 'App\\Console\\Commands') !== false) {
			return 'Command';
		}
		if (strpos($namespace, 'App\\Http\\Middleware') !== false) {
			return 'Middleware';
		}
		if (strpos($namespace, 'App\\Http\\Requests') !== false) {
			return 'Request';
		}
		if (strpos($namespace, 'App\\Observers') !== false) {
			return 'Observer';
		}
		if (strpos($namespace, 'App\\Extensions') !== false) {
			return 'Extension';
		}

		// Check if it's a trait
		if (trait_exists($className)) {
			return 'Trait';
		}

		return 'Class';
	}

	/**
	 * Get property visibility string
	 */
	private function getPropertyVisibility(\ReflectionProperty $property)
	{
		if ($property->isPublic()) {
			return 'public';
		}
		if ($property->isProtected()) {
			return 'protected';
		}
		return 'private';
	}

	/**
	 * Get method visibility string
	 */
	private function getMethodVisibility(\ReflectionMethod $method)
	{
		if ($method->isPublic()) {
			return 'public';
		}
		if ($method->isProtected()) {
			return 'protected';
		}
		return 'private';
	}

	/**
	 * Get docblock from reflection
	 */
	private function getDocblock($reflection)
	{
		$docblock = $reflection->getDocComment();
		if ($docblock) {
			$lines = explode("\n", $docblock);
			$cleaned = [];
			foreach ($lines as $line) {
				$line = trim($line);
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

	/**
	 * Get default value as string
	 */
	private function getDefaultValueString($value)
	{
		if (is_null($value)) {
			return 'null';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_string($value)) {
			return '"' . addslashes($value) . '"';
		}
		if (is_array($value)) {
			return '[]';
		}
		if (is_object($value)) {
			return get_class($value) . '()';
		}
		return (string) $value;
	}

	/**
	 * Get constant value as string
	 */
	private function getConstantValueString($value)
	{
		if (is_null($value)) {
			return 'null';
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_string($value)) {
			return '"' . addslashes($value) . '"';
		}
		if (is_array($value)) {
			return json_encode($value);
		}
		if (is_object($value)) {
			return get_class($value) . '()';
		}
		return (string) $value;
	}
}