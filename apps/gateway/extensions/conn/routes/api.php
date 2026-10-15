<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Conn\Http\Controllers\ConnEnvironmentsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnNodesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnPairingsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProfilesController;

// Every active Node, phones and operator machines included, may use every endpoint; there are no grants yet.
Route::middleware([
    'api',
    RequireActiveWireGuardPeer::class,
    RequireEnabledExtension::class.':conn',
])->prefix('api/v1/conn')->group(function (): void {
    Route::get('me', [ConnNodesController::class, 'show'])->name('conn:me');
    Route::put('me/profile', [ConnNodesController::class, 'bindProfile'])->name('conn:me:profile');
    Route::get('profiles', [ConnProfilesController::class, 'index'])->name('conn:profile:list');
    Route::post('profiles', [ConnProfilesController::class, 'store'])->name('conn:profile:create');
    Route::get('profiles/{profile}/settings', [ConnProfilesController::class, 'settings'])
        ->whereNumber('profile')
        ->name('conn:profile:settings:show');
    Route::put('profiles/{profile}/settings', [ConnProfilesController::class, 'replaceSettings'])
        ->whereNumber('profile')
        ->name('conn:profile:settings:update');
    Route::patch('profiles/{profile}/settings', [ConnProfilesController::class, 'patchSettings'])
        ->whereNumber('profile')
        ->name('conn:profile:settings:patch');
    Route::get('environments', [ConnEnvironmentsController::class, 'index'])->name('conn:environment:list');
    Route::post('environments', [ConnEnvironmentsController::class, 'store'])->name('conn:environment:register');
    Route::get('environments/{environment}/pairings', [ConnPairingsController::class, 'index'])->name('conn:pairing:list');
    Route::post('environments/{environment}/pairings', [ConnPairingsController::class, 'store'])->name('conn:pairing:create');
    Route::post('environments/{environment}/pairings/revoke', [ConnPairingsController::class, 'revoke'])->name('conn:pairing:revoke');
});
