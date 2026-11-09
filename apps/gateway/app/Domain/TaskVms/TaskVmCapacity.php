<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\StoredInteger;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The slots of the task VM hosts. Placement transactions serialize writers, so two
 * claims never both take a host's last slot.
 */
final readonly class TaskVmCapacity
{
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
     * Each live row holds exactly one place in its admitted pool of its runtime, including a failed row until deletion
     * is confirmed. Container places and VM places are counted apart.
     *
     * @param  list<array{TaskVmHost, Node}>  $hosts
     * @return array<int, int> host Node id => free places of `$runtime`
     */
    public function freeSlots(array $hosts, bool $proof, TaskVmRuntime $runtime): array
    {
        $used = TaskVm::query()->live()->where('provider', TaskVmProviderKind::Incus)->where('runtime', $runtime)
            ->where('capacity_pool', $proof ? 'ci' : 'task')->selectRaw('host_node_id, count(*) as total')
            ->groupBy('host_node_id')->pluck('total', 'host_node_id');
        $free = [];
        foreach ($hosts as [$host]) {
            $free[$host->nodeId] = max(0, $host->places($runtime, $proof) - StoredInteger::fromOrZero($used[$host->nodeId] ?? 0));
        }

        return $free;
    }
}
