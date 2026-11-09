<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A task VM holds its place only while its group works (docs/reference/tasks.md#release-an-idle-task-vm): a group that has run no
 * Operation for {@see self::IdleMinutes} loses its VM through the release, once origin has the task branch that later work restores and
 * the group's latest approved commit. The group stays open; its next turn, build, fix or review round claims a fresh place.
 */
final readonly class TaskVmIdleRelease
{
    public const int IdleMinutes = 30;

    public function __construct(private TaskFinalReview $review, private RemoteBranchHeads $heads, private TaskVmSlotRuns $slots) {}

    /** @return list<int> the task VMs whose release this pass admitted */
    public function release(): array
    {
        $vms = TaskVm::query()->where('capacity_pool', 'task')->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])->whereNotNull('group_id')->get();
        $ids = array_values(array_unique(array_map(static fn (TaskVm $vm): int => ValidatedData::integer($vm->group_id), $vms->all())));
        $activity = TaskGroupActivity::of($ids);
        $groups = Task::topLevel()->with('project')->whereKey($ids)->get()->keyBy('id');
        $released = [];
        foreach ($vms as $vm) {
            $group = $groups->get($vm->group_id);
            if (! $group instanceof Task || ! $group->groupStatus()->isActive() || ! self::idle($activity[$group->id])) {
                continue;
            }
            try {
                // GitHub is read outside the transaction, as complete and cancel read it before they admit the release. The task branch
                // must exist, because the next turn restores it on a fresh VM.
                $this->heads->head($group->project, TaskWorkspaceName::for($group));
                $this->review->assertOnOrigin($group);
                if ($this->releaseIfIdle($vm->id, $group->id)) {
                    $released[] = $vm->id;
                    Log::info('Released the task VM of an idle task group.', ['task_vm_id' => $vm->id, 'group_id' => $group->id]);
                }
            } catch (ResourceOperationException $kept) {
                Log::info('An idle task VM keeps its place.', ['task_vm_id' => $vm->id, 'group_id' => $group->id, 'error_code' => $kept->errorCode]);
            }
        }

        return $released;
    }

    /**
     * Locks the group and the VM and asks again, so an Operation admitted on the group since the selection keeps the VM. The release
     * admission refuses while an Operation, such as a resume, is open on the VM itself.
     */
    private function releaseIfIdle(int $vmId, int $groupId): bool
    {
        return DB::transaction(function () use ($vmId, $groupId): bool {
            $group = Task::topLevel()->lockForUpdate()->find($groupId);
            $vm = TaskVm::query()->lockForUpdate()->find($vmId);
            if (! $group instanceof Task || ! $vm instanceof TaskVm || $vm->group_id !== $group->id
                || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true) || ! self::idle(TaskGroupActivity::ofGroup($group->id))) {
                return false;
            }
            $this->slots->startRelease($vm, 'task-vm-idle');

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
