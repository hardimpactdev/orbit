<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Admission and cancellation shared by every operation whose target is a task group. */
final readonly class TaskOperations
{
    public function __construct(
        private StartOperationAction $start,
        private CancelOperationAction $cancel,
        private OperationIdempotency $idempotency,
        private TaskExecutionLock $execution,
        private RequireTasksExtensionAction $extension,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(Task): void  $validate
     * @param  null|Closure(Task): array<string,mixed>  $intent
     * @param  list<string>  $except  kinds that do not hold the group for this admission, such as the turn of the caller
     */
    public function start(Task $group, string $kind, array $input, Closure $validate, string $triggeredBy, ?string $requestId, ?Closure $intent = null, array $except = []): Operation
    {
        if (($replay = $this->admitTarget($group, $kind, $except)) !== null) {
            return $replay;
        }

        $admit = function () use ($group, $kind, $input, $validate, $triggeredBy, $requestId, $intent, $except): Operation {
            if (($replay = $this->admitTarget($group, $kind, $except)) !== null) {
                return $replay;
            }
            $group->refresh();
            $this->requireOpenExternal($group);
            $validate($group);

            if (in_array($kind, ['tasks.build', 'tasks.prep_fix', 'tasks.review_round', 'tasks.turn'], true) && $intent !== null) {
                return app(TaskVmAdmission::class)->start($group, $kind, $input, fn (): array => $intent($group), $triggeredBy, $requestId);
            }

            $admitted = $intent === null ? $input : $intent($group);

            return $this->start->execute($kind, OperationKinds::TaskGroupTarget, $group->id, $intent === null ? null : ValidatedData::integer($admitted['node_id']), $admitted, $triggeredBy, $requestId);
        };

        return $this->execution->synchronized($group->id, fn (): Operation => $intent === null ? $admit() : DB::transaction($admit));
    }

    /**
     * Returns a keyed replay, refuses a busy target, or admits a new start.
     *
     * @param  list<string>  $except
     */
    private function admitTarget(Task $group, string $kind, array $except = []): ?Operation
    {
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        $holder = $this->holders($group, $except)->first();
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
        if ($subtask === null) {
            $this->cancelTimedOutRuns($group);
        }
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

    /**
     * An Operation the Gateway timed out is terminal, so it holds no group, yet its native run may still be going and holds
     * the SSH session of a VM that this ending is about to destroy. Ask Swamp to stop it; Swamp ends the run.
     */
    private function cancelTimedOutRuns(Task $group): void
    {
        $cancellation = app(NativeRunCancellation::class);
        Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('status', OperationStatus::Failed)->where('error_code', 'operation.timed_out')
            ->whereIn('kind', array_keys(app(DirectOperation::class)->definitions()))
            ->each(function (Operation $operation) use ($cancellation): void {
                if ($cancellation->outlivesTimeout($operation)) {
                    $cancellation->request($operation);
                }
            });
    }

    /**
     * @param  list<string>  $except
     * @return Builder<Operation>
     */
    private function holders(Task $group, array $except = []): Builder
    {
        return Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereIn('kind', array_values(array_diff(array_keys(app(DirectOperation::class)->definitions()), $except)))
            ->whereNotIn('status', OperationStatus::terminalValues());
    }
}
