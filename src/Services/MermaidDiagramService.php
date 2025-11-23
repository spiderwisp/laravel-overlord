<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

class MermaidDiagramService
{
	protected $modelDiscovery;

	public function __construct()
	{
		$this->modelDiscovery = new ModelDiscovery();
	}

	/**
	 * Generate Mermaid diagram for model relationships
	 * 
	 * @return string Mermaid flowchart syntax
	 */
	public function generateDiagram(): string
	{
		$includePackage = config('laravel-overlord.mermaid.include_package', false);
		$cacheKey = 'overlord_mermaid_models_' . ($includePackage ? 'with_package' : 'app_only');
		$cacheTag = $this->getCodebaseCacheTag();
		$cacheKeyWithTag = "{$cacheKey}_{$cacheTag}";

		return Cache::remember($cacheKeyWithTag, now()->addHours(24), function () {
			return $this->buildModelDiagram();
		});
	}

	/**
	 * Force regeneration of diagram (bypass cache)
	 */
	public function regenerateDiagram(): string
	{
		$includePackage = config('laravel-overlord.mermaid.include_package', false);
		$cacheKey = 'overlord_mermaid_models_' . ($includePackage ? 'with_package' : 'app_only');
		$cacheTag = $this->getCodebaseCacheTag();
		$cacheKeyWithTag = "{$cacheKey}_{$cacheTag}";

		// Clear old cache
		Cache::forget($cacheKeyWithTag);

		$diagram = $this->buildModelDiagram();
		Cache::put($cacheKeyWithTag, $diagram, now()->addHours(24));
		
		return $diagram;
	}

	/**
	 * Generate focused diagram showing a specific model and its relationships
	 * 
	 * @param string $modelName The name of the model to focus on
	 * @param int $connectionDepth How many levels of relationships to include (1 = direct only, 2-3 = include indirect)
	 * @return string Mermaid flowchart syntax
	 */
	public function getFocusedDiagram(string $modelName, int $connectionDepth = 1): string
	{
		$includePackage = config('laravel-overlord.mermaid.include_package', false);
		$cacheKey = 'overlord_mermaid_focused_model_' . md5($modelName . $connectionDepth . ($includePackage ? 'with_package' : 'app_only'));
		$cacheTag = $this->getCodebaseCacheTag();
		$cacheKeyWithTag = "{$cacheKey}_{$cacheTag}";

		return Cache::remember($cacheKeyWithTag, now()->addHours(24), function () use ($modelName, $connectionDepth) {
			return $this->buildFocusedModelDiagram($modelName, $connectionDepth);
		});
	}

	/**
	 * Build the Mermaid diagram syntax for models and relationships
	 */
	protected function buildModelDiagram(): string
	{
		$models = $this->getModels();
		$relationships = $this->analyzeModelRelationships($models);
		
		// Generate Mermaid syntax - LR (left-to-right) for horizontal scrolling
		$mermaid = "flowchart LR\n";
		
		// Add class definitions for relationship types
		$mermaid .= "    classDef model fill:#e74c3c,stroke:#a93226,stroke-width:3px,color:#fff,stroke-dasharray: 0\n";
		$mermaid .= "    classDef focused fill:#ff6b6b,stroke:#c92a2a,stroke-width:4px,color:#fff,stroke-dasharray: 0\n";
		$mermaid .= "\n";
		
		// Add all models as nodes
		foreach ($models as $model) {
			$name = $this->sanitizeNodeId($model['name']);
			$label = $this->escapeMermaidLabel($model['name']);
			$mermaid .= "    {$name}[\"{$label}\"]\n";
		}
		
		$mermaid .= "\n";
		
		// Add relationships as edges
		// Use a map to track unique edges by direction and label to avoid true duplicates
		// but allow bidirectional relationships (same nodes, different directions)
		$edgeKeys = [];
		
		foreach ($relationships as $rel) {
			$fromId = $this->sanitizeNodeId($rel['from']);
			$toId = $this->sanitizeNodeId($rel['to']);
			$relType = $this->escapeMermaidLabel($rel['type']);
			
			// Add relationship label with type
			$label = $relType;
			if (isset($rel['name'])) {
				$label .= ': ' . $this->escapeMermaidLabel($rel['name']);
			}
			
			// For polymorphic relationships, show morphable types
			if (isset($rel['morphable_types']) && !empty($rel['morphable_types'])) {
				$morphable = implode(', ', array_map([$this, 'escapeMermaidLabel'], $rel['morphable_types']));
				$label .= ' (' . $morphable . ')';
			}
			
			// Create a unique key that includes direction AND label to allow bidirectional relationships
			// but prevent exact duplicates (same direction, same label)
			$edgeKey = "{$fromId}->{$toId}:{$label}";
			
			// Only skip if we've already added this exact edge (same direction, same label)
			if (!isset($edgeKeys[$edgeKey])) {
				$edgeKeys[$edgeKey] = true;
				$mermaid .= "    {$fromId} -->|{$label}| {$toId}\n";
			}
		}
		
		// Apply styles to all models
		foreach ($models as $model) {
			$name = $this->sanitizeNodeId($model['name']);
			$mermaid .= "    class {$name} model\n";
		}
		
		return $mermaid;
	}

