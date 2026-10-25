<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskScriptData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskScriptAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations) {}

    public function execute(Task $group, StartTaskScriptData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksScript, $input->toArray(),
            function (Task $current) use ($input): void {
                $this->operations->requireCurrentSubtask($current, $input->subtask);
            }, $triggeredBy, $requestId);
    }
}
