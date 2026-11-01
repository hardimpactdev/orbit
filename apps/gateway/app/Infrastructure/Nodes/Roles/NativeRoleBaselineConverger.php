<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use LogicException;

final readonly class NativeRoleBaselineConverger implements RoleBaselineConverger
{
    public function __construct(
        private GatewayRoleBaseline $gateway,
        private VpnRoleBaseline $vpn,
        private DevelopmentRoleBaseline $appDev,
        private ProductionRoleBaseline $appProd,
        private NodeRoleOperatingSystemGuard $operatingSystem,
        private ?RouterRoleBaseline $router = null,
        private ?ClusterRouterOperationLock $clusterRouterOperations = null,
        private ?DatabaseRoleBaseline $database = null,
        private ?WebSocketRoleBaseline $websocket = null,
        private ?IngressRoleBaseline $ingress = null,
        private ?NodeRoleConvergeLock $nodeLock = null,
        private ?CiRunnerRoleBaseline $ciRunner = null,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->convergeOwned($node, $assignment),
            );

            return;
        }

        $this->convergeOwned($node, $assignment);
    }

    private function convergeOwned(Node $node, NodeRole $assignment): void
    {
        $this->nodeLock()->run($node, function () use ($node, $assignment): void {
            $this->operatingSystem->assert($node, $assignment->role);
            $this->baseline($assignment->role)->converge($node, $assignment);
        });

    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->removeOwned($node, $assignment, $purgeData),
            );

            return;
        }

        $this->removeOwned($node, $assignment, $purgeData);
    }

    private function removeOwned(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->nodeLock()->run(
            $node,
            fn () => $this->baseline($assignment->role)->remove($node, $assignment, $purgeData),
            'node_role.remove_failed',
        );

    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        if ($assignment->role === RoleName::Router) {
            $this->routerOperations()->run(
                $this->clusterId($assignment),
                fn () => $this->removeUnreachableOwned($node, $assignment),
            );

            return;
        }

        $this->removeUnreachableOwned($node, $assignment);
    }

    private function removeUnreachableOwned(Node $node, NodeRole $assignment): void
    {
        $this->baseline($assignment->role)->removeUnreachable($node, $assignment);

    }

    private function baseline(RoleName $role): RoleBaseline
    {
        return match ($role) {
            RoleName::Gateway => $this->gateway,
            RoleName::Vpn => $this->vpn,
            RoleName::AppDev => $this->appDev,
            RoleName::AppProd => $this->appProd,
            RoleName::Metrics => throw new ResourceOperationException('operation.kind_unavailable', 'Metrics requires its native parent workflow.', 503),
            RoleName::WebSocket => $this->websocket ?? app(WebSocketRoleBaseline::class),
            RoleName::Analytics => throw new ResourceOperationException('operation.kind_unavailable', 'Analytics requires its native parent workflow.', 503),
            RoleName::Swamp => throw new ResourceOperationException('operation.kind_unavailable', 'Swamp requires its native parent workflow.', 503),
            RoleName::Router => $this->router ?? app(RouterRoleBaseline::class),
            RoleName::Database => $this->database ?? app(DatabaseRoleBaseline::class),
            RoleName::CiRunner => $this->ciRunner ?? app(CiRunnerRoleBaseline::class),
            RoleName::Ingress => $this->ingress ?? app(IngressRoleBaseline::class),
        };
    }

    private function clusterId(NodeRole $assignment): int
    {
        if ($assignment->cluster_id === null) {
            throw new LogicException('A Router role assignment must belong to a Cluster.');
        }

        return $assignment->cluster_id;
    }

    private function nodeLock(): NodeRoleConvergeLock
    {
        return $this->nodeLock ?? app(NodeRoleConvergeLock::class);
    }

    private function routerOperations(): ClusterRouterOperationLock
    {
        return $this->clusterRouterOperations ?? app(ClusterRouterOperationLock::class);
    }
}
