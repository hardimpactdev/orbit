<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases the task VMs that a failed start left on each Incus host, through the release of a task start
 * (docs/cli/task/vm-release.mmd). An Incus host has no sweep of its own.
 */
final class SweepTaskVmHosts implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onTaskVmQueue();
    }

    /**
     * Releases each task VM of the host that no group holds and whose last release failed or whose Operation ended without its projection, such
     * as a refused start (docs/cli/task/vm-release.mmd); a VM of the old pool, which has no slot number, too. One that a task start or a refresh proof still uses is left alone.
     * Only the slot of an open refresh is that refresh's: a refresh that waits for a CI place holds none, and the failed slots of earlier
     * refreshes on the host are the places it waits for.
     */
    private function releaseStale(int $hostNodeId): void
    {
        $stale = TaskVm::query()->where('host_node_id', $hostNodeId)->whereNull('group_id')
            ->whereIn('state', [TaskVmState::Failed, TaskVmState::Provisioning, TaskVmState::Releasing])->get()
            ->reject(static fn (TaskVm $vm): bool => TaskVmImageRefreshIntent::holds($vm) || Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists());
        foreach ($stale as $vm) {
            try {
                $this->releaseIfStale($vm);
            } catch (ResourceOperationException $refused) {
                Log::warning('A task VM release was not admitted.', ['task_vm_id' => $vm->id, 'error_code' => $refused->errorCode, 'details' => $refused->details]);
            } catch (Throwable $broken) {
                // One row whose intent cannot be built, such as one without its keys, must not stop the sweep of the other rows and hosts.
                report($broken);
            }
        }
    }

    /**
     * Locks the row and asks again, before the release: a refresh makes its group-less VM in the same transaction as its Operation, so a
     * refresh that holds the row now leaves it alone, and so does a row that changed meanwhile.
     */
    private function releaseIfStale(TaskVm $vm): void
    {
        DB::transaction(function () use ($vm): void {
            $vm = TaskVm::query()->lockForUpdate()->find($vm->id);
            if (! $vm instanceof TaskVm || TaskVmImageRefreshIntent::holds($vm) || $vm->group_id !== null
                || ! in_array($vm->state, [TaskVmState::Failed, TaskVmState::Provisioning, TaskVmState::Releasing], true)) {
                return;
            }
            app(TaskVmSlotRuns::class)->startRelease($vm, 'task-vm-sweep');
        });
    }

    public function handle(TaskVmSettings $settings, TaskVmCapacity $capacity): void
    {
        foreach ($settings->enabled ? $capacity->activeHosts() : [] as [, $node]) {
            $this->releaseStale($node->id);
        }
        foreach ($settings->enabled ? TaskVmHostAudit::strayNodes() : [] as $stray) {
            Log::warning('A task VM Node has no live task VM record.', ['node_id' => $stray->id, 'name' => $stray->name]);
        }
    }
}
