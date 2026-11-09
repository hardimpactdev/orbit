<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A task VM holds its place only while its group works (docs/reference/tasks.md#release-an-idle-task-vm): a group that has run no
 * Operation for {@see self::IdleMinutes} loses its VM through the release that publication uses, once origin has its approved work.
 * The group stays open; its next turn, build, fix or review round claims a fresh place from the published branch.
 */
final readonly class TaskVmIdleRelease
{
    public const int IdleMinutes = 30;

    public function __construct(private TaskFinalReview $review) {}

    /** @return list<int> the task VMs this pass released */
    public function release(): array
    {
        $vms = TaskVm::query()->where('capacity_pool', 'task')->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])->whereNotNull('group_id')->get();
        $activity = TaskGroupActivity::of(array_values(array_unique(array_map(static fn (TaskVm $vm): int => ValidatedData::integer($vm->group_id), $vms->all()))));
        $released = [];
        foreach ($vms as $vm) {
            $group = Task::topLevel()->find($vm->group_id);
            if (! $group instanceof Task || ! self::idle($activity[$group->id]) || ! $group->groupStatus()->isActive()) {
                continue;
            }
            try {
                // GitHub is read outside the transaction, as complete and cancel read it before they admit the release.
                $this->review->assertOnOrigin($group);
            } catch (ResourceOperationException $refused) {
                Log::info('An idle task VM keeps its place until its approved work is on origin.', ['task_vm_id' => $vm->id, 'group_id' => $group->id, 'error_code' => $refused->errorCode]);

                continue;
            }
            if ($this->releaseIfIdle($vm->id, $group->id)) {
                $released[] = $vm->id;
            }
        }

        return $released;
    }

    /** Locks the group and the VM and asks again, so an Operation admitted since the selection keeps the VM. */
    private function releaseIfIdle(int $vmId, int $groupId): bool
    {
        return DB::transaction(static function () use ($vmId, $groupId): bool {
            $group = Task::topLevel()->lockForUpdate()->find($groupId);
            $vm = TaskVm::query()->lockForUpdate()->find($vmId);
            if (! $group instanceof Task || ! $vm instanceof TaskVm || $vm->group_id !== $group->id
                || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true) || ! self::idle(TaskGroupActivity::ofGroup($group->id))) {
                return false;
            }
            TaskVmReentry::free($vm, $group);

            return true;
        });
    }

    /** @param array{last_activity_at: string|null, active_operation_id: int|null} $activity */
    private static function idle(array $activity): bool
    {
        return $activity['active_operation_id'] === null && $activity['last_activity_at'] !== null
            && CarbonImmutable::parse($activity['last_activity_at'])->lte(now()->subMinutes(self::IdleMinutes));
    }
}
