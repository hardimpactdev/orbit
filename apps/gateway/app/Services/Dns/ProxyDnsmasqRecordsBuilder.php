<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

final readonly class ProxyDnsmasqRecordsBuilder
{
    public function __construct(
        private S3BackendDnsRecords $s3BackendDnsRecords = new S3BackendDnsRecords,
    ) {}

    /**
     * @param  Enumerable<int, Node>|iterable<int, Node>  $nodes
     * @param  Enumerable<int, ProxyRoute>|iterable<int, ProxyRoute>  $serviceRoutes
     */
    public function build(iterable $nodes, iterable $serviceRoutes): string
    {
        /** @var Collection<int, Node> $allNodes */
        $allNodes = Collection::make($nodes)->values();

        /** @var Collection<int, ProxyRoute> $routes */
        $routes = Collection::make($serviceRoutes)
            ->filter(fn (ProxyRoute $route): bool => $this->isResolvableServiceRoute($route, $allNodes))
            ->sortBy(static fn (ProxyRoute $route): string => $route->domain)
            ->values();

        /** @var Collection<int, Node> $orbitRouters */
        $orbitRouters = $routes
            ->map(fn (ProxyRoute $route): ?Node => $this->routeNode($route, $allNodes))
            ->filter(static fn (?Node $node): bool => $node instanceof Node)
            ->unique(static fn (Node $node): string => (string) $node->wireguard_address)
            ->sortBy(static fn (Node $node): string => (string) $node->wireguard_address)
            ->values();

        return implode("\n", [
            '# orbit-managed=proxy-dns-records',
            ...$this->s3BackendDnsRecords->build($routes, $allNodes)->all(),
            ...$orbitRouters->flatMap($this->routerDirectives(...))->all(),
            '',
        ]);
    }

    public function buildGatewayState(): string
    {
        return $this->build(
            Node::query()->with('roleAssignments')->get(),
            ProxyRoute::query()->with('node')->get(),
        );
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function isResolvableServiceRoute(ProxyRoute $route, Collection $nodes): bool
    {
        return ! in_array(
            false,
            [
                $route->owner_type === 'router',
                str_ends_with($route->domain, '.orbit'),
                $this->routeNode($route, $nodes) instanceof Node,
            ],
            strict: true,
        );
    }

    /**
     * @param  Collection<int, Node>  $nodes
     */
    private function routeNode(ProxyRoute $route, Collection $nodes): ?Node
    {
        $node = $route->relationLoaded('node') ? $route->node : null;

        if (! $node instanceof Node) {
            $node = $nodes->first(static fn (Node $candidate): bool => $candidate->id === $route->node_id);
        }

        if (! $node instanceof Node || ! $this->isAddressable($node)) {
            return null;
        }

        return $node;
    }

    private function isAddressable(Node $node): bool
    {
        return $node->isActive() && filled($node->wireguard_address);
    }

    /** @return list<string> */
    private function routerDirectives(Node $router): array
    {
        $address = (string) $router->wireguard_address;

        return [
            "address=/orbit/{$address}",
            'local=/orbit/',
        ];
    }
}
