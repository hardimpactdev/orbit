<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\AppDev\DnsRequester;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Instances\InstanceProvisionProgress;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Doctor\ProductionInstanceInspectionExpectationFactory;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Route;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Collection;
use Throwable;

/** Stored application intent only. Every host observation runs on the exact Swamp worker. */
final readonly class ApplicationDoctorIntent
{
    /** @return array<string, list<array<string, mixed>>> */
    public function execute(Node $node): array
    {
        $instances = Instance::query()->with(['project', 'tasks', 'node.roles', 'node.cluster'])->where('node_id', $node->id)->orderBy('id')->get();
        $eligible = $instances->filter(static fn (Instance $instance): bool => $instance->status !== InstanceState::Removing
            && ! InstanceProvisionProgress::isInFlight($instance, TaskWorkspaceLifecycle::settledState($instance)));
        $instanceRows = [];
        foreach ($instances as $instance) {
            $settled = TaskWorkspaceLifecycle::settledState($instance);
            $row = [
                'id' => $instance->id, 'name' => $instance->name, 'status' => $instance->status->value,
                'settled_status' => $settled->value, 'source_layout' => $instance->source_layout,
                'provisioning' => InstanceProvisionProgress::isInFlight($instance, $settled),
                'updated_at' => $instance->updated_at?->toIso8601String(),
                'production' => $instance->placedOnAppProd(),
                'association_missing' => $this->associationMissing($instance),
                'association_shared' => $instance->placedOnAppProd() && $instance->selected_php_version !== null && $instances->contains(static fn (Instance $other): bool => $other->id !== $instance->id && $other->placedOnAppProd() && $other->selected_php_version !== null && (
                    $other->production_php_service === $instance->production_php_service || $other->production_php_pool === $instance->production_php_pool || $other->production_php_socket === $instance->production_php_socket
                )),
            ];
            try {
                $row['inspection'] = $instance->status === InstanceState::Removing || $row['provisioning'] ? null : $this->instanceInput($instance);
            } catch (Throwable) {
                $row['inspection'] = null;
            }
            $row['applications'] = [];
            if (! $instance->placedOnAppProd() && $instance->selected_php_version !== null) {
                foreach (RouteWebRoot::applicationUrlRoutes($instance, RouteWebRoot::applicationUrlCandidates($instance)) as $directory => $route) {
                    $row['applications'][] = ['directory' => (string) $directory, 'url' => 'https://'.$route->domain];
                }
                $row['checkout'] = $instance->checkout_path;
            }
            $instanceRows[] = $row;
        }
        $projects = [];
        foreach ($eligible->groupBy('project_id') as $checkouts) {
            $first = $checkouts->first();
            if (! $first instanceof Instance) {
                continue;
            }
            $project = $first->project;
            $valid = GitRepositoryOrigin::isValid($project->repository_url);
            $row = ['id' => $project->id, 'name' => $project->name, 'identity_matches' => ! $valid || GitRepositoryIdentity::derive($project->repository_url) === $project->repository_identity];
            try {
                $account = $checkouts->contains(static fn (Instance $instance): bool => ! $instance->placedOnAppProd()) ? new ManagedUserAccount($node->user, 'orbit-doctor-group', '/__orbit_doctor_home__') : null;
                $root = $account === null ? null : app(StorageRootResolver::class)->resolveApps(app(NodeSettingsNormalizer::class)->fromStored($node->settings), $account)->value;
                $row['inspection'] = [
                    'repository' => GitRepositoryOrigin::validate($project->repository_url),
                    'checkouts' => $checkouts->map(static fn (Instance $instance): array => [
                        'instance_id' => $instance->id, 'path' => $instance->checkout_path,
                        'root' => $instance->placedOnAppProd() ? $instance->production_home : $root,
                        'user' => $instance->placedOnAppProd() ? $instance->production_user : $node->user,
                        'production' => $instance->placedOnAppProd(),
                    ])->values()->all(),
                ];
            } catch (Throwable) {
                $row['inspection'] = null;
            }
            $projects[] = $row;
        }
        $clusters = NodeRole::query()->where('node_id', $node->id)->where('role', RoleName::Router)->where('status', LifecycleStatus::Active)->whereNotNull('cluster_id')->pluck('cluster_id');
        $reserved = app(ReservedPrivateHostname::class)->names();
        $served = Route::query()->where(static fn ($query) => $query->where('node_id', $node->id)
            ->orWhere(static fn ($query) => $query->whereIn('cluster_id', $clusters)->where('kind', '!=', 'custom_proxy'))
            ->orWhereHas('customProxy.route.cluster.ingressAssignment', static fn ($query) => $query->where('node_id', $node->id)))->orderBy('id')->get();
        $routeRows = [];
        foreach ($served as $route) {
            $proxy = $route->customProxy;
            $row = ['id' => $route->id, 'name' => $route->domain, 'status' => $route->status->value, 'reserved' => in_array($route->domain, $reserved, true), 'custom_proxy' => $route->isCustomProxy(), 'owner_node_id' => $proxy?->node_id, 'websocket' => []];
            foreach (WebSocketAppTarget::query()->with('route.targets.instance.project')->where('route_id', $route->id)->get()->unique('route_id') as $attachment) {
                if (! $attachment->route instanceof Route) {
                    continue;
                }
                foreach ($attachment->route->targets as $target) {
                    $instance = $target->instance;
                    $row['websocket'][] = ['node_id' => $instance->node_id, 'input' => $instance->placedOnAppProd() ? [
                        'user' => $instance->production_user, 'home' => $instance->production_home,
                        'application' => $instance->applicationDirectory(), 'php_version' => $instance->selected_php_version,
                    ] : null];
                }
            }
            if ($proxy !== null && $route->publication === RoutePublication::Private) {
                try {
                    $row['inspection'] = ['domain' => $route->domain, 'certificates' => "/etc/caddy/orbit-certificates/route-{$route->id}/current", 'upstream' => CustomProxyUpstream::parse($proxy->upstream)->authority(), 'dns_address' => $node->wireguard_ip];
                } catch (Throwable) {
                    $row['inspection'] = null;
                }
            }
            $routeRows[] = $row;
        }
        $checks = $this->routeChecks($node);

        return ['instances' => $instanceRows, 'projects' => $projects, 'routes' => $routeRows, 'application_route_checks' => $checks];
    }

    private function associationMissing(Instance $instance): bool
    {
        return $instance->placedOnAppProd() && $instance->selected_php_version !== null && array_any([
            $instance->production_php_service, $instance->production_php_pool, $instance->production_php_socket,
        ], static fn (?string $value): bool => ! is_string($value) || $value === '');
    }

    /** @return array<string, mixed> */
    private function instanceInput(Instance $instance): array
    {
        if (! $instance->placedOnAppProd()) {
            $account = new ManagedUserAccount($instance->node->user, 'orbit-doctor-group', '/__orbit_doctor_home__');

            return ['repository' => GitRepositoryOrigin::validate($instance->project->repository_url), 'checkout' => $instance->checkout_path,
                'root' => app(CheckoutRemovalBoundary::class)->instanceRoot($instance, $account)->value,
                'user' => $account->user, 'group' => $account->group, 'layout' => $instance->source_layout, 'starting_commit' => $instance->starting_commit ?? ''];
        }
        $expected = app(ProductionInstanceInspectionExpectationFactory::class)->make($instance);
        $runtime = $expected->runtime;
        $config = $expected->runtimeConfiguration;
        $initial = $expected->initialRuntimeConfiguration;

        return ['user' => $expected->user, 'home' => $expected->home, 'root' => $expected->root,
            'application_suffix' => ProductionApplicationPaths::suffix($expected->root),
            'environment_sha256' => hash('sha256', $expected->environment()),
            'caddy_sites' => $expected->caddySites, 'association' => $expected->associationMatches,
            'runtime' => $runtime !== null && $config !== null && $initial !== null ? [
                'version' => $runtime->version, 'marker' => $runtime->marker(), 'application' => $runtime->applicationDirectory(),
                'initial_application' => $runtime->applicationDirectory(initialRelease: true), 'configuration' => [
                    'main' => $config->main, 'pool' => $config->pool, 'initial_pool' => $initial->pool,
                    'local_defaults' => $config->localDefaults, 'master_ini' => $config->masterIni, 'unit' => $config->unit,
                ],
            ] : null];
    }

    /** @return list<array<string, mixed>> */
    private function routeChecks(Node $node): array
    {
        $checks = [];
        $siteFailed = false;
        try {
            $sites = app(DevelopmentSiteRepository::class)->forNode($node);
        } catch (Throwable) {
            $siteFailed = true;
            $sites = new Collection;
        }
        $eligibility = app(PublicRouteEligibility::class);
        foreach (Route::query()->with(['targets.instance.node.roles', 'targets.instance.project', 'targets.instance.tasks', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node', 'customProxy.node'])->orderBy('id')->get() as $route) {
            try {
                $cluster = $route->cluster;
                $router = $cluster !== null && $cluster->state === ClusterState::Active ? $eligibility->activeRouter($cluster) : null;
                $ingress = $cluster !== null ? $eligibility->servingIngress($cluster) : null;
                $public = $route->publication === RoutePublication::Public;
                if ($public && ! $route->isCustomProxy() && ! $eligibility->publicEdgeIsLive($route)) {
                    continue;
                }
                if (! $public && $route->status !== RouteStatus::Active) {
                    continue;
                }
                foreach ($route->targets as $target) {
                    $instance = $target->instance;
                    $settled = TaskWorkspaceLifecycle::settledState($instance);
                    if ($instance->status === InstanceState::Removing || InstanceProvisionProgress::isInFlight($instance, $settled) || ! in_array($instance->source_layout, ['checkout', 'worktree'], true)) {
                        continue;
                    }
                    $related = array_values(array_unique(array_filter([$public ? $ingress?->id : null, $router?->id])));
                    $base = ['id' => $instance->id, 'name' => $instance->name, 'family' => 'instance', 'owner_node_id' => $instance->node_id, 'related_node_ids' => $related];
                    if ($instance->node_id === $node->id && WebSocketAppTarget::query()->where('route_id', $route->id)->exists()) {
                        $checks[] = ['id' => $route->id, 'name' => $route->domain, 'family' => 'route', 'kind' => 'websocket', 'owner_node_id' => $instance->node_id, 'related_node_ids' => [],
                            'inspection' => $instance->placedOnAppProd() ? ['user' => $instance->production_user, 'home' => $instance->production_home, 'application' => $instance->applicationDirectory(), 'php_version' => $instance->selected_php_version] : null];
                    }
                    if ($instance->node_id === $node->id) {
                        $checks[] = [...$base, 'kind' => 'scope'];
                    }
                    if ($public && $ingress?->id === $node->id) {
                        $checks[] = [...$base, 'kind' => 'public', 'inspection' => $this->publicInput($node, $route, $sites), 'firewall' => $this->firewallInput($node, RoleName::Ingress)];
                    }
                    if ($public) {
                        continue;
                    }
                    if ($instance->node_id === $node->id) {
                        $site = $sites->first(static fn (DevelopmentSite $site): bool => $site->domain === $route->domain && ! $site->isProxy() && ! $site->publicListener);
                        $placement = app(RouteStateResolver::class)->forNode($node);
                        $own = $instance->routeTargets()->whereHas('route', static fn ($query) => $query->whereNull('web_root'));
                        $association = $route->hasWebRoot() ? $own->count() <= 1 : $own->count() === 1 && $own->where('route_id', $route->id)->exists();
                        $url = $instance->environmentValues()->where('env_key', 'APP_URL')->value('env_value');
                        $url = is_string($url) && $url !== '' ? str_replace(['{{instance.domain}}', '{{instance.environment}}'], [$route->domain, $instance->defaultAppEnv()], $url) : 'https://'.$route->domain;
                        $answers = app(ClusterRouterDnsSelection::class)->answers();
                        $dns = $route->cluster_id === null ? $node->wireguard_ip : ($answers['overrides'][DnsRequester::registered($node->id, (string) $node->wireguard_ip)->cacheKey()][$route->domain] ?? $router?->wireguard_ip);
                        $laravel = $route->hasWebRoot() || $instance->source_is_laravel === false ? 'skip' : ($instance->source_is_laravel === true ? 'check' : 'unknown');
                        $checks[] = [...$base, 'kind' => 'workload', 'routing_scope_matches' => $route->node_id === $placement->nodeId && $route->cluster_id === $placement->clusterId, 'association_matches' => $association,
                            'expects_firewall' => $node->roles->contains(fn ($role): bool => app(NodeFirewallRuleCatalog::class)->forRole($node, $role->role) !== []) || ($router !== null && app(NodeFirewallRuleCatalog::class)->forRole($router, RoleName::Router) !== []),
                            'laravel' => $laravel, 'url_sha256' => hash('sha256', $url), 'environment' => ($instance->usesProductionReleaseLayout() ? (string) $instance->production_home : $instance->applicationDirectory()).'/.env',
                            'inspection' => $site === null ? null : ['domain' => $site->domain, 'certificates' => $site->certificateDirectory(), 'projection' => ['kind' => 'workload', 'dns_address' => $dns, 'laravel' => ['kind' => 'skip']]]];
                    }
                    if ($router !== null && $router->id === $node->id && $router->id !== $instance->node_id) {
                        $site = $sites->first(static fn (DevelopmentSite $site): bool => $site->domain === $route->domain && $site->isProxy() && ! $site->publicListener);
                        $checks[] = [...$base, 'kind' => 'router', 'missing_site' => $site === null && ! $siteFailed, 'target_set_matches' => $route->targets->count() <= 1, 'expects_firewall' => app(NodeFirewallRuleCatalog::class)->forRole($node, RoleName::Router) !== [] || $instance->node->roles->contains(fn ($role): bool => app(NodeFirewallRuleCatalog::class)->forRole($instance->node, $role->role) !== []), 'inspection' => $site === null ? ($siteFailed ? null : []) : ['domain' => $route->domain, 'certificates' => $site->certificateDirectory(), 'projection' => ['kind' => 'router', 'upstreams' => array_values(array_filter($site->proxyAddresses(), static fn (string $address): bool => ! str_starts_with($address, 'unix/')))]]];
                    }
                }
                if ($public && $route->isCustomProxy() && $route->customProxy?->node_id === $node->id && $ingress !== null) {
                    $expected = [];
                    try {
                        $process = $route->customProxy->process;
                        if (! $process instanceof Process) {
                            throw new \InvalidArgumentException('The custom proxy Process is missing.');
                        }
                        $upstream = app(CustomProxyProcessListener::class)->resolvePublic($process, $ingress);
                        $candidates = [
                            ...array_map(static fn ($rule) => $rule->shape, app(NodeFirewallDesiredRules::class)->forNode($node)),
                            ...$node->firewallRules()->get()->map(static fn ($rule) => FirewallInspectionTarget::fromRule($rule)->shape)->all(),
                        ];
                        foreach ($candidates as $shape) {
                            if ($upstream->host === $node->wireguard_ip ? $shape->comment === 'orbit:wireguard-members' : $shape->action === 'allow' && $shape->source === $ingress->lan_ip && in_array($shape->destination, ['any', $upstream->host], true) && $shape->port === (string) $upstream->port && $shape->protocol === 'tcp' && $shape->inInterface === null) {
                                $expected[] = $this->shapeInput($shape);
                            }
                        }
                    } catch (Throwable) {
                        // Incomplete stored Process placement is drift; no host call is needed.
                    }
                    $checks[] = ['id' => $route->id, 'name' => $route->domain, 'family' => 'route', 'kind' => 'backend_firewall', 'owner_node_id' => $node->id,
                        'related_node_ids' => [$ingress->id], 'inspection' => ['expected' => $expected, 'exclusive_families' => [], 'stored' => false]];
                }
                if ($public && $route->isCustomProxy() && $ingress?->id === $node->id) {
                    $checks[] = ['id' => $route->id, 'name' => $route->domain, 'family' => 'route', 'kind' => 'public', 'owner_node_id' => $route->customProxy?->node_id,
                        'related_node_ids' => [], 'inspection' => $this->publicInput($node, $route, $sites),
                        'backend_missing' => $route->customProxy?->process === null, 'dns_address' => $node->wireguard_ip, 'firewall' => $this->firewallInput($node, RoleName::Ingress)];
                }
            } catch (Throwable) {
                foreach ($route->targets as $target) {
                    if ($target->instance->node_id === $node->id) {
                        $checks[] = ['id' => $target->instance->id, 'name' => $target->instance->name, 'family' => 'instance', 'kind' => 'workload', 'owner_node_id' => $node->id, 'related_node_ids' => [], 'inspection' => null];
                    }
                }
                if ($route->isCustomProxy() && ($route->customProxy?->node_id === $node->id || $route->cluster?->ingressAssignment?->node_id === $node->id)) {
                    $checks[] = ['id' => $route->id, 'name' => $route->domain, 'family' => 'route', 'kind' => 'public', 'owner_node_id' => $node->id, 'related_node_ids' => [], 'inspection' => null];
                }
            }
        }

        return $checks;
    }

    /**
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return array<string, mixed>|null
     */
    private function publicInput(Node $node, Route $route, Collection $sites): ?array
    {
        try {
            $site = $sites->first(static fn (DevelopmentSite $site): bool => $site->domain === $route->domain && $site->publicListener);
            if (! $site instanceof DevelopmentSite) {
                return null;
            }
            $forwarding = $site->localHttpUpstream === null ? [] : [$site->localHttpUpstream];
            foreach ($site->proxyAddresses() as $address) {
                if (! str_starts_with($address, 'unix/')) {
                    $forwarding[] = parse_url('https://'.$address, PHP_URL_HOST).':'.(parse_url('https://'.$address, PHP_URL_PORT) ?? 443);
                }
            }
            $expected = app(NodeCaddyfileRenderer::class)->render($node)->blocksFor($site->scope)[0] ?? null;

            return is_string($expected) ? ['domain' => $route->domain, 'expected_site' => $expected, 'certificates' => $site->certificateDirectory(), 'forwarding' => $forwarding] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function shapeInput(FirewallInspectionShape|UfwRuleShape $shape): array
    {
        return ['comment' => $shape->comment, 'action' => $shape->action, 'direction' => $shape->direction,
            'source' => $shape->source, 'destination' => $shape->destination, 'port' => $shape->port,
            'protocol' => $shape->protocol, 'in_interface' => $shape->inInterface, 'out_interface' => $shape->outInterface, 'family' => $shape->family];
    }

    /** @return array<string, mixed> */
    private function firewallInput(Node $node, RoleName $role): array
    {
        return ['expected' => array_map(static fn ($rule): array => $rule->shape->toAgentInput(), app(NodeFirewallRuleCatalog::class)->forRole($node, $role)), 'exclusive_families' => [], 'stored' => false];
    }
}
