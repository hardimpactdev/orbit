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
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admission of the owning native ending DAG. Records are retained until complete cleanup. */
final readonly class TaskCancelIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $keys,
        private TaskExecutionLock $execution, private TaskOperations $operations, private TaskVmSlotRuns $slots, private TaskFinalReview $review) {}

    public function finishMaintenance(Task $group): Operation
    {
        return $this->admit($group, 'project-maintenance', null, 'tasks.maintenance_finish');
    }

    public function start(Task $group, string $caller, ?string $request): Operation
    {
        return $this->admit($group, $caller, $request, 'tasks.cancel');
    }

    private function admit(Task $group, string $caller, ?string $request, string $kind): Operation
    {
        app(RequireTasksExtensionAction::class)->execute();
        if (($replay = $this->keys->replay($kind)) !== null) {
            return $replay;
        }

        return $this->execution->synchronized($group->id, function () use ($group, $caller, $request, $kind): Operation {
            if (($replay = $this->keys->replay($kind)) !== null) {
                return $replay;
            }
            $held = Operation::query()->where('lock_key', OperationStore::lockKey('task_group', $group->id))->value('id');
            if ($held !== null) {
                throw new ResourceOperationException('operation.target_busy', 'Another Operation owns this target.', 409, details: ['operation_id' => $held]);
            }
            if (Operation::query()->whereIn('kind', ['tasks.cancel', 'tasks.maintenance_finish'])->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Task cancellation already owns this group.', 409);
            }
            // Every refusal comes before the cancel changes anything. An Operation still open on the group's VM, such as a release after a
            // failed start or a resume, refuses it; approved work that origin does not have refuses it too: task:publish comes first.
            $slot = TaskVm::forGroup($group);
            if ($slot instanceof TaskVm) {
                TaskVmSlotRuns::assertReleasable($slot);
            }
            if ($kind === 'tasks.cancel') {
                self::eligible($group->refresh(), $kind);
                $this->review->assertOnOrigin($group);
                $this->operations->cancelOpen($group);
            } elseif (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'The current Task root must settle before maintenance cleanup.', 409);
            }

            return DB::transaction(function () use ($group, $caller, $request, $kind): Operation {
                $input = $this->capture($group, $kind, reserve: true);

                return $this->start->execute($kind, 'task_group', $group->id, isset($input['node_id']) ? ValidatedData::integer($input['node_id']) : SwampNode::id(), $input, $caller, $request, lockKey: OperationStore::lockKey('task_group', $group->id));
            });
        }, wait: false);
    }

    /**
     * The full input of the cancel of a proof's group, captured with its VM before the VM exists in the provider, for the one Swamp
     * run that carries both. Starts no Operation. The Gateway projects the end of the group from this input when that run ends.
     *
     * @return array<string,mixed>
     */
    public function captureForProof(Task $group): array
    {
        return DB::transaction(fn (): array => $this->capture($group, 'tasks.cancel', reserve: false));
    }

    /**
     * The release of the group's slot. Nothing else ends: the claim ran no setup and published no Route, origin already has the approved
     * work, and the delete of the task VM removes the task's files. An admitted cancel reserves the VM as a release does; a proof's capture
     * runs before its VM exists and reserves nothing.
     *
     * @return array<string,mixed>
     */
    private function capture(Task $group, string $kind, bool $reserve): array
    {
        $group = Task::topLevel()->with(['project', 'taskable'])->lockForUpdate()->findOrFail($group->id);
        self::eligible($group, $kind);
        $workspace = $group->taskable;
        $slot = TaskVm::forGroup($group);
        if ($workspace !== null && (! $workspace instanceof Instance || ! $slot instanceof TaskVm
            || $workspace->task_workspace_owner_id !== $group->id || $workspace->node_id !== $slot->node_id)) {
            throw new ResourceOperationException('tasks.workspace_ownership_refused', 'Cancellation requires the exact owned slot workspace.', 409);
        }
        $release = $slot instanceof TaskVm ? ($reserve ? $this->slots->reserveRelease($slot) : $this->slots->release($slot)) : null;

        return ['group_id' => $group->id, 'project_id' => $group->project_id, 'instance_id' => $workspace?->id, 'task_vm_id' => $slot?->id,
            ...$slot instanceof TaskVm ? ['node_id' => (int) $slot->host_node_id] : [], 'release' => $release];
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
        } elseif (($group->status === TaskGroupStatus::Completed && $group->taskable_id === null
            && ! TaskVm::query()->where('group_id', $group->id)->where('state', '!=', TaskVmState::Destroyed)->exists()) || (in_array($group->status, TaskGroupStatus::awaitingCompletion(), true) && $group->pr_url)) {
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
