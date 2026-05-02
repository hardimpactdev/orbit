<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CaRootController;
use App\Http\Controllers\Api\MeController;
use App\Http\Middleware\CorrelationHeader;
use App\Http\Middleware\WireGuardIdentity;
use Illuminate\Support\Facades\Route;

Route::middleware(CorrelationHeader::class)->group(function (): void {
    Route::get('/ca/root', CaRootController::class);

    Route::middleware(WireGuardIdentity::class)->group(function (): void {
        Route::get('/me', MeController::class);
    });
});
