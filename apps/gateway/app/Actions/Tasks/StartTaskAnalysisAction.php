<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskAnalysisData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskAnalysisOperation;
use App\Domain\Tasks\TaskOperations;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskAnalysisAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskAnalysisOperation $analysis) {}

    public function execute(Task $group, StartTaskAnalysisData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksAnalyze, $input->toArray(), function (Task $current) use ($input): void {
            $this->analysis->validate($current, $input->subtask);
        }, $triggeredBy, $requestId);
    }
}
