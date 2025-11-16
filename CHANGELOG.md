# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-XX

### Initial Implementation

This is the initial release of Laravel Overlord. The following features are included:

- **Interactive Terminal Console**: Full PHP REPL with Laravel model aliases
- **AI Assistant**: Context-aware assistant with codebase, database, and log access (requires external API key)
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
- **Install Command**: Automated setup via `php artisan overlord:install`
- **Comprehensive Documentation**: Complete setup guide and API documentation

### Security Features

- Authentication middleware protection for all routes
- SQL injection protection in database query execution
- Authorization checks for issue management
- Secure API communication with encryption and HMAC signatures

### Requirements

- PHP ^8.2
- Laravel ^12.0
- Redis (required for queue features)
- Vue 3 (for frontend components)
- AI features are optional and require an external API key (`LARAVEL_OVERLORD_API_KEY`)
- AI features have limitations on free plans (rate limits, quota restrictions)
