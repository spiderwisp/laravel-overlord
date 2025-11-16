<?php

namespace Spiderwisp\LaravelOverlord\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AiService
{
	protected $defaultModel;
	protected $contextWindow;
	protected $systemPrompt;
	protected $enabled;
	protected $knowledgeBase;
	protected $modelDiscovery;
	protected $controllerDiscovery;
	protected $classDiscovery;
	protected $fuzzyMatchingThreshold;
	protected $codebaseContextEnabled;
	protected $maxCodebaseFiles;
	protected $contextCache;

	public function __construct(
		ModelDiscovery $modelDiscovery = null,
		ControllerDiscovery $controllerDiscovery = null,
		ClassDiscovery $classDiscovery = null
	) {
		$this->defaultModel = config('laravel-overlord.ai.default_model', 'local');
		$this->contextWindow = config('laravel-overlord.ai.context_window', 10);
		$this->systemPrompt = config('laravel-overlord.ai.system_prompt', $this->getDefaultSystemPrompt());
		$this->enabled = config('laravel-overlord.ai.enabled', true);
		$this->fuzzyMatchingThreshold = config('laravel-overlord.ai.fuzzy_matching_threshold', 0.6);
		$this->codebaseContextEnabled = config('laravel-overlord.ai.codebase_context_enabled', true);
		// Enforce maximum limit to prevent large API payloads (server-side will enforce stricter limits)
		$this->maxCodebaseFiles = min(config('laravel-overlord.ai.max_codebase_files', 5), 20);
		$this->knowledgeBase = $this->initializeKnowledgeBase();

		// Lazy-load discovery services to avoid performance issues
		$this->modelDiscovery = $modelDiscovery;
		$this->controllerDiscovery = $controllerDiscovery;
		$this->classDiscovery = $classDiscovery;
		$this->contextCache = [];
	}

	/**
	 * Get default system prompt for terminal assistance
	 */
	protected function getDefaultSystemPrompt(): string
	{
		return "You are an AI assistant helping developers use Laravel Overlord. 
You understand Laravel, PHP, and command-line operations. 
When users ask questions:
- Provide clear, concise explanations
- Suggest appropriate terminal commands or PHP code
- Format code blocks with proper syntax
- Explain how to use commands
- If generating code, make it ready to paste into the terminal

When providing code or commands, format them clearly and ensure they are syntactically correct.
Always be helpful and focus on Laravel/PHP development tasks.";
	}