	/**
	 * Build focused diagram for a specific model
	 */
	protected function buildFocusedModelDiagram(string $modelName, int $connectionDepth): string
	{
		$models = $this->getModels();
		$allRelationships = $this->analyzeModelRelationships($models);
		
		// Find the focused model
		$focusedModel = null;
		foreach ($models as $model) {
			if (strtolower($model['name']) === strtolower($modelName)) {
				$focusedModel = $model;
				break;
			}
		}
		
		if (!$focusedModel) {
			return "flowchart LR\n    NotFound[\"Model not found: {$modelName}\"]\n";
		}
		
		// Find connected models
		$connectedModels = $this->findConnectedModels($focusedModel['name'], $models, $allRelationships, $connectionDepth);
		$connectedModelNames = array_map(function($m) { return $m['name']; }, $connectedModels);
		$connectedModelNames[] = $focusedModel['name'];
		
		// Filter relationships to only include those involving connected models
		$filteredRelationships = array_filter($allRelationships, function($rel) use ($connectedModelNames) {
			return in_array($rel['from'], $connectedModelNames) && in_array($rel['to'], $connectedModelNames);
		});
		
		// Generate Mermaid syntax
		$mermaid = "flowchart LR\n";
		
		// Add class definitions
		$mermaid .= "    classDef model fill:#e74c3c,stroke:#a93226,stroke-width:3px,color:#fff,stroke-dasharray: 0\n";
		$mermaid .= "    classDef focused fill:#ff6b6b,stroke:#c92a2a,stroke-width:4px,color:#fff,stroke-dasharray: 0\n";
		$mermaid .= "\n";
		
		// Add focused model
		$focusedId = $this->sanitizeNodeId($focusedModel['name']);
		$focusedLabel = $this->escapeMermaidLabel($focusedModel['name']);
		$mermaid .= "    {$focusedId}[\"{$focusedLabel}\"]\n";
		
		// Add connected models
		foreach ($connectedModels as $model) {
			$name = $this->sanitizeNodeId($model['name']);
			$label = $this->escapeMermaidLabel($model['name']);
			$mermaid .= "    {$name}[\"{$label}\"]\n";
		}
		
		$mermaid .= "\n";
		
		// Add relationships - track to avoid exact duplicates but allow bidirectional
		$edgeKeys = [];
		foreach ($filteredRelationships as $rel) {
			$fromId = $this->sanitizeNodeId($rel['from']);
			$toId = $this->sanitizeNodeId($rel['to']);
			$relType = $this->escapeMermaidLabel($rel['type']);
			
			$label = $relType;
			if (isset($rel['name'])) {
				$label .= ': ' . $this->escapeMermaidLabel($rel['name']);
			}
			
			if (isset($rel['morphable_types']) && !empty($rel['morphable_types'])) {
				$morphable = implode(', ', array_map([$this, 'escapeMermaidLabel'], $rel['morphable_types']));
				$label .= ' (' . $morphable . ')';
			}
			
			// Create unique key including direction and label
			$edgeKey = "{$fromId}->{$toId}:{$label}";
			if (!isset($edgeKeys[$edgeKey])) {
				$edgeKeys[$edgeKey] = true;
				$mermaid .= "    {$fromId} -->|{$label}| {$toId}\n";
			}
		}
		
		// Apply styles
		$mermaid .= "\n    class {$focusedId} focused\n";
		foreach ($connectedModels as $model) {
			$name = $this->sanitizeNodeId($model['name']);
			$mermaid .= "    class {$name} model\n";
		}
		
		return $mermaid;
	}

	/**
	 * Analyze all model relationships
	 */
	protected function analyzeModelRelationships(array $models): array
	{
		$relationships = [];
		
		foreach ($models as $model) {
			$fullClassName = $model['fullName'];
			
			if (!class_exists($fullClassName)) {
				continue;
			}
			
			try {
				$reflection = new ReflectionClass($fullClassName);
				$modelInstance = new $fullClassName();
				
				// Get all public methods
				$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
				
				foreach ($methods as $method) {
					$methodName = $method->getName();
					
					// Skip magic methods and methods with parameters
					if (strpos($methodName, '__') === 0 || $method->getNumberOfParameters() > 0) {
						continue;
					}
					
					// Check if method returns a relationship
					$returnType = $method->getReturnType();
					if (!$returnType || $returnType->getName() === 'void') {
						continue;
					}
					
					$returnTypeName = $returnType->getName();
					
					// Check for relationship return types (both Relations and Relation)
					if (strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relations') !== false ||
						strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relation') !== false) {
						try {
							$relation = $modelInstance->$methodName();
							
							if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
								$relationshipInfo = $this->extractRelationshipInfo($relation, $methodName, $model['name']);
								
								if ($relationshipInfo) {
									$relationships[] = $relationshipInfo;
								}
							}
						} catch (\Throwable $e) {
							// Skip relationships that can't be instantiated (e.g., require parameters, database not set up)
							// Log for debugging but don't fail
							continue;
						}
					}
				}
			} catch (\Throwable $e) {
				// Skip models that can't be analyzed
				continue;
			}
		}
		
