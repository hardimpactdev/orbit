<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Native\Laravel\Facades\Shell;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('servers', ServerController::class);

Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');

// SSH Key Management
Route::prefix('ssh-keys')->name('ssh-keys.')->group(function () {
    Route::post('/', [SshKeyController::class, 'store'])->name('store');
    Route::put('{sshKey}', [SshKeyController::class, 'update'])->name('update');
    Route::delete('{sshKey}', [SshKeyController::class, 'destroy'])->name('destroy');
    Route::post('{sshKey}/default', [SshKeyController::class, 'setDefault'])->name('default');
    Route::get('available', [SshKeyController::class, 'getAvailableKeys'])->name('available');
});

Route::prefix('servers/{server}')->name('servers.')->group(function () {
    Route::post('test-connection', [ServerController::class, 'testConnection'])->name('test-connection');
    Route::get('status', [ServerController::class, 'status'])->name('status');
    Route::get('sites', [ServerController::class, 'sites'])->name('sites');
    Route::post('start', [ServerController::class, 'start'])->name('start');
    Route::post('stop', [ServerController::class, 'stop'])->name('stop');
    Route::post('restart', [ServerController::class, 'restart'])->name('restart');
    Route::post('php', [ServerController::class, 'changePhp'])->name('php');
    Route::post('php/reset', [ServerController::class, 'resetPhp'])->name('php.reset');
});

Route::post('/open-external', function (Request $request) {
    $url = $request->input('url');

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
        return response()->json(['success' => false, 'error' => 'Invalid URL'], 400);
    }

    Shell::openExternal($url);

    return response()->json(['success' => true]);
})->name('open-external');

// CLI Management Routes
Route::prefix('cli')->name('cli.')->group(function () {
    Route::get('status', [SettingsController::class, 'cliStatus'])->name('status');
    Route::post('update', [SettingsController::class, 'cliUpdate'])->name('update');
});

// Provisioning Routes
Route::prefix('provision')->name('provision.')->group(function () {
    Route::get('/', [ProvisioningController::class, 'create'])->name('create');
    Route::post('/', [ProvisioningController::class, 'store'])->name('store');
    Route::post('/run', [ProvisioningController::class, 'provision'])->name('run');
});
