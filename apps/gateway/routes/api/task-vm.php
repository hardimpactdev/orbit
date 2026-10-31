<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TaskVmExecutorReadController;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class])->group(function (): void {
    Route::get('task-vm-executor/{operation}/sweep', [TaskVmExecutorReadController::class, 'sweep'])->name('task-vm-executor:sweep');
    Route::get('task-vm-executor/{operation}/upcloud-image/{view?}', [TaskVmExecutorReadController::class, 'upcloudImage'])->name('task-vm-executor:upcloud-image');
    Route::get('task-vm-executor/{operation}/lifecycle', [TaskVmExecutorReadController::class, 'lifecycle'])->whereNumber('operation')->name('task-vm-executor:lifecycle');
    Route::get('task-vm-executor/{operation}/runtime', [TaskVmExecutorReadController::class, 'runtime'])->whereNumber('operation')->name('task-vm-executor:runtime');
});
