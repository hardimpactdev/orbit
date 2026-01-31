<?php

use HardImpact\Orbit\App\Http\Controllers\DashboardController;
use HardImpact\Orbit\App\Http\Controllers\EnvironmentController;
use HardImpact\Orbit\App\Http\Controllers\ProvisioningController;
use HardImpact\Orbit\App\Http\Controllers\SetupController;
use HardImpact\Orbit\App\Http\Controllers\SettingsController;
use HardImpact\Orbit\App\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;

// First-Run Setup Routes (accessible without middleware)
Route::prefix('setup')->name('setup.')->group(function (): void {
    Route::get('/', [SetupController::class, 'index'])->name('index');
    Route::get('/check', [SetupController::class, 'check'])->name('check');
    Route::post('/run', [SetupController::class, 'run'])->name('run');
    Route::get('/status', [SetupController::class, 'status'])->name('status');
});

if (config('orbit.multi_environment')) {
    // Desktop: Environment management + prefixed routes
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('environments', EnvironmentController::class);

    // Redirect old server routes to environments
    Route::redirect('/servers', '/environments')->name('servers.index');
    Route::redirect('/servers/{id}', '/environments/{id}');

    Route::post('environments/{environment}/switch', [EnvironmentController::class, 'switchEnvironment'])->name('environments.switch');

    // SSH Key Management (Desktop-only for now)
    Route::prefix('ssh-keys')->name('ssh-keys.')->group(function (): void {
        Route::post('/', [SshKeyController::class, 'store'])->name('store');
        Route::put('{sshKey}', [SshKeyController::class, 'update'])->name('update');
        Route::delete('{sshKey}', [SshKeyController::class, 'destroy'])->name('destroy');
        Route::post('{sshKey}/default', [SshKeyController::class, 'setDefault'])->name('default');
        Route::get('available', [SshKeyController::class, 'getAvailableKeys'])->name('available');
    });

    // Include environment-scoped routes WITH prefix
    Route::prefix('environments/{environment}')
        ->group(__DIR__.'/environment.php');
} else {
    // Gate desktop-only management routes with 403 in web mode
    // These MUST come before the compatibility routes below
    Route::any('/environments', fn () => abort(403));
    Route::any('/environments/create', fn () => abort(403));
    Route::any('/ssh-keys/{any?}', fn () => abort(403))->where('any', '.*');

    // Web: Flat routes, middleware injects implicit environment
    // Web: Routes
    Route::middleware('implicit.environment')->group(function () {
        Route::get('/', [EnvironmentController::class, 'show'])->name('dashboard');

        // Flat routes (e.g. /projects)
        Route::group([], __DIR__.'/environment.php');
    });

    // Prefixed routes for compatibility (e.g. /environments/1/projects)
    // These work in web mode too, but are not the primary way to access them
    Route::prefix('environments/{environment}')
        ->group(__DIR__.'/environment.php');

    // Redirect environment show to root in web mode
    Route::get('environments/{environment}', fn () => redirect('/'))->name('environments.show');

    // Gate environment edit route specifically
    Route::any('/environments/{environment}/edit', fn () => abort(403));
}

// SHARED ROUTES (Outside conditional)

// Project routes - forwards to active environment's API
Route::post('projects', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'store'])->name('projects.store');
Route::delete('projects/{slug}', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'destroy'])->name('projects.destroy');
Route::post('projects/{project}/php', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'setPhpVersion'])->name('projects.php.set');
Route::post('projects/{project}/php/reset', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'resetPhpVersion'])->name('projects.php.reset');

// API routes for environment data
Route::prefix('api/environments')->group(function (): void {
    Route::get('tlds', [EnvironmentController::class, 'getAllTlds'])->name('api.environments.tlds');
});

// Redirect global settings to environment configuration
Route::get('settings', function () {
    $environment = \HardImpact\Orbit\Core\Models\Environment::getLocal()
        ?? \HardImpact\Orbit\Core\Models\Environment::getDefault()
        ?? \HardImpact\Orbit\Core\Models\Environment::first();

    if ($environment) {
        return redirect()->route('environments.configuration', $environment);
    }

    // Fallback if no environment exists (shouldn't happen normally)
    return redirect('/');
})->name('settings.index');

// Redirect /configuration to environment configuration
Route::get('configuration', function () {
    $environment = \HardImpact\Orbit\Core\Models\Environment::getLocal()
        ?? \HardImpact\Orbit\Core\Models\Environment::getDefault()
        ?? \HardImpact\Orbit\Core\Models\Environment::first();

    if ($environment) {
        return redirect()->route('environments.configuration', $environment);
    }

    return redirect('/');
})->name('configuration.index');

// Keep POST routes for backwards compatibility (used by desktop app settings)
Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/notifications', [SettingsController::class, 'toggleNotifications'])->name('settings.notifications');
Route::post('settings/menu-bar', [SettingsController::class, 'toggleMenuBar'])->name('settings.menu-bar');

// CLI Management Routes
Route::prefix('cli')->name('cli.')->group(function (): void {
    Route::get('status', [SettingsController::class, 'cliStatus'])->name('status');
    Route::post('install', [SettingsController::class, 'cliInstall'])->name('install');
    Route::post('update', [SettingsController::class, 'cliUpdate'])->name('update');
});

// Template Favorites Management
Route::prefix('template-favorites')->name('template-favorites.')->group(function (): void {
    Route::post('/', [SettingsController::class, 'storeTemplate'])->name('store');
    Route::put('{template}', [SettingsController::class, 'updateTemplate'])->name('update');
    Route::delete('{template}', [SettingsController::class, 'destroyTemplate'])->name('destroy');
});

// Provisioning Routes
Route::prefix('provision')->name('provision.')->group(function (): void {
    Route::get('/', [ProvisioningController::class, 'create'])->name('create');
    Route::post('/', [ProvisioningController::class, 'store'])->name('store');
    Route::post('/check-server', [ProvisioningController::class, 'checkServer'])->name('check-server');
    Route::post('/{environment}/run', [ProvisioningController::class, 'run'])->name('run');
    Route::get('/{environment}/status', [ProvisioningController::class, 'status'])->name('status');
});
