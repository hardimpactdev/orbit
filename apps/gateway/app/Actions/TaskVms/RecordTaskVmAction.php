<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmPlacementTarget;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/**
 * Records a new `provisioning` row on its placement: a group's VM, or a pool VM when the group is null. The row is
 * named `tvm-<row id>`, so a retry after a destroyed VM never reuses a name, and it holds the next free WireGuard
 * address of the reserved range from the start. A pool row gets no Pi token; a claim gives it one. Call it under the
 * placement transaction, which serializes writers.
 */
final readonly class RecordTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private WireGuardAddressAllocator $addresses,
    ) {}

    public function execute(?Task $group, TaskVmPlacementTarget $target, TaskVmSize $size = TaskVmSize::Normal): TaskVm
    {
        $vm = TaskVm::query()->create([
            'group_id' => $group?->id,
            'host_node_id' => $target->hostNodeId,
            'provider' => $target->provider,
            'size' => $size,
            'name' => 'tvm-pending-'.Str::uuid(),
            'state' => TaskVmState::Provisioning,
            'wireguard_ip' => $this->addresses->nextIn(
                $this->settings->wireguardRange,
                array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all()),
            ),
            'pi_token' => $group instanceof Task ? self::piToken() : null,
            'claimed_at' => $group instanceof Task ? now() : null,
        ]);
        $vm->update(['name' => 'tvm-'.$vm->id]);

        return $vm;
    }

    /** A new random Pi token, for a new group VM. */
    public static function piToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
