<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewOverflowException;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewRound;

/** Read-only evidence for the ready and merge steps. The Gateway reads GitHub here and changes nothing on it. */
final readonly class TaskPullRequestEvidence
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public static function reviewer(): string
    {
        $login = config('task_pull_requests.reviewer');

        return is_string($login) && $login !== '' ? $login : throw new ResourceOperationException('tasks_pr.reviewer_missing', 'The Gateway has no reviewer for task pull requests.', 409);
    }

    public function ready(Task $group, string $head, string $mode): TaskPullRequestTarget
    {
        $target = $this->target($group, $head);
        if ($mode === 'manual' && strcasecmp($target->pull->authorLogin ?? '', self::reviewer()) === 0) {
            throw new ResourceOperationException('tasks_pr.reviewer_is_author', 'GitHub refuses a review request from the author of the pull request.', 409);
        }

        return $target;
    }

    /** A review round needs a pull request out of draft, with a green merge check on the exact head. */
    public function round(Task $group, string $head): TaskPullRequestTarget
    {
        $target = $this->target($group, $head);
        if ($target->pull->draft) {
            throw new ResourceOperationException('tasks_pr.draft', 'The pull request is still a draft; take it out of draft first.', 409);
        }

        return $target;
    }

    public function merge(Task $group, string $head): TaskPullRequestTarget
    {
        $target = $this->target($group, $head);
        if ($target->pull->draft) {
            throw new ResourceOperationException('tasks_pr.draft', 'The pull request is still a draft; take it out of draft first.', 409);
        }
        if ($group->review_mode === TaskReviewMode::Automatic) {
            $passed = TaskReviewRound::query()->where('task_group_id', $group->id)->where('head_sha', $head)->where('verdict', TaskReviewRoundVerdict::Pass->value)->exists();

            return $passed ? $target : throw new ResourceOperationException('tasks_pr.approval_missing', 'No review round passed this head.', 409, details: ['state' => 'round_missing']);
        }
        $this->requireApproval($target, $head);

        return $target;
    }

    /** The approval of a spec: the open pull request is a draft on the exact head, and in manual mode the latest review of the reviewer approves it. */
    public function build(Task $group, string $head): TaskPullRequestTarget
    {
        $target = $this->target($group, $head, checks: false);
        if (! $target->pull->draft) {
            throw new ResourceOperationException('tasks_pr.not_draft', 'The pull request is not a draft, so the spec is not waiting for approval.', 409);
        }
        if ($group->review_mode === TaskReviewMode::Manual) {
            $this->requireApproval($target, $head);
        }

        return $target;
    }

    private function requireApproval(TaskPullRequestTarget $target, string $head): void
    {
        try {
            $reviews = $this->github->reviews($this->access->reviewsToken($target->repository), $target->repository, $target->number);
        } catch (GitHubApiException|GitHubReviewOverflowException $exception) {
            throw $this->unreadable($exception);
        }
        $gate = PullRequestGate::manual($reviews, $head, self::reviewer());
        if ($gate !== PullRequestGateState::Approved) {
            throw new ResourceOperationException('tasks_pr.approval_missing', 'The reviewer has not approved this head.', 409, details: ['state' => $gate->value]);
        }
    }

    private function target(Task $group, string $head, bool $checks = true): TaskPullRequestTarget
    {
        $group->loadMissing('project');
        $project = $group->project;
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        $number = $group->watched_pr_number;
        $url = $group->watched_pr_url;
        if ($group->parent_id !== null || $group->orchestration !== TaskOrchestration::External || ! $repository instanceof GitHubRepository
            || ! is_int($number) || $number < 1 || ! is_string($url) || $repository->pullRequestNumber($url) !== $number) {
            throw new ResourceOperationException('tasks_pr.no_pull_request', 'The group needs a published pull request.', 409);
        }

        return $this->read($project, $repository, $number, $head, $checks);
    }

    /**
     * A pull request that no Task group watches: open, out of draft, into the default branch, from a branch of the repository itself, on the exact head,
     * with the merge check passed on it. The call to merge it is the approval,
     * so no review is read.
     */
    public function outside(Project $project, int $number, string $head): TaskPullRequestTarget
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        if (! $repository instanceof GitHubRepository) {
            throw new ResourceOperationException('tasks_pr.no_pull_request', 'The Project needs a GitHub repository.', 409);
        }
        $target = $this->read($project, $repository, $number, $head, checks: true);
        if ($target->pull->draft) {
            throw new ResourceOperationException('tasks_pr.draft', 'The pull request is still a draft.', 409);
        }
        if ($target->pull->baseRef !== $project->default_branch) {
            throw new ResourceOperationException('tasks_pr.wrong_base', 'The pull request does not target the default branch of the Project.', 409);
        }
        if ($target->pull->fromFork) {
            throw new ResourceOperationException('tasks_pr.from_fork', 'A pull request from a fork is not merged here: its own workflow can report the merge check.', 409);
        }

        return $target;
    }

    private function read(Project $project, GitHubRepository $repository, int $number, string $head, bool $checks): TaskPullRequestTarget
    {
        try {
            $pull = $this->github->pullRequest($this->access->reviewsToken($repository), $repository, $number);
            if ($pull->state !== GitHubPullRequestState::Open) {
                throw new ResourceOperationException('tasks_pr.no_pull_request', 'The pull request is not open.', 409);
            }
            if ($pull->headSha !== $head) {
                throw new ResourceOperationException('tasks_pr.head_moved', 'The pull request head is not the admitted head.', 409);
            }
            if (! $checks) {
                return new TaskPullRequestTarget($repository, $number, $pull);
            }
            $name = $project->mergeCheckName();
            $token = $name === null ? null : $this->access->checksToken($repository);
            $state = $name === null ? 'not_configured' : ($token === null ? 'unreadable' : RequiredCheckState::of($this->github->checkRuns($token, $repository, $head, $name), $head, $name)->value);
        } catch (GitHubApiException $exception) {
            throw $this->unreadable($exception);
        }
        if ($state !== RequiredCheckState::Passed->value) {
            throw new ResourceOperationException('tasks_pr.checks_not_passed', 'The Project merge check did not pass on this head.', 409, details: ['state' => $state]);
        }

        return new TaskPullRequestTarget($repository, $number, $pull);
    }

    private function unreadable(GitHubApiException|GitHubReviewOverflowException $exception): ResourceOperationException
    {
        report($exception);

        return new ResourceOperationException('tasks_pr.github_unreadable', 'The Gateway could not read the pull request from GitHub.', 502);
    }
}
