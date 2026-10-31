<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Tasks\TaskPublishIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskPublishAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskPublishIntent $publish) {}

    public function execute(Task $group, StartTaskPublishData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->publish->start($group, $input, $triggeredBy, $requestId);
    }
}
