<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('analytics/update', [AnalyticsController::class, 'update'])->name('analytics:update');
    Route::get('analytics/credentials', [AnalyticsController::class, 'credentials'])->name('analytics:credentials');
    Route::put('analytics/credentials', [AnalyticsController::class, 'setCredentials'])->name('analytics:credentials:set');
    Route::delete('analytics/credentials', [AnalyticsController::class, 'unsetCredentials'])->name('analytics:credentials:unset');
});
