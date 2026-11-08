<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentThreadsController;
use App\Http\Controllers\Api\DependencyBatchMergeController;
use App\Http\Controllers\Api\ProofRunsController;
use App\Http\Controllers\Api\TaskEgressController;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TaskInsightsController;
use App\Http\Controllers\Api\TaskPullRequestController;
use App\Http\Controllers\Api\TaskQuestionsController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\TaskSwampRunsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\RequireTaskLevel;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('tasks/status', [TasksController::class, 'status'])->name('task:status');
    Route::middleware(RequireEnabledExtension::class.':tasks')->group(function (): void {
        Route::get('tasks/{task}/swamp-runs', [TaskSwampRunsController::class, 'show'])
            ->whereNumber('task')->withoutMiddleware(RecordCommandActivity::class)->middleware(RequireTaskLevel::class.':parent')->name('task:swamp-runs');
        Route::get('tasks/{task}/egress', [TaskEgressController::class, 'show'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:egress');
        Route::get('tasks/{task}/agents', [AgentThreadsController::class, 'index'])
            ->whereNumber('task')->withoutMiddleware(RecordCommandActivity::class)->middleware(RequireTaskLevel::class.':parent')->name('task:agent:list');
        Route::get('tasks/{task}/agents/{session}', [AgentThreadsController::class, 'show'])
            ->whereNumber('task')->whereNumber('session')->middleware(RequireTaskLevel::class.':parent')->name('task:agent:show');
        Route::get('tasks/{task}/agents/{session}/stream', [AgentThreadsController::class, 'stream'])
            ->whereNumber('task')->whereNumber('session')->withoutMiddleware(RecordCommandActivity::class)->middleware(RequireTaskLevel::class.':parent')->name('task:agent:stream');
        Route::get('tasks', [TaskGroupsController::class, 'index'])->name('task:list');
        Route::get('task-insights', [TaskInsightsController::class, 'show'])->name('task:insights');
        Route::get('task-questions', [TaskQuestionsController::class, 'index'])->name('task:question:list');
        Route::post('task-questions/{question}/close', [TaskQuestionsController::class, 'close'])
            ->whereNumber('question')
            ->name('task:question:close');
        Route::post('tasks', [TaskGroupsController::class, 'store'])->name('task:create');
        Route::get('tasks/{task}', [TaskGroupsController::class, 'show'])
            ->whereNumber('task')
            ->name('task:show');
        Route::patch('tasks/{task}', [TaskGroupsController::class, 'update'])
            ->whereNumber('task')
            ->name('task:update');
        Route::delete('tasks/{task}', [TaskGroupsController::class, 'destroyTask'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':child')->name('task:destroy');
        Route::post('tasks/{task}/check/cancel', [TaskGroupsController::class, 'cancelCheck'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':child')->name('task:check:cancel');
        Route::post('tasks/{task}/comments', [TaskGroupsController::class, 'storeComment'])
            ->whereNumber('task')->name('task:comment:create');
        Route::get('tasks/{task}/comments', [TaskGroupsController::class, 'comments'])
            ->whereNumber('task')->name('task:comment:list');
        Route::post('proof/run', [ProofRunsController::class, 'store'])->name('proof:run');
        Route::post('proof/verify', [ProofRunsController::class, 'verify'])->name('proof');

        Route::post('tasks/{task}/sync', [TaskGroupsController::class, 'sync'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:sync');
        Route::post('tasks/{task}/prep', [TaskGroupsController::class, 'prep'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:prep');
        Route::post('tasks/{task}/prep-fix', [TaskGroupsController::class, 'prepFix'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:prep-fix');
        Route::post('tasks/{task}/dependency-merge', [DependencyBatchMergeController::class, 'store'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:dependency-merge');
        Route::post('tasks/{task}/pr-ready', [TaskPullRequestController::class, 'ready'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:pr-ready');
        Route::post('tasks/{task}/pr-merge', [TaskPullRequestController::class, 'merge'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:pr-merge');
        Route::post('tasks/{task}/publish', [TaskGroupsController::class, 'publish'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:publish');
        Route::post('tasks/{task}/package', [TaskGroupsController::class, 'package'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:package');
        Route::post('tasks/{task}/script', [TaskGroupsController::class, 'script'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:script');
        Route::post('tasks/{task}/check', [TaskGroupsController::class, 'check'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':child')->name('task:check');
        Route::post('tasks/{task}/turn', [TaskGroupsController::class, 'turn'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':child')->name('task:turn');
        Route::post('tasks/{task}/start', [TaskGroupsController::class, 'provision'])
            ->whereNumber('task')->middleware(RequireTaskLevel::class.':parent')->name('task:start');
        Route::post('tasks/{task}/cancel', [TaskGroupsController::class, 'cancel'])
            ->whereNumber('task')
            ->name('task:cancel');
        Route::post('tasks/{task}/complete', [TaskGroupsController::class, 'complete'])
            ->whereNumber('task')
            ->middleware(RequireTaskLevel::class.':parent')->name('task:complete');
    });
});
