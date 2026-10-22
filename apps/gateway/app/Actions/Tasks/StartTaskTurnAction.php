<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskTurnOperation;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskTurnAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskTurnOperation $turn) {}

    public function execute(Task $group, TaskTurnData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksTurn, $input->toArray(),
            fn (Task $fresh) => $this->turn->validate($fresh, $input), $triggeredBy, $requestId);
    }
}
