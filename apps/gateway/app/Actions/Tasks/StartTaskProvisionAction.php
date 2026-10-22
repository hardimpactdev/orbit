<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskProvisionAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksProvision, [], TaskProvisionOperation::requireTodo(...), $triggeredBy, $requestId);
    }
}
