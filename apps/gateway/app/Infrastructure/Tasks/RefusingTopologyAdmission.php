<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Models\Task;

/** No lane prepares declared workload nodes, so a subtask that declares them waits for an operator. */
final readonly class RefusingTopologyAdmission implements TaskTopologyAdmission
{
    public function prepare(Task $group, Task $task): void
    {
        if (TaskTopology::from($task->topology ?? []) !== []) {
            throw new TaskCapacityException(false, 'Declared topology is not available.');
        }
    }
}
