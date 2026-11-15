<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

/**
 * The one refusal of the removed task VM runtime (docs/reference/tasks.md#task-runtime). A task runs only in a copy of its Project's
 * default container Instance. Every entry that would admit a task VM answers this error before it reads or writes anything else.
 */
final class TaskVmRemoved
{
    public const string Code = 'tasks.vm_runtime_removed';

    public static function refusal(): ResourceOperationException
    {
        return new ResourceOperationException(self::Code,
            'Task VMs are removed. A task runs only in a copy of its Project\'s default Instance, and that default must be a container. Move a checkout default into a container with instance:migrate, or create a container default with instance:create; then start the task and work in its copy with task:turn, task:check and task:publish.', 422);
    }
}
