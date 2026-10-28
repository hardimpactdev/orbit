<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ProcessesController;
use App\Http\Controllers\Api\ProcessLogStreamsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('processes', [ProcessesController::class, 'index'])
        ->name('process:list');
    Route::get('processes/{process}/logs', [ProcessesController::class, 'logs'])
        ->name('process:logs');
    Route::post('processes/{process}/log-streams', [ProcessLogStreamsController::class, 'store'])
        ->name('process:log-stream:create');
    Route::put('processes/{process}/log-streams/{stream}', [ProcessLogStreamsController::class, 'update'])
        ->where('stream', '[0-9a-f]{32}')
        ->withoutMiddleware(RecordCommandActivity::class)->name('process:log-stream:renew');
    Route::delete('processes/{process}/log-streams/{stream}', [ProcessLogStreamsController::class, 'destroy'])
        ->where('stream', '[0-9a-f]{32}')->name('process:log-stream:destroy');
    Route::post('processes', [ProcessesController::class, 'store'])
        ->name('process:create');
    Route::patch('processes/{process}', [ProcessesController::class, 'update'])
        ->name('process:update');
    Route::post('processes/{process}/start', [ProcessesController::class, 'start'])
        ->name('process:start');
    Route::post('processes/{process}/stop', [ProcessesController::class, 'stop'])
        ->name('process:stop');
    Route::post('processes/{process}/restart', [ProcessesController::class, 'restart'])
        ->name('process:restart');
    Route::delete('processes/{process}', [ProcessesController::class, 'destroy'])
        ->name('process:destroy');
});
