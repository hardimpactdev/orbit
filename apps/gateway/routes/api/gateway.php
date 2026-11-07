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
        Route::get('release-automation', [GatewayReleaseAutomationController::class, 'show'])->name('gateway:auto-release:status');
        Route::post('release-automation/enable', [GatewayReleaseAutomationController::class, 'enable'])->name('gateway:auto-release:enable');
        Route::post('release-automation/disable', [GatewayReleaseAutomationController::class, 'disable'])->name('gateway:auto-release:disable');
        Route::post('release-automation/resume', [GatewayReleaseAutomationController::class, 'resume'])->name('gateway:auto-release:resume');
    });
