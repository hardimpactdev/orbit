<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ObservationController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->prefix('observation')->group(function (): void {
    Route::post('collections/{collection}/plan', [ObservationController::class, 'plan'])->whereUuid('collection')->name('observation:plan');
    Route::post('maintenance/health', [ObservationController::class, 'health'])->name('observation:health');
});
