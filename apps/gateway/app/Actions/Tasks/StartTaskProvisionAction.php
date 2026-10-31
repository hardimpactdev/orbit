<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskWorkerData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Models\Operation;
use App\Models\Task;
use SensitiveParameter;

final readonly class StartTaskProvisionAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskProvisionIntent $intent) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId, #[SensitiveParameter] ?TaskWorkerData $worker = null): Operation
    {
        $this->extension->execute();

        if ($worker !== null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Native provisioning issues its own Node worker credentials.', 503);
        }

        return $this->intent->start($group, $triggeredBy, $requestId);
    }
}
