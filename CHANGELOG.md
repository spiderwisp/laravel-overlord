# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2025-01-15

### Initial Implementation

This is the initial release of Laravel Overlord. The following features are included:

#### Core Terminal Features
- **Interactive Terminal Console**: Full PHP REPL with Laravel model aliases
- **Command History**: Persistent logging with search and filtering
- **Templates & Snippets**: Pre-built templates and custom snippets
- **Command Builder**: Visual query builder for Eloquent
- **Favorites System**: Save and organize frequently used commands
- **Session Management**: Clear session variables and maintain state across commands

#### Codebase Exploration
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

#### Artisan & Commands
- **Artisan Commands**: Execute Laravel commands with dynamic forms
- **Migration Management**: View, run, rollback, and generate migrations
- **Shell Commands**: Execute shell commands (with security restrictions)

#### Database Features
- **Database Browser**: Browse tables, view structure, data, and statistics
- **Database CRUD**: Create, read, update, and delete database rows
- **Database Query Execution**: Execute custom SQL queries (with security protection)
- **Database Scanning**: Schema and data analysis with issue detection

#### Code Analysis
- **Codebase Scanning**: Automated code analysis to identify bugs, security issues, and improvements
- **Issue Management**: Create, track, assign, and resolve issues from scans and manual reports

#### Monitoring & Logging
- **Log Viewer**: Browse, search, and analyze application logs
- **Laravel Horizon Integration**: Monitor queue jobs, statistics, job history, and manage Horizon (requires Redis)

#### AI Features
- **AI Assistant**: Context-aware assistant with codebase, database, and log access
- **AI Model Management**: Check available models and API key status
- **Bug Report System**: Submit encrypted bug reports to laravel-overlord.com

#### Installation & Configuration
- **Install Command**: Automated setup via `php artisan overlord:install`
- **Comprehensive Documentation**: Complete setup guide and API documentation
- **Flexible Authentication**: Support for custom middleware and authentication guards

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
