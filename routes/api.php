<?php

use App\Http\Controllers\EnvironmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless (no session) to avoid session locking.
| This allows them to run in parallel without blocking Inertia navigation.
|
*/

Route::prefix('environments/{environment}')->group(function (): void {
    // Dashboard data endpoints
    Route::post('test-connection', [EnvironmentController::class, 'testConnection']);
    Route::get('status', [EnvironmentController::class, 'status']);
    Route::get('sites', [EnvironmentController::class, 'sites']);
    Route::get('config', [EnvironmentController::class, 'getConfig']);
    Route::get('worktrees', [EnvironmentController::class, 'worktrees']);

    // Async data loading endpoints
    Route::get('projects', [EnvironmentController::class, 'projectsApi']);
    Route::get('workspaces', [EnvironmentController::class, 'workspacesApi']);
    Route::get('workspaces/{workspace}', [EnvironmentController::class, 'workspaceApi']);

    // Service control endpoints (stateless API for Vue async calls)
    Route::post('start', [EnvironmentController::class, 'start']);
    Route::post('stop', [EnvironmentController::class, 'stop']);
    Route::post('restart', [EnvironmentController::class, 'restart']);

    // Individual service routes
    Route::get('services/available', [EnvironmentController::class, 'availableServices']);
    Route::post('services/{service}/start', [EnvironmentController::class, 'startService']);
    Route::post('services/{service}/stop', [EnvironmentController::class, 'stopService']);
    Route::post('services/{service}/restart', [EnvironmentController::class, 'restartService']);
    Route::post('host-services/{service}/start', [EnvironmentController::class, 'startHostService']);
    Route::post('host-services/{service}/stop', [EnvironmentController::class, 'stopHostService']);
    Route::post('host-services/{service}/restart', [EnvironmentController::class, 'restartHostService']);
    Route::get('services/{service}/logs', [EnvironmentController::class, 'serviceLogs']);
    Route::get('host-services/{service}/logs', [EnvironmentController::class, 'hostServiceLogs']);
    Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService']);
    Route::delete('services/{service}', [EnvironmentController::class, 'disableService']);
    Route::put('services/{service}/config', [EnvironmentController::class, 'configureService']);
    Route::get('services/{service}/info', [EnvironmentController::class, 'serviceInfo']);

    // PHP Configuration
    Route::get('php/config/{version?}', [EnvironmentController::class, 'getPhpConfig']);
    Route::post('php/config/{version?}', [EnvironmentController::class, 'setPhpConfig']);
});
