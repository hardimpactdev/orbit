<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;

/**
 * A `router` row that failed Cluster Router Operations left on a Node that is not its Cluster's
 * Router. `node:role:remove` may withdraw it; `cluster:router:set` and `cluster:router:unset` own
 * every other Router change. The caller holds the Cluster Router Operation lock and the row lock.
 */
final readonly class StaleRouterAssignment
{
    public function assertRemovable(NodeRole $assignment): void
    {
        if ($assignment->role !== RoleName::Router) {
            throw new \LogicException('Only a router assignment can be a stale Router assignment.');
        }
        if ($assignment->cluster_id === null) {
            $this->refuse('no_cluster');
        }
        if ($assignment->status === LifecycleStatus::Active) {
            $this->refuse('active_router');
        }
        if ($assignment->status === LifecycleStatus::Removing) {
            $this->refuse('replacement_in_progress');
        }
        $replacing = NodeRole::query()->where('cluster_id', $assignment->cluster_id)->where('role', RoleName::Router)
            ->get()->contains(static fn (NodeRole $row): bool => ClusterRouterReplacementStep::isReplacementProgress($row->failed_step));
        if ($replacing) {
            $this->refuse('replacement_in_progress');
        }
        $open = Operation::query()->whereIn('kind', ['cluster.router_set', 'cluster.router_unset'])
            ->where('target_type', 'cluster_router')->where('target_id', $assignment->cluster_id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
        if ($open) {
            $this->refuse('router_operation_open');
        }
    }

    /** Whether an admitted removal of a stale `router` row of the Cluster still runs; Router set and unset wait for it. */
    public function removalOpen(int $clusterId): bool
    {
        $nodeIds = NodeRole::query()->where('cluster_id', $clusterId)->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Removing)->pluck('node_id')->all();

        return $nodeIds !== [] && Operation::query()->where('kind', 'node.role_remove')->where('target_type', 'node')
            ->whereIn('target_id', $nodeIds)->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    private function refuse(string $reason): never
    {
        throw new ResourceOperationException('node_role.router_protected',
            'This router assignment is its Cluster\'s Router or part of a Router change. Use cluster:router:set or cluster:router:unset.',
            409, details: ['role' => RoleName::Router->value, 'reason' => $reason]);
    }
}
