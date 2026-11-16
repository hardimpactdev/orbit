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
 * it against the copy's current head, so a commit made after the read is never taken as published.
 */
final readonly class TaskCopyWork
{
    public function __construct(private ContainerWorkspaceRead $read) {}

    /**
     * Why the group's committed work does not count as carried to the default branch, or null when it does: the copy's latest read is
     * of its current head, found every committed path on the default branch, and the copy changed something.
     */
    public function refusal(Task $group, TaskInstanceWorkspace $copy): ?string
    {
        $work = self::latest($group);
        if ($work === null || $work['head'] !== $this->read->read($copy->instance)['head']) {
            return 'work_unread';
        }
        if ($work['paths']['count'] > 0) {
            return 'work_not_on_default';
        }

        return $work['empty'] ? 'work_empty' : null;
    }

    /**
     * Whether the default branch holds the group's approved commit by content: the copy's latest read is of its current head, was made
     * for that approved commit, finds it in the head's history and every committed path on the default branch.
     */
    public function holdsApproved(Task $group, TaskInstanceWorkspace $copy, string $approved): bool
    {
        $work = self::latest($group);

        return $work !== null && $work['approved'] === $approved && $work['approved_held'] === true && $work['paths']['count'] === 0
            && $work['head'] === $this->read->read($copy->instance)['head'];
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
        $work = is_array($sync?->result) ? ($sync->result['work'] ?? null) : null;
        if (! is_array($work) || ! is_string($work['head'] ?? null) || ! is_bool($work['empty'] ?? null) || ! is_int($work['paths']['count'] ?? null)) {
            return null;
        }
        $approved = is_array($sync->input) && is_array($sync->input['copy'] ?? null) ? ($sync->input['copy']['approved'] ?? '') : '';

        return ['head' => $work['head'], 'empty' => $work['empty'], 'paths' => ['count' => $work['paths']['count']],
            'approved_held' => is_bool($work['approved_held'] ?? null) ? $work['approved_held'] : null,
            'approved' => is_string($approved) ? $approved : ''];
    }
}
