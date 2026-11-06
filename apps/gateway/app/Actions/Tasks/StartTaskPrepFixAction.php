<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\Tasks\TaskPrepFixIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskPrepFixAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskPrepFixIntent $intent) {}

    public function execute(Task $group, StartTaskPrepFixData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->intent->start($group, $input, $triggeredBy, $requestId);
    }
}
