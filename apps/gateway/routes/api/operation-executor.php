<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OperationExecutorController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::get('operation-executor/{operation}/children', [OperationExecutorController::class, 'readChildren'])
    ->middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->whereNumber('operation')->name('operation:executor:children:read');

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::get('operation-executor/claimable', [OperationExecutorController::class, 'claimable'])->withoutMiddleware(RecordCommandActivity::class)->name('operation:executor:claimable');
    Route::post('operation-executor/claim', [OperationExecutorController::class, 'claim'])->name('operation:executor:claim');
    Route::get('operation-executor/{operation}/receipts/{step}', [OperationExecutorController::class, 'effectReceipt'])
        ->whereNumber('operation')->where('step', '[a-z0-9_.:-]{1,128}')->name('operation:executor:receipt');
    foreach (['heartbeat', 'step', 'complete', 'run', 'children', 'wait'] as $primitive) {
        Route::post('operation-executor/{operation}/'.$primitive, [OperationExecutorController::class, $primitive])
            ->whereNumber('operation')->name('operation:executor:'.$primitive);
    }
});
