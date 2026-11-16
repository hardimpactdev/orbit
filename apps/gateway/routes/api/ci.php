<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CiRunnersController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->get('ci/runners', [CiRunnersController::class, 'show'])
    ->name('ci:runners');
