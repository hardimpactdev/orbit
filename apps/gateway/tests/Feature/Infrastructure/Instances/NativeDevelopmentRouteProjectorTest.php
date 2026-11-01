<?php

declare(strict_types=1);

use App\Actions\Routes\SetRouteTargetAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

it('renders one local workload site when Router and workload roles share a Node', function (): void {
    [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $sites = new DevelopmentSiteRepository;
    $route->publishSites();
    $nodeSites = $sites->forNode($node);
    $configuration = new DevelopmentCaddyConfigRenderer()->render($nodeSites);

    expect($nodeSites)->toHaveCount(1)
        ->and($nodeSites->sole()->isProxy())->toBeFalse();
    expect(mb_substr_count($configuration, "https://{$route->domain}"))->toBe(1);
    expect($configuration)
        ->toContain("php_fastcgi unix//run/php/orbit-app-instance-{$instance->id}.sock")
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->toContain('forward_auth')
        ->toContain('/api/v1/runtime-activations/app-instance/'.$instance->id)
        ->toContain('handle @orbit_asleep')
        ->toContain('root /dev/shm/orbit/hibernation')
        ->toContain('try_files /app-instance-'.$instance->id.'.awake')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain("orbit-certificates/app-instance-{$instance->id}/current/root.pem")
        ->not->toContain('reverse_proxy https://');
});

it('renders a dedicated Router with the workload LAN address and preserved TLS identity', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    $route->publishSites();

    $configuration = app(NodeCaddyfileRenderer::class)->render($router)->content;

    expect($configuration)->toContain(
        'reverse_proxy https://10.10.0.10',
        "header_up Host {$route->domain}",
        "tls_server_name {$route->domain}",
        "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
    );
});

it('renders old and candidate hostname sites with separate certificate scopes before DNS cutover', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::RouterCaddy);
    $sites = new DevelopmentSiteRepository;

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);
    $dns = new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render();

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}/current",
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-hostname-change/current",
        ])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($routerSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/route-{$route->id}-router/current",
            "/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current",
        ])
        ->and($dns)
        ->toContain(
            "host-record=feature.acme.test,{$router->wireguard_ip}",
            "host-record=next.acme.test,{$router->wireguard_ip}",
        );
});

it('retires a transferred generated Route without inventing an old Router certificate', function (): void {
    [$instance, $sourceRoute, $source] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $source->cluster_id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $replacement = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $source->cluster_id,
        'generation_basis_node_id' => $destination->id,
        'domain' => 'transferred.acme.test', 'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
        'replaces_route_id' => $sourceRoute->id, 'replacement_step' => RouteReplacementStep::DatabaseCutover,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $replacement->update(['status' => RouteStatus::Active]);
    $sourceRoute->update([
        'status' => RouteStatus::Retiring, 'replaced_by_route_id' => $replacement->id,
        'replacement_step' => RouteReplacementStep::DatabaseCutover,
    ]);
    $transfer = orb368_projection_transfer($instance, $sourceRoute, $replacement, $source, $destination, $source);
    $sites = new DevelopmentSiteRepository;
    expect(orb_native_retirement_input($transfer, $source,
        ["app-instance-{$instance->id}", "route-{$sourceRoute->id}-router"], [$sourceRoute->id]))
        ->toMatchArray(['certificate_scopes' => ["app-instance-{$instance->id}", "route-{$sourceRoute->id}-router"],
            'lan_route_ids' => [$sourceRoute->id]]);
});

it('preserves a Router certificate still serving the transferred explicit Route', function (): void {
    [$instance, $route, $source, $router] = orb127_route_projection_models();
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $source->cluster_id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route->update(['status' => RouteStatus::Active]);
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $router);
    expect(orb_native_retirement_input($transfer, $router, ["route-{$route->id}-router"])['certificate_scopes'])
        ->toBe([]);
});