		// Handle polymorphic inverse relationships (morphTo)
		$relationships = $this->detectPolymorphicInverse($models, $relationships);
		
		return $relationships;
	}

	/**
	 * Extract relationship information from a relation instance
	 */
	protected function extractRelationshipInfo($relation, string $methodName, string $modelName): ?array
	{
		$relationType = $this->getRelationshipType($relation);
		
		if ($relationType === 'unknown') {
			return null;
		}
		
		$relationshipInfo = [
			'from' => $modelName,
			'type' => $relationType,
			'name' => $methodName,
		];
		
		// Handle different relationship types
		if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
			$relationshipInfo['foreign_key'] = $relation->getForeignKeyName();
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne || 
				  $relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
			$relationshipInfo['foreign_key'] = $relation->getForeignKeyName();
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
			$relationshipInfo['pivot_table'] = $relation->getTable();
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasManyThrough) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
			// For morphTo, we need to detect which models can morph to this
			// This will be handled in detectPolymorphicInverse
			$relationshipInfo['to'] = 'Morphable';
			$relationshipInfo['morphable_types'] = []; // Will be populated later
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOne ||
				  $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
			$relationshipInfo['morph_type'] = $relation->getMorphType();
			$relationshipInfo['morph_class'] = $relation->getMorphClass();
		} elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany ||
				  $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphedByMany) {
			$relatedModel = get_class($relation->getRelated());
			$relationshipInfo['to'] = class_basename($relatedModel);
			$relationshipInfo['morph_type'] = $relation->getMorphType();
		} else {
			return null;
		}
		
		return $relationshipInfo;
	}

	/**
	 * Get relationship type from relation instance
	 */
	protected function getRelationshipType($relation): string
	{
		$class = get_class($relation);
		
		if (strpos($class, 'HasOne') !== false) {
			return 'hasOne';
		}
		if (strpos($class, 'HasMany') !== false) {
			return 'hasMany';
		}
		if (strpos($class, 'BelongsTo') !== false && strpos($class, 'BelongsToMany') === false) {
			return 'belongsTo';
		}
		if (strpos($class, 'BelongsToMany') !== false) {
			return 'belongsToMany';
		}
		if (strpos($class, 'MorphTo') !== false) {
			return 'morphTo';
		}
		if (strpos($class, 'MorphMany') !== false) {
			return 'morphMany';
		}
		if (strpos($class, 'MorphOne') !== false) {
			return 'morphOne';
		}
		if (strpos($class, 'MorphToMany') !== false) {
			return 'morphToMany';
		}
		if (strpos($class, 'MorphedByMany') !== false) {
			return 'morphedByMany';
		}
		if (strpos($class, 'HasManyThrough') !== false) {
			return 'hasManyThrough';
		}
		
		return 'unknown';
	}

	/**
	 * Detect polymorphic inverse relationships (which models can morph to a model with morphTo)
	 */
	protected function detectPolymorphicInverse(array $models, array $relationships): array
	{
		// Find all morphTo relationships
		$morphToRelationships = [];
		foreach ($relationships as $index => $rel) {
			if ($rel['type'] === 'morphTo') {
				$morphToRelationships[] = $index;
			}
		}
		
		// For each morphTo, find models that have morphMany/morphOne pointing to it
		foreach ($morphToRelationships as $index) {
			$morphToRel = &$relationships[$index];
			$morphableTypes = [];
			
			// Find models with morphMany/morphOne that point to this model
			foreach ($relationships as $otherRel) {
				if (($otherRel['type'] === 'morphMany' || $otherRel['type'] === 'morphOne') &&
					$otherRel['to'] === $morphToRel['from']) {
					$morphableTypes[] = $otherRel['from'];
				}
			}
			
			if (!empty($morphableTypes)) {
				$morphToRel['morphable_types'] = array_unique($morphableTypes);
				// Update the 'to' field to show it's polymorphic
				$morphToRel['to'] = 'Morphable (' . implode(', ', $morphableTypes) . ')';
			}
		}
		
		return $relationships;
	}

	/**
	 * Find models connected to a given model
	 */
	protected function findConnectedModels(string $modelName, array $allModels, array $relationships, int $depth): array
	{
		$connected = [];
		$visited = [];
		$queue = [[$modelName, 0]]; // [model name, level]
		
		while (!empty($queue)) {
			[$currentModel, $level] = array_shift($queue);
			
			if ($level > $depth || isset($visited[$currentModel])) {
				continue;
			}
			
			$visited[$currentModel] = true;
			
			if ($level > 0) {
				// Find the model in allModels
				foreach ($allModels as $model) {
					if ($model['name'] === $currentModel) {
						$connected[] = $model;
						break;
					}
				}
			}
			
			if ($level < $depth) {
				// Find all relationships involving this model
				foreach ($relationships as $rel) {
					$nextModel = null;
					if ($rel['from'] === $currentModel) {
						$nextModel = $rel['to'];
					} elseif ($rel['to'] === $currentModel) {
						$nextModel = $rel['from'];
					}
					
					if ($nextModel && !isset($visited[$nextModel]) && $nextModel !== 'Morphable') {
						$queue[] = [$nextModel, $level + 1];
					}
				}
			}
		}
		
		return $connected;
	}

	/**
	 * Get models from ModelDiscovery service
	 */
	protected function getModels(): array
	{
		try {
			$modelClasses = $this->modelDiscovery->getModelClasses();
			$models = [];
			
			foreach ($modelClasses as $name => $fullName) {
				$models[] = [
					'name' => $name,
					'fullName' => $fullName,
				];
			}
			
			$includePackage = config('laravel-overlord.mermaid.include_package', false);
			if (!$includePackage) {
				$models = $this->filterPackageComponents($models);
			}
			
			return array_values($models);
		} catch (\Throwable $e) {
			return [];
		}
	}

	/**
	 * Filter out components belonging to the Laravel Overlord package
	 */
	protected function filterPackageComponents(array $components): array
	{
		return array_values(array_filter($components, function ($component) {
			if (!is_array($component)) {
				return true;
			}
			
			// Check fullName first (most reliable)
			if (isset($component['fullName']) && is_string($component['fullName'])) {
				$fullName = $component['fullName'];
				if (strpos($fullName, 'Spiderwisp\\LaravelOverlord\\') === 0) {
					return false;
				}
			}
			
			// Check namespace
			if (isset($component['namespace']) && is_string($component['namespace'])) {
				$namespace = $component['namespace'];
				if (strpos($namespace, 'Spiderwisp\\LaravelOverlord\\') === 0) {
					return false;
				}
			}
			
			// Check class name
			if (isset($component['class']) && is_string($component['class'])) {
				$class = $component['class'];
				if (strpos($class, 'Spiderwisp\\LaravelOverlord\\') === 0) {
					return false;
				}
			}

			// Also check filePath to exclude package files
			if (isset($component['filePath']) && is_string($component['filePath'])) {
				$filePath = $component['filePath'];
				if (strpos($filePath, 'vendor/spiderwisp') !== false || 
					strpos($filePath, 'packages/laravel-overlord') !== false) {
					return false;
				}
			}
			
			return true;
		}));
	}

	/**
	 * Get cache tag based on codebase modification times
	 */
	protected function getCodebaseCacheTag(): string
	{
		$paths = [
			app_path('Models'),
			app_path(),
		];
		
		$maxMtime = 0;
		foreach ($paths as $path) {
			if (is_dir($path)) {
				$mtime = filemtime($path);
				if ($mtime > $maxMtime) {
					$maxMtime = $mtime;
				}
				
				// Also check files in directory recursively
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
				);
				foreach ($iterator as $file) {
					if ($file->isFile()) {
						$fileMtime = $file->getMTime();
						if ($fileMtime > $maxMtime) {
							$maxMtime = $fileMtime;
						}
					}
				}
			}
		}
		
		return md5($maxMtime . implode('|', $paths));
	}

	/**
	 * Sanitize node ID for Mermaid (remove special characters)
	 */
	protected function sanitizeNodeId(string $name): string
	{
		// Replace spaces and special chars with underscores, remove dots
		$id = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
		// Remove multiple underscores
		$id = preg_replace('/_+/', '_', $id);
		// Remove leading/trailing underscores
		$id = trim($id, '_');
		return $id ?: 'node';
	}

	/**
	 * Escape label text for Mermaid (handle special characters in labels)
	 */
	protected function escapeMermaidLabel(string $label): string
	{
		// Escape quotes and backslashes
		$label = str_replace('\\', '\\\\', $label);
		$label = str_replace('"', '\\"', $label);
		// Also escape newlines and other problematic characters
		$label = str_replace(["\n", "\r"], ' ', $label);
		return $label;
	}
}
