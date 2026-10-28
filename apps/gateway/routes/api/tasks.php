<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentThreadsController;
use App\Http\Controllers\Api\TaskGroupsController;
use App\Http\Controllers\Api\TaskInsightsController;
use App\Http\Controllers\Api\TaskQuestionsController;
use App\Http\Controllers\Api\TasksController;
use App\Http\Controllers\Api\TaskSwampRunsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('tasks/status', [TasksController::class, 'status'])->name('tasks:status');
    Route::middleware(RequireEnabledExtension::class.':tasks')->group(function (): void {
        Route::get('task-groups/{group}/swamp-runs', [TaskSwampRunsController::class, 'show'])
            ->whereNumber('group')->withoutMiddleware(RecordCommandActivity::class)->name('tasks:swamp-runs');
        Route::get('task-groups/{group}/agents', [AgentThreadsController::class, 'index'])
            ->whereNumber('group')->withoutMiddleware(RecordCommandActivity::class)->name('tasks:agents');
        Route::get('task-groups/{group}/agents/{session}', [AgentThreadsController::class, 'show'])
            ->whereNumber('group')->whereNumber('session')->name('tasks:agent:show');
        Route::get('task-groups/{group}/agents/{session}/stream', [AgentThreadsController::class, 'stream'])
            ->whereNumber('group')->whereNumber('session')->withoutMiddleware(RecordCommandActivity::class)->name('tasks:agent-stream');
        Route::get('task-groups', [TaskGroupsController::class, 'index'])->name('tasks:list');
        Route::get('task-insights', [TaskInsightsController::class, 'show'])->name('tasks:insights');
        Route::get('task-questions', [TaskQuestionsController::class, 'index'])->name('tasks:question:list');
        Route::post('task-questions/{question}/close', [TaskQuestionsController::class, 'close'])
            ->whereNumber('question')
            ->name('tasks:question:close');
        Route::post('task-groups', [TaskGroupsController::class, 'store'])->name('tasks:create');
        Route::get('task-groups/{group}', [TaskGroupsController::class, 'show'])
            ->whereNumber('group')
            ->name('tasks:show');
        Route::patch('task-groups/{group}', [TaskGroupsController::class, 'update'])
            ->whereNumber('group')
            ->name('tasks:update');
        Route::post('task-groups/{group}/tasks', [TaskGroupsController::class, 'createTask'])
            ->whereNumber('group')
            ->name('tasks:subtask:create');
        Route::patch('task-groups/{group}/tasks/{task}', [TaskGroupsController::class, 'updateTask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:update');
        Route::delete('task-groups/{group}/tasks/{task}', [TaskGroupsController::class, 'destroyTask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:destroy');
        Route::post('task-groups/{group}/tasks/{task}/cancel', [TaskGroupsController::class, 'cancelSubtask'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:subtask:cancel');
        Route::post('task-groups/{group}/tasks/{task}/check/cancel', [TaskGroupsController::class, 'cancelCheck'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:check:cancel');
        Route::post('task-groups/{group}/comments', [TaskGroupsController::class, 'storeGroupComment'])
            ->whereNumber('group')->name('tasks:group:comment:create');
        Route::get('task-groups/{group}/comments', [TaskGroupsController::class, 'groupComments'])
            ->whereNumber('group')->name('tasks:group:comment:list');
        Route::post('task-groups/{group}/tasks/{task}/comments', [TaskGroupsController::class, 'storeComment'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:comment:create');
        Route::get('task-groups/{group}/tasks/{task}/comments', [TaskGroupsController::class, 'comments'])
            ->whereNumber('group')->whereNumber('task')->name('tasks:comment:list');
        Route::post('task-groups/{group}/park', [TaskGroupsController::class, 'park'])
            ->whereNumber('group')->name('tasks:park');
        Route::post('task-groups/{group}/publish', [TaskGroupsController::class, 'publish'])
            ->whereNumber('group')->name('tasks:publish');
        Route::post('task-groups/{group}/analyze', [TaskGroupsController::class, 'analyze'])
            ->whereNumber('group')->name('tasks:analyze');
        Route::post('task-groups/{group}/script', [TaskGroupsController::class, 'script'])
            ->whereNumber('group')->name('tasks:script');
        Route::post('task-groups/{group}/check', [TaskGroupsController::class, 'check'])
            ->whereNumber('group')->name('tasks:check');
        Route::post('task-groups/{group}/turn', [TaskGroupsController::class, 'turn'])
            ->whereNumber('group')->name('tasks:turn');
        Route::post('task-groups/{group}/provision', [TaskGroupsController::class, 'provision'])
            ->whereNumber('group')->name('tasks:provision');
        Route::post('task-groups/{group}/worker', [TaskGroupsController::class, 'worker'])
            ->whereNumber('group')->name('tasks:worker');
        Route::post('task-groups/{group}/cancel', [TaskGroupsController::class, 'cancel'])
            ->whereNumber('group')
            ->name('tasks:cancel');
        Route::post('task-groups/{group}/complete', [TaskGroupsController::class, 'complete'])
            ->whereNumber('group')
            ->name('tasks:complete');
    });
});
