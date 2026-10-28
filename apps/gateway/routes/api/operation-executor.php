<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OperationExecutorController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::post('operation-executor/claim', [OperationExecutorController::class, 'claim'])->name('operation:executor:claim');
    foreach (['heartbeat', 'step', 'complete', 'run'] as $primitive) {
        Route::post('operation-executor/{operation}/'.$primitive, [OperationExecutorController::class, $primitive])
            ->whereNumber('operation')->name('operation:executor:'.$primitive);
    }
});
