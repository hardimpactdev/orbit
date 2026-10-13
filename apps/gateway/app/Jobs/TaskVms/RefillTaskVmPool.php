<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\RefillTaskVmPoolAction;
use App\Domain\TaskVms\TaskVmQueue;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Keeps every task VM host's pool of warm VMs at its `warm_vms`. The scheduler queues it every minute while a host
 * has `warm_vms` or a pool VM exists, a claim queues it after it takes a warm VM, and a VM that becomes warm queues
 * it so its image is read. A queued copy absorbs the others.
 */
final class RefillTaskVmPool implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onConnection('task-vms')->onQueue(TaskVmQueue::Vms->value);
    }

    /**
     * Whether the scheduler queues a refill: task VMs are enabled and a host sets `warm_vms`, or a pool VM is not
     * destroyed yet, which the refill drains. It reads the raw config, so an invalid value never stops the scheduler.
     */
    public static function due(): bool
    {
        $hosts = config('task_vms.incus.hosts');
        $warm = config('task_vms.enabled') === true && is_array($hosts)
            && array_any($hosts, static fn (mixed $host): bool => is_array($host) && is_int($host['warm_vms'] ?? null) && $host['warm_vms'] > 0);

        return $warm || TaskVm::query()->live()->pool()->exists();
    }

    public function handle(RefillTaskVmPoolAction $refill): void
    {
        $refill->execute();
    }
}
