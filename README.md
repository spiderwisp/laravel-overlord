# Laravel Overlord

A powerful Laravel development console with advanced features including interactive terminal, codebase scanning, database analysis, command history, and more.

## Features

### Core Terminal Features
- **Interactive Terminal Console**: Full PHP REPL with Laravel model aliases
- **Command History**: Persistent logging with search and filtering
- **Templates & Snippets**: Pre-built templates and custom snippets
- **Command Builder**: Visual query builder for Eloquent
- **Favorites System**: Save and organize frequently used commands
- **Session Management**: Clear session variables and maintain state across commands

### Codebase Exploration
- **Controllers Explorer**: Browse and analyze all controllers with method source code
- **Classes Explorer**: Explore PHP classes with full reflection data
- **Traits Explorer**: Discover and explore application traits
- **Services Explorer**: Browse and analyze service classes
- **Requests Explorer**: Explore form request classes
- **Providers Explorer**: View and manage service providers
- **Middleware Explorer**: Discover and manage middleware classes
- **Jobs Explorer**: Explore queue job classes
- **Exceptions Explorer**: Browse exception classes
- **Command Classes Explorer**: Explore Artisan command classes
- **Routes Explorer**: Explore and test application routes with URL generation and testing
- **Model Relationships Diagram**: Visual representation of Eloquent relationships
- **Model Fields**: View model attributes and properties

### Artisan & Commands
- **Artisan Commands**: Execute Laravel commands with dynamic forms
- **Migration Management**: View, run, rollback, and generate migrations
- **Shell Commands**: Execute shell commands (with security restrictions)

### Database Features
- **Database Browser**: Browse tables, view structure, data, and statistics
- **Database CRUD**: Create, read, update, and delete database rows
- **Database Query Execution**: Execute custom SQL queries (with security protection)
- **Database Scanning**: Schema and data analysis with issue detection

### Code Analysis
- **Codebase Scanning**: Automated code analysis to identify bugs, security issues, and improvements
- **PHPStan Integration**: Static analysis with PHPStan for type checking and code quality (optional)
- **Issue Management**: Create, track, assign, and resolve issues from scans and manual reports

### Monitoring & Logging
- **Log Viewer**: Browse, search, and analyze application logs
- **Laravel Horizon Integration**: Monitor queue jobs, statistics, job history, and manage Horizon (requires Redis)

### AI Features
- **AI Assistant**: Context-aware assistant with codebase, database, and log access
- **AI Model Management**: Check available models and API key status
- **Bug Report System**: Submit encrypted bug reports to laravel-overlord.com

## Requirements

- PHP ^8.2
- Laravel ^12.0
- Redis (required for queue features)

### Optional Dependencies

- **PHPStan** (for static analysis features): `composer require --dev phpstan/phpstan larastan/larastan`
  - Larastan is recommended to reduce false positives with Laravel magic methods and facades

## Installation

### Quick Install

```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

The install command will automatically:
- Publish configuration files
- Publish database migrations
- Publish pre-compiled assets (no build step required!)
- Optionally run migrations
- Update catch-all routes if needed

**After installation, access the terminal at:** `http://your-app.com/overlord`

> **Note:** To disable the default `/overlord` route, set `LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=false` in your `.env` file.

**With options:**
```bash
# Force overwrite existing files and auto-run migrations
php artisan overlord:install --force --migrate
```

### Manual Installation

If you prefer manual installation:

```bash
# 1. Install package
composer require spiderwisp/laravel-overlord

# 2. Publish configuration
php artisan vendor:publish --tag=laravel-overlord-config

# 3. Publish migrations
php artisan vendor:publish --tag=laravel-overlord-migrations
php artisan migrate

# 4. Publish pre-compiled assets
php artisan vendor:publish --tag=laravel-assets

# 5. Assets are pre-compiled - no build step needed!
```

> **Note:** The `laravel-assets` tag publishes pre-compiled assets (ready to use). For development/customization, you can use `--tag=laravel-overlord-assets` to publish source files, but this requires building the assets yourself.

For detailed installation instructions, including alternative installation methods, see the [Setup Guide](docs/SETUP.md).

## Configuration

### Basic Configuration

Edit `config/laravel-overlord.php`:

