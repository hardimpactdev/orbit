<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskSyncIntent;
use App\Domain\Tasks\TaskTurn;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;

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
            // A removal of the copy that was admitted first holds it; this check and the Instance lock below run in one transaction.
            if (TaskInstanceWorkspace::forGroup($group)?->instance->refresh()->status === InstanceState::Removing) {
                throw new ResourceOperationException('operation.target_busy', 'The task copy is being removed.', 409);
            }
        } : static function (): void {};

        // A copy's sync holds the copy's Instance from its admission, so no removal or other Instance writer is admitted beside it,
        // whichever engine runs it (docs/reference/instance-removal.md).
        return $this->operations->start($group, OperationKinds::TasksSync, [], $validate, $triggeredBy, $requestId, $this->intent->capture(...),
            static fn (array $intent): ?string => isset($intent['copy']) ? OperationStore::lockKey('instance', ValidatedData::integer($intent['instance_id'])) : null);
    }
}
