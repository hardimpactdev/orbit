<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;
use App\Models\Task;
use Throwable;

/**
 * Fetches `origin/{base}` with the pull request token. `{base}` is one argument. The fetch updates
 * the remote-tracking ref and does not check out or rebase the task branch (ADR 0164).
 * `fastForward` uses the refs already fetched for the turn and moves only a strictly behind workspace.
 * `fetchForTurn` fetches the default branch, `task-{id}`, and a different pull request base with the
 * read token and `--no-tags`. It updates remote-tracking refs and does not move HEAD.
 */
final readonly class GitHubTaskBaseBranchFetcher implements TaskBaseBranchFetcher
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private RepositoryReadAccess $reads,
        private GitHubApi $github,
        private TaskWorkspaceExecutor $workspaces,
        private TaskVmGit $git,
    ) {}

    public function fetch(Task $group, string $base): void
    {
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $instance = $group->taskable;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ! TaskVmWorkspaceGuard::allowsWorkspace($group, $instance)) {
            throw new TaskPullRequestException('The base branch could not be fetched.');
        }

        try {
            $this->sendToVm($instance, [$base], [], GitReadEnvironment::forGitHubToken($this->access->token($repository)), 'task-base-fetch', 'The base branch could not be fetched.');
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The base branch could not be fetched.', previous: $exception);
        }
    }

    public function fastForward(Task $group, bool $missingRefOk = false): void
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === '') {
            throw new TaskPullRequestException('The task branch could not be fetched.');
        }

        $instance->loadMissing('node');
        // The turn fetch has already updated this ref. This step is local and needs no token.
        $script = WorkspaceGit::bashPreamble().<<<'BASH'
            checkout=$1
            branch=$2
            status=0
            git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch" || status=$?
            if [ "$status" -eq 1 ] && [ "${3:-}" = "missing-ok" ]; then
                exit 0
            fi
            if [ "$status" -ne 0 ]; then
                exit "$status"
            fi
            head=$(git -C "$checkout" rev-parse HEAD)
            remote=$(git -C "$checkout" rev-parse "refs/remotes/origin/$branch")
            if [ "$head" != "$remote" ] && git -C "$checkout" merge-base --is-ancestor "$head" "$remote"; then
                git -C "$checkout" merge --ff-only --quiet "$remote"
            fi
            BASH;
        $arguments = ['bash', '-seu', '--', $instance->checkout_path, TaskRemoteBranch::for($group)];
        if ($missingRefOk) {
            $arguments[] = 'missing-ok';
        }
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: $arguments,
                input: $script,
            ), 'task-branch-sync', 'tasks.fetch_failed', mutates: true, changesCheckout: true);
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The task branch could not be fetched.', previous: $exception);
        }
    }

    public function resetToDefault(Task $group): string
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        $local = $instance instanceof Instance && is_string($instance->branch) && $instance->branch !== '' ? $instance->branch : 'task-'.$group->id;
        if (! $instance instanceof Instance || $instance->checkout_path === ''
            || ! is_string($default) || ! GitBranchName::isValid($default) || ! GitBranchName::isValid($local)) {
            throw new TaskPullRequestException('The baseline workspace could not be reset.');
        }
        $instance->loadMissing('node');
        // The checks and the reset run in one command: another branch, a commit that is not on the
        // default branch, or a tracked change is manual work, and the reset refuses it.
        $script = WorkspaceGit::bashPreamble().<<<'BASH'
            checkout=$1
            branch=$2
            local=$3
            tip=$(git -C "$checkout" rev-parse --verify "refs/remotes/origin/$branch^{commit}")
            if [ "$(git -C "$checkout" symbolic-ref -q HEAD)" != "refs/heads/$local" ] \
                || ! git -C "$checkout" merge-base --is-ancestor HEAD "$tip" \
                || ! git -C "$checkout" diff --quiet --cached HEAD -- \
                || ! git -C "$checkout" diff --quiet --; then
                echo 'The workspace has manual work.' >&2
                exit 3
            fi
            git -C "$checkout" reset --hard --quiet "$tip"
            git -C "$checkout" rev-parse HEAD
            BASH;
        try {
            $result = $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $default, $local],
                input: $script,
            ), 'task-baseline-reset', 'tasks.baseline_reset_failed', mutates: true, changesCheckout: true);
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException('The baseline workspace could not be reset.', previous: $exception);
        }
        $head = trim($result->stdout);
        if (preg_match('/\\A[0-9a-f]{40}\\z/', $head) !== 1) {
            throw new TaskPullRequestException('The baseline workspace start commit could not be read.');
        }

        return $head;
    }

    public function mergeBase(Task $group): string
    {
        $instance = $this->workspace($group);
        $default = $group->project->default_branch;
        if (! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The merge base could not be read.');
        }
        $script = WorkspaceGit::bashPreamble().<<<'BASH'
            checkout=$1
            branch=$2
            git -C "$checkout" merge-base HEAD "refs/remotes/origin/$branch"
            BASH;

        return $this->commitFrom($instance, $script, [$default], 'task-merge-base', 'The merge base could not be read.');
    }

    public function moveTo(Task $group, string $sha): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new TaskPullRequestException('The pull request head is not a Git SHA.');
        }
        $instance = $this->workspace($group);
        // Only a workspace without tracked changes moves. Untracked Orbit files such as .mcp.json stay.
        $script = WorkspaceGit::bashPreamble().<<<'BASH'
            checkout=$1
            sha=$2
            git -C "$checkout" cat-file -e "$sha^{commit}"
            git -C "$checkout" diff --quiet HEAD --
            git -C "$checkout" reset --hard --quiet "$sha"
            git -C "$checkout" rev-parse HEAD
            BASH;
        if ($this->commitFrom($instance, $script, [$sha], 'task-pull-request-head', 'The workspace could not move to the pull request head.', changesCheckout: true) !== $sha) {
            throw new TaskPullRequestException('The workspace could not move to the pull request head.');
        }
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws TaskPullRequestException
     */
    private function commitFrom(Instance $instance, string $script, array $arguments, string $step, string $failure, bool $changesCheckout = false): string
    {
        try {
            $result = $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: $script,
            ), $step, 'tasks.fetch_failed', mutates: $changesCheckout, changesCheckout: $changesCheckout);
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException($failure, previous: $exception);
        }
        $commit = trim($result->stdout);
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new TaskPullRequestException($failure);
        }

        return $commit;
    }

    /** @throws TaskPullRequestException */
    private function workspace(Task $group): Instance
    {
        $group->loadMissing(['project', 'taskable']);
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === '' || $instance->project_id !== $group->project_id) {
            throw new TaskPullRequestException('The task workspace is unavailable.');
        }
        $instance->loadMissing('node');

        return $instance;
    }

    public function fetchForTurn(Task $group): void
    {
        try {
            $this->fetchTurnRefs($group);
        } catch (TaskPullRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
    }

    /**
     * @throws TaskPullRequestException
     */
    private function fetchTurnRefs(Task $group): void
    {
        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        $instance = $group->taskable;
        $default = $group->project->default_branch;
        if (! $repository instanceof GitHubRepository || ! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ! TaskVmWorkspaceGuard::allowsWorkspace($group, $instance)
            || ! is_string($default) || ! GitBranchName::isValid($default)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }
        $taskBranch = TaskRemoteBranch::for($group);
        if (! GitBranchName::isValid($taskBranch)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        try {
            $environment = $this->reads->for((string) $group->project->repository_url, $group->project->source_access);
        } catch (ResourceOperationException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }

        $pullBase = $this->pullRequestBase($group, $repository, $default, $taskBranch);
        $this->sendToVm($instance, array_filter([$default, $pullBase], is_string(...)), [$taskBranch], $environment, 'task-turn-fetch', 'The workspace refs could not be fetched.');
    }

    /**
     * The pull request base when the turn should fetch it, or null when it is absent or already included.
     * The base name is read on the Gateway. The git fetch itself uses the read token.
     *
     * @throws TaskPullRequestException
     */
    private function pullRequestBase(Task $group, GitHubRepository $repository, string $default, string $taskBranch): ?string
    {
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            return null;
        }
        $number = $repository->pullRequestNumber($group->pr_url);
        if ($number === null) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        try {
            $base = $this->github->pullRequest($this->access->token($repository), $repository, $number)->baseRef;
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.', previous: $exception);
        }
        if (! is_string($base) || $base === '' || $base === $default || $base === $taskBranch) {
            return null;
        }
        if (! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The workspace refs could not be fetched.');
        }

        return $base;
    }

    /**
     * A task VM gets its refs from the Gateway's mirror, so no token reaches it
     * ([Git on a task VM](/reference/compute-drivers#git-on-a-task-vm)). A missing optional branch loses its ref.
     *
     * @param  list<string>  $required
     * @param  list<string>  $optional
     *
     * @throws TaskPullRequestException
     */
    private function sendToVm(Instance $instance, array $required, array $optional, GitReadEnvironment $access, string $step, string $failure): void
    {
        $project = $instance->loadMissing(['project', 'node'])->project;
        try {
            $tips = $this->git->fetch($project, [...$required, ...$optional], $access, 'tasks.fetch_failed');
            foreach ($required as $branch) {
                if ($tips[$branch] === null) {
                    throw new TaskPullRequestException($failure." The repository has no branch [{$branch}].");
                }
            }
            $this->git->send($project, $instance->checkout_path, $tips, fn (RemoteCommand $command): CommandResult => $this->workspaces->execute($instance, $command, $step, 'tasks.fetch_failed', mutates: true), 'tasks.fetch_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskPullRequestException($failure, previous: $exception);
        }
    }
}
