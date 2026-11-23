<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
     * The review of each group, by id, with the newest reviewer turn and newest handoff check of the whole page read once, and only the fields the review reads. `reviewing` while the newest
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
        $turns = self::newestReviewerTurns($reviewers);
        $completed = array_values(array_map(static fn (Task $work): int => $work->id, array_filter($works, static fn (Task $work): bool => $work->status === TaskStatus::Completed)));
        $newest = $completed === [] ? [] : TaskCheck::query()->whereIn('task_id', $completed)->where('kind', TaskCheckKind::Handoff->value)
            ->groupBy('task_id')->selectRaw('max(id) as id')->pluck('id')->all();
        $checks = $newest === [] ? new Collection : TaskCheck::query()->whereIn('id', $newest)->get(['id', 'task_id', 'status', 'commit_sha'])->keyBy('task_id');
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

    /**
     * The newest turn of each group's reviewer thread, by group id, holding only what the review reads: its status, the subtask and
     * candidate of its input, and the state and end of its result. A turn's prompt and other fields stay unread.
     *
     * @param  array<int, int>  $reviewers  the reviewer thread of each group, by group id
     * @return array<int, Operation>
     */
    private static function newestReviewerTurns(array $reviewers): array
    {
        if ($reviewers === []) {
            return [];
        }
        $newest = Operation::query()->where('kind', OperationKinds::TasksCopyTurn)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where(static function ($query) use ($reviewers): void {
                foreach ($reviewers as $group => $thread) {
                    $query->orWhere(static fn ($turn) => $turn->where('target_id', $group)->where('input->thread_id', $thread));
                }
            })->groupBy('target_id')->selectRaw('max(id) as id')->pluck('id')->all();
        $turns = [];
        foreach (DB::table('operations')->whereIn('id', $newest)->get(['id', 'target_id', 'status', DB::raw("json_extract(input, '$.subtask') as subtask"),
            DB::raw("json_extract(input, '$.candidate') as candidate"), DB::raw("json_extract(result, '$.state') as state"),
            DB::raw("json_type(result, '$.ended') as ended")]) as $row) {
            $turns[(int) $row->target_id] = (new Operation)->forceFill([
                'status' => $row->status,
                'input' => ['subtask' => is_numeric($row->subtask) ? (int) $row->subtask : $row->subtask, 'candidate' => $row->candidate],
                'result' => ['state' => $row->state, 'ended' => match ($row->ended) {
                    'true' => true, 'false' => false, default => null
                }],
            ]);
        }

        return $turns;
    }
}
