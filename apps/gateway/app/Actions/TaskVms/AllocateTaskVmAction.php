<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Gives a group a new task VM of its declared size. Placement is serialized by the database transaction. */
final readonly class AllocateTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private TaskVmPlacementPolicy $policy,
        private RecordTaskVmAction $record,
    ) {}

    /** @throws TaskCapacityException when no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        $existing = TaskVm::forGroup($group);
        if ($existing instanceof TaskVm) {

            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds);
        try {
            $vm = $this->place($group, $size);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    private function place(Task $group, TaskVmSize $size): TaskVm
    {
        return DB::transaction(function () use ($group, $size): TaskVm {
            $existing = TaskVm::forGroup($group);
            if ($existing instanceof TaskVm) {
                return $existing;
            }
            $free = $this->capacity->freeSlots($this->capacity->activeHosts());

            return $this->record->execute($group, $this->policy->choose($size, $group, $free), $size);
        });
    }
}
