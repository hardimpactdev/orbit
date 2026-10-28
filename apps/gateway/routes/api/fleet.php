<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FleetRolloutsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('fleet/rollout', [FleetRolloutsController::class, 'show'])
        ->name('fleet:rollout:status');
    Route::post('fleet/rollout/resume', [FleetRolloutsController::class, 'resume'])
        ->name('fleet:rollout:resume');
});
