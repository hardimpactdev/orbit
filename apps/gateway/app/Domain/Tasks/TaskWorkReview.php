<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where the review of a task with no subtask stands, from Orbit's records alone (docs/reference/tasks.md#a-task-with-no-subtask). The
 * work's status does not say it: a reviewer turn runs while the work stays `running`, and a completion without a reviewer turn on the
 * newest check is no approval. Approval is of the reviewed check, not readiness to publish it: a publish, the copy's removal or the
 * task's end keep it, and a reopen or a newer handoff check drops it.
 */
final readonly class TaskWorkReview
{
    public const string Reviewing = 'reviewing';

    public const string Approved = 'approved';

    public const string NotApproved = 'not_approved';

    /**
     * The review of each group, by id, with the reviewer turns and handoff checks of the whole page read once. `reviewing` while the newest
     * turn of the reviewer thread has not ended. `approved` while the work is completed, its newest handoff check passed, and the reviewer
     * thread's newest turn reviewed exactly that check's commit and ended done (TaskGroupDone::reviewedBy). `not_approved` otherwise. Null
     * for a task with subtasks.
     *
     * @param  Collection<int, Task>  $groups  with their subtasks loaded
     * @return array<int, string|null>
     */
    public static function of(Collection $groups): array
    {
        $works = [];
        foreach ($groups as $group) {
            $work = $group->tasks->first(static fn (Task $task): bool => $task->type === TaskType::Work);
            if ($work instanceof Task) {
                $works[$group->id] = $work;
            }
        }
        $review = array_fill_keys(array_values($groups->modelKeys()), null);
        if ($works === []) {
            return $review;
        }
        $reviewers = array_filter($groups->whereIn('id', array_keys($works))->pluck('reviewer_agent_thread_id', 'id')->all(), is_int(...));
        $turns = [];
        if ($reviewers !== []) {
            $operations = Operation::query()->where('kind', OperationKinds::TasksCopyTurn)->where('target_type', OperationKinds::TaskGroupTarget)
                ->whereIn('target_id', array_keys($reviewers))->orderByDesc('id')->get();
            foreach ($operations as $operation) {
                $group = (int) $operation->target_id;
                if (! isset($turns[$group]) && ($operation->input['thread_id'] ?? null) === $reviewers[$group]) {
                    $turns[$group] = $operation;
                }
            }
        }
        $checks = TaskCheck::query()->whereIn('task_id', array_map(static fn (Task $work): int => $work->id, array_values($works)))
            ->where('kind', TaskCheckKind::Handoff->value)->orderByDesc('id')->get()->unique('task_id')->keyBy('task_id');
        foreach ($works as $id => $work) {
            $turn = $turns[$id] ?? null;
            $check = $checks->get($work->id);
            $review[$id] = match (true) {
                $turn instanceof Operation && ! $turn->status->terminal() => self::Reviewing,
                $work->status === TaskStatus::Completed && $check instanceof TaskCheck && $check->status === TaskCheckStatus::Passed
                    && TaskGroupDone::reviewedBy($turn, $work, $check) => self::Approved,
                default => self::NotApproved,
            };
        }

        return $review;
    }
}
