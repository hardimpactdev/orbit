<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/**
 * Admits `tasks.build`: the run that starts the implementation of a command once its spec is approved. The gate check calls it. It records
 * the approval, appends the implementation subtask, moves the group to `running` and captures the whole intent, so no step of the run
 * calls the Gateway. See docs/cli/task/build.mmd.
 */
final readonly class TaskBuildIntent
{
    public function __construct(private TaskOperations $operations, private TaskVmResumeIntent $resume, private TaskSyncIntent $sync) {}

    /**
     * A prep group whose spec is done and whose build has not started: its only subtask is the completed spec subtask, a prep run
     * succeeded, and no build was admitted. Manual prep parks the group (`waiting_for_review`); automatic prep leaves it `running`, and
     * so does a park that was refused. A group with a build subtask never matches, whatever its status.
     */
    public static function waiting(Task $group): bool
    {
        $subtasks = $group->tasks()->get();
        $runs = static fn (string $kind) => Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)->where('kind', $kind);

        return in_array($group->status, [TaskGroupStatus::WaitingForReview, TaskGroupStatus::Running], true)
            && $subtasks->count() === 1 && $subtasks->first()?->status === TaskStatus::Completed
            && $runs(OperationKinds::TasksPrep)->where('status', OperationStatus::Succeeded)->exists()
            && ! $runs(OperationKinds::TasksBuild)->exists();
    }

    public function start(Task $group, string $head, string $caller, ?string $request): Operation
    {
        return $this->operations->start($group, OperationKinds::TasksBuild, [], static function (Task $group): void {
            if (! self::waiting($group)) {
                throw new ResourceOperationException('tasks.build_refused', 'The build needs a prep group whose spec is done and whose build has not started.', 409);
            }
        }, $caller, $request, fn (Task $group): array => $this->capture($group, $head));
    }

    /** @return array<string,mixed> */
    private function capture(Task $group, string $head): array
    {
        $group->loadMissing('project', 'taskable');
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $prep = Operation::query()->where('kind', OperationKinds::TasksPrep)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === ''
            || ! $prep instanceof Operation || $repository === null || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.build_refused', 'The build needs the owned task VM workspace of a prep group, its Pi credential and its GitHub repository.', 409);
        }
        if (! in_array($vm->state, [TaskVmState::Parked, TaskVmState::Ready], true)) {
            throw new ResourceOperationException('tasks.build_refused', 'The build needs the task VM parked or running.', 409);
        }
        $prompts = TaskPrepPrompts::for(ValidatedData::string(ValidatedData::object($prep->input)['command']));
        $branch = 'task-'.$group->id;
        $instance->loadMissing('node');
        $resume = $vm->state === TaskVmState::Parked ? TaskVmProviderWorkflowInput::envelope($this->resume->capture($vm)) : null;
        $sync = $this->sync->capture($group);
        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);
        $subtask = $this->appendSubtask($group, $prompts, $head, $branch);
        // Park or not, the group builds from here on; it is no longer settled.
        $group->update(['status' => TaskGroupStatus::Running, 'settled_at' => null]);
        $thread = TaskPrepThreads::create($group, $subtask, $instance, 'implementer');
        // A query update: every subtask row carries `review_mode`, and a model save of a subtask refuses it.
        Task::query()->whereKey($subtask->id)->update(['implementer_agent_thread_id' => $thread->id]);

        return [
            'command' => $prompts->command, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'subtask' => $subtask->id,
            'task_vm_id' => $vm->id,
            'approval' => ['head' => $head, 'mode' => $group->review_mode->value, 'reviewer' => $group->review_mode === TaskReviewMode::Manual ? TaskPullRequestEvidence::reviewer() : null],
            ...($resume === null ? [] : ['resume' => $resume['intent'], 'resume_secrets' => $resume['execution_secrets']]),
            'sync' => $sync,
            'turn' => TaskPrepThreads::turn($thread, TaskTurnPrompt::compose($subtask, $prompts->buildTurn()), true, $group, $subtask, $instance, $ssh),
            // Only vault key names are admitted. The Pi token is written when the run starts ({@see TaskBuildProjection::workflowInput()}),
            // and so is the read token of the sync child ({@see TaskGitHubTokens::writeClone()}).
            'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]],
        ];
    }

    /** The implementation subtask after the spec subtask, with the fixed brief and the two deliverables of the build. */
    private function appendSubtask(Task $group, TaskPrepPrompts $prompts, string $head, string $branch): Task
    {
        $check = $group->project->task_check;
        $deliverables = [
            new TaskDeliverable('runner-plan', TaskDeliverableType::Command, 'The runner plan of the cases file passes.', command: $prompts->plan()),
            is_string($check) && $check !== ''
                ? new TaskDeliverable('project-check', TaskDeliverableType::Command, 'The Project check passes.', command: $check)
                : new TaskDeliverable('project-check', TaskDeliverableType::Review, 'The Project check passes.'),
        ];

        return Task::query()->create([
            'parent_id' => $group->id, 'position' => StoredInteger::fromOrZero($group->tasks()->max('position')) + 1, 'status' => TaskStatus::Todo,
            'title' => $prompts->buildTitle(), 'brief' => $prompts->buildBrief($head, $branch),
            'deliverables' => array_map(static fn (TaskDeliverable $deliverable): array => $deliverable->toArray(), $deliverables),
        ]);
    }
}
