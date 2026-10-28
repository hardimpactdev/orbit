<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentRealtimeController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAgentSecret;
use Illuminate\Support\Facades\Route;

// Every agent endpoint requires the agent's secret as well as the Node's address (ADR 0155).
Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAgentSecret::class])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->prefix('agent')->group(function (): void {
        Route::get('realtime', [AgentRealtimeController::class, 'show'])->name('agent:realtime');
        Route::post('broadcasting/auth', [AgentRealtimeController::class, 'authenticate'])->name('agent:realtime:auth');
        Route::get('workspaces', [AgentRealtimeController::class, 'workspaces'])->name('agent:workspaces');
        Route::get('log-streams', [AgentRealtimeController::class, 'logStreams'])->name('agent:log-streams');
    });