it('cleans the recorded Router after the source Node changes Cluster membership', function (): void {
    [$instance, $route, $source, $originalRouter] = orb127_route_projection_models();
    $destinationCluster = Cluster::query()->create(['name' => 'destination', 'state' => ClusterState::Active]);
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $destinationCluster->id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $destination->roles()->create([
        'cluster_id' => $destinationCluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active,
    ]);
    $route->update(['status' => RouteStatus::Active, 'cluster_id' => $destinationCluster->id]);
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $originalRouter);
    $source->update(['cluster_id' => $destinationCluster->id]);
    expect(orb_native_retirement_input($transfer, $originalRouter, ["route-{$route->id}-router"])['certificate_scopes'])
        ->toBe(["route-{$route->id}-router"]);
});

it('retires the source leaves and firewall rules of every recorded Route with a web root', function (): void {
    [$instance, $route, $source, $originalRouter] = orb127_route_projection_models();
    [$destinationCluster, $destinationRouter] = orb_second_cluster_router();
    $destination = orb_transfer_destination($destinationCluster);
    $route->update(['status' => RouteStatus::Active, 'cluster_id' => $destinationCluster->id]);
    $docs = orb_web_root_route($instance, $destinationCluster, 'docs.acme.test');
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $originalRouter);
    // Route 999 left after cutover; its record still names its source leaves.
    $transfer->update(['web_root_route_ids' => [$docs->id, 999]]);
    expect(orb_native_retirement_input($transfer, $source,
        ["app-instance-{$instance->id}", "route-{$docs->id}", 'route-999'], [$route->id, $docs->id, 999]))
        ->toMatchArray(['certificate_scopes' => ["app-instance-{$instance->id}", "route-{$docs->id}", 'route-999'],
            'lan_route_ids' => [$route->id, $docs->id, 999]]);
    expect(orb_native_retirement_input($transfer, $originalRouter,
        ["route-{$route->id}-router", "route-{$docs->id}-router", 'route-999-router'])['certificate_scopes'])
        ->toBe(["route-{$route->id}-router", "route-{$docs->id}-router", 'route-999-router']);
});

it('keeps the Router leaf of a Route with a web root that stays in its Cluster', function (): void {
    [$instance, $route, $source, $router] = orb127_route_projection_models();
    $destination = orb_transfer_destination(Cluster::query()->findOrFail($source->cluster_id));
    $route->update(['status' => RouteStatus::Active]);
    $docs = orb_web_root_route($instance, Cluster::query()->findOrFail($source->cluster_id), 'docs.acme.test');
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $router);
    $transfer->update(['web_root_route_ids' => [$docs->id]]);
    expect(orb_native_retirement_input($transfer, $router,
        ["route-{$route->id}-router", "route-{$docs->id}-router"])['certificate_scopes'])->toBe([]);
});

// Publication-before-removal and failed-publication retention execute in Swamp:
// routes/removal-local_test.ts, routes/residue-local_test.ts and removal_native_test.py.

