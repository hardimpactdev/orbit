<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\PublishPublicRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteTarget;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;

function pendingNodeRouteFixture(int $projectId, int $nodeId, string $domain): Route
{
    return Route::query()->create([
        'project_id' => $projectId,
        'node_id' => $nodeId,
        'domain' => $domain,
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
}

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = route_node('dev-one', '10.44.0.2', 'one.test');
    $this->target = route_instance($this->orbitApp, $this->node, 'main');
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(RouteRemovalProjector::class, $this->removal);
});

it('refuses a package Instance whose repository root is not a supported Route web root', function (): void {
    $this->orbitApp->update([
        'type' => ProjectType::NodePackage,
        'root' => '.',
    ]);

    $this->postJson('/api/v1/routes', [
        'domain' => 'node-package.example.test',
        'publication' => 'private',
        'instance_id' => $this->target->id,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'route.target_web_root_unsupported');

    $this->assertDatabaseCount('routes', 0);
    $this->assertDatabaseCount('route_targets', 0);
});

it('refuses to attach a package Instance with repository root . to an existing Route', function (): void {
    $this->orbitApp->update([
        'type' => ProjectType::NodePackage,
        'root' => '.',
    ]);

    $route = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'targetless.example.test')->id;

    $this->putJson("/api/v1/routes/{$route}/target", [
        'instance_id' => $this->target->id,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'route.target_web_root_unsupported');

    $this->assertDatabaseCount('route_targets', 0);
});

it('lists and shows a pre-existing explicit Route', function (): void {
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'app.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $routeId = $route->id;
    $this->getJson('/api/v1/routes')->assertOk()->assertJsonPath('data.0.id', $routeId);
    $this->getJson("/api/v1/routes/{$routeId}")->assertOk()->assertJsonPath('data.id', $routeId);
});

it('refuses targetless app Route creation in the action', function (): void {
    expect(fn () => app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'unsupported.example.test',
        publication: RoutePublication::Private,
        nodeId: $this->node->id,
    )))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('routes', 0);
});

it('rejects Project and targetless app Route creation inputs', function (): void {
    foreach ([
        ['project_id' => $this->orbitApp->id, 'instance_id' => $this->target->id],
        ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id],
        ['cluster_id' => route_cluster('active', 'cluster.test')[0]->id],
        ['instance_id' => $this->target->id, 'node_id' => $this->node->id],
    ] as $scope) {
        $this->postJson('/api/v1/routes', [
            ...$scope,
            'domain' => 'invalid.example.test',
            'publication' => 'private',
        ])->assertUnprocessable();
    }

    $this->assertDatabaseCount('routes', 0);
});

it('returns 409 before replacing or clearing an active target or removing its Route', function (): void {
    $route = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $route->update(['status' => 'active']);
    $otherNode = route_node('dev-two', '10.44.0.3', 'two.test');
    $other = route_instance($this->orbitApp, $otherNode, 'feature');
    $before = $route->fresh(['targets'])->toArray();
    $targetRowsBefore = route_api_target_rows();
    $message = "Active Instance [{$this->target->id}] must remain associated with Route [{$route->id}].";

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'instance_id' => $other->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);
    $this
        ->deleteJson("/api/v1/routes/{$route->id}/target")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);
    $this
        ->deleteJson("/api/v1/routes/{$route->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath('error.message', $message);

    expect($route->fresh(['targets'])->toArray())
        ->toBe($before)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore)
        ->and($this->target->fresh())
        ->not->toBeNull()
        ->and($this->removal->events)
        ->toBe([]);
});

it('refuses a tracking route', function (): void {
    $route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'project_id' => null,
        'node_id' => $this->node->id,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'domain' => 'tracking.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    RouteAnalyticsTracking::query()->create([
        'route_id' => $route->id,
        'instance_id' => $this->target->id,
    ]);

    $this->deleteJson("/api/v1/routes/{$route->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.tracking_managed');

    expect($route->fresh())->not->toBeNull()
        ->and($this->removal->events)->toBe([]);
});

it('returns 409 with both Routes when the requested target belongs to another Route', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $requestedId = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'fixed.example.test')->id;
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->putJson("/api/v1/routes/{$requestedId}/target", [
            'instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "Instance [{$this->target->id}] is already associated with Route [{$existing->id}] and cannot be assigned to Route [{$requestedId}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore);
});

