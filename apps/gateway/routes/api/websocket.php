<?php

declare(strict_types=1);

use App\Http\Controllers\Api\WebSocketAppsController;
use App\Http\Controllers\Api\WebSocketRegistryController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\RequireWebSocketRuntimeSecret;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireWebSocketRuntimeSecret::class])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->prefix('websocket-registry')->group(function (): void {
        Route::get('', [WebSocketRegistryController::class, 'snapshot'])->name('websocket:registry:snapshot');
        Route::post('processes/{runtime}/boots', [WebSocketRegistryController::class, 'boot'])->whereUuid('runtime')->name('websocket:runtime:boot');
        Route::put('processes/{runtime}', [WebSocketRegistryController::class, 'report'])->whereUuid('runtime')->name('websocket:runtime:report');
    });

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('websocket-sync', [WebSocketAppsController::class, 'sync'])->name('websocket:sync');
    Route::get('websocket-operations/{operation}', [WebSocketAppsController::class, 'operation'])->whereUuid('operation')->name('websocket:operation:show');
    Route::post('websocket-apps/{websocket_app}/rotate', [WebSocketAppsController::class, 'rotate'])
        ->where('websocket_app', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('websocket:app:rotate');
    Route::get('websocket-apps', [WebSocketAppsController::class, 'index'])
        ->name('websocket:app:list');
    Route::post('websocket-apps', [WebSocketAppsController::class, 'store'])
        ->name('websocket:app:create');
    Route::get('websocket-apps/{websocket_app}', [WebSocketAppsController::class, 'show'])
        ->where('websocket_app', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('websocket:app:show');
    Route::delete('websocket-apps/{websocket_app}', [WebSocketAppsController::class, 'destroy'])
        ->where('websocket_app', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('websocket:app:destroy');
});