it('preserves the ready hostname candidate across an interrupted DNS publication and ordinary rebuild', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'status' => InstanceState::Active,
        'source_is_laravel' => false,
    ]);
    $sites = new DevelopmentSiteRepository;

    expect($sites->forNode($workload)->pluck('domain')->all())
        ->toBe(['feature.acme.test']);

    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::LaravelUrl,
    ]);
    $replacement->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);
    $dns = new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render();

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}/current",
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-hostname-change/current",
        ])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($routerSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/route-{$route->id}-router/current",
            "/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current",
        ])
        ->and($dns)
        ->toContain(
            "host-record=feature.acme.test,{$router->wireguard_ip}",
            "host-record=next.acme.test,{$router->wireguard_ip}",
        );

    expect($sites->forNode($workload)->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test']);

    // Keep the old published site through grace; retirement alone does not withdraw it.
    $route->update(['status' => RouteStatus::Retiring, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    $replacement->update(['status' => RouteStatus::Active, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    expect($sites->forNode($router)->pluck('domain')->sort()->values()->all())
        ->toBe(['feature.acme.test', 'next.acme.test']);
    expect(new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render())
        ->toContain("host-record=feature.acme.test,{$router->wireguard_ip}", "host-record=next.acme.test,{$router->wireguard_ip}");

    $route->update(['sites_published' => false]);

    expect($sites->forNode($workload)->pluck('domain')->all())->toBe(['next.acme.test']);
    expect($sites->forNode($router)->pluck('domain')->all())->toBe(['next.acme.test']);
    expect(new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render())
        ->toContain("host-record=next.acme.test,{$router->wireguard_ip}")
        ->not->toContain("host-record=feature.acme.test,{$router->wireguard_ip}");
});

it('renders a pending replacement only after the step that writes each certificate and never once it failed', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::Reserved);
    $sites = new DevelopmentSiteRepository;
    $domains = static fn (Node $node): array => $sites->forNode($node)->pluck('domain')->all();

    expect($domains($workload))->toBe(['feature.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test']);

    $replacement->update(['replacement_step' => RouteReplacementStep::WorkloadCaddy]);

    expect($domains($workload))->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test']);

    $replacement->update(['replacement_step' => RouteReplacementStep::RouterCertificate]);

    expect($domains($router))->toBe(['feature.acme.test', 'next.acme.test']);

    $replacement->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'dns-publication',
        'error_code' => 'app-dev.dns_failed',
    ]);

    expect($domains($workload))->toBe(['feature.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test'])
        ->and(new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render())->not->toContain('next.acme.test');
});

it('renders a composed Router pool with the staging Router certificate during a domain change', function (): void {
    [$remote, $route, $workload, $router] = orb127_route_projection_models();
    // A multi-target Route is a production pool on app-prod Nodes of its Cluster.
    $workload->roles()->where('role', RoleName::AppDev->value)->delete();
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $router->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $production = static fn (string $name): array => [
        'environment' => 'production',
        'production_home' => "/srv/acme/{$name}",
        'production_user' => 'orbit-acme',
        'source_is_laravel' => false,
    ];
    $remote->update($production('feature'));
    $local = Instance::query()->create([
        'project_id' => $route->project_id,
        'node_id' => $router->id,
        'name' => 'local',
        'checkout_path' => '/srv/acme/local',
        'root' => 'public',
        'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40),
        ...$production('local'),
    ]);
    // The target on the Router comes first, so its cleanup publishes the composed pool first.
    $route->targets()->delete();
    $route->targets()->create(['instance_id' => $local->id, 'position' => 0]);
    $route->targets()->create(['instance_id' => $remote->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $remote->update(['status' => InstanceState::Active]);
    $local->update(['status' => InstanceState::Active]);
    $replacement = orb_pending_domain_change($route, $local, RouteReplacementStep::Reserved);
    $replacement->targets()->create(['instance_id' => $remote->id, 'position' => 1]);
    $replacement->update(['replacement_step' => RouteReplacementStep::RouterCaddy]);

    $routerDuringChange = new DevelopmentSiteRepository()->forNode($router)->firstWhere('domain', 'next.acme.test');

    expect($routerDuringChange?->certificateDirectory())
        ->toBe("/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current");
});

it('renders a provisioning Router from stored rows and withdraws a failed replacement', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $candidate = orb_router_candidate($oldRouter);
    $assignment = $candidate->roles()->create(['role' => RoleName::Router, 'cluster_id' => $oldRouter->cluster_id, 'status' => LifecycleStatus::Provisioning]);
    $assignment->update(['failed_step' => 'router-caddy']);
    expect(new DevelopmentSiteRepository()->forNode($candidate)->pluck('domain')->all())->toBe(['feature.acme.test']);
    $assignment->update(['status' => LifecycleStatus::Failed, 'error_code' => 'fixture.publication_failed']);
    expect(new DevelopmentSiteRepository()->forNode($candidate)->pluck('domain')->all())->toBe([])
        ->and(new DevelopmentSiteRepository()->forNode($oldRouter)->pluck('domain')->all())->toBe(['feature.acme.test']);
});

it('serves a crash-left pending domain change from its staging certificate on the replacement Router', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::RouterCaddy);
    $candidate = orb_router_candidate($oldRouter);
    $assignment = $candidate->roles()->create(['role' => RoleName::Router, 'cluster_id' => $oldRouter->cluster_id,
        'status' => LifecycleStatus::Provisioning, 'failed_step' => 'router-caddy']);
    expect(new DevelopmentSiteRepository()->forNode($candidate)->mapWithKeys(static fn ($site) => [$site->domain => $site->certificateScope ?? $site->scope])->all())->toBe([
        'feature.acme.test' => "route-{$route->id}-router",
        'next.acme.test' => "route-{$replacement->id}-router-hostname-change",
    ]);
});

