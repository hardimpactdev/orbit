<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InstancePowerController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::post('instances/{instance}/sleep', [InstancePowerController::class, 'sleep'])
        ->whereNumber('instance')->name('instance:sleep');
    Route::post('instances/{instance}/wake', [InstancePowerController::class, 'wake'])
        ->whereNumber('instance')->name('instance:wake');
});
