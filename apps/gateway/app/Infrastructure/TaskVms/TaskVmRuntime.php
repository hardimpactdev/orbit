<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

/** Runtime admission only. The native parent owns installation and readiness. */
final readonly class TaskVmRuntime
{
    public const string PiProvider = 'orbit-task-vm';
}
