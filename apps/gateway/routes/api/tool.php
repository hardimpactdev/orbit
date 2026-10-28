<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ToolInventoryController;
use App\Http\Controllers\Api\ToolManagersController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('tool-inventory', [ToolInventoryController::class, 'scan'])
        ->name('tool:scan');
    Route::get('tool-managers', [ToolManagersController::class, 'index'])
        ->name('tool:manager:list');
    Route::get('tools', [ToolsController::class, 'index'])->name('tool:list');
    Route::get('tools/{tool}', [ToolsController::class, 'show'])
        ->whereNumber('tool')
        ->name('tool:show');
    Route::post('tools', [ToolsController::class, 'store'])->name('tool:install');
    Route::post('tools/adopt', [ToolsController::class, 'adopt'])->name('tool:adopt');
    Route::post('tools/{tool}/update', [ToolsController::class, 'update'])
        ->whereNumber('tool')
        ->name('tool:update');
    Route::delete('tools/{tool}', [ToolsController::class, 'destroy'])
        ->whereNumber('tool')
        ->name('tool:remove');
});