it('preserves production release sites at the environment synchronization checkpoint', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'environment' => 'production',
        'checkout_path' => '/srv/acme/releases/one',
        'production_home' => '/srv/acme',
        'production_user' => 'orbit-acme',
        'production_php_socket' => '/run/php/orbit-acme.sock',
        'status' => InstanceState::Active,
        'source_is_laravel' => true,
    ]);
    $workload->roles()->where('role', RoleName::AppDev)->delete();
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance->unsetRelation('node');
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::EnvironmentSynchronized,
    ]);
    $replacement->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    $sites = new DevelopmentSiteRepository;

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->pluck('checkoutPath')->unique()->values()->all())
        ->toBe(['/srv/acme/current'])
        ->and($workloadSites->pluck('productionPhpSocket')->unique()->values()->all())
        ->toBe(['/run/php/orbit-acme.sock'])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test']);
});

it('hydrates only requested workload and Router routes while global inventory stays complete', function (): void {
    [$pendingInstance, $pendingRoute, $workload, $router] = orb127_route_projection_models();
    $activeInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $workload->id,
        'name' => 'active',
        'checkout_path' => '/home/orbit/apps/acme/active',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $activeRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'domain' => 'active.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $activeRoute->targets()->create(['instance_id' => $activeInstance->id, 'position' => 0]);
    $activeRoute->update(['status' => RouteStatus::Active]);
    $activeInstance->update(['status' => 'active']);
    $failedInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $workload->id,
        'name' => 'failed',
        'checkout_path' => '/home/orbit/apps/acme/failed',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $failedRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'domain' => 'failed.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $failedRoute->targets()->create(['instance_id' => $failedInstance->id, 'position' => 0]);
    $failedRoute->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'runtime',
        'error_code' => 'app-dev.runtime_failed',
    ]);
    $unrelatedNode = Node::query()->create([
        'name' => 'unrelated-workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $unrelatedNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $unrelatedInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $unrelatedNode->id,
        'name' => 'unrelated',
        'checkout_path' => '/home/orbit/apps/acme/unrelated',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $unrelatedRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $unrelatedNode->id,
        'domain' => 'unrelated.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $unrelatedRoute->targets()->create(['instance_id' => $unrelatedInstance->id, 'position' => 0]);
    $unrelatedRoute->update(['status' => RouteStatus::Active]);
    $unrelatedInstance->update(['status' => 'active']);
    $sites = new DevelopmentSiteRepository;
    // Creation stores the publication record before the first build of the pending Route.
    $pendingRoute->publishSites();
    $globalSites = $sites->all();
    $retrievedRoutes = collect();
    $retrievedInstances = collect();
    Event::listen(
        'eloquent.retrieved: '.Route::class,
        static function (Route $retrieved) use ($retrievedRoutes): void {
            $retrievedRoutes->push($retrieved->id);
        },
    );
    Event::listen(
        'eloquent.retrieved: '.Instance::class,
        static function (Instance $retrieved) use ($retrievedInstances): void {
            $retrievedInstances->push($retrieved->id);
        },
    );

    $workloadSites = $sites->forNode($workload);

    $siteIdentity = static fn (DevelopmentSite $site): array => [
        $site->scope,
        $site->domain,
        $site->nodeAddress,
        $site->upstreamAddresses,
    ];
    expect($workloadSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $workload->id)->values()->map($siteIdentity)->all())
        ->and($workloadSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("app-instance-{$pendingInstance->id}", "app-instance-{$activeInstance->id}")
        ->and($workloadSites->pluck('domain'))
        ->not->toContain($failedRoute->domain, $unrelatedRoute->domain)->and($retrievedRoutes)->toContain(
            $pendingRoute->id,
            $activeRoute->id,
        )
        ->not->toContain($failedRoute->id, $unrelatedRoute->id)->and($retrievedInstances)->toContain(
            $pendingInstance->id,
            $activeInstance->id,
        )
        ->not->toContain($failedInstance->id, $unrelatedInstance->id)->and($globalSites->pluck('domain'))->toContain(
            $pendingRoute->domain,
            $activeRoute->domain,
            $unrelatedRoute->domain,
        )
        ->not->toContain($failedRoute->domain);

    $routerSites = $sites->forNode($router);

    expect($routerSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $router->id)->values()->map($siteIdentity)->all())
        ->and($routerSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("route-{$pendingRoute->id}-router", "route-{$activeRoute->id}-router");
});