```php
return [
    // Paths for discovery
    'models_path' => app_path('Models'),
    'controllers_path' => app_path('Http/Controllers'),
    'classes_path' => app_path(),

    // User model for logging
    'user_model' => \App\Models\User::class,

    // Middleware for routes (can be overridden via .env)
    // Default: empty array in local environment, ['auth'] in production
    'middleware' => env('LARAVEL_OVERLORD_MIDDLEWARE') 
        ? explode(',', env('LARAVEL_OVERLORD_MIDDLEWARE')) 
        : (env('APP_ENV') === 'local' ? [] : ['auth']),

    // Authentication guard (null uses default guard)
    'auth_guard' => env('LARAVEL_OVERLORD_AUTH_GUARD', null),

    // Route prefix
    'route_prefix' => 'admin/overlord',

    // Default route configuration
    'default_route_enabled' => env('LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED', true),
    'default_route_path' => env('LARAVEL_OVERLORD_DEFAULT_ROUTE_PATH', 'overlord'),

    // Help view
    'help_view' => 'laravel-overlord::help',

    // UI customization
    'ui' => [
        'title' => 'Laravel Overlord',
        'subtitle' => 'Development Console',
        'brand_color' => '#007acc',
    ],

    // AI configuration
    'ai' => [
        'enabled' => env('LARAVEL_OVERLORD_AI_ENABLED', true),
        'api_url' => 'https://laravel-overlord.com/api',
        'api_key' => env('LARAVEL_OVERLORD_API_KEY'),
        'encryption_key' => env('LARAVEL_OVERLORD_ENCRYPTION_KEY'),
        'context_window' => env('LARAVEL_OVERLORD_AI_CONTEXT_WINDOW', 10),
        'system_prompt' => env('LARAVEL_OVERLORD_AI_SYSTEM_PROMPT', null),
        'fuzzy_matching_threshold' => env('LARAVEL_OVERLORD_AI_FUZZY_MATCHING_THRESHOLD', 0.6),
        'codebase_context_enabled' => env('LARAVEL_OVERLORD_AI_CODEBASE_CONTEXT_ENABLED', true),
        'database_context_enabled' => env('LARAVEL_OVERLORD_AI_DATABASE_CONTEXT_ENABLED', true),
        'log_context_enabled' => env('LARAVEL_OVERLORD_AI_LOG_CONTEXT_ENABLED', true),
        'max_codebase_files' => env('LARAVEL_OVERLORD_AI_MAX_CODEBASE_FILES', 5),
        'max_database_tables' => env('LARAVEL_OVERLORD_AI_MAX_DATABASE_TABLES', 3),
        'max_log_entries' => env('LARAVEL_OVERLORD_AI_MAX_LOG_ENTRIES', 10),
        'context_cache_ttl' => env('LARAVEL_OVERLORD_AI_CONTEXT_CACHE_TTL', 3600),
    ],

    // Bug report configuration
    'bug_report' => [
        'enabled' => env('LARAVEL_OVERLORD_BUG_REPORT_ENABLED', true),
        'api_url' => env('LARAVEL_OVERLORD_BUG_REPORT_API_URL', 'https://laravel-overlord.com/api/bug-reports'),
        'encryption_key' => env('LARAVEL_OVERLORD_ENCRYPTION_KEY'),
    ],
];
```

### Environment Variables

#### Required: Redis Configuration

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
```

#### Optional: AI Assistant

The AI assistant is optional and requires an external API key. The API URL is configured internally and does not need to be set by users.

**Note:** AI features have limitations on free plans (rate limits, quota restrictions).

```env
# Enable AI features (optional)
LARAVEL_OVERLORD_AI_ENABLED=true

# API key is required for AI features (get from laravel-overlord.com)
LARAVEL_OVERLORD_API_KEY=your_api_key_here

# Optional: Encryption key (defaults to API key if not set)
# LARAVEL_OVERLORD_ENCRYPTION_KEY=your_encryption_key

# Optional: AI context configuration
# LARAVEL_OVERLORD_AI_CONTEXT_WINDOW=10
# LARAVEL_OVERLORD_AI_CODEBASE_CONTEXT_ENABLED=true
# LARAVEL_OVERLORD_AI_DATABASE_CONTEXT_ENABLED=true
# LARAVEL_OVERLORD_AI_LOG_CONTEXT_ENABLED=true
# LARAVEL_OVERLORD_AI_MAX_CODEBASE_FILES=5
# LARAVEL_OVERLORD_AI_MAX_DATABASE_TABLES=3
# LARAVEL_OVERLORD_AI_MAX_LOG_ENTRIES=10
# LARAVEL_OVERLORD_AI_CONTEXT_CACHE_TTL=3600
```

#### Optional: Authentication & Middleware

```env
# Override middleware (comma-separated)
# LARAVEL_OVERLORD_MIDDLEWARE=auth,verified

