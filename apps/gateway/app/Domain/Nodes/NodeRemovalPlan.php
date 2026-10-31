<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use App\Models\TaskVm;

/** Immutable public participants. The native parent owns all execution and compensation. */
final readonly class NodeRemovalPlan
{
    /** @return array<string,mixed> */
    public function build(Node $node, bool $offline, bool $machineDeleted = false): array
    {
        $vm = TaskVm::query()->where('node_id', $node->id)->first();
        $hubId = NodeRole::query()->where('role', RoleName::Vpn)->value('node_id');
        $metricsId = $vm === null ? NodeRole::query()->where('role', RoleName::Metrics)->where('node_id', '!=', $node->id)->value('node_id') : null;
        $gatewayId = NodeRole::query()->where('role', RoleName::Gateway)->value('node_id');
        $participants = Node::query()->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get()
            ->filter(static fn (Node $target): bool => $target->platform === 'linux' && $target->ssh_host_fingerprint !== null && $target->wireguard_ip !== null)
            ->map(static fn (Node $target): array => ['node_id' => $target->id,
                'peer' => true, 'residue' => true, 'dns' => PrivateDnsPublicationNodes::query()->whereKey($target->id)->exists()])->values()->all();

        return ['schema_version' => 1, 'node_id' => $node->id, 'name' => $node->name, 'prior_status' => $node->status->value,
            'task_vm_id' => $vm?->id, 'machine_deleted' => $machineDeleted, 'offline' => $offline,
            'hub_id' => $node->wireguard_public_key === null ? null : $hubId,
            'roles' => $node->roles()->orderBy('id')->get()->map(static fn (NodeRole $role): array => ['id' => $role->id, 'role' => $role->role->value])->all(),
            'participants' => $participants, 'metrics' => $metricsId === null ? null : ['node_id' => $metricsId, 'gateway_id' => $gatewayId],
            'route_ids' => Route::query()->where(static fn ($query) => $query->where('node_id', $node->id)->orWhere('generation_basis_node_id', $node->id)
                ->orWhere('transition_node_id', $node->id)->orWhereHas('targets.instance', static fn ($q) => $q->where('node_id', $node->id)))->orderBy('id')->pluck('id')->all()];
    }

    /** The owning TaskVM admission captures this before its native provider-delete phase.
     * @return array<string,mixed>
     */
    public function deletedTaskVm(TaskVm $vm, Node $node): array
    {
        if ($vm->node_id !== $node->id || $vm->name !== $node->name || $vm->wireguard_ip !== $node->wireguard_ip
            || TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists()) {
            throw new ResourceOperationException('node.removal_owner_mismatch', 'Node cleanup must belong to the exact TaskVM.', 422);
        }

        return $this->build($node, true, true);
    }
}
