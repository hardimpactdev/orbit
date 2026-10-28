<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskParkOperation;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskParkAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksPark, [], static function (Task $group): void {
            TaskParkOperation::requireEligible($group);
        }, $triggeredBy, $requestId);
    }
}
