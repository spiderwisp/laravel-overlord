# Laravel Overlord

A powerful Laravel development console with advanced features including interactive terminal, codebase scanning, database analysis, command history, and more.

## Features

- **Interactive Terminal Console**: Full PHP REPL with Laravel model aliases
- **AI Assistant**: Context-aware assistant with codebase, database, and log access
- **Codebase Scanning**: Automated code analysis to identify bugs, security issues, and improvements
- **Database Scanning**: Schema and data analysis with issue detection
- **Command History**: Persistent logging with search and filtering
- **Model Relationships Diagram**: Visual representation of Eloquent relationships
- **Controllers Explorer**: Browse and analyze all controllers
- **Classes Explorer**: Explore PHP classes with full reflection data
- **Artisan Commands**: Execute Laravel commands with dynamic forms
- **Templates & Snippets**: Pre-built templates and custom snippets
- **Command Builder**: Visual query builder for Eloquent
- **Favorites System**: Save and organize frequently used commands
- **Laravel Horizon Integration**: Monitor queue jobs, statistics, and job history (requires Redis)

## Requirements

- PHP ^8.2
- Laravel ^12.0
- Redis (required for queue features)

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

    // Middleware for routes
    'middleware' => ['auth'],

    // Route prefix
    'route_prefix' => 'admin/overlord',

    // UI customization
    'ui' => [
        'title' => 'Developer Terminal',
        'subtitle' => 'Laravel Overlord',
        'brand_color' => '#007acc',
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

All endpoints are prefixed with the configured `route_prefix`:

- `POST /{prefix}/execute` - Execute a terminal command
- `GET /{prefix}/history` - Get command history
- `DELETE /{prefix}/session` - Clear session variables
- `GET /{prefix}/model-relationships` - Get model relationships
- `GET /{prefix}/controllers` - Get all controllers
- `GET /{prefix}/classes` - Get all classes
- `GET /{prefix}/commands` - Get all Artisan commands
- `POST /{prefix}/commands/execute` - Execute an Artisan command

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
