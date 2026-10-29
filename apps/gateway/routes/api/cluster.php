<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ClusterRouterController;
use App\Http\Controllers\Api\ClustersController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('clusters', [ClustersController::class, 'index'])->name('cluster:list');
    Route::post('clusters', [ClustersController::class, 'store'])->name('cluster:create');
    Route::get('clusters/{cluster}', [ClustersController::class, 'show'])
        ->whereNumber('cluster')
        ->name('cluster:show');
    Route::patch('clusters/{cluster}', [ClustersController::class, 'update'])
        ->whereNumber('cluster')
        ->name('cluster:update');
    Route::delete('clusters/{cluster}', [ClustersController::class, 'destroy'])
        ->whereNumber('cluster')
        ->name('cluster:destroy');
    Route::put('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'attach'])
        ->whereNumber('cluster')
        ->whereNumber('node')
        ->name('cluster:node:add');
    Route::delete('clusters/{cluster}/nodes/{node}', [ClustersController::class, 'detach'])
        ->whereNumber('cluster')
        ->whereNumber('node')
        ->name('cluster:node:remove');
    Route::put('clusters/{cluster}/router/{node}', [ClusterRouterController::class, 'set'])
        ->whereNumber('cluster')
        ->whereNumber('node')
        ->name('cluster:router:set');
    Route::delete('clusters/{cluster}/router', [ClusterRouterController::class, 'unset'])
        ->whereNumber('cluster')
        ->name('cluster:router:unset');
});