it('returns 409 with route.target_conflict when creating a Route for an Instance that already has one', function (): void {
    $existing = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $routesBefore = route_api_routes();
    $targetRowsBefore = route_api_target_rows();

    $this
        ->postJson('/api/v1/routes', [
            'domain' => 'unused-host.example.test',
            'publication' => 'private',
            'instance_id' => $this->target->id,
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict')
        ->assertJsonPath(
            'error.message',
            "Instance [{$this->target->id}] is already associated with Route [{$existing->id}].",
        );

    expect(route_api_routes())
        ->toBe($routesBefore)
        ->and(route_api_target_rows())
        ->toBe($targetRowsBefore)
        ->and(Route::query()->where('domain', 'unused-host.example.test')->exists())
        ->toBeFalse();
});

it('leaves the complete Route unchanged for invalid target proposals', function (): void {
    $route = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $before = $route->fresh(['targets'])->toArray();
    $otherApp = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $foreign = route_instance($otherApp, $this->node, 'foreign');

    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $foreign->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_app_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $inactive = route_instance($this->orbitApp, $this->node, 'inactive');
    $inactive->update(['status' => InstanceState::Reserved]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $inactive->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_inactive');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $tldlessNode = route_node('tldless', '10.44.0.4', null);
    $tldless = route_instance($this->orbitApp, $tldlessNode, 'tldless');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $tldless->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.tld_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $routerlessCluster = Cluster::query()->create([
        'name' => 'routerless',
        'state' => ClusterState::Active,
        'tld' => null,
    ]);
    $clusterNode = route_node('clustered', '10.44.0.5', 'clustered.test');
    $clusterNode->update(['cluster_id' => $routerlessCluster->id]);
    $clustered = route_instance($this->orbitApp, $clusterNode, 'clustered');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $clustered->id,
    ])->assertConflict()->assertJsonPath('error.code', 'route.router_required');
    expect($route->fresh(['targets'])->toArray())->toBe($before);

    $collisionNode = route_node('collision', '10.44.0.6', 'collision.test');
    $collision = route_instance($this->orbitApp, $collisionNode, 'feature');
    Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $collisionNode->id,
        'domain' => 'feature.acme.collision.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'instance_id' => $collision->id,
    ])->assertConflict();
    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('rejects malformed input, caller-owned fields, arrays, and conflicting retries unchanged', function (): void {
    foreach (['bad_name', '-bad.test', str_repeat('a', 254)] as $domain) {
        $this->postJson('/api/v1/routes', [
            'project_id' => $this->orbitApp->id,
            'domain' => $domain,
            'publication' => 'private',
            'node_id' => $this->node->id,
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/routes', [
        'project_id' => $this->orbitApp->id,
        'domain' => 'safe.test',
        'publication' => 'private',
        'node_id' => $this->node->id,
        'targets' => [$this->target->id],
        'status' => 'active',
    ])->assertUnprocessable();

    $generated = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}/target", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->targets()->count())->toBe(1);
    $this
        ->deleteJson("/api/v1/routes/{$generated->id}", ['unsupported' => true])
        ->assertUnprocessable();
    expect($generated->fresh())->not->toBeNull();

});

it('refuses invalid or occupied active explicit domains before Route or projection state changes', function (): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'domain' => 'occupied.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'bad_name'])
        ->assertUnprocessable();
    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'occupied.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.domain_conflict');

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('refuses a reserved domain for a pending Route before the replacement Route exists', function (string $domain): void {
    $route = pendingNodeRouteFixture($this->orbitApp->id, $this->node->id, 'pending.example.test');
    $route->targets()->create(['instance_id' => $this->target->id, 'position' => 0]);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => $domain])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.domain_conflict')
        ->assertJsonPath('error.message', "Route domain [{$domain}] is reserved.");

    expect($route->fresh(['targets'])->toArray())->toBe($before)
        ->and(Route::query()->count())->toBe(1);
})->with(['gateway.orbit', 'collector.ai-router.orbit']);

it('refuses a reserved domain for an active Route before Route or projection state changes', function (string $domain): void {
    $this->target->update(['source_is_laravel' => false, 'provisioning_step' => 'active']);
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => $domain])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.domain_conflict')
        ->assertJsonPath('error.message', "Route domain [{$domain}] is reserved.");

    expect($route->fresh(['targets'])->toArray())->toBe($before)
        ->and(Route::query()->count())->toBe(1);
})->with(['gateway.orbit', 'collector.ai-router.orbit']);

