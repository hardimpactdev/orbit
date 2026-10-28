<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ObservationController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->prefix('observation')->group(function (): void {
    Route::post('collections/{collection}/plan', [ObservationController::class, 'plan'])->whereUuid('collection')->name('observation:plan');
    Route::post('collections/{collection}/nodes/{node}', [ObservationController::class, 'observe'])->whereUuid('collection')->whereNumber('node')->name('observation:observe');
    Route::post('collections/{collection}/probes/{probe}', [ObservationController::class, 'inspect'])->whereUuid('collection')->whereNumber('probe')->name('observation:inspect');
    Route::post('collections/{collection}/signals', [ObservationController::class, 'signals'])->whereUuid('collection')->name('observation:signals');
    Route::post('maintenance/{routine}', [ObservationController::class, 'maintain'])->whereIn('routine', ['activities', 'operations', 'metrics', 'health', 'batches', 'failed'])->name('observation:maintain');
});
