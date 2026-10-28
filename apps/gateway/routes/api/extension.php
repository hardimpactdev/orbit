<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExtensionsController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::get('extensions', [ExtensionsController::class, 'index'])->name('extension:list');
    Route::post('extensions/{extension}/enable', [ExtensionsController::class, 'enable'])->name('extension:enable');
    Route::post('extensions/{extension}/disable', [ExtensionsController::class, 'disable'])->name('extension:disable');
});
