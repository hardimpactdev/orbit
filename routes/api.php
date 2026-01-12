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
});
