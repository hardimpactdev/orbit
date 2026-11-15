<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\Tasks\TaskVmRemoved;
use App\Models\Operation;
use App\Models\Task;

/** `task:prep-fix` ran only on a task VM. Task VMs are removed, so it answers their refusal (docs/reference/tasks.md#task-runtime). */
final readonly class StartTaskPrepFixAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(Task $group, StartTaskPrepFixData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        throw TaskVmRemoved::refusal();
    }
}
