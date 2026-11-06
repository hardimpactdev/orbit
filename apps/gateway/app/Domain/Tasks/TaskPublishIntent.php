<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
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
        TaskVmAwake::require($group);

        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): Operation => $this->admit($group, $input, $caller, $request));
    }

    private function admit(Task $group, StartTaskPublishData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
            return $existing;
        }
        $group->refresh()->load('taskable', 'project');
        $this->assertIdle($group);
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

        // Only the vault key names are admitted. The publish step mints the token when it runs ({@see TaskGitHubTokens}).
        $copy = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];
        $authorization = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];

        return DB::transaction(function () use ($group, $instance, $check, $repository, $base, $copy, $authorization, $input, $caller, $request): Operation {
            Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
                return $existing;
            }
            $this->assertIdle($group);
            $branch = 'task-'.$group->id;
            $commit = ValidatedData::string($check->commit_sha);
            $tree = ValidatedData::string($check->tree_after);
            $operation = $this->start->execute('tasks.publish', 'task_group', $group->id, $instance->node_id, [
                ...$input->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'checkout_path' => $instance->checkout_path, 'commit' => $commit, 'tree' => $tree,
                'repository' => $repository->owner.'/'.$repository->name, 'base' => $base, 'branch' => $branch,
                'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
                'refspec' => $commit.':refs/heads/'.$branch,
                'object_format' => strlen($commit) === 64 ? 'sha256' : 'sha1',
                'pull_head' => $repository->owner.':'.$branch, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
                'execution_secrets' => ['github_token' => $copy, 'git_authorization' => $authorization],
            ], $caller, $request, lockKey: OperationStore::lockKey('task_group', $group->id));

            return $operation;
        });
    }

    /**
     * Where a run pushes: the checkout, the repository and the branch of the pull request, with the SSH target. A prep fix and a review
     * round hold it in their intent and publish with it; the commit and the tree come from their own steps.
     *
     * @param  array<string,mixed>  $ssh
     * @return array<string,mixed>
     */
    public static function destination(Task $group, Instance $instance, GitHubRepository $repository, array $ssh): array
    {
        $branch = 'task-'.$group->id;

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'checkout_path' => $instance->checkout_path, 'repository' => $repository->owner.'/'.$repository->name,
            'base' => $group->project->default_branch, 'branch' => $branch, 'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
            'pull_head' => $repository->owner.':'.$branch, 'ssh_execution' => $ssh,
        ];
    }

    private function assertIdle(Task $group): void
    {
        if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
        }
    }
}
