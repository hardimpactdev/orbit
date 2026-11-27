<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The cancel of a task group that owns a copy (docs/cli/task/cancel.mmd). It reads what the copy holds that origin does not, records
 * the cancel with that read and admits the normal `instance.destroy` of the copy with force. The cancel runs until the destroy ends
 * and takes its outcome (destroyEnded): the group is cancelled when the destroy succeeds (TaskLifecycle::destroyed), and a failed
 * destroy fails the cancel with its code and leaves the group running and bound, so the next `task:cancel` admits a new destroy.
 */
final readonly class TaskEnd
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private ContainerWorkspaceRead $read) {}

    /**
     * What the copy's workspace holds beyond the seed: commits that origin lacks, uncommitted and untracked files. A copy never fetches,
     * so the commits that the group's publishes pushed are named to the read: they and their history are on origin. The checked-out
     * branch's own commits are published too when the copy's latest content read found their content on the default branch
     * (TaskWork); a commit only another branch holds still counts. `commits` keeps what the read counted and `work` the verdict,
     * null when the branch holds none of them. A stopped or unreachable copy reads `unknown` with its reason. One bounded SSH read; call it
     * outside any transaction.
     *
     * @return array<string, mixed>
     */
    public function workspace(Task $group, Instance $copy): array
    {
        $read = $this->read->read($copy, self::published($group));
        $count = static fn (mixed $class): ?int => is_array($class) && is_int($class['count'] ?? null) ? $class['count'] : null;
        // Committed work counts by content, not by commit id: when the copy's content read of this head finds every committed path on
        // the default branch, the checked-out branch's own commits are published, as after a squash merge or a hand-carry. The content
        // read is of that branch alone, so a commit that only another branch holds always counts.
        $own = $read['commits']['head'] ?? 0;
        $work = $own > 0 ? TaskWork::committed($group, $read['head_commit']) : null;
        $unpushed = $read['unpushed'] === null ? null : $read['unpushed'] - ($own > 0 && $work === null ? $own : 0);
        $state = $read['state'] === 'dirty' && $unpushed + $read['uncommitted'] + ($count($read['stashes']) ?? 0) === 0 ? 'clean' : $read['state'];

        return ['state' => $state, 'reason' => $read['reason'], 'read_at' => $read['read_at'], 'head' => $read['head'],
            'commits' => $read['unpushed'], 'unpushed' => $unpushed, 'work' => $own > 0 ? ($work ?? 'on_default') : null,
            'uncommitted' => $read['uncommitted'], 'modified' => $count($read['modified']),
            'untracked' => $count($read['untracked']), 'stashes' => $count($read['stashes'])];
    }

    /**
     * The commits that the group's succeeded publishes pushed: they and their history are on origin. The copy's reads name them, and
     * the content read of its base counts what they hold as published (docs/reference/tasks.md#published-work-of-a-copy).
     *
     * @return list<string>
     */
    public static function published(Task $group): array
    {
        return array_values(Operation::query()->where('kind', 'tasks.publish')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('status', OperationStatus::Succeeded)->pluck('result')
            ->map(static fn (mixed $result): mixed => is_array($result) ? ($result['commit'] ?? null) : null)->filter(static fn (mixed $commit): bool => is_string($commit))->unique()->all());
    }

    /** Refuses while an Operation holds the copy: its create still runs, or an earlier destroy has not ended. Call before any change. */
    public static function assertFree(Instance $copy): void
    {
        $held = Operation::query()->exceptFleetWait()->where('target_type', 'instance')->where('target_id', $copy->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->value('id');
        if ($held !== null) {
            throw new ResourceOperationException('operation.target_busy', 'An Operation still holds the task copy; cancel again when it has ended.', 409,
                details: ['operation_id' => $held]);
        }
    }

    /**
     * Refuses a copy that holds work origin lacks, or whose workspace could not be read, unless the caller discards it. Before
     * `task:publish` the task branch exists only in the copy. Call before any change.
     *
     * @param  array<string, mixed>  $workspace
     */
    public static function assertDiscardable(array $workspace, bool $discard): void
    {
        if ($discard || $workspace['state'] === 'clean') {
            return;
        }
        if ($workspace['state'] === 'dirty') {
            throw new ResourceOperationException('tasks.copy_unpublished_work', 'The task copy holds work that is not on the default branch or origin; publish it, run task:sync to read it again, or cancel with discard to destroy it.', 409,
                details: array_intersect_key($workspace, array_flip(['head', 'commits', 'unpushed', 'work', 'uncommitted', 'modified', 'untracked', 'stashes'])));
        }

        throw new ResourceOperationException('tasks.copy_workspace_unknown', 'The task copy\'s workspace cannot be read; cancel with discard to destroy it unread.', 409,
            details: ['reason' => $workspace['reason']]);
    }

    /**
     * Records the cancel and admits the destroy of the copy, in the destroy's one transaction after every read of its admission. The
     * record comes first and takes the group's lock key, so an Operation that holds or takes the group lock refuses the record and the
     * transaction admits no destroy. The cancel then runs without the lock until the destroy ends. Refused while an Operation holds
     * the copy: its create still runs, or an earlier destroy has not ended.
     *
     * @param  array<string, mixed>  $workspace
     */
    public function cancel(Task $group, Instance $copy, array $workspace, string $caller, ?string $request): Operation
    {
        self::assertFree($copy);
        // The destroy is admitted with the cancel and takes no request key of its own: the cancel's key replays both.
        $record = fn (Closure $destroy): Operation => $this->start->record('tasks.cancel', 'task_group', $group->id, $copy->node_id,
            ['group_id' => $group->id, 'instance_id' => $copy->id, 'task_vm_id' => null],
            ['group_id' => $group->id, 'instance_id' => $copy->id, 'workspace' => $workspace], $caller, $request,
            OperationStore::lockKey('task_group', $group->id), fn (): array => ['destroy_operation_id' => $this->idempotency->suspended(static fn (): Operation => $destroy())->id], open: true);

        return app(StartInstanceDestroyAction::class)->execute($copy->refresh(), true, $caller, $request, within: $record);
    }

    /**
     * Every end of an `instance.destroy` reaches here (OperationStore::change): a run's report, a refused start, an expired budget, and
     * a later receipt that corrects that end. The cancel that admitted it takes the destroy's outcome: succeeded, or failed with the
     * destroy's error code. The destroy's budget bounds the cancel.
     */
    public function destroyEnded(Operation $destroy): void
    {
        $cancel = Operation::query()->where('kind', 'tasks.cancel')->where('target_type', 'task_group')->where('result->destroy_operation_id', $destroy->id)
            ->lockForUpdate()->first();
        if ($cancel !== null) {
            $this->follow($cancel, $destroy);
        }
    }

    /**
     * Ends each waiting cancel whose destroy a worker without destroyEnded recorded, as during a release's worker handover
     * (ReconcileSwampOperations). A succeeded destroy ends the group as TaskLifecycle::destroyed does for a cancel's copy.
     */
    public function sweep(): void
    {
        Operation::query()->where('kind', 'tasks.cancel')->where('target_type', 'task_group')->whereNotIn('status', OperationStatus::terminalValues())
            ->whereNotNull('result->destroy_operation_id')->lazyById()->each(function (Operation $waiting): void {
                DB::transaction(function () use ($waiting): void {
                    $cancel = Operation::query()->lockForUpdate()->findOrFail($waiting->id);
                    $id = $cancel->result['destroy_operation_id'] ?? null;
                    $destroy = is_int($id) ? Operation::query()->find($id) : null;
                    if ($cancel->status->terminal() || $destroy === null || ! $destroy->status->terminal()) {
                        return;
                    }
                    $group = Task::topLevel()->lockForUpdate()->find($cancel->target_id);
                    if ($destroy->status === OperationStatus::Succeeded && $group !== null
                        && ! in_array($group->status, [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed], true)
                        && in_array($group->taskable_id, [null, $cancel->input['instance_id'] ?? null], true)) {
                        app(TaskGroupEnding::class)->settle($group, 'tasks.cancel');
                    }
                    $this->follow($cancel, $destroy);
                });
            });
    }

    private function follow(Operation $cancel, Operation $destroy): void
    {
        $status = $destroy->status === OperationStatus::Succeeded ? OperationStatus::Succeeded : OperationStatus::Failed;
        $code = $status === OperationStatus::Succeeded ? null : ($destroy->error_code ?? 'operation.'.$destroy->status->value);
        if ($cancel->status === $status && $cancel->error_code === $code) {
            return;
        }
        app(OperationStore::class)->change($cancel, ['status' => $status, 'finished_at' => now(), 'error_code' => $code]);
    }

    /** A cancel that waits for its copy's destroy has no deadline of its own: the destroy's budget ends both (OperationRunBudgets). */
    public static function waitsForDestroy(Operation $operation): bool
    {
        return $operation->kind === 'tasks.cancel' && ! $operation->status->terminal() && is_int($operation->result['destroy_operation_id'] ?? null);
    }
}
