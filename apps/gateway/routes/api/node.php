<?php

declare(strict_types=1);

use App\Http\Controllers\Api\NodeAccessController;
use App\Http\Controllers\Api\NodeExcludedProjectsController;
use App\Http\Controllers\Api\NodeFootprintsController;
use App\Http\Controllers\Api\NodeMetricsController;
use App\Http\Controllers\Api\NodeRolesController;
use App\Http\Controllers\Api\NodesController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('nodes', [NodesController::class, 'index'])
        ->name('node:list');
    Route::get('nodes/{node}', [NodesController::class, 'show'])
        ->name('node:show');
    Route::post('nodes/{node}/converge', [NodeFootprintsController::class, 'converge'])
        ->whereNumber('node')
        ->name('node:converge');
    Route::get('nodes/{node}/roles', [NodeRolesController::class, 'index'])
        ->whereNumber('node')
        ->name('node:role:list');
    Route::post('nodes/{node}/roles', [NodeRolesController::class, 'store'])
        ->whereNumber('node')
        ->name('node:role:add');
    Route::post('nodes/{node}/roles/{role}/relocate', [NodeRolesController::class, 'relocate'])
        ->whereNumber('node')
        ->name('node:role:relocate');
    Route::delete('nodes/{node}/roles/{role}', [NodeRolesController::class, 'destroy'])
        ->whereNumber('node')
        ->name('node:role:remove');
    Route::get('nodes/{node}/metrics', [NodeMetricsController::class, 'show'])
        ->name('node:metrics');
    Route::get('nodes/{node}/excluded-projects', [NodeExcludedProjectsController::class, 'index'])
        ->whereNumber('node')
        ->name('node:excluded-project:list');
    Route::post('nodes/{node}/excluded-projects/{project}', [NodeExcludedProjectsController::class, 'store'])
        ->whereNumber('node')
        ->whereNumber('project')
        ->name('node:excluded-project:add');
    Route::delete('nodes/{node}/excluded-projects/{project}', [NodeExcludedProjectsController::class, 'destroy'])
        ->whereNumber('node')
        ->whereNumber('project')
        ->name('node:excluded-project:remove');
    Route::post('nodes', [NodesController::class, 'store'])
        ->name('node:add');
    Route::patch('nodes/{node}/name', [NodesController::class, 'rename'])
        ->whereNumber('node')
        ->name('node:rename');
    Route::patch('nodes/{node}/wireguard-key', [NodesController::class, 'wireguardKey'])
        ->whereNumber('node')
        ->name('node:wireguard-key');
    Route::patch('nodes/{node}/settings', [NodesController::class, 'settings'])
        ->whereNumber('node')
        ->name('node:settings');
    Route::delete('nodes/{node}', [NodesController::class, 'destroy'])
        ->name('node:remove');
    Route::put(
        'nodes/{servingNode}/access/{consumerNode}',
        [NodeAccessController::class, 'store'],
    )->name('node:access:add');
    Route::delete(
        'nodes/{servingNode}/access/{consumerNode}',
        [NodeAccessController::class, 'destroy'],
    )->name('node:access:remove');
});