# Use a specific authentication guard
# LARAVEL_OVERLORD_AUTH_GUARD=admin
```

#### Optional: Bug Reports

```env
# Enable/disable bug reporting (default: true)
# LARAVEL_OVERLORD_BUG_REPORT_ENABLED=true

# Custom bug report API URL (default: https://laravel-overlord.com/api/bug-reports)
# LARAVEL_OVERLORD_BUG_REPORT_API_URL=https://laravel-overlord.com/api/bug-reports
```

After adding environment variables, clear the config cache:

```bash
php artisan config:clear
```

## Frontend Integration

### For Inertia.js Applications

Import the `DeveloperTerminal` component in your layout:

```vue
<script setup>
import DeveloperTerminal from '@/vendor/laravel-overlord/Components/DeveloperTerminal.vue';
</script>

<template>
  <DeveloperTerminal :visible="terminalVisible" @close="terminalVisible = false" />
</template>
```

Ensure your Inertia shared props include the route prefix:

```php
// In app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        'overlord' => [
            'routePrefix' => config('laravel-overlord.route_prefix'),
        ],
    ];
}
```

## Usage

### Access the Terminal

**Default Route (Full-Page Terminal):**

After installation, the terminal is automatically available at:
```
http://your-app.com/overlord
```

This provides a full-page terminal interface (similar to Laravel Horizon's `/horizon` route).

**To disable the default route:**
Add to your `.env` file:
```env
LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=false
```

**Custom Route Path:**
To change the route path, set in your `.env` file:
```env
LARAVEL_OVERLORD_DEFAULT_ROUTE_PATH=dev-console
```
This will make the terminal available at `http://your-app.com/dev-console`

**Vue Component Integration (Alternative):**

If you prefer to integrate the terminal into your own pages, you can disable the default route and use the `DeveloperTerminal` Vue component. Add it to your layout and control visibility with a prop.

### Basic Commands

```php
// Count records
User::count()

// Query with conditions
User::where('role', 'CREATOR')->get()

// Work with relationships
$user = User::find(1)
$user->creator
$creator->videos()->take(5)->get()

// Aggregations
Video::where('status', 'PUBLISHED')->sum('duration')
```

### Help Command

Type `help` or `?` in the terminal to view the comprehensive help guide.

## API Endpoints

All endpoints are prefixed with the configured `route_prefix` (default: `admin/overlord`):

### Terminal Execution
- `POST /{prefix}/execute` - Execute a terminal command
- `GET /{prefix}/history` - Get command history
- `DELETE /{prefix}/session` - Clear session variables
- `GET /{prefix}/help` - Get help content

### Codebase Exploration
- `GET /{prefix}/model-relationships` - Get model relationships diagram
- `GET /{prefix}/model-fields` - Get model fields and attributes
- `GET /{prefix}/controllers` - Get all controllers
- `GET /{prefix}/controllers/method-source` - Get controller method source code
- `GET /{prefix}/classes` - Get all classes
- `GET /{prefix}/traits` - Get all traits
- `GET /{prefix}/services` - Get all services
- `GET /{prefix}/requests` - Get all form requests
- `GET /{prefix}/providers` - Get all service providers
- `GET /{prefix}/middleware` - Get all middleware classes
- `GET /{prefix}/jobs` - Get all job classes
- `GET /{prefix}/exceptions` - Get all exception classes
- `GET /{prefix}/command-classes` - Get all Artisan command classes
- `GET /{prefix}/routes` - Get all routes
- `GET /{prefix}/routes/{identifier}` - Get route details
- `POST /{prefix}/routes/generate-url` - Generate URL from route
- `POST /{prefix}/routes/test` - Test a route

### Artisan Commands
- `GET /{prefix}/commands` - Get all Artisan commands
- `POST /{prefix}/commands/execute` - Execute an Artisan command

### Migrations
- `GET /{prefix}/migrations` - Get all migrations
- `GET /{prefix}/migrations/status` - Get migration status
- `GET /{prefix}/migrations/preview-run` - Preview migration run
- `GET /{prefix}/migrations/preview-rollback` - Preview migration rollback
- `GET /{prefix}/migrations/{migration}` - Get migration details
- `POST /{prefix}/migrations/run` - Run migrations
- `POST /{prefix}/migrations/rollback` - Rollback migrations
- `POST /{prefix}/migrations/generate` - Generate migration from model
- `POST /{prefix}/migrations/create` - Create new migration

