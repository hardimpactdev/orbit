<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskScriptData;
use App\Domain\Tasks\TaskScriptIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskScriptAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskScriptIntent $operations) {}

    public function execute(Task $group, StartTaskScriptData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, $input, $triggeredBy, $requestId);
    }
}
