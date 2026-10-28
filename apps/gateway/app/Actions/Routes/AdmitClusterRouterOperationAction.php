<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Router validation and intent only. Swamp prepares the replacement and performs cutover. */
final readonly class AdmitClusterRouterOperationAction
{
    public function __construct(private StartOperationAction $operations, private RouteReconciliationGuard $routes, private RouteRemovalGuard $removal) {}

    public function set(Cluster $cluster, Node $node, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($cluster, $node, $requestId): Operation {
            $cluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->cluster_id !== $cluster->id || $node->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('cluster.router_node_invalid', 'A Cluster Router must be an active member Node.', 409);
            }
            $assignments = NodeRole::query()->where('cluster_id', $cluster->id)->where('role', RoleName::Router)->lockForUpdate()->get();
            foreach ($assignments as $assignment) {
                if ($assignment->node_id !== $node->id && in_array($assignment->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed, LifecycleStatus::Active], true)
                    && ClusterRouterReplacementStep::isReplacementProgress($assignment->failed_step)) {
                    throw new ResourceOperationException('cluster.router_transition_conflict', 'Another Cluster Router replacement is in progress.', 409);
                }
            }
            $current = $assignments->first(static fn (NodeRole $row): bool => $row->status === LifecycleStatus::Active);
            $candidate = $assignments->first(static fn (NodeRole $row): bool => $row->node_id === $node->id);
            if ($candidate === null) {
                $candidate = NodeRole::query()->create(['node_id' => $node->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Provisioning]);
            }
            $routeIds = Route::query()->where('cluster_id', $cluster->id)->orderBy('id')->pluck('id')->map(StoredInteger::from(...))->values()->all();
            $workloadNodes = Node::query()->whereHas('instances.routeTargets', static fn (Builder $q): Builder => $q->whereIn('route_id', $routeIds))->pluck('id')->map(StoredInteger::from(...))->all();
            $nodeIds = array_values(array_unique([$node->id, ...$assignments->map(static fn (NodeRole $row): int => $row->node_id)->all(), ...$workloadNodes, ...$this->dnsNodeIds()]));

            return $this->operations->execute('cluster.router_set', 'cluster_router', $cluster->id, $node->id,
                ['cluster_id' => $cluster->id, 'node_id' => $node->id, 'candidate_role_id' => $candidate->id, 'old_role_id' => $current?->id,
                    'old_node_id' => $current?->node_id, 'route_ids' => $routeIds, 'node_ids' => $nodeIds], 'operator', $requestId);
        });
    }

    public function unset(Cluster $cluster, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($cluster, $requestId): Operation {
            $cluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
            $this->routes->assertClusterRouterMutable($cluster->id);
            $this->removal->assertRouterRemovable($cluster);
            if ($cluster->state === ClusterState::Active && $cluster->tld !== null) {
                throw new ResourceOperationException('cluster.active_router_required', 'Deactivate the TLD-bearing Cluster or remove its TLD before clearing its Router.', 409);
            }
            $assignments = NodeRole::query()->where('cluster_id', $cluster->id)->where('role', RoleName::Router)->lockForUpdate()->orderBy('id')->get();
            $nodeIds = array_values(array_unique([...$assignments->map(static fn (NodeRole $row): int => $row->node_id)->all(), ...$this->dnsNodeIds()]));
            $nodeId = $nodeIds[0] ?? $cluster->nodes()->orderBy('id')->value('id');
            if (! is_int($nodeId)) {
                throw new ResourceOperationException('cluster.node_required', 'The Cluster requires a stored Node for this Operation.', 409);
            }

            return $this->operations->execute('cluster.router_unset', 'cluster_router', $cluster->id, $nodeId,
                ['cluster_id' => $cluster->id, 'assignment_ids' => $assignments->modelKeys(), 'node_ids' => $nodeIds], 'operator', $requestId);
        });
    }

    /** @return list<int> */
    private function dnsNodeIds(): array
    {
        return Node::query()->whereHas('roles', static fn (Builder $query): Builder => $query->whereIn('role', [RoleName::Vpn, RoleName::Gateway]))->pluck('id')->map(StoredInteger::from(...))->values()->all();
    }
}
