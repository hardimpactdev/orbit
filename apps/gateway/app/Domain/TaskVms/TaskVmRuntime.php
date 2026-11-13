<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * What runs a task group on its host (docs/reference/old-paths.md#off-in-the-live-fleet): a task VM, or an unprivileged task container
 * on the host's `containers` side. A `task_vms` row and a base build row keep the runtime they started with.
 */
enum TaskVmRuntime: string
{
    case Vm = 'vm';
    case Container = 'container';
}
