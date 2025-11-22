<?php

use Illuminate\Support\Facades\Route;
use Spiderwisp\LaravelOverlord\Http\Controllers\TerminalController;

// Register default route if enabled (similar to Horizon's /horizon route)
// This route provides a full-page terminal interface accessible at the configured path
// Note: The conditional check is handled in the service provider to ensure proper registration
// Routes loaded via loadRoutesFrom are automatically wrapped in 'web' middleware by Laravel
$path = config('laravel-overlord.default_route_path', 'overlord');
$middleware = config('laravel-overlord.middleware', ['auth']);

// Merge 'web' middleware if not already present (web is required for sessions/CSRF)
$middleware = array_unique(array_merge(['web'], $middleware));

Route::middleware($middleware)->get($path, [
	TerminalController::class,
	'index'
]);

