<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequest;
use App\Models\Task;

/**
 * Pushes the task branch from the group's workspace and opens its pull request.
 * The refspec names the stored approved commit, never HEAD (ADR 0160).
 */
interface TaskPullRequestPublisher
{
    /**
     * Pushes `$commit` and opens the pull request. When the branch already has an open draft, such as the one
     * the handoffs opened, Orbit gives it this title and body and marks it ready instead of opening another.
     *
     * @return string the pull request's web URL
     *
     * @throws TaskPullRequestException
     */
    public function publish(Task $group, string $body, string $commit): string;

    /**
     * Pushes a handoff commit to the task branch so CI tests it ([Handoff CI](/reference/tasks#handoff-ci)), and
     * makes sure a draft pull request runs that CI: the group's pull request, converted to a draft first, or else a
     * draft that Orbit opens for the branch, or the open pull request the branch already has. A draft cannot merge
     * unreviewed work. A merged or closed stored pull request is returned without a push.
     *
     * @return GitHubPullRequest that pull request as GitHub reports it now
     *
     * @throws TaskPullRequestException pending while the push job waits or runs
     */
    public function publishHandoff(Task $group, string $commit): GitHubPullRequest;

    /**
     * Pushes an approved `$commit` to the task branch without opening a pull request. When a handoff made the
     * group's stored pull request a draft, the push marks it ready again: an approved commit is reviewed work.
     *
     * @throws TaskPullRequestException
     */
    public function push(Task $group, string $commit): void;

    /**
     * Gives back a pull request that a handoff made a draft, for reviewed work or a group that ends. A stored open
     * draft is marked ready. Without a stored pull request, the draft that the handoffs opened is closed.
     *
     * @throws TaskPullRequestException
     */
    public function releaseHandoffDraft(Task $group): void;
}
