<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

/** Exact child intent from stored placement. Swamp owns the stage order and recovery. */
final readonly class RouteProjectionPlan
{
    public function __construct(private ManagedUserAccountResolver $accounts, private LeafCertificateSigner $signer,
        private IngressSiteRepository $ingress, private DevelopmentSiteRepository $sites) {}

    /** @return array<string, mixed> */
    public function converge(Operation $operation, Route $route, ?Instance $selected = null): array
    {
        $route->loadMissing(['targets.instance.node', 'cluster.routerAssignment.node']);
        $instances = $selected === null ? $route->targets->map(static fn (RouteTarget $target): Instance => $target->instance)->all() : [$selected];
        foreach ($instances as $instance) {
            if (! $route->targets->contains('instance_id', $instance->id)) {
                throw new ResourceOperationException('route.target_conflict', 'The Instance must be a stored Route target.', 409);
            }
        }
        $router = $route->cluster?->routerAssignment?->node;
        if ($route->cluster_id !== null && ! $router instanceof Node) {
            throw new ResourceOperationException('cluster.router_required', 'The Cluster Route requires a recorded Router.', 409);
        }
        $stages = [];
        foreach ($instances as $instance) {
            $instance->loadMissing('node');
            $scope = $route->hasWebRoot() ? "route-{$route->id}" : "app-instance-{$instance->id}";
            if (RouteCertificateStaging::workload($route)) {
                $scope = "app-instance-{$instance->id}-hostname-change";
            }
            $this->leaf($stages, 'workload-certificate', $operation, $instance->node, $scope, $route->domain);
            $production = $instance->placedOnAppProd();
            if ($route->hasWebRoot()) {
                $this->child($stages, 'synchronize-web-root-urls', 'instance.sync_environment', $instance->node_id,
                    ['instance_id' => $instance->id, 'route_domain' => 'authoritative'], (string) $instance->id);
            }
            $this->child($stages, $production ? 'production-runtime' : 'workload-prepared',
                $production ? 'instance.prepare_php_runtime' : 'route.workload_prepare', $instance->node_id,
                $production ? ['instance_id' => $instance->id] : ['route_id' => $route->id, 'instance_id' => $instance->id], "instance-{$instance->id}");
            $this->caddy($stages, 'workload-caddy', $instance->node_id, $route->id);
            if ($router instanceof Node && $router->id !== $instance->node_id) {
                $this->child($stages, 'firewall-policy', 'route.lan_converge', $instance->node_id,
                    ['route_id' => $route->id, 'instance_id' => $instance->id, 'router_node_id' => $router->id], "instance-{$instance->id}");
                $this->child($stages, 'workload-verified', 'route.workload_verify', $router->id,
                    ['route_id' => $route->id, 'instance_id' => $instance->id], "instance-{$instance->id}");
                $scope = "route-{$route->id}-router".(RouteCertificateStaging::router($route) ? '-hostname-change' : '');
                $this->leaf($stages, 'router-certificate', $operation, $router, $scope, $route->domain);
                $this->caddy($stages, 'router-caddy', $router->id, $route->id);
            }
        }
        if ($route->isCustomProxy() && $route->node_id !== null) {
            $node = Node::query()->findOrFail($route->node_id);
            $this->leaf($stages, 'workload-certificate', $operation, $node, "route-{$route->id}", $route->domain);
            $this->caddy($stages, 'workload-caddy', $node->id, $route->id);
        }
        $this->dns($stages, 'dns-published', $route->id);
        $this->publicEdge($stages, $route);

        return $this->plan($route, array_values($instances), $stages);
    }

    /** @return array<string, mixed> */
    public function analytics(Operation $operation, Route $route): array
    {
        if (! $route->isAnalyticsTracking()) {
            throw new ResourceOperationException('route.kind_unsupported', 'An analytics child requires a stored tracking Route.', 409);
        }
        $host = $this->ingress->routerNode($route);
        $stages = [];
        if ($operation->kind !== 'route.analytics_host_withdraw' && $operation->node_id !== $host->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The analytics Operation must use its recorded serving Node.', 422);
        }
        if ($operation->kind === 'route.analytics_host_withdraw') {
            $route->loadMissing(['transitionNode', 'transitionCluster.routerAssignment.node']);
            if (! in_array($operation->node_id, array_filter([$host->id, $route->transitionNode?->id, $route->transitionCluster?->routerAssignment?->node_id], is_int(...)), true)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Host withdrawal requires a recorded current or retiring placement.', 422);
            }
            $host = Node::query()->findOrFail($operation->node_id);
            $this->caddy($stages, 'host-withdraw', $host->id, $route->id);
            $scope = "route-{$route->id}-router";
            if (! $this->sites->forNode($host)->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
                $this->removeLeaf($stages, 'host-certificate-remove', $host, $scope);
            }
        } elseif ($operation->kind === 'route.analytics_remove') {
            return $this->withdraw($operation, $route);
        } else {
            if ($operation->node_id !== $host->id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The analytics Operation must use its stored serving Node.', 422);
            }
            $this->leaf($stages, 'host-certificate', $operation, $host, "route-{$route->id}-router", $route->domain);
            if ($operation->kind === 'route.analytics_publish') {
                $this->caddy($stages, 'host-caddy', $host->id, $route->id);
                $this->dns($stages, 'dns-published', $route->id);
                $this->publicEdge($stages, $route);
            }
        }

        return $this->plan($route, [], $stages);
    }

    /** @return array<string, mixed> */
    public function replacement(Operation $operation, Route $current, Route $candidate): array
    {
        if ($current->replaced_by_route_id !== $candidate->id || $candidate->replaces_route_id !== $current->id) {
            throw new ResourceOperationException('route.domain_change_conflict', 'The recorded Route replacement pair changed.', 409);
        }
        $plan = $this->converge($operation, $candidate);
        $stages = ValidatedData::object($plan['stages']);
        unset($stages['workload-prepared'], $stages['production-runtime'], $stages['synchronize-web-root-urls']);
        $candidate->loadMissing(['targets.instance.node', 'cluster.routerAssignment.node']);
        // Each stage has distinct keyed children so Caddy withdrawal settles before leaf removal.
        $cleanup = [];
        foreach ($candidate->targets as $target) {
            $instance = $target->instance;
            $environment = $instance->placedOnAppProd() ? 'environment-synchronized' : 'laravel-url';
            foreach ([$environment => 'candidate', 'rollback-environment' => 'authoritative', 'synchronize-current-environment' => 'authoritative'] as $stage => $domain) {
                $this->child($cleanup, $stage, 'instance.sync_environment', $instance->node_id,
                    ['instance_id' => $instance->id, 'route_domain' => $domain], (string) $instance->id);
            }
            $this->leaf($cleanup, 'prepare-cleanup', $operation, $instance->node, "app-instance-{$instance->id}", $candidate->domain);
            $this->caddy($cleanup, 'cleanup-caddy', $instance->node_id, $candidate->id);
            $this->removeLeaf($cleanup, 'cleanup-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change");
            $this->caddy($cleanup, 'rollback-caddy', $instance->node_id, $candidate->id);
            $this->removeLeaf($cleanup, 'rollback-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change");
        }
        $router = $candidate->cluster?->routerAssignment?->node;
        if ($router instanceof Node) {
            $this->leaf($cleanup, 'prepare-cleanup', $operation, $router, "route-{$candidate->id}-router", $candidate->domain);
        }
        foreach ([$current, $candidate] as $placement) {
            $placement->loadMissing('cluster.routerAssignment.node');
            $host = $placement->cluster?->routerAssignment?->node;
            if ($host instanceof Node) {
                $this->caddy($cleanup, 'cleanup-caddy', $host->id, $candidate->id);
                $this->caddy($cleanup, 'rollback-caddy', $host->id, $candidate->id);
                $this->removeLeaf($cleanup, 'cleanup-certificates', $host, "route-{$placement->id}-router-hostname-change");
                if ($placement->id === $current->id) {
                    $this->removeLeaf($cleanup, 'cleanup-certificates', $host, "route-{$current->id}-router");
                } else {
                    $this->removeLeaf($cleanup, 'rollback-certificates', $host, "route-{$candidate->id}-router-hostname-change");
                }
            }
        }
        $this->dns($cleanup, 'rollback-dns', $candidate->id);
        $plan['stages'] = [...$stages, ...array_map(array_values(...), $cleanup)];
        $plan['route_id'] = $current->id;
        $plan['candidate_route_id'] = $candidate->id;

        return $plan;
    }

    /** @return array<string, mixed> */
    public function withdraw(Operation $operation, Route $route): array
    {
        $route->loadMissing(['node', 'targets.instance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
        $stages = [];
        $this->dns($stages, 'dns', $route->id);
        $nodes = [];
        foreach ($route->targets as $target) {
            $instance = $target->instance;
            $nodes[$instance->node_id] = $instance->node;
            if (! $instance->placedOnAppProd()) {
                $this->child($stages, 'php', 'route.shared_php_withdraw', $instance->node_id, ['route_id' => $route->id], "node-{$instance->node_id}");
            } elseif ($route->hasWebRoot()) {
                $this->child($stages, 'php', 'instance.prepare_php_runtime', $instance->node_id, ['instance_id' => $instance->id], "instance-{$instance->id}");
            }
            $this->child($stages, 'firewall', 'route.lan_remove', $instance->node_id, ['route_id' => $route->id], "node-{$instance->node_id}");
            if ($route->hasWebRoot()) {
                $this->removeLeaf($stages, 'certificates', $instance->node, "route-{$route->id}");
            }
        }
        if ($route->node instanceof Node) {
            $nodes[$route->node->id] = $route->node;
            if ($route->isCustomProxy()) {
                $this->removeLeaf($stages, 'certificates', $route->node, "route-{$route->id}");
            }
        }
        $router = $route->cluster?->routerAssignment?->node;
        if ($router instanceof Node) {
            $nodes[$router->id] = $router;
            foreach (["route-{$route->id}-router", "route-{$route->id}-router-hostname-change"] as $scope) {
                $this->removeLeaf($stages, 'certificates', $router, $scope);
            }
        }
        $ingress = $route->cluster?->ingressAssignment?->node;
        if ($route->publication === RoutePublication::Public && $ingress instanceof Node) {
            $nodes[$ingress->id] = $ingress;
            $this->child($stages, 'firewall', 'route.public_firewall', $ingress->id, ['route_id' => $route->id], "node-{$ingress->id}");
        }
        foreach ($nodes as $node) {
            $this->caddy($stages, 'caddy', $node->id, $route->id);
        }

        return $this->plan($route, array_values($route->targets->map(static fn (RouteTarget $target): Instance => $target->instance)->all()), $stages);
    }

    /** @return array<string, mixed> */
    public function retireSource(Operation $operation, InstanceTransfer $transfer): array
    {
        $transfer->loadMissing(['sourceNode', 'instance.node']);
        $route = Route::query()->findOrFail($transfer->destination_route_id ?? $transfer->source_route_id);
        if ($transfer->cutover_at === null || $transfer->source_router_node_id === null || $operation->node_id !== $transfer->source_node_id) {
            throw new ResourceOperationException('instance.transfer_source_router_unknown', 'Record the source Router and cutover before retiring source projections.', 409);
        }
        $sourceRouter = Node::query()->findOrFail($transfer->source_router_node_id);
        $stages = [];
        foreach (array_unique([$transfer->source_node_id, $sourceRouter->id, $transfer->instance->node_id, $this->ingress->routerNode($route)->id]) as $nodeId) {
            $this->caddy($stages, 'rebuild-transfer-caddy', $nodeId, $route->id);
        }
        $this->child($stages, 'source-php-pools', 'route.transfer_php_withdraw', $transfer->source_node_id, ['transfer_id' => $transfer->id], 'source-php');
        $this->dns($stages, 'dns-published', $route->id);
        $this->unusedLeaf($stages, 'remove-unused-source-certificate', $transfer->sourceNode, "app-instance-{$transfer->instance_id}");
        foreach ([$transfer->source_route_id, ...($transfer->web_root_route_ids ?? [])] as $routeId) {
            $this->child($stages, 'remove-source-firewall', 'route.transfer_lan_remove', $transfer->source_node_id, ['transfer_id' => $transfer->id, 'route_id' => $routeId], "route-{$routeId}");
            $this->unusedLeaf($stages, 'remove-unused-source-router-certificate', $sourceRouter, "route-{$routeId}-router");
            if ($routeId !== $transfer->source_route_id) {
                $this->unusedLeaf($stages, 'remove-source-web-root-artifacts', $transfer->sourceNode, "route-{$routeId}");
            }
        }

        return $this->plan($route, [$transfer->instance], $stages);
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function publicEdge(array &$stages, Route $route): void
    {
        if ($route->publication !== RoutePublication::Public) {
            return;
        }
        $ingress = $this->ingress->ingressNode($route);
        // Public TLS stays Caddy automatic HTTPS. No Orbit leaf is issued on the Ingress.
        $this->child($stages, 'public-edge-verified', 'route.public_verify', $ingress->id, ['route_id' => $route->id], 'ingress');
        $this->caddy($stages, 'public-activated', $ingress->id, $route->id);
        $this->child($stages, 'ingress-firewall', 'route.public_firewall', $ingress->id, ['route_id' => $route->id], 'ingress');
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function dns(array &$stages, string $stage, int $routeId): void
    {
        $nodes = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn (Builder $query): Builder => $query
            ->whereIn('role', [RoleName::Vpn, RoleName::Gateway])->where('status', LifecycleStatus::Active))->orderBy('id')->get();
        foreach ($nodes as $node) {
            $this->child($stages, $stage, 'route.private_dns_publish', $node->id, ['reason' => "Route {$routeId} {$stage}"], "node-{$node->id}");
        }
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function caddy(array &$stages, string $stage, int $nodeId, int $routeId): void
    {
        $this->child($stages, $stage, 'route.caddy_converge', $nodeId, ['reason' => "Route {$routeId} {$stage}"], "node-{$nodeId}");
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function leaf(array &$stages, string $stage, Operation $operation, Node $node, string $scope, string $domain): void
    {
        $account = $this->accounts->resolve($node);
        $this->child($stages, $stage, 'certificate.converge', $node->id, ['scope' => $scope, 'domain' => $domain,
            'version' => substr(hash('sha256', "{$operation->id}:{$stage}:{$node->id}:{$scope}"), 0, 16),
            'root_hash' => hash('sha256', $this->signer->rootCertificate()), 'user' => $account->user, 'home' => $account->home], "node-{$node->id}-{$scope}");
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function unusedLeaf(array &$stages, string $stage, Node $node, string $scope): void
    {
        if (! $this->sites->forNode($node)->contains(static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))) {
            $this->removeLeaf($stages, $stage, $node, $scope);
        }
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages */
    private function removeLeaf(array &$stages, string $stage, Node $node, string $scope): void
    {
        $this->child($stages, $stage, 'certificate.remove', $node->id,
            ['scope' => $scope, 'home' => $this->accounts->resolve($node)->home], "node-{$node->id}-{$scope}");
    }

    /** @param array<string, array<string, array<string, mixed>>> $stages
     * @param  array<string, mixed>  $input
     */
    private function child(array &$stages, string $stage, string $kind, int $nodeId, array $input, string $key): void
    {
        $stages[$stage][$key] = ['key' => "{$stage}.{$key}", 'kind' => $kind, 'node_id' => $nodeId, 'input' => $input];
    }

    /** @param list<Instance> $instances
     * @param  array<string, array<string, array<string, mixed>>>  $stages
     * @return array<string, mixed>
     */
    private function plan(Route $route, array $instances, array $stages): array
    {
        return ['route_id' => $route->id, 'domain' => $route->domain, 'publication' => $route->publication->value,
            'target_instance_ids' => array_map(static fn (Instance $instance): int => $instance->id, $instances),
            'previous_target_instance_ids' => array_map(static fn (Instance $instance): int => $instance->id, $instances),
            'placements' => array_map(static fn (Instance $instance): array => ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'production' => $instance->placedOnAppProd()], $instances),
            'stages' => array_map(array_values(...), $stages)];
    }
}
