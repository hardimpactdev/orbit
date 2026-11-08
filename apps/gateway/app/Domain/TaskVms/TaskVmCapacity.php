<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
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
     * The free slots of each of `$hosts`, in their order: `max_vms` minus the slots of every task VM on the host that
     * is not destroyed or failed, parked ones too. A failed VM holds no slot, so it never blocks placement:
     * its group asks for assistance. The exception is a failed VM that a proof run kept (`--keep-failed`): it holds the CPU and memory of its host, so it counts until
     * it is destroyed. A VM takes the slots of its size
     * ({@see TaskVmSize::slots()}). A host over its budget has 0. Unless `$proof` is true, the free
     * reserve (slot number 1) is withheld and CI-only hosts expose no capacity. An occupied reserve is already counted and is not withheld twice. `task_max_vms` caps task-work claims,
     * including parked ones, by VM count. Authenticated CI and base refresh proofs do not consume that count.
     *
     * @param  list<array{TaskVmHost, Node}>  $hosts  from {@see self::activeHosts()}
     * @return array<int, int> host Node id => free slots
     */
    public function freeSlots(array $hosts, bool $proof = false): array
    {
        $used = [];
        $reserved = [];
        $tasks = [];
        $vms = TaskVm::query()->countedAgainstCap(TaskVmProviderKind::Incus)->get(['host_node_id', 'size', 'slot_number', 'group_id']);
        $ciGroups = Operation::query()->where('kind', 'proof.command')->whereIn('target_id', $vms->pluck('group_id'))
            ->where('input->ci_reserve', true)->pluck('target_id')->flip()->all();
        foreach ($vms as $vm) {
            $host = (int) $vm->host_node_id;
            if ($vm->slot_number === 1) {
                $reserved[$host] = true;
            }
            if ($vm->group_id !== null && ! isset($ciGroups[$vm->group_id])) {
                $tasks[$host] = ($tasks[$host] ?? 0) + 1;
            }
            $used[$host] = ($used[$host] ?? 0) + $vm->size->slots();
        }
        $free = [];
        foreach ($hosts as [$host]) {
            $reserve = $proof || isset($reserved[$host->nodeId]) ? 0 : TaskVmSize::Large->slots();
            $taskFull = ! $proof && ($host->ciOnly || ($host->taskMaxVms !== null && ($tasks[$host->nodeId] ?? 0) >= $host->taskMaxVms));
            $free[$host->nodeId] = $taskFull ? 0 : max(0, $host->maxVms - ($used[$host->nodeId] ?? 0) - $reserve);
        }

        return $free;
    }
}
