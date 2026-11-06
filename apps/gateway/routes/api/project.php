<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ProjectDependenciesController;
use App\Http\Controllers\Api\ProjectDependencyReadTokenController;
use App\Http\Controllers\Api\ProjectDevelopmentDeployStepsController;
use App\Http\Controllers\Api\ProjectMaintenanceController;
use App\Http\Controllers\Api\ProjectPullRequestController;
use App\Http\Controllers\Api\ProjectRuntimeDefinitionsController;
use App\Http\Controllers\Api\ProjectsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->post('projects/{project}/dependency-read-token', [ProjectDependencyReadTokenController::class, 'store'])
    ->whereNumber('project')->name('project:dependency-read-token');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('projects/{project}/maintenance/enable', [ProjectMaintenanceController::class, 'enable'])->whereNumber('project')->name('project:maintenance:enable');
    Route::post('projects/{project}/maintenance/disable', [ProjectMaintenanceController::class, 'disable'])->whereNumber('project')->name('project:maintenance:disable');
    Route::post('projects/{project}/maintenance/inspect', [ProjectMaintenanceController::class, 'inspect'])->whereNumber('project')->name('project:maintenance:inspect');
    Route::post('projects/{project}/maintenance/routine', [ProjectMaintenanceController::class, 'routine'])->whereNumber('project')->name('project:maintenance:routine');
    Route::get('projects/{project}/dependencies', [ProjectDependenciesController::class, 'show'])->whereNumber('project')->name('project:dependencies:show');
    Route::get('projects', [ProjectsController::class, 'index'])->name('project:list');
    Route::get('projects/{project}', [ProjectsController::class, 'show'])->name('project:show');
    Route::post('projects', [ProjectsController::class, 'store'])->name('project:create');
    Route::patch('projects/{project}', [ProjectsController::class, 'update'])->name('project:update');
    Route::post('projects/{project}/review-and-merge/enable', [ProjectsController::class, 'enableReviewAndMerge'])->name('project:review-and-merge:enable');
    Route::post('projects/{project}/review-and-merge/disable', [ProjectsController::class, 'disableReviewAndMerge'])->name('project:review-and-merge:disable');
    Route::post('projects/{project}/pr-merge', [ProjectPullRequestController::class, 'merge'])->whereNumber('project')->name('project:pr-merge');
    Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('project:destroy');
    Route::get('projects/{project}/dev-deploy-steps', [ProjectDevelopmentDeployStepsController::class, 'index'])->name('project:dev-deploy-step:list');
    Route::post('projects/{project}/dev-deploy-steps', [ProjectDevelopmentDeployStepsController::class, 'store'])->name('project:dev-deploy-step:create');
    Route::patch('projects/{project}/dev-deploy-steps/{step}', [ProjectDevelopmentDeployStepsController::class, 'update'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('project:dev-deploy-step:update');
    Route::delete('projects/{project}/dev-deploy-steps/{step}', [ProjectDevelopmentDeployStepsController::class, 'destroy'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('project:dev-deploy-step:destroy');
    Route::prefix('projects/{project}/process-definitions')->scopeBindings()->group(function (): void {
        Route::get('/', [ProjectRuntimeDefinitionsController::class, 'processIndex'])
            ->name('project:process-definition:list');
        Route::post('/', [ProjectRuntimeDefinitionsController::class, 'processStore'])
            ->name('project:process-definition:create');
        Route::get('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processShow'])
            ->name('project:process-definition:show');
        Route::put('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processUpdate'])
            ->name('project:process-definition:update');
        Route::delete('{processDefinition}', [ProjectRuntimeDefinitionsController::class, 'processDestroy'])
            ->name('project:process-definition:destroy');
    });
    Route::prefix('projects/{project}/schedule-definitions')->scopeBindings()->group(function (): void {
        Route::get('/', [ProjectRuntimeDefinitionsController::class, 'scheduleIndex'])
            ->name('project:schedule-definition:list');
        Route::post('/', [ProjectRuntimeDefinitionsController::class, 'scheduleStore'])
            ->name('project:schedule-definition:create');
        Route::get('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleShow'])
            ->name('project:schedule-definition:show');
        Route::put('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleUpdate'])
            ->name('project:schedule-definition:update');
        Route::delete('{scheduleDefinition}', [ProjectRuntimeDefinitionsController::class, 'scheduleDestroy'])
            ->name('project:schedule-definition:destroy');
    });
});
