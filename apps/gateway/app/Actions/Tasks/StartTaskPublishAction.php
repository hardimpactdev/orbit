<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskPublishOperation;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskPublishAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskPublishOperation $publish) {}

    public function execute(Task $group, StartTaskPublishData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksPublish, $input->toArray(),
            function (Task $current) use ($input): void {
                $this->publish->requireCheck($current, $input->check);
            }, $triggeredBy, $requestId);
    }
}
