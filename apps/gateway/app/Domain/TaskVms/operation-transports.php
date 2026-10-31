<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\TaskVmLifecycleCommand;
use App\Infrastructure\TaskVms\TaskVmRuntimeCommand;
use App\Infrastructure\TaskVms\TaskVmUpCloudCommand;

return [
    'task_vm_runtime' => TaskVmRuntimeCommand::class,
    'task_vm_lifecycle' => TaskVmLifecycleCommand::class,
    'task_vm_upcloud' => TaskVmUpCloudCommand::class,
];
