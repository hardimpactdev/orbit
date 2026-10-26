<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskWorkerData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Models\Operation;
use App\Models\Task;
use SensitiveParameter;

final readonly class StartTaskProvisionAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId, #[SensitiveParameter] ?TaskWorkerData $worker = null): Operation
    {
        $this->extension->execute();

        return $this->operations->startWithWorker($group, OperationKinds::TasksProvision, $worker, TaskProvisionOperation::requireTodo(...), $triggeredBy, $requestId);
    }
}
