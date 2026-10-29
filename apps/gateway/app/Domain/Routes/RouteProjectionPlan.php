<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;

/** Exact child intent from stored placement. Swamp owns the stage order and recovery. */
final readonly class RouteProjectionPlan
{
    public function __construct(private IngressSiteRepository $ingress, private DevelopmentSiteRepository $sites) {}

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
            $this->leaf($stages, 'workload-certificate', $instance->node, $scope, $route->domain);
            $production = $instance->placedOnAppProd();
            if ($production) {
                $this->child($stages, 'workload-firewall', 'route.workload_firewall', $instance->node_id, ['route_id' => $route->id, 'instance_id' => $instance->id], (string) $instance->id);
                if ($route->hasWebRoot()) {
                    $this->child($stages, 'prepare-web-root', 'instance.prepare_served_web_roots', $instance->node_id, ['instance_id' => $instance->id], (string) $instance->id);
                }
            }
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
                $this->leaf($stages, 'router-certificate', $router, $scope, $route->domain);
                $this->caddy($stages, 'router-caddy', $router->id, $route->id);
            }
        }
        if ($route->isCustomProxy() && $route->node_id !== null) {
            $node = Node::query()->findOrFail($route->node_id);
            $this->leaf($stages, 'workload-certificate', $node, "route-{$route->id}", $route->domain);
            $this->caddy($stages, 'workload-caddy', $node->id, $route->id);
        }
        $this->dns($stages, 'dns-published', $route->id);
        $this->publicEdge($stages, $route);

        return $this->plan($route, array_values($instances), $stages);
    }

    /** @return array<string, mixed> */
    public function targets(Operation $operation, Route $route, SetRouteTargetsData $proposal): array
    {
        $state = app(RouteTargetState::class);
        $detached = RouteExecutionProof::objects($operation->input['detached_instances'] ?? []);
        $capturedIds = array_map(static fn (array $snapshot): int => ValidatedData::integer($snapshot['instance_id']), $detached);
        $removedIds = ValidatedData::integerList($operation->input['removed_instance_ids']);
        sort($capturedIds);
        sort($removedIds);
        if ($capturedIds !== $removedIds) {
            throw new ResourceOperationException('route.target_conflict', 'Every removed Instance needs its admitted detached placement.', 409);
        }
        $prepared = $state->preparedInstances($route, $proposal);
        $stages = [];
        $relations = [];
        foreach ($prepared as [$instance, $destination]) {
            $instance->loadMissing('node');
            $destination->loadMissing('cluster.routerAssignment.node');
            $relations[] = ['route_id' => $destination->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id];
            $this->leaf($stages, 'target-workload-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change", $destination->domain);
            $this->child($stages, 'target-runtime-prepared', 'instance.prepare_php_runtime', $instance->node_id, ['instance_id' => $instance->id], (string) $instance->id);
            $this->caddy($stages, 'target-workload-caddy', $instance->node_id, $destination->id);
            $router = $destination->cluster?->routerAssignment?->node;
            if ($router instanceof Node && $router->id !== $instance->node_id) {
                $this->leaf($stages, 'target-router-certificates', $router, "route-{$destination->id}-router", $destination->domain);
                $this->child($stages, 'target-firewall-prepared', 'route.lan_converge', $instance->node_id,
                    ['route_id' => $destination->id, 'instance_id' => $instance->id, 'router_node_id' => $router->id], "route-{$destination->id}-instance-{$instance->id}");
                $this->child($stages, 'target-workload-verified', 'route.workload_verify', $router->id,
                    ['route_id' => $destination->id, 'instance_id' => $instance->id], "route-{$destination->id}-instance-{$instance->id}");
            }
            $this->leaf($stages, 'target-live-certificates', $instance->node, "app-instance-{$instance->id}", $destination->domain);
            $this->caddy($stages, 'target-live-caddy', $instance->node_id, $destination->id);
            $this->removeLeaf($stages, 'target-staging-cleanup', $instance->node, "app-instance-{$instance->id}-hostname-change");
            $this->caddy($stages, 'rollback-preparations-caddy', $instance->node_id, $destination->id);
            $this->removeLeaf($stages, 'rollback-preparations-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change");
        }
        foreach ($state->synchronizedInstances($route, $proposal) as $instance) {
            $this->child($stages, 'environment-synchronized', 'instance.sync_environment', $instance->node_id,
                ['instance_id' => $instance->id, 'route_domain' => 'authoritative'], (string) $instance->id);
        }
        $route->loadMissing('cluster.routerAssignment');
        $routerId = $route->cluster?->routerAssignment?->node_id;
        if ($routerId !== null) {
            $this->caddy($stages, 'router-published', $routerId, $route->id);
        }
        $vacatedIds = $state->vacatedRouteIds($route, $proposal);
        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->routeId !== null) {
                $vacatedIds[] = $disposition->routeId;
            }
            if ($disposition->remove) {
                $instance = Instance::query()->findOrFail($disposition->instanceId);
                $snapshot = array_find($detached, static fn (array $value): bool => $value['instance_id'] === $instance->id);
                if ($snapshot === null || $snapshot['node_id'] !== $instance->node_id || $snapshot['checkout_path'] !== $instance->checkout_path) {
                    throw new ResourceOperationException('route.target_conflict', 'The admitted detached Instance Node or checkout path changed.', 409);
                }
                $this->child($stages, 'removal', 'instance.destroy', $instance->node_id,
                    ['instance_id' => $instance->id, 'force' => true], (string) $instance->id);
            }
        }
        foreach (Route::query()->with('cluster.routerAssignment')->whereKey(array_values(array_unique($vacatedIds)))->get() as $vacated) {
            $vacatedRouter = $vacated->cluster?->routerAssignment?->node_id;
            if ($vacatedRouter !== null && $vacated->id !== $route->id) {
                $this->caddy($stages, 'vacated-published', $vacatedRouter, $vacated->id);
            }
        }
        $plan = $this->plan($route, array_map(static fn (array $pair): Instance => $pair[0], $prepared), $stages);
        $plan['placements'] = RouteExecutionProof::objects($operation->input['instance_placements']);
        $plan['detached_instances'] = $detached;
        $plan['prepared_targets'] = $relations;
        $plan['target_instance_ids'] = $operation->input['target_instance_ids'];
        $plan['previous_target_instance_ids'] = $operation->input['previous_target_instance_ids'];
        $plan['removed_instance_ids'] = $operation->input['removed_instance_ids'];
        $plan['vacated_route_ids'] = array_values(array_unique($vacatedIds));

        return $plan;
    }

    /** @return array<string, mixed> */
    public function clusterRouter(Operation $operation): array
    {
        $cluster = Cluster::query()->findOrFail(ValidatedData::integer($operation->input['cluster_id']));
        $assignments = NodeRole::query()->with('node')->where('cluster_id', $cluster->id)->where('role', RoleName::Router)->orderBy('id')->get();
        $candidateId = $operation->input['candidate_role_id'] ?? null;
        $candidate = is_int($candidateId) ? $assignments->firstWhere('id', $candidateId) : null;
        $stages = [];
        $routes = Route::query()->with(['targets.instance.node'])->where('cluster_id', $cluster->id)
            ->whereIn('status', [RouteStatus::Active, RouteStatus::Activating, RouteStatus::Pending, RouteStatus::Failed])->orderBy('id')->get();
        $old = $assignments->filter(static fn (NodeRole $row): bool => $row->id !== $candidateId)->values();
        $mode = $operation->kind === 'cluster.router_unset' ? 'router_unset' : 'router_set';
        if ($mode === 'router_set') {
            if ($candidate === null || $candidate->node_id !== $operation->node_id) {
                throw new ResourceOperationException('cluster.router_transition_conflict', 'The recorded Router candidate assignment changed.', 409);
            }
            $same = $candidate->status === LifecycleStatus::Active;
            if ($same && $old->isEmpty()) {
                $mode = 'router_noop';
            } elseif (! $same) {
                $this->child($stages, 'baseline', 'node.role_converge', $candidate->node_id, ['assignment_id' => $candidate->id], 'router');
            }
            foreach ($routes as $route) {
                $renders = $route->sites_published || ($route->status === RouteStatus::Pending && $route->replaces_route_id !== null
                    && RouteCertificateStaging::reached($route, RouteReplacementStep::RouterCertificate));
                $remote = $route->isAnalyticsTracking();
                foreach ($route->targets as $target) {
                    $instance = $target->instance;
                    if ($instance->node_id === $candidate->node_id) {
                        continue;
                    }
                    $remote = true;
                    $this->child($stages, 'firewall-policy', 'route.lan_converge', $instance->node_id,
                        ['route_id' => $route->id, 'instance_id' => $instance->id, 'router_node_id' => $candidate->node_id], "route-{$route->id}-instance-{$instance->id}");
                    if ($renders) {
                        $this->child($stages, 'workload-verify', 'route.workload_verify', $candidate->node_id,
                            ['route_id' => $route->id, 'instance_id' => $instance->id], "route-{$route->id}-instance-{$instance->id}");
                    }
                }
                if ($remote && $renders) {
                    $scope = "route-{$route->id}-router".(RouteCertificateStaging::router($route) ? '-hostname-change' : '');
                    $this->leaf($stages, 'router-certificate', $candidate->node, $scope, $route->domain);
                }
                $this->caddy($stages, 'router-caddy', $candidate->node_id, $route->id);
                $this->caddy($stages, 'withdraw-candidate-sites', $candidate->node_id, $route->id);
                foreach (["route-{$route->id}-router", "route-{$route->id}-router-hostname-change"] as $scope) {
                    $this->removeLeaf($stages, 'remove-candidate-certificates', $candidate->node, $scope);
                }
                foreach ($route->targets as $target) {
                    if ($target->instance->node_id !== $candidate->node_id) {
                        $this->child($stages, 'remove-candidate-firewall', 'route.router_lan_retire', $target->instance->node_id,
                            ['route_id' => $route->id, 'router_node_id' => $candidate->node_id], "route-{$route->id}-node-{$target->instance->node_id}");
                    }
                }
            }
        }
        foreach ($old as $assignment) {
            foreach ($routes as $route) {
                $this->caddy($stages, 'withdraw-old-sites', $assignment->node_id, $route->id);
                foreach (["route-{$route->id}-router", "route-{$route->id}-router-hostname-change"] as $scope) {
                    $this->removeLeaf($stages, 'remove-old-certificates', $assignment->node, $scope);
                }
                foreach ($route->targets as $target) {
                    if ($target->instance->node_id !== $assignment->node_id) {
                        $this->child($stages, 'remove-old-firewall', 'route.router_lan_retire', $target->instance->node_id,
                            ['route_id' => $route->id, 'router_node_id' => $assignment->node_id], "route-{$route->id}-router-{$assignment->node_id}-node-{$target->instance->node_id}");
                    }
                }
            }
            $this->child($stages, 'remove-old-baseline', 'node.role_remove', $assignment->node_id,
                ['role' => 'router', 'purge_data' => false, 'offline' => false], 'router-'.$assignment->id);
        }
        foreach (['dns-publication', 'prune-dns', 'restore-dns'] as $phase) {
            $this->dns($stages, $phase, $cluster->id);
        }
        $nodes = [];
        foreach ($stages as $children) {
            foreach ($children as $child) {
                $nodes[] = ValidatedData::integer($child['node_id']);
            }
        }

        return ['mode' => $mode, 'cluster_id' => $cluster->id, 'candidate_role_id' => $candidate?->id,
            'old_role_ids' => $old->modelKeys(), 'assignment_ids' => $assignments->modelKeys(),
            'node_ids' => array_values(array_unique($nodes)), 'route_ids' => $routes->modelKeys(),
            'stages' => array_map(array_values(...), $stages)];
    }

    /** @return array<string, mixed> */
    public function publication(Operation $operation, Route $route): array
    {
        $desired = RoutePublication::from(ValidatedData::string($operation->input['publication']));
        $candidate = clone $route;
        $candidate->publication = $desired;
        $stages = [];
        $active = in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true);
        $activation = $active && $desired === RoutePublication::Public && new PublicRouteEligibility()->canStartActivation($candidate);
        if ($activation) {
            $this->publicEdge($stages, $candidate);
        } elseif ($active && $desired === RoutePublication::Private
            && (new PublicRouteEligibility()->canActivate($route) || new PublicRouteEligibility()->publicEdgeIsLive($route))) {
            $ingress = $this->ingress->ingressNode($route);
            $this->caddy($stages, 'public-withdraw-caddy', $ingress->id, $route->id);
            $router = $this->ingress->routerNode($route);
            if ($router->id !== $ingress->id) {
                $this->caddy($stages, 'public-withdraw-caddy', $router->id, $route->id);
            }
            $this->child($stages, 'public-withdraw-firewall', 'route.public_firewall', $ingress->id, ['route_id' => $route->id], 'ingress');
            $this->removeLeaf($stages, 'public-withdraw-certificates', $ingress, "route-{$route->id}-ingress");
        }
        $plan = $this->plan($candidate, [], $stages);
        if ($activation) {
            $restore = [];
            $ingress = $this->ingress->ingressNode($route);
            $this->caddy($restore, 'rollback-public-edge', $ingress->id, $route->id);
            $router = $this->ingress->routerNode($route);
            if ($router->id !== $ingress->id) {
                $this->caddy($restore, 'rollback-public-edge', $router->id, $route->id);
            }
            $this->child($restore, 'rollback-public-firewall', 'route.public_firewall', $ingress->id, ['route_id' => $route->id], 'ingress');
            $plan['stages'] = [...ValidatedData::object($plan['stages']), ...array_map(array_values(...), $restore)];
        }
        $plan['activate_public'] = $activation;
        $plan['withdraw_public'] = isset($stages['public-withdraw-caddy']);

        return $plan;
    }

    /** @return array<string, mixed> */
    public function webRoot(Operation $operation, Route $route): array
    {
        $candidate = clone $route;
        $candidate->web_root = ValidatedData::nullableString($operation->input['web_root']);
        $plan = $this->converge($operation, $candidate);
        $allowed = ['workload-certificate', 'prepare-web-root', 'synchronize-web-root-urls', 'production-runtime', 'workload-prepared', 'workload-caddy'];
        $plan['stages'] = array_intersect_key(ValidatedData::object($plan['stages']), array_flip($allowed));
        $instance = $candidate->targets->firstOrFail()->instance;
        $urls = [];
        $this->child($urls, 'synchronize-web-root-urls', 'instance.sync_environment', $instance->node_id,
            ['instance_id' => $instance->id, 'route_domain' => 'authoritative'], (string) $instance->id);
        $plan['stages']['synchronize-web-root-urls'] = array_values($urls['synchronize-web-root-urls']);
        $cleanup = [];
        if ($route->web_root !== null && $candidate->web_root === null) {
            $this->removeLeaf($cleanup, 'web-root-certificate-remove', $instance->node, "route-{$route->id}");
        }
        $plan['stages'] = [...ValidatedData::object($plan['stages']), ...array_map(array_values(...), $cleanup)];
        $restore = $this->converge($operation, $route);
        $restoreStages = ValidatedData::object($restore['stages']);
        $restoreStages['synchronize-web-root-urls'] = $plan['stages']['synchronize-web-root-urls'];
        foreach (array_intersect_key($restoreStages, array_flip($allowed)) as $stage => $children) {
            $plan['stages'][$stage === 'synchronize-web-root-urls' ? 'synchronize-web-root-urls.rollback' : 'rollback-'.$stage] = array_map(static function (array $child): array {
                $key = ValidatedData::string($child['key']);
                $child['key'] = str_starts_with($key, 'synchronize-web-root-urls.')
                    ? str_replace('synchronize-web-root-urls.', 'synchronize-web-root-urls.rollback.', $key)
                    : 'rollback-'.$key;

                return $child;
            }, RouteExecutionProof::objects($children));
        }
        $plan['web_root'] = $candidate->hasWebRoot();
        $plan['previous_web_root'] = $route->web_root;
        $plan['requested_web_root'] = $candidate->web_root;
        $plan['changed'] = $candidate->web_root !== $route->web_root;

        return $plan;
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
        $currentHostId = $host->id;
        if ($operation->kind === 'route.analytics_host_withdraw') {
            $route->loadMissing(['transitionNode', 'transitionCluster.routerAssignment.node']);
            if (! in_array($operation->node_id, array_filter([$host->id, $route->transitionNode?->id, $route->transitionCluster?->routerAssignment?->node_id], is_int(...)), true)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Host withdrawal requires a recorded current or retiring placement.', 422);
            }
            $host = Node::query()->findOrFail($operation->node_id);
            $this->caddy($stages, 'host-withdraw', $host->id, $route->id);
            $scope = "route-{$route->id}-router";
            if ($host->id !== $currentHostId) {
                $this->removeLeaf($stages, 'host-certificate-remove', $host, $scope);
            }
        } elseif ($operation->kind === 'route.analytics_remove') {
            return $this->withdraw($operation, $route);
        } else {
            if ($operation->node_id !== $host->id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The analytics Operation must use its stored serving Node.', 422);
            }
            $this->leaf($stages, 'host-certificate', $host, "route-{$route->id}-router", $route->domain);
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
            $this->leaf($cleanup, 'prepare-cleanup', $instance->node, "app-instance-{$instance->id}", $candidate->domain);
            $this->caddy($cleanup, 'cleanup-caddy', $instance->node_id, $candidate->id);
            $this->removeLeaf($cleanup, 'cleanup-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change");
            $this->caddy($cleanup, 'rollback-caddy', $instance->node_id, $candidate->id);
            $this->removeLeaf($cleanup, 'rollback-certificates', $instance->node, "app-instance-{$instance->id}-hostname-change");
        }
        $router = $candidate->cluster?->routerAssignment?->node;
        if ($router instanceof Node) {
            $this->leaf($cleanup, 'prepare-cleanup', $router, "route-{$candidate->id}-router", $candidate->domain);
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
        if ($router instanceof Node) {
            foreach ($candidate->targets as $target) {
                if ($target->instance->node_id !== $router->id) {
                    $this->child($cleanup, 'verify-current-workload', 'route.workload_verify', $router->id, ['route_id' => $candidate->id, 'instance_id' => $target->instance_id], (string) $target->instance_id);
                }
            }
        }
        if ($candidate->publication === RoutePublication::Public) {
            $ingress = $this->ingress->ingressNode($candidate);
            $this->caddy($cleanup, 'rollback-public-edge', $ingress->id, $candidate->id);
            $this->child($cleanup, 'rollback-public-edge', 'route.public_firewall', $ingress->id, ['route_id' => $candidate->id], 'ingress-firewall');
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
        $route->loadMissing(['node', 'generationBasisNode', 'targets.instance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
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
            } elseif ($route->isAnalyticsTracking()) {
                $this->removeLeaf($stages, 'certificates', $route->node, "route-{$route->id}-router");
            }
        }
        if ($route->generationBasisNode instanceof Node) {
            $basis = $route->generationBasisNode;
            $nodes[$basis->id] = $basis;
            $this->child($stages, 'firewall', 'route.lan_remove', $basis->id, ['route_id' => $route->id], "node-{$basis->id}");
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

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function withMetrics(array $plan): array
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
            ->whereHas('node', static fn (Builder $query): Builder => $query->where('status', LifecycleStatus::Active))->limit(2)->get();
        if ($assignments->count() > 1) {
            throw new ResourceOperationException('metrics.assignment_drift', 'The Metrics hook requires one active serving assignment.', 409);
        }
        $assignment = $assignments->first();
        if ($assignment !== null) {
            $stages = ValidatedData::object($plan['stages']);
            $stages['metrics-reconciled'] = [['key' => 'metrics-reconciled.fleet', 'kind' => 'metrics.reconcile',
                'node_id' => $assignment->node_id, 'input' => []]];
            $plan['stages'] = $stages;
        }

        return $plan;
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
        if (! $route->isCustomProxy()) {
            $router = $this->ingress->routerNode($route);
            if ($router->id !== $ingress->id) {
                $this->caddy($stages, 'public-activated', $router->id, $route->id);
            }
            foreach ($route->targets as $target) {
                if ($target->instance->node_id !== $router->id) {
                    $this->child($stages, 'public-edge-verified', 'route.workload_verify', $router->id,
                        ['route_id' => $route->id, 'instance_id' => $target->instance_id], 'workload-'.$target->instance_id);
                }
            }
        }
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
    private function leaf(array &$stages, string $stage, Node $node, string $scope, string $domain): void
    {
        $this->child($stages, $stage, 'certificate.converge', $node->id,
            ['scope' => $scope, 'domain' => $domain], "node-{$node->id}-{$scope}");
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
            ['scope' => $scope], "node-{$node->id}-{$scope}");
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
