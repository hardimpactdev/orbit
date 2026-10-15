<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Vault\Http\Controllers\VaultController;

Route::middleware([
    'api',
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
    RequireEnabledExtension::class.':vault',
])->prefix('api/v1')->group(function (): void {
    Route::get('vault', [VaultController::class, 'status'])->name('vault:status');
    Route::put('vault', [VaultController::class, 'setup'])->name('vault:setup');
    Route::post('vault/sync', [VaultController::class, 'sync'])->name('vault:sync');
    Route::delete('vault', [VaultController::class, 'teardown'])->name('vault:teardown');
});
