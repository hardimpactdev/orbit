<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Task;
use App\Models\TaskComment;

/** Detects edits to the generated group, including its subtasks and comments. */
final readonly class ProblemGroupSnapshot
{
    public function hash(Task $group): string
    {
        $rows = Task::query()->withoutGlobalScope('subtask')
            ->where(fn ($query) => $query->whereKey($group->id)->orWhere('parent_id', $group->id))
            ->orderBy('id')->get()->map(static function (Task $task): array {
                $attributes = $task->getAttributes();
                ksort($attributes);

                return $attributes;
            })->all();
        $comments = TaskComment::query()->where('task_group_id', $group->id)->orderBy('id')->get()->map->getAttributes()->all();

        return hash('sha256', json_encode([$rows, $comments], JSON_THROW_ON_ERROR));
    }

    public function unchanged(Task $group, mixed $hash): bool
    {
        return $group->groupStatus() === TaskGroupStatus::Backlog
            && is_string($hash) && hash_equals($hash, $this->hash($group));
    }
}
