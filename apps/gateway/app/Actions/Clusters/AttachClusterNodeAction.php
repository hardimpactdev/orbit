<?php

declare(strict_types=1);

namespace App\Actions\Clusters;

use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterDnsTransition;
use App\Domain\Firewall\RouterLanIngressReconciler;
use App\Domain\Nodes\NodeLanAddress;
use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AttachClusterNodeAction
{
    public function __construct(
        private ?RouteMutationReconciler $routes = null,
        private ?ConvergeRouteAction $convergeRoute = null,
        private ?RouterLanIngressReconciler $lanIngress = null,
    ) {}

    public function execute(Cluster $cluster, Node $node): Cluster
    {
        $node->refresh();

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                errorCode: 'cluster.node_inactive',
                message: "Node [{$node->name}] must be active before Cluster attachment.",
                status: 409,
            );
        }

        if ($node->cluster_id !== null && $node->cluster_id !== $cluster->id) {
            throw new ResourceOperationException(
                errorCode: 'cluster.membership_conflict',
                message: "Node [{$node->name}] already belongs to another Cluster.",
                status: 409,
            );
        }

        if (is_string($node->lan_ip)) {
            NodeLanAddress::assertFree($node->lan_ip, $cluster->id, $node->id);
        }

        $overrides = [$node->id => ['cluster_id' => $cluster->id]];
        $this->convergeMembership($overrides);

        $this->lanIngress()->expand(
            nodeOverrides: $overrides,
            clusterIds: [$cluster->id],
        );

        try {

            $updated = DB::transaction(function () use ($cluster, $node): Cluster {
                $lockedCluster = Cluster::query()->lockForUpdate()->findOrFail($cluster->id);
                $lockedNode = Node::query()->lockForUpdate()->findOrFail($node->id);

                if ($lockedNode->status !== LifecycleStatus::Active) {
                    throw new ResourceOperationException(
                        errorCode: 'cluster.node_inactive',
                        message: "Node [{$lockedNode->name}] must be active before Cluster attachment.",
                        status: 409,
                    );
                }

                if ($lockedNode->cluster_id !== null && $lockedNode->cluster_id !== $lockedCluster->id) {
                    throw new ResourceOperationException(
                        errorCode: 'cluster.membership_conflict',
                        message: "Node [{$lockedNode->name}] already belongs to another Cluster.",
                        status: 409,
                    );
                }

                try {
                    $this->routeReconciler()->reconcile(nodeOverrides: [
                        $lockedNode->id => ['cluster_id' => $lockedCluster->id],
                    ]);
                    $lockedNode->update(['cluster_id' => $lockedCluster->id]);
                } catch (QueryException $exception) {
                    throw $this->lanIpConflict($lockedNode, $exception);
                }

                return $lockedCluster->refresh();
            });
        } catch (Throwable $exception) {
            $this->lanIngress()->prune(clusterIds: [$cluster->id]);

            throw $exception;
        }

        app(ClusterDnsTransition::class)->begin($node, $cluster->id, $cluster->id);
        app(PrivateDnsManager::class)->converge();
        $this->lanIngress()->prune(clusterIds: [$updated->id]);
        app(ClusterDnsTransition::class)->complete($node);

        return $updated;
    }

    /** @param array<int, array{cluster_id: ?int}> $overrides */
    private function convergeMembership(array $overrides): void
    {
        $changes = $this->routeReconciler()->membershipChanges(nodeOverrides: $overrides);
        $converged = [];

        // Every Route is checked before the first one moves, so a refusal leaves them all in place.
        foreach ($changes as $change) {
            $this->convergeRoute()->assertConvergible($change['route'], $change['domain'], allowGenerated: true);
        }

        foreach ($changes as $change) {
            $converged[] = $this->convergeRoute()->execute(
                $change['route'],
                $change['domain'],
                allowGenerated: true,
                placement: $change['placement'],
                deferPlacementWithdrawal: true,
            );
        }

        // One wait for private DNS answers to expire covers every Route this change moved.
        $this->convergeRoute()->completePlacements($converged);
    }

    private function lanIpConflict(Node $node, ?QueryException $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'cluster.lan_ip_conflict',
            message: "Node [{$node->name}] conflicts with a Cluster LAN address.",
            status: 409,
            previous: $previous,
        );
    }

    private function routeReconciler(): RouteMutationReconciler
    {
        return $this->routes ?? app(RouteMutationReconciler::class);
    }

    private function convergeRoute(): ConvergeRouteAction
    {
        return $this->convergeRoute ?? app(ConvergeRouteAction::class);
    }

    private function lanIngress(): RouterLanIngressReconciler
    {
        return $this->lanIngress ?? app(RouterLanIngressReconciler::class);
    }
}
