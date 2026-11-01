<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Models\Operation;
use App\Models\TaskVm;

/** Runtime admission only. The native parent owns installation and readiness. */
final readonly class TaskVmRuntime
{
    public const string PiProvider = 'orbit-task-vm';

}
