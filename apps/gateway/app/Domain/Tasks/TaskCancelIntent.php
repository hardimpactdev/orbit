<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** Admission of the owning native ending DAG. Records are retained until complete cleanup. */
final readonly class TaskCancelIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $keys,
        private TaskExecutionLock $execution, private TaskOperations $operations, private TaskFinalReview $review) {}

    public function finishMaintenance(Task $group): Operation
    {
        return $this->admit($group, 'project-maintenance', null, 'tasks.maintenance_finish');
    }

    /** `$discard`: the caller lets the destroy of a task copy take work that origin lacks, or a copy that cannot be read. */
    public function start(Task $group, string $caller, ?string $request, bool $discard = false): Operation
    {
        return $this->admit($group, $caller, $request, 'tasks.cancel', $discard);
    }

    private function admit(Task $group, string $caller, ?string $request, string $kind, bool $discard = false): Operation
    {
        app(RequireTasksExtensionAction::class)->execute();
        if (($replay = $this->keys->replay($kind)) !== null) {
            return $replay;
        }
        // A group that owns a task copy ends through the copy's destroy (TaskEnd); the read of its workspace runs before any lock.
        // The copy and its read travel as one pair, so no check on the read can be skipped while the destroy still runs.
        $workspace = $kind === 'tasks.cancel' ? TaskInstanceWorkspace::forGroup($group) : null;
        $copy = $workspace === null ? null : ['instance' => $workspace->instance, 'read' => app(TaskEnd::class)->workspace($group, $workspace->instance)];

        return $this->execution->synchronized($group->id, function () use ($group, $caller, $request, $kind, $copy, $discard): Operation {
            if (($replay = $this->keys->replay($kind)) !== null) {
                return $replay;
            }
            $held = Operation::query()->where('lock_key', OperationStore::lockKey('task_group', $group->id))->value('id');
            if ($held !== null && $kind !== 'tasks.cancel') {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409, details: ['operation_id' => $held]);
            }
            // A cancel that waits for its copy's destroy answers a new one with the destroy that still holds the copy.
            if ($copy !== null) {
                TaskEnd::assertFree($copy['instance']);
            }
            if (Operation::query()->whereIn('kind', ['tasks.cancel', 'tasks.maintenance_finish'])->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Task cancellation already owns this group.', 409);
            }
            // Every refusal comes before the cancel changes anything. Approved work that origin does not have refuses it: task:publish
            // comes first.
            if ($kind === 'tasks.cancel') {
                self::eligible($group->refresh(), $kind);
                $this->review->assertOnOrigin($group);
                if ($copy !== null) {
                    TaskEnd::assertFree($copy['instance']);
                    TaskEnd::assertDiscardable($copy['read'], $discard);
                } else {
                    self::refuseWorkspace($group);
                }
                $this->operations->cancelOpen($group);
                // An ended Operation can still hold the group: a timed-out one whose run nothing has proven ended. The cancel refuses
                // before it admits the copy's destroy; one that takes the lock later refuses the record, in the destroy's transaction.
                $held = Operation::query()->where('lock_key', OperationStore::lockKey('task_group', $group->id))->value('id');
                if ($held !== null) {
                    throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409, details: ['operation_id' => $held]);
                }
                if ($copy !== null) {
                    return app(TaskEnd::class)->cancel($group, $copy['instance'], $copy['read'], $caller, $request);
                }
            } elseif (Operation::query()->exceptCancelWait()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'The current Task root must settle before maintenance cleanup.', 409);
            }

            // A group without a task copy holds no workspace: nothing is left to release, so the group ends here and the Operation
            // succeeds with no run (R1). The task VMs and their release are removed (docs/reference/old-paths.md).
            return DB::transaction(function () use ($group, $caller, $request, $kind): Operation {
                $input = $this->capture($group, $kind);
                $operation = $this->start->record($kind, 'task_group', $group->id, SwampNode::id(), $input,
                    ['group_id' => $group->id, 'instance_id' => null, 'released' => true], $caller, $request, OperationStore::lockKey('task_group', $group->id));
                if ($operation->wasRecentlyCreated) {
                    app(TaskGroupEnding::class)->settle(Task::topLevel()->lockForUpdate()->findOrFail($group->id), $kind);
                }

                return $operation;
            });
        }, wait: false);
    }

    /**
     * The input of a cancel of a group without a task copy. Such a group must hold no workspace: a task copy ends through its destroy,
     * and a task VM workspace has no release any more.
     *
     * @return array{group_id: int, project_id: int, instance_id: null}
     */
    private function capture(Task $group, string $kind): array
    {
        $group = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
        self::eligible($group, $kind);
        self::refuseWorkspace($group);

        return ['group_id' => $group->id, 'project_id' => $group->project_id, 'instance_id' => null];
    }

    /** A group without a task copy must hold no workspace: nothing releases a task VM workspace any more. */
    private static function refuseWorkspace(Task $group): void
    {
        if ($group->taskable_id !== null) {
            throw new ResourceOperationException('tasks.workspace_ownership_refused',
                'This cancel cannot end the group\'s workspace: a task copy ends with task:cancel, and task VM workspaces are removed.', 409);
        }
    }

    public static function eligible(Task $group, string $kind = 'tasks.cancel'): void
    {
        if ($kind === 'tasks.maintenance_finish') {
            $state = $group->maintenance ?? [];
            $noChange = ($state['changed'] ?? true) === false && ! $group->pr_url;
            $published = isset($state['publish_operation_id']) && Operation::query()->whereKey($state['publish_operation_id'])
                ->where('kind', 'tasks.publish')->where('target_type', 'task_group')->where('target_id', $group->id)
                ->where('status', 'succeeded')->where('result->pull_request_url', $group->pr_url)->exists();
            $endedPr = $published && $group->pr_url === $group->watched_pr_url && in_array($group->watched_pr_state, ['merged', 'closed'], true);
            if ($group->maintenance === null || $group->status !== TaskGroupStatus::Settling || (! $noChange && ! $endedPr)
                || $group->tasks()->where('status', '!=', TaskStatus::Completed)->exists()
                || ! Operation::query()->whereKey($state['check_operation_id'] ?? 0)->where('target_type', 'task_group')->where('target_id', $group->id)->where('kind', 'tasks.check')
                    ->where('status', 'succeeded')->where('result->outcome', 'passed')->exists()) {
                throw new ResourceOperationException('tasks.not_settling', 'Native maintenance cleanup requires its passed no-change work or ended published PR.', 409);
            }
        } elseif (($group->status === TaskGroupStatus::Completed && $group->taskable_id === null)
            || (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && $group->pr_url)) {
            throw new ResourceOperationException('tasks.not_cancellable', 'A completed group or a settling group with a PR cannot be cancelled.', 409);
        }
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $group = Task::topLevel()->findOrFail($operation->target_id);
        self::eligible($group, $operation->kind);
        // A release that ended after the admission may have deleted the workspace and unbound the group; then nothing is left to own.
        if (($operation->input['group_id'] ?? null) !== $group->id || ($operation->input['project_id'] ?? null) !== $group->project_id
            || (($operation->input['instance_id'] ?? null) !== $group->taskable_id && $group->taskable_id !== null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cancellation no longer owns its admitted group workspace.', 422);
        }

        return ['intent' => ['group_id' => $group->id, 'release' => $operation->input['release'] ?? null],
            'execution_secrets' => []];
    }
}
