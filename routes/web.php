<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvironmentController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SshKeyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Native\Laravel\Facades\Shell;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('environments', EnvironmentController::class);

// Redirect old server routes to environments
Route::redirect('/servers', '/environments')->name('servers.index');
Route::redirect('/servers/{id}', '/environments/{id}');

// API routes for environment data
Route::prefix('api/environments')->group(function (): void {
    Route::get('tlds', [EnvironmentController::class, 'getAllTlds'])->name('api.environments.tlds');
});

Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/notifications', [SettingsController::class, 'toggleNotifications'])->name('settings.notifications');
Route::post('settings/menu-bar', [SettingsController::class, 'toggleMenuBar'])->name('settings.menu-bar');

// SSH Key Management
Route::prefix('ssh-keys')->name('ssh-keys.')->group(function (): void {
    Route::post('/', [SshKeyController::class, 'store'])->name('store');
    Route::put('{sshKey}', [SshKeyController::class, 'update'])->name('update');
    Route::delete('{sshKey}', [SshKeyController::class, 'destroy'])->name('destroy');
    Route::post('{sshKey}/default', [SshKeyController::class, 'setDefault'])->name('default');
    Route::get('available', [SshKeyController::class, 'getAvailableKeys'])->name('available');
});

Route::prefix('environments/{environment}')->name('environments.')->group(function (): void {
    Route::post('set-default', [EnvironmentController::class, 'setDefault'])->name('set-default');

    // Note: test-connection, status, sites, config, worktrees moved to routes/api.php (stateless)

    // Doctor (health checks)
    Route::get('doctor', [EnvironmentController::class, 'runDoctor'])->name('doctor');
    Route::get('doctor/quick', [EnvironmentController::class, 'quickCheck'])->name('doctor.quick');
    Route::post('doctor/fix/{check}', [EnvironmentController::class, 'fixDoctorIssue'])->name('doctor.fix');

    // Environment pages
    Route::get('projects', [EnvironmentController::class, 'projectsPage'])->name('projects');
    Route::get('services', [EnvironmentController::class, 'servicesPage'])->name('services');
    Route::get('orchestrator', [EnvironmentController::class, 'orchestrator'])->name('orchestrator');
    Route::post('orchestrator/enable', [EnvironmentController::class, 'enableOrchestrator'])->name('orchestrator.enable');
    Route::post('orchestrator/disable', [EnvironmentController::class, 'disableOrchestrator'])->name('orchestrator.disable');
    Route::post('orchestrator/install', [EnvironmentController::class, 'installOrchestrator'])->name('orchestrator.install');
    Route::get('orchestrator/detect', [EnvironmentController::class, 'detectOrchestrator'])->name('orchestrator.detect');
    Route::post('orchestrator/reconcile', [EnvironmentController::class, 'reconcileOrchestrator'])->name('orchestrator.reconcile');
    Route::get('orchestrator/services', [EnvironmentController::class, 'orchestratorServices'])->name('orchestrator.services');
    Route::get('orchestrator/projects', [EnvironmentController::class, 'orchestratorProjects'])->name('orchestrator.projects');
    Route::get('settings', [EnvironmentController::class, 'settings'])->name('settings');
    Route::post('settings', [EnvironmentController::class, 'updateSettings'])->name('settings.update');

    // Note: api/projects moved to routes/api.php (stateless)
    Route::post('start', [EnvironmentController::class, 'start'])->name('start');
    Route::post('stop', [EnvironmentController::class, 'stop'])->name('stop');
    Route::post('restart', [EnvironmentController::class, 'restart'])->name('restart');
    Route::post('php', [EnvironmentController::class, 'changePhp'])->name('php');
    Route::post('php/reset', [EnvironmentController::class, 'resetPhp'])->name('php.reset');

    // Individual service routes
    Route::get('services/available', [EnvironmentController::class, 'availableServices'])->name('services.available');
    Route::post('services/{service}/start', [EnvironmentController::class, 'startService'])->name('services.start');
    Route::post('services/{service}/stop', [EnvironmentController::class, 'stopService'])->name('services.stop');
    Route::post('services/{service}/restart', [EnvironmentController::class, 'restartService'])->name('services.restart');
    Route::post('host-services/{service}/start', [EnvironmentController::class, 'startHostService'])->name('host-services.start');
    Route::post('host-services/{service}/stop', [EnvironmentController::class, 'stopHostService'])->name('host-services.stop');
    Route::post('host-services/{service}/restart', [EnvironmentController::class, 'restartHostService'])->name('host-services.restart');
    Route::get('services/{service}/logs', [EnvironmentController::class, 'serviceLogs'])->name('services.logs');
    Route::post('services/{service}/enable', [EnvironmentController::class, 'enableService'])->name('services.enable');
    Route::delete('services/{service}', [EnvironmentController::class, 'disableService'])->name('services.disable');
    Route::put('services/{service}/config', [EnvironmentController::class, 'configureService'])->name('services.config');
    Route::get('services/{service}/info', [EnvironmentController::class, 'serviceInfo'])->name('services.info');

    // Note: GET config and worktrees moved to routes/api.php (stateless)
    Route::post('config', [EnvironmentController::class, 'saveConfig'])->name('config.save');
    Route::get('reverb-config', [EnvironmentController::class, 'getReverbConfig'])->name('reverb-config');

    // Worktree modification routes (need session for CSRF)
    Route::post('worktrees/unlink', [EnvironmentController::class, 'unlinkWorktree'])->name('worktrees.unlink');
    Route::post('worktrees/refresh', [EnvironmentController::class, 'refreshWorktrees'])->name('worktrees.refresh');

    // Project routes
    Route::get('projects/create', [EnvironmentController::class, 'createProject'])->name('projects.create');
    Route::post('projects', [EnvironmentController::class, 'storeProject'])->name('projects.store');
    Route::delete('projects/{projectName}', [EnvironmentController::class, 'destroyProject'])->name('projects.destroy');
    Route::post('projects/{projectName}/rebuild', [EnvironmentController::class, 'rebuildProject'])->name('projects.rebuild');
    Route::get('projects/{projectSlug}/provision-status', [EnvironmentController::class, 'provisionStatus'])->name('projects.provision-status');
    Route::post('template-defaults', [EnvironmentController::class, 'templateDefaults'])->name('template-defaults');
    Route::get('github-user', [EnvironmentController::class, 'githubUser'])->name('github-user');
    Route::post('github-repo-exists', [EnvironmentController::class, 'githubRepoExists'])->name('github-repo-exists');
    Route::get('linear-teams', [EnvironmentController::class, 'linearTeams'])->name('linear-teams');

    // Workspace routes (API endpoints moved to routes/api.php for stateless access)
    Route::get('workspaces', [EnvironmentController::class, 'workspaces'])->name('workspaces');
    Route::get('workspaces/create', [EnvironmentController::class, 'createWorkspace'])->name('workspaces.create');
    Route::post('workspaces', [EnvironmentController::class, 'storeWorkspace'])->name('workspaces.store');
    Route::get('workspaces/{workspace}', [EnvironmentController::class, 'showWorkspace'])->name('workspaces.show');
    Route::delete('workspaces/{workspace}', [EnvironmentController::class, 'destroyWorkspace'])->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/projects', [EnvironmentController::class, 'addWorkspaceProject'])->name('workspaces.projects.add');
    Route::delete('workspaces/{workspace}/projects/{project}', [EnvironmentController::class, 'removeWorkspaceProject'])->name('workspaces.projects.remove');

    // Package linking routes
    Route::get('projects/{project}/linked-packages', [EnvironmentController::class, 'linkedPackages'])->name('projects.linked-packages');
    Route::post('projects/{project}/link-package', [EnvironmentController::class, 'linkPackage'])->name('projects.link-package');
    Route::delete('projects/{project}/unlink-package/{package}', [EnvironmentController::class, 'unlinkPackage'])->name('projects.unlink-package');
});

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
    $escapedCommandSingle = str_replace("'", "'\\''", $sshCommand);

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
