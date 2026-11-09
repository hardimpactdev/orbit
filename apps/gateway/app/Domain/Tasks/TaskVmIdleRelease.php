<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A task VM holds its place only while its group works (docs/reference/tasks.md#release-an-idle-task-vm): a group that has run no
 * Operation for {@see self::IdleMinutes} saves its workspace to origin with `tasks.idle_save`, and only a passed save admits the release
 * ({@see TaskIdleSaveProjection}). The group stays open; its next turn, build, fix or review round claims a fresh place from the save.
 */
final readonly class TaskVmIdleRelease
{
    public const int IdleMinutes = 30;

    public function __construct(private TaskFinalReview $review, private RemoteBranchHeads $heads, private TaskIdleSave $save) {}

    /** @return list<int> the task VMs whose save this pass admitted */
    public function release(): array
    {
        $vms = TaskVm::query()->where('capacity_pool', 'task')->whereIn('state', [TaskVmState::Ready, TaskVmState::Parked])->whereNotNull('group_id')->get();
        $ids = array_values(array_unique(array_map(static fn (TaskVm $vm): int => ValidatedData::integer($vm->group_id), $vms->all())));
        $activity = TaskGroupActivity::of($ids, [TaskIdleSave::Kind]);
        $groups = Task::topLevel()->with('project')->whereKey($ids)->get()->keyBy('id');
        $saved = [];
        foreach ($vms as $vm) {
            $group = $groups->get($vm->group_id);
            if (! $group instanceof Task || ! $group->groupStatus()->isActive() || ! self::idle($group->id, $activity[$group->id])) {
                continue;
            }
            try {
                // GitHub is read outside the transaction, as complete and cancel read it before they admit the release. The task branch
                // must exist, because the next turn restores it on a fresh VM.
                $head = $this->heads->head($group->project, TaskWorkspaceName::for($group));
                $this->review->assertOnOrigin($group);
                // A parked VM runs no save: its resume is admitted and the next pass saves it once it runs.
                TaskVmAwake::require($group);
                if ($this->saveIfIdle($vm->id, $group->id, $head)) {
                    $saved[] = $vm->id;
                    Log::info('Saving the workspace of an idle task group before its VM is released.', ['task_vm_id' => $vm->id, 'group_id' => $group->id]);
                }
            } catch (ResourceOperationException $kept) {
                Log::info('An idle task VM keeps its place.', ['task_vm_id' => $vm->id, 'group_id' => $group->id, 'error_code' => $kept->errorCode]);
            }
        }

        return $saved;
    }

    /**
     * Locks the group and the VM and asks again, so an Operation admitted on the group since the selection keeps the VM. An open
     * Operation on the VM itself, such as a resume, keeps it too.
     */
    private function saveIfIdle(int $vmId, int $groupId, string $head): bool
    {
        return DB::transaction(function () use ($vmId, $groupId, $head): bool {
            $group = Task::topLevel()->lockForUpdate()->find($groupId);
            $vm = TaskVm::query()->lockForUpdate()->find($vmId);
            if (! $group instanceof Task || ! $vm instanceof TaskVm || $vm->group_id !== $group->id || $vm->state !== TaskVmState::Ready
                || ! self::idle($group->id, TaskGroupActivity::ofGroup($group->id, [TaskIdleSave::Kind]))) {
                return false;
            }
            TaskVmSlotRuns::assertReleasable($vm);
            $this->save->admit($group, $vm, $head);

            return true;
        });
    }

    /**
     * No Operation of any kind is open on the group, and the last one other than an idle save is older than the limit, so a failed
     * save is tried again at the next pass.
     *
     * @param  array{last_activity_at: string|null, active_operation_id: int|null}  $activity
     */
    private static function idle(int $group, array $activity): bool
    {
        return $activity['active_operation_id'] === null && $activity['last_activity_at'] !== null
            && CarbonImmutable::parse($activity['last_activity_at'])->lte(now()->subMinutes(self::IdleMinutes))
            && ! Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }
}
