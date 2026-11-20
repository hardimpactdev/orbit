<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TaskController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireTaskKey;
use Illuminate\Support\Facades\Route;

// A task copy's calls through its Node's forwarder, which sends only these paths here (docs/reference/ai-router.md#orbit-routes-for-task-copies).
// They accept the copy's own key from its own Node, never a Node alone: RequireActiveWireGuardPeer and RequireNodeAccess do not apply.
Route::middleware(RequireTaskKey::class)->withoutMiddleware(RecordCommandActivity::class)->prefix('copy')->group(function (): void {
    Route::get('whoami', [TaskController::class, 'whoami'])->name('copy:whoami');
    Route::post('pull-request', [TaskController::class, 'pullRequest'])->name('copy:pull-request');
    Route::post('handoff', [TaskController::class, 'handoff'])->name('copy:handoff');
});
