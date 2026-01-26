<?php

use App\Http\Controllers\CliController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Desktop-specific Web Routes
|--------------------------------------------------------------------------
|
| These routes are specific to the desktop app and are loaded after
| the orbit-app routes.
|
*/

Route::prefix('cli')->group(function () {
    Route::get('/status', [CliController::class, 'status']);
    Route::post('/install', [CliController::class, 'install']);
});
