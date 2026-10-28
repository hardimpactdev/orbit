<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RealtimeAuthController;
use App\Http\Controllers\Api\RealtimeConfigController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->match(['get', 'post'], 'broadcasting/auth', [RealtimeAuthController::class, 'authenticate'])
    ->name('realtime:auth');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('realtime', [RealtimeConfigController::class, 'show'])
    ->name('realtime:show');
