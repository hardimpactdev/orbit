<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Where a new task group starts, as InstancePlacement::taskRuntime() decides from its Project. */
enum TaskRuntime: string
{
    /** A copy of the Project's default Instance container, named task-<id>, that the group owns. */
    case Copy = 'copy';

    /** A task VM of the host pool. */
    case Vm = 'vm';
}
