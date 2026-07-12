<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;

final class DnsmasqConfigBuilder
{
    /**
     * @param  Enumerable<int, Node>|iterable<int, Node>  $nodes
     * @param  Enumerable<int, ProxyRoute>|iterable<int, ProxyRoute>  $serviceRoutes
     */
    public function build(iterable $nodes, iterable $serviceRoutes = []): string
    {
        /** @var Collection<int, Node> $allNodes */
        $allNodes = Collection::make($nodes)->values();

        /** @var Collection<int, Node> $resolvable */
        $resolvable = $allNodes
            ->filter(fn (Node $node): bool => $this->isResolvable($node))
            ->sortBy(fn (Node $node): string => $node->tld)
            ->values();

        /** @var Collection<int, ProxyRoute> $routes */
        $routes = Collection::make($serviceRoutes)
            ->filter(fn (ProxyRoute $route): bool => $this->isResolvableServiceRoute($route, $allNodes))
            ->sortBy(fn (ProxyRoute $route): string => $route->domain)
            ->values();

        /** @var Collection<int, Node> $orbitRouters */
        $orbitRouters = $routes
            ->map(fn (ProxyRoute $route): ?Node => $this->routeNode($route, $allNodes))
            ->filter(fn (?Node $node): bool => $node instanceof Node)
            ->unique(fn (Node $node): string => (string) $node->wireguard_address)
            ->sortBy(fn (Node $node): string => (string) $node->wireguard_address)
            ->values();

        $lines = [
            ...$resolvable->flatMap($this->nodeLines(...))->all(),
            ...$orbitRouters->flatMap($this->routerLines(...))->all(),
            'no-resolv',
            'server=1.1.1.1',
            'server=8.8.8.8',
            'conf-dir=/etc/dnsmasq.d/,*.conf',
            'log-queries',
            'log-facility=-',
        ];

        return implode("\n", $lines)."\n";
    }

    public function buildGatewayState(): string
    {
        return $this->build(
            Node::query()->with('roleAssignments')->get(),
            ProxyRoute::query()->with('node')->get(),
        );
    }

    private function isResolvable(Node $node): bool
    {
        return ! in_array(
            false,
            [
                $node->isActive(),
                filled($node->tld),
                filled($node->wireguard_address),
            ],
            strict: true,
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
            $node = $nodes->first(fn (Node $candidate): bool => $candidate->id === $route->node_id);
        }

        if (! $node instanceof Node || ! $this->isAddressable($node)) {
            return null;
        }

        return $node;
    }

    private function isAddressable(Node $node): bool
    {
        return ! in_array(
            false,
            [
                $node->isActive(),
                filled($node->wireguard_address),
            ],
            strict: true,
        );
    }

    private function hasWildcardDnsRole(Node $node): bool
    {
        return array_any(
            [NodeRoleName::AppDevelopment, NodeRoleName::Agent],
            static fn (NodeRoleName $role): bool => $node->hasActiveRole($role->value),
        );
    }

    /** @return list<string> */
    private function nodeLines(Node $node): array
    {
        $tld = $node->tld;
        $address = (string) $node->wireguard_address;
        $lines = ["address=/orbit.{$tld}/{$address}"];

        if (! $this->hasWildcardDnsRole($node)) {
            return $lines;
        }

        return [
            ...$lines,
            "address=/{$tld}/{$address}",
            "local=/{$tld}/",
        ];
    }

    /** @return list<string> */
    private function routerLines(Node $router): array
    {
        $address = (string) $router->wireguard_address;

        return [
            "address=/orbit/{$address}",
            'local=/orbit/',
        ];
    }
}
