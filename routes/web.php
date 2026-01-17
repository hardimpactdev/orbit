<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SshKeyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Laravel\Facades\Shell;

if (config('orbit.multi_environment')) {
    // Desktop: Environment management + prefixed routes
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('environments', EnvironmentController::class);

    // Redirect old server routes to environments
    Route::redirect('/servers', '/environments')->name('servers.index');
    Route::redirect('/servers/{id}', '/environments/{id}');

    // SSH Key Management (Desktop-only for now)
    Route::prefix('ssh-keys')->name('ssh-keys.')->group(function (): void {
        Route::post('/', [SshKeyController::class, 'store'])->name('store');
        Route::put('{sshKey}', [SshKeyController::class, 'update'])->name('update');
        Route::delete('{sshKey}', [SshKeyController::class, 'destroy'])->name('destroy');
        Route::post('{sshKey}/default', [SshKeyController::class, 'setDefault'])->name('default');
        Route::get('available', [SshKeyController::class, 'getAvailableKeys'])->name('available');
    });

    // Native desktop operations
    Route::post('/open-external', function (Request $request) {
        $url = $request->input('url');

        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['success' => false, 'error' => 'Invalid URL'], 400);
        }

        Shell::openExternal($url);

        return response()->json(['success' => true]);
    })->name('open-external');

    Route::post('/open-terminal', function (Request $request) {
        $user = $request->input('user');
        $host = $request->input('host');
        $path = $request->input('path');

        if (! $user || ! $host) {
            return response()->json(['success' => false, 'error' => 'Missing user or host'], 400);
        }

        $terminal = \App\Models\Setting::getTerminal();

        // Build SSH command - cd to path if provided, then start shell
        // Use 'bash' explicitly since $SHELL would expand locally
        $cdCommand = $path ? "cd {$path} && exec bash" : 'exec bash';
        $sshCommand = "ssh {$user}@{$host} -t \"{$cdCommand}\"";

        $escapedCommand = str_replace('"', '\\"', $sshCommand);

        switch ($terminal) {
            case 'iTerm':
                $appleScript = <<<APPLESCRIPT
tell application "iTerm"
    activate
    create window with default profile command "{$escapedCommand}"
end tell
APPLESCRIPT;
                \Illuminate\Support\Facades\Process::run(['osascript', '-e', $appleScript]);
                break;

            case 'Ghostty':
                // Use AppleScript to open new window in existing instance and run command
                $appleScript = <<<APPLESCRIPT
tell application "Ghostty"
    activate
end tell
delay 0.3
tell application "System Events"
    tell process "Ghostty"
        click menu item "New Window" of menu "File" of menu bar 1
    end tell
end tell
delay 0.3
tell application "System Events"
    keystroke "{$escapedCommand}"
    keystroke return
end tell
APPLESCRIPT;
                \Illuminate\Support\Facades\Process::run(['osascript', '-e', $appleScript]);
                break;

            case 'Warp':
                // Warp supports opening with a command via AppleScript
                $appleScript = <<<APPLESCRIPT
tell application "Warp" to activate
delay 0.5
tell application "System Events"
    keystroke "t" using command down
    delay 0.3
    keystroke "{$escapedCommand}"
    keystroke return
end tell
APPLESCRIPT;
                \Illuminate\Support\Facades\Process::run(['osascript', '-e', $appleScript]);
                break;

            case 'kitty':
                \Illuminate\Support\Facades\Process::run(['kitty', '--single-instance', 'sh', '-c', $sshCommand]);
                break;

            case 'Alacritty':
                \Illuminate\Support\Facades\Process::run(['open', '-na', 'Alacritty', '--args', '-e', 'sh', '-c', $sshCommand]);
                break;

            case 'Hyper':
                \Illuminate\Support\Facades\Process::run(['open', '-a', 'Hyper']);
                // Hyper doesn't have great CLI support, so we just open it
                break;

            case 'Terminal':
            default:
                $appleScript = "tell application \"Terminal\" to do script \"{$escapedCommand}\"";
                \Illuminate\Support\Facades\Process::run(['osascript', '-e', $appleScript]);
                \Illuminate\Support\Facades\Process::run(['osascript', '-e', 'tell application "Terminal" to activate']);
                break;
        }

        return response()->json(['success' => true]);
    })->name('open-terminal');

    // Include environment-scoped routes WITH prefix
    Route::prefix('environments/{environment}')
        ->group(base_path('routes/environment.php'));
} else {
    // Gate desktop-only management routes with 403 in web mode
    // These MUST come before the compatibility routes below
    Route::any('/environments', fn() => abort(403));
    Route::any('/environments/create', fn() => abort(403));
    Route::any('/ssh-keys/{any?}', fn() => abort(403))->where('any', '.*');
    Route::any('/open-terminal', fn() => abort(403));
    Route::any('/open-external', fn() => abort(403));

    // Web: Flat routes, middleware injects implicit environment
    // Web: Routes
    Route::middleware('implicit.environment')->group(function() {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        
        // Flat routes (e.g. /projects)
        Route::group([], base_path('routes/environment.php'));
    });

    // Prefixed routes for compatibility (e.g. /environments/1/projects)
    // These work in web mode too, but are not the primary way to access them
    Route::prefix('environments/{environment}')
        ->group(base_path('routes/environment.php'));
            
    // Add show route for compatibility
    Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');

    // Gate environment edit route specifically
    Route::any('/environments/{environment}/edit', fn() => abort(403));
}

// SHARED ROUTES (Outside conditional)

// API routes for environment data
Route::prefix('api/environments')->group(function (): void {
    Route::get('tlds', [EnvironmentController::class, 'getAllTlds'])->name('api.environments.tlds');
});

Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
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
