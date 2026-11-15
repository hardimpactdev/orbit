<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskVmRemoved;
use App\Models\Operation;
use App\Models\Task;

/** The build of an approved spec ran only on a task VM. Task VMs are removed, so it answers their refusal (docs/reference/tasks.md#task-runtime). */
final readonly class StartTaskBuildAction
{
    public function __construct(private RequireTasksExtensionAction $extension) {}

    public function execute(Task $group, string $head, string $caller, ?string $requestId): Operation
    {
        $this->extension->execute();

        throw TaskVmRemoved::refusal();
    }
}
