<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DatabaseConnectionsController;
use App\Http\Controllers\Api\DatabaseServersController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('database-connections', [DatabaseConnectionsController::class, 'index'])
        ->name('database:list');
    Route::post('database-connections', [DatabaseConnectionsController::class, 'store'])
        ->name('database:create');
    Route::get('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'show'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:show');
    Route::get('database-connections/{database_connection}/users', [DatabaseConnectionsController::class, 'users'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:user:list');
    Route::post('database-connections/{database_connection}/users', [DatabaseConnectionsController::class, 'storeUser'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:user:create');
    Route::patch('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'update'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:update');
    Route::delete('database-connections/{database_connection}', [DatabaseConnectionsController::class, 'destroy'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:destroy');
    Route::post('database-connections/{database_connection}/query', [DatabaseConnectionsController::class, 'query'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:query');
    Route::get('database-connections/{database_connection}/tables', [DatabaseConnectionsController::class, 'tables'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:tables');
    Route::get('database-connections/{database_connection}/schema', [DatabaseConnectionsController::class, 'schema'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:schema');
    Route::get('database-connections/{database_connection}/describe/{table}', [DatabaseConnectionsController::class, 'describe'])
        ->where('database_connection', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->where('table', '[A-Za-z_][A-Za-z0-9_]*')
        ->name('database:describe');
    Route::get('database-servers', [DatabaseServersController::class, 'index'])
        ->name('database:server:list');
    Route::post('database-servers', [DatabaseServersController::class, 'store'])
        ->name('database:server:create');
    Route::get('database-servers/{database_server}', [DatabaseServersController::class, 'show'])
        ->where('database_server', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:server:show');
    Route::post('database-servers/{database_server}/close', [DatabaseServersController::class, 'close'])
        ->where('database_server', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:server:close');
    Route::delete('database-servers/{database_server}', [DatabaseServersController::class, 'destroy'])
        ->where('database_server', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('database:server:destroy');
});
