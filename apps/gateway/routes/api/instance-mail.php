<?php

declare(strict_types=1);

use App\Http\Controllers\Api\InstanceMailController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::post('instances/{instance}/mail', [InstanceMailController::class, 'store'])
        ->whereNumber('instance')->name('instance:mail:update');
});
