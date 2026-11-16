<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TaskCopyController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireTaskCopyKey;
use Illuminate\Support\Facades\Route;

// A task copy's calls through its Node's forwarder, which sends only these two paths here (docs/reference/ai-router.md#orbit-routes-for-task-copies).
// They accept the copy's own key from its own Node, never a Node alone: RequireActiveWireGuardPeer and RequireNodeAccess do not apply.
Route::middleware(RequireTaskCopyKey::class)->withoutMiddleware(RecordCommandActivity::class)->prefix('copy')->group(function (): void {
    Route::get('whoami', [TaskCopyController::class, 'whoami'])->name('copy:whoami');
    Route::post('pull-request', [TaskCopyController::class, 'pullRequest'])->name('copy:pull-request');
});
