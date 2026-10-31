<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Records board metadata and the owned workspace. Swamp executes the check. */
final readonly class TaskCheckIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskCheckEvidence $evidence) {}

    public function start(Task $group, StartTaskCheckData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.check')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($group, $input, $caller, $request): Operation {
            $group->setRawAttributes(Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            if (($existing = $this->idempotency->replay('tasks.check')) !== null) {
                return $existing;
            }
            $group->load('taskable', 'project');
            if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
            }
            $instance = $group->taskable;
            if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
                || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === '') {
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
            $ci = $input->phase === TaskCheckKind::Handoff ? $group->project->handoffCheckName() : null;
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
                ];
            }
            $check = $task->checks()->create(['kind' => $input->phase, 'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);

            return $this->start->execute('tasks.check', 'task_group', $group->id, $instance->node_id, [
                'subtask' => $task->id, 'phase' => $input->phase->value, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'node_id' => $instance->node_id, 'checkout_path' => $instance->checkout_path, 'ssr_port' => $instance->ssr_port,
                'check_id' => $check->id, 'command' => $group->project->taskCheckCommand(),
                'setup' => $input->phase === TaskCheckKind::Baseline ? $this->evidence->baselineSetup($group) : [],
                'deliverables' => $input->phase === TaskCheckKind::Handoff ? ($task->deliverables ?? []) : [],
                'base' => TaskReviewBase::commit($task), 'message' => TaskHandoffCiChecks::message($task),
                'repository' => $repository === null ? null : $repository->owner.'/'.$repository->name,
                'check_name' => $ci, 'branch' => TaskRemoteBranch::handoff($group),
                'ssh_execution' => $ssh, 'execution_secrets' => $copies, 'admitted_at' => now()->toISOString(),
            ], $caller, $request);
        });
    }
}
