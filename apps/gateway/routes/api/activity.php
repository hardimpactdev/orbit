<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivitiesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('activities', [ActivitiesController::class, 'index'])
        ->name('activity:list');
    Route::get('activities/{activity}', [ActivitiesController::class, 'show'])
        ->name('activity:show');
});
