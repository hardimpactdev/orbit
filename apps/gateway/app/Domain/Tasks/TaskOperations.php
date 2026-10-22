<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use Closure;

/** Admission and cancellation shared by every operation whose target is a task group. */
final readonly class TaskOperations
{
    public function __construct(
        private StartOperationAction $start,
        private CancelOperationAction $cancel,
        private OperationIdempotency $idempotency,
        private TaskExecutionLock $execution,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(Task): void  $validate
     */
    public function start(Task $group, string $kind, array $input, Closure $validate, string $triggeredBy, ?string $requestId): Operation
    {
        if (($replay = $this->admitTarget($group, $kind)) !== null) {
            return $replay;
        }

        return $this->execution->synchronized($group->id, function () use ($group, $kind, $input, $validate, $triggeredBy, $requestId): Operation {
            if (($replay = $this->admitTarget($group, $kind)) !== null) {
                return $replay;
            }
            $group->refresh();
            $this->requireOpenExternal($group);
            $validate($group);

            return $this->start->execute($kind, OperationKinds::TaskGroupTarget, $group->id, null, $input, $triggeredBy, $requestId);
        });
    }

    /** Returns a keyed replay, refuses a busy target, or admits a new start. */
    private function admitTarget(Task $group, string $kind): ?Operation
    {
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        $holder = Operation::query()->where('lock_key', OperationStore::lockKey(OperationKinds::TaskGroupTarget, $group->id))->first();
        if ($holder instanceof Operation) {
            // The holder may have appeared after the replay read.
            return $this->idempotency->replay($kind)
                ?? throw new ResourceOperationException('operation.target_busy', 'Another operation on this task group has not ended.', 409, details: ['operation_id' => $holder->id]);
        }

        return null;
    }

    public function requireOpenExternal(Task $group): void
    {
        if ($group->groupOrchestration() !== TaskOrchestration::External) {
            throw new ResourceOperationException('tasks.not_orchestrated', 'This task group uses tick orchestration.', 409);
        }
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
    }

    public function cancelOpen(Task $group): void
    {
        $operation = Operation::query()->where('lock_key', OperationStore::lockKey(OperationKinds::TaskGroupTarget, $group->id))->first();
        if ($operation instanceof Operation) {
            $this->cancel->execute($operation);
        }
    }
}
