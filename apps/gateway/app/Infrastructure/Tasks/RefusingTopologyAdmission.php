<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * No lane prepares declared workload nodes yet, so a subtask that declares them waits, and its group gets no large task
 * VM. A subtask whose group's task VM was sized without a topology names that cause instead: Orbit never resizes a
 * running VM or moves the group by itself.
 */
final readonly class RefusingTopologyAdmission implements TaskTopologyAdmission
{
    public const string Unavailable = 'Declared topology is not available.';

    public const string TooSmall = 'Task VM: this subtask declares a topology, but the group\'s task VM was sized without one, and Orbit does not resize it.';

    public function available(): bool
    {
        return false;
    }

    public function prepare(Task $group, Task $task): void
    {
        if (TaskTopology::from($task->topology ?? []) === []) {
            return;
        }
        $vm = $group->task_compute === TaskCompute::Vm ? TaskVmPlacement::forGroup($group) : null;

        throw new TaskCapacityException(false, $vm instanceof TaskVm && ! $vm->size->holds($task) ? self::TooSmall : self::Unavailable);
    }
}
