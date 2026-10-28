<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\TaskVms\ParkTaskVm;
use App\Models\Task;
use App\Models\TaskVm;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Parks the ready VM, or retries an errored parked VM, of each group that has nothing to do: it waits for review with a pull request,
 * has no open subtask, is not a preview, has no failed workspace removal or merge cleanup, and it and its
 * subtasks settled longer ago than `task_vms.park_after_minutes`. The VM must also have been `ready` that
 * long, by its `ready_at`, which the first preparation and every resume set. So a VM that Orbit started again for
 * work, such as a removal retry, is never stopped and started on every tick, and another write to the row, such as
 * a cleared error, does not reset the clock. The row becomes `parked` under the group's admission lock, so no work is admitted while the
 * VM stops, and `ParkTaskVm` stops it.
 *
 * It reads `TaskVmSettings` only when a retryable task VM qualifies, so without task VMs it reads nothing.
 */
final readonly class ParkIdleTaskVmsAction
{
    /** @var list<TaskStatus> */
    private const array OpenSubtasks = [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing];

    public function __construct(private TaskExecutionLock $execution) {}

    public function execute(): int
    {
        $retryable = static fn (Builder $query): Builder => $query->where('state', TaskVmState::Ready)
            ->orWhere(static fn (Builder $parked): Builder => $parked->where('state', TaskVmState::Parked)->whereNotNull('error_code'));
        $candidates = TaskVm::query()->where($retryable)
            ->whereHas('group', static fn (Builder $group): Builder => self::idle($group))
            ->with('group')->get();
        if ($candidates->isEmpty()) {
            return 0;
        }

        $cutoff = now()->subMinutes(app(TaskVmSettings::class)->parkAfterMinutes);
        $parked = 0;
        foreach ($candidates as $vm) {
            $owner = $vm->group;
            $settled = $owner instanceof Task ? self::settledAt($owner) : null;
            if (! $owner instanceof Task || $settled === null || $settled->greaterThan($cutoff) || $vm->ready_at === null || $vm->ready_at->greaterThan($cutoff)) {
                continue;
            }
            $parked += $this->execution->synchronized($owner->id, static function () use ($vm, $cutoff, $retryable): int {
                $group = self::idle(Task::topLevel()->whereKey($vm->group_id))->first();
                $settled = $group instanceof Task ? self::settledAt($group) : null;
                if (! $group instanceof Task || TaskExecutionHold::active($group) || $settled === null || $settled->greaterThan($cutoff)
                    || TaskVm::query()->whereKey($vm->id)->where($retryable)->where('ready_at', '<=', $cutoff)
                        // The nested topology's guests stop with the VM, so the next subtask with one prepares it again.
                        ->update(['state' => TaskVmState::Parked, ...TaskVm::ForgetTopology]) !== 1) {
                    return 0;
                }
                ParkTaskVm::dispatch($vm->id);

                return 1;
            });
        }

        return $parked;
    }

    /**
     * @param  Builder<Task>  $groups
     * @return Builder<Task>
     */
    private static function idle(Builder $groups): Builder
    {
        return $groups
            ->whereNotExists(static fn (QueryBuilder $operations): QueryBuilder => $operations->selectRaw('1')->from('operations')
                ->where('target_type', OperationKinds::TaskGroupTarget)->whereColumn('target_id', 'tasks.id')
                ->whereNotIn('status', array_map(static fn (OperationStatus $status): string => $status->value,
                    array_filter(OperationStatus::cases(), static fn (OperationStatus $status): bool => $status->terminal()))))
            ->where('status', TaskGroupStatus::WaitingForReview)
            ->whereNotNull('pr_url')->where('pr_url', '!=', '')
            ->whereNull('watched_pr_completion')
            // Removal and merge cleanup retry on the tick and need the VM; DestroyTaskVm would wake it again.
            ->whereNot(static fn (Builder $request): Builder => $request->removalFailure())
            ->where(static fn (Builder $preview): Builder => $preview->whereNull('preview')->orWhere('preview', false))
            ->whereDoesntHave('tasks', static fn (Builder $tasks): Builder => $tasks->whereIn('status', self::OpenSubtasks));
    }

    /** The latest settle time of the group and its subtasks: when it last stopped working. */
    private static function settledAt(Task $group): ?CarbonInterface
    {
        $latest = $group->settled_at;
        foreach ($group->tasks()->pluck('settled_at') as $settled) {
            if ($settled instanceof CarbonInterface && ($latest === null || $settled->greaterThan($latest))) {
                $latest = $settled;
            }
        }

        return $latest;
    }
}
