<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/**
 * The save of an idle group's workspace and its restore (docs/reference/tasks.md#release-an-idle-task-vm, docs/cli/task/idle-save.mmd).
 * Before the idle release deletes a VM, `tasks.idle_save` commits the workspace and pushes its head to the group's save branch; the
 * next claim starts from that commit when it holds the task branch head. Swamp owns the Git and GitHub effects.
 */
final readonly class TaskIdleSave
{
    public const string Kind = 'tasks.idle_save';

    public function __construct(private StartOperationAction $start, private GitHubApi $github, private RepositoryPullRequestAccess $access) {}

    public static function branch(Task $group): string
    {
        return 'task-'.$group->id.'-idle';
    }

    /** Admits the save of the group's workspace on its VM; `$head` is the task branch head on origin. Call in the transaction that locked the group and the VM. */
    public function admit(Task $group, TaskVm $vm, string $head): Operation
    {
        $group->load('taskable', 'project');
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '')
            ?? throw new ResourceOperationException('tasks.idle_save_failed', 'The Project requires a GitHub repository.', 422);
        $branch = self::branch($group);
        // Only the vault key names are admitted. The push step mints the token when it runs ({@see TaskGitHubTokens}).
        $secrets = ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()],
            'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()]];

        return $this->start->execute(self::Kind, 'task_group', $group->id, $instance->node_id, [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'task_vm_id' => $vm->id, 'node_id' => $instance->node_id,
            'checkout_path' => $instance->checkout_path, 'repository' => $repository->owner.'/'.$repository->name,
            'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
            'save_branch' => $branch, 'refspec' => '+refs/heads/candidate:refs/heads/'.$branch, 'object_format' => strlen($head) === 64 ? 'sha256' : 'sha1',
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node), 'execution_secrets' => $secrets,
        ], 'task-vm-idle', null, lockKey: OperationStore::lockKey('task_group', $group->id));
    }

    /**
     * The commit a new claim of the group starts from: its saved commit when that holds `$head`, the task branch head the claim would
     * restore, and otherwise `$head`. A save that the task branch has moved past or away from stays on its branch on origin.
     */
    public function restore(Task $group, string $head): string
    {
        $saved = $group->idle_saved_commit;
        if ($saved === null || $saved === $head) {
            return $head;
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        try {
            $comparison = $repository === null ? null : $this->github->compareCommits($this->access->contentsReadToken($repository), $repository, $head, $saved);
        } catch (GitHubApiException) {
            $comparison = null;
        }
        if ($comparison === null) {
            // Restoring the task branch here could drop the saved work, so the claim waits for a readable comparison.
            throw new ResourceOperationException('tasks.idle_save_unreadable', 'The Gateway could not compare the saved workspace with the task branch. Try again.', 503,
                details: ['saved_commit' => $saved, 'branch' => self::branch($group)]);
        }

        return $comparison->status === GitHubComparisonStatus::Identical || $comparison->headDescendsFromBase() ? $saved : $head;
    }
}
