<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/** Records the exact checked candidate. Swamp owns Git and GitHub effects. */
final readonly class TaskPublishIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskCheckedCandidate $candidates) {}

    public function start(Task $group, StartTaskPublishData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
            return $existing;
        }
        $group->refresh()->load('taskable', 'project');
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $check = TaskCheck::query()->whereKey($input->check)->whereHas('task', fn ($tasks) => $tasks->where('parent_id', $group->id))->first();
        if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
            throw new ResourceOperationException('tasks.commit_not_checked', 'Publish requires this group\'s passed handoff candidate and required CI.', 422);
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $base = $group->project->default_branch;
        if ($repository === null || ! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new ResourceOperationException('tasks.publish_failed', 'The Project requires a GitHub repository and valid default branch.', 422);
        }

        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);
        $token = app(RepositoryPullRequestAccess::class)->token($repository);
        $copy = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'task-publish-token-'.Str::uuid(), $token);

        return $this->start->execute('tasks.publish', 'task_group', $group->id, $instance->node_id, [
            ...$input->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'checkout_path' => $instance->checkout_path, 'commit' => $check->commit_sha, 'tree' => $check->tree_after,
            'repository' => $repository->owner.'/'.$repository->name, 'base' => $base, 'branch' => 'task-'.$group->id,
            'ssh_execution' => $ssh,
            'execution_secrets' => ['github_token' => $copy],
        ], $caller, $request);
    }
}
