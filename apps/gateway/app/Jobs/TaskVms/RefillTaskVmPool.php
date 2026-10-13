<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\RefillTaskVmPoolAction;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Keeps every task VM host's pool of warm VMs at its `warm_vms`. The scheduler queues it every minute while a host
 * has `warm_vms` or a pool VM exists, a claim queues it after it takes a warm VM, and a VM that becomes warm queues
 * it so its image is read. A queued copy absorbs the others.
 */
final class RefillTaskVmPool implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onTaskVmQueue();
    }

    /**
     * Whether the scheduler queues a refill: task VMs are enabled and a host sets `warm_vms`, or a pool VM is not
     * destroyed yet, which the refill drains. It reads the config leniently, so an invalid value never stops the
     * scheduler.
     */
    public static function due(): bool
    {
        return TaskVmSettings::scheduled()->warmVmsWanted || TaskVm::query()->live()->pool()->exists();
    }

    public function handle(RefillTaskVmPoolAction $refill): void
    {
        $refill->execute();
    }
}
