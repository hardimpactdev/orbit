<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\AiRouter\Http\Controllers\AiRouterController;

Route::middleware(['api', RequireActiveWireGuardPeer::class, RequireNodeAccess::class, RequireEnabledExtension::class.':ai-router'])->prefix('api/v1')->group(function (): void {
    Route::post('ai-router', [AiRouterController::class, 'store'])->name('ai-router:setup');
    Route::delete('ai-router', [AiRouterController::class, 'destroy'])->name('ai-router:teardown');
    Route::get('ai-router', [AiRouterController::class, 'status'])->name('ai-router:status');
    Route::get('ai-router/providers', [AiRouterController::class, 'index'])->name('ai-router:provider:list');
    Route::get('ai-router/providers/{provider}', [AiRouterController::class, 'show'])
        ->where('provider', '[a-z][a-z0-9-]*')
        ->name('ai-router:provider:show');
    Route::get('ai-router/models', [AiRouterController::class, 'models'])->name('ai-router:model:list');
    Route::post('ai-router/accounts/{account}/enable', [AiRouterController::class, 'enableAccount'])
        ->where('account', '[A-Za-z0-9._-]+')->name('ai-router:account:enable');
    Route::post('ai-router/accounts/{account}/disable', [AiRouterController::class, 'disableAccount'])
        ->where('account', '[A-Za-z0-9._-]+')->name('ai-router:account:disable');
});