	/**
	 * Initialize knowledge base with Laravel/PHP patterns and templates
	 */
	protected function initializeKnowledgeBase(): array
	{
		return [
			'patterns' => [
				'get_all' => [
					'keywords' => ['get all', 'list all', 'show all', 'fetch all', 'retrieve all'],
					'template' => "User::all()\n// Or with pagination:\nUser::paginate(15)\n// Or with conditions:\nUser::where('active', true)->get()",
				],
				'find_by_id' => [
					'keywords' => ['find by id', 'get by id', 'find user', 'get user'],
					'template' => "User::find(1)\n// Or:\nUser::findOrFail(1)\n// Or with relations:\nUser::with('posts')->find(1)",
				],
				'create_record' => [
					'keywords' => ['create', 'new record', 'insert', 'add'],
					'template' => "User::create(['name' => 'John Doe', 'email' => 'john@example.com'])\n// Or:\n\$user = new User();\n\$user->name = 'John Doe';\n\$user->save();",
				],
				'update_record' => [
					'keywords' => ['update', 'modify', 'change', 'edit'],
					'template' => "User::where('id', 1)->update(['name' => 'Jane Doe'])\n// Or:\n\$user = User::find(1);\n\$user->name = 'Jane Doe';\n\$user->save();",
				],
				'delete_record' => [
					'keywords' => ['delete', 'remove', 'destroy'],
					'template' => "User::find(1)->delete()\n// Or:\nUser::destroy(1)\n// Or multiple:\nUser::where('active', false)->delete()",
				],
				'count_records' => [
					'keywords' => ['count', 'how many', 'number of'],
					'template' => "User::count()\n// Or with conditions:\nUser::where('active', true)->count()",
				],
				'relationships' => [
					'keywords' => ['relationship', 'relation', 'related', 'belongs to', 'has many'],
					'template' => "// Access relationships:\n\$user = User::find(1);\n\$user->posts  // hasMany\n\$user->profile  // hasOne\n\n// Eager loading:\nUser::with('posts')->get()\n\n// Query relationships:\nUser::whereHas('posts', function(\$q) {\n    \$q->where('published', true);\n})->get()",
				],
				'query_builder' => [
					'keywords' => ['query', 'where', 'filter', 'search'],
					'template' => "User::where('name', 'John')\n    ->where('active', true)\n    ->orderBy('created_at', 'desc')\n    ->get()\n\n// Or with orWhere:\nUser::where('name', 'John')\n    ->orWhere('email', 'john@example.com')\n    ->get()",
				],
				'artisan_commands' => [
					'keywords' => ['artisan', 'command', 'php artisan'],
					'template' => "// Common Artisan commands:\nArtisan::call('migrate')\nArtisan::call('cache:clear')\nArtisan::call('route:list')\nArtisan::call('make:controller', ['name' => 'UserController'])",
				],
				'collections' => [
					'keywords' => ['collection', 'map', 'filter', 'reduce', 'pluck', 'groupby', 'group by'],
					'template' => "// Collection methods:\n\$users = User::all();\n\n// Map - transform each item\n\$names = \$users->map(function(\$user) {\n    return \$user->name;\n});\n\n// Filter - filter items\n\$active = \$users->filter(function(\$user) {\n    return \$user->active;\n});\n\n// Pluck - extract single column\n\$emails = \$users->pluck('email');\n\n// Group by\n\$grouped = \$users->groupBy('status');\n\n// Reduce - reduce to single value\n\$total = \$users->reduce(function(\$carry, \$user) {\n    return \$carry + \$user->amount;\n}, 0);",
				],
				'events' => [
					'keywords' => ['event', 'dispatch', 'listen', 'fire', 'observer'],
					'template' => "// Dispatch an event:\nevent(new UserCreated(\$user));\n// Or:\nUserCreated::dispatch(\$user);\n\n// Listen to events (in EventServiceProvider):\nprotected \$listen = [\n    UserCreated::class => [\n        SendWelcomeEmail::class,\n    ],\n];\n\n// Fire event manually:\nEvent::fire(new UserCreated(\$user));",
				],
				'jobs' => [
					'keywords' => ['job', 'queue', 'dispatch job', 'background'],
					'template' => "// Dispatch a job:\nProcessPayment::dispatch(\$order);\n\n// Dispatch with delay:\nProcessPayment::dispatch(\$order)->delay(now()->addMinutes(10));\n\n// Dispatch to specific queue:\nProcessPayment::dispatch(\$order)->onQueue('high-priority');\n\n// Chain jobs:\nProcessPayment::withChain([\n    new SendNotification,\n    new UpdateStatus,\n])->dispatch(\$order);",
				],
				'queues' => [
					'keywords' => ['queue', 'push', 'later', 'bulk queue'],
					'template' => "// Push to queue:\nQueue::push(new ProcessPayment(\$order));\n\n// Push with delay:\nQueue::later(now()->addMinutes(5), new ProcessPayment(\$order));\n\n// Bulk push:\nQueue::bulk([\n    new ProcessPayment(\$order1),\n    new ProcessPayment(\$order2),\n]);",
				],
				'middleware' => [
					'keywords' => ['middleware', 'create middleware', 'register middleware'],
					'template' => "// Create middleware:\nphp artisan make:middleware CheckAge\n\n// Register in Kernel.php:\nprotected \$middlewareGroups = [\n    'web' => [\n        // ...\n        \\App\\Http\\Middleware\\CheckAge::class,\n    ],\n];\n\n// Apply to routes:\nRoute::get('profile', function () {\n    //\n})->middleware('age');",
				],
				'validation' => [
					'keywords' => ['validate', 'validation', 'rules', 'validator'],
					'template' => "// Validate request:\n\$request->validate([\n    'name' => 'required|string|max:255',\n    'email' => 'required|email|unique:users',\n]);\n\n// Custom validation rules:\nValidator::make(\$data, [\n    'email' => ['required', 'email', new CustomRule],\n]);\n\n// Validation in model:\nprotected \$rules = [\n    'name' => 'required',\n];",
				],
				'form_requests' => [
					'keywords' => ['form request', 'request validation', 'create request'],
					'template' => "// Create form request:\nphp artisan make:request StoreUserRequest\n\n// In StoreUserRequest.php:\npublic function rules()\n{\n    return [\n        'name' => 'required|string',\n        'email' => 'required|email|unique:users',\n    ];\n}\n\n// Use in controller:\npublic function store(StoreUserRequest \$request)\n{\n    // Request is already validated\n}",
				],
				'policies' => [
					'keywords' => ['policy', 'authorize', 'can', 'cannot', 'gate'],
					'template' => "// Create policy:\nphp artisan make:policy PostPolicy\n\n// Check authorization:\n\$this->authorize('update', \$post);\n\n// Using Gate:\nGate::allows('update-post', \$post);\nGate::denies('update-post', \$post);\n\n// Using can():\nif (auth()->user()->can('update', \$post)) {\n    //\n}",
				],
				'factories' => [
					'keywords' => ['factory', 'model factory', 'make factory', 'create factory'],
					'template' => "// Using factory:\nUser::factory()->make();\nUser::factory()->create();\n\n// With attributes:\nUser::factory()->make(['name' => 'John']);\n\n// Create multiple:\nUser::factory()->count(10)->create();\n\n// Raw factory:\nUser::factory()->raw(['name' => 'John']);",
				],
				'seeders' => [
					'keywords' => ['seeder', 'seed', 'database seed'],
					'template' => "// Create seeder:\nphp artisan make:seeder UserSeeder\n\n// Run seeder:\nphp artisan db:seed\nphp artisan db:seed --class=UserSeeder\n\n// In seeder:\nUser::factory()->count(10)->create();\n\n// Call other seeders:\n\$this->call([\n    UserSeeder::class,\n    PostSeeder::class,\n]);",
				],
				'migrations' => [
					'keywords' => ['migration', 'create migration', 'migrate', 'rollback'],
					'template' => "// Create migration:\nphp artisan make:migration create_users_table\n\n// Run migrations:\nphp artisan migrate\n\n// Rollback:\nphp artisan migrate:rollback\nphp artisan migrate:rollback --step=3\n\n// Reset:\nphp artisan migrate:reset\n\n// Refresh:\nphp artisan migrate:refresh",
				],
				'routes' => [
					'keywords' => ['route', 'routes', 'get route', 'post route', 'resource route'],
					'template' => "// Basic routes:\nRoute::get('/users', [UserController::class, 'index']);\nRoute::post('/users', [UserController::class, 'store']);\n\n// Resource routes:\nRoute::resource('users', UserController::class);\n\n// API resource:\nRoute::apiResource('users', UserController::class);\n\n// Route groups:\nRoute::prefix('admin')->group(function () {\n    Route::resource('users', UserController::class);\n});",
				],
				'cache' => [
					'keywords' => ['cache', 'remember', 'forget cache', 'cache put', 'cache get'],
					'template' => "// Remember (get or store):\n\$value = Cache::remember('key', 3600, function () {\n    return DB::table('users')->get();\n});\n\n// Put:\nCache::put('key', 'value', 3600);\n\n// Get:\n\$value = Cache::get('key');\n\n// Forget:\nCache::forget('key');\n\n// Clear all:\nCache::flush();",
				],
				'session' => [
					'keywords' => ['session', 'session put', 'session get', 'session flash'],
					'template' => "// Put session:\nSession::put('key', 'value');\nsession(['key' => 'value']);\n\n// Get session:\n\$value = Session::get('key');\nsession('key');\n\n// Flash (one-time):\nSession::flash('message', 'Success!');\n\n// Forget:\nSession::forget('key');\n\n// Clear all:\nSession::flush();",
				],
				'mail' => [
					'keywords' => ['mail', 'send email', 'mail send', 'mail queue'],
					'template' => "// Send mail:\nMail::to(\$user->email)->send(new WelcomeMail(\$user));\n\n// Queue mail:\nMail::to(\$user->email)->queue(new WelcomeMail(\$user));\n\n// Later:\nMail::to(\$user->email)->later(now()->addMinutes(5), new WelcomeMail(\$user));\n\n// Raw mail:\nMail::raw('Text content', function (\$message) {\n    \$message->to('user@example.com')->subject('Subject');\n});",
				],
				'notifications' => [
					'keywords' => ['notification', 'notify', 'send notification'],
					'template' => "// Send notification:\n\$user->notify(new InvoicePaid(\$invoice));\n\n// Using Notification facade:\nNotification::send(\$users, new InvoicePaid(\$invoice));\n\n// Send to specific channels:\n\$user->notify((new InvoicePaid(\$invoice))->via('mail'));\n\n// Queue notification:\n\$user->notify(new InvoicePaid(\$invoice));\n// (Enable in notification class)",
				],
			],
			'explanations' => [
				'eloquent' => 'Eloquent is Laravel\'s ORM (Object-Relational Mapping) that provides an ActiveRecord implementation for working with your database.',
				'tinker' => 'Tinker is Laravel\'s REPL (Read-Eval-Print Loop) that allows you to interact with your Laravel application from the command line. You can use it via `php artisan tinker` or in this Overlord terminal console.',
				'migration' => 'Migrations are like version control for your database, allowing you to modify your database structure in a versioned, controlled manner.',
				'collection' => 'Collections are Laravel\'s powerful wrapper for working with arrays of data, providing fluent methods for filtering, mapping, and transforming data.',
				'event' => 'Events allow you to decouple various aspects of your application. When an event is dispatched, all of its registered listeners are executed.',
				'job' => 'Jobs are classes that encapsulate work that should be performed outside of the current request cycle, typically queued for background processing.',
				'middleware' => 'Middleware provides a convenient mechanism for filtering HTTP requests entering your application, such as authentication or CSRF protection.',
				'validation' => 'Laravel provides several different approaches to validate your application\'s incoming data, from simple rules to complex custom validators.',
				'policy' => 'Policies are classes that organize authorization logic around a particular model or resource, determining what actions a user can perform.',
			],
		];
	}

