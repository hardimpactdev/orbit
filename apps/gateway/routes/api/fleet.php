<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FleetMaintenanceController;
use App\Http\Controllers\Api\FleetPeerDnsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('fleet/rollout', [FleetMaintenanceController::class, 'rollout'])->name('fleet:rollout');
    Route::post('fleet/reboot', [FleetMaintenanceController::class, 'reboot'])->name('fleet:reboot');
    Route::post('fleet/cli/converge', [FleetMaintenanceController::class, 'cli'])->name('fleet:cli:converge');
    Route::post('fleet/nodes/{node}/peer-dns', [FleetPeerDnsController::class, 'store'])->name('fleet:peer-dns:reconcile');
});
