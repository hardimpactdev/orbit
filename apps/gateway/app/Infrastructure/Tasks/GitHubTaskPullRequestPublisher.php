<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRemoteBranch;
use App\Models\Task;

/** Handoff cleanup remains until the native terminal Tasks flows replace its last caller. */
final readonly class GitHubTaskPullRequestPublisher implements TaskPullRequestPublisher
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function deleteHandoffBranch(Task $group): void
    {
        $group->loadMissing('project');
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url)
            ?? throw new TaskPullRequestException('The Project repository is not on github.com.');

        try {
            $this->github->deleteBranch($this->access->token($repository), $repository, TaskRemoteBranch::handoff($group));
        } catch (GitHubApiException $exception) {
            throw new TaskPullRequestException('The handoff branch could not be deleted: '.$exception->getMessage(), previous: $exception);
        }
    }
}
