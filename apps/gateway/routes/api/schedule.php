<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ScheduleCompletionsController;
use App\Http\Controllers\Api\SchedulesController;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->post('schedules/{schedule}/complete', [ScheduleCompletionsController::class, 'store'])
    ->whereUuid('schedule')
    ->withoutMiddleware(RecordCommandActivity::class)
    ->name('schedule:complete');

Route::middleware([
    RequireActiveWireGuardPeer::class,
    RequireNodeAccess::class,
])->group(function (): void {
    Route::get('schedules', [SchedulesController::class, 'index'])
        ->name('schedule:list');
    Route::post('schedules', [SchedulesController::class, 'store'])
        ->name('schedule:create');
    Route::get('schedules/{schedule}/logs', [SchedulesController::class, 'logs'])
        ->whereUuid('schedule')
        ->name('schedule:logs');
    Route::post('schedules/{schedule}/run', [SchedulesController::class, 'run'])
        ->whereUuid('schedule')
        ->name('schedule:run');
    Route::post('schedules/{schedule}/activate', [SchedulesController::class, 'activate'])
        ->whereUuid('schedule')
        ->name('schedule:enable');
    Route::get('schedules/{schedule}', [SchedulesController::class, 'show'])
        ->whereUuid('schedule')
        ->name('schedule:show');
    Route::delete('schedules/{schedule}', [SchedulesController::class, 'destroy'])
        ->whereUuid('schedule')
        ->name('schedule:destroy');
});
