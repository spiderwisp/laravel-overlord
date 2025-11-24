<?php

	return [
		/*
	/*
	|--------------------------------------------------------------------------
	| Paths Configuration
	|--------------------------------------------------------------------------
	|
	| Configure the paths where the package should look for models, controllers,
	| and classes. These paths are used for discovery and analysis features.
	|
	*/
	'models_path' => app_path('Models'),
	'controllers_path' => app_path('Http/Controllers'),
	'classes_path' => app_path(),

	/*
	|--------------------------------------------------------------------------
	| User Model
	|--------------------------------------------------------------------------
	|
	| The user model class that will be used for command logging and
	| authentication. This should be your application's User model.
	|
	*/
	'user_model' => \App\Models\User::class,

	/*
	|--------------------------------------------------------------------------
	| Middleware
	|--------------------------------------------------------------------------
	|
	| Middleware to apply to all terminal routes. Default is 'auth', but you
	| can add additional middleware like 'role:ADMIN' or custom middleware.
	|
	| For development, you can set this to an empty array [] to disable authentication.
	| WARNING: Never use empty middleware in production!
	|
	| You can also override this via .env: LARAVEL_OVERLORD_MIDDLEWARE=auth,verified
	|
	*/
	'middleware' => env('LARAVEL_OVERLORD_MIDDLEWARE') ? explode(',', env('LARAVEL_OVERLORD_MIDDLEWARE')) : (env('APP_ENV') === 'local' ? [] : ['auth']),

	/*
	|--------------------------------------------------------------------------
	| Authentication Guard
	|--------------------------------------------------------------------------
	|
	| The authentication guard to use for checking user authentication.
	| Set to null to use the default guard (usually 'web').
	|
	| For multi-guard setups (e.g., admin panel with separate auth), specify
	| the guard name here. Example: 'auth_guard' => 'admin'
	|
	| This allows the package to work with different Laravel authentication
	| systems without requiring code changes.
	|
	*/
	'auth_guard' => env('LARAVEL_OVERLORD_AUTH_GUARD', null),

	/*
	|--------------------------------------------------------------------------
	| Route Prefix
	|--------------------------------------------------------------------------
	|
	| The prefix for all terminal routes. Default is 'admin/overlord'.
	| All routes will be prefixed with this value.
	|
	*/
	'route_prefix' => 'admin/overlord',

	/*
	|--------------------------------------------------------------------------
	| Default Route Configuration
	|--------------------------------------------------------------------------
	|
	| Configure the default full-page terminal route. This creates a route
	| similar to Horizon's /horizon or Telescope's /telescope routes.
	|
	*/
	'default_route_enabled' => env('LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED', true),
	'default_route_path' => env('LARAVEL_OVERLORD_DEFAULT_ROUTE_PATH', 'overlord'),

	/*
	|--------------------------------------------------------------------------
	| Help View
	|--------------------------------------------------------------------------
	|
	| The Blade view to use for the help command. This view will receive
	| a 'models' array with all discovered model names.
	|
	*/
	'help_view' => 'laravel-overlord::help',

	/*
	|--------------------------------------------------------------------------
	| UI Configuration
	|--------------------------------------------------------------------------
	|
	| Customize the terminal UI appearance and branding.
	|
	*/
	'ui' => [
		'title' => 'Laravel Overlord',
		'subtitle' => 'Development Console',
		'brand_color' => '#007acc',
	],

	/*
	|--------------------------------------------------------------------------
	| Standalone Route Configuration
	|--------------------------------------------------------------------------
	|
	| Configure the standalone route that serves the terminal interface
	| as a full-page view. This allows accessing the terminal directly
	| without needing to integrate the Vue component into your application.
	|
	*/
	'standalone_route' => [
		// Enable or disable the standalone route
		'enabled' => true,

		// The path for the standalone route (e.g., 'overlord' creates /overlord)
		'path' => 'overlord',
	],

	/*
	|--------------------------------------------------------------------------
	| AI Configuration
	|--------------------------------------------------------------------------
	|
	| Configure AI assistant features for the terminal. The AI has full access
	| to codebase, database, and logs for intelligent, context-aware assistance.
	|
	*/
	'ai' => [
		// Enable or disable AI features
		'enabled' => env('LARAVEL_OVERLORD_AI_ENABLED', true),

		// API configuration
		// API URL is internal and should not be configured by users
		'api_url' => 'https://laravel-overlord.com/api',
		// API key: ENV takes precedence, then database settings
		// The actual resolution happens in OverlordProvider to allow database fallback
		'api_key' => env('LARAVEL_OVERLORD_API_KEY'),
		'encryption_key' => env('LARAVEL_OVERLORD_ENCRYPTION_KEY'),

		// Number of previous messages to keep in conversation context
		// Default: 10, Recommended range: 5-20
		'context_window' => env('LARAVEL_OVERLORD_AI_CONTEXT_WINDOW', 10),

		// System prompt for the AI (customize to change AI behavior)
		// Leave null to use the default system prompt
		'system_prompt' => env('LARAVEL_OVERLORD_AI_SYSTEM_PROMPT', null),

		// Fuzzy matching threshold (0.0 to 1.0) - for fallback pattern matching
		// Lower values = more lenient matching, higher values = stricter matching
		// Default: 0.6, Range: 0.0-1.0
		'fuzzy_matching_threshold' => env('LARAVEL_OVERLORD_AI_FUZZY_MATCHING_THRESHOLD', 0.6),

		// Enable codebase context reading (reads actual PHP files for context)
		'codebase_context_enabled' => env('LARAVEL_OVERLORD_AI_CODEBASE_CONTEXT_ENABLED', true),

		// Enable database context gathering (table schemas, relationships, sample data)
		'database_context_enabled' => env('LARAVEL_OVERLORD_AI_DATABASE_CONTEXT_ENABLED', true),

		// Enable log context gathering (recent errors, application logs)
		'log_context_enabled' => env('LARAVEL_OVERLORD_AI_LOG_CONTEXT_ENABLED', true),

		// Maximum number of codebase files to read for context
		// Default: 5, Recommended range: 3-10
		'max_codebase_files' => env('LARAVEL_OVERLORD_AI_MAX_CODEBASE_FILES', 5),

		// Maximum number of database tables to include in context
		// Default: 3, Recommended range: 2-5
		'max_database_tables' => env('LARAVEL_OVERLORD_AI_MAX_DATABASE_TABLES', 3),

		// Maximum number of log entries to include in context
		// Default: 10, Recommended range: 5-20
		'max_log_entries' => env('LARAVEL_OVERLORD_AI_MAX_LOG_ENTRIES', 10),

		// Cache TTL for context gathering (in seconds)
		// Default: 3600 (1 hour), Recommended range: 1800-7200 (30 minutes to 2 hours)
		'context_cache_ttl' => env('LARAVEL_OVERLORD_AI_CONTEXT_CACHE_TTL', 3600),
	],

	/*
	|--------------------------------------------------------------------------
	| Bug Report Configuration
	|--------------------------------------------------------------------------
	|
	| Configure bug reporting features for submitting bug reports to
	| laravel-overlord.com. All payloads are encrypted before transmission.
	|
	*/
	'bug_report' => [
		// Enable or disable bug reporting
		'enabled' => env('LARAVEL_OVERLORD_BUG_REPORT_ENABLED', true),

		// API endpoint for bug report submission
		'api_url' => env('LARAVEL_OVERLORD_BUG_REPORT_API_URL', 'https://laravel-overlord.com/api/bug-reports'),

		// Encryption key: Uses same key as AI features
		// Falls back to LARAVEL_OVERLORD_API_KEY if not set
		'encryption_key' => env('LARAVEL_OVERLORD_ENCRYPTION_KEY'),
	],

	/*
	|--------------------------------------------------------------------------
	| Mermaid Diagram Configuration
	|--------------------------------------------------------------------------
	|
	| Configure Mermaid diagram generation settings.
	|
	*/
	'mermaid' => [
		/*
		| Include package components in diagram
		| Set to true to include Spiderwisp\LaravelOverlord components
		| Default is false to show only the main application
		*/
		'include_package' => env('LARAVEL_OVERLORD_MERMAID_INCLUDE_PACKAGE', false),
	],
];