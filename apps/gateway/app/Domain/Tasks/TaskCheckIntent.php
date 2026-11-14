<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Records board metadata and the owned workspace: a task VM checkout or a container Instance. Swamp executes the check. */
final readonly class TaskCheckIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskCheckEvidence $evidence) {}

    public function start(Task $group, StartTaskCheckData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.check')) !== null) {
            return $existing;
        }
        TaskProvisionPending::refuse($group);
        // A container Instance workspace has no task VM to wake.
        if (TaskInstanceWorkspace::forGroup($group) === null) {
            TaskVmAwake::require($group);
        }

        return DB::transaction(function () use ($group, $input, $caller, $request): Operation {
            $group->setRawAttributes(Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (($existing = $this->idempotency->replay('tasks.check')) !== null) {
                return $existing;
            }
            $intent = $this->capture($group, $input, hostedCi: true);

            return $this->start->execute('tasks.check', 'task_group', $group->id, ValidatedData::integer($intent['node_id']), $intent, $caller, $request);
        });
    }

    /**
     * The intent of one check of the current subtask, and its check row as `running`. A handoff check with hosted CI names the vault keys
     * of its tokens. A review round checks its fix without hosted CI: the pull request runs its own CI on the pushed head.
     *
     * @return array<string,mixed>
     */
    public function capture(Task $group, StartTaskCheckData $input, bool $hostedCi): array
    {
        $group->load('taskable', 'project');
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        $container = TaskInstanceWorkspace::forGroup($group);
        $instance = $container->instance ?? $group->taskable;
        if (! $instance instanceof Instance || ($container === null && ($instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === ''))) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $task = $group->tasks()->findOrFail($input->subtask);
        $current = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->value('id');
        if ($current !== $task->id) {
            throw new ResourceOperationException('tasks.subtask_not_current', 'Only the current open subtask can run.', 409);
        }
        if ($input->phase === TaskCheckKind::Baseline && $this->evidence->groupImplementerStarted($group)) {
            throw new ResourceOperationException('tasks.baseline_done', 'An implementer has already started in this task group.', 409);
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $ci = $hostedCi && $input->phase === TaskCheckKind::Handoff ? $group->project->handoffCheckName() : null;
        $setup = $input->phase === TaskCheckKind::Baseline ? $this->evidence->baselineSetup($group) : [];
        $workspace = null;
        if ($container !== null) {
            TaskCopyTurn::refuseUnended($group);
            TaskInstanceWorkspace::refuseUnavailable($ci, $setup);
            $workspace = $container->intent();
        }
        if ($ci !== null && $repository === null) {
            throw new ResourceOperationException('tasks.repository_unavailable', 'Hosted CI requires a GitHub repository.', 422);
        }
        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);
        $copies = [];
        if ($ci !== null) {
            $key = (string) Str::uuid();
            $copies = [
                'github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-write-'.$key],
                'checks_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-read-'.$key],
                // The push reads the token only as a Git extra header, as tasks.publish does.
                'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-git-'.$key],
            ];
        }
        $check = $task->checks()->create(['kind' => $input->phase, 'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);

        return [
            'subtask' => $task->id, 'phase' => $input->phase->value, 'group_id' => $group->id, 'instance_id' => $instance->id,
            'node_id' => $instance->node_id, 'checkout_path' => $workspace === null ? $instance->checkout_path : TaskInstanceWorkspace::Root, 'ssr_port' => $instance->ssr_port,
            'check_id' => $check->id, 'command' => $group->project->taskCheckCommand(),
            'setup' => $setup,
            'deliverables' => $input->phase === TaskCheckKind::Handoff ? self::withFailsOnBase($task->deliverables ?? []) : [],
            'base' => TaskReviewBase::commit($task), 'message' => TaskHandoffCiChecks::message($task),
            'repository' => $repository === null ? null : $repository->owner.'/'.$repository->name,
            'check_name' => $ci, 'branch' => TaskRemoteBranch::handoff($group),
            'ssh_execution' => $ssh, 'execution_secrets' => $copies, 'admitted_at' => now()->toISOString(),
            // The time limit of each SSH step of the check workflows, so a step against a VM that is gone ends with the Operation.
            'run_budget_seconds' => OperationRunBudgets::historySeconds(OperationKinds::TasksCheck),
            ...($workspace === null ? [] : ['workspace' => $workspace]),
        ];
    }

    /**
     * The check workflows read `fails_on_base` of every command deliverable, so the intent states it: an omitted value means false.
     * Other deliverables stay as stored.
     *
     * @param  array<int, mixed>  $deliverables
     * @return list<mixed>
     */
    public static function withFailsOnBase(array $deliverables): array
    {
        $admitted = [];
        foreach ($deliverables as $item) {
            $isCommand = is_array($item) && ($item['type'] ?? null) === 'command';
            $admitted[] = $isCommand && ! array_key_exists('fails_on_base', $item) ? [...$item, 'fails_on_base' => false] : $item;
        }

        return $admitted;
    }
}
