<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Where a task VM's nested topology is. A VM without one, or whose preparation failed, has no state. */
enum TaskVmTopologyState: string
{
    /** The preparation unit runs in the VM. */
    case Preparing = 'preparing';

    /** The topology in `topology` runs in the VM. */
    case Ready = 'ready';
}
