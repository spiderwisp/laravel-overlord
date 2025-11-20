# Laravel Overlord Setup Guide

Complete step-by-step guide for installing and configuring Laravel Overlord.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Frontend Integration](#frontend-integration)
5. [AI Setup (Optional)](#ai-setup-optional)
6. [Verification](#verification)
7. [Troubleshooting](#troubleshooting)

## Prerequisites

Before installing, ensure you have:

- **PHP ^8.2** or higher
- **Laravel ^12.0** or higher
- **Composer** installed
- **Redis** installed and configured (required for queue features)

> **Note:** highlight.js is automatically loaded from CDN - no installation required!

### Redis Setup

Redis is required for Horizon integration and job queue features.

#### Using Docker/Sail

Add to your `docker-compose.yml`:

```yaml
services:
    redis:
        image: 'redis:7-alpine'
        ports:
            - '${FORWARD_REDIS_PORT:-6379}:6379'
        volumes:
            - 'redis:/data'
```

#### Using Homebrew (macOS)

```bash
brew install redis
brew services start redis
```

#### Using apt (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install redis-server
sudo systemctl start redis-server
sudo systemctl enable redis-server
```

#### Verify Redis Installation

```bash
redis-cli ping
# Should return: PONG
```

> **Note:** After installing Redis, make sure to configure the environment variables in your `.env` file. See the [Environment Variables Configuration](#environment-variables-configuration) section below.

## Installation

### Quick Install (Recommended)

The easiest way to install Laravel Overlord is using the automated install command:

```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

This single command will:
- ✅ Publish configuration files
- ✅ Publish database migrations
- ✅ Publish pre-compiled assets (no build step required!)
- ✅ Optionally run migrations (with interactive prompt)
- ✅ Update catch-all routes if needed

**After installation, access the terminal at:** `http://your-app.com/overlord`

> **Note:** To disable the default `/overlord` route, set `LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=false` in your `.env` file.

**Command Options:**
```bash
# Force overwrite existing published files
php artisan overlord:install --force

# Auto-run migrations without prompting
php artisan overlord:install --migrate

# Both options combined
php artisan overlord:install --force --migrate
```

The install command will check for prerequisites and provide helpful instructions if anything is missing. It's safe to run multiple times.

### Installation Methods

#### Method 1: Composer (Standard)

This is the standard method for installing from Packagist or a Git repository:

```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

**Access the terminal** at `http://your-app.com/overlord`

#### Method 2: Path Repository (Local Development)

If you're developing the package locally or have it on the same machine:

1. In your Laravel project's `composer.json`, add:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/laravel-overlord/laravel-overlord"
        }
    ],
    "require": {
        "spiderwisp/laravel-overlord": "@dev"
    }
}
```

2. Run:
```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

3. **Access the terminal** at `http://your-app.com/overlord`

#### Method 3: Zip File (Share Package)

If you need to share the package as a zip file:

1. **Create the package zip** (if you have a script):
```bash
./packages/laravel-overlord/create-package.sh
```

2. **Extract it** to a `packages/` directory in your project:
```bash
cd /path/to/your-laravel-project
mkdir -p packages
unzip spiderwisp-laravel-overlord.zip -d packages/
```

3. **Add to `composer.json`**:
```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/spiderwisp-laravel-overlord"
        }
    ],
    "require": {
        "spiderwisp/laravel-overlord": "@dev"
    }
}
```

4. **Install the package**:
```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

5. **Access the terminal** at `http://your-app.com/overlord`

#### Method 4: Git Repository (VCS)

If you have the package in a Git repository:

1. In your Laravel project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-username/laravel-overlord"
        }
    ],
    "require": {
        "spiderwisp/laravel-overlord": "@dev"
    }
}
```

2. Run:
```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

3. **Access the terminal** at `http://your-app.com/overlord`

### Manual Installation

If you prefer to install manually or need more control over each step:

#### Step 1: Install Package

```bash
composer require spiderwisp/laravel-overlord
```

#### Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=laravel-overlord-config
```

This creates `config/laravel-overlord.php` in your project.

#### Step 3: Publish Migrations

```bash
php artisan vendor:publish --tag=laravel-overlord-migrations
php artisan migrate
```

This creates the following tables:
- `overlord_command_logs` - Command execution history
- `overlord_issues` - Issue tracking
- `overlord_scan_issues` - Codebase scan results
- `overlord_scan_history` - Scan history
- `overlord_database_scan_history` - Database scan history
- `overlord_database_scan_issues` - Database scan issues

#### Step 4: Publish Views (Optional)

If you need to customize the help view:

```bash
php artisan vendor:publish --tag=laravel-overlord-views
```

#### Step 5: Publish Pre-compiled Assets

The package includes pre-compiled assets (like Horizon). Publish them with:

```bash
php artisan vendor:publish --tag=laravel-assets
```

This publishes the compiled assets to `public/vendor/laravel-overlord/`. **No build step is required!**

6. **Access the terminal** at `http://your-app.com/overlord`

