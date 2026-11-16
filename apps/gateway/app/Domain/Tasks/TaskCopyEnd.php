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

/**
 * The cancel of a task group that owns a copy (docs/cli/task/cancel.mmd). It reads what the copy holds that origin does not, records
 * the cancel with that read and admits the normal `instance.destroy` of the copy with force. It does not wait for
 * the work: the group is cancelled when the destroy succeeds (TaskCopyLifecycle::destroyed), and a failed destroy leaves the group
 * running and bound, so the next `task:cancel` admits a new destroy.
 */
final readonly class TaskCopyEnd
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private ContainerWorkspaceRead $read) {}

    /**
     * What the copy's workspace holds beyond the seed: commits that origin lacks, uncommitted and untracked files. A copy never fetches,
     * so the commits that the group's publishes pushed are named to the read: they and their history are on origin. The checked-out
     * branch's own commits are published too when the copy's latest content read found their content on the default branch
     * (TaskCopyWork); a commit only another branch holds still counts. `commits` keeps what the read counted and `work` the verdict,
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
        $work = $own > 0 ? TaskCopyWork::committed($group, $read['head_commit']) : null;
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
        $held = Operation::query()->where('target_type', 'instance')->where('target_id', $copy->id)
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
     * transaction admits no destroy. Refused while an Operation holds the copy: its create still runs, or an earlier destroy has not ended.
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
            OperationStore::lockKey('task_group', $group->id), fn (): array => ['destroy_operation_id' => $this->idempotency->suspended(static fn (): Operation => $destroy())->id]);

        return app(StartInstanceDestroyAction::class)->execute($copy->refresh(), true, $caller, $request, within: $record);
    }
}
