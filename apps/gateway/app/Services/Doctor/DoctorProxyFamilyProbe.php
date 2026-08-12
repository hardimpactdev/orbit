<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsProxyDoctorProbe;
use App\Services\Analytics\AnalyticsPublicProxyDoctorProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteProbe;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class DoctorProxyFamilyProbe
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        private DoctorFamilyProbeRunner $familyProbeRunner,
        private DoctorProxyRouteInventory $routeInventory,
        private ProxyRouteProbe $proxyRouteProbe,
        private NodeRoleAssignments $nodeRoleAssignments,
        private ProxyDnsProjectionProbe $proxyDnsProjectionProbe,
        private WebSocketProxyDoctorProbe $webSocketProxyDoctorProbe,
        private S3ProxyDoctorProbe $s3ProxyDoctorProbe,
        private AnalyticsProxyDoctorProbe $analyticsProxyDoctorProbe,
        private AnalyticsPublicProxyDoctorProbe $analyticsPublicProxyDoctorProbe,
        private DoctorIssueFactory $doctorIssueFactory,
    ) {}

    /**
     * @param  (callable(string, 'running'|'done', list<array<string, mixed>>, ?int, ?int): void)|null  $onFamilyProgress
     * @return list<DoctorIssue>
     */
    public function probe(
        Node $node,
        DoctorTargetScope $scope,
        ?string $key,
        ?callable $onFamilyProgress,
    ): array {
        $routes = $this->routeInventory->forScope($node, $scope);
        $unscoped = $scope->app === null && $scope->workspace === null;
        $probeCaddy = $node->isActive() && $this->nodeRoleAssignments->nodeHostsOrbitCaddy($node);
        $total =
            $routes->count()
            + 2
            + ($unscoped ? 1 : 0)
            + ($this->shouldProbeDnsProjection($node, $scope) ? 1 : 0)
            + ($probeCaddy ? 2 : 0);

        return $this->familyProbeRunner->run(
            node: $node,
            family: 'proxy',
            total: $total,
            key: $key,
            onFamilyProgress: $onFamilyProgress,
            probe: function (callable $addIssue, callable $advance) use (
                $node,
                $probeCaddy,
                $routes,
                $scope,
                $unscoped,
            ): void {
                $this->probeRoutes($routes, $addIssue, $advance);
                $this->probeSharedRoutes($node, $scope, $addIssue, $advance);

                if (! $probeCaddy) {
                    return;
                }

                $this->probeCaddyContainer($node, $addIssue);
                $advance();
                $this->probeCaddyRoutes($node, $addIssue);
                $advance();
            },
        );
    }

    /**
     * @param  iterable<int, ProxyRoute>  $routes
     * @param  callable(DoctorIssue): void  $addIssue
     * @param  callable(): void  $advance
     */
    private function probeRoutes(iterable $routes, callable $addIssue, callable $advance): void
    {
        foreach ($routes as $route) {
            $snapshot = $this->proxyRouteProbe->introspect($route);

            foreach ($this->proxyRouteProbe->diff($route, $snapshot) as $entry) {
                $addIssue($this->routeIssue($entry, $route));
            }

            $advance();
        }
    }

    /**
     * @param  callable(DoctorIssue): void  $addIssue
     * @param  callable(): void  $advance
     * @mago-expect lint:halstead
     */
    private function probeSharedRoutes(
        Node $node,
        DoctorTargetScope $scope,
        callable $addIssue,
        callable $advance,
    ): void {
        $unscoped = $scope->app === null && $scope->workspace === null;

        if ($unscoped) {
            foreach ($this->proxyRouteProbe->diffAgentToolRouteIntent($node) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }

            $advance();
        }

        if ($this->shouldProbeDnsProjection($node, $scope)) {
            foreach ($this->proxyDnsProjectionProbe->drift($node) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }

            $advance();
        }

        if ($scope->workspace === null) {
            foreach ($this->webSocketProxyDoctorProbe->drift($node, $scope->app) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }
        }

        $advance();

        if ($unscoped) {
            foreach ($this->s3ProxyDoctorProbe->drift($node) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }

            foreach ($this->analyticsProxyDoctorProbe->drift($node) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }
        }

        if ($scope->workspace === null) {
            foreach ($this->analyticsPublicProxyDoctorProbe->drift($node, $scope->app) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }
        }

        $advance();
    }

    /**
     * @param  callable(DoctorIssue): void  $addIssue
     */
    private function probeCaddyContainer(Node $node, callable $addIssue): void
    {
        $snapshot = $this->proxyRouteProbe->introspectCaddyContainer($node);

        foreach ($this->proxyRouteProbe->diffCaddyContainer($node, $snapshot) as $entry) {
            $addIssue($this->nodeIssue($entry, $node));
        }
    }

    /**
     * @param  callable(DoctorIssue): void  $addIssue
     */
    private function probeCaddyRoutes(Node $node, callable $addIssue): void
    {
        try {
            $snapshot = $this->proxyRouteProbe->introspectNode($node);
            $expectedDomains = $this->proxyRouteProbe->expectedDomainsForNode($node);

            foreach ($this->proxyRouteProbe->observedRouteDomainsForNode($node, $snapshot) as $domain) {
                if (in_array($domain, $expectedDomains, strict: true)) {
                    continue;
                }

                $entry = new DriftEntry(
                    family: 'proxy',
                    key: $domain,
                    kind: DriftKind::Extra,
                    summary: "Proxy route '{$domain}' exists on node but not in gateway registry.",
                );

                $addIssue($this->doctorIssueFactory->fromDriftEntry(
                    $entry,
                    $node->name,
                    code: 'proxy.route_extra',
                    detail: [
                        'domain' => $domain,
                        'code' => 'proxy.route_extra',
                    ],
                ));
            }

            $globalSnapshot = $this->proxyRouteProbe->introspectGlobalConfig($node);

            foreach ($this->proxyRouteProbe->diffGlobalConfig($node, $globalSnapshot) as $entry) {
                $addIssue($this->nodeIssue($entry, $node));
            }
        } catch (RemoteShellFailed $exception) {
            $addIssue($this->doctorIssueFactory->fromProbeFailure(
                family: 'proxy',
                node: $node->name,
                key: 'proxy.node_probe_failed',
                exception: $exception,
                summary: "Proxy node route scan failed on node '{$node->name}'; extra backend routes on the node cannot be detected.",
            ));
        }
    }

    private function shouldProbeDnsProjection(Node $node, DoctorTargetScope $scope): bool
    {
        return (
            $scope->app === null
            && $scope->workspace === null
            && $this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::Router->value)
        );
    }

    private function routeIssue(DriftEntry $entry, ProxyRoute $route): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $route->node->name,
            detail: [
                ...($entry->detail ?? []),
                'domain' => $route->domain,
            ],
        );
    }

    private function nodeIssue(DriftEntry $entry, Node $node): DoctorIssue
    {
        return $this->doctorIssueFactory->fromDriftEntry(
            $entry,
            $node->name,
            detail: $entry->detail ?? [],
        );
    }
}
