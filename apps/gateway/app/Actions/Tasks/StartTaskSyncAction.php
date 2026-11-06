<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskSyncIntent;
use App\Domain\Tasks\TaskVmAwake;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskSyncAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskSyncIntent $intent, private OperationIdempotency $idempotency) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay(OperationKinds::TasksSync)) !== null) {
            return $existing;
        }
        TaskVmAwake::require($group);

        return $this->operations->start($group, OperationKinds::TasksSync, [], static function (): void {}, $triggeredBy, $requestId, $this->intent->capture(...));
    }
}
