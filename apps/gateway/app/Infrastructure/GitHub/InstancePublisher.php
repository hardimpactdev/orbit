<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Data\Instances\InstancePublishInput;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubOpenedPullRequest;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmGit;
use App\Models\Instance;

/** Development publishing shares Tasks' verified pack transfer and mirror. No credential reaches the checkout. */
final readonly class InstancePublisher
{
    public function __construct(
        private TaskVmGit $git,
        private DevelopmentSshExecutor $ssh,
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private CommandDeadline $deadline,
    ) {}

    public function check(Instance $instance, InstancePublishInput $input): void
    {
        try {
            $workflows = $this->changesWorkflows($instance, $input->commit);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException('instance_publish.source_unavailable', 'The Instance checkout does not contain a readable commit.', previous: $exception);
        }
        try {
            $repository = $this->repository($instance);
            $this->access->token($repository, false);
        } catch (GitHubApiException $exception) {
            throw new ResourceOperationException('instance_publish.github_unavailable', 'The GitHub App cannot publish this repository.', previous: $exception);
        }
        if ($workflows) {
            try {
                $this->access->token($repository, true);
            } catch (GitHubApiException $exception) {
                throw new ResourceOperationException(
                    $exception->isTokenRefusal() ? 'instance_publish.workflows_permission' : 'instance_publish.github_unavailable',
                    'The GitHub App could not obtain Workflows: write for this commit.', previous: $exception,
                );
            }
        }
    }

    private function changesWorkflows(Instance $instance, string $commit): bool
    {
        $type = $this->ssh->execute($instance->node, new RemoteCommand([
            'git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $instance->checkout_path,
            'cat-file', '-t', $commit,
        ]), 'instance-publish-check', 'instance_publish.source_unavailable');
        if (trim($type->stdout) !== 'commit') {
            throw new RuntimeConvergenceException('instance-publish-check', 'instance_publish.source_unavailable', 'The supplied SHA does not name a commit.');
        }
        $result = $this->ssh->execute($instance->node, new RemoteCommand([
            'git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $instance->checkout_path,
            'diff-tree', '--root', '-m', '--no-commit-id', '--name-only', '-r', $commit, '--', '.github/workflows/',
        ]), 'instance-publish-check', 'instance_publish.source_unavailable');

        return trim($result->stdout) !== '';
    }

    public function push(Instance $instance, InstancePublishInput $input): void
    {
        $repository = $this->repository($instance);
        $write = GitReadEnvironment::forGitHubToken($this->access->token($repository, $this->changesWorkflows($instance, $input->commit)));
        $deadline = microtime(true) + $this->deadline->cap(1580.0);
        $tips = $this->git->fetch($instance->project, [$input->branch], $write, 'instance_publish.push_failed', $deadline);
        $this->git->publish($instance->project, $instance->checkout_path, $input->commit, $input->branch,
            array_values(array_filter($tips, is_string(...))), $write,
            fn (RemoteCommand $command): CommandResult => $this->ssh->execute($instance->node, $command, 'instance-publish-pack', 'instance_publish.push_failed'),
            'instance_publish.push_failed', $deadline, requireFastForward: true);
    }

    public function pullRequest(Instance $instance, InstancePublishInput $input): GitHubOpenedPullRequest
    {
        $repository = $this->repository($instance);
        $token = $this->access->token($repository, false);
        $draft = $input->pull_request;
        assert($draft !== null);
        foreach ($this->github->pullRequestsByHead($token, $repository, $input->branch, openOnly: true) as $existing) {
            if ($existing->state !== GitHubPullRequestState::Open) {
                continue;
            }
            $pr = $this->github->pullRequest($token, $repository, $existing->number);
            if ($pr->state !== GitHubPullRequestState::Open) {
                continue;
            }
            if ($pr->baseRef !== $draft['base']) {
                throw new ResourceOperationException('instance_publish.pull_request_base_mismatch', 'The open pull request has another base.');
            }

            return new GitHubOpenedPullRequest($existing->url, $existing->number, null);
        }

        return $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($input->branch, $draft['base'], $draft['title'], $draft['body']));
    }

    private function repository(Instance $instance): GitHubRepository
    {
        return GitHubRepository::fromOrigin((string) $instance->project->repository_url)
            ?? throw new GitHubApiException('Publish requires a github.com repository.');
    }
}
