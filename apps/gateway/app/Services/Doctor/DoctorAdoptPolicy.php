<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Firewall\FirewallTargetPlatform;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteProbe;

final readonly class DoctorAdoptPolicy
{
    public function __construct(
        private ProxyRouteProbe $proxyRouteProbe,
        private DoctorProxyRouteInventory $proxyRouteInventory,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function proxySnapshot(Node $node, DoctorTargetScope $scope): ?ProbeSnapshot
    {
        if (! $node->isActive() || ! $this->canServeGatewayOrAppHost($node)) {
            return null;
        }

        $snapshot = $this->proxyRouteProbe->snapshotForAdopt($node);
        $excludedDomains = $this->proxyRouteInventory->excludedWorkspaceDomains($node);

        if ($excludedDomains !== []) {
            $snapshot = new ProbeSnapshot(array_diff_key(
                $snapshot->items,
                array_fill_keys($excludedDomains, value: true),
            ));
        }

        if ($scope->app === null && $scope->workspace === null) {
            return $snapshot;
        }

        $domains = $this->proxyRouteInventory
            ->forScope($node, $scope)
            ->map(static fn (ProxyRoute $route): string => $route->domain)
            ->all();

        return new ProbeSnapshot(array_intersect_key(
            $snapshot->items,
            array_fill_keys($domains, value: true),
        ));
    }

    public function canAdoptFirewallRules(Node $node): bool
    {
        return (
            $node->isActive()
            && FirewallTargetPlatform::isUbuntu($node->platform)
            && $this->canServeGatewayOrAppHost($node)
        );
    }

    private function canServeGatewayOrAppHost(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }
}
