<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases the task VMs that a failed start left on each Incus host, through the release of a task start
 * (docs/cli/tasks/vm-release.mmd), and records the sweep of the UpCloud servers. An Incus host has no sweep of its own.
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
     * as a refused start (docs/cli/tasks/vm-release.mmd). One that a task start or a refresh proof still uses is left alone.
     */
    private function releaseStale(int $hostNodeId): void
    {
        if (TaskVmImageRefreshIntent::open($hostNodeId) instanceof Operation) {
            return;
        }
        $stale = TaskVm::query()->where('host_node_id', $hostNodeId)->whereNotNull('slot_number')->whereNull('group_id')
            ->whereIn('state', [TaskVmState::Failed, TaskVmState::Provisioning, TaskVmState::Releasing])->get()
            ->reject(static fn (TaskVm $vm): bool => Operation::query()->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists());
        foreach ($stale as $vm) {
            try {
                app(TaskVmSlotRuns::class)->startRelease($vm, 'task-vm-sweep');
            } catch (ResourceOperationException $refused) {
                Log::warning('A task VM release was not admitted.', ['task_vm_id' => $vm->id, 'error_code' => $refused->errorCode, 'details' => $refused->details]);
            } catch (Throwable $broken) {
                // One row whose intent cannot be built, such as one without its keys, must not stop the sweep of the other rows and hosts.
                report($broken);
            }
        }
    }

    public function handle(TaskVmSettings $settings, TaskVmCapacity $capacity, StartTaskVmSweepOperationAction $operations): void
    {
        foreach ($settings->enabled ? $capacity->activeHosts() : [] as [$host,$node]) {
            $this->releaseStale($node->id);
        }
        foreach ($settings->enabled ? TaskVmHostAudit::strayNodes() : [] as $stray) {
            Log::warning('A task VM Node has no live task VM record.', ['node_id' => $stray->id, 'name' => $stray->name]);
        }
        if ($settings->upcloud !== null) {
            $gateway = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
            $operations->execute($gateway->id);
        }
    }
}
