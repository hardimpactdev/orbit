<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Carbon\CarbonInterface;

/** Prepare a subtask's declared topology before its baseline check or any of its turns, and read its evidence. */
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
