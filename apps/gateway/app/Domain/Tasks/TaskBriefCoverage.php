<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\LaravelAiTaskBriefCoverage;
use App\Models\Task;
use Illuminate\Container\Attributes\Bind;

/**
 * Asks Jev whether the pull request change list covers every subtask of the group, except cancelled and failed subtasks.
 */
#[Bind(LaravelAiTaskBriefCoverage::class)]
interface TaskBriefCoverage
{
    /**
     * @param  list<string>|null  $approvalChanges
     * @return list<string> the titles of subtasks that no change covers
     *
     * @throws TaskSessionClassificationException
     */
    public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array;
}