### Database Browser
- `GET /{prefix}/database/tables` - Get all database tables
- `GET /{prefix}/database/tables/{table}/structure` - Get table structure
- `GET /{prefix}/database/tables/{table}/data` - Get table data
- `GET /{prefix}/database/tables/{table}/stats` - Get table statistics
- `GET /{prefix}/database/tables/{table}/row` - Get a specific row
- `POST /{prefix}/database/query` - Execute SQL query
- `POST /{prefix}/database/tables/{table}/row` - Create a new row
- `PUT /{prefix}/database/tables/{table}/row/{id}` - Update a row
- `DELETE /{prefix}/database/tables/{table}/row/{id}` - Delete a row

### Codebase Scanning
- `GET /{prefix}/scan/file-tree` - Get file tree for scanning
- `POST /{prefix}/scan/start` - Start a codebase scan
- `GET /{prefix}/scan/history` - Get scan history
- `GET /{prefix}/scan/history/{scanId}` - Get scan history details
- `GET /{prefix}/scan/{scanId}/status` - Get scan status
- `GET /{prefix}/scan/{scanId}/results` - Get scan results
- `GET /{prefix}/scan/issues` - Get scan issues
- `GET /{prefix}/scan/issues/has-existing` - Check for existing issues
- `POST /{prefix}/scan/issues/{issueId}/resolve` - Resolve a scan issue
- `POST /{prefix}/scan/issues/{issueId}/unresolve` - Unresolve a scan issue
- `DELETE /{prefix}/scan/issues` - Clear all scan issues

### Database Scanning
- `GET /{prefix}/scan/database/tables` - Get tables for database scan
- `POST /{prefix}/scan/database/start` - Start a database scan
- `GET /{prefix}/scan/database/history` - Get database scan history
- `GET /{prefix}/scan/database/history/{scanId}` - Get database scan history details
- `GET /{prefix}/scan/database/{scanId}/status` - Get database scan status
- `GET /{prefix}/scan/database/{scanId}/results` - Get database scan results
- `GET /{prefix}/scan/database/issues` - Get database scan issues
- `GET /{prefix}/scan/database/issues/has-existing` - Check for existing database scan issues
- `POST /{prefix}/scan/database/issues/{issueId}/resolve` - Resolve a database scan issue
- `POST /{prefix}/scan/database/issues/{issueId}/unresolve` - Unresolve a database scan issue
- `DELETE /{prefix}/scan/database/issues` - Clear all database scan issues

### PHPStan Static Analysis
- `GET /{prefix}/phpstan/config` - Get PHPStan configuration (auto-detected)
- `POST /{prefix}/phpstan/start` - Start a PHPStan analysis
- `GET /{prefix}/phpstan/history` - Get PHPStan scan history
- `GET /{prefix}/phpstan/{scanId}/status` - Get PHPStan scan status
- `GET /{prefix}/phpstan/{scanId}/results` - Get PHPStan scan results
- `GET /{prefix}/phpstan/issues` - Get PHPStan issues
- `POST /{prefix}/phpstan/issues/{issueId}/resolve` - Resolve a PHPStan issue
- `POST /{prefix}/phpstan/issues/{issueId}/unresolve` - Unresolve a PHPStan issue
- `DELETE /{prefix}/phpstan/issues` - Clear all PHPStan issues

### Issues Management
- `GET /{prefix}/issues` - Get all issues
- `GET /{prefix}/issues/stats` - Get issue statistics
- `GET /{prefix}/issues/users` - Get users associated with issues
- `GET /{prefix}/issues/{id}` - Get issue details
- `POST /{prefix}/issues` - Create a new issue
- `PUT /{prefix}/issues/{id}` - Update an issue
- `POST /{prefix}/issues/{id}/resolve` - Resolve an issue
- `POST /{prefix}/issues/{id}/close` - Close an issue
- `POST /{prefix}/issues/{id}/reopen` - Reopen an issue
- `POST /{prefix}/issues/{id}/assign` - Assign an issue to a user
- `DELETE /{prefix}/issues/{id}` - Delete an issue

