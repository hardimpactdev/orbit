<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Actions\TaskVms\StartTaskVmSlotReleaseAction;
use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlotReleaseIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/** Records independent host Operations. Swamp continues other hosts when one host fails. */
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

    public function handle(TaskVmSettings $settings, TaskVmCapacity $capacity, StartTaskVmSweepOperationAction $operations, TaskVmSlotReleaseIntent $intent): void
    {
        foreach (TaskVm::query()->whereNotNull('slot')->whereNotNull('group_id')->get() as $slot) {
            if (! $intent->sweepable($slot)) {
                continue;
            }
            try {
                app(StartTaskVmSlotReleaseAction::class)->execute($slot->id);
            } catch (ResourceOperationException $refused) {
                // Admission refused this slot; the next sweep asks again.
                Log::warning('A task VM slot was not released.', ['task_vm_id' => $slot->id, 'error_code' => $refused->errorCode, 'details' => $refused->details]);
            }
        }
        foreach (TaskVm::query()->whereNull('slot')->whereNull('group_id')->where('state', TaskVmState::Destroying)->get() as $retired) {
            try {
                app(StartTaskVmDestroyAction::class)->execute($retired->id);
            } catch (ResourceOperationException $refused) {
                // Admission refused this row; the next sweep asks again. Other rows and the host sweeps still run.
                Log::warning('A destroying task VM was not readmitted.', ['task_vm_id' => $retired->id, 'error_code' => $refused->errorCode, 'details' => $refused->details]);
            }
        }
        foreach ($settings->enabled ? $capacity->activeHosts() : [] as [$host,$node]) {
            $operations->execute($node->id, 'incus');
        }
        foreach ($settings->enabled ? TaskVmHostAudit::strayNodes() : [] as $stray) {
            Log::warning('A task VM Node has no live task VM record.', ['node_id' => $stray->id, 'name' => $stray->name]);
        }
        if ($settings->upcloud !== null) {
            $gateway = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
            $operations->execute($gateway->id, 'upcloud');
        }
    }
}
