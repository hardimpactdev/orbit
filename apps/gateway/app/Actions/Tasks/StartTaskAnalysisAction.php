<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskAnalysisData;
use App\Domain\Tasks\TaskAnalysisIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskAnalysisAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskAnalysisIntent $analysis) {}

    public function execute(Task $group, StartTaskAnalysisData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->analysis->start($group, $input, $triggeredBy, $requestId);
    }
}
