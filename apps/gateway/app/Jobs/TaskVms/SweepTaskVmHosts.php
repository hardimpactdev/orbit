<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\StartTaskVmLifecycleOperationAction;
use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmSettings;
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

    public function handle(TaskVmSettings $settings, TaskVmCapacity $capacity, StartTaskVmSweepOperationAction $operations): void
    {
        foreach (TaskVm::query()->whereNull('group_id')->where('state', TaskVmState::Destroying)->get() as $retired) {
            app(StartTaskVmLifecycleOperationAction::class)->execute($retired->id, 'destroy');
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
