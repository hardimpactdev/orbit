<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AptRepositoriesController;
use App\Http\Controllers\Api\ToolInventoryController;
use App\Http\Controllers\Api\ToolManagersController;
use App\Http\Controllers\Api\ToolPolicyController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('tool-policy', [ToolPolicyController::class, 'show'])->name('tool:mode');
    Route::put('tool-policy', [ToolPolicyController::class, 'update'])->name('tool:mode:set');
    Route::post('nodes/{node}/tools/converge', [ToolsController::class, 'converge'])->whereNumber('node')->name('tool:converge');
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
    Route::get('apt-repositories', [AptRepositoriesController::class, 'index'])->name('tool:apt-repository-list');
    Route::get('apt-repositories/{repository}', [AptRepositoriesController::class, 'show'])->whereNumber('repository')->name('tool:apt-repository-show');
    Route::post('apt-repositories', [AptRepositoriesController::class, 'store'])->name('tool:apt-repository-add');
    Route::delete('apt-repositories/{repository}', [AptRepositoriesController::class, 'destroy'])->whereNumber('repository')->name('tool:apt-repository-remove');
});
