<img src="https://raw.githubusercontent.com/spiderwisp/laravel-overlord/main/art/banner.png" alt="Laravel Overlord">

# Laravel Overlord

**The ultimate local development console for Laravel** — Think Tinker on steroids crossed with Telescope, Horizon, and an AI pair programmer.

One command → full interactive terminal, database browser, queue monitor, log viewer, codebase explorer, relationship diagrams, PHPStan integration, and an AI assistant that actually knows your code.

Perfect for solo developers and small teams who want to stop context-switching between 10 tools.

[![Latest Version](https://img.shields.io/packagist/v/spiderwisp/laravel-overlord.svg?style=flat-square)](https://packagist.org/packages/spiderwisp/laravel-overlord)

[![Total Downloads](https://img.shields.io/packagist/dt/spiderwisp/laravel-overlord.svg?style=flat-square)](https://packagist.org/packages/spiderwisp/laravel-overlord)

[![Laravel 12](https://img.shields.io/badge/Laravel-12-ff2d20?style=flat-square&logo=laravel)](https://laravel.com)

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php)](https://php.net)

<!-- TODO: Add 15-30 second GIF/video demo here showing terminal + AI + DB browser -->
<!-- Example: https://github.com/spiderwisp/laravel-overlord/assets/12345/abc123-def456 -->

## Why Overlord Exists

You already use:

- **Tinker** → slow, no history
- **Telescope** → great but read-only
- **Debugbar** → helpful but cluttered
- **Horizon** → only queues
- **Separate PHPStan, log viewers, etc.**

Overlord puts everything in one beautiful, fast console.

## Features People Actually Use Every Day

| Feature                        | What you can do in <10 seconds                              |
|-------------------------------|---------------------------------------------------------------|
| Interactive Terminal          | `User::where('plan', 'pro')->count()` + history + favorites   |
| Database Browser              | Click tables → see data → edit rows inline                    |
| Model Relationship Diagram    | Visual graph of all Eloquent relationships                   |
| Route Tester                  | Type route name → see URL + test with fake data               |
| Log Viewer                    | Search logs instantly, click stack trace → jump to code       |
| Horizon Dashboard             | Full Horizon UI built-in (no extra route)                     |
| AI Assistant                  | "Why is this query N+1?" → it reads your code and explains    |

(Yes, there are 50+ more features — power users love them)

## Installation (30 seconds)

```bash
composer require spiderwisp/laravel-overlord
php artisan overlord:install
```

Visit → `http://your-app.test/overlord`

That's it. No build step. No Node.js required.

Works out-of-the-box on Laravel 12 + PHP 8.2+

For detailed installation instructions, see the [Setup Guide](docs/SETUP.md).

## Screenshot Gallery

### Controllers 
<img src="art/Overlord 1.png" alt="Terminal with autocomplete suggestions, command history, and syntax highlighting" width="800">

### Models
<img src="art/Overlord 2.png" alt="Database browser showing table data with inline editing capabilities" width="800">

### Model Mermaid
<img src="art/Overlord 3.png" alt="Codebase explorer showing controllers, classes, and methods" width="800">

### Database Explorer/Editor
<img src="art/Overlord 4.png" alt="Visual Mermaid diagram showing Eloquent model relationships" width="800">

### Migrations Management
<img src="art/Overlord 5.png" alt="AI assistant providing context-aware answer about codebase" width="800">

### Ai Code Scanner
<img src="art/Overlord 6.png" alt="Full Horizon dashboard integrated within Overlord interface" width="800">

### Ai Larastan Code Fixer
<img src="art/Overlord 7.png" alt="Log viewer with search and filtering capabilities" width="800">

### Issues Tracker & Management
<img src="art/Overlord 8.png" alt="Beautiful dark mode interface" width="800">

### Settings with Several Themes
<img src="art/Overlord 9.png" alt="Additional Overlord features and capabilities" width="800">

## Just some of Overlord's Features

- **Many other features, including Ai Code Scans, Ai Database Scans, Artisan Command Explorer & Building, & More

## Security

- **Requires authentication in production** (defaults to `auth` middleware outside local environment)
- **AI features are optional** — requires explicit API key configuration to enable

For detailed security configuration, see the [Setup Guide](docs/SETUP.md).

## Basic Usage

### Access the Terminal

After installation, the terminal is automatically available at:
```
http://your-app.com/overlord
```

### Example Commands

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

Type `help` or `?` in the terminal to view the comprehensive help guide.

## Requirements

- PHP ^8.2
- Laravel ^12.0
- Redis (required for queue features)

### Optional Dependencies

- **PHPStan** (for static analysis features): `composer require --dev phpstan/phpstan larastan/larastan`
  - Larastan is recommended to reduce false positives with Laravel magic methods and facades

## AI Features

The AI assistant provides context-aware assistance with access to your codebase, database, and logs.

### Getting Started with AI

1. Obtain an API key from [laravel-overlord.com](https://laravel-overlord.com)
2. Add to your `.env` file:
   ```env
   LARAVEL_OVERLORD_AI_ENABLED=true
   LARAVEL_OVERLORD_API_KEY=your_api_key_here
   ```
3. Clear config cache: `php artisan config:clear`
4. The AI assistant will be available in the terminal

**Note:** AI features are optional and have limitations on free plans (rate limits, quota restrictions). AI sends zero code unless explicitly enabled.

## Configuration

For detailed configuration options, see the [Setup Guide](docs/SETUP.md).

Basic configuration is handled via environment variables:

```env
# Redis (required for queue features)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0

# AI Assistant (optional)
LARAVEL_OVERLORD_AI_ENABLED=true
LARAVEL_OVERLORD_API_KEY=your_api_key_here

# Authentication (optional - defaults to auth in production)
LARAVEL_OVERLORD_MIDDLEWARE=auth,verified
```

## Used by

<!-- TODO: Add company logos/logos of users once you have testimonials -->

## Documentation

- [Setup Guide](docs/SETUP.md) - Complete installation and configuration guide

## Credits & License

Created by [Spiderwisp](https://spiderwisp.com)

MIT License — free forever
