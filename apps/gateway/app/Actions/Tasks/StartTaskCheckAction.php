<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Tasks\TaskCheckIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskCheckAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskCheckIntent $operations) {}

    public function execute(Task $group, StartTaskCheckData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, $input, $triggeredBy, $requestId);
    }
}
