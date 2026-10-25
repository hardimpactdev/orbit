<?php

declare(strict_types=1);

namespace App\Infrastructure\Routes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\IngressSite;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRoutePrivateOverride;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Models\Node;
use App\Models\Route;

final readonly class NativePublicRouteEdgeProjector implements PublicRouteEdgeProjector
{
    public function __construct(
        private IngressSiteRepository $sites,
        private RemoteAppDevCertificateManager $certificates,
        private RemoteAppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private AgentCommandRunner $agents,
    ) {}

    public function artifact(Route $route): IngressSite
    {
        return $this->sites->forRoute($route);
    }

    public function privateOverride(Route $route): PublicRoutePrivateOverride
    {
        return $this->sites->privateOverride($route);
    }

    public function prepareIngressCertificate(Route $route): void
    {
        // Public Ingress uses Caddy automatic HTTPS (Let's Encrypt). Confirm Ingress exists
        // and do not pin an Orbit CA leaf that would replace a working public certificate.
        $this->sites->ingressNode($route);
    }

    public function verifyPublicEdge(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        if ($route->isCustomProxy()) {
            $process = $route->customProxy?->process;
            if ($process === null) {
                throw new RuntimeConvergenceException('public-edge-verified', 'route.upstream_unresolved', 'The public Route Process is missing.');
            }
            $upstream = new CustomProxyProcessListener()->resolvePublic($process, $ingress);
            if (! app(PublicProcessRouteFirewall::class)->matches($route, $ingress)) {
                throw new RuntimeConvergenceException('public-edge-verified', 'route.upstream_firewall_mismatch', 'The Process Node firewall must allow the selected Ingress source to the published listener. Add a managed firewall rule and retry.');
            }
            try {
                AgentStep::run($this->agents, $ingress, 'routes.verify-http', [
                    'address' => $upstream->host,
                    'port' => $upstream->port,
                    'domain' => $route->domain,
                ], 'public-edge-verified', 'route.upstream_unreachable', 'Public Route', 15);
            } catch (RuntimeConvergenceException $exception) {
                throw new RuntimeConvergenceException('public-edge-verified', 'route.upstream_unreachable', 'The Ingress cannot reach the selected Process listener. Check its listener and firewall before retrying.', previous: $exception);
            }

            return;
        }
        $router = $this->sites->routerNode($route);
        $override = $this->sites->privateOverride($route);

        if (is_string($router->lan_ip) && $router->lan_ip !== '' && $override->routerAddress !== $router->lan_ip) {
            throw new RuntimeConvergenceException(
                step: 'route-address',
                errorCode: 'route.lan_unreachable',
                message: 'The configured Ingress LAN path does not match the Router LAN address.',
            );
        }

        $this->verifyHop($ingress, $override->routerAddress, $route->domain, 'ingress-forwarding');

        if ($override->workloadAddress !== $override->routerAddress) {
            $this->verifyHop($router, $override->workloadAddress, $route->domain, 'router-forwarding');
        }
    }

    public function activatePublicHandler(Route $route): void
    {
        $this->buildEdge($route);
    }

    public function prepareIngressFirewall(Route $route): void
    {
        $ingress = $this->sites->ingressNode($route);
        $this->firewall->converge($ingress, RoleName::Ingress, $ingress->user);
        if ($route->isCustomProxy()) {
            app(DnsmasqPrivateDnsManager::class)->converge();
        }
    }

    /** @param list<int> $skippedNodeIds */
    public function rollbackPublicEdge(Route $route, array $skippedNodeIds = []): void
    {
        $ingress = $this->buildEdge($route, $skippedNodeIds);
        if (in_array($ingress->id, $skippedNodeIds, true)) {
            return;
        }
        $this->firewall->converge($ingress, RoleName::Ingress, $ingress->user);
        $this->certificates->removeRouteIngress($route, $ingress);
    }

    public function removePublicEdge(Route $route): void
    {
        $this->rollbackPublicEdge($route);
    }

    /**
     * Builds the Ingress Node, and the Router when it is another Node: a Router that forwards to a target
     * on the Ingress Node changes how it verifies that target while the Route is public.
     *
     * @param  list<int>  $skippedNodeIds
     */
    private function buildEdge(Route $route, array $skippedNodeIds = []): Node
    {
        $route->loadMissing('cluster.ingressAssignment.node');
        $assigned = $route->cluster?->ingressAssignment?->node;
        $ingress = $assigned instanceof Node && in_array($assigned->id, $skippedNodeIds, true)
            ? $assigned
            : $this->sites->ingressNode($route);
        if (! in_array($ingress->id, $skippedNodeIds, true)) {
            $this->caddy->build($ingress);
        }

        if ($route->isCustomProxy()) {
            return $ingress;
        }

        try {
            $router = $this->sites->routerNode($route);
        } catch (RuntimeConvergenceException $exception) {
            // A Cluster without an active Router has no Router site for this Route to build.
            if ($exception->errorCode !== 'cluster.router_required') {
                throw $exception;
            }

            return $ingress;
        }

        if (! $router->is($ingress) && ! in_array($router->id, $skippedNodeIds, true)) {
            $this->caddy->build($router);
        }

        return $ingress;
    }

    private function verifyHop(Node $from, string $address, string $domain, string $step): void
    {
        AgentStep::run($this->agents, $from, 'routes.verify-certificate', [
            'address' => $address,
            'port' => 443,
            'domain' => $domain,
        ], $step, 'route.public_edge_unverified', 'Public Route', 15);
    }
}