	/**
	 * Check if AI is enabled
	 */
	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * Check if AI is available (always true for local implementation)
	 */
	public function isAvailable(): bool
	{
		return $this->isEnabled();
	}

	/**
	 * Get list of available models (local only)
	 */
	public function getAvailableModels(): array
	{
		if (!$this->isEnabled()) {
			return [];
		}

		return [
			[
				'name' => 'local',
				'size' => 0,
				'modified_at' => null,
			],
		];
	}

	/**
	 * Check if a specific model is available
	 */
	public function isModelAvailable(string $modelName): bool
	{
		return $modelName === 'local' && $this->isEnabled();
	}

	/**
	 * Send a chat message to the AI
	 */
	public function chat(string $message, array $conversationHistory = [], ?string $model = null, ?array $logContext = null): array
	{
		if (!$this->isEnabled()) {
			return [
				'success' => false,
				'error' => 'AI features are disabled',
			];
		}

		try {
			// Analyze the user's message
			$response = $this->generateResponse($message, $conversationHistory);

			return [
				'success' => true,
				'message' => $response,
				'model' => 'local',
			];
		} catch (\Exception $e) {
			Log::error('AI chat request failed', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);

			return [
				'success' => false,
				'error' => 'Failed to generate response: ' . $e->getMessage(),
			];
		}
	}

	/**
	 * Extract context from conversation history
	 */
	protected function extractContextFromHistory(array $conversationHistory): array
	{
		$context = [
			'mentioned_models' => [],
			'mentioned_relationships' => [],
			'mentioned_operations' => [],
			'recent_topics' => [],
		];

		if (empty($conversationHistory)) {
			return $context;
		}

		// Get last N messages for context
		$recentMessages = array_slice($conversationHistory, -$this->contextWindow);

		foreach ($recentMessages as $message) {
			$content = strtolower($message['content'] ?? '');

			// Extract mentioned models
			$models = $this->getMentionedModels($content);
			$context['mentioned_models'] = array_merge($context['mentioned_models'], $models);

			// Extract relationships
			$relationships = $this->extractMentionedRelationships($content);
			$context['mentioned_relationships'] = array_merge($context['mentioned_relationships'], $relationships);

			// Extract operations
			$operations = $this->extractMentionedOperations($content);
			$context['mentioned_operations'] = array_merge($context['mentioned_operations'], $operations);
		}

		// Remove duplicates and keep unique values
		$context['mentioned_models'] = array_unique($context['mentioned_models']);
		$context['mentioned_relationships'] = array_unique($context['mentioned_relationships']);
		$context['mentioned_operations'] = array_unique($context['mentioned_operations']);

		return $context;
	}

	/**
	 * Get mentioned model names from text
	 */
	protected function getMentionedModels(string $text): array
	{
		$models = [];
		$availableModels = $this->getAvailableCodebaseModels();

		foreach ($availableModels as $modelName => $fullClassName) {
			$modelNameLower = strtolower($modelName);

			// Check if model name is mentioned
			if (preg_match('/\b' . preg_quote($modelNameLower, '/') . '\b/i', $text)) {
				$models[] = $modelName;
			}
		}

		return $models;
	}

	/**
	 * Extract mentioned relationships from text
	 */
	protected function extractMentionedRelationships(string $text): array
	{
		$relationships = [];
		$relationshipKeywords = ['hasmany', 'has many', 'belongsto', 'belongs to', 'belongstomany', 'belongs to many', 'hasone', 'has one'];

		foreach ($relationshipKeywords as $keyword) {
			if (strpos($text, $keyword) !== false) {
				$relationships[] = $keyword;
			}
		}

		return $relationships;
	}

