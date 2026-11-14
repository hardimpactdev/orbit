<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Builder;

/**
 * The publish of a task copy's done check, for the tick and for the release call alike (docs/reference/tasks.md#publish-by-itself):
 * the group title, a body of the brief, the completed subtasks and the checked commit, and a ready pull request. The publish's
 * `.env` content check reads this title and body too.
 */
final class TaskDonePublication
{
    /** A publish whose step waited too long for a Swamp behavior lock: no refusal, the tick starts the same check again. */
    public const string LockTimeoutCode = 'tasks.publish_lock_timeout';

    /** The limit of a pull request body that the publish request accepts. */
    public const int BodyLimit = 65536;

    public static function for(Task $group, TaskCheck $check): StartTaskPublishData
    {
        $group->loadMissing('tasks');
        $subtasks = $group->tasks->filter(static fn (Task $task): bool => $task->status === TaskStatus::Completed)->sortBy([['position', 'asc'], ['id', 'asc']])
            ->map(static fn (Task $task): string => '- '.$task->title)->implode("\n");
        $tail = "\n\n## Subtasks\n\n{$subtasks}\n\nChecked commit `{$check->commit_sha}`, handoff check {$check->id}.";
        // A long brief is cut so the whole body stays within the limit; the subtasks and the checked commit always stay.
        $brief = trim((string) $group->brief);
        $room = max(0, self::BodyLimit - mb_strlen($tail));
        if (mb_strlen($brief) > $room) {
            $brief = mb_substr($brief, 0, max(0, $room - 1)).'…';
        }
        $body = mb_substr($brief.$tail, 0, self::BodyLimit);

        return new StartTaskPublishData($check->id, ['title' => $group->title, 'body' => $body, 'draft' => false]);
    }

    /** How many publishes of one done check may time out on a lock, the first and its retries, before the check shows as refused. */
    public const int LockTimeoutAttempts = 3;

    /**
     * The newest publish of this done check, by any caller, that ended without success: Orbit does not start that check again by itself,
     * and the group shows it as refused (docs/reference/tasks.md#a-refused-automatic-publish). A newest publish that timed out on a lock is
     * none while fewer than three publishes of the check did: the tick starts the check again.
     */
    public static function unsuccessful(Task $group, TaskCheck $check): ?Operation
    {
        $failed = self::publishes($group, $check)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Cancelled->value, OperationStatus::Interrupted->value])
            ->orderByDesc('id')->first();
        if ($failed?->error_code === self::LockTimeoutCode && self::lockTimeouts($group, $check) < self::LockTimeoutAttempts) {
            return null;
        }

        return $failed;
    }

    /** The attempt of the tick's next publish of this check: one more than its publishes that timed out on a lock, by any caller. */
    public static function attempt(Task $group, TaskCheck $check): int
    {
        return self::lockTimeouts($group, $check) + 1;
    }

    private static function lockTimeouts(Task $group, TaskCheck $check): int
    {
        return self::publishes($group, $check)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value])
            ->where('error_code', self::LockTimeoutCode)->count();
    }

    /** @return Builder<Operation> */
    private static function publishes(Task $group, TaskCheck $check): Builder
    {
        return Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('input->check', $check->id);
    }

    /** The code a refused publish shows: its own, or `operation.cancelled` and the like when it ended without one. */
    public static function code(Operation $operation): string
    {
        return $operation->error_code ?? 'operation.'.$operation->status->value;
    }
}
