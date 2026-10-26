<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;

/**
 * The Orbit CA leaf certificates of development Routes, through the Node agent's `certificates.*-leaf` commands
 * (docs/reference/routes.md). The Gateway signs each request; the Node keeps the key.
 */
final readonly class RemoteAppDevCertificateManager
{
    public function __construct(
        private AgentCommandRunner $agents,
        private LeafCertificateSigner $signer,
        private ManagedUserAccountResolver $accounts,
        private DevelopmentSiteRepository $sites = new DevelopmentSiteRepository,
    ) {}

    public function convergeInstance(Instance $instance, Route $route): void
    {
        $instance->loadMissing('node');
        // A Route with a web root has its own leaf, so the Instance's leaf keeps naming its own Route.
        $scope = $route->hasWebRoot() ? "route-{$route->id}" : "app-instance-{$instance->id}";
        $this->converge($instance->node, $scope, $route->domain);
    }

    public function convergeRouteRouter(Route $route, Node $router): void
    {
        $this->converge($router, "route-{$route->id}-router", $route->domain);
    }

    public function convergeRouteIngress(Route $route, Node $ingress): void
    {
        $this->converge($ingress, "route-{$route->id}-ingress", $route->domain);
    }

    public function convergeCustomProxy(Route $route, Node $node): void
    {
        $this->converge($node, "route-{$route->id}", $route->domain);
    }

    public function convergeInstanceHostnameChange(Instance $instance, string $domain): void
    {
        $instance->loadMissing('node');
        $this->converge(
            $instance->node,
            "app-instance-{$instance->id}-hostname-change",
            $domain,
        );
    }

    public function convergeRouteRouterHostnameChange(Route $route, Node $router): void
    {
        $this->converge($router, "route-{$route->id}-router-hostname-change", $route->domain);
    }

    public function instanceCertificateExists(Instance $instance): bool
    {
        $instance->loadMissing('node');
        $account = $this->accounts->resolve($instance->node);
        $result = AgentStep::run($this->agents, $instance->node, 'certificates.inspect-leaf', [
            'scope' => "app-instance-{$instance->id}",
            'home' => $account->home,
        ], 'certificate-inspect', 'app-dev.certificate_inspection_failed', 'App development');

        return match ($result['present'] ?? null) {
            true => true,
            false => false,
            default => throw new RuntimeConvergenceException(
                step: 'certificate-inspect',
                errorCode: 'app-dev.certificate_inspection_failed',
                message: 'Instance certificate inspection returned invalid evidence.',
            ),
        };
    }

    public function removeInstance(Instance $instance): void
    {
        $instance->loadMissing('node');
        $this->remove($instance->node, "app-instance-{$instance->id}");
    }

    public function removeRouteRouter(Route $route, Node $router): void
    {
        $this->remove($router, "route-{$route->id}-router");
    }

    public function removeRouteRouterHostnameChange(Route $route, Node $router): void
    {
        $this->remove($router, "route-{$route->id}-router-hostname-change");
    }

    /**
     * Removes every Route-owned certificate scope on the Node after the Route record is gone, as the
     * cleanup of an offline removal does once the Node answers.
     */
    public function removeRemovedRoute(int $routeId, Node $node): void
    {
        foreach (["route-{$routeId}", "route-{$routeId}-router", "route-{$routeId}-router-hostname-change"] as $scope) {
            $this->remove($node, $scope);
        }
    }

    public function removeRouteIngress(Route $route, Node $ingress): void
    {
        $this->remove($ingress, "route-{$route->id}-ingress");
    }

    /** Removes the workload leaf of a Route with a web root, or of a custom proxy Route. */
    public function removeRouteLeaf(Route $route, Node $node): void
    {
        $this->remove($node, "route-{$route->id}");
    }

    public function removeCustomProxy(Route $route, Node $node): void
    {
        $this->remove($node, "route-{$route->id}");
    }

    /** @param list<int> $skippedNodeIds */
    public function removeHostnameChange(Instance $instance, Route $route, array $skippedNodeIds = []): void
    {
        $instance->loadMissing('node');
        if (! in_array($instance->node_id, $skippedNodeIds, true)) {
            $this->remove($instance->node, "app-instance-{$instance->id}-hostname-change");
        }
        $router = $route->cluster?->routerAssignment?->node;

        if ($router instanceof Node && ! $router->is($instance->node) && ! in_array($router->id, $skippedNodeIds, true)) {
            $this->remove($router, "route-{$route->id}-router-hostname-change");
        }
    }

    private function converge(Node $node, string $scope, string $domain): void
    {
        $account = $this->accounts->resolve($node);
        $rootCertificate = $this->signer->rootCertificate();
        $leaf = [
            'scope' => $scope,
            'domain' => $domain,
            'version' => bin2hex(random_bytes(8)),
            'root_hash' => hash('sha256', $rootCertificate),
            'user' => $account->user,
            'home' => $account->home,
        ];
        $request = AgentStep::run($this->agents, $node, 'certificates.request-leaf', $leaf, 'certificate-request', 'app-dev.certificate_request_failed', 'App development');

        if (($request['state'] ?? null) === 'current') {
            return;
        }

        AgentStep::run($this->agents, $node, 'certificates.publish-leaf', [
            ...$leaf,
            'certificate' => $this->signer->sign($domain, StoredValue::string($request['request'] ?? null)),
            'root_certificate' => $rootCertificate,
        ], 'certificate-publish', 'app-dev.certificate_publish_failed', 'App development');
    }

    /**
     * Caddy loads every certificate its Caddyfile names, so a certificate is removed only after the
     * build that withdraws its last site. While a site in the Gateway's stored state still renders
     * the scope on the Node, the removal fails and keeps the certificate, so the caller can retry.
     */
    private function remove(Node $node, string $scope): void
    {
        $site = $this->sites->forNode($node)->first(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope));

        if ($site instanceof DevelopmentSite) {
            throw new RuntimeConvergenceException(
                step: 'certificate-remove',
                errorCode: 'app-dev.certificate_in_use',
                message: "Certificate [{$scope}] is still named by the stored Caddy site [{$site->domain}] on node [{$node->name}].",
            );
        }

        AgentStep::run($this->agents, $node, 'certificates.remove-leaf', [
            'scope' => $scope,
            'home' => $this->accounts->resolve($node)->home,
        ], 'certificate-remove', 'app-dev.certificate_remove_failed', 'App development');
    }
}
