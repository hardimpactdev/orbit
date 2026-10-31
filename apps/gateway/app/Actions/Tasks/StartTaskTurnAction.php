<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskTurnData;
use App\Domain\Tasks\TaskTurnIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskTurnAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskTurnIntent $intent) {}

    public function execute(Task $group, TaskTurnData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->intent->start($group, $input, $triggeredBy, $requestId);
    }
}
