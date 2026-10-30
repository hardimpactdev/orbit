<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DirectOperationsController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('direct-operations/{operation}', [DirectOperationsController::class, 'show'])->whereNumber('operation')->name('operation:direct:show');
    Route::post('direct-operations/{operation}/result', [DirectOperationsController::class, 'result'])->whereNumber('operation')->name('operation:direct:result');
    Route::post('direct-operations/{operation}/primitive', [DirectOperationsController::class, 'primitive'])->whereNumber('operation')->name('operation:direct:primitive');
    Route::get('direct-operations/{operation}/private-material', [DirectOperationsController::class, 'privateMaterial'])->whereNumber('operation')->name('operation:direct:private-material');
    Route::post('direct-operations/{operation}/secret-output', [DirectOperationsController::class, 'secretOutput'])->whereNumber('operation')->name('operation:direct:secret-output');
    // Operations run on the Gateway queue; each read is one database query (docs/reference/operations.md).
    Route::get('operations', [OperationsController::class, 'index'])->name('operation:list');
    Route::get('operations/{operation}', [OperationsController::class, 'show'])->whereNumber('operation')->name('operation:show');
    Route::post('operations/{operation}/cancel', [OperationsController::class, 'cancel'])->whereNumber('operation')->name('operation:cancel');
    Route::post('operations/{operation}/resume', [OperationsController::class, 'resume'])->whereNumber('operation')->name('operation:resume');
});