it('hands a generated Route target to the replacement Route when the domain changes', function (): void {
    [$instance, $explicit, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $explicit->cluster->update(['tld' => 'acme.test']);
    $explicit->targets()->delete();
    $explicit->delete();
    $route = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $explicit->cluster_id, 'generation_basis_node_id' => $node->id,
        'domain' => "feature.{$instance->project->slug}.acme.test", 'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    Event::fake([RecordBroadcast::class]);

    $replacement = app(SetRouteTargetAction::class)->execute($route, $added->id);

    expect($replacement->id)->not->toBe($route->id)
        ->and($replacement->domain)->toBe("added.{$instance->project->slug}.acme.test")
        ->and($replacement->targets->sole()->instance_id)->toBe($added->id)
        ->and(Route::query()->find($route->id))->toBeNull()
        ->and($agents->calls)->toBe([]);
    Event::assertDispatched(
        RecordBroadcast::class,
        fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated && $event->id === $replacement->id,
    );
});

/** @return array{Instance, Route, Node, Node} */
function orb127_route_projection_models(
    bool $coLocated = false,
    ?string $workloadLan = null,
    ?string $routerLan = null,
    ?string $phpVersion = null,
): array {
    $cluster = Cluster::query()->create([
        'name' => 'routing-'.Str::lower(Str::random(8)),
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'workload-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => $workloadLan,
        'user' => 'orbit',
    ]);
    $workload
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $router = $coLocated
        ? $workload
        : Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'router-'.Str::lower(Str::random(8)),
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
            'lan_ip' => $routerLan,
            'user' => 'orbit',
        ]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'feature',
        'checkout_path' => '/home/orbit/apps/acme/feature',
        'root' => 'public',
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => $phpVersion,
        'status' => 'source_resolved',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'feature.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'instance_id' => $instance->id,
            'position' => 0,
        ]);

    return [$instance, $route, $workload, $router];
}

