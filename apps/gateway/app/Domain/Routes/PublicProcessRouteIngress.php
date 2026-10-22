<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Route;

final readonly class PublicProcessRouteIngress
{
    public function resolve(Process $process, ?int $ingressNodeId = null): Node
    {
        $owner = $process->owner;
        $eligibility = new PublicRouteEligibility;
        if ($process->owner_type !== Node::class || ! $owner instanceof Node) {
            throw new ResourceOperationException('route.upstream_unresolved', 'A public custom proxy requires a Node-owned Process.', 409);
        }

        if ($ingressNodeId === null && $owner->cluster_id !== null) {
            $ingressNodeId = $eligibility->activeIngress(Cluster::query()->findOrFail($owner->cluster_id))?->id;
            if ($ingressNodeId === null) {
                throw $this->missing();
            }
        }

        if ($ingressNodeId === null) {
            $clusterIds = Route::query()->with(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
                ->where('kind', RouteKind::App)->where('publication', RoutePublication::Public)->where('status', RouteStatus::Active)
                ->get()->filter(static fn (Route $route): bool => $eligibility->publicEdgeIsLive($route))->pluck('cluster_id')->unique();
            $candidates = NodeRole::query()->whereIn('cluster_id', $clusterIds)
                ->where('role', RoleName::Ingress)->where('status', LifecycleStatus::Active)
                ->whereHas('node', fn ($query) => $query->where('status', LifecycleStatus::Active))
                ->pluck('node_id')->unique();
            if ($candidates->count() > 1) {
                throw new ResourceOperationException('route.ingress_ambiguous', 'More than one Ingress serves public app Routes. Select one with --ingress.', 409);
            }
            $ingressNodeId = $candidates->first();
        }

        $node = $ingressNodeId !== null ? Node::query()->find($ingressNodeId) : null;
        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active
            || ! $node->roles()->where('role', RoleName::Ingress)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->missing();
        }

        return $node;
    }

    private function missing(): ResourceOperationException
    {
        return new ResourceOperationException('route.ingress_required', 'No active public Ingress is available. Select an active Ingress Node with --ingress.', 409);
    }
}
