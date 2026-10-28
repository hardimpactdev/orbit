<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GrafanaAccessAuthorizationController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('metrics/grafana/authorize', [GrafanaAccessAuthorizationController::class, 'show'])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->name('metrics:grafana:authorize');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {

    Route::post('metrics', [MetricsController::class, 'store'])->name('metrics:enable');
    Route::delete('metrics', [MetricsController::class, 'destroy'])->name('metrics:disable');
    Route::get('metrics/status', [MetricsController::class, 'status'])->name('metrics:status');
    Route::get('metrics/credentials', [MetricsController::class, 'credentials'])->name('metrics:credentials');
    Route::post('metrics/credentials/reset', [MetricsController::class, 'reset'])->name(
        'metrics:credentials:reset',
    );
    Route::put('metrics/exporters/{node}', [MetricsController::class, 'enableExporter'])
        ->whereNumber('node')->name('metrics:exporter:enable');
    Route::delete('metrics/exporters/{node}', [MetricsController::class, 'disableExporter'])
        ->whereNumber('node')
        ->name('metrics:exporter:disable');
});