/** @return array{Cluster, Node} */
function orb_second_cluster_router(): array
{
    $cluster = Cluster::query()->create(['name' => 'moved-'.Str::lower(Str::random(8)), 'state' => ClusterState::Active]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $router->roles()->create(['cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);

    return [$cluster, $router];
}

/** An app-dev Node in the Cluster that a transfer moves an Instance to. */
function orb_transfer_destination(Cluster $cluster): Node
{
    $destination = Node::query()->create([
        'name' => 'transfer-destination-'.Str::lower(Str::random(8)), 'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.31', 'public_ssh_host' => '192.0.2.31', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return $destination;
}

/** An active Route with a web root that serves `apps/docs/public` of the Instance in the Cluster. */
function orb_web_root_route(Instance $instance, Cluster $cluster, string $domain): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $cluster->id,
        'domain' => $domain, 'web_root' => 'apps/docs/public', 'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

function orb_router_candidate(Node $router): Node
{
    return Node::query()->create([
        'cluster_id' => $router->cluster_id,
        'name' => 'candidate-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
}

function orb_pending_domain_change(Route $route, Instance $instance, RouteReplacementStep $step): Route
{
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => $step,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['replaced_by_route_id' => $replacement->id]);

    return $replacement;
}

/**
 * An active development Instance on the Route's Node, served by its own active Route unless `$routed` is false.
 */
function route_access_served_instance(
    Instance $instance,
    Route $route,
    Node $node,
    string $name,
    string $checkout,
    bool $releases = false,
    bool $routed = true,
): Instance {
    $other = Instance::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => $checkout, 'development_release_layout' => $releases, 'root' => 'public',
        'branch' => $name, 'starting_commit' => str_repeat('b', 40), 'selected_php_version' => '8.5',
        'status' => InstanceState::Active,
    ]);

    if (! $routed) {
        return $other;
    }

    $otherRoute = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $route->cluster_id, 'domain' => "{$name}.acme.test",
        'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $otherRoute->targets()->create(['instance_id' => $other->id, 'position' => 0]);
    $otherRoute->publishSites();
    $otherRoute->update(['status' => RouteStatus::Active]);

    return $other;
}

function orb368_projection_transfer(
    Instance $instance,
    Route $sourceRoute,
    Route $destinationRoute,
    Node $source,
    Node $destination,
    Node $sourceRouter,
): InstanceTransfer {
    $transfer = InstanceTransfer::query()->create([
        'instance_id' => $instance->id,
        'source_node_id' => $source->id,
        'source_router_node_id' => $sourceRouter->id,
        'destination_node_id' => $destination->id,
        'destination_name' => $instance->name,
        'destination_path' => '/srv/orbit/apps/acme/feature',
        'destination_domain' => $destinationRoute->domain,
        'source_layout' => 'checkout',
        'source_path' => $instance->checkout_path,
        'source_route_id' => $sourceRoute->id,
        'destination_route_id' => $destinationRoute->id,
        'status' => InstanceTransferStatus::InProgress,
        'current_step' => InstanceTransferStep::DestinationActivated,
        'cutover_at' => now(),
    ]);
    $instance->update([
        'node_id' => $destination->id,
        'checkout_path' => $transfer->destination_path,
        'status' => InstanceState::Active,
        'provisioning_step' => 'active',
    ]);

    return $transfer;
}

/** Native transfer public input filters captured leaves against the fresh stored sites. */
function orb_native_retirement_input(InstanceTransfer $transfer, Node $node, array $scopes, array $lan = []): array
{
    $node->update(['architecture' => 'x86_64']);
    $operation = new Operation(['input' => [
        'participant_node_ids' => [$node->id],
        'route_transfer' => [
            'instance_id' => $transfer->instance_id,
            'source_node_id' => $transfer->source_node_id,
            'destination_router_node_id' => $transfer->source_router_node_id,
            'target' => ['prepared' => [], 'live_certificates' => []],
            'retire_nodes' => [[
                'node_id' => $node->id, 'user' => $node->user,
                'certificate_scopes' => $scopes, 'lan_route_ids' => $lan,
            ]],
        ],
    ]]);

    return app(NativeRouteTransferPlan::class)->publicInput($operation, $node->id)['route_retire'];
}
