<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GatewayDesiredFleetStatesController;
use App\Http\Controllers\Api\GatewayReleaseAutomationController;
use App\Http\Controllers\Api\GatewayReleasesController;
use App\Http\Controllers\Api\GatewayStatusesController;
use App\Http\Controllers\Api\RootCaCertificatesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::get('gateway/status', [GatewayStatusesController::class, 'show'])
    ->name('gateway:status');
Route::get('ca/root', [RootCaCertificatesController::class, 'show'])
    ->name('gateway:trust');
// Any active WireGuard peer, a managed Node or an operator machine, may read what the fleet should run (ADR 0202).
Route::middleware([RequireActiveWireGuardPeer::class])
    ->get('gateway/desired-fleet-state', [GatewayDesiredFleetStatesController::class, 'show'])
    ->name('gateway:desired-fleet-state');

// Gateway releases run in their own systemd unit; deploy and rollback answer 202 with the queued record.
// Smoke restarts nothing and writes no record, so it runs in the request, bounded below PHP-FPM's limit.
Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])
    ->prefix('gateway')->group(function (): void {
        Route::get('releases', [GatewayReleasesController::class, 'index'])->name('gateway:release:list');
        Route::post('releases', [GatewayReleasesController::class, 'store'])->name('gateway:release:deploy');
        Route::get('releases/{release}', [GatewayReleasesController::class, 'show'])
            ->where('release', '[0-9a-f]{1,40}')
            ->name('gateway:release:show');
        Route::post('releases/{release}/rollback', [GatewayReleasesController::class, 'rollback'])
            ->where('release', '[0-9a-f]{12}')
            ->name('gateway:release:rollback');
        Route::post('release-smoke', [GatewayReleasesController::class, 'smoke'])->name('gateway:release:smoke');
        Route::get('release-automation', [GatewayReleaseAutomationController::class, 'show'])->name('gateway:release:auto:status');
        Route::post('release-automation/enable', [GatewayReleaseAutomationController::class, 'enable'])->name('gateway:release:auto:enable');
        Route::post('release-automation/disable', [GatewayReleaseAutomationController::class, 'disable'])->name('gateway:release:auto:disable');
        Route::post('release-automation/resume', [GatewayReleaseAutomationController::class, 'resume'])->name('gateway:release:auto:resume');
    });
