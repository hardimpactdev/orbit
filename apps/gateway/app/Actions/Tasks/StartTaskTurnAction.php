<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskTurn;
use App\Domain\Tasks\TaskVmRemoved;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskTurnAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskTurn $turn) {}

    public function execute(Task $group, TaskTurnData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        // A group that owns a task copy turns in it (docs/cli/task/copy-turn.mmd). A nested-fleet group without a copy refuses on the copy path.
        // Any other group would turn on a task VM, which is removed.
        if (TaskInstanceWorkspace::forGroup($group) !== null || $group->project()->firstOrFail()->nested_fleet) {
            return $this->turn->start($group, $input, $triggeredBy, $requestId);
        }

        throw TaskVmRemoved::refusal();
    }
}
