<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Routing\Route as RouteInstance;

class RouteDiscovery
{
	/**
	 * Get all routes with full metadata
	 */
	public function getRoutes(): array
	{
		$routes = Route::getRoutes();
		$routesData = [];

		foreach ($routes as $route) {
			try {
				$routeData = $this->extractRouteData($route);
				if ($routeData) {
					$routesData[] = $routeData;
				}
			} catch (\Throwable $e) {
				// Skip routes that can't be analyzed
				continue;
			}
		}

		// Sort by URI
		usort($routesData, function ($a, $b) {
			return strcmp($a['uri'], $b['uri']);
		});

		return $routesData;
	}

	/**
	 * Get detailed information for a specific route
	 */
	public function getRouteDetails(string $identifier): ?array
	{
		$routes = Route::getRoutes();
		
		foreach ($routes as $route) {
			try {
				$routeData = $this->extractRouteData($route);
				if ($routeData && $this->matchesIdentifier($routeData, $identifier)) {
					return $routeData;
				}
			} catch (\Throwable $e) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Generate URL from route name
	 */
	public function generateUrl(string $name, array $parameters = []): string
	{
		try {
			return route($name, $parameters);
		} catch (\Exception $e) {
			throw new \Exception("Route '{$name}' not found or parameters invalid: " . $e->getMessage());
		}
	}

	/**
	 * Extract route data from Route instance
	 */
	protected function extractRouteData(RouteInstance $route): ?array
	{
		$methods = $route->methods();
		$uri = $route->uri();
		$name = $route->getName();
		$action = $route->getAction();

		// Get identifier (use name if available, otherwise URI + method)
		$identifier = $name ?: $this->generateIdentifier($uri, $methods);

		// Extract parameters from URI
		$parameters = $this->extractParameters($uri, $route);

		// Extract middleware
		$middleware = $this->extractMiddleware($route);

		// Extract controller information
		$controllerInfo = $this->extractControllerInfo($action);

		// Extract cross-references
		$crossReferences = $this->extractCrossReferences($route, $controllerInfo);

		return [
			'identifier' => $identifier,
			'methods' => array_values(array_filter($methods, function ($method) {
				return $method !== 'HEAD';
			})),
			'uri' => $uri,
			'name' => $name,
			'action' => $this->formatAction($action),
			'controller' => $controllerInfo,
			'middleware' => $middleware,
			'parameters' => $parameters,
			'domain' => $route->getDomain(),
			'where' => $route->wheres,
			'defaults' => $route->defaults,
			'cross_references' => $crossReferences,
		];
	}

	/**
	 * Extract parameters from route URI
	 */
	protected function extractParameters(string $uri, RouteInstance $route): array
	{
		$parameters = [];
		
		// Find all parameter placeholders in URI
		preg_match_all('/\{([^}]+)\}/', $uri, $matches);
		
		if (!empty($matches[1])) {
			foreach ($matches[1] as $paramName) {
				// Check if parameter is optional
				$isOptional = strpos($paramName, '?') !== false;
				$cleanName = str_replace('?', '', $paramName);
				
				// Get constraint pattern
				$pattern = $route->wheres[$cleanName] ?? null;
				
				// Check if has default value
				$hasDefault = isset($route->defaults[$cleanName]);
				$defaultValue = $hasDefault ? $route->defaults[$cleanName] : null;

				$parameters[] = [
					'name' => $cleanName,
					'required' => !$isOptional && !$hasDefault,
					'optional' => $isOptional || $hasDefault,
					'pattern' => $pattern,
					'default' => $defaultValue,
				];
			}
		}

		return $parameters;
	}

	/**
	 * Extract middleware from route
	 */
	protected function extractMiddleware(RouteInstance $route): array
	{
		$middleware = [];
		
		try {
			$middlewareArray = $route->middleware();
			foreach ($middlewareArray as $mw) {
				$middleware[] = [
					'name' => $mw,
					'type' => 'middleware',
				];
			}
		} catch (\Throwable $e) {
			// If middleware can't be extracted, return empty array
		}

		return $middleware;
	}

	/**
	 * Extract controller information from action
	 */
	protected function extractControllerInfo(array $action): ?array
	{
		$controller = null;
		$method = null;

		if (isset($action['controller'])) {
			$controllerString = $action['controller'];
			if (strpos($controllerString, '@') !== false) {
				list($controller, $method) = explode('@', $controllerString, 2);
			} else {
				$controller = $controllerString;
			}
		} elseif (isset($action['uses']) && is_string($action['uses'])) {
			$controllerString = $action['uses'];
			if (strpos($controllerString, '@') !== false) {
				list($controller, $method) = explode('@', $controllerString, 2);
			} else {
				$controller = $controllerString;
			}
		}

		if (!$controller) {
			return null;
		}

		return [
			'class' => $controller,
			'method' => $method,
			'full_name' => $method ? "{$controller}@{$method}" : $controller,
		];
	}

	/**
	 * Extract cross-references from route
	 */
	protected function extractCrossReferences(RouteInstance $route, ?array $controllerInfo): array
	{
		$crossReferences = [
			'controller' => null,
			'middleware' => [],
			'models' => [],
			'services' => [],
		];

		// Controller cross-reference
		if ($controllerInfo) {
			$crossReferences['controller'] = $controllerInfo;
		}

		// Middleware cross-references
		try {
			$middlewareArray = $route->middleware();
			foreach ($middlewareArray as $mw) {
				$crossReferences['middleware'][] = [
					'name' => $mw,
					'type' => 'middleware',
				];
			}
		} catch (\Throwable $e) {
			// Ignore
		}

		// Try to detect models from route parameters
		$uri = $route->uri();
		preg_match_all('/\{([^}]+)\}/', $uri, $matches);
		if (!empty($matches[1])) {
			foreach ($matches[1] as $paramName) {
				$cleanName = str_replace('?', '', $paramName);
				// Try to infer model from parameter name (e.g., {user} -> User model)
				$modelName = $this->inferModelName($cleanName);
				if ($modelName) {
					$crossReferences['models'][] = [
						'name' => class_basename($modelName),
						'full_name' => $modelName,
						'detected_from' => 'parameter',
					];
				}
			}
		}

		// Services would be detected from controller reflection (future enhancement)

		return $crossReferences;
	}

	/**
	 * Infer model name from parameter name
	 */
	protected function inferModelName(string $paramName): ?string
	{
		// Convert snake_case or kebab-case to PascalCase
		$modelName = str_replace(['-', '_'], ' ', $paramName);
		$modelName = ucwords($modelName);
		$modelName = str_replace(' ', '', $modelName);

		// Try common model namespaces
		$namespaces = [
			'App\\Models\\',
			'App\\',
		];

		foreach ($namespaces as $namespace) {
			$fullName = $namespace . $modelName;
			if (class_exists($fullName)) {
				// Check if it's actually a model
				try {
					$reflection = new \ReflectionClass($fullName);
					if ($reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
						return $fullName;
					}
				} catch (\Throwable $e) {
					// Continue
				}
			}
		}

		return null;
	}

	/**
	 * Format action for display
	 */
	protected function formatAction(array $action): string
	{
		if (isset($action['controller'])) {
			return $action['controller'];
		}
		
		if (isset($action['uses']) && is_string($action['uses'])) {
			return $action['uses'];
		}

		if (isset($action['uses']) && is_callable($action['uses'])) {
			return 'Closure';
		}

		return 'Unknown';
	}

	/**
	 * Generate identifier for unnamed routes
	 */
	protected function generateIdentifier(string $uri, array $methods): string
	{
		$method = !empty($methods) ? $methods[0] : 'GET';
		return md5($uri . '|' . $method);
	}

	/**
	 * Check if route matches identifier
	 */
	protected function matchesIdentifier(array $routeData, string $identifier): bool
	{
		if ($routeData['identifier'] === $identifier) {
			return true;
		}

		if ($routeData['name'] === $identifier) {
			return true;
		}

		// Try URI + method match
		if (strpos($identifier, '|') !== false) {
			list($uri, $method) = explode('|', $identifier, 2);
			if ($routeData['uri'] === $uri && in_array($method, $routeData['methods'])) {
				return true;
			}
		}

		return false;
	}
}

