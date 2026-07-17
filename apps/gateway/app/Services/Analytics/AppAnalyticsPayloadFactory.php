<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AppAnalyticsBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\IngressResolver;
use Illuminate\Support\Str;
use Throwable;

/** @mago-expect lint:too-many-methods */
final readonly class AppAnalyticsPayloadFactory
{
    public function __construct(
        private AnalyticsRouteRegistrar $routes,
        private IngressResolver $ingressResolver,
    ) {}

    /** @return array<string, mixed> */
    public function enableResult(AppAnalyticsBinding $binding): array
    {
        return [
            'binding' => $this->integrationBinding($binding),
            'route_enactment' => [
                'status' => 'completed',
                'placements' => ['router', 'ingress'],
            ],
            'dns_expectation' => $this->dnsExpectation($binding),
            'public_readiness' => [
                'status' => 'not_verified',
                'dns' => 'unchecked',
                'tls' => 'unchecked',
                'script' => 'unchecked',
                'dashboard' => 'unchecked',
                'event' => 'not_run',
                'plausible_site' => 'unchecked',
            ],
            'remaining_actions' => [
                'configure_provider_dns',
                'ensure_plausible_site',
                'integrate_application_script',
                'verify_public_readiness',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function verificationContext(AppAnalyticsBinding $binding): array
    {
        return [
            'binding' => $this->integrationBinding($binding),
            'routes' => $this->routeStatuses($binding),
            'dns_expectation' => $this->dnsExpectation($binding),
        ];
    }

    /** @return array<string, mixed> */
    public function binding(AppAnalyticsBinding $binding): array
    {
        $binding->loadMissing('app');
        $publicHosts = $this->stringList($binding->public_hosts);

        return [
            'app' => $binding->app->name,
            'enabled' => $binding->enabled,
            'internal_host' => AnalyticsRouteRegistrar::ServiceDomain,
            'dashboard_url' => 'https://'.AnalyticsRouteRegistrar::ServiceDomain,
            'public_hosts' => $publicHosts,
            'tracking_paths' => AnalyticsRouteRegistrar::TrackingPaths,
            'tracking_endpoints' => array_map(
                static fn (string $host): array => [
                    'host' => $host,
                    'script_base_url' => "https://{$host}",
                    'event_endpoint' => "https://{$host}/api/event",
                ],
                $publicHosts,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function integrationBinding(AppAnalyticsBinding $binding): array
    {
        $bindingPayload = $this->binding($binding);
        $siteDomain = $this->siteDomain($binding);
        $publicHosts = $this->stringList($binding->public_hosts);

        return [
            ...$bindingPayload,
            'site_domain' => $siteDomain,
            'tracking_endpoints' => array_map(
                fn (string $host): array => $this->trackingEndpoint($host, $siteDomain),
                $publicHosts,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function trackingEndpoint(string $host, string $siteDomain): array
    {
        $scriptUrl = "https://{$host}/js/script.js";

        return [
            'host' => $host,
            'script_base_url' => "https://{$host}",
            'script_url' => $scriptUrl,
            'event_endpoint' => "https://{$host}/api/event",
            'data_domain' => $siteDomain,
            'snippet' => "<script defer data-domain=\"{$siteDomain}\" src=\"{$scriptUrl}\"></script>",
        ];
    }

    /** @return array<string, mixed> */
    private function dnsExpectation(AppAnalyticsBinding $binding): array
    {
        $hosts = $this->stringList($binding->public_hosts);
        $ingress = $this->ingress($binding);

        if (! $ingress instanceof Node) {
            return [
                'hosts' => $hosts,
                'ingress_node' => null,
                'targets' => [],
                'provider_managed' => false,
            ];
        }

        $targets = [];
        $this->appendAddressTarget($targets, 'A', $ingress->public_ipv4, FILTER_FLAG_IPV4);
        $this->appendAddressTarget($targets, 'AAAA', $ingress->public_ipv6, FILTER_FLAG_IPV6);

        return [
            'hosts' => $hosts,
            'ingress_node' => $ingress->name,
            'targets' => $targets,
            'provider_managed' => false,
        ];
    }

    /** @return list<array{host: string, status: string}> */
    private function routeStatuses(AppAnalyticsBinding $binding): array
    {
        $hosts = $this->stringList($binding->public_hosts);

        try {
            $intents = $this->routes->publicRouteIntents($binding);
        } catch (Throwable) {
            return array_map(
                static fn (string $host): array => ['host' => $host, 'status' => 'unverifiable'],
                $hosts,
            );
        }

        $intentsByHost = [];

        foreach ($intents as $intent) {
            $intentsByHost[$intent->domain] = $intent;
        }

        return array_map(static function (string $host) use ($intentsByHost): array {
            $intent = $intentsByHost[$host] ?? null;

            if (! $intent instanceof ProxyRoute) {
                return ['host' => $host, 'status' => 'unverifiable'];
            }

            $route = ProxyRoute::query()->where('domain', $host)->first();

            if (! $route instanceof ProxyRoute) {
                return ['host' => $host, 'status' => 'missing'];
            }

            $keys = ['node_id', 'app_id', 'workspace_id', 'owner_type', 'kind', 'config', 'source_hash'];
            $status = $route->only($keys) === $intent->only($keys) ? 'registered' : 'divergent';

            return ['host' => $host, 'status' => $status];
        }, $hosts);
    }

    private function ingress(AppAnalyticsBinding $binding): ?Node
    {
        $binding->loadMissing('app.node');

        if (! $binding->app->node instanceof Node) {
            return null;
        }

        try {
            return $this->ingressResolver->forAppNode($binding->app->node);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array{type: string, value: string}> $targets */
    private function appendAddressTarget(array &$targets, string $type, mixed $value, int $flag): void
    {
        if (! is_string($value)) {
            return;
        }

        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            return;
        }

        $targets[] = ['type' => $type, 'value' => $value];
    }

    private function siteDomain(AppAnalyticsBinding $binding): string
    {
        $domain = is_string($binding->app->domain) ? trim($binding->app->domain) : '';

        return Str::lower($domain);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