> **Note:** For development/customization, you can also publish source assets with `--tag=laravel-overlord-assets`, but this requires building the assets yourself.

> **Note:** highlight.js is automatically loaded from CDN - no installation required!

> **Note:** To disable the default `/overlord` route, set `LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=false` in your `.env` file.

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

#### Middleware Configuration

The `middleware` configuration controls access to all terminal routes. This middleware is automatically applied to all routes including the help route.

**How it works:**
- The middleware array from your config is applied to all routes via `Route::middleware(config('laravel-overlord.middleware'))`
- Default behavior: empty array `[]` in local environment, `['auth']` in production
- You can override via `.env` using `LARAVEL_OVERLORD_MIDDLEWARE` (comma-separated)
- You can add multiple middleware for additional security

**Examples:**

Basic authentication (default in production):
```php
'middleware' => ['auth'],
```

Via environment variable:
```env
LARAVEL_OVERLORD_MIDDLEWARE=auth,verified
```

Role-based access:
```php
'middleware' => ['auth', 'role:ADMIN'],
```

Custom middleware:
```php
'middleware' => ['auth', 'verified', 'custom-middleware'],
```

**Security Note:** All routes including the help route (`/{prefix}/help`) are protected by the configured middleware. Never use empty middleware in production!

#### Authentication Guard Configuration

The `auth_guard` configuration allows you to specify which authentication guard to use for checking user authentication.

**How it works:**
- Set to `null` (default) to use the default guard (usually 'web')
- Set to a guard name (e.g., 'admin') to use a specific guard
- Useful for multi-guard setups where you have separate authentication for admin panels

**Examples:**

Use default guard (default):
```php
'auth_guard' => null,
```

Use a specific guard:
```php
'auth_guard' => 'admin',
```

Via environment variable:
```env
LARAVEL_OVERLORD_AUTH_GUARD=admin
```

#### Default Route Configuration

Laravel Overlord automatically creates a default full-page terminal route (similar to Horizon's `/horizon` or Telescope's `/telescope` routes). This route provides a standalone page where users can access the terminal interface.

**By default, the terminal is available at:**
```
http://your-app.com/overlord
```

**Configuration via Environment Variables:**

Add these to your `.env` file to configure the default route:

```env
# Enable or disable the default route (default: true)
LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=true

# Customize the route path (default: 'overlord')
LARAVEL_OVERLORD_DEFAULT_ROUTE_PATH=overlord
```

**Examples:**

1. **Default route (enabled by default):**
   - Access at: `http://your-app.com/overlord`
   - No configuration needed - works out of the box!

2. **Custom path:**
   ```env
   LARAVEL_OVERLORD_DEFAULT_ROUTE_PATH=dev-console
   ```
   - Access at: `http://your-app.com/dev-console`

3. **Disable the default route:**
   ```env
   LARAVEL_OVERLORD_DEFAULT_ROUTE_ENABLED=false
   ```
   - The `/overlord` route will not be registered
   - Use this if you prefer to integrate the terminal component into your own pages

**Important Notes:**
- The default route uses the same middleware as the API routes (configured via `middleware` setting)
- The route is automatically registered when the package is installed
- You can disable it if you prefer to integrate the terminal component into your own pages
- The route path should not conflict with your existing routes
- The terminal uses pre-compiled assets that are automatically published. No build step is required!

### Environment Variables Configuration

#### Required: Redis Configuration

Redis is required for Horizon integration and job queue features. Add these to your `.env` file:

```env
# Redis Configuration (Required for Horizon/Queue features)
REDIS_HOST=127.0.0.1  # Or "redis" if using Docker service name
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
```

**For Docker/Sail**, use the service name:

```env
REDIS_HOST=redis
```

#### Optional: AI Assistant Configuration

To enable the AI assistant features, add these to your `.env` file:

**Note:** The API URL is configured internally and does not need to be set. Only the API key is required.

