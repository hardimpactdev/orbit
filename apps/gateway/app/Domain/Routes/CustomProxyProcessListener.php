<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\RouteCustomProxy;

final readonly class CustomProxyProcessListener
{
    public function resolvePublic(Process $process, Node $ingress): CustomProxyUpstream
    {
        $node = $process->owner;
        $address = $node instanceof Node ? $this->publicAddress($node, $ingress) : null;

        return $this->resolvePublicAtAddress($process, $address);
    }

    private function resolvePublicAtAddress(Process $process, ?string $address): CustomProxyUpstream
    {
        $node = $process->owner;
        $ports = $process->runtime_config['ports'] ?? [];
        $listeners = [];

        if ($process->owner_type === Node::class && $node instanceof Node
            && $node->platform === 'linux'
            && is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $process->runtime === ProcessRuntime::Docker && is_array($ports)) {
            foreach ($ports as $spec) {
                if (! is_string($spec) || (str_contains($spec, '/') && ! str_ends_with($spec, '/tcp'))) {
                    continue;
                }
                $port = DockerPublishedPort::parse($spec);
                if ($port !== null && $port->bindAddress === $address) {
                    $listeners[] = new CustomProxyUpstream($address, $port->publishedPort);
                }
            }
        }

        if (count($listeners) !== 1) {
            throw new ResourceOperationException('route.upstream_unresolved', "Process [{$process->id}] must publish exactly one TCP listener on the selected upstream address [{$address}]. Add that address with process:update before creating the Route.", 409);
        }

        return $listeners[0];
    }

    public function assertPreservesRoutes(Process $candidate): void
    {
        $proxies = RouteCustomProxy::query()->where('process_id', $candidate->id)->with('route')->orderBy('route_id')->get();

        foreach ($proxies as $proxy) {
            $route = $proxy->route;
            if (! $route instanceof Route) {
                continue;
            }
            try {
                $address = parse_url($proxy->upstream, PHP_URL_HOST);
                $upstream = $route->publication === RoutePublication::Public
                    ? $this->resolvePublicAtAddress($candidate, is_string($address) ? $address : null)
                    : $this->resolve($candidate);
                if ($upstream->url() === $proxy->upstream) {
                    continue;
                }
            } catch (ResourceOperationException) {
                // An unresolved listener also invalidates the stored Route upstream.
            }

            throw new ResourceOperationException(
                errorCode: 'process.route_upstream_changed',
                message: "Update would change or remove the upstream listener for Route [{$route->domain}] ({$route->id}).",
                status: 409,
                details: ['route_id' => $route->id, 'domain' => $route->domain],
            );
        }
    }

    public function publicAddress(Node $node, Node $ingress): ?string
    {
        if (is_string($node->lan_ip) && is_string($ingress->lan_ip)
            && filter_var($node->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ingress->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (ip2long($node->lan_ip) & 0xFFFFFF00) === (ip2long($ingress->lan_ip) & 0xFFFFFF00)) {
            return $node->lan_ip;
        }

        return $node->wireguard_ip;
    }

    public function resolve(Process $process): CustomProxyUpstream
    {
        if ($process->runtime !== ProcessRuntime::Docker) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        $ports = $process->runtime_config['ports'] ?? null;

        if (! is_array($ports) || $ports === []) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        $resolved = [];

        foreach ($ports as $spec) {
            if (! is_string($spec) || (str_contains($spec, '/') && ! str_ends_with($spec, '/tcp'))) {
                continue;
            }

            $parsed = DockerPublishedPort::parse($spec);

            if (! $parsed instanceof DockerPublishedPort) {
                continue;
            }

            if (
                $parsed->bindAddress !== null
                && $parsed->bindAddress !== '127.0.0.1'
                && $parsed->bindAddress !== '0.0.0.0'
                && $parsed->bindAddress !== '::1'
                && $parsed->bindAddress !== 'localhost'
            ) {
                continue;
            }

            $resolved[] = new CustomProxyUpstream('127.0.0.1', $parsed->publishedPort);
        }

        if (count($resolved) !== 1) {
            throw new ResourceOperationException(
                errorCode: 'route.upstream_unresolved',
                message: "Process [{$process->name}] has no single Node-local listener.",
                status: 409,
            );
        }

        return $resolved[0];
    }
}
