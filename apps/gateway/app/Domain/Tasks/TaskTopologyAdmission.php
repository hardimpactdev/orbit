<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\Bind;

/** Prepare a subtask's declared topology before each turn that Orbit prepares for it, and read its evidence. */
#[Bind(TaskVmTopologyAdmission::class)]
interface TaskTopologyAdmission
{
    /**
     * Returns when the subtask declares no topology or its group's topology holds it.
     *
     * @throws TaskCapacityException while the topology is being prepared or cannot be prepared yet
     * @throws TaskTopologyFailedException when preparing it failed
     */
    public function prepare(Task $group, Task $task): void;

    /**
     * The records of the group's evidence log that count since `$since` ({@see TaskTopologyEvidence}).
     *
     * @throws TaskCheckException when the evidence log cannot be read
     */
    public function evidence(Task $group, CarbonInterface $since): TaskTopologyEvidence;
}