### Log Viewer
- `GET /{prefix}/logs/list` - List available log files
- `GET /{prefix}/logs/content` - Get log file content
- `GET /{prefix}/logs/surrounding` - Get surrounding lines from log
- `GET /{prefix}/logs/search` - Search logs
- `GET /{prefix}/logs/stats` - Get log statistics

### Laravel Horizon
- `GET /{prefix}/horizon/check` - Check if Horizon is installed
- `GET /{prefix}/horizon/stats` - Get Horizon statistics
- `GET /{prefix}/horizon/jobs` - Get Horizon jobs
- `GET /{prefix}/horizon/jobs/{id}` - Get Horizon job details
- `POST /{prefix}/horizon/jobs/{id}/retry` - Retry a failed job
- `DELETE /{prefix}/horizon/jobs/{id}` - Delete a job
- `POST /{prefix}/horizon/jobs/{id}/execute` - Execute a job
- `POST /{prefix}/horizon/jobs/create` - Create a new job
- `POST /{prefix}/horizon/pause` - Pause Horizon
- `POST /{prefix}/horizon/continue` - Continue Horizon
- `POST /{prefix}/horizon/terminate` - Terminate Horizon
- `POST /{prefix}/horizon/restart` - Restart Horizon
- `POST /{prefix}/horizon/clear` - Clear Horizon cache
- `POST /{prefix}/horizon/snapshot` - Create Horizon snapshot
- `GET /{prefix}/horizon/status` - Get Horizon status
- `GET /{prefix}/horizon/supervisors` - Get Horizon supervisors
- `GET /{prefix}/horizon/config` - Get Horizon configuration
- `GET /{prefix}/horizon/system-info` - Get Horizon system information

### AI Assistant
- `POST /{prefix}/ai/chat` - Send a chat message to AI
- `GET /{prefix}/ai/models` - Get available AI models
- `POST /{prefix}/ai/models/check` - Check if a model is available
- `GET /{prefix}/ai/status` - Get AI status
- `GET /{prefix}/ai/api-key-status` - Get API key status
- `GET /{prefix}/ai/api-key-setting` - Get API key setting from database
- `PUT /{prefix}/ai/api-key-setting` - Update API key setting in database
- `DELETE /{prefix}/ai/api-key-setting` - Delete API key setting from database

### Shell Commands
- `POST /{prefix}/shell/execute` - Execute a shell command (with security restrictions)

### Bug Reports
- `POST /{prefix}/bug-report/submit` - Submit a bug report (encrypted)

## Security

### Authentication

Always protect terminal routes with authentication:

```php
'middleware' => ['auth'],
```

For additional security, add role-based access:

```php
'middleware' => ['auth', 'role:ADMIN'],
```

### Best Practices

- Always use authentication middleware
- Restrict access to trusted users only
- Keep API keys secure and rotate them regularly
- Monitor command logs for suspicious activity
- Use Redis password protection in production

## Troubleshooting

### Terminal Not Appearing

1. Check Vue component import
2. Verify route prefix matches configuration
3. Check browser console for JavaScript errors
4. Verify assets were published: `php artisan vendor:publish --tag=laravel-assets`

### Commands Not Executing

1. Check `storage/logs/laravel.log` for errors
2. Verify database connection
3. Check user authentication
4. Verify Redis is running

### Redis Connection Errors

1. Verify Redis is running: `redis-cli ping`
2. Check `.env` Redis configuration
3. For Docker: Use service name `redis` instead of `127.0.0.1`

For more detailed troubleshooting, see the [Setup Guide](docs/SETUP.md).

## Documentation

- [Setup Guide](docs/SETUP.md) - Complete installation and configuration guide

## AI Features

The AI assistant provides context-aware assistance with access to your codebase, database, and logs. 

### Requirements
- **API Key Required**: You must provide `LARAVEL_OVERLORD_API_KEY` in your `.env` file
- **API URL**: Configured internally - no user configuration needed
- **Free Plan Limitations**: Rate limits and quota restrictions apply on free plans
- **Optional**: AI features work without the API key, but AI functionality will be disabled

### Getting Started with AI

1. Obtain an API key from laravel-overlord.com
2. Add to your `.env` file:
   ```env
   LARAVEL_OVERLORD_AI_ENABLED=true
   LARAVEL_OVERLORD_API_KEY=your_api_key_here
   ```
3. Clear config cache: `php artisan config:clear`
4. The AI assistant will be available in the terminal

## License

MIT
