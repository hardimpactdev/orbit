<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RoutePublicationsController;
use App\Http\Controllers\Api\RoutesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('route-publications/{operation}', [RoutePublicationsController::class, 'show'])->whereNumber('operation')->name('route:publication:show');
    Route::post('route-publications/{operation}/publish', [RoutePublicationsController::class, 'publish'])->whereNumber('operation')->name('route:publication:publish');
    Route::post('route-publications/{operation}/result', [RoutePublicationsController::class, 'result'])->whereNumber('operation')->name('route:publication:result');
    Route::get('routes', [RoutesController::class, 'index'])->name('route:list');
    Route::post('routes', [RoutesController::class, 'store'])->name('route:create');
    Route::get('routes/{route}', [RoutesController::class, 'show'])
        ->whereNumber('route')
        ->name('route:show');
    Route::patch('routes/{route}', [RoutesController::class, 'update'])
        ->whereNumber('route')
        ->name('route:update');
    Route::put('routes/{route}/target', [RoutesController::class, 'setTarget'])
        ->whereNumber('route')
        ->name('route:target:set');
    Route::delete('routes/{route}/target', [RoutesController::class, 'clearTarget'])
        ->whereNumber('route')
        ->name('route:target:unset');
    Route::delete('routes/{route}', [RoutesController::class, 'destroy'])
        ->whereNumber('route')
        ->name('route:destroy');
});
