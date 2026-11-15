<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPrepData;
use App\Domain\Tasks\TaskVmRemoved;
use App\Models\Operation;
use App\Models\Task;

/** `task:prep` ran only on a task VM. Task VMs are removed, so it answers their refusal (docs/reference/tasks.md#task-runtime). */
final readonly class StartTaskPrepAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(Task $group, StartTaskPrepData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        throw TaskVmRemoved::refusal();
    }
}
