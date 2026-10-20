<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\SourceControl\GitBranchName;
use App\Models\Task;

/**
 * The branch on `origin` that a task fetches and pushes. It is `task-{id}`, or the incoming pull request's
 * head branch (ADR 0203). The workspace's local branch stays `task-{id}` either way.
 */
final readonly class TaskRemoteBranch
{
    /** @throws TaskPullRequestException when a stored pull request branch is not a valid branch name */
    public static function for(Task $group): string
    {
        $branch = $group->pr_branch;
        if (! is_string($branch) || $branch === '') {
            return self::handoff($group);
        }
        if (! GitBranchName::isValid($branch)) {
            throw new TaskPullRequestException('The pull request branch is not a valid Git branch name.');
        }

        return $branch;
    }

    /**
     * `task-{id}`, Orbit's own branch, where every handoff commit goes for CI (/reference/tasks#handoff-ci), also for
     * an incoming pull request: unreviewed commits never reach an outside author's branch.
     */
    public static function handoff(Task $group): string
    {
        return 'task-'.$group->id;
    }
}
