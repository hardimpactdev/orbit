<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\RouteMutationReconciler;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;

/** Captures Route ownership before enrollment changes the domain's generation basis. */
final readonly class NodeEnrollmentRoutes
{
    public function __construct(private RouteMutationReconciler $routes) {}

    /** @return list<array{route_id:int,domain:string,node_id:int}> */
    public function capture(Node $node, ?string $tld, ?int $clusterId): array
    {
        if (! $node->exists) {
            return [];
        }
        $changes = $this->routes->generatedPrivateDomainChanges(
            nodeOverrides: [$node->id => ['tld' => $tld, 'cluster_id' => $clusterId]],
            baselineNodeOverrides: [$node->id => ['tld' => $node->tld, 'cluster_id' => $node->cluster_id]],
        );
        $captured = [];
        foreach ($changes as $change) {
            if ($change['route']->generation_basis_node_id !== $node->id) {
                throw new ResourceOperationException('route.generation_basis_invalid', 'Enrollment cannot change a Route owned by another generation basis.', 409);
            }
            $captured[] = ['route_id' => $change['route']->id, 'domain' => $change['domain'], 'node_id' => $this->targetNode($change['route'])];
        }

        return $captured;
    }

    public function targetNode(Route $route): int
    {
        $routerId = $route->cluster?->routerAssignment?->node_id;
        $proxyId = $route->customProxy?->node_id;
        $nodeId = Instance::query()->whereKey($route->targets()->pluck('instance_id'))->orderBy('id')->value('node_id')
            ?? $route->node_id ?? $routerId ?? $proxyId;
        if (! is_int($nodeId)) {
            throw new ResourceOperationException('route.router_required', 'The Route requires a serving Node.', 409);
        }

        return $nodeId;
    }

    /** @param list<array{route_id:int,domain:string,node_id:int}> $changes
     * @return list<array{type:string,key:string,kind:string,node_id:int,input:array{route_id:int,domain:string},optional:bool}>
     */
    public function steps(array $changes): array
    {
        return array_map(static fn (array $change): array => ['type' => 'child', 'key' => 'add-route-'.$change['route_id'],
            'kind' => 'route.update', 'node_id' => $change['node_id'],
            'input' => ['route_id' => $change['route_id'], 'domain' => $change['domain']], 'optional' => false], $changes);
    }
}
