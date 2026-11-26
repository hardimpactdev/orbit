<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskSyncIntent;
use App\Domain\Tasks\TaskTurn;
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
        // A copy turn that may still run holds the copy (docs/reference/tasks.md#bring-main-into-a-copy). A group without a copy gets
        // `tasks.no_workspace` from the capture.
        $copy = TaskInstanceWorkspace::forGroup($group) !== null;
        $validate = $copy ? static function (Task $group): void {
            TaskTurn::refuseUnended($group);
        } : static function (): void {};

        return $this->operations->start($group, OperationKinds::TasksSync, [], $validate, $triggeredBy, $requestId, $this->intent->capture(...));
    }
}
