<?php

declare(strict_types=1);

use App\Http\Controllers\Api\IntegrationIntentController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])
    ->post('operation-executor/{operation}/integrations/intent', [IntegrationIntentController::class, 'show'])
    ->whereNumber('operation')->name('operation:executor:integration-intent');