```env
# AI Assistant Configuration
LARAVEL_OVERLORD_AI_ENABLED=true

# API key is required for AI features (get from laravel-overlord.com)
# The API URL is configured internally - no user configuration needed
LARAVEL_OVERLORD_API_KEY=your_api_key_here

# Optional: Encryption key (defaults to API key if not set)
# LARAVEL_OVERLORD_ENCRYPTION_KEY=your_encryption_key

# Context settings (optional - defaults shown)
LARAVEL_OVERLORD_AI_CONTEXT_WINDOW=10
LARAVEL_OVERLORD_AI_CODEBASE_CONTEXT_ENABLED=true
LARAVEL_OVERLORD_AI_DATABASE_CONTEXT_ENABLED=true
LARAVEL_OVERLORD_AI_LOG_CONTEXT_ENABLED=true
LARAVEL_OVERLORD_AI_MAX_CODEBASE_FILES=5
LARAVEL_OVERLORD_AI_MAX_DATABASE_TABLES=3
LARAVEL_OVERLORD_AI_MAX_LOG_ENTRIES=10
LARAVEL_OVERLORD_AI_CONTEXT_CACHE_TTL=3600

# Optional: Custom system prompt
# LARAVEL_OVERLORD_AI_SYSTEM_PROMPT=Your custom system prompt here

# Optional: Fuzzy matching threshold (0.0 to 1.0, default: 0.6)
# LARAVEL_OVERLORD_AI_FUZZY_MATCHING_THRESHOLD=0.6
```

**Important:** 
- AI features are optional - the package works without them
- Free plans have limitations (rate limits, quota restrictions)
- Only the API key needs to be configured by users

#### Optional: Authentication & Middleware Configuration

```env
# Override middleware (comma-separated)
# Default: empty in local, ['auth'] in production
LARAVEL_OVERLORD_MIDDLEWARE=auth,verified

# Use a specific authentication guard
# Default: null (uses default guard)
LARAVEL_OVERLORD_AUTH_GUARD=admin
```

#### Optional: Bug Report Configuration

```env
# Enable/disable bug reporting (default: true)
LARAVEL_OVERLORD_BUG_REPORT_ENABLED=true

# Custom bug report API URL (default: https://laravel-overlord.com/api/bug-reports)
# LARAVEL_OVERLORD_BUG_REPORT_API_URL=https://laravel-overlord.com/api/bug-reports
```

**After adding environment variables**, clear the config cache:

```bash
php artisan config:clear
```

### Laravel Horizon (Optional but Recommended)

For queue monitoring features:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

## Frontend Integration

### For Inertia.js Applications

#### 1. Import Component

In your main layout file (e.g., `resources/js/Layouts/AppLayout.vue`):

```vue
<script setup>
import { ref } from 'vue';
import DeveloperTerminal from '@/vendor/laravel-overlord/Components/DeveloperTerminal.vue';

const terminalVisible = ref(false);

function openTerminal() {
    terminalVisible.value = true;
}

function closeTerminal() {
    terminalVisible.value = false;
}
</script>

<template>
    <div>
        <!-- Your app content -->
        
        <!-- Terminal Toggle Button -->
        <button @click="openTerminal" class="terminal-toggle">
            Open Terminal
        </button>
        
        <!-- Terminal Component -->
        <DeveloperTerminal 
            :visible="terminalVisible" 
            @close="closeTerminal"
            :floating="true"
        />
    </div>
</template>
```

#### 2. Configure API Endpoints

The components use `useOverlordApi.js` which automatically detects the route prefix. Ensure your Inertia shared props include the route prefix:

```php
// In app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        // ... other shared props
        'overlord' => [
            'routePrefix' => config('laravel-overlord.route_prefix'),
        ],
    ];
}
```

### For Standalone Vue Applications

If not using Inertia, you'll need to configure the API base URL manually:

```javascript
// In your main.js or app.js
window.overlordConfig = {
    routePrefix: '/admin/overlord' // Match your config
};
```

Then import and use the component:

```vue
<script setup>
import DeveloperTerminal from '@/vendor/laravel-overlord/Components/DeveloperTerminal.vue';
</script>

<template>
    <DeveloperTerminal :visible="true" />
</template>
```

### Keyboard Shortcut (Optional)

Add a keyboard shortcut to open the terminal:

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const terminalVisible = ref(false);

function handleKeyPress(event) {
    // Ctrl/Cmd + K to toggle terminal
    if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
        event.preventDefault();
        terminalVisible.value = !terminalVisible.value;
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeyPress);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyPress);
});
</script>
```

## AI Setup (Optional)

The AI assistant is optional but provides intelligent code analysis and assistance.

### Quick AI Setup

1. Get an API key from laravel-overlord.com
2. Add to `.env`:

```env
LARAVEL_OVERLORD_AI_ENABLED=true
LARAVEL_OVERLORD_API_KEY=your_api_key_here
```

**Note:** The API URL is configured internally and does not need to be set by users.

3. Clear config cache:

```bash
php artisan config:clear
```

The AI assistant has full access to:
- **Codebase**: All PHP files, models, controllers, classes, and relationships
- **Database**: Table schemas, column types, relationships, and sample data
- **Logs**: Application logs, error logs, and recent entries

## Verification

### Step 1: Check Routes

Verify routes are registered:

```bash
php artisan route:list | grep overlord
```

You should see routes prefixed with your configured route prefix.

### Step 2: Test Terminal Access

1. Open your application in a browser
2. Navigate to `http://your-app.com/overlord` (or your configured route path)
3. You should see the full-page terminal interface
4. Try a simple command:

