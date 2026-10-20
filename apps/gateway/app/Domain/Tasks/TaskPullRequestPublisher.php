<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

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
     * Pushes a handoff commit to Orbit's own branch `task-{id}` so CI tests it
     * ([Handoff CI](/reference/tasks#handoff-ci)), and makes sure a draft pull request of that branch runs the CI:
     * the group's own `pr_url`, converted to a draft first, or else a draft Orbit opens, or the open pull request the
     * branch already has. An incoming pull request's branch and state are never touched. A merged or closed own
     * pull request gets no push.
     *
     * @return int the number of the pull request that runs CI on the commit
     *
     * @throws TaskPullRequestException pending while the push job waits or runs
     */
    public function publishHandoff(Task $group, string $commit): int;

    /**
     * Pushes an approved `$commit` to the task branch without opening a pull request. When a handoff made the
     * group's own pull request a draft, the push marks it ready again: an approved commit is reviewed work.
     *
     * @throws TaskPullRequestException
     */
    public function push(Task $group, string $commit): void;

    /**
     * Gives back the pull request that the handoffs used when the group ends. The group's own `pr_url`, if a handoff
     * made it a draft, is marked ready. Otherwise the open drafts of `task-{id}` that the handoffs opened are closed.
     *
     * @throws TaskPullRequestException
     */
    public function releaseHandoffDraft(Task $group): void;
}
