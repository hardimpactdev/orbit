<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FleetMaintenanceController;
use App\Http\Controllers\Api\FleetPeerDnsController;
use App\Http\Controllers\Api\FleetRecipesController;
use App\Http\Controllers\Api\FleetRolloutsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('fleet/nodes/{node}/visit-policy', [FleetMaintenanceController::class, 'visitPolicy'])->name('fleet:visit:policy');
    Route::get('fleet/rollout/plan', [FleetMaintenanceController::class, 'rolloutPlan'])->name('fleet:rollout:plan');
    Route::post('fleet/nodes/{node}/rollout', [FleetMaintenanceController::class, 'rolloutNode'])->name('fleet:rollout:node');
    Route::post('fleet/recipe/state', [FleetMaintenanceController::class, 'recipeState'])->name('fleet:recipe:state');
    Route::get('fleet/recipe/plan', [FleetMaintenanceController::class, 'recipePlan'])->name('fleet:recipe:plan');
    Route::get('fleet/recipe-pr/plan', [FleetMaintenanceController::class, 'recipePrPlan'])->name('fleet:recipe-pr:plan');
    Route::post('fleet/recipe-pr', [FleetMaintenanceController::class, 'recipePr'])->name('fleet:recipe-pr');
    Route::get('fleet/peer-dns/targets', [FleetMaintenanceController::class, 'peerDnsTargets'])->name('fleet:peer-dns:targets');
    Route::post('fleet/nodes/{node}/recipe', [FleetRecipesController::class, 'store'])->name('fleet:recipe:converge');
    Route::post('fleet/nodes/{node}/peer-dns', [FleetPeerDnsController::class, 'store'])->name('fleet:peer-dns:reconcile');
    Route::get('fleet/rollout', [FleetRolloutsController::class, 'show'])
        ->name('fleet:rollout:status');
    Route::post('fleet/rollout/resume', [FleetRolloutsController::class, 'resume'])
        ->name('fleet:rollout:resume');
});
