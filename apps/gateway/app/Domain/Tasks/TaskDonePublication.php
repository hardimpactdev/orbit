<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * The publish of a task copy's done check, for the tick and for the release call alike (docs/reference/tasks.md#publish-by-itself):
 * the group title, a body of the brief, the completed subtasks and the checked commit, and a ready pull request. The publish's
 * `.env` content check reads this title and body too.
 */
final class TaskDonePublication
{
    public static function for(Task $group, TaskCheck $check): StartTaskPublishData
    {
        $group->loadMissing('tasks');
        $subtasks = $group->tasks->filter(static fn (Task $task): bool => $task->status === TaskStatus::Completed)->sortBy([['position', 'asc'], ['id', 'asc']])
            ->map(static fn (Task $task): string => '- '.$task->title)->implode("\n");
        $body = trim((string) $group->brief)."\n\n## Subtasks\n\n{$subtasks}\n\nChecked commit `{$check->commit_sha}`, handoff check {$check->id}.";

        return new StartTaskPublishData($check->id, ['title' => $group->title, 'body' => $body, 'draft' => false]);
    }
}
