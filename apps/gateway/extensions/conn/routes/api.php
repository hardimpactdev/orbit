<?php

declare(strict_types=1);

use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Conn\Http\Controllers\ConnEnvironmentRestartController;
use Orbit\Extensions\Conn\Http\Controllers\ConnEnvironmentsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnInstancesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnLaunchesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnNodesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnOperationsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnPairingsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProfilesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProjectBranchesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProjectsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnSessionsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnTasksController;
use Orbit\Extensions\Conn\Http\Controllers\ConnThreadsController;

// Conn reuses node access (/reference/conn#access): a server is reached through its registering Node.
// Registration needs none: it ties the server to the calling Node.
Route::middleware([
    'api',
    RequireActiveWireGuardPeer::class,
    RequireEnabledExtension::class.':conn',
])->prefix('api/v1/conn')->group(function (): void {
    Route::post('environments', [ConnEnvironmentsController::class, 'store'])->name('conn:environment:register');

    Route::middleware(RequireNodeAccess::class)->group(function (): void {
        Route::get('me', [ConnNodesController::class, 'show'])->name('conn:me');
        Route::get('operations/{operation}', [ConnOperationsController::class, 'show'])->whereNumber('operation')->name('conn:operation:show');
        Route::post('operations/{operation}/cancel', [ConnOperationsController::class, 'cancel'])->whereNumber('operation')->name('conn:operation:cancel');
        Route::withoutMiddleware(RecordCommandActivity::class)->group(function (): void {
            Route::get('launches', [ConnLaunchesController::class, 'index'])->name('conn:launch:list');
            Route::post('launches', [ConnLaunchesController::class, 'store'])->withoutMiddleware(RequireNodeAccess::class)->name('conn:launch:create');
            Route::get('launches/{operationId}', [ConnLaunchesController::class, 'show'])->whereNumber('operationId')->name('conn:launch:show');
        });
        Route::put('me/profile', [ConnNodesController::class, 'bindProfile'])->name('conn:me:profile');
        Route::get('profiles', [ConnProfilesController::class, 'index'])->name('conn:profile:list');
        Route::post('profiles', [ConnProfilesController::class, 'store'])->name('conn:profile:create');
        Route::get('profiles/{profile}/settings', [ConnProfilesController::class, 'settings'])
            ->whereNumber('profile')
            ->name('conn:profile:settings:show');
        Route::put('profiles/{profile}/settings', [ConnProfilesController::class, 'replaceSettings'])
            ->whereNumber('profile')
            ->name('conn:profile:settings:update');
        Route::patch('profiles/{profile}/settings', [ConnProfilesController::class, 'patchSettings'])
            ->whereNumber('profile')
            ->name('conn:profile:settings:patch');
        Route::get('projects/{project}/branches', [ConnProjectBranchesController::class, 'index'])->whereNumber('project')->name('conn:project:branches');
        Route::get('projects', [ConnProjectsController::class, 'index'])->withoutMiddleware(RecordCommandActivity::class)->name('conn:project:list');
        Route::get('projects/{project}/instances/check', [ConnProjectsController::class, 'checkInstanceName'])
            ->withoutMiddleware(RecordCommandActivity::class)->whereNumber('project')->name('conn:instance:name:check');
        Route::post('projects/{project}/instances', [ConnProjectsController::class, 'createInstance'])->whereNumber('project')->name('conn:instance:create');
        Route::get('instances/{instance_id}/work', [ConnInstancesController::class, 'work'])
            ->withoutMiddleware(RecordCommandActivity::class)->whereNumber('instance_id')->name('conn:instance:work');
        Route::post('instances/{instance_id}/destroy', [ConnInstancesController::class, 'destroy'])->whereNumber('instance_id')->name('conn:instance:destroy');
        Route::post('instances/{instance_id}/complete', [ConnInstancesController::class, 'complete'])->whereNumber('instance_id')->name('conn:instance:complete');
        Route::get('environments', [ConnEnvironmentsController::class, 'index'])->name('conn:environment:list');
        // Operator commands: restart idle container T3 servers for their machine name (/reference/conn#restart-for-the-machine-name).
        Route::post('instances/{instance}/t3-restart', [ConnEnvironmentRestartController::class, 'instance'])->whereNumber('instance')->name('conn:environment:restart');
        Route::post('nodes/{node}/t3-restart', [ConnEnvironmentRestartController::class, 'node'])->whereNumber('node')->name('conn:node:environments:restart');
        Route::post('environments/{environment}/renew', [ConnEnvironmentsController::class, 'renew'])->name('conn:environment:renew');
        Route::get('environments/{environment}/session', [ConnSessionsController::class, 'show'])->name('conn:environment:session');
        Route::post('environments/{environment}/sessions/cleanup', [ConnEnvironmentsController::class, 'cleanup'])->name('conn:environment:cleanup');
        // The read-only Tasks panel (/reference/conn#tasks-panel): the task list and show, read for a device.
        Route::middleware(RequireEnabledExtension::class.':tasks')->withoutMiddleware(RecordCommandActivity::class)->group(function (): void {
            Route::get('tasks', [ConnTasksController::class, 'index'])->name('conn:task:list');
            Route::get('tasks/{task}', [ConnTasksController::class, 'show'])->whereNumber('task')->name('conn:task:show');
        });
        Route::get('threads', [ConnThreadsController::class, 'index'])->withoutMiddleware(RecordCommandActivity::class)->name('conn:thread:list');
        Route::post('environments/{environment}/threads', [ConnThreadsController::class, 'store'])->name('conn:thread:record');
        Route::get('environments/{environment}/pairings', [ConnPairingsController::class, 'index'])->name('conn:pairing:list');
        Route::get('environments/{environment}/pairings/{pairing}', [ConnPairingsController::class, 'show'])->whereNumber('pairing')->name('conn:pairing:show');
        Route::post('environments/{environment}/pairings', [ConnPairingsController::class, 'store'])->name('conn:pairing:create');
        Route::post('environments/{environment}/pairings/revoke', [ConnPairingsController::class, 'revoke'])->name('conn:pairing:revoke');
    });
});
