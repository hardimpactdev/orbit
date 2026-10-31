<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

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
        $metricsId = NodeRole::query()->where('role', RoleName::Metrics)->value('node_id');
        $gatewayId = NodeRole::query()->where('role', RoleName::Gateway)->value('node_id');
        $participants = app(NodeRemovalParticipants::class)->capture($node);

        return ['schema_version' => 1, 'node_id' => $node->id, 'name' => $node->name, 'prior_status' => $node->status->value,
            'task_vm_id' => $vm?->id, 'machine_deleted' => $machineDeleted, 'offline' => $offline,
            'hub_id' => $node->wireguard_public_key === null ? null : $hubId,
            'roles' => $node->roles()->orderBy('id')->get()->map(static fn (NodeRole $role): array => ['id' => $role->id, 'role' => $role->role->value])->all(),
            'participants' => $participants, 'metrics' => $metricsId === null ? null : ['node_id' => $metricsId, 'gateway_id' => $gatewayId],
            'route_ids' => Route::query()->where(static fn ($query) => $query->where('node_id', $node->id)->orWhere('generation_basis_node_id', $node->id)
                ->orWhere('transition_node_id', $node->id)->orWhereHas('targets.instance', static fn ($q) => $q->where('node_id', $node->id)))->orderBy('id')->pluck('id')->all()];
    }
}
