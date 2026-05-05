<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ActivityListController;
use App\Http\Controllers\Api\ActivityShowController;
use App\Http\Controllers\Api\AppAgentIdeController;
use App\Http\Controllers\Api\AppListController;
use App\Http\Controllers\Api\AppRegisterController;
use App\Http\Controllers\Api\AppRemoveController;
use App\Http\Controllers\Api\AppRootController;
use App\Http\Controllers\Api\AppShowController;
use App\Http\Controllers\Api\AppStoreController;
use App\Http\Controllers\Api\CaRootController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NodeAgentIdeController;
use App\Http\Controllers\Api\NodeDefaultController;
use App\Http\Controllers\Api\NodeGrantController;
use App\Http\Controllers\Api\NodeListController;
use App\Http\Controllers\Api\NodeRemoveController;
use App\Http\Controllers\Api\NodeRevokeController;
use App\Http\Controllers\Api\NodeShowController;
use App\Http\Controllers\Api\NodeStoreController;
use App\Http\Controllers\Api\NodeUpdateController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WorkspaceHistoryController;
use App\Http\Controllers\Api\WorkspaceListController;
use App\Http\Controllers\Api\WorkspaceShowController;
use App\Http\Middleware\CorrelationHeader;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\WireGuardIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware(CorrelationHeader::class)->group(function (): void {
    Route::get('/ca/root', CaRootController::class);

    Route::middleware([WireGuardIdentity::class, LogActivity::class])->group(function (): void {
        Route::get('/activity', ActivityListController::class);
        Route::get('/activity/{id}', ActivityShowController::class);
        Route::get('/me', MeController::class);
        Route::get('/profile', ProfileController::class);
        Route::get('/workspaces', WorkspaceListController::class);
        Route::get('/workspaces/history/resolve-by-path', [WorkspaceHistoryController::class, 'fromPath']);
        Route::get('/workspaces/resolve-by-path', [WorkspaceShowController::class, 'fromPath']);
        Route::get('/workspaces/{name}/history', WorkspaceHistoryController::class);
        Route::get('/workspaces/{name}', WorkspaceShowController::class);
        Route::get('/apps', AppListController::class);
        Route::post('/apps/register', AppRegisterController::class);
        Route::post('/apps', AppStoreController::class);
        Route::post('/apps/{app}/agent-ide', AppAgentIdeController::class);
        Route::post('/apps/{app}/root', AppRootController::class);
        Route::delete('/apps/{app}', AppRemoveController::class);
        Route::get('/apps/{app}', AppShowController::class);
        Route::get('/nodes', NodeListController::class);
        Route::post('/nodes', NodeStoreController::class);
        Route::get('/nodes/default', [NodeDefaultController::class, 'show']);
        Route::put('/nodes/default', [NodeDefaultController::class, 'set']);
        Route::delete('/nodes/default', [NodeDefaultController::class, 'clear']);
        Route::post('/nodes/grant', NodeGrantController::class);
        Route::post('/nodes/revoke', NodeRevokeController::class);
        Route::post('/nodes/{name}/agent-ide', NodeAgentIdeController::class);
        Route::delete('/nodes/{name}', NodeRemoveController::class);
        Route::put('/nodes/{name}', NodeUpdateController::class);
        Route::get('/nodes/{name}', NodeShowController::class);
    });
});
