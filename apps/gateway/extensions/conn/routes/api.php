<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;
use Orbit\Extensions\Conn\Http\Controllers\ConnEnvironmentsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnNodesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnOperationsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnPairingsController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProfilesController;
use Orbit\Extensions\Conn\Http\Controllers\ConnProjectsController;

// Conn reuses node access (/reference/conn#access): a server is reached through its registering Node.
// Registration needs none: it ties the server to the calling Node.
Route::middleware([
    'api',
    RequireActiveWireGuardPeer::class,
    RequireEnabledExtension::class.':conn',
])->prefix('api/v1/conn')->group(function (): void {
    Route::post('environments', [ConnEnvironmentsController::class, 'store'])->name('conn:environment:register');

    Route::middleware(RequireNodeAccess::class)->group(function (): void {
        Route::get('me', [ConnNodesController::class, 'show'])->name('conn:me');
        Route::get('operations/{operation}', [ConnOperationsController::class, 'show'])->whereNumber('operation')->name('conn:operation:show');
        Route::post('operations/{operation}/cancel', [ConnOperationsController::class, 'cancel'])->whereNumber('operation')->name('conn:operation:cancel');
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
        Route::get('projects', [ConnProjectsController::class, 'index'])->name('conn:project:list');
        Route::post('projects/{project}/instances', [ConnProjectsController::class, 'createInstance'])->whereNumber('project')->name('conn:instance:create');
        Route::get('environments', [ConnEnvironmentsController::class, 'index'])->name('conn:environment:list');
        Route::post('environments/{environment}/sessions/cleanup', [ConnEnvironmentsController::class, 'cleanup'])->name('conn:environment:cleanup');
        Route::get('environments/{environment}/pairings', [ConnPairingsController::class, 'index'])->name('conn:pairing:list');
        Route::get('environments/{environment}/pairings/{pairing}', [ConnPairingsController::class, 'show'])->whereNumber('pairing')->name('conn:pairing:show');
        Route::post('environments/{environment}/pairings', [ConnPairingsController::class, 'store'])->name('conn:pairing:create');
        Route::post('environments/{environment}/pairings/revoke', [ConnPairingsController::class, 'revoke'])->name('conn:pairing:revoke');
    });
});
