<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\TaskVm;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The slots of the task VM hosts, and the lock that every placement holds. One placement runs at a time, so two
 * claims never take the same warm VM or both take a host's last slot. The pool refill takes the same lock.
 */
final readonly class TaskVmCapacity
{
    private const string PlacementLock = 'task-vms:placement';

    public function __construct(private TaskVmSettings $settings) {}

    /**
     * The configured hosts whose Node is active, in config order.
     *
     * @return list<array{TaskVmHost, Node}>
     */
    public function activeHosts(): array
    {
        $nodes = Node::query()->whereKey(array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))
            ->where('status', LifecycleStatus::Active)->get()->keyBy('id');
        $hosts = [];
        foreach ($this->settings->hosts as $host) {
            $node = $nodes->get($host->nodeId);
            if ($node instanceof Node) {
                $hosts[] = [$host, $node];
            }
        }

        return $hosts;
    }

    /**
     * The free slots of each of `$hosts`, in their order: `max_vms` minus the slots of every task VM on the host that
     * is not destroyed or failed, warm and parked ones too. A failed VM holds no slot, so it never blocks placement:
     * its group asks for assistance, and the pool refill destroys a failed pool VM. A VM takes the slots of its size
     * ({@see TaskVmSize::slots()}). A host over its budget has 0.
     *
     * @param  list<array{TaskVmHost, Node}>  $hosts  from {@see self::activeHosts()}
     * @return array<int, int> host Node id => free slots
     */
    public function freeSlots(array $hosts): array
    {
        $used = [];
        foreach (TaskVm::query()->countedAgainstCap(TaskVmProviderKind::Incus)->get(['host_node_id', 'size']) as $vm) {
            $host = (int) $vm->host_node_id;
            $used[$host] = ($used[$host] ?? 0) + $vm->size->slots();
        }
        $free = [];
        foreach ($hosts as [$host]) {
            $free[$host->nodeId] = max(0, $host->maxVms - ($used[$host->nodeId] ?? 0));
        }

        return $free;
    }

    /**
     * Runs `$placement` under the placement lock, waiting up to 15 seconds for it.
     *
     * @template T
     *
     * @param  Closure(): T  $placement
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function withPlacementLock(Closure $placement): mixed
    {
        return Cache::lock(self::PlacementLock, 30)->block(15, $placement);
    }
}
