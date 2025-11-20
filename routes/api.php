<?php

use Illuminate\Support\Facades\Route;
use Spiderwisp\LaravelOverlord\Http\Controllers\TerminalController;
use Spiderwisp\LaravelOverlord\Http\Controllers\AiController;
use Spiderwisp\LaravelOverlord\Http\Controllers\LogsController;
use Spiderwisp\LaravelOverlord\Http\Controllers\IssuesController;
use Spiderwisp\LaravelOverlord\Http\Controllers\ScanController;
use Spiderwisp\LaravelOverlord\Http\Controllers\DatabaseScanController;
use Spiderwisp\LaravelOverlord\Http\Controllers\DatabaseController;
use Spiderwisp\LaravelOverlord\Http\Controllers\MigrationController;

// Wrap all routes in web middleware to ensure session support
// Use static values to avoid config() calls - config is merged in service provider
Route::middleware('web')->group(function () {
    // Use default values directly - config is already merged, but avoid calling config() here
    // for performance. These defaults match the config file defaults.
    $routePrefix = 'admin/overlord';
    
    // Protected routes - require authentication
    // SECURITY: Apply auth middleware from config to all routes including help route
    Route::prefix($routePrefix)
        ->middleware(config('laravel-overlord.middleware', ['auth']))
        ->group(function () {
            Route::get('/help', [TerminalController::class, 'getHelp']);
            Route::post('/execute', [TerminalController::class, 'execute']);
            Route::get('/history', [TerminalController::class, 'history']);
            Route::delete('/session', [TerminalController::class, 'clearSession']);
            Route::get('/model-relationships', [TerminalController::class, 'getModelRelationships']);
            Route::get('/model-fields', [TerminalController::class, 'getModelFields']);
            Route::get('/controllers', [TerminalController::class, 'getControllers']);
            Route::get('/controllers/method-source', [TerminalController::class, 'getMethodSourceCode']);
            Route::get('/classes', [TerminalController::class, 'getClasses']);
            Route::get('/traits', [TerminalController::class, 'getTraits']);
            Route::get('/services', [TerminalController::class, 'getServices']);
            Route::get('/requests', [TerminalController::class, 'getRequests']);
            Route::get('/providers', [TerminalController::class, 'getProviders']);
			Route::get('/middleware', [TerminalController::class, 'getMiddlewareClasses']);
            Route::get('/jobs', [TerminalController::class, 'getJobs']);
            Route::get('/exceptions', [TerminalController::class, 'getExceptions']);
            Route::get('/command-classes', [TerminalController::class, 'getCommandClasses']);
            Route::get('/commands', [TerminalController::class, 'getCommands']);
            Route::post('/commands/execute', [TerminalController::class, 'executeArtisanCommand']);
            
            // Horizon routes
            Route::prefix('horizon')->group(function () {
                Route::get('/check', [TerminalController::class, 'checkHorizon']);
                Route::get('/stats', [TerminalController::class, 'getHorizonStats']);
                Route::get('/jobs', [TerminalController::class, 'getHorizonJobs']);
                Route::get('/jobs/{id}', [TerminalController::class, 'getHorizonJobDetails']);
                Route::post('/jobs/{id}/retry', [TerminalController::class, 'retryHorizonJob']);
                Route::delete('/jobs/{id}', [TerminalController::class, 'deleteHorizonJob']);
                Route::post('/jobs/{id}/execute', [TerminalController::class, 'executeHorizonJob']);
                Route::post('/jobs/create', [TerminalController::class, 'createHorizonJob']);
                // Horizon management commands
                Route::post('/pause', [TerminalController::class, 'pauseHorizon']);
                Route::post('/continue', [TerminalController::class, 'continueHorizon']);
                Route::post('/terminate', [TerminalController::class, 'terminateHorizon']);
                Route::post('/restart', [TerminalController::class, 'restartHorizon']);
                Route::post('/clear', [TerminalController::class, 'clearHorizon']);
                Route::post('/snapshot', [TerminalController::class, 'snapshotHorizon']);
                Route::get('/status', [TerminalController::class, 'getHorizonStatus']);
                Route::get('/supervisors', [TerminalController::class, 'getHorizonSupervisors']);
                Route::get('/config', [TerminalController::class, 'getHorizonConfig']);
                Route::get('/system-info', [TerminalController::class, 'getHorizonSystemInfo']);
            });
            
            // Log routes
            Route::prefix('logs')->group(function () {
                Route::get('/list', [LogsController::class, 'listLogs']);
                Route::get('/content', [LogsController::class, 'getLogContent']);
                Route::get('/surrounding', [LogsController::class, 'getSurroundingLines']);
                Route::get('/search', [LogsController::class, 'searchLogs']);
                Route::get('/stats', [LogsController::class, 'getLogStats']);
            });
            
            // AI routes
            Route::prefix('ai')->group(function () {
                Route::post('/chat', [AiController::class, 'chat']);
                Route::get('/models', [AiController::class, 'getModels']);
                Route::post('/models/check', [AiController::class, 'checkModel']);
                Route::get('/status', [AiController::class, 'getStatus']);
                Route::get('/api-key-status', [AiController::class, 'getApiKeyStatus']);
                Route::get('/api-key-setting', [AiController::class, 'getApiKeySetting']);
                Route::put('/api-key-setting', [AiController::class, 'updateApiKeySetting']);
                Route::delete('/api-key-setting', [AiController::class, 'deleteApiKeySetting']);
            });
            
            // Shell routes
            Route::prefix('shell')->group(function () {
                Route::post('/execute', [TerminalController::class, 'executeShellCommand']);
            });
            
            // Issues routes
            Route::prefix('issues')->group(function () {
                Route::get('/', [IssuesController::class, 'index']);
                Route::get('/stats', [IssuesController::class, 'stats']);
                Route::get('/users', [IssuesController::class, 'users']);
                Route::get('/{id}', [IssuesController::class, 'show']);
                Route::post('/', [IssuesController::class, 'store']);
                Route::put('/{id}', [IssuesController::class, 'update']);
                Route::post('/{id}/resolve', [IssuesController::class, 'resolve']);
                Route::post('/{id}/close', [IssuesController::class, 'close']);
                Route::post('/{id}/reopen', [IssuesController::class, 'reopen']);
                Route::post('/{id}/assign', [IssuesController::class, 'assign']);
                Route::delete('/{id}', [IssuesController::class, 'delete']);
            });
            
            // Scan routes
            Route::prefix('scan')->group(function () {
                Route::get('/file-tree', [ScanController::class, 'fileTree']);
                Route::post('/start', [ScanController::class, 'start']);
                Route::get('/history', [ScanController::class, 'history']);
                Route::get('/history/{scanId}', [ScanController::class, 'historyDetails']);
                Route::get('/{scanId}/status', [ScanController::class, 'status']);
                Route::get('/{scanId}/results', [ScanController::class, 'results']);
                Route::get('/issues/has-existing', [ScanController::class, 'hasExistingIssues']);
                Route::get('/issues', [ScanController::class, 'issues']);
                Route::post('/issues/{issueId}/resolve', [ScanController::class, 'resolveIssue']);
                Route::post('/issues/{issueId}/unresolve', [ScanController::class, 'unresolveIssue']);
                Route::delete('/issues', [ScanController::class, 'clearIssues']);
            });
            
            // Database scan routes
            Route::prefix('scan/database')->group(function () {
                Route::get('/tables', [DatabaseScanController::class, 'tables']);
                Route::post('/start', [DatabaseScanController::class, 'start']);
                Route::get('/history', [DatabaseScanController::class, 'history']);
                Route::get('/history/{scanId}', [DatabaseScanController::class, 'historyDetails']);
                Route::get('/{scanId}/status', [DatabaseScanController::class, 'status']);
                Route::get('/{scanId}/results', [DatabaseScanController::class, 'results']);
                Route::get('/issues/has-existing', [DatabaseScanController::class, 'hasExistingIssues']);
                Route::get('/issues', [DatabaseScanController::class, 'issues']);
                Route::post('/issues/{issueId}/resolve', [DatabaseScanController::class, 'resolveIssue']);
                Route::post('/issues/{issueId}/unresolve', [DatabaseScanController::class, 'unresolveIssue']);
                Route::delete('/issues', [DatabaseScanController::class, 'clearIssues']);
            });
            
            // Database browser routes
            Route::prefix('database')->group(function () {
                Route::get('/tables', [DatabaseController::class, 'tables']);
                Route::get('/tables/{table}/structure', [DatabaseController::class, 'tableStructure']);
                Route::get('/tables/{table}/data', [DatabaseController::class, 'tableData']);
                Route::get('/tables/{table}/stats', [DatabaseController::class, 'tableStats']);
                Route::post('/query', [DatabaseController::class, 'executeQuery']);
                Route::get('/tables/{table}/row', [DatabaseController::class, 'getRow']);
                Route::post('/tables/{table}/row', [DatabaseController::class, 'createRow']);
                Route::put('/tables/{table}/row/{id}', [DatabaseController::class, 'updateRow']);
                Route::delete('/tables/{table}/row/{id}', [DatabaseController::class, 'deleteRow']);
            });
            
            // Migration routes
            Route::prefix('migrations')->group(function () {
                Route::get('/', [MigrationController::class, 'index']);
                Route::get('/status', [MigrationController::class, 'status']);
                Route::get('/preview-run', [MigrationController::class, 'previewRun']);
                Route::get('/preview-rollback', [MigrationController::class, 'previewRollback']);
                Route::get('/{migration}', [MigrationController::class, 'show']);
                Route::post('/run', [MigrationController::class, 'run']);
                Route::post('/rollback', [MigrationController::class, 'rollback']);
                Route::post('/generate', [MigrationController::class, 'generate']);
                Route::post('/create', [MigrationController::class, 'create']);
            });
            
            // Routes explorer
            Route::prefix('routes')->group(function () {
                Route::get('/', [TerminalController::class, 'getRoutes']);
                Route::get('/{identifier}', [TerminalController::class, 'getRouteDetails']);
                Route::post('/generate-url', [TerminalController::class, 'generateRouteUrl']);
                Route::post('/test', [TerminalController::class, 'testRoute']);
            });
        });
});