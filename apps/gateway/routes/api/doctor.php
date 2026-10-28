<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DoctorRunsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('doctor', [DoctorRunsController::class, 'store'])
        ->name('doctor');
});
