<?php

declare(strict_types=1);

use App\Http\Controllers\Api\OperationsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    // Operations run on the Gateway queue; each read is one database query (docs/reference/operations.md).
    Route::get('operations', [OperationsController::class, 'index'])->name('operation:list');
    Route::get('operations/{operation}', [OperationsController::class, 'show'])->whereNumber('operation')->name('operation:show');
    Route::post('operations/{operation}/cancel', [OperationsController::class, 'cancel'])->whereNumber('operation')->name('operation:cancel');
    Route::post('operations/{operation}/resume', [OperationsController::class, 'resume'])->whereNumber('operation')->name('operation:resume');
});
