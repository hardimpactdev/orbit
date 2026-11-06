<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogRedactor;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admission of the owning native ending DAG. Records are retained until complete cleanup. */
final readonly class TaskCancelIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $keys,
        private TaskExecutionLock $execution, private TaskOperations $operations, private TaskVmDestroyIntent $destroy) {}

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
            if ($kind === 'tasks.cancel') {
                $this->operations->cancelOpen($group);
            } elseif (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'The current Task root must settle before maintenance cleanup.', 409);
            }

            // The push token is named here and minted by the push step.
            $token = $kind === 'tasks.cancel' && self::pushable($group->refresh()) ? $this->pushToken($group) : null;

            return DB::transaction(function () use ($group, $caller, $request, $kind, $token): Operation {
                $input = $this->capture($group, $kind, $token);

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
        return DB::transaction(fn (): array => $this->capture($group, 'tasks.cancel', null));
    }

    /**
     * @param  array{remote:string,copy:array{vault:string,key:string}}|null  $token
     * @return array<string,mixed>
     */
    private function capture(Task $group, string $kind, ?array $token): array
    {
        $group = Task::topLevel()->with(['project', 'taskable'])->lockForUpdate()->findOrFail($group->id);
        self::eligible($group, $kind);
        $workspace = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if ($workspace !== null && (! $workspace instanceof Instance || ! $vm instanceof TaskVm
            || $workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id
            || $workspace->node_id !== $vm->node_id)) {
            throw new ResourceOperationException('tasks.workspace_ownership_refused', 'Cancellation requires the exact owned TaskVM workspace.', 409);
        }
        $commit = $kind === 'tasks.cancel' ? TaskFinalReview::unpublishedRemovalCommit($group) : null;
        if ($commit !== null && ! is_array($token)) {
            throw new ResourceOperationException('tasks.push_failed', 'Publish the approved work before removing this workspace.', 409);
        }
        $capture = $workspace instanceof Instance ? $this->destroy->captureForWorkspace($vm, $workspace, $commit)
            : ($vm instanceof TaskVm && $vm->state !== TaskVmState::Destroyed ? $this->destroy->capture($vm) : []);
        $steps = $workspace instanceof Instance && $vm->state === TaskVmState::Ready
            && ! in_array($workspace->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true) ? app(ProjectLifecycleStepStore::class)->ordered($group->project, LifecyclePhase::Teardown) : [];
        $values = $workspace instanceof Instance ? app(LogRedactor::class)->valuesFor($workspace) : [];
        $teardown = array_map(static function ($step) use ($values): array {
            if (app(LogRedactor::class)->redact($step->command, $values) !== $step->command) {
                throw new ResourceOperationException('tasks.cancel_refused', 'Teardown commands must contain no credential values.', 422);
            }

            return ['input_json' => json_encode($step->toArray(), JSON_THROW_ON_ERROR), 'timeout_seconds' => $step->timeoutSeconds];
        }, $steps);

        $push = $commit === null || ! $workspace instanceof Instance ? null : [
            'commit' => $commit, 'checkout_path' => $workspace->checkout_path, 'remote' => $token['remote'],
            'refspec' => $commit.':refs/heads/'.TaskRemoteBranch::for($group), 'object_format' => strlen($commit) === 64 ? 'sha256' : 'sha1',
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($workspace->node), 'github_token' => $token['copy'],
        ];

        return [
            'workspace_routes' => [], 'task_vm_id' => null, ...$capture, 'group_id' => $group->id, 'project_id' => $group->project_id,
            'instance_id' => $workspace?->id, 'checkout_path' => $workspace?->checkout_path,
            'ssh_execution' => $teardown !== [] && $workspace instanceof Instance ? app(SwampSshExecutionInput::class)->forNode($workspace->node) : null,
            'teardown' => $teardown, 'push' => $push,
        ];
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
        if (TaskFinalReview::reconcileRemovalPush($group) !== null && ($kind !== 'tasks.cancel' || ! self::pushable($group))) {
            throw new ResourceOperationException('tasks.push_failed', 'Publish the approved work before removing this workspace.', 409);
        }
    }

    /** Cancel pushes approved work only from a running VM; a parked VM keeps its work until it is published. */
    private static function pushable(Task $group): bool
    {
        return TaskFinalReview::unpublishedRemovalCommit($group) !== null && $group->taskable instanceof Instance
            && TaskVm::forGroup($group)?->state === TaskVmState::Ready;
    }

    /** @return array{remote:string,copy:array{vault:string,key:string}} */
    private function pushToken(Task $group): array
    {
        $repository = GitHubRepository::fromOrigin($group->project->repository_url)
            ?? throw new ResourceOperationException('tasks.push_failed', 'The Project requires a GitHub repository to push approved work.', 422);
        // Only the vault key name is admitted. The approved push mints the token when it runs ({@see TaskGitHubTokens}).
        $copy = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];

        return ['remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git', 'copy' => $copy];
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $group = Task::topLevel()->findOrFail($operation->target_id);
        self::eligible($group, $operation->kind);
        if (($operation->input['group_id'] ?? null) !== $group->id || ($operation->input['project_id'] ?? null) !== $group->project_id
            || ($operation->input['instance_id'] ?? null) !== $group->taskable_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cancellation no longer owns its admitted group workspace.', 422);
        }

        if (($operation->input['task_vm_id'] ?? null) !== null) {
            $workspace = $group->taskable_id === null ? null : Instance::query()->findOrFail($group->taskable_id);
            $vm = TaskVm::query()->findOrFail(ValidatedData::integer($operation->input['task_vm_id']));
            $this->destroy->ownedForWorkspace($vm, $workspace, $operation->input, $operation->id);
        }

        return TaskVmProviderWorkflowInput::envelope($operation->input);
    }
}
