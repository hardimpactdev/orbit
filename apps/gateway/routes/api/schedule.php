<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ScheduleCompletionsController;
use App\Http\Controllers\Api\ScheduleLogStreamsController;
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
    Route::post('schedules/{schedule}/log-streams', [ScheduleLogStreamsController::class, 'store'])
        ->whereUuid('schedule')->name('schedule:log-stream:create');
    Route::put('schedules/{schedule}/log-streams/{stream}', [ScheduleLogStreamsController::class, 'update'])
        ->whereUuid('schedule')->withoutMiddleware(RecordCommandActivity::class)->name('schedule:log-stream:renew');
    Route::delete('schedules/{schedule}/log-streams/{stream}', [ScheduleLogStreamsController::class, 'destroy'])
        ->whereUuid('schedule')->name('schedule:log-stream:destroy');
    Route::get('schedules/{schedule}/logs', [SchedulesController::class, 'logs'])
        ->whereUuid('schedule')
        ->name('schedule:logs');
    Route::post('schedules/{schedule}/run', [SchedulesController::class, 'run'])
        ->whereUuid('schedule')
        ->name('schedule:run');
    Route::post('schedules/{schedule}/activate', [SchedulesController::class, 'activate'])
        ->whereUuid('schedule')
        ->name('schedule:enable');
    Route::post('schedules/{schedule}/disable', [SchedulesController::class, 'disable'])
        ->whereUuid('schedule')
        ->name('schedule:disable');
    Route::get('schedules/{schedule}', [SchedulesController::class, 'show'])
        ->whereUuid('schedule')
        ->name('schedule:show');
    Route::delete('schedules/{schedule}', [SchedulesController::class, 'destroy'])
        ->whereUuid('schedule')
        ->name('schedule:destroy');
});