	/**
	 * Extract mentioned operations from text
	 */
	protected function extractMentionedOperations(string $text): array
	{
		$operations = [];
		$operationKeywords = ['create', 'update', 'delete', 'get', 'find', 'query', 'count', 'paginate'];

		foreach ($operationKeywords as $keyword) {
			if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $text)) {
				$operations[] = $keyword;
			}
		}

		return $operations;
	}

	/**
	 * Build contextual prompt with conversation history
	 */
	protected function buildContextualPrompt(string $message, array $context): string
	{
		$prompt = $message;

		// Add context about mentioned models
		if (!empty($context['mentioned_models'])) {
			$models = implode(', ', array_unique($context['mentioned_models']));
			$prompt .= "\n\nContext: The user has been working with these models: {$models}";
		}

		return $prompt;
	}

	/**
	 * Remember context for next message
	 */
	protected function rememberContext(string $message, string $response): void
	{
		// Store in context cache (last 10 interactions)
		$this->contextCache[] = [
			'message' => $message,
			'response' => $response,
			'timestamp' => now(),
		];

		// Keep only last 10
		if (count($this->contextCache) > 10) {
			array_shift($this->contextCache);
		}
	}

	/**
	 * Generate response based on user message
	 */
	protected function generateResponse(string $message, array $conversationHistory = []): string
	{
		// Extract context from conversation history
		$context = $this->extractContextFromHistory($conversationHistory);

		$messageLower = strtolower($message);

		// If user is asking about an error, don't give generic code examples
		if (
			strpos($messageLower, 'debug this error') !== false ||
			strpos($messageLower, 'this error') !== false ||
			(strpos($messageLower, 'error') !== false && (strpos($messageLower, 'what') !== false || strpos($messageLower, 'why') !== false || strpos($messageLower, 'how') !== false))
		) {

			// User is asking about an error - don't give generic code examples
			return "I'm currently operating in fallback mode due to API rate limits. For detailed error analysis, please wait for the rate limit to reset or try again later when the full AI service is available.";
		}

		// Use fuzzy matching to find best pattern
		$bestMatch = $this->findBestPatternMatch($message);

		if ($bestMatch) {
			$code = $bestMatch['pattern']['template'];

			// Try to replace User with actual model from context or query
			$code = $this->replaceModelInCode($code, $message, $context);

			$response = $this->formatResponse($code, $bestMatch['name']);
			$this->rememberContext($message, $response);
			return $response;
		}

		// Check for explanation requests
		foreach ($this->knowledgeBase['explanations'] as $term => $explanation) {
			if (strpos(strtolower($message), $term) !== false) {
				$response = $this->formatExplanation($term, $explanation);
				$this->rememberContext($message, $response);
				return $response;
			}
		}

		// Generate contextual response based on message intent
		$response = $this->generateContextualResponse($message, strtolower($message), $context);
		$this->rememberContext($message, $response);
		return $response;
	}

	/**
	 * Format code response
	 */
	protected function formatResponse(string $code, string $patternName): string
	{
		$explanations = [
			'get_all' => 'Here are some ways to retrieve all records:',
			'find_by_id' => 'Here are ways to find a record by ID:',
			'create_record' => 'Here are ways to create a new record:',
			'update_record' => 'Here are ways to update a record:',
			'delete_record' => 'Here are ways to delete a record:',
			'count_records' => 'Here are ways to count records:',
			'relationships' => 'Here are ways to work with Eloquent relationships:',
			'query_builder' => 'Here are examples of querying with Eloquent:',
			'artisan_commands' => 'Here are some common Artisan commands you can run:',
			'collections' => 'Here are ways to work with Laravel Collections:',
			'events' => 'Here are ways to work with Laravel Events:',
			'jobs' => 'Here are ways to work with Laravel Jobs:',
			'queues' => 'Here are ways to work with Laravel Queues:',
			'middleware' => 'Here are ways to work with Laravel Middleware:',
			'validation' => 'Here are ways to validate data in Laravel:',
			'form_requests' => 'Here are ways to use Form Requests:',
			'policies' => 'Here are ways to work with Laravel Policies:',
			'factories' => 'Here are ways to use Model Factories:',
			'seeders' => 'Here are ways to work with Database Seeders:',
			'migrations' => 'Here are ways to work with Database Migrations:',
			'routes' => 'Here are ways to define routes in Laravel:',
			'cache' => 'Here are ways to work with Laravel Cache:',
			'session' => 'Here are ways to work with Laravel Session:',
			'mail' => 'Here are ways to send emails in Laravel:',
			'notifications' => 'Here are ways to send notifications in Laravel:',
		];

		$explanation = $explanations[$patternName] ?? 'Here is the code:';

		return $explanation . "\n\n```php\n" . $code . "\n```\n\nYou can copy and paste this code directly into the terminal.";
	}

	/**
	 * Format explanation response
	 */
	protected function formatExplanation(string $term, string $explanation): string
	{
		return "**" . ucfirst($term) . "**\n\n" . $explanation;
	}

	/**
	 * Replace User model in code with actual model from context or query
	 */
	protected function replaceModelInCode(string $code, string $message, array $context): string
	{
		// Try to find model from context first
		$modelName = null;

		if (!empty($context['mentioned_models'])) {
			$modelName = $context['mentioned_models'][0]; // Use first mentioned model
		} else {
			// Try to extract model name from message
			$suggestedModel = $this->suggestModelName($message);
			if ($suggestedModel) {
				$modelName = $suggestedModel;
			}
		}

		// If we found a model, replace User with it
		if ($modelName && $modelName !== 'User') {
			$code = str_replace('User::', $modelName . '::', $code);
			$code = str_replace('User()', $modelName . '()', $code);
			$code = str_replace('new User()', 'new ' . $modelName . '()', $code);
			$code = str_replace('$user', '$' . strtolower($modelName), $code);
			$code = str_replace('User ', $modelName . ' ', $code);
		}

		return $code;
	}

	/**
	 * Generate code for a specific model and operation
	 */
	protected function generateModelCode(string $modelName, string $operation, array $context = []): ?string
	{
		$modelInfo = $this->getModelInfo($modelName);

		if (!$modelInfo) {
			return null;
		}

		$code = '';

		switch (strtolower($operation)) {
			case 'get':
			case 'all':
			case 'list':
				$code = "{$modelName}::all()";
				if (!empty($modelInfo['relationships'])) {
					$relNames = array_keys(array_slice($modelInfo['relationships'], 0, 2));
					if (!empty($relNames)) {
						$code .= "\n// Or with eager loading:\n{$modelName}::with('" . implode("', '", $relNames) . "')->get()";
					}
				}
				break;

			case 'create':
				$fillable = $modelInfo['fillable'] ?? [];
				if (!empty($fillable)) {
					$exampleData = [];
					foreach (array_slice($fillable, 0, 3) as $attr) {
						$exampleData[$attr] = $this->getExampleValueForAttribute($attr, $modelInfo);
					}
					$code = "{$modelName}::create([\n";
					foreach ($exampleData as $key => $value) {
						$code .= "    '{$key}' => {$value},\n";
					}
					$code .= "])";
				} else {
					$code = "{$modelName}::create([\n    // Add your attributes here\n])";
				}
				break;

			case 'find':
			case 'get by id':
				$code = "{$modelName}::find(1)";
				if (!empty($modelInfo['relationships']) && count($modelInfo['relationships']) > 0) {
					$relName = array_keys($modelInfo['relationships'])[0];
					$code .= "\n// Or with relationship:\n{$modelName}::with('{$relName}')->find(1)";
				}
				break;

			case 'update':
				$code = "\$" . strtolower($modelName) . " = {$modelName}::find(1);\n";
				$fillable = $modelInfo['fillable'] ?? [];
				if (!empty($fillable)) {
					$attr = $fillable[0];
					$code .= "\$" . strtolower($modelName) . "->{$attr} = 'new value';\n";
				}
				$code .= "\$" . strtolower($modelName) . "->save();";
				break;

			case 'delete':
				$code = "{$modelName}::find(1)->delete()";
				break;
		}

		return $code;
	}

	/**
	 * Get example value for an attribute based on its name and type
	 */
	protected function getExampleValueForAttribute(string $attr, array $modelInfo): string
	{
		$attrLower = strtolower($attr);
		$cast = $modelInfo['casts'][$attr] ?? null;

		// Check attribute name patterns
		if (strpos($attrLower, 'email') !== false) {
			return "'john@example.com'";
		}
		if (strpos($attrLower, 'name') !== false) {
			return "'John Doe'";
		}
		if (strpos($attrLower, 'title') !== false) {
			return "'Example Title'";
		}
		if (strpos($attrLower, 'description') !== false || strpos($attrLower, 'content') !== false) {
			return "'Example description'";
		}
		if (strpos($attrLower, 'active') !== false || strpos($attrLower, 'enabled') !== false) {
			return 'true';
		}
		if (strpos($attrLower, 'amount') !== false || strpos($attrLower, 'price') !== false || strpos($attrLower, 'cost') !== false) {
			return '100.00';
		}
		if (strpos($attrLower, 'count') !== false || strpos($attrLower, 'quantity') !== false) {
			return '1';
		}

		// Check casts
		if ($cast) {
			if (strpos($cast, 'boolean') !== false) {
				return 'true';
			}
			if (strpos($cast, 'integer') !== false || strpos($cast, 'int') !== false) {
				return '1';
			}
			if (strpos($cast, 'float') !== false || strpos($cast, 'decimal') !== false) {
				return '100.00';
			}
			if (strpos($cast, 'array') !== false || strpos($cast, 'json') !== false) {
				return "['key' => 'value']";
			}
		}

		return "'value'";
	}

	/**
	 * Generate contextual response for general queries
	 */
	protected function generateContextualResponse(string $message, string $messageLower, array $context = []): string
	{
		// Check for question words
		if (preg_match('/\b(how|what|why|when|where|can|should|would)\b/i', $message)) {
			return $this->generateHelpfulResponse($message, $messageLower, $context);
		}

		// Check for code generation requests
		if (preg_match('/\b(generate|create|write|make|build|code|script)\b/i', $message)) {
			return $this->generateCodeSuggestion($message, $messageLower, $context);
		}

		// Default helpful response
		return $this->getDefaultHelpResponse($message, $context);
	}

	/**
	 * Generate helpful response for questions
	 */
	protected function generateHelpfulResponse(string $message, string $messageLower, array $context = []): string
	{
		// Try to use model from context
		$modelName = !empty($context['mentioned_models']) ? $context['mentioned_models'][0] : null;
		if (!$modelName) {
			$modelName = $this->suggestModelName($message) ?? 'User';
		}

		if (strpos($messageLower, 'how to') !== false || strpos($messageLower, 'how do') !== false) {
			if (strpos($messageLower, 'model') !== false) {
				$code = $this->generateModelCode($modelName, 'get');
				if ($code) {
					return "To work with models in the terminal:\n\n```php\n// Get all records\n{$code}\n\n// Find by ID\n{$modelName}::find(1)\n\n// Create a new record\n" .
						($this->generateModelCode($modelName, 'create') ?? "{$modelName}::create([...])") .
						"\n\n// Update a record\n\$" . strtolower($modelName) . " = {$modelName}::find(1);\n\$" . strtolower($modelName) . "->save();\n\n// Delete a record\n{$modelName}::find(1)->delete();\n```";
				}
				return "To work with models in the terminal:\n\n```php\n// Get all records\n{$modelName}::all()\n\n// Find by ID\n{$modelName}::find(1)\n\n// Create a new record\n{$modelName}::create([...])\n\n// Update a record\n\$" . strtolower($modelName) . " = {$modelName}::find(1);\n\$" . strtolower($modelName) . "->save();\n\n// Delete a record\n{$modelName}::find(1)->delete();\n```";
			}

			if (strpos($messageLower, 'query') !== false || strpos($messageLower, 'database') !== false) {
				return "To query the database in Laravel:\n\n```php\n// Basic query\n{$modelName}::where('active', true)->get()\n\n// With multiple conditions\n{$modelName}::where('name', 'John')\n    ->where('active', true)\n    ->get()\n\n// Order by\n{$modelName}::orderBy('created_at', 'desc')->get()\n\n// Limit results\n{$modelName}::take(10)->get()\n```";
			}
		}

		return "I can help you with Laravel and PHP questions. Try asking:\n- How to query models\n- How to create/update/delete records\n- How to use relationships\n- How to run Artisan commands\n\nOr ask me to generate code for a specific task!";
	}

	/**
	 * Generate code suggestion
	 */
	protected function generateCodeSuggestion(string $message, string $messageLower, array $context = []): string
	{
		// Try to find model from context or message
		$modelName = !empty($context['mentioned_models']) ? $context['mentioned_models'][0] : null;
		if (!$modelName) {
			$modelName = $this->suggestModelName($message) ?? 'User';
		}

		// Try to determine operation
		$operation = null;
		if (strpos($messageLower, 'get all') !== false || strpos($messageLower, 'list') !== false) {
			$operation = 'get';
		} elseif (strpos($messageLower, 'create') !== false || strpos($messageLower, 'new') !== false) {
			$operation = 'create';
		} elseif (strpos($messageLower, 'find') !== false || strpos($messageLower, 'get by') !== false) {
			$operation = 'find';
		} elseif (strpos($messageLower, 'update') !== false) {
			$operation = 'update';
		} elseif (strpos($messageLower, 'delete') !== false) {
			$operation = 'delete';
		}

		if ($operation) {
			$code = $this->generateModelCode($modelName, $operation, $context);
			if ($code) {
				return "Here's code for working with {$modelName}:\n\n```php\n{$code}\n```";
			}
		}

		if (strpos($messageLower, 'user') !== false || strpos($messageLower, 'model') !== false) {
			$code = $this->generateModelCode($modelName, 'get');
			if ($code) {
				return "Here's a basic example for working with {$modelName}:\n\n```php\n// Get all records\n{$code}\n\n// Find a specific record\n{$modelName}::find(1)\n\n// Create a new record\n" .
					($this->generateModelCode($modelName, 'create') ?? "{$modelName}::create([...])") .
					"\n\n// Update record\n\$" . strtolower($modelName) . " = {$modelName}::find(1);\n\$" . strtolower($modelName) . "->save();\n\n// Delete record\n{$modelName}::find(1)->delete();\n```";
			}
		}

		return "I can help generate code! Try being more specific, for example:\n- \"Generate code to get all {$modelName}\"\n- \"Create code to find a {$modelName} by id\"\n- \"Write code to update a record\"\n\nOr use one of the common patterns I know about!";
	}

	/**
	 * Get default help response
	 */
	protected function getDefaultHelpResponse(string $message, array $context = []): string
	{
		$availableModels = $this->getAvailableCodebaseModels();
		$modelList = !empty($availableModels) ? implode(', ', array_slice(array_keys($availableModels), 0, 5)) : 'User';

		return "I'm here to help with Laravel and PHP development! I can:\n\n" .
			"• Generate code snippets for common tasks\n" .
			"• Explain Laravel concepts\n" .
			"• Help with Eloquent queries\n" .
			"• Assist with terminal commands\n" .
			"• Work with your actual codebase models\n\n" .
			"Available models in your codebase: {$modelList}" . (count($availableModels) > 5 ? '...' : '') . "\n\n" .
			"Try asking:\n" .
			"- \"How to get all records?\"\n" .
			"- \"Generate code to create a new record\"\n" .
			"- \"What is Eloquent?\"\n" .
			"- \"Show me how to query with conditions\"";
	}

	/**
	 * Get the default model name
	 */
	public function getDefaultModel(): string
	{
		return $this->defaultModel;
	}

	/**
	 * Get the base URL (not applicable for local)
	 */
	public function getBaseUrl(): string
	{
		return 'local';
	}

	/**
	 * Get model discovery service (lazy-loaded)
	 */
	protected function getModelDiscovery(): ModelDiscovery
	{
		if ($this->modelDiscovery === null) {
			$this->modelDiscovery = new ModelDiscovery();
		}
		return $this->modelDiscovery;
	}

	/**
	 * Get controller discovery service (lazy-loaded)
	 */
	protected function getControllerDiscovery(): ControllerDiscovery
	{
		if ($this->controllerDiscovery === null) {
			$this->controllerDiscovery = new ControllerDiscovery();
		}
		return $this->controllerDiscovery;
	}

	/**
	 * Get class discovery service (lazy-loaded)
	 */
	protected function getClassDiscovery(): ClassDiscovery
	{
		if ($this->classDiscovery === null) {
			$this->classDiscovery = new ClassDiscovery();
		}
		return $this->classDiscovery;
	}

	/**
	 * Get all available models from codebase
	 */
	public function getAvailableCodebaseModels(): array
	{
		try {
			return $this->getModelDiscovery()->getModelClasses();
		} catch (\Exception $e) {
			Log::warning('Failed to get codebase models', ['error' => $e->getMessage()]);
			return [];
		}
	}

	/**
	 * Get information about a specific model
	 */
	public function getModelInfo(string $modelName): ?array
	{
		$models = $this->getAvailableCodebaseModels();

		// Try exact match first
		if (isset($models[$modelName])) {
			$fullClassName = $models[$modelName];
			return $this->extractModelInfo($fullClassName);
		}

		// Try case-insensitive match
		foreach ($models as $name => $fullClassName) {
			if (strtolower($name) === strtolower($modelName)) {
				return $this->extractModelInfo($fullClassName);
			}
		}

		return null;
	}

	/**
	 * Extract detailed information about a model using reflection
	 */
	protected function extractModelInfo(string $fullClassName): array
	{
		$cacheKey = 'ai_model_info_' . md5($fullClassName);

		return Cache::remember($cacheKey, now()->addHours(24), function () use ($fullClassName) {
			try {
				if (!class_exists($fullClassName)) {
					return null;
				}

				$reflection = new \ReflectionClass($fullClassName);
				$model = new $fullClassName();

				$info = [
					'name' => class_basename($fullClassName),
					'fullName' => $fullClassName,
					'table' => $model->getTable(),
					'fillable' => $model->getFillable(),
					'guarded' => $model->getGuarded(),
					'casts' => $model->getCasts(),
					'attributes' => $this->extractModelAttributes($reflection, $model),
					'relationships' => $this->extractModelRelationships($reflection, $model),
					'methods' => $this->extractModelMethods($reflection),
				];

				return $info;
			} catch (\Exception $e) {
				Log::warning('Failed to extract model info', [
					'model' => $fullClassName,
					'error' => $e->getMessage(),
				]);
				return null;
			}
		});
	}

	/**
	 * Extract model attributes (fillable, guarded, casts)
	 */
	protected function extractModelAttributes(\ReflectionClass $reflection, $model): array
	{
		$attributes = [];

		// Get fillable attributes
		$fillable = $model->getFillable();
		foreach ($fillable as $attr) {
			$attributes[$attr] = [
				'fillable' => true,
				'guarded' => false,
				'cast' => $model->getCasts()[$attr] ?? null,
			];
		}

		// Get guarded attributes
		$guarded = $model->getGuarded();
		foreach ($guarded as $attr) {
			if ($attr !== '*') {
				if (!isset($attributes[$attr])) {
					$attributes[$attr] = [
						'fillable' => false,
						'guarded' => true,
						'cast' => $model->getCasts()[$attr] ?? null,
					];
				} else {
					$attributes[$attr]['guarded'] = true;
				}
			}
		}

		return $attributes;
	}

	/**
	 * Extract model relationships
	 */
	protected function extractModelRelationships(\ReflectionClass $reflection, $model): array
	{
		$relationships = [];
		$methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		foreach ($methods as $method) {
			$methodName = $method->getName();

			// Skip magic methods and non-relationship methods
			if (strpos($methodName, '__') === 0 || $method->getNumberOfParameters() > 0) {
				continue;
			}

			try {
				$returnType = $method->getReturnType();
				if ($returnType && $returnType->getName() !== 'void') {
					$returnTypeName = $returnType->getName();

					// Check if it's a relationship type
					if (
						strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relations') !== false ||
						strpos($returnTypeName, 'Illuminate\\Database\\Eloquent\\Relation') !== false
					) {

						// Try to get the relationship
						try {
							$relation = $model->$methodName();
							if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
								$relatedModel = get_class($relation->getRelated());
								$relationType = $this->getRelationshipType($relation);

								$relationships[$methodName] = [
									'type' => $relationType,
									'related' => class_basename($relatedModel),
									'relatedFull' => $relatedModel,
								];
							}
						} catch (\Exception $e) {
							// Skip if relationship can't be instantiated
							continue;
						}
					}
				}
			} catch (\Exception $e) {
				continue;
			}
		}

		return $relationships;
	}

	/**
	 * Get relationship type from relation instance
	 */
	protected function getRelationshipType($relation): string
	{
		$class = get_class($relation);

		if (strpos($class, 'HasOne') !== false) {
			return 'hasOne';
		} elseif (strpos($class, 'HasMany') !== false) {
			return 'hasMany';
		} elseif (strpos($class, 'BelongsTo') !== false) {
			return 'belongsTo';
		} elseif (strpos($class, 'BelongsToMany') !== false) {
			return 'belongsToMany';
		} elseif (strpos($class, 'MorphTo') !== false) {
			return 'morphTo';
		} elseif (strpos($class, 'MorphMany') !== false) {
			return 'morphMany';
		} elseif (strpos($class, 'MorphOne') !== false) {
			return 'morphOne';
		} elseif (strpos($class, 'HasManyThrough') !== false) {
			return 'hasManyThrough';
		}

		return 'unknown';
	}

	/**
	 * Extract model methods
	 */
	protected function extractModelMethods(\ReflectionClass $reflection): array
	{
		$methods = [];
		$modelMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

		foreach ($modelMethods as $method) {
			$methodName = $method->getName();

			// Skip magic methods
			if (strpos($methodName, '__') === 0) {
				continue;
			}

			// Skip if from parent class
			if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
				continue;
			}

			$methods[] = [
				'name' => $methodName,
				'parameters' => array_map(function ($param) {
					return [
						'name' => $param->getName(),
						'type' => $param->getType() ? $param->getType()->getName() : null,
						'hasDefault' => $param->isDefaultValueAvailable(),
					];
				}, $method->getParameters()),
			];
		}

		return $methods;
	}

	/**
	 * Get all controllers from codebase
	 */
	public function getAvailableControllers(): array
	{
		try {
			return $this->getControllerDiscovery()->getControllers();
		} catch (\Exception $e) {
			Log::warning('Failed to get codebase controllers', ['error' => $e->getMessage()]);
			return [];
		}
	}

	/**
	 * Get information about a specific controller
	 */
	public function getControllerInfo(string $controllerName): ?array
	{
		$controllers = $this->getAvailableControllers();

		foreach ($controllers as $controller) {
			if (
				strtolower($controller['name']) === strtolower($controllerName) ||
				strtolower(class_basename($controller['fullName'])) === strtolower($controllerName)
			) {
				return $controller;
			}
		}

		return null;
	}

	/**
	 * Suggest model name from query using fuzzy matching
	 */
	public function suggestModelName(string $query): ?string
	{
		$models = $this->getAvailableCodebaseModels();
		$queryLower = strtolower($query);
		$bestMatch = null;
		$bestScore = 0;

		foreach ($models as $modelName => $fullClassName) {
			$modelNameLower = strtolower($modelName);

			// Exact match
			if ($modelNameLower === $queryLower) {
				return $modelName;
			}

			// Check if query contains model name or vice versa
			if (
				strpos($queryLower, $modelNameLower) !== false ||
				strpos($modelNameLower, $queryLower) !== false
			) {
				$score = $this->calculateSimilarity($queryLower, $modelNameLower);
				if ($score > $bestScore && $score >= $this->fuzzyMatchingThreshold) {
					$bestScore = $score;
					$bestMatch = $modelName;
				}
			}
		}

		return $bestMatch;
	}

	/**
	 * Calculate similarity between two strings using Levenshtein distance
	 */
	protected function calculateSimilarity(string $text1, string $text2): float
	{
		$maxLength = max(strlen($text1), strlen($text2));
		if ($maxLength === 0) {
			return 1.0;
		}

		$distance = levenshtein($text1, $text2);
		$similarity = 1 - ($distance / $maxLength);

		return max(0.0, min(1.0, $similarity));
	}

	/**
	 * Score pattern match based on keyword relevance
	 */
	protected function scorePatternMatch(string $message, array $pattern): float
	{
		$messageLower = strtolower($message);
		$bestScore = 0.0;

		foreach ($pattern['keywords'] as $keyword) {
			$keywordLower = strtolower($keyword);

			// Exact match gets highest score
			if ($messageLower === $keywordLower) {
				return 1.0;
			}

			// Exact substring match
			if (strpos($messageLower, $keywordLower) !== false) {
				$score = strlen($keywordLower) / strlen($messageLower);
				$bestScore = max($bestScore, $score);
			} else {
				// Fuzzy match
				$similarity = $this->calculateSimilarity($messageLower, $keywordLower);
				if ($similarity >= $this->fuzzyMatchingThreshold) {
					$bestScore = max($bestScore, $similarity * 0.7); // Penalize fuzzy matches
				}
			}
		}

		return $bestScore;
	}

	/**
	 * Find best pattern match using fuzzy matching
	 */
	protected function findBestPatternMatch(string $message): ?array
	{
		$messageLower = strtolower($message);
		$bestMatch = null;
		$bestScore = 0.0;

		foreach ($this->knowledgeBase['patterns'] as $patternName => $pattern) {
			$score = $this->scorePatternMatch($message, $pattern);

			if ($score > $bestScore && $score >= $this->fuzzyMatchingThreshold) {
				$bestScore = $score;
				$bestMatch = [
					'name' => $patternName,
					'pattern' => $pattern,
					'score' => $score,
				];
			}
		}

		return $bestMatch;
	}

	/**
	 * Read and parse a PHP code file
	 */
	protected function readCodeFile(string $filePath): ?array
	{
		if (!file_exists($filePath) || !is_readable($filePath)) {
			return null;
		}

		$cacheKey = 'ai_code_file_' . md5($filePath . filemtime($filePath));

		return Cache::remember($cacheKey, now()->addHours(24), function () use ($filePath) {
			try {
				$content = file_get_contents($filePath);

				// Extract basic information
				$info = [
					'path' => $filePath,
					'content' => $content,
					'namespace' => null,
					'class' => null,
					'methods' => [],
				];

				// Extract namespace
				if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
					$info['namespace'] = trim($matches[1]);
				}

				// Extract class name
				if (preg_match('/class\s+(\w+)/', $content, $matches)) {
					$info['class'] = $matches[1];
				}

				// Extract method signatures
				if (preg_match_all('/public\s+function\s+(\w+)\s*\([^)]*\)/', $content, $matches, PREG_SET_ORDER)) {
					foreach ($matches as $match) {
						$info['methods'][] = $match[1];
					}
				}

				return $info;
			} catch (\Exception $e) {
				Log::warning('Failed to read code file', [
					'file' => $filePath,
					'error' => $e->getMessage(),
				]);
				return null;
			}
		});
	}

	/**
	 * Extract relevant code snippets from a file based on query
	 */
	protected function extractRelevantCode(string $filePath, string $query): ?string
	{
		$fileInfo = $this->readCodeFile($filePath);
		if (!$fileInfo) {
			return null;
		}

		$queryLower = strtolower($query);
		$content = $fileInfo['content'];
		$lines = explode("\n", $content);
		$relevantLines = [];

		// Find lines that match the query
		foreach ($lines as $lineNum => $line) {
			$lineLower = strtolower($line);

			// Check if line contains query keywords
			if (preg_match('/\b' . preg_quote($queryLower, '/') . '\b/i', $lineLower)) {
				// Include context (3 lines before and after)
				$start = max(0, $lineNum - 3);
				$end = min(count($lines), $lineNum + 4);

				for ($i = $start; $i < $end; $i++) {
					if (!isset($relevantLines[$i])) {
						$relevantLines[$i] = $lines[$i];
					}
				}
			}
		}

		if (empty($relevantLines)) {
			// Return first 20 lines as fallback
			$relevantLines = array_slice($lines, 0, 20);
		}

		// Sort by line number and return
		ksort($relevantLines);
		return implode("\n", $relevantLines);
	}

	/**
	 * Find files matching a query
	 */
	protected function findFilesMatchingQuery(string $query): array
	{
		if (!$this->codebaseContextEnabled) {
			return [];
		}

		$queryLower = strtolower($query);
		$matchingFiles = [];

		// Search in models
		try {
			$models = $this->getAvailableCodebaseModels();
			foreach ($models as $modelName => $fullClassName) {
				if (strpos(strtolower($modelName), $queryLower) !== false) {
					$reflection = new \ReflectionClass($fullClassName);
					$filePath = $reflection->getFileName();
					if ($filePath) {
						$matchingFiles[] = $filePath;
					}
				}
			}
		} catch (\Exception $e) {
			// Continue
		}

		// Search in controllers
		try {
			$controllers = $this->getAvailableControllers();
			foreach ($controllers as $controller) {
				if (strpos(strtolower($controller['name']), $queryLower) !== false) {
					$reflection = new \ReflectionClass($controller['fullName']);
					$filePath = $reflection->getFileName();
					if ($filePath) {
						$matchingFiles[] = $filePath;
					}
				}
			}
		} catch (\Exception $e) {
			// Continue
		}

		// Limit results
		return array_slice($matchingFiles, 0, $this->maxCodebaseFiles);
	}

	/**
	 * Get code context for a query
	 */
	protected function getCodeContext(string $query): string
	{
		if (!$this->codebaseContextEnabled) {
			return '';
		}

		$context = '';
		$matchingFiles = $this->findFilesMatchingQuery($query);

		foreach ($matchingFiles as $filePath) {
			$relevantCode = $this->extractRelevantCode($filePath, $query);
			if ($relevantCode) {
				$fileName = basename($filePath);
				$context .= "\n\n// From {$fileName}:\n{$relevantCode}";
			}
		}

		return $context;
	}
}