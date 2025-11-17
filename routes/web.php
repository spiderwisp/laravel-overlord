<?php

use Illuminate\Support\Facades\Route;
use Spiderwisp\LaravelOverlord\Http\Controllers\TerminalController;

// Register default route if enabled (similar to Horizon's /horizon route)
// This route provides a full-page terminal interface accessible at the configured path
if (config('laravel-overlord.default_route_enabled', true)) {
	Route::middleware('web')->group(function () {
		$path = config('laravel-overlord.default_route_path', 'overlord');
		$middleware = config('laravel-overlord.middleware', ['auth']);

		Route::get($path, [
			TerminalController::class,
			'index'
		])->middleware($middleware);
	});
}

