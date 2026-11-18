<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\Operation;
use App\Models\Task;

/**
 * The Gateway's reader of a task copy's content read (docs/reference/tasks.md#published-work-of-a-copy). The rule itself runs in the
 * copy, at the end of its `base` phase; this class only reads the verdict that the newest succeeded `tasks.sync` recorded, and holds
 * it against the copy's current head, so a commit made after the read is never taken as published. Cancel (TaskEnd) and complete
 * read it here.
 */
final readonly class TaskWork
{
    public function __construct(private ContainerWorkspaceRead $read) {}

    /**
     * Why the group's committed work does not count as carried to the default branch, or null when it does: the copy's latest read is
     * of its current head, found every committed path on the default branch, and the copy changed something.
     */
    public function refusal(Task $group, TaskInstanceWorkspace $copy): ?string
    {
        return self::committed($group, $this->read->read($copy->instance)['head_commit']) ?? (self::latest($group)['empty'] ?? false ? 'work_empty' : null);
    }

    /**
     * Why the copy's committed work at `$head` is not on the default branch, or null when the latest content read is of that head and
     * found every committed path there: `work_unread` (no read of that head: run task:sync) or `work_not_on_default`.
     */
    public static function committed(Task $group, ?string $head): ?string
    {
        $work = self::latest($group);
        if ($work === null || $head === null || $work['head'] !== $head) {
            return 'work_unread';
        }

        return $work['paths']['count'] > 0 ? 'work_not_on_default' : null;
    }

    /**
     * Whether the default branch holds the group's approved commit by content: the copy's latest read is of its current head, was made
     * for that approved commit, finds it in the head's history and every committed path on the default branch.
     */
    public function holdsApproved(Task $group, TaskInstanceWorkspace $copy, string $approved): bool
    {
        $work = self::latest($group);

        return $work !== null && $work['approved'] === $approved && $work['approved_held'] === true && $work['paths']['count'] === 0
            && $work['head'] === $this->read->read($copy->instance)['head_commit'];
    }

    /**
     * The `work` of the group's newest succeeded sync, with the approved commit its intent named, or null when it recorded none.
     *
     * @return array{head: string, empty: bool, paths: array{count: int}, approved_held: ?bool, approved: string}|null
     */
    private static function latest(Task $group): ?array
    {
        $sync = Operation::query()->where('kind', 'tasks.sync')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first(['input', 'result']);
        if ($sync === null) {
            return null;
        }
        $work = $sync->result['work'] ?? null;
        $paths = is_array($work) ? ($work['paths'] ?? null) : null;
        if (! is_array($work) || ! is_array($paths) || ! is_string($work['head'] ?? null) || ! is_bool($work['empty'] ?? null)
            || ! is_int($paths['count'] ?? null)) {
            return null;
        }
        $copy = $sync->input['copy'] ?? null;
        $approved = is_array($copy) ? ($copy['approved'] ?? '') : '';

        return ['head' => $work['head'], 'empty' => $work['empty'], 'paths' => ['count' => $paths['count']],
            'approved_held' => is_bool($work['approved_held'] ?? null) ? $work['approved_held'] : null,
            'approved' => is_string($approved) ? $approved : ''];
    }
}
