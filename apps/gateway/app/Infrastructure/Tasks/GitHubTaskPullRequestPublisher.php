<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubOpenedPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Domain\Tasks\TaskReviewRequestLogins;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * The token reaches the Node only on the SSH process's standard input, as for a read
 * ([ADR 0098](/decisions/0098-read-github-repositories-through-a-gateway-owned-github-app)).
 * A task VM never gets the token: a queued job pushes from the Gateway's mirror ({@see TaskVmGit::publish()}).
 * A failed push names git's error, with the token removed. A missing Workflows permission is named.
 */
final readonly class GitHubTaskPullRequestPublisher implements TaskPullRequestPublisher
{
    /** Last lines of git's stderr. Progress above them is not useful in an assistance reason. */
    private const int PushErrorLines = 12;

    /** End of those lines, in bytes, so one huge line cannot fill the assistance reason. */
    private const int PushErrorBytes = 2000;

    /**
     * A queued push that has not finished after this long failed: the job's 1700 seconds, a minute for its worker to
     * start, and a margin.
     */
    private const int PublishStaleSeconds = 2400;

    private const string WorkflowRefusal = 'GitHub refused a change under .github/workflows/: grant the Orbit GitHub App the Workflows (read and write) permission, or push the commit yourself.';

    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private TaskWorkspaceExecutor $workspaces,
        private TaskVmGit $git,
    ) {}

    public function publish(Task $group, string $body, string $commit): string
    {
        [$repository, $instance, $branch] = $this->target($group);
        $base = $group->project->default_branch;
        if (! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new TaskPullRequestException('The Project has no valid default branch.');
        }

        try {
            $token = $this->access->token($repository);
            $this->pushBranch($group, $instance, $branch, $token, $commit);
            $opened = $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, $base, $group->title, $body));
            $this->requestReviewers($token, $repository, $opened);

            return $opened->url;
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The pull request could not be opened: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function requestReviewers(#[SensitiveParameter] string $token, GitHubRepository $repository, GitHubOpenedPullRequest $opened): void
    {
        $configured = config('orbit.tasks.review_request_logins');
        $logins = TaskReviewRequestLogins::withoutAuthor(
            is_array($configured) ? array_values(array_filter($configured, is_string(...))) : [],
            $opened->authorLogin,
        );
        if ($logins === []) {
            return;
        }

        $number = $opened->number ?? $repository->pullRequestNumber($opened->url);
        if ($number === null) {
            Log::warning('The task pull request reviewers could not be requested.', [
                'reason' => 'The pull request number could not be resolved.',
                'repository' => $repository->owner.'/'.$repository->name,
                'url' => $opened->url,
            ]);

            return;
        }

        try {
            $this->github->requestPullRequestReviewers($token, $repository, $number, $logins);
        } catch (GitHubApiException $exception) {
            Log::warning('The task pull request reviewers could not be requested.', [
                'reason' => $exception->getMessage(),
                'repository' => $repository->owner.'/'.$repository->name,
                'pull_request' => $number,
                'reviewers' => $logins,
            ]);
        }
    }

    public function push(Task $group, string $commit): void
    {
        [$repository, $instance, $branch] = $this->target($group);

        try {
            $this->pushBranch($group, $instance, $branch, $this->access->token($repository), $commit);
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The task branch could not be pushed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{GitHubRepository, Instance, string}
     *
     * @throws TaskPullRequestException
     */
    private function target(Task $group): array
    {
        $group->loadMissing(['project', 'taskable']);
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        if (! $repository instanceof GitHubRepository) {
            throw new TaskPullRequestException('The Project repository is not on github.com.');
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->checkout_path === ''
            || $instance->project_id !== $group->project_id
            || ! TaskVmWorkspaceGuard::allowsWorkspace($group, $instance)) {
            throw new TaskPullRequestException('The task workspace is unavailable.');
        }

        return [$repository, $instance, TaskRemoteBranch::for($group)];
    }

    private function pushBranch(Task $group, Instance $instance, string $branch, #[SensitiveParameter] string $token, string $commit): void
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            throw new TaskPullRequestException('The approved commit is not a Git SHA.');
        }
        $instance->loadMissing(['node', 'project']);
        if ($group->task_compute === TaskCompute::Vm) {
            $this->publishFromVm($group, $branch, $commit);

            return;
        }
        $script = GitReadScript::for(GitReadEnvironment::forGitHubToken($token), WorkspaceGit::networkPreamble().<<<'BASH'
            checkout=$1
            branch=$2
            commit=$3
            repository=$4
            common=$5
            isolated_push "$common" "$repository" "$commit:refs/heads/$branch"
            BASH);
        try {
            $this->workspaces->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $branch, $commit, (string) $instance->loadMissing('project')->project->repository_url, WorkspaceGit::commonDirectory($instance)],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ), 'task-pull-request-push', 'tasks.push_failed');
        } catch (RuntimeConvergenceException $exception) {
            $this->failedPush($exception, $token);
        }
    }

    /**
     * A task VM never gets the token. The Gateway takes the approved commit out of the VM into its mirror and pushes
     * from there ([Git on a task VM](/reference/compute-drivers#publish-from-the-vm)). That transfer runs at the VM's
     * pace, so a queued job runs it ({@see PublishTaskVmCommit}) and this call only queues and observes it: it returns
     * once GitHub has the commit at the branch, reports a failed job once, and is pending in between. The task VM row
     * holds the commit and branch of the push, so a repeated call queues nothing new.
     *
     * @throws TaskPullRequestException pending while the job waits or runs
     */
    private function publishFromVm(Task $group, string $branch, string $commit): void
    {
        $vm = TaskVm::forGroup($group) ?? throw new TaskPullRequestException('The task workspace is unavailable.');
        if ($vm->publish_commit === $commit && $vm->publish_branch === $branch && $vm->publish_state instanceof TaskVmPublishState) {
            if ($vm->publish_state === TaskVmPublishState::Pushed) {
                return;
            }
            $stale = $vm->publish_state === TaskVmPublishState::Queued
                && ! ($vm->publish_queued_at?->isAfter(now()->subSeconds(self::PublishStaleSeconds)) ?? false);
            if ($vm->publish_state === TaskVmPublishState::Queued && ! $stale) {
                throw TaskPullRequestException::pending("Orbit is pushing commit {$commit} to {$branch}.");
            }
            // The failure is reported once. The next push, after the scheduler's backoff, queues a new job.
            TaskVm::query()->whereKey($vm->id)->where('publish_commit', $commit)->where('publish_branch', $branch)
                ->where('publish_state', $vm->publish_state)->update(['publish_state' => null]);

            throw new TaskPullRequestException($stale
                ? 'The task branch could not be pushed: the publish job did not finish in '.self::PublishStaleSeconds.' seconds.'
                : ($vm->publish_error ?? 'The task branch could not be pushed.'));
        }
        $vm->update([
            'publish_commit' => $commit, 'publish_branch' => $branch, 'publish_state' => TaskVmPublishState::Queued,
            'publish_error' => null, 'publish_queued_at' => now(),
        ]);
        PublishTaskVmCommit::dispatch($vm->id, $commit, $branch);

        throw TaskPullRequestException::pending("Orbit is pushing commit {$commit} to {$branch}.");
    }

    /**
     * The work of {@see PublishTaskVmCommit}: fetches the default branch and the task branch into the mirror, so the VM
     * sends only the new objects, then takes the approved commit out of the VM and pushes exactly that commit. It acts
     * only while the row still names this commit and branch as queued, and records the result there.
     *
     * @param  float  $deadline  a `microtime(true)` that no Git command may outlast
     */
    public function publishQueued(TaskVm $vm, string $commit, string $branch, float $deadline): void
    {
        $queued = static fn () => TaskVm::query()->whereKey($vm->id)->where('publish_commit', $commit)
            ->where('publish_branch', $branch)->where('publish_state', TaskVmPublishState::Queued);
        if (! $queued()->exists()) {
            return;
        }
        try {
            $group = $vm->group ?? throw new TaskPullRequestException('The task workspace is unavailable.');
            [$repository, $instance, $expected] = $this->target($group);
            if ($expected !== $branch || $group->task_compute !== TaskCompute::Vm) {
                throw new TaskPullRequestException('The task workspace is unavailable.');
            }
            $instance->loadMissing(['node', 'project']);
            $token = $this->access->token($repository);
            $project = $instance->project;
            $access = GitReadEnvironment::forGitHubToken($token);
            $default = $group->project->default_branch;
            try {
                $tips = $this->git->fetch($project, array_values(array_filter([is_string($default) && GitBranchName::isValid($default) ? $default : null, $branch], is_string(...))), $access, 'tasks.push_failed', $deadline);
                $this->git->publish(
                    $project,
                    $instance->checkout_path,
                    $commit,
                    $branch,
                    array_values(array_filter($tips, is_string(...))),
                    $access,
                    fn (RemoteCommand $command): CommandResult => $this->workspaces->execute($instance, $command, 'task-pull-request-push', 'tasks.push_failed'),
                    'tasks.push_failed',
                    $deadline,
                );
            } catch (RuntimeConvergenceException $exception) {
                $this->failedPush($exception, $token);
            }
        } catch (TaskPullRequestException $exception) {
            $queued()->update(['publish_state' => TaskVmPublishState::Failed, 'publish_error' => $exception->getMessage()]);

            return;
        } catch (GitHubApiException $exception) {
            $queued()->update(['publish_state' => TaskVmPublishState::Failed, 'publish_error' => 'The task branch could not be pushed: '.$exception->getMessage()]);

            return;
        }
        $queued()->update(['publish_state' => TaskVmPublishState::Pushed, 'publish_error' => null]);
        $this->collectGarbage($project, $deadline);
    }

    /** A failed collection only leaves the mirror larger. The next publish tries again. */
    private function collectGarbage(Project $project, float $deadline): void
    {
        try {
            $this->git->collectGarbage($project, $deadline);
        } catch (Throwable $exception) {
            Log::warning('The task VM Git mirror could not collect garbage.', ['project_id' => $project->id, 'reason' => $exception->getMessage()]);
        }
    }

    /**
     * Git's own message is the only sign of a rejected push. Keep the last lines, without the
     * installation token. GitHub's workflow refusal names the permission the Project needs.
     */
    private function failedPush(RuntimeConvergenceException $exception, #[SensitiveParameter] string $token): never
    {
        $stderr = $exception->result instanceof CommandResult ? $exception->result->stderr : '';
        $message = 'The task branch could not be pushed.';

        if ($this->refusedWorkflow($stderr)) {
            $message .= ' '.self::WorkflowRefusal;
        }

        $tail = $this->stderrTail($stderr, $token);
        if ($tail !== '') {
            $message .= "\n".$tail;
        }

        throw new TaskPullRequestException($message, previous: $exception);
    }

    private function refusedWorkflow(string $stderr): bool
    {
        return str_contains($stderr, 'refusing to allow a GitHub App to create or update workflow ')
            && str_contains($stderr, '.github/workflows/')
            && str_contains($stderr, 'without `workflows` permission');
    }

    private function stderrTail(string $stderr, #[SensitiveParameter] string $token): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $this->redactToken($stderr, $token));
        $text = trim(mb_scrub($text, 'UTF-8'));
        if ($text === '') {
            return '';
        }

        $tail = implode("\n", array_slice(explode("\n", $text), -self::PushErrorLines));
        if (strlen($tail) <= self::PushErrorBytes) {
            return $tail;
        }

        return mb_strcut($tail, -self::PushErrorBytes, null, 'UTF-8');
    }

    private function redactToken(string $text, #[SensitiveParameter] string $token): string
    {
        if ($token === '') {
            return $text;
        }

        $secrets = [
            'x-access-token:'.$token,
            base64_encode('x-access-token:'.$token),
            base64_encode($token),
            $token,
        ];
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace($secrets, '[REDACTED]', $text);
    }
}
