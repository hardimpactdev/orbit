<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskCheckOperation;
use App\Domain\Tasks\TaskOperations;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskCheckAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskCheckOperation $checks) {}

    public function execute(Task $group, StartTaskCheckData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksCheck, ['subtask' => $input->subtask, 'phase' => $input->phase->value],
            function (Task $current) use ($input): void {
                $this->checks->requireCheck($current, $input);
            }, $triggeredBy, $requestId);
    }
}
