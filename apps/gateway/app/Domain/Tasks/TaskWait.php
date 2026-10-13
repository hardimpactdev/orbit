<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Why a claim or a turn waits. A wait retries on the next tick and never asks for assistance. */
enum TaskWait: string
{
    /** No Node or host has room for the group, or another placement holds the lock. */
    case Capacity = 'capacity';

    /** The group's task VM is not ready yet: it is provisioning, resuming, or destroying, or cannot be placed yet. */
    case TaskVm = 'task_vm';

    /** The subtask's declared topology is being prepared, or its group cannot run it. */
    case Topology = 'topology';
}
