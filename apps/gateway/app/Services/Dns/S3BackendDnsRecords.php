<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Support\Collection;

final class S3BackendDnsRecords
{
    /**
     * @param  Collection<int, ProxyRoute>  $routes
     * @param  Collection<int, Node>  $nodes
     * @return Collection<int, string>
     */
    public function build(Collection $routes, Collection $nodes): Collection
    {
        $lines = [];

        foreach ($routes as $route) {
            if (! $this->isS3ServiceRoute($route)) {
                continue;
            }

            $lines = [...$lines, ...$this->routeLines($route, $nodes)];
        }

        $lines = array_values(array_unique($lines));
        sort($lines);

        return new Collection($lines);
    }

    private function isS3ServiceRoute(ProxyRoute $route): bool
    {
        $config = is_array($route->config) ? $route->config : [];

        return ! in_array(
            false,
            [
                $route->domain === 's3.orbit',
                ($config['protocol'] ?? null) === 's3',
                is_array($config['upstreams'] ?? null),
            ],
            strict: true,
        );
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<string>
     */
    private function routeLines(ProxyRoute $route, Collection $nodes): array
    {
        $config = is_array($route->config) ? $route->config : [];

        if (! is_array($config['upstreams'] ?? null)) {
            return [];
        }

        $rawUpstreams = $config['upstreams'];
        $upstreams = array_filter($rawUpstreams, is_array(...));
        $lines = [];

        foreach ($upstreams as $upstream) {
            $line = $this->lineForUpstream($upstream, $nodes);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** @param  Collection<int, Node>  $nodes */
    private function lineForUpstream(array $upstream, Collection $nodes): ?string
    {
        if (! is_string($upstream['host'] ?? null)) {
            return null;
        }

        $host = $upstream['host'];
        $node = $nodes->first(
            static fn (Node $candidate): bool => ! in_array(
                false,
                [
                    "{$candidate->name}.s3.orbit" === $host,
                    $candidate->isActive(),
                    $candidate->hasActiveRole(NodeRoleName::S3->value),
                    filled($candidate->wireguard_address),
                ],
                strict: true,
            ),
        );

        if (! $node instanceof Node) {
            return null;
        }

        return "address=/{$host}/{$node->wireguard_address}";
    }
}
