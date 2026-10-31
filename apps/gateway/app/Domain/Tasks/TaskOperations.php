<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Data\Tasks\TaskWorkerData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\TaskWorkerEnrollment;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/** Admission and cancellation shared by every operation whose target is a task group. */
final readonly class TaskOperations
{
    public function __construct(
        private StartOperationAction $start,
        private CancelOperationAction $cancel,
        private OperationIdempotency $idempotency,
        private TaskExecutionLock $execution,
        private RequireTasksExtensionAction $extension,
        private TaskWorkerEnrollment $enrollment,
    ) {}

    /** @param Closure(Task): void $validate */
    public function startWithWorker(Task $group, string $kind, #[SensitiveParameter] ?TaskWorkerData $worker, Closure $validate, string $triggeredBy, ?string $requestId): Operation
    {
        $reference = $worker === null ? null : $this->enrollment->stage($worker->enrollment_token, $worker->server_token);
        $input = $worker === null ? [] : ['worker' => [
            'orchestrator_url' => $worker->orchestrator_url, 'labels' => $worker->labels, 'enrollment_reference' => $reference, 'server_authenticated' => $worker->server_token !== null,
        ]];
        try {
            $operation = $this->start($group, $kind, $input, $validate, $triggeredBy, $requestId);
        } catch (Throwable $exception) {
            if ($reference !== null) {
                $this->enrollment->forget($reference);
            }
            throw $exception;
        }
        $recorded = $operation->input['worker'] ?? null;
        if ($reference !== null && (! is_array($recorded) || ($recorded['enrollment_reference'] ?? null) !== $reference)) {
            $this->enrollment->forget($reference);
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(Task): void  $validate
     * @param  null|Closure(Task): array<string,mixed>  $intent
     */
    public function start(Task $group, string $kind, array $input, Closure $validate, string $triggeredBy, ?string $requestId, ?Closure $intent = null): Operation
    {
        if (($replay = $this->admitTarget($group, $kind)) !== null) {
            return $replay;
        }

        $admit = function () use ($group, $kind, $input, $validate, $triggeredBy, $requestId, $intent): Operation {
            if (($replay = $this->admitTarget($group, $kind)) !== null) {
                return $replay;
            }
            $group->refresh();
            $this->requireOpenExternal($group);
            $validate($group);

            $admitted = $intent === null ? $input : $intent($group);

            return $this->start->execute($kind, OperationKinds::TaskGroupTarget, $group->id, $intent === null ? null : ValidatedData::integer($admitted['node_id']), $admitted, $triggeredBy, $requestId);
        };

        return $this->execution->synchronized($group->id, fn (): Operation => $intent === null ? $admit() : DB::transaction($admit));
    }

    /** Returns a keyed replay, refuses a busy target, or admits a new start. */
    private function admitTarget(Task $group, string $kind): ?Operation
    {
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        $holder = $this->holders($group)->first();
        if ($holder instanceof Operation) {
            // The holder may have appeared after the replay read.
            return $this->idempotency->replay($kind)
                ?? throw new ResourceOperationException('operation.target_busy', 'Another operation on this task group has not ended.', 409, details: ['operation_id' => $holder->id]);
        }

        return null;
    }

    public function requireOpenExternal(Task $group): void
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
    }

    public function requireCurrentSubtask(Task $group, int $subtask): Task
    {
        if (! $group->taskable instanceof Instance) {
            throw new ResourceOperationException('tasks.no_workspace', 'This task group has no workspace.', 409);
        }
        $task = Task::query()->where('parent_id', $group->id)->findOrFail($subtask);
        $current = Task::query()->where('parent_id', $group->id)
            ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])
            ->orderBy('position')->orderBy('id')->value('id');
        if ($current !== $task->id) {
            throw new ResourceOperationException('tasks.subtask_not_current', 'Only the current open subtask can run.', 409);
        }

        return $task;
    }

    public function cancelOpen(Task $group, ?int $subtask = null): void
    {
        $operation = $this->holders($group)
            ->when($subtask !== null, fn ($query) => $query->where('input->subtask', $subtask))->first();
        if ($operation instanceof Operation) {
            $this->extension->execute();
            $this->cancel->execute($operation, targetEnding: $subtask === null);
            if (! $operation->refresh()->status->terminal()) {
                throw new ResourceOperationException('operation.target_busy', 'The task publish job must finish before this group can end.', 409, details: ['operation_id' => $operation->id]);
            }
        }
    }

    /** @return Builder<Operation> */
    private function holders(Task $group): Builder
    {
        return Operation::query()->where(fn (Builder $q) => $q->where('lock_key', OperationStore::lockKey(OperationKinds::TaskGroupTarget, $group->id))
            ->orWhere(fn (Builder $q) => $q->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
                ->where('state->task_park_writer', 'direct')->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])));
    }
}
