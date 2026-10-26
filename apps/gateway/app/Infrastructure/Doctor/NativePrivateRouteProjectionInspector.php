<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\AppDev\DnsRequester;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteStateResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use Throwable;

final readonly class NativePrivateRouteProjectionInspector implements PrivateRouteProjectionInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
        private DevelopmentSiteRepository $sites = new DevelopmentSiteRepository,
        private RouteStateResolver $placements = new RouteStateResolver,
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
        private ClusterRouterDnsSelection $dnsSelection = new ClusterRouterDnsSelection,
        private NodeFirewallRuleCatalog $firewall = new NodeFirewallRuleCatalog,
    ) {}

    public function inspect(Instance $instance, Route $route): PrivateRouteProjectionObservation
    {
        $instance->loadMissing(['node.cluster', 'node.roles', 'project']);
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.instance.node']);

        try {
            $workload = $this->workloadSite($instance, $route);
            $router = $this->routerNode($instance, $route);
            $routerSite = $router instanceof Node ? $this->routerSite($route, $router) : null;
            $workloadValues = $this->observe($instance->node, $this->workloadCommand($instance, $route, $workload));
            $routerValues = $router instanceof Node && $routerSite instanceof DevelopmentSite
                ? $this->observe($router, $this->routerCommand($route, $routerSite))
                : ['caddy' => true, 'tls' => true, 'firewall' => true];
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $expectsFirewall = $this->expectsFirewall($instance->node, $router);

        return new PrivateRouteProjectionObservation(
            routingScopeMatches: $this->routingScopeMatches($instance, $route),
            routerCaddyMatches: $router instanceof Node ? ($routerValues['caddy'] ?? null) : true,
            workloadCaddyMatches: $workloadValues['caddy'] ?? null,
            certificateMatches: $this->combine(
                $workloadValues['tls'] ?? null,
                $router instanceof Node ? ($routerValues['tls'] ?? null) : true,
            ),
            dnsMatches: $workloadValues['dns'] ?? null,
            firewallMatches: $expectsFirewall
                ? $this->combine($workloadValues['firewall'] ?? true, $routerValues['firewall'] ?? true)
                : true,
            laravelUrlMatches: $workloadValues['laravel'] ?? null,
            targetSetMatches: $router instanceof Node
                ? ($routerValues['pool'] ?? $this->targetSetMatches($route, $routerSite))
                : true,
            associationMatches: $this->associationMatches($instance, $route),
        );
    }

    private function targetSetMatches(Route $route, ?DevelopmentSite $routerSite): bool
    {
        if (! $routerSite instanceof DevelopmentSite) {
            return $route->targets->count() <= 1;
        }

        $router = $route->cluster?->routerAssignment?->node;
        $expected = $route->targets
            ->map(static function ($row) use ($router): ?string {
                $node = $row->instance->node;

                if ($router instanceof Node && $router->is($node)) {
                    return null;
                }

                return is_string($node->lan_ip) && $node->lan_ip !== ''
                    ? $node->lan_ip
                    : $node->wireguard_ip;
            })
            ->filter(static fn (?string $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();
        $observed = array_values(array_filter(
            $routerSite->proxyAddresses(),
            static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
        ));

        sort($expected);
        sort($observed);

        return $expected === $observed;
    }

    /** Each Instance has exactly one Route without a web root. Routes with a web root are additional sites. */
    private function associationMatches(Instance $instance, Route $route): bool
    {
        $own = static fn () => $instance->routeTargets()->whereHas('route', static fn ($query) => $query->whereNull('web_root'));

        return $route->hasWebRoot()
            ? $own()->count() <= 1
            : $own()->count() === 1 && $own()->where('route_id', $route->id)->exists();
    }

    private function routingScopeMatches(Instance $instance, Route $route): bool
    {
        $placement = $this->placements->forNode($instance->node);

        return $route->node_id === $placement->nodeId && $route->cluster_id === $placement->clusterId;
    }

    private function routerNode(Instance $instance, Route $route): ?Node
    {
        $cluster = $route->cluster;
        if ($cluster === null || $cluster->state !== ClusterState::Active) {
            return null;
        }

        $router = $this->eligibility->activeRouter($cluster);

        return $router instanceof Node && ! $router->is($instance->node) ? $router : null;
    }

    private function workloadSite(Instance $instance, Route $route): DevelopmentSite
    {
        $site = $this->sites->forNode($instance->node)->first(
            static fn (DevelopmentSite $candidate): bool => $candidate->domain === $route->domain
                && $candidate->nodeId === $instance->node_id
                && ! $candidate->isProxy()
                && ! $candidate->publicListener,
        );

        if (! $site instanceof DevelopmentSite) {
            throw new DoctorInspectionException;
        }

        return $site;
    }

    private function routerSite(Route $route, Node $router): ?DevelopmentSite
    {
        $site = $this->sites->forNode($router)->first(
            static fn (DevelopmentSite $candidate): bool => $candidate->domain === $route->domain
                && $candidate->nodeId === $router->id
                && $candidate->isProxy()
                && ! $candidate->publicListener,
        );

        return $site instanceof DevelopmentSite ? $site : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, ?bool>
     */
    private function observe(Node $node, array $input): array
    {
        try {
            $result = $this->agents->run($node, 'doctor.inspect-private-route', $input, $this->deadline->cap(30.0));
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $values = [];
        foreach (['caddy', 'tls', 'dns', 'firewall', 'laravel', 'pool'] as $key) {
            if (! array_key_exists($key, $result) || ($result[$key] !== null && ! is_bool($result[$key]))) {
                throw new DoctorInspectionException;
            }
            $values[$key] = $result[$key];
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function workloadCommand(Instance $instance, Route $route, DevelopmentSite $site): array
    {
        $environment = $instance->usesProductionReleaseLayout()
            ? (string) $instance->production_home
            : $instance->applicationDirectory();
        $laravel = $route->hasWebRoot() || $instance->source_is_laravel === false
            ? ['kind' => 'skip']
            : ($instance->source_is_laravel === true ? [
                'kind' => 'check',
                'environment' => $environment.'/.env',
                'url' => $this->expectedLaravelUrl($instance, $route),
            ] : null);

        return [
            'domain' => $site->domain,
            'certificates' => $site->certificateDirectory(),
            'projection' => [
                'kind' => 'workload',
                'dns_address' => $this->expectedDnsAddress($instance, $route),
                'laravel' => $laravel,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function routerCommand(Route $route, DevelopmentSite $site): array
    {
        return [
            'domain' => $route->domain,
            'certificates' => $site->certificateDirectory(),
            'projection' => [
                'kind' => 'router',
                'upstreams' => array_values(array_filter(
                    $site->proxyAddresses(),
                    static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
                )),
            ],
        ];
    }

    private function expectedLaravelUrl(Instance $instance, Route $route): string
    {
        $stored = $instance->environmentValues()
            ->where('env_key', 'APP_URL')
            ->value('env_value');

        if (! is_string($stored) || $stored === '') {
            return 'https://'.$route->domain;
        }

        return str_replace(
            ['{{instance.domain}}', '{{instance.environment}}'],
            [$route->domain, $instance->defaultAppEnv()],
            $stored,
        );
    }

    private function expectedDnsAddress(Instance $instance, Route $route): ?string
    {
        $node = $instance->node;
        if ($route->cluster_id === null) {
            return is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
                ? $node->wireguard_ip
                : null;
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return null;
        }

        $answers = $this->dnsSelection->answers();
        $override = $answers['overrides'][DnsRequester::registered($node->id, $node->wireguard_ip)->cacheKey()][$route->domain] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $router = $route->cluster?->routerAssignment?->node;
        $address = $router?->wireguard_ip;

        return is_string($address) && $address !== '' ? $address : null;
    }

    private function expectsFirewall(Node $workload, ?Node $router): bool
    {
        foreach ($workload->roles as $assignment) {
            if ($this->firewall->forRole($workload, $assignment->role) !== []) {
                return true;
            }
        }

        return $router instanceof Node && $this->firewall->forRole($router, RoleName::Router) !== [];
    }

    private function combine(?bool $left, ?bool $right): ?bool
    {
        if ($left === null || $right === null) {
            return null;
        }

        return $left && $right;
    }
}
