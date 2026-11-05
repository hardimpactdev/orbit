<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPrepData;
use App\Domain\Tasks\TaskPrepIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskPrepAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskPrepIntent $intent) {}

    public function execute(Task $group, StartTaskPrepData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->intent->start($group, $input, $triggeredBy, $requestId);
    }
}
