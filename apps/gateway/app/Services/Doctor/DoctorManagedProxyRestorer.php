<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use Throwable;

final readonly class DoctorManagedProxyRestorer
{
    private const array BEFORE_EXTRA_KEYS = [
        'proxy.agent_tool_route_missing',
        'proxy.agent_tool_route_mismatch',
        AnalyticsProxyDoctorProbe::RouterRouteKey,
        AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
        AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY,
    ];

    private const array AFTER_EXTRA_KEYS = [
        'proxy.caddy_container_missing',
        'proxy.caddy_container_down',
        'proxy.caddy_container_detached',
        'proxy.global_config_missing',
        'proxy.global_config_mismatch',
        WebSocketProxyDoctorProbe::RouterRouteKey,
        WebSocketProxyDoctorProbe::PublicRouteKey,
        S3ProxyDoctorProbe::RouterRouteKey,
        S3ProxyDoctorProbe::RouterBackendKey,
        S3ProxyDoctorProbe::PublicRouteKey,
    ];

    public function __construct(
        private ProxyRouteFixer $proxyRouteFixer,
        private WebSocketProxyDoctorProbe $webSocketProxyDoctorProbe,
        private S3ProxyDoctorProbe $s3ProxyDoctorProbe,
        private AnalyticsProxyDoctorProbe $analyticsProxyDoctorProbe,
        private AnalyticsPublicProxyDoctorProbe $analyticsPublicProxyDoctorProbe,
    ) {}

    public function supportsBeforeExtra(string $key): bool
    {
        return in_array($key, self::BEFORE_EXTRA_KEYS, strict: true);
    }

    public function supportsAfterExtra(string $key): bool
    {
        return in_array($key, self::AFTER_EXTRA_KEYS, strict: true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $mode, string $key, DriftEntry $entry): ?array
    {
        if ($mode === 'verify') {
            return null;
        }

        try {
            return match ($key) {
                'proxy.agent_tool_route_missing',
                'proxy.agent_tool_route_mismatch',
                    => $this->proxyRouteFixer->restoreAgentToolRoute($node, $entry),
                AnalyticsProxyDoctorProbe::RouterRouteKey,
                AnalyticsProxyDoctorProbe::RouterRouteOrphanedKey,
                    => $this->analyticsProxyDoctorProbe->restore(
                    $node,
                    $entry,
                ),
                AnalyticsPublicProxyDoctorProbe::PUBLIC_ROUTE_KEY => $this->analyticsPublicProxyDoctorProbe->restore(
                    $node,
                    $entry,
                ),
                'proxy.caddy_container_missing',
                'proxy.caddy_container_down',
                'proxy.caddy_container_detached',
                    => $this->proxyRouteFixer->fixCaddyContainer($node, $entry),
                'proxy.global_config_missing',
                'proxy.global_config_mismatch',
                    => $this->proxyRouteFixer->fixGlobalConfig($node, $entry),
                WebSocketProxyDoctorProbe::RouterRouteKey,
                WebSocketProxyDoctorProbe::PublicRouteKey,
                    => $this->webSocketProxyDoctorProbe->restore($node, $entry),
                S3ProxyDoctorProbe::RouterRouteKey,
                S3ProxyDoctorProbe::RouterBackendKey,
                S3ProxyDoctorProbe::PublicRouteKey,
                    => $this->s3ProxyDoctorProbe->restore($node, $entry),
                default => null,
            };
        } catch (Throwable $throwable) {
            return [
                'family' => $entry->family,
                'node' => $node->name,
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => $mode,
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $throwable->getMessage(),
                ],
            ];
        }
    }
}
