<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DatabaseConnectionAttachmentsController;
use App\Http\Controllers\Api\InstanceAnalyticsController;
use App\Http\Controllers\Api\InstanceAutomaticDeployPolicyController;
use App\Http\Controllers\Api\InstanceClonesController;
use App\Http\Controllers\Api\InstanceDependenciesController;
use App\Http\Controllers\Api\InstanceDeploymentsController;
use App\Http\Controllers\Api\InstanceDeploymentSourceController;
use App\Http\Controllers\Api\InstanceDeployStepsController;
use App\Http\Controllers\Api\InstanceLogsController;
use App\Http\Controllers\Api\InstanceLogStreamsController;
use App\Http\Controllers\Api\InstanceQueueController;
use App\Http\Controllers\Api\InstanceReleasesController;
use App\Http\Controllers\Api\InstanceRollbacksController;
use App\Http\Controllers\Api\InstancesController;
use App\Http\Controllers\Api\InstanceSmokeController;
use App\Http\Controllers\Api\InstanceTransfersController;
use App\Http\Controllers\Api\ProjectLifecycleStepsController;
use App\Http\Controllers\Api\ResolveDependencyInstanceController;
use App\Http\Controllers\Api\ResolveDirectoryInstanceController;
use App\Http\Controllers\Api\WebSocketAppAttachmentsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('projects/{project}/setup-steps', [ProjectLifecycleStepsController::class, 'setupIndex'])->name('instance:setup-step:list');
    Route::post('projects/{project}/setup-steps', [ProjectLifecycleStepsController::class, 'setupStore'])->name('instance:setup-step:create');
    Route::patch('projects/{project}/setup-steps/{step}', [ProjectLifecycleStepsController::class, 'setupUpdate'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('instance:setup-step:update');
    Route::delete('projects/{project}/setup-steps/{step}', [ProjectLifecycleStepsController::class, 'setupDestroy'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('instance:setup-step:destroy');
    Route::get('projects/{project}/teardown-steps', [ProjectLifecycleStepsController::class, 'teardownIndex'])->name('instance:teardown-step:list');
    Route::post('projects/{project}/teardown-steps', [ProjectLifecycleStepsController::class, 'teardownStore'])->name('instance:teardown-step:create');
    Route::patch('projects/{project}/teardown-steps/{step}', [ProjectLifecycleStepsController::class, 'teardownUpdate'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('instance:teardown-step:update');
    Route::delete('projects/{project}/teardown-steps/{step}', [ProjectLifecycleStepsController::class, 'teardownDestroy'])
        ->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')
        ->name('instance:teardown-step:destroy');
    Route::get('instances/resolve-directory', [ResolveDirectoryInstanceController::class, '__invoke'])->name('instance:resolve-directory');
    Route::get('instances/resolve', [ResolveDependencyInstanceController::class, '__invoke'])->name('instance:resolve');
    Route::get('instances/{instance}/dependencies', [InstanceDependenciesController::class, 'show'])->whereNumber('instance')->name('instance:dependencies:show');
    Route::post('instances/{instance}/dependencies/scan', [InstanceDependenciesController::class, 'scan'])->whereNumber('instance')->name('instance:dependencies:scan');
    Route::post('instances/{instance}/dependencies/update', [InstanceDependenciesController::class, 'update'])->whereNumber('instance')->name('instance:dependencies:update');
    Route::get('instances/{instance}/analytics/stats', [InstanceAnalyticsController::class, 'stats'])->whereNumber('instance')->name('instance:analytics:stats');
    Route::get('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'show'])->whereNumber('instance')->name('instance:analytics:show');
    Route::post('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'enable'])->whereNumber('instance')->name('instance:analytics:enable');
    Route::delete('instances/{instance}/analytics', [InstanceAnalyticsController::class, 'disable'])->whereNumber('instance')->name('instance:analytics:disable');
    Route::get('instances', [InstancesController::class, 'index'])->name('instance:list');
    Route::get('instances/{instance}', [InstancesController::class, 'show'])->name('instance:show');
    Route::patch('instances/{instance}', [InstancesController::class, 'update'])->name('instance:update');
    Route::post('instances/{instance}/rename', [InstancesController::class, 'rename'])->name('instance:rename');
    Route::post('instances', [InstancesController::class, 'store'])->name('instance:create');
    Route::post('instances/register', [InstancesController::class, 'register'])->name('instance:register');
    Route::post('instances/{candidate}/clone', [InstanceClonesController::class, 'store'])
        ->whereNumber('candidate')
        ->name('instance:clone');
    Route::post('instances/{instance}/transfer', [InstanceTransfersController::class, 'store'])
        ->whereNumber('instance')
        ->name('instance:transfer');
    Route::delete('instances/{instance}', [InstancesController::class, 'destroy'])
        ->name('instance:destroy');
    Route::post('instances/{instance}/setup', [InstancesController::class, 'setup'])
        ->whereNumber('instance')
        ->name('instance:setup');
    Route::get(
        'instances/{instance}/deploy-steps',
        [InstanceDeployStepsController::class, 'index'],
    )->name('instance:deploy-step:list');
    Route::post(
        'instances/{instance}/deploy-steps',
        [InstanceDeployStepsController::class, 'store'],
    )->name('instance:deploy-step:create');
    Route::patch(
        'instances/{instance}/deploy-steps/{step}',
        [InstanceDeployStepsController::class, 'update'],
    )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:update');
    Route::delete(
        'instances/{instance}/deploy-steps/{step}',
        [InstanceDeployStepsController::class, 'destroy'],
    )->where('step', '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?')->name('instance:deploy-step:destroy');
    Route::post(
        'instances/{instance}/deploy',
        [InstanceDeploymentsController::class, 'store'],
    )->name('instance:deploy');

    Route::get('instances/{instance}/deployment-source/head', [InstanceDeploymentSourceController::class, 'head'])->name('instance:deployment-source:head');
    Route::get('instances/{instance}/deployment-source/commits', [InstanceDeploymentSourceController::class, 'commits'])->name('instance:deployment-source:commits');
    Route::get('instances/{instance}/deployment-source/checks', [InstanceDeploymentSourceController::class, 'checks'])->name('instance:deployment-source:checks');
    Route::post('instances/{instance}/deploy/automatic/resolve', [InstanceDeploymentsController::class, 'resolve'])->name('instance:deploy:automatic:resolve');
    Route::get('instances/{instance}/deploy/automatic/receipt', [InstanceDeploymentsController::class, 'receipt'])->name('instance:deploy:automatic:receipt');
    Route::post('instances/{instance}/smoke', [InstanceSmokeController::class, 'store'])->name('instance:smoke');
    Route::get('instances/{instance}/smoke/receipt', [InstanceSmokeController::class, 'receipt'])->name('instance:smoke:receipt');
    Route::get('instances/{instance}/smoke/context', [InstanceSmokeController::class, 'context'])->name('instance:smoke:context');
    Route::post('instances/{instance}/smoke/failure', [InstanceSmokeController::class, 'failure'])->name('instance:smoke:failure');
    Route::get('instances/{instance}/smoke/failure/receipt', [InstanceSmokeController::class, 'failureReceipt'])->name('instance:smoke:failure:receipt');
    Route::post('instances/{instance}/smoke/rollback', [InstanceSmokeController::class, 'rollback'])->name('instance:smoke:rollback');
    Route::get('instances/{instance}/smoke/rollback/receipt', [InstanceSmokeController::class, 'rollbackReceipt'])->name('instance:smoke:rollback:receipt');
    Route::put('instances/{instance}/auto-deploy/policy', [InstanceAutomaticDeployPolicyController::class, 'update'])->name('instance:auto-deploy:policy:update');
    Route::post('instances/{instance}/auto-deploy/events', [InstanceAutomaticDeployPolicyController::class, 'store'])->name('instance:auto-deploy:event:create');
    Route::post('instances/{instance}/deploy/automatic', [InstanceDeploymentsController::class, 'automatic'])->name('instance:deploy:automatic');
    Route::post(
        'instances/{instance}/rollback',
        [InstanceRollbacksController::class, 'store'],
    )->name('instance:rollback');
    Route::get(
        'instances/{instance}/releases',
        [InstanceReleasesController::class, 'index'],
    )->name('instance:release:list');
    Route::get(
        'instances/{instance}/queue',
        [InstanceQueueController::class, 'show'],
    )->whereNumber('instance')->name('instance:queue');
    Route::get(
        'instances/{instance}/logs',
        [InstanceLogsController::class, 'show'],
    )->whereNumber('instance')->name('instance:logs');
    Route::post(
        'instances/{instance}/log-streams',
        [InstanceLogStreamsController::class, 'store'],
    )->whereNumber('instance')->name('instance:log-stream:create');
    Route::put(
        'instances/{instance}/log-streams/{stream}',
        [InstanceLogStreamsController::class, 'update'],
    )->whereNumber('instance')->where('stream', '[0-9a-f]{32}')
        ->withoutMiddleware(RecordCommandActivity::class)->name('instance:log-stream:renew');
    Route::delete(
        'instances/{instance}/log-streams/{stream}',
        [InstanceLogStreamsController::class, 'destroy'],
    )->whereNumber('instance')->where('stream', '[0-9a-f]{32}')->name('instance:log-stream:destroy');
    Route::get(
        'instances/{instance}/deployments',
        [InstanceDeploymentsController::class, 'index'],
    )->name('instance:deployment:list');
    Route::get(
        'deployments/{deployment}',
        [InstanceDeploymentsController::class, 'show'],
    )->name('instance:deployment:show');
    Route::put(
        'instances/{instance}/database-connections/{database_connection}',
        [DatabaseConnectionAttachmentsController::class, 'store'],
    )
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('instance:database:add');
    Route::delete(
        'instances/{instance}/database-connections/{database_connection}',
        [DatabaseConnectionAttachmentsController::class, 'destroy'],
    )
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('instance:database:remove');
    Route::put(
        'instances/{instance}/websocket-apps/{websocket_app}',
        [WebSocketAppAttachmentsController::class, 'store'],
    )
        ->where('websocket_app', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('instance:websocket:add');
    Route::delete(
        'instances/{instance}/websocket-apps/{websocket_app}',
        [WebSocketAppAttachmentsController::class, 'destroy'],
    )
        ->where('websocket_app', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('instance:websocket:remove');
});
