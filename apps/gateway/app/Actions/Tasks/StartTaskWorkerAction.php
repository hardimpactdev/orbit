<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskWorkerData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskWorkerOperation;
use App\Models\Operation;
use App\Models\Task;
use SensitiveParameter;

final readonly class StartTaskWorkerAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, #[SensitiveParameter] TaskWorkerData $worker, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->startWithWorker($group, OperationKinds::TasksWorker, $worker,
            static function (Task $current): void {
                TaskWorkerOperation::requireWorkspace($current);
            }, $triggeredBy, $requestId);
    }
}
