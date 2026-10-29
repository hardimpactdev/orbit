<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\TaskVmGuestCommand;
use App\Infrastructure\TaskVms\TaskVmImageHostCommand;
use App\Infrastructure\TaskVms\TaskVmImagePhaseCommand;
use App\Infrastructure\TaskVms\TaskVmLifecycleCommand;
use App\Infrastructure\TaskVms\TaskVmPackageCacheCommand;
use App\Infrastructure\TaskVms\TaskVmRuntimeCommand;
use App\Infrastructure\TaskVms\TaskVmSweepCommand;
use App\Infrastructure\TaskVms\TaskVmUpCloudCommand;
use App\Infrastructure\TaskVms\TaskVmUpCloudImageCommand;

return [
    'task_vm_sweep' => TaskVmSweepCommand::class,
    'task_vm_upcloud-image' => TaskVmUpCloudImageCommand::class,
    'task_vm_guest' => TaskVmGuestCommand::class,
    'task_vm_runtime' => TaskVmRuntimeCommand::class,
    'task_vm_lifecycle' => TaskVmLifecycleCommand::class,
    'task_vm_upcloud' => TaskVmUpCloudCommand::class,
    'task_vm_package-cache' => TaskVmPackageCacheCommand::class,
    'task_vm_image-host' => TaskVmImageHostCommand::class,
    'task_vm_image-phase' => TaskVmImagePhaseCommand::class,
];
