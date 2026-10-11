<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a group's task VM on the active host with the most free slots, then queues its creation. The
 * row is named `tvm-<row id>`, so a retry after a destroyed VM never reuses a name, and it holds its
 * reserved WireGuard address and Pi token from the start.
 *
 * One placement runs at a time under a cache lock, so two claims never both take a host's last slot.
 */
final readonly class AllocateTaskVmAction
{
    public const string NoCapacity = 'Task VM: no capacity.';

    private const string PlacementLock = 'task-vms:placement';

    public function __construct(
        private TaskVmSettings $settings,
        private WireGuardAddressAllocator $addresses,
    ) {}

    /** @throws TaskCapacityException when no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        try {
            $vm = Cache::lock(self::PlacementLock, 30)->block(15, fn (): TaskVm => $this->place($group));
        } catch (LockTimeoutException) {
            throw new TaskCapacityException(false, 'Task VM: another placement is running.');
        }
        assert($vm instanceof TaskVm);

        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    private function place(Task $group): TaskVm
    {
        return DB::transaction(function () use ($group): TaskVm {
            $existing = TaskVm::query()->live()->where('group_id', $group->id)->first();
            if ($existing instanceof TaskVm) {
                return $existing;
            }

            $vm = TaskVm::query()->create([
                'group_id' => $group->id,
                'host_node_id' => $this->host(),
                'provider' => 'incus',
                'name' => 'tvm-pending-'.Str::uuid(),
                'state' => TaskVmState::Provisioning,
                'wireguard_ip' => $this->addresses->nextIn(
                    $this->settings->wireguardRange,
                    array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all()),
                ),
                'pi_token' => bin2hex(random_bytes(32)),
            ]);
            $vm->update(['name' => 'tvm-'.$vm->id]);

            return $vm;
        });
    }

    /**
     * The active host with the most free slots. Every live task VM takes a slot, parked and failed ones too.
     * Hosts with as many free slots keep their config order.
     */
    private function host(): int
    {
        $active = Node::query()->whereKey(array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))
            ->where('status', LifecycleStatus::Active)->pluck('id')->all();
        $used = TaskVm::query()->live()->get(['host_node_id'])->countBy('host_node_id')->all();
        $best = null;
        $bestFree = 0;
        foreach ($this->settings->hosts as $host) {
            $free = $host->maxVms - ($used[$host->nodeId] ?? 0);
            if (in_array($host->nodeId, $active, true) && $free > $bestFree) {
                [$best, $bestFree] = [$host->nodeId, $free];
            }
        }

        return $best ?? throw new TaskCapacityException(false, self::NoCapacity);
    }
}
