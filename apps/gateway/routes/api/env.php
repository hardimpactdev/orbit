<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InstanceEnvironmentImportsController;
use App\Http\Controllers\Api\InstanceEnvironmentSynchronizationsController;
use App\Http\Controllers\Api\InstanceEnvironmentValuesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post(
        'instances/{instance}/environment/import',
        [InstanceEnvironmentImportsController::class, 'store'],
    )->name('env:import');
    Route::post(
        'instances/{instance}/environment/sync',
        [InstanceEnvironmentSynchronizationsController::class, 'store'],
    )->name('env:sync');
    Route::put(
        'instances/{instance}/environment/{key}',
        [InstanceEnvironmentValuesController::class, 'update'],
    )->name('env:update');
});