```php
User::count()
```

### Step 3: Verify Database Tables

Check that migrations ran successfully:

```bash
php artisan migrate:status
```

All `overlord_*` tables should be migrated.

### Step 4: Test AI (if enabled)

1. Open the terminal
2. Navigate to the AI tab
3. Check that AI status shows "Available"
4. Try asking: "How do I query users?"

### Step 5: Test Redis Connection

```bash
php artisan tinker
```

Then in the terminal:

```php
Redis::ping()
// Should return: "PONG"
```

## Troubleshooting

### Terminal Not Appearing

**Symptoms**: Terminal component doesn't show up

**Solutions**:
1. Check browser console for JavaScript errors
2. Verify assets were published: `php artisan vendor:publish --tag=laravel-assets`
3. Check that assets exist at `public/vendor/laravel-overlord/js/terminal.js`
4. Verify route prefix matches configuration
5. Check that middleware allows access (authentication)
6. Clear view cache: `php artisan view:clear`

### Commands Not Executing

**Symptoms**: Commands return errors or don't execute

**Solutions**:
1. Check `storage/logs/laravel.log` for errors
2. Verify database connection
3. Check user authentication
4. Verify Redis is running
5. Check PHP memory limit (may need increase for large operations)

### AI Not Available

**Symptoms**: AI tab shows "Not Available"

**Solutions**:
1. Check API key is set in `.env` (only API key is required, URL is internal)
2. Verify `LARAVEL_OVERLORD_AI_ENABLED=true` is set
3. Clear config cache: `php artisan config:clear`
4. Check logs for API errors
5. Verify API key is valid and not expired
6. Check if you've hit rate limits or quota restrictions (free plan limitations)

### Redis Connection Errors

**Symptoms**: Horizon features not working, queue errors

**Solutions**:
1. Verify Redis is running: `redis-cli ping`
2. Check `.env` Redis configuration
3. For Docker: Verify service name matches (use `redis` not `127.0.0.1`)
4. Check Redis password if set
5. Verify port is correct (default: 6379)

### Migration Errors

**Symptoms**: Tables not created

**Solutions**:
1. Check database connection
2. Verify user has CREATE TABLE permissions
3. Check for conflicting table names
4. Run migrations individually if needed
5. Check `storage/logs/laravel.log` for specific errors

### Assets Not Found

**Symptoms**: Terminal shows "Assets Not Found" error

**Solutions**:
1. Publish assets: `php artisan vendor:publish --tag=laravel-assets`
2. Verify assets exist at `public/vendor/laravel-overlord/js/terminal.js`
3. Check file permissions on `public/vendor/laravel-overlord/` directory
4. Clear view cache: `php artisan view:clear`
5. Re-run install command: `php artisan overlord:install --force`

### Route Not Found

**Symptoms**: 404 errors when accessing terminal

**Solutions**:
1. Verify route prefix in config
2. Check routes are registered: `php artisan route:list`
3. Clear route cache: `php artisan route:clear`
4. Verify middleware allows access
5. Check web server configuration

## Security Best Practices

### 1. Authentication

Always protect terminal routes with authentication:

```php
'middleware' => ['auth'],
```

For additional security, add role-based access:

```php
'middleware' => ['auth', 'role:ADMIN'],
```

### 2. API Keys

- Never commit API keys to version control
- Use environment variables for all sensitive data
- Rotate API keys regularly
- Use different keys for development and production

### 3. Redis Security

In production, protect Redis:

```env
REDIS_PASSWORD=your_secure_password
```

### 4. Command Logging

Monitor command logs regularly:

```bash
# View recent commands
php artisan tinker
DB::table('overlord_command_logs')->latest()->take(10)->get();
```

### 5. Rate Limiting

Consider adding rate limiting to terminal routes if needed.

## Post-Installation

After successful installation:

1. ✅ Test basic terminal functionality
2. ✅ Configure AI assistant (optional)
3. ✅ Set up keyboard shortcuts (optional)
4. ✅ Customize UI colors/branding
5. ✅ Review and adjust middleware settings
6. ✅ Set up monitoring for command logs
7. ✅ Configure Redis persistence (production)

## Support

For issues or questions:

1. Check this guide and troubleshooting section
2. Review `storage/logs/laravel.log` for errors
3. Check browser console for frontend errors
4. Verify all prerequisites are met
5. Review configuration files

## Additional Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Vue 3 Documentation](https://vuejs.org/)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Redis Documentation](https://redis.io/docs/)
