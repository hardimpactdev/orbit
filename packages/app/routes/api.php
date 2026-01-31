<?php

use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentConfigController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentProjectController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentServiceController;
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentStatusController;
use HardImpact\Orbit\Ui\Http\Controllers\JobController;
use HardImpact\Orbit\Ui\Http\Controllers\PhpConfigController;
use HardImpact\Orbit\Ui\Http\Controllers\WorkspaceController;
use HardImpact\Orbit\Ui\Http\Controllers\WorktreeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless (no session) to avoid session locking.
| This allows them to run in parallel without blocking Inertia navigation.
|
| ARCHITECTURE: Dual Route Patterns (Intentional)
|
| This file defines routes in TWO patterns for different clients:
|
| 1. PREFIXED ROUTES (lines 18-58): /environments/{environment}/...
|    - Used by DESKTOP app which manages multiple environments
|    - Environment is explicitly specified in URL
|
| 2. FLAT ROUTES (lines 63+): /status, /projects, etc.
|    - Used by WEB app which has single implicit environment
|    - Uses implicit.environment middleware to inject environment
|    - Also provides backward compatibility for desktop's remoteApiUrl calls
|
| This duplication is intentional - do NOT consolidate without understanding
| both client needs. See CLAUDE.md "Direct API Calls" section for details.
|
*/

Route::prefix('environments/{environment}')->group(function (): void {
    // Dashboard data endpoints
    Route::post('test-connection', [EnvironmentController::class, 'testConnection']);
    Route::get('status', [EnvironmentStatusController::class, 'status']);
    Route::get('projects/status', [EnvironmentStatusController::class, 'projects']);
    Route::get('config', [EnvironmentConfigController::class, 'getConfig']);
    Route::get('worktrees', [WorktreeController::class, 'index']);

    // Async data loading endpoints
    Route::get('projects', [EnvironmentProjectController::class, 'indexApi']);
    Route::post('projects/sync', [EnvironmentProjectController::class, 'syncApi']);
    Route::delete('projects/{projectName}', [EnvironmentProjectController::class, 'destroy']);
    Route::get('workspaces', [WorkspaceController::class, 'indexApi']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'showApi']);

    // Service control endpoints (stateless API for Vue async calls)
    Route::post('start', [EnvironmentServiceController::class, 'start']);
    Route::post('stop', [EnvironmentServiceController::class, 'stop']);
    Route::post('restart', [EnvironmentServiceController::class, 'restart']);

    // Individual service routes
    Route::get('services/available', [EnvironmentServiceController::class, 'availableServices']);
    Route::post('services/{service}/start', [EnvironmentServiceController::class, 'startService']);
    Route::post('services/{service}/stop', [EnvironmentServiceController::class, 'stopService']);
    Route::post('services/{service}/restart', [EnvironmentServiceController::class, 'restartService']);
    Route::post('host-services/{service}/start', [EnvironmentServiceController::class, 'startHostService']);
    Route::post('host-services/{service}/stop', [EnvironmentServiceController::class, 'stopHostService']);
    Route::post('host-services/{service}/restart', [EnvironmentServiceController::class, 'restartHostService']);
    Route::get('services/{service}/logs', [EnvironmentServiceController::class, 'serviceLogs']);
    Route::get('host-services/{service}/logs', [EnvironmentServiceController::class, 'hostServiceLogs']);
    Route::post('services/{service}/enable', [EnvironmentServiceController::class, 'enableService']);
    Route::delete('services/{service}', [EnvironmentServiceController::class, 'disableService']);
    Route::put('services/{service}/config', [EnvironmentServiceController::class, 'configureService']);
    Route::get('services/{service}/info', [EnvironmentServiceController::class, 'serviceInfo']);

    // PHP Configuration
    Route::get('php/config/{version?}', [PhpConfigController::class, 'getConfig']);
    Route::post('php/config/{version?}', [PhpConfigController::class, 'setConfig']);
    Route::post('php/{project}', [PhpConfigController::class, 'changePhp']);
    Route::post('php/{project}/reset', [PhpConfigController::class, 'resetPhp']);
});

// Project routes (without environment prefix - used when remoteApiUrl is set)
// These are accessed directly via orbit.{tld}/api/projects/{slug}
// Uses implicit.environment middleware to inject the local environment
Route::middleware('implicit.environment')->group(function (): void {
    // Instance info endpoints (for environment naming sync)
    Route::get('instance-info', [EnvironmentController::class, 'instanceInfo'])->name('api.instance-info');
    Route::put('instance-info', [EnvironmentController::class, 'updateInstanceInfo'])->name('api.instance-info.update');

    // Flat routes for desktop app compatibility
    Route::get('status', [EnvironmentStatusController::class, 'status'])->name('api.status');
    Route::get('projects', [EnvironmentProjectController::class, 'indexApi'])->name('api.projects');
    Route::get('config', [EnvironmentConfigController::class, 'getConfig'])->name('api.config');
    Route::get('worktrees', [WorktreeController::class, 'index'])->name('api.worktrees');
    Route::get('workspaces', [WorkspaceController::class, 'indexApi'])->name('api.workspaces');
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'showApi'])->name('api.workspaces.show');

    Route::post('start', [EnvironmentServiceController::class, 'start'])->name('api.start');
    Route::post('stop', [EnvironmentServiceController::class, 'stop'])->name('api.stop');
    Route::post('restart', [EnvironmentServiceController::class, 'restart'])->name('api.restart');

    Route::post('projects', [EnvironmentProjectController::class, 'store'])->name('api.projects.store');
    Route::post('projects/sync', [EnvironmentProjectController::class, 'syncApi'])->name('api.projects.sync');
    Route::delete('projects/{projectName}', [EnvironmentProjectController::class, 'destroy'])->name('api.projects.destroy');
    Route::post('projects/{projectName}/rebuild', [EnvironmentProjectController::class, 'rebuild'])->name('api.projects.rebuild');
    Route::get('projects/{projectSlug}/provision-status', [EnvironmentProjectController::class, 'provisionStatus'])->name('api.projects.provision-status');

    // Service control endpoints (legacy paths)
    Route::get('services/status', [EnvironmentStatusController::class, 'status']);
    Route::post('services/{service}/start', [EnvironmentServiceController::class, 'startService']);
    Route::post('services/{service}/stop', [EnvironmentServiceController::class, 'stopService']);
    Route::post('services/{service}/restart', [EnvironmentServiceController::class, 'restartService']);
    Route::post('services/{service}/enable', [EnvironmentServiceController::class, 'enableService']);
    Route::post('services/{service}/disable', [EnvironmentServiceController::class, 'disableService']);

    // PHP Management
    Route::get('php-versions', function () {
        return response()->json([
            'success' => true,
            'versions' => ['8.3', '8.4', '8.5'],
        ]);
    });
    Route::post('php/{project}', [PhpConfigController::class, 'changePhp'])->name('api.php.set');
    Route::post('php/{project}/reset', [PhpConfigController::class, 'resetPhp'])->name('api.php.reset');

    // Jobs
    Route::get('jobs/{trackedJob}', [JobController::class, 'show']);

    // Route discovery for verification
    Route::get('routes', function () {
        return collect(Route::getRoutes())->map(function ($route) {
            return $route->uri();
        });
    });
});
