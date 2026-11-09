<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
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

    /** A save that failed this many times in a row since the group's last other Operation stops the idle release and asks for assistance. */
    public const int MaxFailures = 3;

    /**
     * Whether the idle release stops for the group until its next other Operation: its saves ended without success
     * {@see self::MaxFailures} times in a row since then, or one was refused for changing a workflow, which no retry changes.
     */
    public static function exhausted(Task $group): bool
    {
        $operations = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id);
        $since = (clone $operations)->where('kind', '!=', self::Kind)->max('id') ?? 0;
        $ended = (clone $operations)->where('kind', self::Kind)->where('id', '>', $since)
            ->whereIn('status', [OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted]);

        return (clone $ended)->count() >= self::MaxFailures || (clone $ended)->where('error_code', 'tasks.workflows_permission')->exists();
    }

    /**
     * The commit a new claim of the group starts from, given `$head`, the commit the claim would otherwise restore. A turn or a prep
     * fix (`$continue`) continues from the idle save when that holds `$head`. A review round or a build acts on `$head` itself, so a
     * save ahead of it refuses until a turn and a publish put that work on the task branch. A save that `$head` moved past is
     * contained in it. A save that `$head` moved away from refuses, so the next save cannot overwrite it.
     */
    public function restore(Task $group, string $head, bool $continue): string
    {
        $saved = $group->idle_saved_commit;
        if ($saved === null || $saved === $head) {
            return $head;
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        $branch = self::branch($group);
        $details = ['saved_commit' => $saved, 'branch' => $branch, 'head' => $head];
        try {
            $token = $repository === null ? null : $this->access->contentsReadToken($repository);
            // An unreadable branch counts as present, so only a branch GitHub reports gone takes the missing path.
            $present = $repository === null || $token === null || $this->github->branchHead($token, $repository, $branch) !== null;
        } catch (GitHubApiException) {
            $token = null;
            $present = true;
        }
        try {
            $comparison = $repository === null || $token === null ? null : $this->github->compareCommits($token, $repository, $head, $saved);
        } catch (GitHubApiException) {
            $comparison = null;
        }
        if (! $present) {
            // A deleted save branch is no save once the task branch holds its commit; otherwise its work is not on origin.
            if ($comparison !== null && ($comparison->status === GitHubComparisonStatus::Behind || $comparison->status === GitHubComparisonStatus::Identical)) {
                return $head;
            }
            throw new ResourceOperationException('tasks.idle_save_missing', 'The save branch '.$branch.' is gone and the task branch does not hold its commit '.$saved
                .'. Push '.$saved.' to '.$branch.' again, or merge it into the task branch, then try again. Cancel the group when that commit is lost.', 409, details: $details);
        }
        if ($comparison === null) {
            // Restoring the task branch here could drop the saved work, so the claim waits for a readable comparison.
            throw new ResourceOperationException('tasks.idle_save_unreadable', 'The Gateway could not compare the saved workspace with the task branch. Try again.', 503, details: $details);
        }
        if ($comparison->headDescendsFromBase()) {
            if ($continue) {
                return $saved;
            }
            throw new ResourceOperationException('tasks.idle_save_pending', 'The idle save of this group holds work beyond '.$head.'. Run a turn and publish it first.', 409, details: $details);
        }
        if ($comparison->status === GitHubComparisonStatus::Diverged) {
            throw new ResourceOperationException('tasks.idle_save_diverged', 'The task branch moved away from the idle save on '.self::branch($group)
                .'. Merge that branch into the task branch, with `-s ours` to drop the save, then try again.', 409, details: $details);
        }

        return $head;
    }
}
