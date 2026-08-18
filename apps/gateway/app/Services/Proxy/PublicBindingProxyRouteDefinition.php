<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use InvalidArgumentException;

final readonly class PublicBindingProxyRouteDefinition
{
    /**
     * @param  list<string>|null  $trackingPaths
     */
    private function __construct(
        public string $ownerType,
        public string $protocol,
        public string $serviceTarget,
        public ?array $trackingPaths,
        public bool $usesRouterBackendTls,
    ) {}

    public static function analytics(): self
    {
        return new self(
            ownerType: 'app-analytics',
            protocol: 'analytics',
            serviceTarget: AnalyticsRouteRegistrar::PublicServiceTarget,
            trackingPaths: AnalyticsRouteRegistrar::TrackingPaths,
            usesRouterBackendTls: false,
        );
    }

    public static function websocket(): self
    {
        return new self(
            ownerType: 'app-websocket',
            protocol: 'websocket',
            serviceTarget: WebSocketRouteRegistrar::PublicServiceTarget,
            trackingPaths: null,
            usesRouterBackendTls: true,
        );
    }

    public static function forOwnerType(string $ownerType): self
    {
        return match ($ownerType) {
            'app-analytics' => self::analytics(),
            'app-websocket' => self::websocket(),
            default => throw new InvalidArgumentException("Unsupported public binding owner type [{$ownerType}]."),
        };
    }

    /**
     * @param  list<array{node_id: int, node: string, url: string}>  $backendPool
     * @return array<string, mixed>
     */
    public function config(
        Node $ingress,
        Node $router,
        string $domain,
        string $routerUrl,
        array $backendPool,
    ): array {
        $config = [
            'placement' => 'ingress',
            'ingress_node_id' => $ingress->id,
            'protocol' => $this->protocol,
            'target' => [
                'type' => $this->protocol,
                'value' => $this->serviceTarget,
            ],
            'upstream' => $this->serviceTarget,
        ];

        if ($this->trackingPaths !== null) {
            $config['tracking_paths'] = $this->trackingPaths;
        }

        $config['router_upstream'] = [
            'node_id' => $router->id,
            'node' => $router->name,
            'url' => $routerUrl,
        ];
        $config['router_backend_pool'] = $backendPool;

        if ($this->usesRouterBackendTls) {
            $config['router_backend_tls'] = [
                'trusted_by_gateway_ca' => true,
                'ca_path' => '/etc/orbit/ca/root.crt',
            ];
        }

        $config['tls'] = [
            'cert_path' => "/etc/orbit/certs/{$domain}.crt",
            'key_path' => "/etc/orbit/certs/{$domain}.key",
        ];

        return $config;
    }

    /**
     * @return array{
     *     node_id: int,
     *     domain: string,
     *     app_id: int|null,
     *     workspace_id: null,
     *     instance_id: int,
     *     owner_type: string,
     *     kind: string,
     * }
     */
    public function attributes(Instance $instance, string $domain, int $nodeId): array
    {
        return [
            'node_id' => $nodeId,
            'domain' => $domain,
            'app_id' => $instance->app_id,
            'workspace_id' => null,
            'instance_id' => $instance->id,
            'owner_type' => $this->ownerType,
            'kind' => 'proxy',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function route(Instance $instance, string $domain, int $nodeId, array $config): ProxyRoute
    {
        return new ProxyRoute([
            ...$this->attributes($instance, $domain, $nodeId),
            'config' => $config,
        ]);
    }
}
