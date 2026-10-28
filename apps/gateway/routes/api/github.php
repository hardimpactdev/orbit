<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GitHubAppController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('github/app/register', [GitHubAppController::class, 'register'])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->name('github:app:register');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('github/app/callback', [GitHubAppController::class, 'callback'])
    ->withoutMiddleware(RecordCommandActivity::class)
    ->name('github:app:callback');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::post('github/app/install', [GitHubAppController::class, 'install'])->name('github:app:install');
    Route::get('github/app', [GitHubAppController::class, 'show'])->name('github:app:show');
    Route::delete('github/app', [GitHubAppController::class, 'destroy'])->name('github:app:destroy');
});