it('refuses a reserved explicit domain for an Instance Route before the Route exists', function (string $domain): void {
    expect(fn (): Route => app(CreateRouteAction::class)->ensureForInstance($this->target, $domain))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.domain_conflict')
            ->and($exception->getMessage())->toBe("Route domain [{$domain}] is reserved."));

    expect(Route::query()->count())->toBe(0);
})->with(['gateway.orbit', 'collector.ai-router.orbit']);

it('returns 409 instance.source_profile_missing for an explicit domain change without a recorded profile', function (): void {
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);
    $this->target->update(['provisioning_step' => 'active']);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class));
    app()->instance(
        DevelopmentInstanceConfigurator::class,
        Mockery::mock(DevelopmentInstanceConfigurator::class),
    );
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);
    $before = $route->fresh(['targets'])->toArray();

    $this
        ->patchJson("/api/v1/routes/{$route->id}", ['domain' => 'next.example.test'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_profile_missing')
        ->assertJsonPath(
            'error.message',
            'The Instance has no recorded source profile and cannot be used.',
        );

    expect($route->fresh(['targets'])->toArray())->toBe($before);
});

it('keeps publication=public without artifacts for a Node-scoped Route, inactive Cluster, or missing Ingress', function (): void {
    $edge = new FakePublicRouteEdgeProjector;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    app()->instance(DevelopmentProjectionOperationLock::class, new RouteApiProjectionOwner);

    $active = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'node-public.example.test',
        publication: RoutePublication::Public,
        instanceId: $this->target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $active->update(['status' => RouteStatus::Active]);

    expect($active->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    $published = app(PublishPublicRouteAction::class)->execute($active, RoutePublication::Public);

    expect($published->id)
        ->toBe($active->id)
        ->and($published->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    [$cluster, , , , , $clusterRoute] = route_public_topology(
        $this->orbitApp,
        domain: 'inactive-cluster.example.test',
        name: 'inactive-public',
    );
    $cluster->update(['state' => ClusterState::Inactive]);
    $inactiveCluster = app(PublishPublicRouteAction::class)->execute($clusterRoute, RoutePublication::Public);

    expect($inactiveCluster->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toBe([]);

    [$missingIngress] = route_cluster('missing-ingress', 'missing.test');
    $missing = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => $missingIngress->id,
        'domain' => 'missing-ingress.example.test',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);

    expect($missing->publication)->toBe(RoutePublication::Public)->and($edge->calls)->toBe([]);
});

it('creates and shows a public Route without a Node public-IP field', function (): void {
    $created = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => route_cluster('shown-public', 'shown.test')[0]->id,
        'domain' => 'shown-public.example.test',
        'provenance' => 'explicit',
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);

    $shown = $this->getJson('/api/v1/routes/'.$created->id)->assertOk();

    expect($shown->json('data'))
        ->not->toHaveKey('public_ip')
        ->and($shown->json('data.publication'))
        ->toBe('public');
});

it('renders the stored public Route artifact and limits Ingress firewall rules to its owner', function (): void {
    [$cluster, $router, $ingress, $workload, $instance, $route] = route_public_topology($this->orbitApp);
    $route->update(['replacement_step' => RouteReplacementStep::IngressFirewall, 'sites_published' => true]);

    $artifact = new IngressSiteRepository()->forRoute($route->refresh());
    expect($artifact->artifact())
        ->toBe(['domain' => $route->domain, 'router_upstream' => $router->lan_ip])
        ->and($artifact->artifact())
        ->not->toHaveKey('instance_id')
        ->not->toHaveKey('node_id')
        ->and(array_keys($artifact->artifact()))
        ->toBe(['domain', 'router_upstream']);

    $catalog = new NodeFirewallRuleCatalog;
    expect(collect($catalog->forRole($ingress, RoleName::Ingress))->map(fn ($rule) => $rule->shape->comment)->all())
        ->toBe(['orbit:ingress-http', 'orbit:ingress-https'])
        ->and($catalog->forRole($router, RoleName::Ingress))
        ->toBeEmpty()
        ->and($catalog->forRole($workload, RoleName::AppProd))
        ->toBeEmpty();

    $override = new IngressSiteRepository()->privateOverride($route);
    expect($override->domain)
        ->toBe($route->domain)
        ->and($override->routerAddress)
        ->toBe($router->lan_ip)
        ->and($override->workloadAddress)
        ->toBe($workload->lan_ip);

    expect($instance->refresh()->status->value)->toBe('active');
});

it('composes one public Caddy site when Ingress shares a Node and uses LAN without WireGuard fallback', function (): void {
    [$cluster, $router, $ingress, $workload, $instance, $route] = route_public_topology($this->orbitApp);
    $route->update(['replacement_step' => RouteReplacementStep::IngressFirewall]);
    $route->refresh();
    $sites = new DevelopmentSiteRepository;
    $renderer = new DevelopmentCaddyConfigRenderer;

    $separate = $renderer->render($sites->forNode($ingress, $route));
    expect($separate)
        ->toContain("{$route->domain} {")
        ->toContain("reverse_proxy https://{$router->lan_ip}")
        ->toContain('header_up X-Forwarded-Proto https')
        ->toContain('header_up X-Forwarded-Host '.$route->domain)
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain("tls /etc/caddy/orbit-certificates/route-{$route->id}-ingress/current/cert.pem")
        ->not->toContain('https://'.$route->domain.' {');

    $colocated = route_node('ingress-router', '10.44.0.41', null);
    $colocated->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.40']);
    $router->roles()->where('role', RoleName::Router)->update(['node_id' => $colocated->id, 'cluster_id' => $cluster->id]);
    $ingress->roles()->where('role', RoleName::Ingress)->update(['node_id' => $colocated->id, 'cluster_id' => $cluster->id]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node', 'targets.instance.node']);
    $composed = $renderer->render($sites->forNode($colocated, $route));
    expect($composed)
        ->toContain("{$route->domain} {")
        ->toContain("reverse_proxy https://{$workload->lan_ip}")
        ->not->toContain('reverse_proxy https://127.0.0.1');

    $colocatedUpstream = new IngressSiteRepository()->forRoute($route->refresh());
    expect($colocatedUpstream->routerUpstream)->toBe('127.0.0.1');

    $colocated->roles()->where('role', RoleName::Ingress)->update(['node_id' => $ingress->id, 'cluster_id' => $cluster->id]);
    $colocated->roles()->where('role', RoleName::Router)->update(['node_id' => $router->id, 'cluster_id' => $cluster->id]);
    $router->update(['lan_ip' => null]);
    expect(new IngressSiteRepository()->forRoute($route->refresh())->routerUpstream)
        ->toBe($router->wireguard_ip);
});

it('refuses a generated Route, duplicate target, foreign App or Cluster, inactive target, missing disposition, and concurrent intent', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $devNode = route_node('dev-pool-node', '10.44.0.93', 'devpool.test');
    $dev = route_instance($this->orbitApp, $devNode, 'dev-pool');
    $generated = app(CreateRouteAction::class)->ensureForInstance($this->target, null);
    $foreignApp = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $foreign = route_instance($foreignApp, $second->node, 'foreign');
    [$foreignCluster] = route_cluster('foreign-pool', null);
    $foreignClusterNode = route_node('foreign-app-prod', '10.44.0.91', null);
    $foreignClusterNode->update(['cluster_id' => $foreignCluster->id, 'lan_ip' => '10.10.0.91']);
    $foreignClusterNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $foreignClusterInstance = route_instance($this->orbitApp, $foreignClusterNode, 'foreign-cluster');
    $foreignClusterInstance->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => '/var/www/acme/foreign-cluster',
        'production_user' => 'orbit-acme',
        'selected_php_version' => '8.5',
    ]);
    $inactiveNode = route_node('inactive-pool', '10.44.0.94', null);
    $inactiveNode->update(['cluster_id' => $route->cluster_id]);
    $inactive = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $inactiveNode->id,
        'name' => 'inactive',
        'environment' => 'production',
        'checkout_path' => '/var/www/inactive',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $foreignRoute = Route::query()->create([
        'project_id' => $foreignApp->id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'foreign-dest.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $before = route_api_target_rows();

    $this->putJson("/api/v1/routes/{$generated->id}/target", ['targets' => [$this->target->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.pool_unsupported');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $first->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $dev->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.pool_unsupported');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $foreign->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_app_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $foreignClusterInstance->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_scope_conflict');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $inactive->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_inactive');
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$second->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_disposition_required');
    $this->putJson("/api/v1/routes/{$route->id}/target", [
        'targets' => [$second->id],
        'dispositions' => [
            ['instance_id' => $first->id, 'route_id' => $foreignRoute->id],
        ],
    ])->assertConflict()->assertJsonPath('error.code', 'route.target_app_conflict');

    $route->update([
        'target_set_intent' => ['targets' => [$second->id], 'dispositions' => []],
        'target_set_step' => 'reserved',
    ]);
    $this->putJson("/api/v1/routes/{$route->id}/target", ['targets' => [$first->id, $second->id]])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.target_set_conflict');

    expect(route_api_target_rows())->toBe($before);
});

it('renders a stored active targetless Route as unavailable without its departing workload address', function (): void {
    [$route, , $second] = route_api_production_pool();
    $other = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'vacated.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $other->targets()->create(['instance_id' => $second->id, 'position' => 0]);
    $other->update(['status' => 'active', 'sites_published' => true]);
    $second->update(['status' => InstanceState::SourceResolved]);
    $other->targets()->delete();

    $other->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $router = $other->cluster?->routerAssignment?->node;
    expect($router)->not->toBeNull();
    $sites = new DevelopmentSiteRepository()->forNode($router);
    $rendered = new DevelopmentCaddyConfigRenderer()->render($sites);
    $vacatedSite = collect(preg_split('/\n\n/', $rendered) ?: [])
        ->first(static fn (string $block): bool => str_contains($block, 'vacated.example.test'));
    expect($vacatedSite)
        ->toBeString()
        ->toContain('Orbit Route unavailable')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->not->toContain('10.10.0.62');
});

it('shows stored pool targets in order without exposing their network addresses', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $route->targets()->create(['instance_id' => $second->id, 'position' => 1]);

    $this->getJson("/api/v1/routes/{$route->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.targets.0.instance_id', $first->id)
        ->assertJsonPath('data.targets.1.instance_id', $second->id)
        ->assertJsonMissingPath('data.targets.0.lan_ip')
        ->assertJsonMissingPath('data.lan_ip')
        ->assertJsonMissingPath('data.wireguard_ip');
});

it('projects a production pool with LAN preference, WireGuard fallback, mixed local composition, and empty-pool 503', function (): void {
    [$route, $first, $second] = route_api_production_pool();
    $route->targets()->create(['instance_id' => $second->id, 'position' => 1]);
    $route->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $router = $route->cluster?->routerAssignment?->node;
    expect($router)->not->toBeNull();
    $renderer = new DevelopmentCaddyConfigRenderer;

    $remote = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($remote)
        ->toContain("reverse_proxy https://{$first->node->lan_ip} https://{$second->node->lan_ip}")
        ->toContain('lb_policy round_robin')
        ->toContain('fail_duration 10s')
        ->not->toContain('https://'.$first->node->wireguard_ip);

    $second->node->update(['lan_ip' => null]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $wireguard = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($wireguard)
        ->toContain("https://{$first->node->lan_ip}")
        ->toContain("https://{$second->node->refresh()->wireguard_ip}");

    $first->node->update(['id' => $first->node->id]);
    $router->roles()->where('role', RoleName::AppProd)->delete();
    $router->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $first->update(['node_id' => $router->id]);
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.project', 'targets.instance.node']);
    $composed = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($composed)
        ->toContain('unix//run/orbit/route-'.$route->id.'-local.sock')
        ->toContain('https://'.$second->node->refresh()->wireguard_ip)
        ->not->toContain('reverse_proxy https://127.0.0.1')
        ->and(mb_substr_count($composed, "{$route->domain} {"))
        ->toBe(1);

    $first->update(['status' => InstanceState::SourceResolved]);
    $second->update(['status' => InstanceState::SourceResolved]);
    $route->targets()->delete();
    $route->refresh()->load(['cluster.routerAssignment.node', 'targets.instance.node']);
    $empty = $renderer->render(new DevelopmentSiteRepository()->forNode($router));
    expect($empty)
        ->toContain('Orbit Route unavailable')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->not->toContain('10.10.0.61')
        ->not->toContain('10.44.0.62');
});

it('rejects caller-supplied backend URLs, addresses, Caddy directives, and policy fields on target-set', function (): void {
    [$route] = route_api_production_pool();

    $this
        ->putJson("/api/v1/routes/{$route->id}/target", [
            'targets' => [1],
            'backend_url' => 'http://10.10.0.8',
            'lan_ip' => '10.10.0.8',
            'caddy' => 'reverse_proxy 10.10.0.8',
            'lb_policy' => 'least_conn',
        ])
        ->assertUnprocessable();
});

/** @return array{Route, Instance, Instance} */
function route_api_production_pool(): array
{
    [$cluster, $router] = route_cluster('pool', null);
    $firstNode = route_node('pool-one', '10.44.0.61', null);
    $firstNode->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.61']);
    $firstNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $secondNode = route_node('pool-two', '10.44.0.62', null);
    $secondNode->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.62']);
    $secondNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $first = route_instance(test()->orbitApp, $firstNode, 'one');
    $second = route_instance(test()->orbitApp, $secondNode, 'two');
    foreach ([$first, $second] as $instance) {
        $instance->update([
            'environment' => 'production',
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
            'production_home' => '/var/www/acme/'.$instance->name,
            'production_user' => 'orbit-acme',
            'selected_php_version' => '8.5',
        ]);
    }
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(
        domain: 'pool.example.test',
        publication: RoutePublication::Private,
        instanceId: $first->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);

    return [$route->refresh(), $first->refresh(), $second->refresh()];
}

function route_node(string $name, string $wireguardIp, ?string $tld): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => $tld,
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

function route_instance(Project $project, Node $node, string $name): Instance
{
    $production = $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists();
    orbit_test_set_app_placement_role($node, $production);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::Active,
    ]);
}

/** @return list<array<string, mixed>> */
function route_api_routes(): array
{
    return Route::query()
        ->with('targets')
        ->orderBy('id')
        ->get()
        ->map(static fn (Route $route): array => $route->toArray())
        ->all();
}

/** @return list<array<string, mixed>> */
function route_api_target_rows(): array
{
    return RouteTarget::query()
        ->orderBy('id')
        ->get()
        ->map(static fn (RouteTarget $target): array => $target->getAttributes())
        ->all();
}

function route_api_active_development_route(Project $project, Instance $target): Route
{
    $target->update([
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

        domain: 'active.example.test',
        publication: RoutePublication::Private,
        instanceId: $target->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => 'active']);

    return $route->refresh();
}

final readonly class RouteApiProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

/** @return array{Cluster, Node, Node, Node, Instance, Route} */
function route_public_topology(
    Project $project,
    RoutePublication $publication = RoutePublication::Public,
    string $domain = 'public.example.test',
    string $name = 'public',
    bool $createRoute = true,
): array {
    [$cluster, $router] = route_cluster($name, "{$name}.test");
    $router->update(['lan_ip' => '10.10.0.20']);
    $ingress = route_node("{$name}-ingress", '10.44.0.30', null);
    $ingress->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.30']);
    $ingress->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
    ]);
    $workload = route_node("{$name}-prod", '10.44.0.31', null);
    $workload->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.10']);
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance = route_instance($project, $workload, 'production');
    $instance->update([
        'environment' => 'production',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'production_home' => '/var/www/acme',
        'production_user' => 'orbit-acme',
        'selected_php_version' => '8.5',
    ]);
    $route = null;

    if ($createRoute) {
        $route = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(

            domain: $domain,
            publication: $publication,
            instanceId: $instance->id,
            nodeId: null,
            clusterId: null,
        ))['route'];
        $route->update(['status' => RouteStatus::Active]);
    }

    return [$cluster, $router->refresh(), $ingress->refresh(), $workload->refresh(), $instance->refresh(), $route?->refresh()];
}

