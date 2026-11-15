<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskProvisionIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskProvisionAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskProvisionIntent $intent) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->intent->start($group, $triggeredBy, $requestId);
    }
}
