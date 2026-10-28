<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RuntimeActivationsController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('runtime-activations/app-instance/{instance}', [RuntimeActivationsController::class, 'show'])
    ->whereNumber('instance')
    ->withoutMiddleware(RecordCommandActivity::class)
    ->name('runtime-activation:app-instance');