/** @return array{Cluster, Node} */
function route_cluster(string $name, ?string $tld): array
{
    static $octet = 100;
    $octet++;
    $cluster = Cluster::query()->create(['name' => $name, 'tld' => $tld, 'state' => ClusterState::Active]);
    $router = route_node("{$name}-router", "10.45.0.{$octet}", null);
    $router->update(['cluster_id' => $cluster->id]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    return [$cluster, $router];
}

describe('offline public replacement withdrawal', function (): void {
    it('leaves a skipped Ingress unchanged and still rebuilds the Router', function (LifecycleStatus $status): void {
        [$cluster, $router, $ingress] = route_public_topology($this->orbitApp, name: 'offline-edge', createRoute: false);
        $ingress->update(['status' => $status]);
        $route = Route::query()->create([
            'project_id' => $this->orbitApp->id,
            'cluster_id' => $cluster->id,
            'domain' => 'offline.example.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Public,
            'status' => RouteStatus::Failed,
            'failed_step' => 'withdrawal',
            'error_code' => 'route.replacement_withdrawn',
        ]);
        $firewall = Mockery::mock(NodeRoleFirewallManager::class);
        $firewall->shouldNotReceive('converge');
        app()->instance(NodeRoleFirewallManager::class, $firewall);
        $builds = new FakeNodeCaddyBuilds;
        app()->instance(NodeCaddyBuilds::class, $builds);

        app(NativePublicRouteEdgeProjector::class)->rollbackPublicEdge($route, [$ingress->id]);

        expect($builds->built)->toBe([$router->name]);
    })->with([LifecycleStatus::Active, LifecycleStatus::Failed]);
});
