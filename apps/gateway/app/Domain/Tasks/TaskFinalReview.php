<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;

/**
 * The precondition that keeps approved work when a group ends (docs/cli/task/complete.mmd, docs/cli/task/cancel.mmd): complete and
 * cancel remove the workspace and its task VM only when origin already has the group's latest approved commit. Neither pushes, wakes a
 * VM or starts a review; `task:publish` comes first, and it resumes a parked VM.
 */
final readonly class TaskFinalReview
{
    public const string FixupProblem = 'final-review';

    public function __construct(private GitHubApi $github, private RepositoryPullRequestAccess $access) {}

    /**
     * Refuses with `tasks.approved_work_unpublished` and changes nothing while the group holds a workspace or a task VM and origin's task
     * branch does not reach its latest approved commit. Only when GitHub answers that the branch does not exist and the group's pull
     * request merged does that pull request's head stand in for it. Every failed GitHub read refuses too.
     */
    public function assertOnOrigin(Task $group): void
    {
        $commit = self::approvedCommit($group);
        if ($commit === null || (! $group->workspace()->exists() && ! TaskVm::forGroup($group) instanceof TaskVm)) {
            return;
        }
        $branch = TaskRemoteBranch::for($group);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        if ($repository === null || ! $this->reaches($repository, $group, $commit, $branch)) {
            throw new ResourceOperationException('tasks.approved_work_unpublished',
                "Approved commit {$commit} is not on origin's {$branch} branch. Run task:publish first; it resumes a parked task VM.", 409,
                details: ['commit' => $commit, 'branch' => $branch]);
        }
    }

    /** The group's latest approved commit: the latest commit Orbit reviewed (ADR 0203), or else the commit of the latest approval. */
    public static function approvedCommit(Task $group): ?string
    {
        $sha = $group->reviewsBeforePush()
            ? TaskReviewedCommit::query()->where('task_id', $group->id)->latest('id')->value('sha')
            : TaskComment::query()->where('task_group_id', $group->id)->where('type', TaskCommentType::Approved)
                ->whereNotNull('commit_sha')->latest('id')->value('commit_sha');

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /** Reads refs and comparisons with a `contents: read` token, and the pull request with a `pull_requests: read` one. */
    private function reaches(GitHubRepository $repository, Task $group, string $commit, string $branch): bool
    {
        try {
            $contents = $this->access->contentsReadToken($repository);
            $head = $this->github->branchHead($contents, $repository, $branch);
            if ($head === null) {
                $number = is_string($group->pr_url) ? $repository->pullRequestNumber($group->pr_url) : null;
                $pull = $number === null ? null : $this->github->pullRequest($this->access->cachedReadToken($repository), $repository, $number);
                $head = $pull?->state === GitHubPullRequestState::Merged ? $pull->headSha : null;
            }

            return $head !== null && $this->contains($contents, $repository, $commit, $head);
        } catch (GitHubApiException) {
            return false;
        }
    }

    private function contains(string $token, GitHubRepository $repository, string $commit, string $head): bool
    {
        $comparison = $this->github->compareCommits($token, $repository, $commit, $head);

        return $comparison->status === GitHubComparisonStatus::Identical || $comparison->headDescendsFromBase();
    }
}
